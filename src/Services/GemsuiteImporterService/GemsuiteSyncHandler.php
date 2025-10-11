<?php
// src/Services/GemsuiteImporterService/GemsuiteSyncHandler.php

namespace App\Services\GemsuiteImporterService;

use App\Entity\Categories;
use App\Entity\Product;
use App\Entity\ProductShipping;
use App\Entity\ProductVariant;
use App\Entity\Style;
use App\Entity\GemsuiteClient;
use App\Services\TranslationGeneratorService\TranslationGeneratorService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use App\Entity\User;
use App\Entity\Entreprise;
use Symfony\Component\String\Slugger\SluggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use App\Services\GemsuiteImporterService\GemsuiteImageUrlBuilder;

class GemsuiteSyncHandler
{
    private const GEMSUITE_API_URL = 'https://app.gem-books.com/api/';

    // --- CONSTRUCTEUR MODIFIÉ ---
    public function __construct(
        private HttpClientInterface $client,
        private TenantEntityManagerProvider $emProvider,
        private TenantConnectionManager $tenantManager,
        private SluggerInterface $slugger,
        private LoggerInterface $logger,
        private GemsuiteImageUrlBuilder $imageUrlBuilder,
        private TranslationGeneratorService $translationGenerator
    ) {
    }

    /**
     * Gère la mise à jour d'un produit.
     */
    public function handleProductUpdate(string $tenantCode, int $productId): void
    {
        $this->logger->info(sprintf('Synchronisation du produit #%d pour le tenant "%s"', $productId, $tenantCode));
        $token = $this->tenantManager->getTenantToken($tenantCode);
        if (!$token) {
            $this->logger->error(sprintf('Aucun token trouvé pour le tenant "%s". Synchronisation annulée.', $tenantCode));
            return;
        }

        try {
            $response = $this->client->request('GET', self::GEMSUITE_API_URL . 'products/' . $productId, [
                'auth_bearer' => $token,
            ]);

            $gemProductData = $response->toArray()['data'] ?? null;
            if (!$gemProductData) {
                $this->logger->warning(sprintf('Produit #%d non trouvé sur GEM-SUITE pour le tenant "%s".', $productId, $tenantCode));
                return;
            }
            
            $tenantEm = $this->getTenantEntityManager($tenantCode);

            $status = (int)($gemProductData['status'] ?? 0);
            $syncWeb = (bool)($gemProductData['sync_web'] ?? false);

            if ($status === 1 && $syncWeb === true) {
                $this->logger->info(sprintf('Produit #%d actif sur GEM-SUITE. Mise à jour en cours...', $productId));
                $entreprise = $tenantEm->getRepository(Entreprise::class)->findOneBy([]);
                $companyIdentifier = $entreprise ? $entreprise->getGemsuiteIdentifier() : null;
                $categoryMap = $this->importCategories($tenantEm, $token, $companyIdentifier);
                $this->updateOrCreateProduct($tenantEm, $gemProductData, $categoryMap, $companyIdentifier);

            } else {
                $this->logger->info(sprintf('Produit #%d inactif sur GEM-SUITE. Tentative de désactivation...', $productId));
                $product = $tenantEm->getRepository(Product::class)->findOneBy(['gemsuiteProductId' => $gemProductData['id']]);
                if ($product) {
                    $product->setIsWeb(false);
                    $this->logger->info(sprintf('Le produit local "%s" a été désactivé.', $product->getName()));
                } else {
                    $this->logger->info(sprintf('Le produit inactif #%d n\'existait pas localement. Aucune action nécessaire.', $productId));
                }
            }

            $tenantEm->flush();
            $this->logger->info(sprintf('Produit #%d synchronisé avec succès pour le tenant "%s".', $productId, $tenantCode));
        } catch (\Throwable $e) {
            $this->logger->error(sprintf('Erreur lors de la synchronisation du produit #%d : %s', $productId, $e->getMessage()));
        }
    }

    /**
     * Gère la mise à jour d'une catégorie.
     */
    public function handleCategoryUpdate(string $tenantCode, int $categoryId): void
    {
        $this->logger->info(sprintf('Synchronisation des catégories (déclenchée par #%d) pour le tenant "%s"', $categoryId, $tenantCode));
        $token = $this->tenantManager->getTenantToken($tenantCode);
        if (!$token) {
            $this->logger->error(sprintf('Aucun token trouvé pour le tenant "%s".', $tenantCode));
            return;
        }
        
        try {
            $tenantEm = $this->getTenantEntityManager($tenantCode);
            $entreprise = $tenantEm->getRepository(Entreprise::class)->findOneBy([]);
            $companyIdentifier = $entreprise ? $entreprise->getGemsuiteIdentifier() : null;
            $this->importCategories($tenantEm, $token, $companyIdentifier);
            $this->logger->info(sprintf('Catégories synchronisées avec succès pour le tenant "%s".', $tenantCode));
        } catch (\Throwable $e) {
            $this->logger->error(sprintf('Erreur lors de la synchronisation des catégories pour le tenant "%s": %s', $tenantCode, $e->getMessage()));
        }
    }
    
