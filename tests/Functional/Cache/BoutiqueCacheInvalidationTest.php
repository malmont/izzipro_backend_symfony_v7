<?php

namespace App\Tests\Functional\Cache;

use App\Entity\AdminSettings;
use App\Entity\BoutiqueSetting;
use App\Entity\Carrier;
use App\Entity\Categories;
use App\Entity\Color;
use App\Entity\Entreprise;
use App\Entity\Product;
use App\Entity\ProductVariant;
use App\Entity\SaleUnit;
use App\Entity\Size;
use App\Entity\SocialNetwork;
use App\Entity\Style;
use App\Entity\Transporteur;
use App\Entity\User;
use App\EventSubscriber\CacheInvalidationSubscriber;
use App\Services\TenantEntityManagerProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Invalidation ciblée de la boutique et du commun (lot 3, 28/10/2026) : une écriture du catalogue, d'un référentiel, des
 * réglages, de la fiche entreprise ou des réglages d'administration vide ses lectures et laisse les autres en cache
 * (témoin : la liste des présentations). Les fiches produit sont vidées une à une.
 */
class BoutiqueCacheInvalidationTest extends WebTestCase
{
    use CacheTestEntityFactory;

    private const WITNESS = ['/api/presentations?locale=fr', 'presentations_all_fr'];
    private const PASSWORD = 'Cache-test-2026!';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    /** @return iterable<string, array{class-string, string, string, bool}> entité écrite, route, clé, connexion admin */
    public static function components(): iterable
    {
        $byCategory = 'products_by_category_' . md5(json_encode([
            'categories' => null, 'keyword' => null, 'page' => 1, 'pageSize' => 12, 'barcode' => null, 'isWeb' => null, 'isPos' => null, 'locale' => 'fr',
        ]));
        yield 'products by category' => [Product::class, '/api/products/by-category?locale=fr', $byCategory, false];
        yield 'category read through the product lists' => [Categories::class, '/api/products/by-category?locale=fr', $byCategory, false];
        yield 'sale unit read through the product lists' => [SaleUnit::class, '/api/products/by-category?locale=fr', $byCategory, false];
        yield 'products by offer' => [Product::class, '/api/products/bestsellers?locale=fr', 'products_by_offer_bestsellers_fr', false];
        // GET /api/products est servi par API Platform (la route du contrôleur, et son cache all_products_*, ne sont jamais atteints)
        yield 'categories' => [Categories::class, '/api/category?locale=fr', 'categories_all_fr', false];
        yield 'colors' => [Color::class, '/api/colors', 'colors_all_en', true];
        yield 'sizes' => [Size::class, '/api/sizes', 'sizes_all_en', true];
        yield 'styles' => [Style::class, '/api/styles', 'styles_all', true];
        yield 'carriers' => [Carrier::class, '/api/Carrier?locale=fr', 'carriers_fr', false];
        yield 'transporteurs' => [Transporteur::class, '/api/transporteurs', 'transporteurs_all', false];
    }

    /**
     * @dataProvider components
     * @param class-string $entityClass
     */
    public function testAWriteClearsItsComponentAndKeepsTheOthers(string $entityClass, string $route, string $key, bool $admin): void
    {
        if ($admin) {
            $this->login(['ROLE_ADMIN', 'ROLE_USER_INTERNET']);
        }
        [$witnessRoute, $witnessKey] = self::WITNESS;
        $this->get($route);
        $this->assertTrue($this->cached($key), "$route est servi par le cache");
        $this->get($witnessRoute);
        $this->assertTrue($this->cached($witnessKey));

        $entity = $this->create($entityClass);
        $this->assertFalse($this->cached($key), "création d'un $entityClass : $route est vidé");
        $this->assertTrue($this->cached($witnessKey), "création d'un $entityClass : le témoin reste en cache");

        $this->get($route);
        $this->assertTrue($this->cached($key));
        $em = $this->em();
        $em->remove($em->find($entityClass, $entity->getId()));
        $em->flush();
        $this->assertFalse($this->cached($key), "suppression d'un $entityClass : $route est vidé");
        $this->assertTrue($this->cached($witnessKey));
        $this->endOfWrite();
    }

