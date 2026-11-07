<?php

namespace App\Tests\Functional\Admin;

use App\Entity\User;
use App\Services\ProductAiService\ProductSheetCreator;
use App\Services\TenantEntityManagerProvider;
use App\Tests\Functional\Support\OtpTestHelper;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/** Page « Contrôles » (07/11/2026) : les contrôles comptent les cas réels (stock négatif, produit sans photo) et mènent à l'écran où corriger ; refusée au client. */
class ControlsConsolePageTest extends WebTestCase
{
    private KernelBrowser $client;
    private \Doctrine\DBAL\Connection $db;
    private array $created = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $this->db = $provider->getEntityManager()->getConnection();
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

    public function testControlsCountRealCasesAndLinkToScreens(): void
    {
        $this->loginAs(['ROLE_USER_INTERNET']);
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin/controles', [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $this->assertContains($this->client->getResponse()->getStatusCode(), [302, 403]);

        $this->loginAs(['ROLE_ADMIN', 'ROLE_USER_INTERNET']);
        $crawler = $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin?routeName=admin_controles', [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $before = (int) $crawler->filter('[data-control="products.negative_stock"] strong')->text();
        $noPictureBefore = (int) $crawler->filter('[data-control="products.no_picture"] strong')->text();

        // Un produit en ligne sans photo, dont la variante passe en stock négatif
        $id = static::getContainer()->get(ProductSheetCreator::class)->create(['name' => 'Contrôle test', 'priceCents' => 500, 'language' => 'fr', 'channels' => ['web' => true]], 'test')['body']['productId'];
        $this->created[] = $id;
        $this->db->executeStatement('UPDATE product_variant SET stock_quantity = -2 WHERE product_id = ?', [$id]);
        static::getContainer()->get(\App\Services\TenantCacheService::class)->invalidateTenant();
        $crawler = $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin?routeName=admin_controles', [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $row = $crawler->filter('[data-control="products.negative_stock"]');
        $this->assertSame($before + 1, (int) $row->filter('strong')->text());
        $this->assertSame('problem', $row->attr('data-severity'));
        $this->assertSame(1, $row->filter('a[href]')->count(), 'lien vers les produits');
        $this->assertSame($noPictureBefore + 1, (int) $crawler->filter('[data-control="products.no_picture"] strong')->text());
        $this->assertGreaterThanOrEqual(1, (int) $crawler->filter('[data-controles]')->attr('data-problems'));
        $this->assertSame(1, $crawler->filter('#arkanoa-assistant')->count(), 'corps EasyAdmin conservé');
    }

    private function loginAs(array $roles): void
    {
        $container = static::getContainer();
        $provider = $container->get(TenantEntityManagerProvider::class);
        $email = 'ctl-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Ctl')->setLastname('Test')->setRoles($roles)->setIsVerified(true);
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, 'Mot-de-passe-de-test-1!'));
        $provider->getEntityManager()->persist($user);
        $provider->getEntityManager()->flush();
        $this->client->getCookieJar()->clear();
        $this->client->loginUser($user, 'main');
        OtpTestHelper::validateIfRequired($this->client, $user);
    }
}
