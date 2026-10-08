<?php

namespace App\Tests\Functional\Boutique;

use App\Entity\User;
use App\Services\BoutiqueDemoService\BoutiqueDemoSeeder;
use App\Services\TenantEntityManagerProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * § 9 : modification des données de la boutique depuis la page (PATCH /api/{products|category|homeslider|explore-cards}/{id}
 * ?locale=), journalisée et restaurable comme les contenus des landing pages.
 */
class BoutiqueContentEditApiTest extends WebTestCase
{
    private const PASSWORD = 'Mot-de-passe-de-test-1!';
    private const IMAGE_KEY = 'ab01ab01ab01ab01ab01ab01ab01ab01ab01ab01ab01ab01ab01ab01ab01ab01';
    private const IMAGE_KEY_2 = 'ab02ab02ab02ab02ab02ab02ab02ab02ab02ab02ab02ab02ab02ab02ab02ab02';

    private KernelBrowser $client;
    private array $session = [];
    private \Doctrine\DBAL\Connection $db;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $this->db = $provider->getEntityManager()->getConnection();
        static::getContainer()->get(BoutiqueDemoSeeder::class)->seed('client-edition@example.invalid', false, false);
        $this->db->executeStatement("DELETE FROM content_audit_log WHERE resource IN ('products', 'category', 'homeslider', 'explore-cards')");
        $this->db->executeStatement('DELETE FROM shared_media WHERE access_key IN (?, ?)', [self::IMAGE_KEY, self::IMAGE_KEY_2]);
        $this->db->executeStatement("INSERT INTO shared_media (titre, filename, media_type, mime_type, visibility, access_key, created_at) VALUES
            ('Photo produit', 'p1.jpg', 'image', 'image/jpeg', 'private', '" . self::IMAGE_KEY . "', NOW()),
            ('Photo produit 2', 'p2.jpg', 'image', 'image/jpeg', 'private', '" . self::IMAGE_KEY_2 . "', NOW())");
        $this->loginAs(['ROLE_ADMIN', 'ROLE_USER_INTERNET']);
    }

    public function testProductFieldsPricesAndGalleryArePatchedPerLanguageAndReadBack(): void
    {
        $id = $this->product('DEMO-CASQUETTE');
        $to = (new \DateTimeImmutable('+10 days'))->setTime(23, 59);

        $fr = $this->patch("/api/products/$id?locale=fr", ['name' => 'Casquette <strong>brodée</strong> 2026', 'description' => '<p>Nouvelle description</p>',
            'price' => 3100, 'specialPrice' => 2500, 'specialPriceFrom' => '2026-01-01T00:00:00-05:00', 'specialPriceTo' => $to->format(DATE_ATOM),
            'image' => self::IMAGE_KEY, 'pictures' => [self::IMAGE_KEY, self::IMAGE_KEY_2]]);
        $this->assertSame(200, $fr->getStatusCode(), $fr->getContent());
        $body = json_decode($fr->getContent(), true);
        $this->assertSame('Casquette <strong>brodée</strong> 2026', $body['name']);
        $this->assertSame(['regular' => 3100, 'amount' => 2500], array_intersect_key($body['pricing'], ['regular' => 1, 'amount' => 1]));
        $this->assertTrue($body['pricing']['special']['active']);
        $this->assertStringEndsWith('/media/secure/' . self::IMAGE_KEY, $body['image'], 'image de la médiathèque servie par sa clé');
        $this->assertSame(2, count($body['pictures']));
        $this->assertStringEndsWith('/media/secure/' . self::IMAGE_KEY_2, $body['pictures'][1]['url']);

        $en = $this->patch("/api/products/$id?locale=en", ['name' => 'Embroidered cap 2026']);
        $this->assertSame(200, $en->getStatusCode(), $en->getContent());
        $this->assertSame('Embroidered cap 2026', json_decode($en->getContent(), true)['name']);
        $this->assertSame('Casquette <strong>brodée</strong> 2026', $this->get("/api/productsid/$id?locale=fr")['name'], 'le français est intact');
        $this->assertSame('Embroidered cap 2026', $this->get("/api/productsid/$id?locale=en")['name']);
        $this->assertSame(3100.0, (float) $this->db->fetchOne('SELECT price FROM product WHERE id = ?', [$id]), 'prix écrit en cents');

        $cleared = $this->patch("/api/products/$id", ['specialPrice' => null, 'pictures' => []]);
        $this->assertSame(200, $cleared->getStatusCode(), $cleared->getContent());
        $this->assertSame([null, []], [json_decode($cleared->getContent(), true)['pricing']['special'], json_decode($cleared->getContent(), true)['pictures']]);
    }

