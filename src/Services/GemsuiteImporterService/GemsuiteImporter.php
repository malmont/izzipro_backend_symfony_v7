<?php
// src/Services/GemsuiteImporterService/GemsuiteImporter.php

namespace App\Services\GemsuiteImporterService;

use App\Entity\Categories;
use App\Entity\Product;
use App\Entity\ProductShipping;
use App\Entity\GemsuiteClient;
use App\Entity\ProductVariant;
use App\Entity\Style;
use App\Entity\ProductOption; 
use App\Entity\ProductOptionValue;
use App\Services\TranslationGeneratorService\TranslationGeneratorService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\String\Slugger\SluggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use App\Services\TenantEntityManagerProvider;
use App\Entity\Entreprise;
use App\Services\GemsuiteImporterService\GemsuiteImageUrlBuilder;
use App\Services\GemsuiteImporterService\GemsuiteAttributeProcessor;

class GemsuiteImporter
{
    private const GEMSUITE_API_URL = 'https://app.gem-books.com/api/';

    public function __construct(
        private HttpClientInterface $client,
        private TenantEntityManagerProvider $emProvider,
        private SluggerInterface $slugger,
        private LoggerInterface $logger,
        private GemsuiteImageUrlBuilder $imageUrlBuilder,
        private TranslationGeneratorService $translationGenerator,
        private GemsuiteAttributeProcessor $attributeProcessor
    ) {
    }

    public function importDataForTenant(string $tenantCode, string $gemsuiteToken): void
    {
        $this->logger->info(sprintf('Début de l\'importation complète pour le tenant "%s"', $tenantCode));

        try {
            $dbname = 'db_' . $tenantCode;
            $this->emProvider->switchTenant($dbname, $tenantCode);
            $tenantEm = $this->emProvider->getEntityManager();

            $this->logger->info(sprintf('Début de l\'importation des clients pour le tenant "%s"', $tenantCode));
            $this->importClients($tenantEm, $gemsuiteToken);
            $this->logger->info(sprintf('Importation des clients terminée.', $tenantCode));
            
            $entreprise = $tenantEm->getRepository(Entreprise::class)->findOneBy([]);
            $companyIdentifier = $entreprise ? $entreprise->getGemsuiteIdentifier() : null;
            if (!$companyIdentifier) {
                $this->logger->warning(sprintf('Identifiant GEM-SUITE non trouvé pour le tenant "%s". Les URLs d\'images pourraient être incomplètes.', $tenantCode));
            }

            $this->logger->info(sprintf('Début de l\'importation des catégories pour le tenant "%s"', $tenantCode));
            $categoryMap = $this->importCategories($tenantEm, $gemsuiteToken,$companyIdentifier);
            $this->logger->info(sprintf('Importation des catégories terminée.', $tenantCode));
            
            $this->logger->info(sprintf('Début de l\'importation des produits pour le tenant "%s"', $tenantCode));
            $this->importProducts($tenantEm, $gemsuiteToken, $categoryMap, $companyIdentifier);
            $this->logger->info(sprintf('Importation des produits terminée.', $tenantCode));
            
            $this->logger->info(sprintf('Importation complète réussie pour le tenant "%s"', $tenantCode));

        } catch (\Throwable $e) {
            $this->logger->error('Erreur durant l\'importation GEM-SUITE: ' . $e->getMessage(), ['exception' => $e]);
            throw $e;
        }
    }

