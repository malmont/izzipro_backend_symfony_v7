<?php

namespace App\Tests\Functional\SiteContent;

use App\Entity\User;
use App\Services\SiteContentService\ReferenceContentBuilder;
use App\Services\TenantEntityManagerProvider;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Contenu d'exemple (12/10/2026) : copie depuis le site de référence (base de test db_site_reference_test, remplie
 * par ReferenceContentBuilder depuis config/site-content/reference.php), marquage isDemo, fiche rendue définitive par
 * une modification, liste et suppression des fiches d'exemple ; ROLE_ADMIN.
 *
 * Le site de test a déjà des présentations, des groupes, une bannière statique, une vidéo et des atouts : en mode
 * « empty », ces ressources ne sont pas copiées.
 */
class SiteContentApiTest extends WebTestCase
{
    private const PASSWORD = 'Mot-de-passe-de-test-1!';
    private static bool $referenceBuilt = false;

    private KernelBrowser $client;
    private array $session = [];
    private Connection $db;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $this->db = $provider->getEntityManager()->getConnection();
        if (!self::$referenceBuilt) {
            $params = $this->db->getParams();
            unset($params['url']);
            $reference = DriverManager::getConnection(['dbname' => SITE_CONTENT_REFERENCE_TEST_DB] + $params);
            static::getContainer()->get(ReferenceContentBuilder::class)->build($reference, require dirname(__DIR__, 3) . '/config/site-content/reference.php');
            $reference->close();
            self::$referenceBuilt = true;
        }
        $this->loginAs(['ROLE_ADMIN', 'ROLE_USER_INTERNET']);
    }

    public function testEmptyModeCopiesOnlyEmptyResourcesOnceAndMarksThemDemo(): void
    {
        // Ressources déjà remplies sur ce site, quel que soit l'ordre des tests (un groupe vide se copierait avec ses présentations)
        if ($this->rows('presentation') === 0) {
            $this->db->executeStatement("INSERT INTO presentation (id, titre) VALUES (nextval('presentation_id_seq'), 'Présentation du site')");
        }
        if ($this->rows('presentation_group') === 0) {
            $this->db->executeStatement("INSERT INTO presentation_group (id, titre) VALUES (nextval('presentation_group_id_seq'), 'Groupe du site')");
        }
        $presentations = $this->rows('presentation');
        $first = $this->json($this->request('POST', '/api/site-content/seed', ['scope' => ['landing'], 'mode' => 'empty']), 201);
        $this->assertSame(3, $first['created']['bannieres'] ?? null, 'ressource vide : copiée');
        $this->assertSame(4, $first['created']['service-offers'] ?? null);
        $this->assertSame(6, $first['created']['marques'] ?? null);
        $this->assertArrayNotHasKey('presentations', $first['created'], 'ressource déjà remplie : jamais d\'écrasement');
        $this->assertArrayNotHasKey('products', $first['created'], 'portée landing seulement');
        $this->assertSame($presentations, $this->rows('presentation'));
        $this->assertCount(3, $first['ids']['bannieres'], 'identifiant de référence => nouvel identifiant');

        $second = $this->json($this->request('POST', '/api/site-content/seed', ['scope' => ['landing'], 'mode' => 'empty']));
        $this->assertSame([], $second['created'], 'second appel : rien de créé');

        $banniere = array_values($first['ids']['bannieres'])[0];
        $read = $this->json($this->request('GET', "/api/bannieres/$banniere?locale=en"));
        $this->assertTrue($read['isDemo']);
        $this->assertSame('Welcome', $read['titre'], 'traduction anglaise copiée');
        $marque = array_values($first['ids']['marques'])[0];
        $this->assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM marque_categorie_marque l JOIN categorie_marque c ON c.id = l.categorie_marque_id WHERE l.marque_id = ? AND c.nom = ?', [$marque, 'Partenaires']));
    }

    public function testAddModeCopiesTheShopWithVariantsAndCustomization(): void
    {
        $result = $this->json($this->request('POST', '/api/site-content/seed', ['scope' => ['boutique'], 'mode' => 'add']), 201);
        $this->assertSame(8, $result['created']['products']);
        $this->assertSame(3, $result['created']['category']);
        $this->assertSame(2, $result['created']['customization-options']);
        $this->assertSame(3, $result['created']['homeslider']);
        $this->assertSame(3, $result['created']['explore-cards']);
        $this->assertSame(4, $result['created']['features'], 'mode add : à côté des atouts du site');

        $products = $result['ids']['products'];
        $gourde = (int) $this->db->fetchOne("SELECT id FROM product WHERE id IN (" . implode(',', $products) . ") AND customizable");
        $this->assertSame(6, (int) $this->db->fetchOne('SELECT COUNT(*) FROM product_customization_image c JOIN product_variant v ON v.id = c.product_variant_id WHERE v.product_id = ?', [$gourde]));
        $this->assertSame(12, (int) $this->db->fetchOne('SELECT COUNT(*) FROM product_customization_image_product_option_value l JOIN product_customization_image c ON c.id = l.product_customization_image_id JOIN product_variant v ON v.id = c.product_variant_id WHERE v.product_id = ?', [$gourde]), 'deux valeurs d\'option par combinaison');
        $tshirt = (int) $this->db->fetchOne("SELECT product_id FROM product_translation WHERE product_id IN (" . implode(',', $products) . ") AND locale = 'fr' AND name = 'T-shirt Horizon'");
        $this->assertSame(7, (int) $this->db->fetchOne('SELECT COUNT(*) FROM product_variant WHERE product_id = ? AND color_id IS NOT NULL AND size_id IS NOT NULL', [$tshirt]), 'couleurs et tailles reprises ou créées');
        $this->assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM product_categories l JOIN categories c ON c.id = l.categories_id WHERE l.product_id = ? AND c.id IN (' . implode(',', $result['ids']['category']) . ')', [$tshirt]), 'lié à la catégorie copiée');

        $slug = (string) $this->db->fetchOne('SELECT slug FROM product WHERE id = ?', [$tshirt]);
        $again = $this->json($this->request('POST', '/api/site-content/seed', ['scope' => ['boutique'], 'mode' => 'add']), 201);
        $copy = $again['ids']['products'][array_search($tshirt, $products, true)];
        $copySlug = (string) $this->db->fetchOne('SELECT slug FROM product WHERE id = ?', [$copy]);
        $this->assertStringStartsWith('t-shirt-horizon-', $copySlug);
        $this->assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM product WHERE slug = ?', [$copySlug]), 'slug rendu unique');

        $detail = $this->json($this->request('GET', '/api/products/by-slug/' . $slug . '?locale=fr'));
        $this->assertTrue($detail['isDemo'] ?? null, (string) json_encode(array_keys($detail)));
    }

    public function testAnEditMakesTheRecordDefinitive(): void
    {
        $seed = $this->json($this->request('POST', '/api/site-content/seed', ['scope' => ['landing', 'boutique'], 'mode' => 'add']), 201);
        $banniere = array_values($seed['ids']['bannieres'])[0];
        $this->json($this->request('PATCH', "/api/bannieres/$banniere?locale=fr", ['titre' => 'Notre bannière']));
        $this->assertFalse($this->json($this->request('GET', "/api/bannieres/$banniere"))['isDemo']);

        $product = array_values($seed['ids']['products'])[0];
        $this->assertTrue((bool) $this->db->fetchOne('SELECT is_demo FROM product WHERE id = ?', [$product]));
        $this->json($this->request('POST', "/api/products/$product/variants", ['stockQuantity' => 2, 'price' => 1000]), 201);
        $this->assertFalse((bool) $this->db->fetchOne('SELECT is_demo FROM product WHERE id = ?', [$product]), 'nouvelle variante : le produit devient définitif');

        $listed = $this->json($this->request('GET', '/api/site-content/demo?locale=fr'));
        $keys = array_map(fn ($i) => $i['resource'] . ':' . $i['id'], $listed);
        $this->assertNotContains("bannieres:$banniere", $keys);
        $this->assertNotContains("products:$product", $keys);
        $other = array_values($seed['ids']['products'])[1];
        $item = $listed[array_search("products:$other", $keys, true)];
        $this->assertSame(['name', 'description', 'price', 'image', 'pictures'], $item['fields']);
        $this->assertNotEmpty($item['label']);
    }

    public function testDeleteRefusesProductsInUseThenRemovesDemoContentAndKeepsWhatTheClientUses(): void
    {
        $seed = $this->json($this->request('POST', '/api/site-content/seed', ['scope' => ['landing', 'boutique'], 'mode' => 'add']), 201);
        $product = array_values($seed['ids']['products'])[0];
        $variant = (int) $this->db->fetchOne('SELECT id FROM product_variant WHERE product_id = ? ORDER BY id LIMIT 1', [$product]);
        $this->db->executeStatement("INSERT INTO checkout_session (payment_intent_id, status, cart, amount, currency, locale, host, created_at, updated_at) VALUES ('pi_demo_test', 'open', ?, 1000, 'cad', 'fr', 'mvtest.test', NOW(), NOW())", [json_encode(['items' => [['productVariantId' => $variant, 'quantity' => 1]]])]);
        // Produit du client rangé dans une catégorie d'exemple : la catégorie est gardée
        $category = array_values($seed['ids']['category'])[0];
        $clientProduct = array_values($seed['ids']['products'])[1];
        $this->db->executeStatement('UPDATE product SET is_demo = false WHERE id = ?', [$clientProduct]);
        $this->db->executeStatement('INSERT INTO product_categories (product_id, categories_id) VALUES (?, ?) ON CONFLICT DO NOTHING', [$clientProduct, $category]);

        $refused = $this->json($this->request('DELETE', '/api/site-content/demo'), 409);
        $this->assertSame([['resource' => 'products', 'id' => $product]], array_map(fn ($e) => ['resource' => $e['resource'], 'id' => $e['id']], array_values(array_filter($refused['errors'], fn ($e) => $e['id'] === $product))));
        $this->assertContains('paiement en cours', $refused['errors'][0]['usedBy']);
        $this->assertTrue((bool) $this->db->fetchOne('SELECT is_demo FROM product WHERE id = ?', [$product]), 'rien n\'a été supprimé');

        $this->db->executeStatement("DELETE FROM checkout_session WHERE payment_intent_id = 'pi_demo_test'");
        $result = $this->json($this->request('DELETE', '/api/site-content/demo'));
        $this->assertGreaterThanOrEqual(7, $result['deleted']['products']);
        $this->assertContains(['resource' => 'category', 'id' => $category], array_map(fn ($k) => ['resource' => $k['resource'], 'id' => $k['id']], $result['kept']));
        $this->assertSame([], $this->json($this->request('GET', '/api/site-content/demo')), 'plus aucune fiche d\'exemple');
        $this->assertFalse((bool) $this->db->fetchOne('SELECT is_demo FROM categories WHERE id = ?', [$category]), 'catégorie gardée, devenue définitive');
        $this->assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM product WHERE id = ?', [$product]));
        $this->assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM product WHERE id = ?', [$clientProduct]), 'fiche du client intacte');
        $this->assertSame(1, (int) $this->db->fetchOne("SELECT COUNT(*) FROM content_audit_log WHERE resource = 'site-content' AND action = 'demo-delete'"), 'suppression journalisée');
    }

    public function testValidationAndAccess(): void
    {
        $this->assertSame(422, $this->request('POST', '/api/site-content/seed', ['scope' => ['blog'], 'mode' => 'empty'])->getStatusCode());
        $this->assertSame(422, $this->request('POST', '/api/site-content/seed', ['scope' => ['landing'], 'mode' => 'replace'])->getStatusCode());
        $this->assertSame(422, $this->request('POST', '/api/site-content/seed', ['scope' => [], 'mode' => 'add'])->getStatusCode());

        $this->loginAs(['ROLE_USER_INTERNET']);
        $this->assertSame(403, $this->request('GET', '/api/site-content/demo')->getStatusCode());
        $this->assertSame(403, $this->request('POST', '/api/site-content/seed', ['scope' => ['landing'], 'mode' => 'add'])->getStatusCode());
        $this->session = [];
        $this->assertContains($this->request('DELETE', '/api/site-content/demo')->getStatusCode(), [401, 403]);
    }

    private function rows(string $table): int
    {
        return (int) $this->db->fetchOne("SELECT COUNT(*) FROM $table");
    }

    private function json(Response $response, int $status = 200): array
    {
        $this->assertSame($status, $response->getStatusCode(), (string) $response->getContent());

        return json_decode((string) $response->getContent(), true);
    }

    private function request(string $method, string $path, ?array $body = null): Response
    {
        $jar = $this->client->getCookieJar();
        $jar->clear();
        foreach ($this->session as $name => $value) {
            $jar->set(new \Symfony\Component\BrowserKit\Cookie($name, $value, null, '/', MV_TEST_TENANT_HOST, true));
        }
        $this->client->request($method, 'https://' . MV_TEST_TENANT_HOST . $path, [], [], array_filter([
            'HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_XSRF_TOKEN' => $this->session['XSRF-TOKEN_' . MV_TEST_TENANT_CODE] ?? null,
        ]), $body === null ? null : json_encode($body, JSON_UNESCAPED_UNICODE));

        return $this->client->getResponse();
    }

    private function loginAs(array $roles): void
    {
        $email = 'contenu-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $container = static::getContainer();
        $provider = $container->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Contenu')->setLastname('Exemple')->setRoles($roles)->setIsVerified(true);
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD));
        $provider->getEntityManager()->persist($user);
        $provider->getEntityManager()->flush();

        $this->client->getCookieJar()->clear();
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . '/api/login', [], [], [
            'HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
        ], json_encode(['username' => $email, 'password' => self::PASSWORD, 'platform' => 'web']));
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());
        $this->session = [];
        foreach ($this->client->getResponse()->headers->getCookies() as $cookie) {
            $this->session[$cookie->getName()] = $cookie->getValue();
        }
    }
}
