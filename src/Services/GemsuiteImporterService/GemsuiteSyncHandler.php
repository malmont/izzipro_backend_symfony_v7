<?php

namespace App\Services\GemsuiteImporterService;

use App\Entity\Categories;
use App\Entity\Product;
use App\Entity\ProductShipping;
use App\Entity\ProductVariant;
use App\Entity\RentalPack;
use App\Entity\SaleUnit;
use App\Entity\ShippingClass;
use App\Entity\Style;
use App\Entity\GemsuiteClient;
use App\Entity\Entreprise;
use App\Services\TranslationGeneratorService\TranslationGeneratorService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\String\Slugger\SluggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use App\Services\GemsuiteImporterService\GemsuiteClientManager;

class GemsuiteSyncHandler
{

    public function __construct(
        private HttpClientInterface $client,
        private TenantEntityManagerProvider $emProvider,
        private TenantConnectionManager $tenantManager,
        private SluggerInterface $slugger,
        private LoggerInterface $logger,
        private GemsuiteImageUrlBuilder $imageUrlBuilder,
        private TranslationGeneratorService $translationGenerator,
        private GemsuiteAttributeProcessor $attributeProcessor,
        private GemsuiteStockCalculator $stockCalculator,
        private string $gemsuiteApiUrl,
        private GemsuiteClientManager $clientManager,
        private GemsuiteRentalWorkaroundService $rentalWorkaround
    ) {}

