<?php


namespace App\Services\GemsuiteImporterService;

use App\Entity\Categories;
use App\Entity\Product;
use App\Entity\ProductShipping;
use App\Entity\ProductVariant;
use App\Entity\Style;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use App\Entity\User;
use Symfony\Component\String\Slugger\SluggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;

class GemsuiteSyncHandler
{
    private const GEMSUITE_API_URL = 'https://app.gem-books.com/api/';

    public function __construct(
        private HttpClientInterface $client,
        private TenantEntityManagerProvider $emProvider,
        private TenantConnectionManager $tenantManager,
        private SluggerInterface $slugger,
        private LoggerInterface $logger
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

        $response = $this->client->request('GET', self::GEMSUITE_API_URL . 'products/' . $productId, [
            'auth_bearer' => $token,
        ]);

        $gemProductData = $response->toArray()['data'] ?? null;
        if (!$gemProductData) {
            $this->logger->warning(sprintf('Produit #%d non trouvé sur GEM-SUITE pour le tenant "%s".', $productId, $tenantCode));
            return;
        }
        
        $tenantEm = $this->getTenantEntityManager($tenantCode);
        
        $categoryMap = $this->importCategories($tenantEm, $token);

        $this->updateOrCreateProduct($tenantEm, $gemProductData, $categoryMap);

        $tenantEm->flush();
        $this->logger->info(sprintf('Produit #%d synchronisé avec succès pour le tenant "%s".', $productId, $tenantCode));
    }

    /**
     * Gère la mise à jour d'une catégorie.
     */
    public function handleCategoryUpdate(string $tenantCode, int $categoryId): void
    {
        $this->logger->info(sprintf('Synchronisation de la catégorie #%d pour le tenant "%s"', $categoryId, $tenantCode));
        $token = $this->tenantManager->getTenantToken($tenantCode);
        if (!$token) {
            $this->logger->error(sprintf('Aucun token trouvé pour le tenant "%s".', $tenantCode));
            return;
        }
        
        $tenantEm = $this->getTenantEntityManager($tenantCode);
        $this->importCategories($tenantEm, $token); 
    }
    

    private function updateOrCreateProduct(EntityManagerInterface $em, array $gemProductData, array $categoryMap): void
    {
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
        $product->setIsWeb(isset($gemProductData['status']) && $gemProductData['status'] === 1);
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
    }

    private function importCategories(EntityManagerInterface $em, string $token): array
    {
        $response = $this->client->request('GET', self::GEMSUITE_API_URL . 'categories', [
            'auth_bearer' => $token,
        ]);
        
        $data = $response->toArray();
        $categoryMap = [];

        foreach ($data['data'] as $gemCategoryData) {
            $category = $em->getRepository(Categories::class)->findOneBy(['gemsuiteCategoryId' => $gemCategoryData['id']]);
            if (!$category) {
                $category = new Categories();
                $category->setGemsuiteCategoryId($gemCategoryData['id']);
            }
            
            $category->setName(trim($gemCategoryData['name_fr']));
            $em->persist($category);
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

    //  public function handleClientUpdate(string $tenantCode, int $clientId): void
    // {
    //     $this->logger->info(sprintf('Synchronisation du client #%d pour le tenant "%s"', $clientId, $tenantCode));
    //     $token = $this->tenantManager->getTenantToken($tenantCode);
    //     if (!$token) {
    //         $this->logger->error(sprintf('Aucun token trouvé pour le tenant "%s".', $tenantCode));
    //         return;
    //     }

    //     try {
    //         // 1. Récupérer les données à jour du client depuis GEM-SUITE
    //         $response = $this->client->request('GET', self::GEMSUITE_API_URL . 'clients/' . $clientId, [
    //             'auth_bearer' => $token,
    //         ]);

    //         $gemClientData = $response->toArray()['data'] ?? null;
    //         if (!$gemClientData) {
    //             $this->logger->warning(sprintf('Client #%d non trouvé sur GEM-SUITE.', $clientId));
    //             return;
    //         }

    //         $tenantEm = $this->getTenantEntityManager($tenantCode);

    //         // 2. Trouver l'utilisateur Iizipro correspondant
    //         $user = $tenantEm->getRepository(User::class)->findOneBy(['gemsuiteClientId' => $clientId]);

    //         if (!$user) {
    //             $this->logger->info(sprintf('Aucun utilisateur Iizipro n\'est lié au client GEM-SUITE #%d.', $clientId));
    //             return;
    //         }

    //         // 3. Mettre à jour (ou créer) son adresse principale
    //         if (!empty($gemClientData['address'])) {
    //             $address = $user->getAdresses()->first() ?: new Adress();

    //             $address->setFirstname($user->getFirstname());
    //             $address->setLastname($user->getLastname());
    //             $address->setAddress($gemClientData['address']);
    //             $address->setCity($gemClientData['city'] ?? '');
    //             $address->setCodepostal($gemClientData['zipcode'] ?? '');
    //             $address->setCountry($gemClientData['pays'] ?? 'Canada');
    //             $address->setProvince($gemClientData['state'] ?? '');
    //             $address->setPhone($gemClientData['phone'] ?? 'N/A');

    //             // On s'assure que l'adresse est bien liée à l'utilisateur
    //             if (!$user->getAdresses()->contains($address)) {
    //                 $user->addAdress($address);
    //                 $tenantEm->persist($address);
    //             }

    //             $tenantEm->flush();
    //             $this->logger->info(sprintf('Adresse de l\'utilisateur "%s" synchronisée avec succès.', $user->getEmail()));
    //         }

    //     } catch (\Throwable $e) {
    //         $this->logger->error(sprintf('Erreur lors de la synchronisation du client #%d : %s', $clientId, $e->getMessage()));
    //     }
    // }
}
