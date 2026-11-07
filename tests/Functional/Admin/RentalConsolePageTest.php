<?php

namespace App\Tests\Functional\Admin;

use App\Entity\User;
use App\Services\ProductAiService\ProductSheetCreator;
use App\Services\TenantEntityManagerProvider;
use App\Tests\Functional\Support\OtpTestHelper;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Page « Location et réservation : réglages et cheminement » (07/11/2026) : étapes (mode, produit louable, règles de stock, grilles
 * tarifaires, horaires, caution, première réservation), état par produit louable, liens vers les écrans ; refusée au client.
 */
class RentalConsolePageTest extends WebTestCase
{
    private KernelBrowser $client;
    private \Doctrine\DBAL\Connection $db;
    private array $created = [];
    private ?string $boutiqueBefore = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $this->db = $provider->getEntityManager()->getConnection();
        $this->boutiqueBefore = $this->db->fetchOne('SELECT configuration::text FROM boutique_setting ORDER BY id LIMIT 1') ?: null;
    }

    protected function tearDown(): void
    {
        foreach ($this->created as $id) {
            $this->db->executeStatement('DELETE FROM booking_configuration WHERE product_id = ?', [$id]);
            foreach (['product_variant', 'product_picture', 'product_translation', 'product_categories'] as $t) {
                $this->db->executeStatement("DELETE FROM $t WHERE product_id = ?", [$id]);
            }
            $this->db->executeStatement('DELETE FROM product WHERE id = ?', [$id]);
        }
        if ($this->boutiqueBefore !== null) {
            $this->db->executeStatement('UPDATE boutique_setting SET configuration = ?::json WHERE id = (SELECT id FROM boutique_setting ORDER BY id LIMIT 1)', [$this->boutiqueBefore]);
        }
        static::getContainer()->get(\App\Services\TenantCacheService::class)->invalidateTenant();
        parent::tearDown();
    }

    public function testStepsFollowTheRentalProductsAndTheirRules(): void
    {
        $this->loginAs(['ROLE_USER_INTERNET']);
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin/location', [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $this->assertContains($this->client->getResponse()->getStatusCode(), [302, 403]);

        $this->loginAs(['ROLE_ADMIN', 'ROLE_USER_INTERNET']);
        $crawler = $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin?routeName=admin_location', [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertSame(1, $crawler->filter('[data-location-cheminement]')->count());
        $this->assertSame(1, $crawler->filter('#arkanoa-assistant')->count(), 'console IA présente : corps EasyAdmin conservé');
        foreach (['rental.mode', 'rental.product', 'rental.booking', 'rental.rates', 'rental.hours', 'rental.deposit', 'rental.first_booking'] as $key) {
            $this->assertSame(1, $crawler->filter('[data-step="' . $key . '"]')->count(), $key);
        }
        $rentableBefore = (int) $this->db->fetchOne('SELECT COUNT(*) FROM product WHERE rental_enabled = TRUE');

        // Un produit louable sans règles ni tarif : étapes rouges, ligne du produit avec ses deux manques
        $id = static::getContainer()->get(ProductSheetCreator::class)->create(['name' => 'Kayak location test', 'priceCents' => 5000, 'language' => 'fr', 'modes' => ['sale' => false, 'rental' => true]], 'test')['body']['productId'];
        $this->created[] = $id;
        static::getContainer()->get(\App\Services\TenantCacheService::class)->invalidateTenant();
        $crawler = $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin?routeName=admin_location', [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $row = $crawler->filter('[data-rental-product="' . $id . '"]');
        $this->assertSame(1, $row->count(), 'le produit louable est listé');
        $this->assertStringContainsString('à régler', $row->text());
        $this->assertStringContainsString('aucun pack', $row->text());
        $this->assertSame('todo', $crawler->filter('[data-step="rental.booking"]')->attr('data-status'));
        $this->assertSame('todo', $crawler->filter('[data-step="rental.rates"]')->attr('data-status'));
        $this->assertGreaterThan(0, $crawler->filter('[data-step="rental.booking"] a[href]')->count(), 'lien vers Règles & Stocks');
        $this->assertSame($rentableBefore + 1, (int) (string) preg_replace('/\D/', '', $crawler->filter('[data-location-products] h2')->text()));

        // Règles de stock posées : l'étape passe (si tous les louables sont configurés)
        $this->db->executeStatement("INSERT INTO booking_configuration (id, product_id, granularity, stock_quantity, min_duration, buffer_time) VALUES (nextval('booking_configuration_id_seq'), ?, 'days', 3, 1, 30)", [$id]);
        static::getContainer()->get(\App\Services\TenantCacheService::class)->invalidateTenant();
        $crawler = $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin?routeName=admin_location', [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $this->assertStringContainsString('journée, stock 3', $crawler->filter('[data-rental-product="' . $id . '"]')->text());
    }

    private function loginAs(array $roles): void
    {
        $container = static::getContainer();
        $provider = $container->get(TenantEntityManagerProvider::class);
        $email = 'loc-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Loc')->setLastname('Test')->setRoles($roles)->setIsVerified(true);
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, 'Mot-de-passe-de-test-1!'));
        $provider->getEntityManager()->persist($user);
        $provider->getEntityManager()->flush();
        $this->client->getCookieJar()->clear();
        $this->client->loginUser($user, 'main');
        OtpTestHelper::validateIfRequired($this->client, $user);
    }
}
