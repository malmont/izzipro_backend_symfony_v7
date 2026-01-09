<?php

namespace App\Services\GemsuiteImporterService;

use App\Entity\Categories;
use App\Entity\Product;
use App\Entity\ProductShipping;
use App\Entity\ProductVariant;
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
        private string $gemsuiteApiUrl
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

            if (!$this->isProductActive($gemProductData)) {
                $this->logger->info("Produit #$productId détecté comme inactif. Désactivation locale.");
                $this->deactivateProductOrVariant($tenantEm, $gemProductData);
            } else {
                $isParent = ($gemProductData['id'] === $gemProductData['origin_product_id']);

                if ($isParent) {
                    $this->logger->info("Traitement du produit PARENT #$productId");
                    $categoryMap = $this->importCategories($tenantEm, $token, $companyIdentifier);
                    $this->updateOrCreateProductParent($tenantEm, $gemProductData, $categoryMap, $companyIdentifier);

                    if (!empty($gemProductData['attributs'])) {
                        $this->logger->info("Parent #$productId possède des attributs : Traitement comme Variante hybride.");
                        $this->updateOrCreateProductVariant($tenantEm, $gemProductData, $token, $companyIdentifier);
                    }
                    // -----------------------------

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
        $this->logger->info(sprintf('Webhook Client #%d', $clientId));
    }

    // -------------------------------------------------------------------------
    // MÉTHODES PRIVÉES (LOGIQUE MÉTIER)
    // -------------------------------------------------------------------------

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

    private function updateOrCreateProductParent(EntityManagerInterface $em, array $gemProductData, array $categoryMap, ?string $companyIdentifier): void
    {
        if (!isset($gemProductData['id'], $gemProductData['name_fr'])) {
            return;
        }

        $product = $em->getRepository(Product::class)->findOneBy(['gemsuiteProductId' => $gemProductData['id']]);
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

        $defaultStyle = $em->getRepository(Style::class)->find(2);
        if ($defaultStyle) {
            $product->setStyle($defaultStyle);
        }

        if (isset($gemProductData['category_id']) && isset($categoryMap[$gemProductData['category_id']])) {
            $product->addCategory($categoryMap[$gemProductData['category_id']]);
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
            $realStock = $this->calculateTotalStock($gemProductData);
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
                $catMap = $this->importCategories($em, $token, $companyIdentifier);
                $this->updateOrCreateProductParent($em, $parentData, $catMap, $companyIdentifier);
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
        $realStock = $this->calculateTotalStock($gemProductData);

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

    /**
     * Calcule le stock TOTAL en additionnant la quantité par défaut
     * et les ajustements du tableau 'quantite'.
     */
    private function calculateTotalStock(array $data): int
    {
        // 1. Stock de base (Fiche produit)
        $baseStock = (float) ($data['default_quantity'] ?? 0);

        // 2. Ajustements (Mouvements entrepôt, réservations...)
        $adjustments = 0.0;
        if (!empty($data['quantite']) && is_array($data['quantite'])) {
            foreach ($data['quantite'] as $q) {
                // On additionne tous les mouvements du tableau
                $adjustments += (float) ($q['quantite'] ?? 0);
            }
        }

        // 3. Retourne la somme
        return (int) ($adjustments);
    }

    private function importCategories(EntityManagerInterface $em, string $token, ?string $companyIdentifier): array
    {
        $response = $this->client->request('GET', $this->gemsuiteApiUrl . 'categories', [
            'auth_bearer' => $token,
        ]);
        $data = $response->toArray();
        $categoryMap = [];

        if (!isset($data['data'])) return [];

        foreach ($data['data'] as $gemCategoryData) {
            $status = (int)($gemCategoryData['status'] ?? 1);
            $syncWeb = (bool)($gemCategoryData['sync_web'] ?? true);

            if ($status !== 1 || $syncWeb !== true) continue;

            $category = $em->getRepository(Categories::class)->findOneBy(['gemsuiteCategoryId' => $gemCategoryData['id']]);
            if (!$category) {
                $category = new Categories();
                $category->setGemsuiteCategoryId($gemCategoryData['id']);
            }

            $category->setName(trim($gemCategoryData['name_fr'] ?? 'Catégorie'));

            $imagePath = $gemCategoryData['img_paths'] ?? null;
            if (!empty($imagePath)) {
                $category->setImage($this->imageUrlBuilder->buildUrl($companyIdentifier, $imagePath));
            }

            $em->persist($category);
            if (method_exists($this->translationGenerator, 'generateTranslations')) {
                $this->translationGenerator->generateTranslations($category);
            }
            $categoryMap[$gemCategoryData['id']] = $category;
        }
        $em->flush();
        return $categoryMap;
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

    private function isProductActive(array $gemProductData): bool
    {
        $status = (int)($gemProductData['status'] ?? 0);
        $syncWeb = (bool)($gemProductData['sync_web'] ?? false);
        $name = trim($gemProductData['name_fr'] ?? '');
        return $status === 1 && $syncWeb === true && !empty($name);
    }

    private function getTenantEntityManager(string $tenantCode): EntityManagerInterface
    {
        $dbname = 'db_' . $tenantCode;
        $this->emProvider->switchTenant($dbname, $tenantCode);
        return $this->emProvider->getEntityManager();
    }
}
