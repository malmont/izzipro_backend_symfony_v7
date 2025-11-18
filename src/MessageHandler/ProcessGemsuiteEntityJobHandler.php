<?php
// src/MessageHandler/ProcessGemsuiteEntityJobHandler.php

namespace App\MessageHandler;

use App\Entity\Categories;
use App\Entity\Entreprise;
use App\Entity\GemsuiteClient;
use App\Entity\Product;
use App\Entity\ProductShipping;
use App\Entity\ProductVariant;
use App\Entity\Style;
use App\Entity\SyncJob;
use App\Message\ProcessGemsuiteEntityJob;
use App\Message\TranslateEntityJob;
use App\Services\GemsuiteImporterService\GemsuiteAttributeProcessor;
use App\Services\GemsuiteImporterService\GemsuiteImageUrlBuilder;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
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
        private SluggerInterface $slugger
    ) {
    }

    public function __invoke(ProcessGemsuiteEntityJob $message)
    {
        $data = $message->getEntityData();
        $type = $message->getEntityType();
        $entityId = $data['id'] ?? 'inconnu';

        $this->logger->info(sprintf(
            '[Micro-Job Start] Traitement de "%s" ID %s pour Tenant ID %d',
            $type, $entityId, $message->getTenantId()
        ));

        $tenant = $this->tenantManager->findTenantById($message->getTenantId());
        if (!$tenant) {
            $this->logger->error(sprintf(
                '[Micro-Job Fail] Tenant ID %d non trouvé pour le job %s ID %s.',
                $message->getTenantId(), $type, $entityId
            ));
            return;
        }

        $tenantEm = null;

        try {
            $this->emProvider->switchTenant($tenant['dbname'], $tenant['code']);
            $tenantEm = $this->emProvider->getEntityManager();
            
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
            }

            if ($entity === null && $type !== 'product_variant') {
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
            } else if ($type !== 'product_variant' && $entity !== null) {
                 $tenantEm->flush();
            }

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

        if ($status !== 1 || $syncWeb !== true) {
            $categoryName = $data['name_fr'] ?? 'ID ' . $id;
            $this->logger->warning(sprintf(
                '[processCategory ID %s] IGNORÉE. Motif : (status: %d, sync_web: %s).',
                $id, $status, $syncWeb ? 'true' : 'false'
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
        $em->persist($category);
        
        return $category;
    }

    private function processProductParent(EntityManagerInterface $em, array $data): ?Product
    {
        if (!$this->isEntityActive($data)) {
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
        $product->setName(trim($data['name_fr']));
        $product->setDescription($data['additional_fr'] ?? 'Pas de description.');
        $product->setPrice((float)($data['price'] ?? 0) * 100); 
        $product->setSlug(strtolower($this->slugger->slug($product->getName())));
        $product->setIsWeb(true);
        $product->setIsnewarrival($data['is_new_arrival'] ?? true);
        $product->setIsbestseller($data['is_bestseller'] ?? true);

        $defaultStyle = $em->getRepository(Style::class)->find(2);
        if ($defaultStyle) { $product->setStyle($defaultStyle); }

        if (isset($data['category_id'])) {
           $category = $em->getRepository(Categories::class)->findOneBy(['gemsuiteCategoryId' => $data['category_id']]);
           if ($category) {
               $product->addCategory($category);
           }
        }
        
        $entreprise = $em->getRepository(Entreprise::class)->findOneBy([]);
        $companyIdentifier = $entreprise ? $entreprise->getGemsuiteIdentifier() : null;
        $imagePath = $data['medias'][0]['path'] ?? null;
        
        if (!empty($imagePath)) {
            $product->setImage($this->imageUrlBuilder->buildUrl($companyIdentifier, $imagePath));
        }
        
        $shipping = $product->getProductShipping() ?? new ProductShipping();
        
        $shipping->setWeightKg((float)($data['weight'] ?? 0));
        $shipping->setLengthCm((float)($data['dimensions_length'] ?? 0));
        $shipping->setWidthCm((float)($data['dimensions_width'] ?? 0));
        $shipping->setHeightCm((float)($data['dimensions_height'] ?? 0));
        $product->setProductShipping($shipping);

        $em->persist($product); 
        return $product;
    }

    private function processProductVariant(EntityManagerInterface $em, array $data): void
    {
        if (!$this->isEntityActive($data)) {
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
        
        $this->attributeProcessor->process($em, $variant, $data['attributs'], $data['default_quantity']);
        
        $em->flush();
    }

    private function isEntityActive(array $data): bool
    {
        $status = (int)($data['status'] ?? 1);
        $syncWeb = (bool)($data['sync_web'] ?? true);
        
        $name = trim($data['name_fr'] ?? '');
        $isVariant = ($data['id'] ?? 0) !== ($data['origin_product_id'] ?? 0);
        
        if ($isVariant) {
            return $status === 1 && $syncWeb === true;
        } else {
            return $status === 1 && $syncWeb === true && !empty($name);
        }
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
}