    private function updateOrCreateProduct(EntityManagerInterface $em, array $gemProductData, array $categoryMap, ?string $companyIdentifier): void
    {
        if (!isset($gemProductData['id'], $gemProductData['name_fr'])) {
            $this->logger->warning('Données de produit GEM-SUITE incomplètes. ID ou nom manquant.');
            return;
        }
        $product = $em->getRepository(Product::class)->findOneBy(['gemsuiteProductId' => $gemProductData['id']]);
        if (!$product) {
            $product = new Product();
            $product->setGemsuiteProductId($gemProductData['id']);
        }

        $product->setName(trim($gemProductData['name_fr']));
        $product->setDescription($gemProductData['additional_fr'] ?? 'Pas de description.');
        $priceInDollars = (float)($gemProductData['price'] ?? 0);
        $product->setPrice($priceInDollars * 100);
        $product->setQuantity((int) ($gemProductData['default_quantity'] ?? 0));
        $product->setSlug(strtolower($this->slugger->slug($product->getName())));
        $product->setIsWeb(true);
        $product->setIsnewarrival($gemProductData['is_new_arrival'] ?? true);
        $product->setIsbestseller($gemProductData['is_bestseller'] ?? true);
        $defaultStyle = $em->getRepository(Style::class)->find(2);
        if ($defaultStyle) {    
                $product->setStyle($defaultStyle);
        } else {
            $this->logger->warning('Le style par défaut avec l\'ID 2 est introuvable dans la base de données du tenant.');
        }

        if (isset($gemProductData['category_id']) && isset($categoryMap[$gemProductData['category_id']])) {
            $product->addCategory($categoryMap[$gemProductData['category_id']]);
        }
        
        $imagePath = $gemProductData['medias'][0]['path'] ?? null;
        $product->setImage(
            $this->imageUrlBuilder->buildUrl($companyIdentifier, $imagePath)
        );

        $shipping = $product->getProductShipping() ?? new ProductShipping();
        $shipping->setWeight((float)($gemProductData['weight'] ?? 0));
        $shipping->setLength((float)($gemProductData['dimensions_length'] ?? 0));
        $shipping->setWidth((float)($gemProductData['dimensions_width'] ?? 0));
        $shipping->setHeight((float)($gemProductData['dimensions_height'] ?? 0));
        $product->setProductShipping($shipping);
         $variant = $product->getVariants()->first() ?: null;

        if (!$variant && empty($gemProductData['variantes'])) {
            $variant = new ProductVariant();
            $product->addVariant($variant);
            $em->persist($variant);
        }

        if ($variant) {
            $quantity = (float)($gemProductData['default_quantity'] ?? 0.0);
            $variant->setStockQuantity((int)$quantity);
        }

        $em->persist($product);

        // --- AJOUT DE LA TRADUCTION ---
        $this->translationGenerator->generateTranslations($product);
    }

    private function importCategories(EntityManagerInterface $em, string $token, ?string $companyIdentifier): array
    {
        $response = $this->client->request('GET', self::GEMSUITE_API_URL . 'categories', [
            'auth_bearer' => $token,
        ]);
        
        $data = $response->toArray();
        $categoryMap = [];

        foreach ($data['data'] as $gemCategoryData) {
            $status = (int)($gemCategoryData['status'] ?? 1);
            $syncWeb = (bool)($gemCategoryData['sync_web'] ?? true);

            if ($status !== 1 || $syncWeb !== true) {
                continue;
            }

            $category = $em->getRepository(Categories::class)->findOneBy(['gemsuiteCategoryId' => $gemCategoryData['id']]);
            if (!$category) {
                $category = new Categories();
                $category->setGemsuiteCategoryId($gemCategoryData['id']);
            }
            
            $category->setName(trim($gemCategoryData['name_fr']));
            $imagePath = $gemCategoryData['img_paths'] ?? null;
                $category->setImage(
                    $this->imageUrlBuilder->buildUrl($companyIdentifier, $imagePath)
                );
            $em->persist($category);

            // --- AJOUT DE LA TRADUCTION ---
            $this->translationGenerator->generateTranslations($category);

            $categoryMap[$gemCategoryData['id']] = $category;
        }
        $em->flush();
        return $categoryMap;
    }

    private function getTenantEntityManager(string $tenantCode): EntityManagerInterface
    {
        $dbname = 'db_' . $tenantCode;
        $this->emProvider->switchTenant($dbname, $tenantCode);
        return $this->emProvider->getEntityManager();
    }

    public function handleClientUpdate(string $tenantCode, int $clientId): void
    {
        $this->logger->info(sprintf('Synchronisation du client #%d pour le tenant "%s"', $clientId, $tenantCode));
        $token = $this->tenantManager->getTenantToken($tenantCode);
        if (!$token) {
            $this->logger->error(sprintf('Aucun token trouvé pour le tenant "%s".', $tenantCode));
            return;
        }

        try {
            $response = $this->client->request('GET', self::GEMSUITE_API_URL . 'clients/' . $clientId, [
                'auth_bearer' => $token,
            ]);

            $gemClientData = $response->toArray()['data'] ?? null;
            if (!$gemClientData) {
                $this->logger->warning(sprintf('Client #%d non trouvé sur GEM-SUITE.', $clientId));
                return;
            }

            $tenantEm = $this->getTenantEntityManager($tenantCode);

            // 2. Trouver le client local correspondant ou en créer un nouveau
            $localClient = $tenantEm->getRepository(GemsuiteClient::class)->findOneBy(['gemsuiteId' => $clientId]);
            if (!$localClient) {
                $localClient = new GemsuiteClient();
                $localClient->setGemsuiteId($clientId);
                $this->logger->info(sprintf('Nouveau client local créé pour l\'ID GEM-SUITE #%d.', $clientId));
            }

            // 3. Mettre à jour les informations
            $localClient->setName($gemClientData['name'] ?? 'N/A');
            $email = strtolower($gemClientData['email'] ?? '');
            $localClient->setEmail(empty($email) ? null : $email);

            $tenantEm->persist($localClient);
            $tenantEm->flush();
            $this->logger->info(sprintf('Client local pour l\'ID GEM-SUITE #%d synchronisé avec succès.', $clientId));

        } catch (\Throwable $e) {
            $this->logger->error(sprintf('Erreur lors de la synchronisation du client #%d : %s', $clientId, $e->getMessage()));
        }
   
}
}