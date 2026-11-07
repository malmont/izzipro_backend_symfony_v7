<?php

namespace App\Tests\Functional\Boutique;

use App\Entity\User;
use App\Services\ProductAiService\ProductSheetCreator;
use App\Services\TenantEntityManagerProvider;
use App\Tests\Fake\FakeLandingAiClient;
use App\Tests\Functional\Support\OtpTestHelper;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * « IA » depuis la fiche d'un produit (07/11/2026) : rédiger la description depuis les photos, traduire, produire une image (refusée sans
 * fournisseur, sans crédit décompté) ; page refusée au client.
 */
class ProductAiSingleTest extends WebTestCase
{
    private KernelBrowser $client;
    private \Doctrine\DBAL\Connection $db;
    private array $created = [];

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
            foreach (['product_variant', 'product_picture', 'product_translation', 'product_categories'] as $t) {
                $this->db->executeStatement("DELETE FROM $t WHERE product_id = ?", [$id]);
            }
            $this->db->executeStatement('DELETE FROM product WHERE id = ?', [$id]);
        }
        static::getContainer()->get(\App\Services\TenantCacheService::class)->invalidateTenant();
        parent::tearDown();
    }

    public function testDescribeTranslateAndImageFromTheProductSheet(): void
    {
        $id = static::getContainer()->get(ProductSheetCreator::class)->create(['name' => 'Veste IA fiche', 'priceCents' => 8900, 'pictures' => ['https://exemple.invalid/veste.jpg'], 'language' => 'fr'], 'test')['body']['productId'];
        $this->created[] = $id;

        $this->loginAs(['ROLE_USER_INTERNET']);
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . "/admin/produits/$id/ia", [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $this->assertContains($this->client->getResponse()->getStatusCode(), [302, 403]);

        $this->loginAs(['ROLE_ADMIN', 'ROLE_USER_INTERNET']);
        $crawler = $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin?routeName=admin_product_ai&routeParams%5Bid%5D=' . $id, [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertSame(3, $crawler->filter('form[data-ai-action]')->count());
        $this->assertStringContainsString('Disponible dès que la plateforme branche son fournisseur', html_entity_decode((string) $this->client->getResponse()->getContent()));
        $token = $crawler->filter('form[data-ai-action="decrire"] input[name="_token"]')->attr('value');

        // Description depuis les photos
        FakeLandingAiClient::$queue[] = (object) ['content' => [(object) ['type' => 'tool_use', 'id' => 'tu_1', 'name' => 'propose_product_sheet', 'input' => (object) ['name' => 'Veste', 'shortDescription' => 'Veste.', 'description' => '<p>Veste rédigée par l\'IA depuis la photo, coupe droite, deux poches.</p>', 'moreInformations' => '<p>Lavage à froid.</p>', 'tags' => 'veste', 'confidence' => 'high']]], 'usage' => (object) ['input_tokens' => 10, 'output_tokens' => 10]];
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . "/admin/produits/$id/ia/decrire", ['_token' => $token], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $this->assertSame(302, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
        $this->assertStringContainsString('crudAction=edit', (string) $this->client->getResponse()->headers->get('Location'), 'retour sur la fiche produit');
        $this->assertStringContainsString('rédigée par l\'IA', (string) $this->db->fetchOne('SELECT description FROM product WHERE id = ?', [$id]));

        // Traduction
        FakeLandingAiClient::$queue[] = (object) ['content' => [(object) ['type' => 'tool_use', 'id' => 'tu_2', 'name' => 'translate_product', 'input' => (object) ['en' => (object) ['name' => 'AI jacket', 'description' => '<p>Jacket.</p>', 'moreInformations' => '', 'tags' => 'jacket']]]], 'usage' => (object) ['input_tokens' => 10, 'output_tokens' => 10]];
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . "/admin/produits/$id/ia/traduire", ['_token' => $token], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $this->assertSame(302, $this->client->getResponse()->getStatusCode());
        $this->assertSame('AI jacket', $this->db->fetchOne("SELECT name FROM product_translation WHERE product_id = ? AND locale = 'en'", [$id]));
        $this->assertSame(2, (int) $this->db->fetchOne("SELECT COALESCE(SUM(credits), 0) FROM ai_usage WHERE status = 'success'"), 'un crédit par action');

        // Image : fournisseur absent, aucun crédit
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . "/admin/produits/$id/ia/image", ['_token' => $token, 'style' => 'packshot'], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $this->assertSame(302, $this->client->getResponse()->getStatusCode());
        $this->client->followRedirect();
        $this->assertStringContainsString('fournisseur d\'images', html_entity_decode((string) $this->client->getResponse()->getContent()));
        $this->assertSame(2, (int) $this->db->fetchOne("SELECT COALESCE(SUM(credits), 0) FROM ai_usage WHERE status = 'success'"));
        $this->assertSame(0, (int) $this->db->fetchOne("SELECT COUNT(*) FROM product_picture WHERE product_id = ? AND source = 'ai'", [$id]));
    }

    private function loginAs(array $roles): void
    {
        $container = static::getContainer();
        $provider = $container->get(TenantEntityManagerProvider::class);
        $email = 'pai-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('P')->setLastname('T')->setRoles($roles)->setIsVerified(true);
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, 'Mot-de-passe-de-test-1!'));
        $provider->getEntityManager()->persist($user);
        $provider->getEntityManager()->flush();
        $this->client->getCookieJar()->clear();
        $this->client->loginUser($user, 'main');
        OtpTestHelper::validateIfRequired($this->client, $user);
    }
}
