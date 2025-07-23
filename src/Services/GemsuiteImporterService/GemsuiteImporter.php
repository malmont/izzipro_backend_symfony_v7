<?php

namespace App\Services\GemsuiteImporterService;

use App\Entity\Categories;
use App\Entity\Product;
use App\Entity\ProductShipping;
use App\Entity\ProductVariant;
use App\Entity\Style;
use Psr\Log\LoggerInterface;
use Symfony\Component\String\Slugger\SluggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Doctrine\ORM\EntityManagerInterface;
use App\Services\TenantEntityManagerProvider;


class GemsuiteImporter
{
    private const GEMSUITE_API_URL = 'https://app.gem-books.com/api/';

    public function __construct(
        private HttpClientInterface $client,
        private TenantEntityManagerProvider $emProvider,
        private SluggerInterface $slugger,
        private LoggerInterface $logger
    ) {
    }

    public function importDataForTenant(string $tenantCode, string $gemsuiteToken): void
    {
        $this->logger->info(sprintf('Début de l\'importation pour le tenant "%s"', $tenantCode));

        try {
            $dbname = 'db_' . $tenantCode;
            $this->emProvider->switchTenant($dbname, $tenantCode);
            $tenantEm = $this->emProvider->getEntityManager();

            $categoryMap = $this->importCategories($tenantEm, $gemsuiteToken);
            $this->importProducts($tenantEm, $gemsuiteToken, $categoryMap);
            
            $this->logger->info(sprintf('Importation réussie pour le tenant "%s"', $tenantCode));

        } catch (\Throwable $e) {
            $this->logger->error('Erreur durant l\'importation GEM-SUITE: ' . $e->getMessage());
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
    
    private function importProducts(EntityManagerInterface $tenantEm, string $token, array $categoryMap): void
    {
        $response = $this->client->request('GET', self::GEMSUITE_API_URL . 'products', [
            'auth_bearer' => $token,
        ]);

        $data = $response->toArray();
        
        foreach ($data['data'] as $gemProductData) {
            $product = $tenantEm->getRepository(Product::class)->findOneBy(['gemsuiteProductId' => $gemProductData['id']]);
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
            $product->setIsWeb(isset($gemProductData['status']) && $gemProductData['status'] === 1);
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
            
             if (!empty($gemProductData['medias'])) {
                $baseUrl = 'https://actif-file.s3-ca-central-1.amazonaws.com';
                $imagePath = ltrim($gemProductData['medias'][0]['path'], '/');
                $product->setImage($baseUrl . '/' . $imagePath);
            } else {
                $product->setImage('');
            }

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
}