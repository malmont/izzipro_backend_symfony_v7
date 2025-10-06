<?php
// src/Services/GemsuiteImporterService/GemsuiteImporter.php

namespace App\Services\GemsuiteImporterService;

use App\Entity\Categories;
use App\Entity\Product;
use App\Entity\ProductShipping;
use App\Entity\GemsuiteClient;
use App\Entity\ProductVariant;
use App\Entity\Style;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\String\Slugger\SluggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use App\Services\TenantEntityManagerProvider;
use App\Entity\Entreprise;
use App\Services\GemsuiteImporterService\GemsuiteImageUrlBuilder;

class GemsuiteImporter
{
    private const GEMSUITE_API_URL = 'https://app.gem-books.com/api/';

    public function __construct(
        private HttpClientInterface $client,
        private TenantEntityManagerProvider $emProvider,
        private SluggerInterface $slugger,
        private LoggerInterface $logger,
        private GemsuiteImageUrlBuilder $imageUrlBuilder
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

            $this->logger->info(sprintf('Début de l\'importation des catégories pour le tenant "%s"', $tenantCode));
            $categoryMap = $this->importCategories($tenantEm, $gemsuiteToken);
            $this->logger->info(sprintf('Importation des catégories terminée.', $tenantCode));
            
            $this->logger->info(sprintf('Début de l\'importation des produits pour le tenant "%s"', $tenantCode));
            $entreprise = $tenantEm->getRepository(Entreprise::class)->findOneBy([]);
            $companyIdentifier = $entreprise ? $entreprise->getGemsuiteIdentifier() : null;
            $this->importProducts($tenantEm, $gemsuiteToken, $categoryMap, $companyIdentifier);
            $this->logger->info(sprintf('Importation des produits terminée.', $tenantCode));
            
            $this->logger->info(sprintf('Importation complète réussie pour le tenant "%s"', $tenantCode));

        } catch (\Throwable $e) {
            $this->logger->error('Erreur durant l\'importation GEM-SUITE: ' . $e->getMessage(), ['exception' => $e]);
            throw $e;
        }
    }

     private function importCategories(EntityManagerInterface $tenantEm, string $token): array
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
                
                $category->setName(trim($gemCategoryData['name_fr']));
                $tenantEm->persist($category);
                $categoryMap[$gemCategoryData['id']] = $category;
            }
            
            $tenantEm->flush();
            return $categoryMap;
        }
    

    private function importProducts(EntityManagerInterface $tenantEm, string $token, array $categoryMap, ?string $companyIdentifier): void
    {
        $response = $this->client->request('GET', self::GEMSUITE_API_URL . 'products', [
            'auth_bearer' => $token,
        ]);

        $data = $response->toArray();
        
        foreach ($data['data'] as $gemProductData) {
            $status = (int)($gemProductData['status'] ?? 0);
            $syncWeb = (bool)($gemProductData['sync_web'] ?? false);
            if ($status !== 1 || $syncWeb !== true) {
                $productName = $gemProductData['name_fr'] ?? 'ID ' . ($gemProductData['id'] ?? 'inconnu');
                $this->logger->info(sprintf(
                    'Produit "%s" ignoré car il n\'est pas actif pour la synchronisation web (status: %d, sync_web: %s).',
                    $productName,
                    $status,
                    $syncWeb ? 'true' : 'false'
                ));
                continue;
            }
            $product = $tenantEm->getRepository(Product::class)->findOneBy(['gemsuiteProductId' => $gemProductData['id']]);
            if (!$product) {
                $product = new Product();
                $product->setGemsuiteProductId($gemProductData['id']);
            }

            $product->setName(trim($gemProductData['name_fr']));
            $product->setDescription($gemProductData['additional_fr'] ?? 'Pas de description.');
            $priceInDollars = (float)($gemProductData['price'] ?? 0);
            $product->setPrice($priceInDollars * 100);
            $product->setSlug(strtolower($this->slugger->slug($product->getName())));
            $product->setIsWeb(true);
            $product->setIsnewarrival($gemProductData['is_new_arrival'] ?? true);
            $product->setIsbestseller($gemProductData['is_bestseller'] ?? true);
            $defaultStyle = $tenantEm->getRepository(Style::class)->find(2);
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
            
            if ($product->getVariants()->isEmpty() && empty($gemProductData['variantes'])) {
                $defaultVariant = new ProductVariant();
                $quantity = (float)($gemProductData['default_quantity'] ?? 0.0);
                $defaultVariant->setStockQuantity((int)$quantity);
                
                $product->addVariant($defaultVariant);
                $tenantEm->persist($defaultVariant);
            }

            $tenantEm->persist($product);
        }
        $tenantEm->flush();
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

}
