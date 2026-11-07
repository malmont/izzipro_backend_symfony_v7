<?php

namespace App\Tests\Functional\Boutique;

use App\Entity\User;
use App\Services\TenantEntityManagerProvider;
use App\Tests\Fake\FakeLandingAiClient;
use App\Tests\Functional\Support\OtpTestHelper;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Fiche produit par IA (07/11/2026) : à partir d'une photo et d'un commentaire, l'IA rédige un brouillon (un crédit, photos déposées dans la
 * médiathèque), la création enregistre produit, variante, photos, canaux et traduction automatique (un crédit) ; refus sans crédit ;
 * page du back-office en deux étapes ; anonyme repoussé.
 */
class ProductAiSheetTest extends WebTestCase
{
    private KernelBrowser $client;
    private \Doctrine\DBAL\Connection $db;
    private array $created = [];
    private ?int $categoryId = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        FakeLandingAiClient::reset();
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $this->db = $provider->getEntityManager()->getConnection();
        $this->db->executeStatement('DELETE FROM ai_usage');
    }

    protected function tearDown(): void
    {
        foreach ($this->created as $id) {
            $this->db->executeStatement('DELETE FROM product_variant WHERE product_id = ?', [$id]);
            $this->db->executeStatement('DELETE FROM product_picture WHERE product_id = ?', [$id]);
            $this->db->executeStatement('DELETE FROM product_translation WHERE product_id = ?', [$id]);
            $this->db->executeStatement('DELETE FROM product_categories WHERE product_id = ?', [$id]);
            $this->db->executeStatement('DELETE FROM product WHERE id = ?', [$id]);
        }
        if ($this->categoryId !== null) {
            $this->db->executeStatement('DELETE FROM categories WHERE id = ?', [$this->categoryId]);
        }
        static::getContainer()->get(\App\Services\TenantCacheService::class)->invalidateTenant();
        parent::tearDown();
    }

    public function testDraftFromPhotoThenCreateWithTranslation(): void
    {
        $this->assertSame(401, $this->api('POST', '/api/products/ai/sheet', null, [], null)->getStatusCode(), 'anonyme');
        $this->assertSame(403, $this->api('POST', '/api/products/ai/sheet', null, [], 'client')->getStatusCode(), 'client');
        $this->assertSame(422, $this->api('POST', '/api/products/ai/sheet', ['comment' => 'x'], [], 'admin')->getStatusCode(), 'photo obligatoire');

        // une catégorie du site (le modèle de test n'en a pas) : l'IA choisit dans la liste
        $em = static::getContainer()->get(TenantEntityManagerProvider::class)->getEntityManager();
        $category = (new \App\Entity\Categories())->setName('Casquettes IA test');
        $em->persist($category);
        $em->flush();
        $this->categoryId = $categoryId = (int) $category->getId();
        $categoryName = 'Casquettes IA test';
        FakeLandingAiClient::$queue[] = (object) ['content' => [(object) ['type' => 'tool_use', 'id' => 'tu_1', 'name' => 'propose_product_sheet', 'input' => (object) [
            'name' => 'Casquette en lin naturel', 'shortDescription' => 'Casquette légère en lin.', 'description' => '<p>Une casquette en lin, légère et respirante.</p>', 'moreInformations' => '<p>Taille unique.</p>',
            'categoryName' => $categoryName, 'attributes' => (object) ['material' => 'lin', 'colors' => ['beige'], 'sizes' => ['unique'], 'gender' => 'mixte', 'season' => 'été'], 'tags' => 'casquette, lin, été',
            'suggestedPriceCents' => 3499, 'shippingClass' => 'standard', 'confidence' => 'high', 'note' => '',
        ]]], 'usage' => (object) ['input_tokens' => 900, 'output_tokens' => 300]];
        $r = $this->api('POST', '/api/products/ai/sheet', ['comment' => 'achetée 12 € au Portugal, lin', 'locale' => 'fr'], ['photos' => [$this->photo('a.png'), $this->photo('b.png')]], 'admin');
        $this->assertSame(200, $r->getStatusCode(), $r->getContent());
        $body = json_decode((string) $r->getContent(), true);
        $this->assertSame(['Casquette en lin naturel', $categoryId, 3499, 'standard', 'fr'], [$body['draft']['name'], $body['draft']['categoryId'], $body['draft']['suggestedPriceCents'], $body['draft']['shippingClass'], $body['draft']['language']]);
        $this->assertCount(2, $body['pictures'], 'photos déposées dans la médiathèque');
        $this->assertStringStartsWith('http', (string) $body['pictures'][0]['url']);
        $this->assertSame(1, $body['credits']['used'], 'un crédit pour la fiche');
        $request = FakeLandingAiClient::$requests[0];
        $this->assertSame('propose_product_sheet', $request['tool_choice']['name']);
        $images = array_filter($request['messages'][0]['content'], fn ($c) => $c['type'] === 'image');
        $this->assertCount(2, $images, 'les deux photos partent à l\'IA en base64');
        $this->assertSame('base64', array_values($images)[0]['source']['type']);
        $this->assertStringContainsString('Portugal', $request['messages'][0]['content'][0]['text']);
        $this->assertStringContainsString($categoryName, $request['messages'][0]['content'][0]['text'], 'catégories du site proposées');

        // Création avec traduction automatique en anglais (un second crédit)
        FakeLandingAiClient::$queue[] = (object) ['content' => [(object) ['type' => 'tool_use', 'id' => 'tu_2', 'name' => 'translate_product', 'input' => (object) ['en' => (object) ['name' => 'Natural linen cap', 'description' => '<p>A light, breathable linen cap.</p>', 'moreInformations' => '<p>One size.</p>', 'tags' => 'cap, linen, summer']]]], 'usage' => (object) ['input_tokens' => 200, 'output_tokens' => 100]];
        $r = $this->api('POST', '/api/products/ai/create', ['name' => $body['draft']['name'], 'description' => $body['draft']['description'], 'moreInformations' => $body['draft']['moreInformations'], 'tags' => $body['draft']['tags'], 'language' => 'fr',
            'priceCents' => 3499, 'stockQuantity' => 3, 'categoryIds' => [$categoryId], 'pictures' => array_column($body['pictures'], 'url'), 'channels' => ['web' => true, 'pos' => true, 'landing' => false], 'modes' => ['sale' => true], 'translate' => true], [], 'admin', true);
        $this->assertSame(201, $r->getStatusCode(), $r->getContent());
        $created = json_decode((string) $r->getContent(), true);
        $this->created[] = $created['productId'];
        $this->assertSame([['fr', 'en'], ['en'], 2], [$created['languages'], $created['translated'], $created['pictures']]);
        $this->assertStringStartsWith('IA-', $created['code']);
        $row = $this->db->fetchAssociative('SELECT name, price, is_web, is_pos, is_landing_page, sale_enabled, rental_enabled, slug, product_type FROM product WHERE id = ?', [$created['productId']]);
        $this->assertSame(['Casquette en lin naturel', 3499, true, true, false, true, false, 'standard'], [$row['name'], (int) $row['price'], (bool) $row['is_web'], (bool) $row['is_pos'], (bool) $row['is_landing_page'], (bool) $row['sale_enabled'], (bool) $row['rental_enabled'], $row['product_type']], 'le stock réel est celui de la variante');
        $this->assertSame($created['slug'], $row['slug']);
        $this->assertSame([3499, 3], array_map('intval', array_values($this->db->fetchAssociative('SELECT price, stock_quantity FROM product_variant WHERE product_id = ?', [$created['productId']]))));
        $this->assertSame('Natural linen cap', $this->db->fetchOne("SELECT name FROM product_translation WHERE product_id = ? AND locale = 'en'", [$created['productId']]));
        $this->assertSame(2, (int) $this->db->fetchOne('SELECT COUNT(*) FROM product_picture WHERE product_id = ?', [$created['productId']]));
        $this->assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM product_categories WHERE product_id = ? AND categories_id = ?', [$created['productId'], $categoryId]));
        $this->assertSame(2, (int) $this->db->fetchOne("SELECT COALESCE(SUM(credits), 0) FROM ai_usage WHERE status = 'success'"), 'deux crédits consommés');
        $this->assertSame('create', $this->db->fetchOne("SELECT action FROM content_audit_log WHERE resource = 'product' AND resource_id = ? ORDER BY id DESC LIMIT 1", [(string) $created['productId']]));

        // Même nom : le second produit prend un autre slug ; prix invalide refusé
        $r = $this->api('POST', '/api/products/ai/create', ['name' => $body['draft']['name'], 'priceCents' => 0], [], 'admin', true);
        $this->assertSame(422, $r->getStatusCode());
        $this->assertSame('PRICE_INVALID', json_decode((string) $r->getContent(), true)['code']);
        $r = $this->api('POST', '/api/products/ai/create', ['name' => $body['draft']['name'], 'priceCents' => 1000], [], 'admin', true);
        $this->assertSame(201, $r->getStatusCode(), $r->getContent());
        $second = json_decode((string) $r->getContent(), true);
        $this->created[] = $second['productId'];
        $this->assertSame($created['slug'] . '-2', $second['slug']);
    }

    public function testNoCreditNoDraftAndTheBackOfficePageWorksInTwoSteps(): void
    {
        $before = $this->db->fetchOne('SELECT monthly_credits FROM ai_credit_setting ORDER BY id LIMIT 1');
        $hadRow = $before !== false && $before !== null;
        try {
            if ($hadRow) {
                $this->db->executeStatement('UPDATE ai_credit_setting SET monthly_credits = 0');
            } else {
                $this->db->executeStatement('INSERT INTO ai_credit_setting (monthly_credits) VALUES (0)');
            }
            static::getContainer()->get(\App\Services\TenantCacheService::class)->invalidateTenant();
            $r = $this->api('POST', '/api/products/ai/sheet', ['comment' => ''], ['photos' => [$this->photo('c.png')]], 'admin');
            $this->assertSame(402, $r->getStatusCode(), $r->getContent());
            $this->assertSame('AI_CREDITS_EXHAUSTED', json_decode((string) $r->getContent(), true)['code']);
            $this->assertSame([], FakeLandingAiClient::$requests, 'aucun appel à l\'IA sans crédit');
        } finally {
            if ($hadRow) {
                $this->db->executeStatement('UPDATE ai_credit_setting SET monthly_credits = ?', [$before]);
            } else {
                $this->db->executeStatement('DELETE FROM ai_credit_setting');
            }
            static::getContainer()->get(\App\Services\TenantCacheService::class)->invalidateTenant();
        }

        // Back-office : étape 1 (photos) puis étape 2 (fiche à corriger) puis création
        $this->loginAdmin();
        $crawler = $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin?routeName=admin_product_photo', [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertSame(1, $crawler->filter('form[data-photo-form]')->count());
        $token = $crawler->filter('form[data-photo-form] input[name="_token"]')->attr('value');
        FakeLandingAiClient::$queue[] = (object) ['content' => [(object) ['type' => 'tool_use', 'id' => 'tu_1', 'name' => 'propose_product_sheet', 'input' => (object) ['name' => 'Sac en toile', 'shortDescription' => 'Sac.', 'description' => '<p>Sac en toile épaisse.</p>', 'confidence' => 'medium', 'note' => 'matière supposée', 'suggestedPriceCents' => 2500, 'tags' => 'sac']]], 'usage' => (object) ['input_tokens' => 10, 'output_tokens' => 10]];
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . '/admin/produits/depuis-photo', ['_token' => $token, 'step' => 'draft', 'comment' => 'toile', 'channel_web' => '1'], ['photos' => [$this->photo('d.png')]], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $this->assertSame(302, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
        $crawler = $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin?routeName=admin_product_photo', [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $this->assertSame(1, $crawler->filter('form[data-draft-form]')->count(), 'étape 2');
        $this->assertSame('Sac en toile', $crawler->filter('form[data-draft-form] input[name="name"]')->attr('value'));
        $this->assertSame('25.00', $crawler->filter('form[data-draft-form] input[name="price"]')->attr('value'));
        $this->assertStringContainsString('matière supposée', html_entity_decode((string) $this->client->getResponse()->getContent()));
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . '/admin/produits/depuis-photo', ['_token' => $token, 'step' => 'create', 'name' => 'Sac en toile épaisse', 'description' => '<p>Sac en toile épaisse.</p>', 'price' => '29,90', 'stock' => '2', 'channel_web' => '1', 'mode_sale' => '1'], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $this->assertSame(302, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
        $this->assertStringContainsString('crudAction=edit', (string) $this->client->getResponse()->headers->get('Location'), 'ouvre la fiche produit');
        $id = (int) $this->db->fetchOne("SELECT id FROM product WHERE name = 'Sac en toile épaisse' ORDER BY id DESC LIMIT 1");
        $this->assertGreaterThan(0, $id);
        $this->created[] = $id;
        $this->assertSame(2990, (int) $this->db->fetchOne('SELECT price FROM product_variant WHERE product_id = ?', [$id]));
        $this->assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM product_picture WHERE product_id = ?', [$id]), 'la photo déposée devient la photo du produit');
    }

    private function photo(string $name): UploadedFile
    {
        $tmp = tempnam(sys_get_temp_dir(), 'photo');
        copy(__DIR__ . '/../../../public/assets/arkanoa-favicon.png', $tmp);

        return new UploadedFile($tmp, $name, 'image/png', null, true);
    }

    private function api(string $method, string $path, ?array $body, array $files, ?string $as, bool $json = false): \Symfony\Component\HttpFoundation\Response
    {
        $server = ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_XSRF_TOKEN' => 'jeton-xsrf-de-test'];
        if ($as !== null) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer ' . $this->token($as);
        }
        $this->client->getCookieJar()->clear();
        if ($json) {
            $server['CONTENT_TYPE'] = 'application/json';
            $this->client->request($method, 'https://' . MV_TEST_TENANT_HOST . $path, [], [], $server, $body !== null ? json_encode($body) : null);
        } else {
            $this->client->request($method, 'https://' . MV_TEST_TENANT_HOST . $path, $body ?? [], $files, $server);
        }

        return $this->client->getResponse();
    }

    private function token(string $as): string
    {
        $container = static::getContainer();
        $em = $container->get(TenantEntityManagerProvider::class)->getEntityManager();
        $email = $as === 'admin' ? 'admin@arkanoa-media.com' : 'client-ai-sheet@example.invalid';
        $user = $em->getRepository(User::class)->findOneBy(['email' => $email]);
        if (!$user) {
            $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Client')->setLastname('Test')->setRoles(['ROLE_USER_INTERNET'])->setPassword('inutilisable')->setIsVerified(true);
            $em->persist($user);
            $em->flush();
        }

        return $container->get('lexik_jwt_authentication.jwt_manager')->create($user);
    }

    private function loginAdmin(): void
    {
        $container = static::getContainer();
        $provider = $container->get(TenantEntityManagerProvider::class);
        $email = 'photo-admin-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Photo')->setLastname('Admin')->setRoles(['ROLE_ADMIN', 'ROLE_USER_INTERNET'])->setIsVerified(true);
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, 'Mot-de-passe-de-test-1!'));
        $provider->getEntityManager()->persist($user);
        $provider->getEntityManager()->flush();
        $this->client->getCookieJar()->clear();
        $this->client->loginUser($user, 'main');
        OtpTestHelper::validateIfRequired($this->client, $user);
    }
}