    private function importCategories(EntityManagerInterface $tenantEm, string $token, ?string $companyIdentifier): array
    {
        $response = $this->client->request('GET', self::GEMSUITE_API_URL . 'categories', [
            'auth_bearer' => $token,
        ]);
        
        $data = $response->toArray();
        if (!isset($data['data'])) {
             $this->logger->error('Clé "data" manquante dans la réponse API des catégories.', ['response' => $data]);
             return [];
        }
        $categoryMap = [];

        foreach ($data['data'] as $gemCategoryData) {
            $status = (int)($gemCategoryData['status'] ?? 1);
            $syncWeb = (bool)($gemCategoryData['sync_web'] ?? true);

             if ($status !== 1 || $syncWeb !== true) {
                    $categoryName = $gemCategoryData['name_fr'] ?? 'ID ' . ($gemCategoryData['id'] ?? 'inconnue');
                    $this->logger->info(sprintf(
                        'Catégorie "%s" ignorée car elle n\'est pas active pour la synchronisation web (status: %d, sync_web: %s).',
                        $categoryName,
                        $status,
                        $syncWeb ? 'true' : 'false'
                    ));
                    continue;
                }
            $category = $tenantEm->getRepository(Categories::class)->findOneBy(['gemsuiteCategoryId' => $gemCategoryData['id']]);
            if (!$category) {
                $category = new Categories();
                $category->setGemsuiteCategoryId($gemCategoryData['id']);
            }
            $imagePath = $gemCategoryData['img_paths'] ?? null;
            $category->setImage(
                $this->imageUrlBuilder->buildUrl($companyIdentifier, $imagePath)
            );
            
            $category->setName(trim($gemCategoryData['name_fr']));
            $tenantEm->persist($category);

            $this->translationGenerator->generateTranslations($category);
            
            $categoryMap[$gemCategoryData['id']] = $category;
        }
        
        $tenantEm->flush();
        return $categoryMap;
    }
    
    private function importProducts(EntityManagerInterface $tenantEm, string $token, array $categoryMap, ?string $companyIdentifier): void
    {
        $response = $this->client->request('GET', self::GEMSUITE_API_URL . 'products', ['auth_bearer' => $token]);
        $data = $response->toArray();
        if (!isset($data['data'])) { throw new \Exception('Réponse invalide de l\'API produits'); }

        $productRepo = $tenantEm->getRepository(Product::class);
        $variantRepo = $tenantEm->getRepository(ProductVariant::class);
        
        $allGemProducts = $data['data'];
        $productMap = []; 

        $this->logger->info('Importation des produits : Passage 1 (Produits Parents)');
        foreach ($allGemProducts as $gemProductData) {
            if ($gemProductData['id'] !== $gemProductData['origin_product_id']) {
                continue; 
            }
            
            if (!$this->isProductActive($gemProductData)) {
                $this->logger->warning(sprintf('Produit parent #%d ignoré (inactif ou pas de nom)', $gemProductData['id']));
                continue;
            }

            $product = $productRepo->findOneBy(['gemsuiteProductId' => $gemProductData['id']]);
            if (!$product) {
                $product = new Product();
                $product->setGemsuiteProductId($gemProductData['id']);
            }
            
            // Mettre à jour les infos du produit parent
            $this->updateOrCreateProductParent($tenantEm, $product, $gemProductData, $categoryMap, $companyIdentifier);
            
            $tenantEm->persist($product);
            $this->translationGenerator->generateTranslations($product);
            
            $productMap[$product->getGemsuiteProductId()] = $product;
        }
        $tenantEm->flush();
        $this->logger->info('Importation des produits : Passage 2 (Variantes)');
        foreach ($allGemProducts as $gemProductData) {
            if (!$this->isProductActive($gemProductData)) {
                continue;
            }

            // Trouver le produit parent associé (via origin_product_id)
            $parentProductId = $gemProductData['origin_product_id'];
            if (!isset($productMap[$parentProductId])) {
                $this->logger->warning(sprintf('Produit/Variante Gemsuite #%d ignoré : produit parent #%d non trouvé ou inactif.', $gemProductData['id'], $parentProductId));
                continue;
            }
            $product = $productMap[$parentProductId];

            // Trouver ou créer la variante en utilisant l'ID unique de l'enregistrement Gemsuite
            $variant = $variantRepo->findOneBy(['gemsuiteVariantId' => $gemProductData['id']]);
            if (!$variant) {
                $variant = new ProductVariant();
                $variant->setProduct($product);
                $variant->setGemsuiteVariantId($gemProductData['id']); 
                $tenantEm->persist($variant);
                if (!$product->getVariants()->contains($variant)) {
                    $product->addVariant($variant);
                }
            }

            // Lier les attributs à la variante et définir sa quantité
            $this->attributeProcessor->process($tenantEm, $variant, $gemProductData['attributs'], $gemProductData['default_quantity']);
        }
        $tenantEm->flush(); // On sauvegarde les variantes
    }