    public function testAProductSheetIsClearedAloneByItsVariantsAndByTheReferentials(): void
    {
        $suffix = bin2hex(random_bytes(3));
        $a = $this->create(Product::class, ['slug' => 'cache-a-' . $suffix]);
        $b = $this->create(Product::class, ['slug' => 'cache-b-' . $suffix]);
        $this->endOfWrite();
        $this->get('/api/products/' . $a->getId() . '?locale=fr');
        $this->get('/api/products/by-slug/cache-b-' . $suffix . '?locale=fr');
        $this->get('/api/products/' . $a->getId() . '/variants');
        $keyA = 'product_detail_' . $a->getId() . '_fr';
        $keyB = 'product_detail_' . $b->getId() . '_fr';
        $variantsA = 'product_variants_' . $a->getId();
        $this->assertTrue($this->cached($keyA) && $this->cached($keyB) && $this->cached($variantsA), 'fiches et variantes en cache');

        $this->create(ProductVariant::class, ['product' => $this->em()->find(Product::class, $a->getId())]);
        $this->assertFalse($this->cached($keyA), 'variante ajoutée : la fiche de son produit est vidée');
        $this->assertFalse($this->cached($variantsA), 'variante ajoutée : la liste des variantes du produit est vidée');
        $this->assertTrue($this->cached($keyB), 'variante ajoutée : l\'autre fiche reste en cache');

        $this->get('/api/products/' . $a->getId() . '?locale=fr');
        $this->create(SaleUnit::class);
        $this->assertFalse($this->cached($keyA) || $this->cached($keyB), 'référentiel écrit : toutes les fiches sont vidées');
        $this->endOfWrite();
    }

    public function testTheBoutiqueSettingsAreCachedUntilWritten(): void
    {
        $suffix = bin2hex(random_bytes(3));
        $this->get('/api/boutique-settings');
        $this->assertTrue($this->cached('boutique_settings'));

        $em = $this->em();
        $setting = $em->getRepository(BoutiqueSetting::class)->findOneBy([]) ?? new BoutiqueSetting();
        $setting->setConfiguration(['cacheTest' => $suffix] + $setting->getConfiguration());
        $em->persist($setting);
        $em->flush();
        $this->assertFalse($this->cached('boutique_settings'), 'réglages écrits : vidés');
        $this->assertStringContainsString($suffix, $this->get('/api/boutique-settings'));
        $this->endOfWrite();
    }

    public function testTheCompanySheetFollowsItsChildrenAndTheAdminSettingsTheirWrite(): void
    {
        $em = $this->em();
        $entreprise = $em->getRepository(Entreprise::class)->findOneBy([]);
        $this->assertNotNull($entreprise, 'base de test : une fiche entreprise');
        $id = $entreprise->getId();
        $this->get("/api/entreprise/$id?locale=fr");
        $this->get('/api/admin-settings');
        [$witnessRoute, $witnessKey] = self::WITNESS;
        $this->get($witnessRoute);
        $this->assertTrue($this->cached("entreprise_{$id}_fr") && $this->cached('admin_settings') && $this->cached($witnessKey));

        $social = $this->create(SocialNetwork::class, ['entreprise' => $em->find(Entreprise::class, $id)]);
        $this->assertFalse($this->cached("entreprise_{$id}_fr"), 'réseau social ajouté : la fiche entreprise est vidée');
        $this->assertTrue($this->cached('admin_settings') && $this->cached($witnessKey), 'les autres composants restent en cache');
        $em = $this->em();
        $em->remove($em->find(SocialNetwork::class, $social->getId()));
        $em->flush();

        $settings = $em->find(AdminSettings::class, 1);
        $this->assertNotNull($settings, 'base de test : réglages d\'administration (id 1)');
        $settings->setThemeChoice('cache-' . bin2hex(random_bytes(2)));
        $em->flush();
        $this->assertFalse($this->cached('admin_settings'), 'réglages d\'administration écrits : vidés');
        $this->assertTrue($this->cached($witnessKey));
        $this->endOfWrite();
    }

    private function get(string $route): string
    {
        $this->client->request('GET', $route, [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), $route . ' : ' . substr((string) $this->client->getResponse()->getContent(), 0, 300));

        return (string) $this->client->getResponse()->getContent();
    }

    private function cached(string $key): bool
    {
        return static::getContainer()->get('cache.app')->hasItem(MV_TEST_TENANT_CODE . '.' . $key);
    }

    protected function em(): EntityManagerInterface
    {
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);

        return $provider->getEntityManager();
    }

    private function endOfWrite(): void
    {
        static::getContainer()->get(CacheInvalidationSubscriber::class)->invalidateWrittenTenants();
    }

    /** Connexion web (cookies de session gardés par le client) d'un compte créé pour le test */
    private function login(array $roles): void
    {
        $email = 'cache-test-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $container = static::getContainer();
        $em = $this->em();
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Cache')->setLastname('Test')->setRoles($roles)->setIsVerified(true);
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD));
        $em->persist($user);
        $em->flush();
        $this->endOfWrite();

        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . '/api/login', [], [], [
            'HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
        ], json_encode(['username' => $email, 'password' => self::PASSWORD, 'platform' => 'web']));
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
    }
}