    /**
     * Point d'entrée principal pour les Webhooks Produits
     */
    public function handleProductUpdate(string $tenantCode, int $productId): void
    {
        $this->logger->info(sprintf('Webhook Produit: Sync ID #%d pour tenant "%s"', $productId, $tenantCode));
        $token = $this->tenantManager->getTenantToken($tenantCode);

        if (!$token) {
            $this->logger->error("Token manquant pour le tenant $tenantCode");
            return;
        }

        try {
            $gemProductData = $this->fetchProductFromApi($productId, $token);

            if (!$gemProductData) {
                $this->logger->warning("Produit #$productId introuvable sur l'API (ou erreur réseau).");
                return;
            }

            $tenantEm = $this->getTenantEntityManager($tenantCode);
            $entreprise = $tenantEm->getRepository(Entreprise::class)->findOneBy([]);
            $companyIdentifier = $entreprise ? $entreprise->getGemsuiteIdentifier() : null;

            // --- ROBUSTESSE WEBHOOK ---
            // On synchronise les catégories au début pour éviter de désactiver un produit 
            // dont la nouvelle catégorie n'existe pas encore localement (Race Condition).
            [$categoryMap, $shippingClassMap] = $this->importCategories($tenantEm, $token, $companyIdentifier);

            if (!$this->isProductActive($tenantEm, $gemProductData)) {
                $this->logger->info("Produit #$productId détecté comme inactif. Désactivation locale.");
                $this->deactivateProductOrVariant($tenantEm, $gemProductData);
            } else {
                $isParent = ($gemProductData['id'] === $gemProductData['origin_product_id']);

                if ($isParent) {
                    $this->logger->info("Traitement du produit PARENT #$productId");
                    $this->updateOrCreateProductParent($tenantEm, $gemProductData, $categoryMap, $shippingClassMap, $companyIdentifier);

                    if (!empty($gemProductData['attributs'])) {
                        $this->logger->info("Parent #$productId possède des attributs : Traitement comme Variante hybride.");
                        $this->updateOrCreateProductVariant($tenantEm, $gemProductData, $token, $companyIdentifier);
                    }
                } else {
                    $this->logger->info("Traitement de la VARIANTE #$productId (Liée au Parent #{$gemProductData['origin_product_id']})");
                    $this->updateOrCreateProductVariant($tenantEm, $gemProductData, $token, $companyIdentifier);
                }
            }

            $tenantEm->flush();
            $this->logger->info("Sync terminée avec succès pour #$productId");
        } catch (\Throwable $e) {

            $this->logger->error("Erreur Critique Sync Produit #$productId : " . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
        }
    }

    public function handleCategoryUpdate(string $tenantCode, int $categoryId): void
    {
        $this->logger->info(sprintf('Webhook Catégorie #%d', $categoryId));
        $token = $this->tenantManager->getTenantToken($tenantCode);
        if (!$token) return;
        try {
            $tenantEm = $this->getTenantEntityManager($tenantCode);
            $entreprise = $tenantEm->getRepository(Entreprise::class)->findOneBy([]);
            $companyIdentifier = $entreprise ? $entreprise->getGemsuiteIdentifier() : null;
            $this->importCategories($tenantEm, $token, $companyIdentifier);
        } catch (\Throwable $e) {
            $this->logger->error("Erreur Sync Catégorie: " . $e->getMessage());
        }
    }

    public function handleClientUpdate(string $tenantCode, int $clientId): void
    {
        $this->clientManager->updateClientGemsuite($tenantCode, $clientId);
    }

    /**
     * Webhook Véhicule
     */
    public function handleVehicleUpdate(string $tenantCode, int $vehicleId): void
    {
        $this->logger->info(sprintf('Webhook Véhicule: Sync ID #%d pour tenant "%s"', $vehicleId, $tenantCode));
        $token = $this->tenantManager->getTenantToken($tenantCode);

        if (!$token) {
            $this->logger->error("Token manquant pour le tenant $tenantCode (Action: Sync Véhicule)");
            return;
        }

        try {
            $vehicleData = $this->fetchVehicleFromApi($vehicleId, $token);

            if (!$vehicleData) {
                $this->logger->warning("Véhicule #$vehicleId introuvable sur l'API.");
                return;
            }

            $tenantEm = $this->getTenantEntityManager($tenantCode);
            // Association Véhicule <-> Produit
            $this->rentalWorkaround->syncVehicles([$vehicleData]);

            $tenantEm->flush();
            $this->logger->info("Sync Véhicule terminée avec succès pour #$vehicleId");
        } catch (\Throwable $e) {
            $this->logger->error("Erreur Sync Véhicule #$vehicleId : " . $e->getMessage());
        }
    }

    /**
     * Webhook Réservations (Rentals)
     */
    public function handleRentalUpdate(string $tenantCode, int $vehicleId): void
    {
        $this->logger->info(sprintf('Webhook Rentals: Sync ID #%d pour tenant "%s"', $vehicleId, $tenantCode));
        $token = $this->tenantManager->getTenantToken($tenantCode);

        if (!$token) {
            $this->logger->error("Token manquant pour le tenant $tenantCode (Action: Sync Rentals)");
            return;
        }

        try {
            $appointments = $this->fetchRentalsFromApi($vehicleId, $token);

            // On switch l'EM pour le tenant
            $this->getTenantEntityManager($tenantCode);

            // Synchro des rendez-vous
            $this->rentalWorkaround->syncRentalsForVehicle($vehicleId, $appointments);

            $this->logger->info("Sync Rentals terminée avec succès pour véhicule #$vehicleId");
        } catch (\Throwable $e) {
            $this->logger->error("Erreur Sync Rentals #$vehicleId : " . $e->getMessage());
        }
    }

    /**
     * Webhook Ventes (Sales)
     * Coordonne la synchro Stock (Retail) et Disponibilités (Rentals)
     */
    public function handleSaleUpdate(string $tenantCode, int $saleId): void
    {
        $this->logger->info(sprintf('Webhook Sale: Sync ID #%d pour tenant "%s"', $saleId, $tenantCode));
        $token = $this->tenantManager->getTenantToken($tenantCode);

        if (!$token) {
            $this->logger->error("Token manquant pour le tenant $tenantCode (Action: Sync Sale)");
            return;
        }

        try {
            $this->logger->info("Appel API pour Sale #$saleId...");
            $saleData = $this->fetchSaleFromApi($saleId, $token);
            
            if (!$saleData) {
                $this->logger->warning("Vente #$saleId introuvable sur l'API.");
                return;
            }

            $this->logger->info(sprintf("Vente #%d récupérée. Analyse de %d lignes...", $saleId, count($saleData['products_lines'] ?? [])));

            $productIds = [];
            $carIds = [];

            foreach ($saleData['products_lines'] ?? [] as $line) {
                // 1. Collecte des IDs de produits (Retail)
                if (isset($line['product_id']) && (int)$line['product_id'] > 0) {
                    $productIds[] = (int) $line['product_id'];
                    $this->logger->info("  - Produit trouvé : #" . $line['product_id']);
                }
                // 2. Collecte des IDs de véhicules (Rentals)
                if (isset($line['car_id']) && (int)$line['car_id'] > 0) {
                    $carIds[] = (int) $line['car_id'];
                    $this->logger->info("  - Véhicule trouvé : #" . $line['car_id']);
                }
            }

            // On filtre les doublons pour optimiser
            $productIds = array_unique($productIds);
            $carIds = array_unique($carIds);

            $this->logger->info(sprintf("Lancement de la synchro pour %d produits et %d véhicules.", count($productIds), count($carIds)));

            foreach ($productIds as $pId) {
                $this->logger->info("Sync Produit #$pId en cours...");
                $this->handleProductUpdate($tenantCode, $pId);
            }

            foreach ($carIds as $cId) {
                $this->logger->info("Sync Rentals pour Véhicule #$cId en cours...");
                $this->handleRentalUpdate($tenantCode, $cId);
            }

            $this->logger->info(sprintf('Sync Sale #%d terminée avec succès.', $saleId));
        } catch (\Throwable $e) {
            $this->logger->error("Erreur Sync Sale #$saleId : " . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
        }
    }

    /**
     * Webhook Ligne de Vente (Sale Product)
     * Utile pour capter les Estimations (Quotes) avant qu'elles soient facturées
     */
    /*
    public function handleSaleProductUpdate(string $tenantCode, int $lineId): void
    {
        $this->logger->info(sprintf('Webhook SaleProduct: Sync ID #%d pour tenant "%s"', $lineId, $tenantCode));
        $token = $this->tenantManager->getTenantToken($tenantCode);

        if (!$token) {
            $this->logger->error("Token manquant pour le tenant $tenantCode (Action: Sync SaleProduct)");
            return;
        }

        try {
            $lineData = $this->fetchSaleProductFromApi($lineId, $token);
            
            if (!$lineData) {
                $this->logger->warning("Ligne de vente #$lineId introuvable sur l'API.");
                return;
            }

            // On déclenche la synchro des entités liées à cette ligne
            
            // 1. Produit (Retail / Stock)
            if (isset($lineData['product_id']) && (int)$lineData['product_id'] > 0) {
                $this->logger->info("  - Déclenchement Sync Produit #" . $lineData['product_id']);
                $this->handleProductUpdate($tenantCode, (int)$lineData['product_id']);
            }

            // 2. Véhicule (Rentals / Calendar)
            if (isset($lineData['car_id']) && (int)$lineData['car_id'] > 0) {
                $this->logger->info("  - Déclenchement Sync Calendar pour Véhicule #" . $lineData['car_id']);
                $this->handleRentalUpdate($tenantCode, (int)$lineData['car_id']);
            }

            $this->logger->info(sprintf('Sync SaleProduct #%d terminée.', $lineId));
        } catch (\Throwable $e) {
            $this->logger->error("Erreur Sync SaleProduct #$lineId : " . $e->getMessage());
        }
    }
    */

    private function fetchSaleFromApi(int $id, string $token): ?array
    {
        try {
            $response = $this->client->request('GET', $this->gemsuiteApiUrl . 'sales/' . $id, [
                'auth_bearer' => $token,
            ]);
            $data = $response->toArray()['data'] ?? null;
            
            // Gestion format tableau
            if (is_array($data) && isset($data[0])) {
                return $data[0];
            }
            
            return $data;
        } catch (\Throwable $e) {
            $this->logger->error("API Fail pour vente #$id: " . $e->getMessage());
            return null;
        }
    }

    /*
    private function fetchSaleProductFromApi(int $id, string $token): ?array
    {
        try {
            $response = $this->client->request('GET', $this->gemsuiteApiUrl . 'sales_products/' . $id, [
                'auth_bearer' => $token,
            ]);
            $data = $response->toArray()['data'] ?? null;

            // Gestion format tableau (souvent le cas pour sales_products)
            if (is_array($data) && isset($data[0])) {
                return $data[0];
            }

            return $data;
        } catch (\Throwable $e) {
            $this->logger->error("API Fail pour ligne de vente #$id: " . $e->getMessage());
            return null;
        }
    }
    */


    private function fetchProductFromApi(int $id, string $token): ?array
    {
        try {
            $response = $this->client->request('GET', $this->gemsuiteApiUrl . 'products/' . $id, [
                'auth_bearer' => $token,
            ]);
            return $response->toArray()['data'] ?? null;
        } catch (\Throwable $e) {
            $this->logger->error("API Fail pour produit #$id: " . $e->getMessage());
            return null;
        }
    }

    private function fetchVehicleFromApi(int $id, string $token): ?array
    {
        try {
            $response = $this->client->request('GET', $this->gemsuiteApiUrl . 'vehicles/' . $id, [
                'auth_bearer' => $token,
            ]);
            return $response->toArray()['data'] ?? null;
        } catch (\Throwable $e) {
            $this->logger->error("API Fail pour véhicule #$id: " . $e->getMessage());
            return null;
        }
    }

    private function fetchRentalsFromApi(int $vehicleId, string $token): array
    {
        try {
            $response = $this->client->request('GET', $this->gemsuiteApiUrl . 'rentals/' . $vehicleId, [
                'auth_bearer' => $token,
            ]);
            $data = $response->toArray();

            // Gestion de formats de retour flexibles (Objet ou Tableau dans 'data')
            if (isset($data['data']['appointments'])) {
                return $data['data']['appointments'];
            }

            if (isset($data['data'][0]['appointments'])) {
                return $data['data'][0]['appointments'];
            }

            return $data['appointments'] ?? [];
        } catch (\Throwable $e) {
            $this->logger->error("API Fail pour rentals véhicule #$vehicleId: " . $e->getMessage());
            return [];
        }
    }

    private function updateOrCreateProductParent(EntityManagerInterface $em, array $gemProductData, array $categoryMap, array $shippingClassMap, ?string $companyIdentifier): void
    {
        if (!isset($gemProductData['id'], $gemProductData['name_fr'])) {
            return;
        }

        $product = $em->getRepository(Product::class)->findOneBy(['gemsuiteProductId' => $gemProductData['id']]);

        // --- NOUVELLE LOGIQUE GEMS-LOCATION (PACKS) ---
        if (isset($gemProductData['category_id']) && isset($categoryMap[$gemProductData['category_id']])) {
            $category = $categoryMap[$gemProductData['category_id']];
            if ($category->getCategoryType() === 10) {
                $this->processRentalPack($em, $gemProductData);
                if ($product) {
                    $em->remove($product); // On nettoie si un produit existait par erreur
                }
                return;
            }
        }

        // --- NETTOYAGE TRANSITION PACK -> PRODUIT ---
        // Si cet ID était un pack mais ne l'est plus (ou n'est plus dans une catégorie de type 10),
        // on supprime l'éventuel RentalPack existant pour laisser place au Produit normal.
        $oldPack = $em->getRepository(RentalPack::class)->findOneBy(['gemsuiteProductId' => $gemProductData['id']]);
        if ($oldPack) {
            $this->logger->info("L'ID #{$gemProductData['id']} n'est plus un pack. Suppression de l'ancien RentalPack.");
            $em->remove($oldPack);
        }

        if (!$product) {
            $product = new Product();
            $product->setGemsuiteProductId($gemProductData['id']);
        }

        $product->getCategory()->clear();
        $product->setName(trim($gemProductData['name_fr']));
        $product->setDescription($gemProductData['additional_fr'] ?? 'Pas de description.');
        $product->setPrice((float)($gemProductData['price'] ?? 0) * 100);
        $product->setSlug(strtolower($this->slugger->slug($product->getName())));
        $product->setIsWeb(true);
        $product->setIsnewarrival((bool)($gemProductData['is_new_arrival'] ?? true));
        $product->setIsbestseller((bool)($gemProductData['is_bestseller'] ?? true));

        // --- GESTION UNITÉ DE VENTE ---
        $unitId = (int)($gemProductData['unit'] ?? 0);
        if ($unitId > 0) {
            $saleUnit = $em->getRepository(SaleUnit::class)->find($unitId);
            if ($saleUnit) {
                $product->setSaleUnit($saleUnit);
            }
        } else {
            $product->setSaleUnit(null);
        }


        $defaultStyle = $em->getRepository(Style::class)->find(2);
        if ($defaultStyle) {
            $product->setStyle($defaultStyle);
        }

        if (isset($gemProductData['category_id']) && isset($categoryMap[$gemProductData['category_id']])) {
            $category = $categoryMap[$gemProductData['category_id']];
            $product->addCategory($category);

            if ($category->isRentalCategory()) {
                $this->rentalWorkaround->applyRentalProductConfiguration($product, $gemProductData);
            } else {
                // --- NETTOYAGE RENTAL (MODIF WEBHOOK) ---
                // Si le produit n'est plus dans une catégorie de location, on le repasse en mode RETAIL.
                $this->rentalWorkaround->removeRentalConfiguration($product, $em);
            }
        }

        $imagePath = $gemProductData['medias'][0]['path'] ?? null;
        if ($imagePath) {
            $product->setImage($this->imageUrlBuilder->buildUrl($companyIdentifier, $imagePath));
        }

        $shipping = $product->getProductShipping();
        if (!$shipping) {
            $shipping = new ProductShipping();
            $product->setProductShipping($shipping);
            $em->persist($shipping);
        }
        $shipping->setWeightKg((float)($gemProductData['weight'] ?? 0));
        $shipping->setLengthCm((float)($gemProductData['dimensions_length'] ?? 0));
        $shipping->setWidthCm((float)($gemProductData['dimensions_width'] ?? 0));
        $shipping->setHeightCm((float)($gemProductData['dimensions_height'] ?? 0));

        // --- GESTION SHIPPING CLASS VIA CATEGORIE ---
        $categoryId = $gemProductData['category_id'] ?? null;
        if ($categoryId && isset($shippingClassMap[$categoryId])) {
            $shippingClassId = (int) $shippingClassMap[$categoryId];
            $shippingClass = $shippingClassId > 0 ? $em->getRepository(ShippingClass::class)->find($shippingClassId) : null;
            if ($shippingClass) {
                $shipping->setShippingClassEntity($shippingClass);
            }
        }


        // Gestion du produit simple (sans attributs ni variantes distinctes) - ID 32
        if (empty($gemProductData['attributs']) && empty($gemProductData['variantes'])) {
            $variant = $product->getVariants()->first() ?: null;
            if (!$variant) {
                $variant = new ProductVariant();
                $variant->setProduct($product);
                $variant->setGemsuiteVariantId($gemProductData['id'] . '-default');
                $product->addVariant($variant);
                $em->persist($variant);
            }

            // Calcul du stock pour le produit simple (Parent = Stock)
            $realStock = $this->stockCalculator->calculateTotalStock($gemProductData);
            $variant->setStockQuantity($realStock);
        }
        if (!empty($gemProductData['attributs']) && empty($gemProductData['variantes'])) {
            // Skip
        }

        $em->persist($product);

        if (method_exists($this->translationGenerator, 'generateTranslations')) {
            $this->translationGenerator->generateTranslations($product);
        }
    }

    /**
     * C'est ICI que la magie des attributs opère.
     * Gestion du Stock corrigée : Base + Ajustements
     */
    private function updateOrCreateProductVariant(EntityManagerInterface $em, array $gemProductData, string $token, ?string $companyIdentifier): void
    {
        $parentProductId = $gemProductData['origin_product_id'];
        $productRepo = $em->getRepository(Product::class);

        // 1. Récupération du Parent
        $product = $productRepo->findOneBy(['gemsuiteProductId' => $parentProductId]);

        // Auto-fix si le parent n'existe pas encore
        if (!$product) {
            $this->logger->warning(sprintf('AUTO-FIX: Parent #%d manquant pour la variante #%d. Téléchargement immédiat...', $parentProductId, $gemProductData['id']));

            $parentData = $this->fetchProductFromApi($parentProductId, $token);

            if ($parentData) {
                [$catMap, $shpClassMap] = $this->importCategories($em, $token, $companyIdentifier);
                $this->updateOrCreateProductParent($em, $parentData, $catMap, $shpClassMap, $companyIdentifier);
                $em->flush();
                $product = $productRepo->findOneBy(['gemsuiteProductId' => $parentProductId]);
            }
        }

        if (!$product) {
            $this->logger->error(sprintf('ABANDON: Impossible de créer la variante #%d car le parent #%d reste introuvable.', $gemProductData['id'], $parentProductId));
            return;
        }

        // 2. Récupération/Création de la Variante
        $variant = $em->getRepository(ProductVariant::class)->findOneBy(['gemsuiteVariantId' => $gemProductData['id']]);
        if (!$variant) {
            $variant = new ProductVariant();
            $variant->setProduct($product);
            $variant->setGemsuiteVariantId($gemProductData['id']);
            $em->persist($variant);

            if (!$product->getVariants()->contains($variant)) {
                $product->addVariant($variant);
            }
        }

        // 3. Calcul du Stock (Addition Base + Tableau)
        $realStock = $this->stockCalculator->calculateTotalStock($gemProductData);

        // 4. Application du Stock à la variante
        $variant->setStockQuantity($realStock);

        // 5. Gestion des attributs (Couleur/Taille)
        if (isset($gemProductData['attributs']) && !empty($gemProductData['attributs'])) {
            $this->attributeProcessor->process(
                $em,
                $variant,
                $gemProductData['attributs']
            );
        }
    }



    private function importCategories(EntityManagerInterface $em, string $token, ?string $companyIdentifier): array
    {
        $response = $this->client->request('GET', $this->gemsuiteApiUrl . 'categories', [
            'auth_bearer' => $token,
        ]);
        $data = $response->toArray();
        $categoryMap = [];
        $shippingClassMap = [];

        if (!isset($data['data'])) return [[], []];

        foreach ($data['data'] as $gemCategoryData) {
            $status = (int)($gemCategoryData['status'] ?? 1);
            $syncWeb = (bool)($gemCategoryData['sync_web'] ?? true);

            if ($status !== 1 || $syncWeb !== true) {
                $categoryToDelete = $em->getRepository(Categories::class)->findOneBy(['gemsuiteCategoryId' => $gemCategoryData['id']]);
                if ($categoryToDelete) {
                    $this->logger->info("Suppression de la catégorie #{$gemCategoryData['id']} car inactive ou sync_web=false.");
                    $em->remove($categoryToDelete);
                }
                continue;
            }


            $category = $em->getRepository(Categories::class)->findOneBy(['gemsuiteCategoryId' => $gemCategoryData['id']]);
            if (!$category) {
                $category = new Categories();
                $category->setGemsuiteCategoryId($gemCategoryData['id']);
            }

            $category->setName(trim($gemCategoryData['name_fr'] ?? 'Catégorie'));

            // --- NOUVELLE LOGIQUE LOCATION ---
            $wasRental = $category->isRentalCategory();
            $category->setCategoryType((int)($gemCategoryData['category_type'] ?? 0));
            $isRental = (int)($gemCategoryData['limit_lot'] ?? 0) === 1;
            $category->setIsRentalCategory($isRental);

            // --- NETTOYAGE TRANSITION CATEGORIE (Rental -> Normal) ---
            if ($wasRental === true && $isRental === false) {
                $this->logger->info("Catégorie #{$gemCategoryData['id']} passée de Rental à Normal. Nettoyage des produits.");
                foreach ($category->getProducts() as $product) {
                    $this->rentalWorkaround->removeRentalConfiguration($product, $em);
                }
            }

            $imagePath = $gemCategoryData['img_paths'] ?? null;
            if (!empty($imagePath)) {
                $category->setImage($this->imageUrlBuilder->buildUrl($companyIdentifier, $imagePath));
            }

            $em->persist($category);
            if (method_exists($this->translationGenerator, 'generateTranslations')) {
                $this->translationGenerator->generateTranslations($category);
            }
            $categoryMap[$gemCategoryData['id']] = $category;
            $shippingClassMap[$gemCategoryData['id']] = $gemCategoryData['expedition_classes'] ?? 0;
        }
        $em->flush();
        return [$categoryMap, $shippingClassMap];
    }

    private function deactivateProductOrVariant(EntityManagerInterface $em, array $gemProductData): void
    {
        $isParent = ($gemProductData['id'] === $gemProductData['origin_product_id']);

        if ($isParent) {
            $product = $em->getRepository(Product::class)->findOneBy(['gemsuiteProductId' => $gemProductData['id']]);
            if ($product) {
                $product->setIsWeb(false);
            }
        } else {
            $variant = $em->getRepository(ProductVariant::class)->findOneBy(['gemsuiteVariantId' => $gemProductData['id']]);
            if ($variant) {
                $em->remove($variant);
            }
        }
    }

    private function isProductActive(EntityManagerInterface $em, array $gemProductData): bool
    {
        $status = (int)($gemProductData['status'] ?? 1);
        $syncWeb = (bool)($gemProductData['sync_web'] ?? true);
        $name = trim($gemProductData['name_fr'] ?? '');

        $isVariant = ($gemProductData['id'] ?? 0) !== ($gemProductData['origin_product_id'] ?? 0);

        // 1. Variantes
        if ($isVariant) {
            return $status === 1 && $syncWeb;
        }

        // 2. Produits Parents (Filtre par catégorie)
        if (isset($gemProductData['category_id']) && !empty($name)) {
            $catId = (int)$gemProductData['category_id'];
            $category = $em->getRepository(Categories::class)->findOneBy(['gemsuiteCategoryId' => $catId]);

            if ($category) {
                // On vérifie quand même le statut et sync_web ici
                return $status === 1 && $syncWeb;
            }
        }

        return false;
    }

    private function getTenantEntityManager(string $tenantCode): EntityManagerInterface
    {
        $dbname = 'db_' . $tenantCode;
        $this->emProvider->switchTenant($dbname, $tenantCode);
        return $this->emProvider->getEntityManager();
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
            $this->logger->info(sprintf('[processRentalPack Webhook] Pack #%d : limit_location_products vide. Association avec toutes les catégories de location.', $data['id'] ?? 0));
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