    public function checkPrerequisites(string $token): void
    {
        $this->logger->info('Début de la pré-vérification des données GEM-SUITE.');
        $response = $this->client->request('GET', self::GEMSUITE_API_URL . 'categories', [
            'auth_bearer' => $token,
        ]);
        
        $data = $response->toArray();
        
        if (empty($data['data'])) {
            $this->logger->error('Pré-vérification échouée : Aucune catégorie retournée par l\'API GEM-SUITE.');
            throw new \Exception('Aucune catégorie trouvée sur GEM-SUITE. L\'importation ne peut pas être lancée.');
        }
        $this->logger->info('Pré-vérification des données GEM-SUITE réussie.');
    }

    private function importClients(EntityManagerInterface $tenantEm, string $token): void
    {
        $response = $this->client->request('GET', self::GEMSUITE_API_URL . 'clients', [
            'auth_bearer' => $token,
        ]);
        
        $data = $response->toArray();
        $clientRepo = $tenantEm->getRepository(GemsuiteClient::class);

        foreach ($data['data'] as $gemClientData) {
            $localClient = $clientRepo->findOneBy(['gemsuiteId' => $gemClientData['id']]);
            if (!$localClient) {
                $localClient = new GemsuiteClient();
                $localClient->setGemsuiteId($gemClientData['id']);
            }
            
            $localClient->setName($gemClientData['name'] ?? 'N/A');
            $email = strtolower($gemClientData['email'] ?? '');
            $localClient->setEmail(empty($email) ? null : $email);
            
            $tenantEm->persist($localClient);
        }
        
        $tenantEm->flush();
    }
   
    private function updateOrCreateProductParent(EntityManagerInterface $em, Product $product, array $gemProductData, array $categoryMap, ?string $companyIdentifier): void
    {
        $product->getCategory()->clear(); 

        $product->setName(trim($gemProductData['name_fr']));
        $product->setDescription($gemProductData['additional_fr'] ?? 'Pas de description.');
        $priceInDollars = (float)($gemProductData['price'] ?? 0);
        $product->setPrice($priceInDollars * 100); 
        $product->setSlug(strtolower($this->slugger->slug($product->getName())));
        $product->setIsWeb(true);
        $product->setIsnewarrival($gemProductData['is_new_arrival'] ?? true);
        $product->setIsbestseller($gemProductData['is_bestseller'] ?? true);

        $defaultStyle = $em->getRepository(Style::class)->find(2);
        if ($defaultStyle) { $product->setStyle($defaultStyle); }

        if (isset($gemProductData['category_id'])) {
           if (isset($categoryMap[$gemProductData['category_id']])) {
               $product->addCategory($categoryMap[$gemProductData['category_id']]);
           } else {
               $this->logger->warning(sprintf('Produit PARENT "%s" lié à une catégorie inactive. Association ignorée.', $product->getName()));
           }
        }
        
        $imagePath = $gemProductData['medias'][0]['path'] ?? null;
        $product->setImage($this->imageUrlBuilder->buildUrl($companyIdentifier, $imagePath));
        
        $shipping = $product->getProductShipping() ?? new ProductShipping();
        $shipping->setWeight((float)($gemProductData['weight'] ?? 0));
        $shipping->setLength((float)($gemProductData['dimensions_length'] ?? 0));
        $shipping->setWidth((float)($gemProductData['dimensions_width'] ?? 0));
        $shipping->setHeight((float)($gemProductData['dimensions_height'] ?? 0));
        $product->setProductShipping($shipping);

        // Fallback pour variante par défaut (votre ancienne logique)
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
}