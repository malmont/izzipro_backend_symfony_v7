<?php
// src/Services/GemsuiteImporterService/GemsuiteSyncHandler.php

namespace App\Services\GemsuiteImporterService;

use App\Entity\Categories;
use App\Entity\Product;
use App\Entity\ProductShipping;
use App\Entity\ProductVariant;
use App\Entity\Style;
use App\Entity\GemsuiteClient;
use App\Entity\ProductOption;
use App\Entity\ProductOptionValue;
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

    public function handleProductUpdate(string $tenantCode, int $productId): void
    {
        $this->logger->info(sprintf('Synchronisation du produit/variante #%d pour le tenant "%s"', $productId, $tenantCode));
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
                $this->logger->warning(sprintf('Produit/Variante #%d non trouvé sur GEM-SUITE pour le tenant "%s".', $productId, $tenantCode));
                return;
            }
            
            $tenantEm = $this->getTenantEntityManager($tenantCode);
            $entreprise = $tenantEm->getRepository(Entreprise::class)->findOneBy([]);
            $companyIdentifier = $entreprise ? $entreprise->getGemsuiteIdentifier() : null;

            // --- DEBUT DE LA LOGIQUE CORRIGÉE ---
            // On vérifie d'abord si le produit est actif
            if (!$this->isProductActive($gemProductData)) {
                $this->logger->info(sprintf('Produit/Variante #%d est inactif sur GEM-SUITE. Tentative de désactivation...', $productId));
                $this->deactivateProductOrVariant($tenantEm, $gemProductData);
                $this->logger->info(sprintf('Traitement terminé pour le produit/variante inactif #%d.', $productId));
            
            } else {
                // Si le produit EST actif, on détermine si c'est un parent ou un enfant
                $isParent = ($gemProductData['id'] === $gemProductData['origin_product_id']);
                
                if ($isParent) {
                    // C'est un produit parent
                    $this->logger->info(sprintf('Traitement du produit PARENT #%d.', $productId));
                    $categoryMap = $this->importCategories($tenantEm, $token, $companyIdentifier); 
                    $this->updateOrCreateProductParent($tenantEm, $gemProductData, $categoryMap, $companyIdentifier);
                } else {
                    // C'est une variante (produit enfant)
                    $this->logger->info(sprintf('Traitement de la VARIANTE #%d (parent #%d).', $productId, $gemProductData['origin_product_id']));
                    $this->updateOrCreateProductVariant($tenantEm, $gemProductData);
                }
            }
            // --- FIN DE LA LOGIQUE CORRIGÉE ---

            $tenantEm->flush();
            $this->logger->info(sprintf('Produit/Variante #%d synchronisé avec succès pour le tenant "%s".', $productId, $tenantCode));
        
        } catch (\Throwable $e) {
            $this->logger->error(sprintf('Erreur lors de la synchronisation du produit/variante #%d : %s', $productId, $e->getMessage()), ['trace' => $e->getTraceAsString()]);
        }
    }

    /**
     * Gère la mise à jour d'une catégorie.
     */
    public function handleCategoryUpdate(string $tenantCode, int $categoryId): void
    {
        // Votre logique existante
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
    
    /**
     * NOUVEAU : Gère spécifiquement les PRODUITS PARENTS.
     */
    private function updateOrCreateProductParent(EntityManagerInterface $em, array $gemProductData, array $categoryMap, ?string $companyIdentifier): void
    {
        if (!isset($gemProductData['id'], $gemProductData['name_fr'])) {
            $this->logger->warning('Données de produit (parent) GEM-SUITE incomplètes. ID ou nom manquant.');
            return;
        }
        $product = $em->getRepository(Product::class)->findOneBy(['gemsuiteProductId' => $gemProductData['id']]);
        if (!$product) {
            $product = new Product();
            $product->setGemsuiteProductId($gemProductData['id']);
        }

        $product->getCategory()->clear(); // Réinitialise les catégories

        $product->setName(trim($gemProductData['name_fr']));
        $product->setDescription($gemProductData['additional_fr'] ?? 'Pas de description.');
        $product->setPrice((float)($gemProductData['price'] ?? 0) * 100); // Gardé votre logique * 100
      
        $product->setSlug(strtolower($this->slugger->slug($product->getName())));
        $product->setIsWeb(true);
        $product->setIsnewarrival($gemProductData['is_new_arrival'] ?? true);
        $product->setIsbestseller($gemProductData['is_bestseller'] ?? true);
        
        $defaultStyle = $em->getRepository(Style::class)->find(2);
        if ($defaultStyle) {    
            $product->setStyle($defaultStyle);
        } else {
            $this->logger->warning('Le style par défaut avec l\'ID 2 est introuvable.');
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

        // Gère le cas d'un produit parent SANS attributs (ancienne logique)
        if (empty($gemProductData['attributs']) && empty($gemProductData['variantes'])) {
            $variant = $product->getVariants()->first() ?: null;
            if (!$variant) {
                $variant = new ProductVariant();
                $variant->setProduct($product);
                $product->addVariant($variant);
                $em->persist($variant);
            }
            $variant->setStockQuantity((int)($gemProductData['default_quantity'] ?? 0));
        }

        $em->persist($product);
        $this->translationGenerator->generateTranslations($product);
    }

    /**
     * NOUVEAU : Gère spécifiquement les PRODUITS ENFANTS (Variantes).
     */
    private function updateOrCreateProductVariant(EntityManagerInterface $em, array $gemProductData): void
    {
        $parentProductId = $gemProductData['origin_product_id'];
        $product = $em->getRepository(Product::class)->findOneBy(['gemsuiteProductId' => $parentProductId]);
        
        if (!$product) {
            $this.logger->error(sprintf('Variante #%d reçue, mais le produit parent #%d n\'existe pas localement. Synchro annulée.', $gemProductData['id'], $parentProductId));
            return;
        }
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
        $this->attributeProcessor->process($em, $variant, $gemProductData['attributs'], $gemProductData['default_quantity']);
    }
    /**
     * NOUVEAU : Désactive un produit ou une variante.
     */
    private function deactivateProductOrVariant(EntityManagerInterface $em, array $gemProductData): void
    {
        $isParent = ($gemProductData['id'] === $gemProductData['origin_product_id']);
        
        if ($isParent) {
            $product = $em->getRepository(Product::class)->findOneBy(['gemsuiteProductId' => $gemProductData['id']]);
            if ($product) {
                $product->setIsWeb(false);
                $this->logger->info(sprintf('Produit PARENT "%s" désactivé.', $product->getName()));
            }
        } else {
            $variant = $em->getRepository(ProductVariant::class)->findOneBy(['gemsuiteVariantId' => $gemProductData['id']]);
             if ($variant) {
                 $this->logger->info(sprintf('Variante #%d désactivée sur Gemsuite. Suppression de la variante locale...', $gemProductData['id']));
                 $em->remove($variant); // On peut décider de supprimer la variante locale
             } else {
                 $this->logger->info(sprintf('Variante inactive #%d non trouvée localement.', $gemProductData['id']));
             }
        }
    }
    
    /**
     * NOUVEAU : Vérifie si un produit/variante est actif et a un nom.
     */
    private function isProductActive(array $gemProductData): bool
    {
        $status = (int)($gemProductData['status'] ?? 0);
        $syncWeb = (bool)($gemProductData['sync_web'] ?? false);
        $name = trim($gemProductData['name_fr'] ?? '');
        return $status === 1 && $syncWeb === true && !empty($name);
    }

    /**
     * Votre méthode existante, avec la traduction et l'image
     */
    private function importCategories(EntityManagerInterface $em, string $token, ?string $companyIdentifier): array
    {
        $response = $this->client->request('GET', self::GEMSUITE_API_URL . 'categories', [
            'auth_bearer' => $token,
        ]);
        
        $data = $response->toArray();
        $categoryMap = [];

        if (!isset($data['data'])) {
             $this->logger->error('Clé "data" manquante dans la réponse API des catégories.', ['response' => $data]);
             return [];
        }

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
            
            $category->setName(trim($gemProductData['name_fr']));
            $imagePath = $gemCategoryData['img_paths'] ?? null;
                $category->setImage(
                    $this->imageUrlBuilder->buildUrl($companyIdentifier, $imagePath)
                );
            $em->persist($category);

            $this->translationGenerator->generateTranslations($category);

            $categoryMap[$gemCategoryData['id']] = $category;
        }
        $em->flush();
        return $categoryMap;
    }

    private function getTenantEntityManager(string $tenantCode): EntityManagerInterface
    {
        // Votre méthode existante
        $dbname = 'db_' . $tenantCode;
        $this->emProvider->switchTenant($dbname, $tenantCode);
        return $this->emProvider->getEntityManager();
    }

    public function handleClientUpdate(string $tenantCode, int $clientId): void
    {
        // Votre méthode existante
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

            $localClient = $tenantEm->getRepository(GemsuiteClient::class)->findOneBy(['gemsuiteId' => $clientId]);
            if (!$localClient) {
                $localClient = new GemsuiteClient();
                $localClient->setGemsuiteId($clientId);
                $this->logger->info(sprintf('Nouveau client local créé pour l\'ID GEM-SUITE #%d.', $clientId));
            }

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