    public function testRefusedProductFieldsAreListedAndNothingIsWritten(): void
    {
        $id = $this->product('DEMO-SWEAT');
        $before = $this->db->fetchAssociative('SELECT name, price FROM product WHERE id = ?', [$id]);

        $response = $this->patch("/api/products/$id", ['price' => -5, 'specialPrice' => 'cher', 'specialPriceFrom' => 'hier', 'name' => '<script>x</script>',
            'pictures' => ['pas-une-cle'], 'slug' => 'pirate', 'image' => 'ftp://x']);

        $this->assertSame(422, $response->getStatusCode(), $response->getContent());
        $paths = array_column(json_decode($response->getContent(), true)['errors'], 'path');
        foreach (['price', 'specialPrice', 'specialPriceFrom', 'name', 'pictures', 'slug', 'image'] as $path) {
            $this->assertContains($path, $paths, implode(' | ', $paths));
        }
        $this->assertSame($before, $this->db->fetchAssociative('SELECT name, price FROM product WHERE id = ?', [$id]));
        $this->assertSame(422, $this->patch("/api/products/$id", ['name' => ''])->getStatusCode(), 'nom obligatoire');
        $this->assertSame(404, $this->patch('/api/products/999999', ['name' => 'x'])->getStatusCode());
    }

    public function testCategorySlideAndExploreCardArePatchedWithTheirOwnFields(): void
    {
        $category = (int) $this->db->fetchOne("SELECT id FROM categories WHERE name = 'Vêtements'");
        $response = $this->patch("/api/category/$category?locale=en", ['name' => 'Apparel', 'description' => '<p>Tees and hoodies</p>', 'image' => self::IMAGE_KEY]);
        $this->assertSame(200, $response->getStatusCode(), $response->getContent());
        $body = json_decode($response->getContent(), true);
        $this->assertSame('Apparel', $body['name']);
        $this->assertStringEndsWith('/media/secure/' . self::IMAGE_KEY, $body['image']);
        $this->assertSame('Vêtements', $this->db->fetchOne('SELECT name FROM categories WHERE id = ?', [$category]), 'l\'anglais ne touche pas la base française');

        $slide = (int) $this->db->fetchOne('SELECT MIN(id) FROM home_slider');
        $response = $this->patch("/api/homeslider/$slide", ['title' => 'Collection <em>Horizon</em>', 'buttonMessage' => 'Voir', 'buttonUrl' => '/catalogue?tri=nouveautes', 'image' => self::IMAGE_KEY_2]);
        $this->assertSame(200, $response->getStatusCode(), $response->getContent());
        $body = json_decode($response->getContent(), true);
        $this->assertSame(['Collection <em>Horizon</em>', 'Voir', '/catalogue?tri=nouveautes'], [$body['title'], $body['buttonMessage'], $body['buttonUrl']]);
        $this->assertStringEndsWith('/media/secure/' . self::IMAGE_KEY_2, $body['image']);
        $this->assertSame(422, $this->patch("/api/homeslider/$slide", ['buttonUrl' => 'javascript:alert(1)', 'imageMobile' => 'x'])->getStatusCode());

        $card = (int) $this->db->fetchOne('SELECT MIN(id) FROM explore_card');
        $response = $this->patch("/api/explore-cards/$card", ['standardTitle' => 'Vêtements', 'differentTitle' => 'Nouvelle collection', 'link' => 'https://exemple.com/collection', 'imageUrl' => self::IMAGE_KEY]);
        $this->assertSame(200, $response->getStatusCode(), $response->getContent());
        $body = json_decode($response->getContent(), true);
        $this->assertSame(['Nouvelle collection', 'https://exemple.com/collection'], [$body['differentTitle'], $body['link']]);
        $this->assertStringEndsWith('/media/secure/' . self::IMAGE_KEY, $body['imageUrl'], 'imageUrl de l\'API → imagePath de l\'entité');
        $this->assertSame('/media/secure/' . self::IMAGE_KEY, $this->db->fetchOne('SELECT image_path FROM explore_card WHERE id = ?', [$card]));

        // Les lectures publiques voient le changement (cache invalidé)
        $this->session = [];
        $this->assertContains('Nouvelle collection', array_column($this->get('/api/explore-cards'), 'differentTitle'));
        $this->assertContains('Collection <em>Horizon</em>', array_column($this->get('/api/homeslider'), 'title'));
    }

