<?php
// src/MessageHandler/ProcessGemsuiteEntityJobHandler.php

namespace App\MessageHandler;

use App\Entity\Categories;
use App\Entity\Entreprise;
use App\Entity\GemsuiteClient;
use App\Entity\Product;
use App\Entity\ProductShipping;
use App\Entity\ProductVariant;
use App\Entity\RentalPack;
use App\Entity\Style;
use App\Entity\SyncJob;
use App\Entity\ProductPicture;
use App\Entity\ShippingClass;
use App\Message\ProcessGemsuiteEntityJob;
use App\Message\TranslateEntityJob;
use App\Services\GemsuiteImporterService\GemsuiteAttributeProcessor;
use App\Services\GemsuiteImporterService\GemsuiteImageUrlBuilder;
use App\Services\GemsuiteImporterService\GemsuiteStockCalculator;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use App\Services\GemsuiteImporterService\GemsuiteRentalWorkaroundService;
use App\Services\GemsuiteImporterService\GemsuiteCompanySyncHandler;
use Symfony\Component\String\Slugger\SluggerInterface;

#[AsMessageHandler]
class ProcessGemsuiteEntityJobHandler
{

    public function __construct(
        private LoggerInterface $logger,
        private TenantConnectionManager $tenantManager,
        private TenantEntityManagerProvider $emProvider,
        private MessageBusInterface $messageBus,
        private GemsuiteImageUrlBuilder $imageUrlBuilder,
        private GemsuiteAttributeProcessor $attributeProcessor,
        private GemsuiteStockCalculator $stockCalculator,
        private SluggerInterface $slugger,
        private GemsuiteRentalWorkaroundService $rentalWorkaround,
        private GemsuiteCompanySyncHandler $companySyncHandler,
        private \App\Services\TranslationGeneratorService\TranslationGeneratorService $translationGenerator
    ) {}