    public function testProductEditsAreJournaledAndRestorable(): void
    {
        $id = $this->product('DEMO-SAC');
        $this->assertSame(200, $this->patch("/api/products/$id", ['price' => 15900, 'name' => 'Sac Escale 2'])->getStatusCode());

        $journal = $this->get('/api/landingpage-audit?resource=products&resourceId=' . $id);
        $this->assertSame(1, $journal['total']);
        $entry = $journal['items'][0];
        $this->assertSame(['products', 'update', true], [$entry['resource'], $entry['action'], $entry['restorable']]);
        $this->assertEqualsCanonicalizing(['price', 'name'], $entry['fields']);
        $detail = $this->get('/api/landingpage-audit/' . $entry['id']);
        $this->assertSame([14900, 'Sac de voyage Escale'], [$detail['before']['price'], $detail['before']['name']]);
        $this->assertSame([15900, 'Sac Escale 2'], [$detail['after']['price'], $detail['after']['name']]);

        $restore = $this->request('POST', '/api/landingpage-audit/' . $entry['id'] . '/restore');
        $this->assertSame(200, $restore->getStatusCode(), $restore->getContent());
        $this->assertSame(14900, json_decode($restore->getContent(), true)['result']['pricing']['regular']);
        $this->assertSame(['Sac de voyage Escale', 14900.0], [$this->db->fetchOne('SELECT name FROM product WHERE id = ?', [$id]), (float) $this->db->fetchOne('SELECT price FROM product WHERE id = ?', [$id])]);
    }

    public function testOnlyAnAdminCanPatchShopData(): void
    {
        $id = $this->product('DEMO-CAFE');
        $this->session = [];
        $this->assertContains($this->patch("/api/products/$id", ['name' => 'x'])->getStatusCode(), [401, 403]);
        $this->loginAs(['ROLE_USER_INTERNET']);
        $this->assertSame(403, $this->patch("/api/products/$id", ['name' => 'x'])->getStatusCode());
        $this->assertSame(403, $this->patch('/api/category/1', ['name' => 'x'])->getStatusCode());
    }

    private function product(string $code): int
    {
        return (int) $this->db->fetchOne('SELECT id FROM product WHERE code = ?', [$code]);
    }

    private function patch(string $path, array $body): Response
    {
        return $this->request('PATCH', $path, json_encode($body, JSON_UNESCAPED_UNICODE));
    }

    private function get(string $path): array
    {
        $response = $this->request('GET', $path);
        $this->assertSame(200, $response->getStatusCode(), $response->getContent());

        return json_decode($response->getContent(), true);
    }

    private function request(string $method, string $path, ?string $body = null): Response
    {
        $jar = $this->client->getCookieJar();
        $jar->clear();
        foreach ($this->session as $name => $value) {
            $jar->set(new \Symfony\Component\BrowserKit\Cookie($name, $value, null, '/', MV_TEST_TENANT_HOST, true));
        }
        $this->client->request($method, 'https://' . MV_TEST_TENANT_HOST . $path, [], [], array_filter([
            'HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_XSRF_TOKEN' => $this->session['XSRF-TOKEN_' . MV_TEST_TENANT_CODE] ?? null,
        ]), $body);

        return $this->client->getResponse();
    }

    private function loginAs(array $roles): void
    {
        $email = 'edition-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $container = static::getContainer();
        $provider = $container->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Édition')->setLastname('Boutique')->setRoles($roles)->setIsVerified(true);
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