    public function __invoke(ProcessGemsuiteEntityJob $message)
    {
        $data = $message->getEntityData();
        $type = $message->getEntityType();
        $entityId = $data['id'] ?? 'inconnu';

        $this->logger->info(sprintf(
            '[Micro-Job Start] Traitement de "%s" ID %s pour Tenant ID %d',
            $type,
            $entityId,
            $message->getTenantId()
        ));

        $tenant = $this->tenantManager->findTenantById($message->getTenantId());
        if (!$tenant) {
            $this->logger->error(sprintf(
                '[Micro-Job Fail] Tenant ID %d non trouvé pour le job %s ID %s.',
                $message->getTenantId(),
                $type,
                $entityId
            ));
            return;
        }

        $tenantEm = null;

        try {
            $this->emProvider->switchTenant($tenant['dbname'], $tenant['code']);
            $tenantEm = $this->emProvider->getEntityManager();
            $startTime = microtime(true);
            $entity = null;

            switch ($type) {
                case 'client':
                    $entity = $this->processClient($tenantEm, $data);
                    break;

                case 'categories':
                    $entity = $this->processCategory($tenantEm, $data);
                    break;

                case 'product_parent':
                    $entity = $this->processProductParent($tenantEm, $data);
                    break;

                case 'product_variant':
                    $this->processProductVariant($tenantEm, $data);
                    break;
                    
                case 'company_config':
                    $this->companySyncHandler->handleCompanyUpdate($tenant['code']);
                    break;
            }

            if ($entity === null && $type !== 'product_variant' && $type !== 'company_config') {
                $this->logger->info(sprintf('[Micro-Job] Entité %s ID %s a été ignorée (process a retourné null).', $type, $entityId));
            }

            if ($entity && method_exists($entity, 'getTranslatableFields')) {
                if ($tenantEm->contains($entity)) {
                    $tenantEm->flush();
                }

                $this->messageBus->dispatch(new TranslateEntityJob(
                    $message->getTenantId(),
                    get_class($entity),
                    $entity->getId()
                ));
            } else if ($type !== 'product_variant' && $type !== 'company_config' && $entity !== null) {
                $tenantEm->flush();
            }

            // Clear settings
            $tenantEm->clear();

            $duration = round((microtime(true) - $startTime) * 1000, 2);
            $this->logger->info(sprintf(
                '[Micro-Job Success] Item "%s" ID %d terminé en %sms (DB: %s)',
                $type,
                $entityId,
                $duration,
                $tenantEm->getConnection()->getDatabase()
            ));

            $this->updateSyncJobCounter($tenantEm, $message->getSyncJobId());
        } catch (\Throwable $e) {
            $this->logger->error("[Micro-Job Fail] Erreur sur '{$type}' ID {$entityId}: " . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            throw $e;
        }
    }

    private function processClient(EntityManagerInterface $em, array $data): GemsuiteClient
    {
        $repo = $em->getRepository(GemsuiteClient::class);
        $client = $repo->findOneBy(['gemsuiteId' => $data['id']]);
        if (!$client) {
            $client = new GemsuiteClient();
            $client->setGemsuiteId($data['id']);
        }
        $client->setName($data['name'] ?? 'N/A');
        $email = strtolower($data['email'] ?? '');
        $client->setEmail(empty($email) ? null : $email);

        $em->persist($client);
        return $client;
    }

    private function processCategory(EntityManagerInterface $em, array $data): ?Categories
    {
        $id = $data['id'] ?? 'inconnu';
        $status = (int)($data['status'] ?? 1);
        $syncWeb = (bool)($data['sync_web'] ?? true);

        if ($status !== 1) {
            $categoryName = $data['name_fr'] ?? 'ID ' . $id;
            $this->logger->warning(sprintf(
                '[processCategory ID %s] IGNORÉE. Motif : (status: %d).',
                $id,
                $status
            ));
            return null;
        }


        $repo = $em->getRepository(Categories::class);
        $category = $repo->findOneBy(['gemsuiteCategoryId' => $data['id']]);
        if (!$category) {
            $category = new Categories();
            $category->setGemsuiteCategoryId($data['id']);
        }


        $entreprise = $em->getRepository(Entreprise::class)->findOneBy([]);
        $companyIdentifier = $entreprise ? $entreprise->getGemsuiteIdentifier() : null;

        $imagePath = $data['img_paths'] ?? null;
        if (!empty($imagePath)) {
            $category->setImage(
                $this->imageUrlBuilder->buildUrl($companyIdentifier, $imagePath)
            );
        }

        $category->setName(trim($data['name_fr']));
        $category->setExternalShippingClassId((int)($data['expedition_classes'] ?? 0));
        $category->setCategoryType((int)($data['category_type'] ?? 0));

        // --- NOUVELLE LOGIQUE LOCATION ---
        $categoryType = (int)($data['category_type'] ?? 0);
        $isRental = (int)($data['limit_lot'] ?? 0) === 1;
        $category->setIsRentalCategory($isRental);

        if ($categoryType === 10) {
            $category->setSyncWeb(true);
            $category->setIsVisible(false);
        } elseif ($isRental) {
            $category->setSyncWeb(true);
            $category->setIsVisible(true);
        } else {
            $category->setSyncWeb($syncWeb);
            $hasActiveWebProducts = false;
            foreach ($category->getProducts() as $product) {
                if ($product->isWeb()) {
                    $hasActiveWebProducts = true;
                    break;
                }
            }
            $category->setIsVisible($syncWeb || $hasActiveWebProducts);
        }

        $em->persist($category);
        $this->translationGenerator->generateTranslations($category);

        return $category;
    }

    private function processProductParent(EntityManagerInterface $em, array $data): ?Product
    {
        if (!$this->isEntityActive($em, $data)) {
            $this->logger->warning(sprintf('Produit parent #%d ignoré (inactif)', $data['id']));
            return null;
        }

        $productRepo = $em->getRepository(Product::class);
        $product = $productRepo->findOneBy(['gemsuiteProductId' => $data['id']]);
        if (!$product) {
            $product = new Product();
            $product->setGemsuiteProductId($data['id']);
        }

        $product->getCategory()->clear();
        $isWebDisplay = (bool)($data['web_display'] ?? false);

        $name = trim($data['name_fr'] ?? '');
        if ($isWebDisplay && !empty($data['web_title_fr'])) {
            $name = trim($data['web_title_fr']);
        }
        $product->setName($name);

        $description = $data['additional_fr'] ?? 'Pas de description.';
        if ($isWebDisplay && !empty($data['web_description_fr'])) {
            $description = $data['web_description_fr'];
        }
        $product->setDescription($description);

        $product->setPrice((float)($data['price'] ?? 0) * 100);

        $slug = strtolower($this->slugger->slug($name));
        if ($isWebDisplay && !empty($data['web_slug'])) {
            $slug = $data['web_slug'];
        }
        $product->setSlug($slug);

        $product->setIsWeb(true);
        $product->setIsnewarrival((bool)($data['new_product'] ?? $data['is_new_arrival'] ?? false));
        $product->setIsfeatured((bool)($data['featured'] ?? false));
        $product->setIsbestseller((bool)($data['is_bestseller'] ?? false));

        $defaultStyle = $em->getRepository(Style::class)->find(2);
        if ($defaultStyle) {
            $product->setStyle($defaultStyle);
        }

        // --- GESTION UNITÉ DE VENTE ---
        $unitId = (int)($data['unit'] ?? 0);
        if ($unitId > 0) {
            $saleUnit = $em->getRepository(\App\Entity\SaleUnit::class)->find($unitId);
            if ($saleUnit) {
                $product->setSaleUnit($saleUnit);
            }
        }

        if (isset($data['category_id'])) {
            $catId = (int)$data['category_id'];
            $category = $em->getRepository(Categories::class)->findOneBy(['gemsuiteCategoryId' => $catId]);

            if ($category) {
                // --- NOUVELLE LOGIQUE GEMS-LOCATION (PACKS) ---
                $isActive = (int)($data['status'] ?? 1) === 1;
                if ($category->getCategoryType() === 10) {
                    if ($isActive) {
                        $this->processRentalPack($em, $data);
                    } else {
                        $oldPack = $em->getRepository(RentalPack::class)->findOneBy(['gemsuiteProductId' => $data['id']]);
                        if ($oldPack) {
                            $this->logger->info("Pack #{$data['id']} inactif. Suppression du RentalPack.");
                            $em->remove($oldPack);
                        }
                    }
                    return null; // On ne crée pas de Product pour une configuration
                }

                // --- NETTOYAGE TRANSITION PACK -> PRODUIT ---
                $oldPack = $em->getRepository(RentalPack::class)->findOneBy(['gemsuiteProductId' => $data['id']]);
                if ($oldPack) {
                    $this->logger->info("L'ID #{$data['id']} n'est plus un pack. Suppression de l'ancien RentalPack.");
                    $em->remove($oldPack);
                }

                $product->addCategory($category);

                // Mettre à jour la visibilité de la catégorie si le produit est synchronisé web
                $isProductSyncWeb = (bool)($data['web_display'] ?? true);
                if ($isProductSyncWeb && !$category->isVisible() && !$category->isRentalCategory()) {
                    $category->setIsVisible(true);
                    $em->persist($category);
                }

                // TODO: TEMP WORKAROUND - Config de location
                if ($category->isRentalCategory() && $category->getCategoryType() !== 10) {
                    $this->rentalWorkaround->applyRentalProductConfiguration($product, $data);
                } else {
                    $this->rentalWorkaround->removeRentalConfiguration($product, $em);
                }

                // Associer la ShippingClass de la catégorie au produit
                $shippingClassId = $category->getExternalShippingClassId();
                if ($shippingClassId > 0) {
                    $shippingClass = $em->getRepository(ShippingClass::class)->find($shippingClassId);
                    if ($shippingClass) {
                        $shipping = $product->getProductShipping() ?? new ProductShipping();
                        $shipping->setShippingClassEntity($shippingClass);
                        $product->setProductShipping($shipping);
                    }
                }
            }
        }

        $entreprise = $em->getRepository(Entreprise::class)->findOneBy([]);
        $companyIdentifier = $entreprise ? $entreprise->getGemsuiteIdentifier() : null;
        $product->getPictures()->clear();
        $medias = $data['medias'] ?? [];
        foreach ($medias as $index => $media) {
            $path = $media['path'] ?? null;
            if (!$path) continue;

            $fullUrl = $this->imageUrlBuilder->buildUrl($companyIdentifier, $path);
            
            if ($index === 0) {
                $product->setImage($fullUrl);
            } else {
                $picture = new ProductPicture();
                $picture->setImageUrl($fullUrl);
                $product->addPicture($picture);
            }
        }

        $shipping = $product->getProductShipping() ?? new ProductShipping();

        $shipping->setWeightKg((float)($data['weight'] ?? 0));
        $shipping->setLengthCm((float)($data['dimensions_length'] ?? 0));
        $shipping->setWidthCm((float)($data['dimensions_width'] ?? 0));
        $shipping->setHeightCm((float)($data['dimensions_height'] ?? 0));
        $product->setProductShipping($shipping);

        $em->persist($product);
        $this->translationGenerator->generateTranslations($product);
        return $product;
    }

    private function processProductVariant(EntityManagerInterface $em, array $data): void
    {
        if (!$this->isEntityActive($em, $data)) {
            return; // Variante inactive
        }

        $productRepo = $em->getRepository(Product::class);
        $variantRepo = $em->getRepository(ProductVariant::class);

        $parentProductId = $data['origin_product_id'];
        $product = $productRepo->findOneBy(['gemsuiteProductId' => $parentProductId]);

        if (!$product) {
            $this->logger->warning(sprintf('Variante #%d ignorée : parent #%d non trouvé.', $data['id'], $parentProductId));
            return;
        }

        $variant = $variantRepo->findOneBy(['gemsuiteVariantId' => $data['id']]);
        if (!$variant) {
            $variant = new ProductVariant();
            $variant->setProduct($product);
            $variant->setGemsuiteVariantId($data['id']);
            $em->persist($variant);
            if (!$product->getVariants()->contains($variant)) {
                $product->addVariant($variant);
            }
        }

        $realStock = $this->stockCalculator->calculateTotalStock($data);
        $variant->setStockQuantity($realStock);

        $this->attributeProcessor->process($em, $variant, $data['attributs'] ?? []);

        $em->flush();
    }

    private function isEntityActive(EntityManagerInterface $em, array $data): bool
    {
        $status = (int)($data['status'] ?? 1);
        $syncWeb = (bool)($data['web_display'] ?? true);

        $name = trim($data['name_fr'] ?? '');
        $isVariant = !empty($data['origin_product_id']) && (int)$data['origin_product_id'] !== (int)($data['id'] ?? 0);

        // 1. Les variantes suivent le statut général et l'existence du parent
        if ($isVariant) {
            $parent = $em->getRepository(Product::class)->findOneBy(['gemsuiteProductId' => $data['origin_product_id']]);
            return $status === 1 && $syncWeb && $parent !== null;
        }

        // 2. Les produits parents dépendent de la présence de leur catégorie en base et des options syncWeb
        if (isset($data['category_id']) && !empty($name)) {
            $catId = (int)$data['category_id'];
            $category = $em->getRepository(Categories::class)->findOneBy(['gemsuiteCategoryId' => $catId]);

            if ($category) {
                return $status === 1 && ($category->isSyncWeb() || $syncWeb);
            }
        }

        return false;
    }

    private function updateSyncJobCounter(EntityManagerInterface $em, int $syncJobId): void
    {
        $em->createQuery(
            'UPDATE App\Entity\SyncJob j 
             SET j.processedItems = j.processedItems + 1 
             WHERE j.id = :id'
        )->setParameter('id', $syncJobId)
            ->execute();
    }

    private function processRentalPack(EntityManagerInterface $em, array $data): void
    {
        $repo = $em->getRepository(RentalPack::class);
        $pack = $repo->findOneBy(['gemsuiteProductId' => $data['id']]);

        if (!$pack) {
            $pack = new RentalPack();
            $pack->setGemsuiteProductId($data['id']);
        }

        $pack->setName(trim($data['name_fr'] ?? 'Pack sans nom'));
        $pack->setHourRate((float)($data['default_rate2'] ?? 0) * 100);
        $pack->setHalfDayRate((float)($data['default_rate3'] ?? 0) * 100);
        $pack->setDayRate((float)($data['default_rate4'] ?? 0) * 100);
        $pack->setWeekRate((float)($data['default_rate5'] ?? 0) * 100);
        $pack->setMonthRate((float)($data['default_rate6'] ?? 0) * 100);

        // Mapping des catégories
        foreach ($pack->getCategories()->toArray() as $oldCategory) {
            $pack->removeCategory($oldCategory);
        }

        $targetCategoriesRaw = $data['limit_location_products'] ?? '';

        // Support pour tableau JSON ou chaine csv
        if (is_array($targetCategoriesRaw)) {
            $targetCategoryGemsuiteIds = array_map('trim', $targetCategoriesRaw);
        } else {
            $targetCategoryGemsuiteIds = array_filter(array_map('trim', explode(',', (string)$targetCategoriesRaw)));
        }

        /** @var Categories[] $targetCategories */
        $targetCategories = [];

        if (empty($targetCategoryGemsuiteIds)) {
            $this->logger->info(sprintf('[processRentalPack] Pack #%d : limit_location_products vide. Association avec toutes les catégories de location.', $data['id'] ?? 0));
            $targetCategories = $em->getRepository(Categories::class)->findBy(['isRentalCategory' => true]);
        } else {
            foreach ($targetCategoryGemsuiteIds as $gemsuiteId) {
                $catId = (int)$gemsuiteId;
                $category = $em->getRepository(Categories::class)->findOneBy(['gemsuiteCategoryId' => $catId]);
                if ($category) {
                    $targetCategories[] = $category;
                }
            }
        }

        foreach ($targetCategories as $category) {
            $pack->addCategory($category);

            // Mettre à jour la granularité de tous les produits de cette catégorie
            foreach ($category->getProducts() as $product) {
                $this->rentalWorkaround->updateSmartGranularity($product);
            }
        }

        $em->persist($pack);
        $this->translationGenerator->generateTranslations($pack);
        $em->flush();
    }

    /**
     * Helper pour recuperer l ID du tenant de maniere robuste
     */
    private function getCurrentTenantId(EntityManagerInterface $em): string
    {
        return $em->getConnection()->getDatabase();
    }
}
