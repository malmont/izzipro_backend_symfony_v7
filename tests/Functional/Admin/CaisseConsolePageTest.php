<?php

namespace App\Tests\Functional\Admin;

use App\Entity\User;
use App\Services\TenantEntityManagerProvider;
use App\Tests\Functional\Support\OtpTestHelper;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Page « Caisse : réglages et cheminement » (07/11/2026) : les étapes de la caisse du cheminement du site, avec leur couleur et leur
 * écran exact, le résumé des réglages ; refusée au client.
 */
class CaisseConsolePageTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        static::getContainer()->get(TenantEntityManagerProvider::class)->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
    }

    public function testThePageListsThePosStepsWithTheirScreens(): void
    {
        $this->loginAs(['ROLE_USER_INTERNET']);
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin/caisse', [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $this->assertContains($this->client->getResponse()->getStatusCode(), [302, 403]);

        $this->loginAs(['ROLE_ADMIN', 'ROLE_USER_INTERNET']);
        $master = static::getContainer()->get(\App\Services\TenantConnectionManager::class)->getPdoMaster();
        $db = static::getContainer()->get(TenantEntityManagerProvider::class)->getEntityManager()->getConnection();
        $master->prepare("DELETE FROM tenant_entitlements WHERE tenant_code = ? AND feature_key = 'module.pos'")->execute([MV_TEST_TENANT_CODE]);
        $db->executeStatement('UPDATE entreprise SET is_pos_active = FALSE');
        static::getContainer()->get(\App\Services\TenantCacheService::class)->invalidateTenant();
        try {
            // Sans la caisse : la page le dit et mène à l'essai
            $crawler = $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin?routeName=admin_caisse', [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
            $this->assertSame(200, $this->client->getResponse()->getStatusCode());
            $this->assertSame(1, $crawler->filter('[data-caisse-not-granted] a[href]')->count());
            $this->assertSame('none', $crawler->filter('[data-caisse-cheminement]')->attr('data-level'));
            $this->assertSame(1, $crawler->filter('#arkanoa-assistant')->count(), 'console IA présente : la page garde le corps EasyAdmin');

            // Caisse accordée : les étapes d'ouverture, avec couleurs et écrans
            static::getContainer()->get(\App\Services\Platform\EntitlementService::class)->grant(MV_TEST_TENANT_CODE, 'module.pos', 'manual', null, 'test', null, null, null, false);
            static::getContainer()->get(\App\Services\TenantCacheService::class)->invalidateTenant();
            $crawler = $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin?routeName=admin_caisse', [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
            $this->assertSame(200, $this->client->getResponse()->getStatusCode());
            foreach (['pos.key', 'pos.register', 'pos.cashiers', 'pos.receipt', 'pos.terminal', 'pos.denominations', 'pos.first_sale'] as $key) {
                $this->assertSame(1, $crawler->filter('[data-step="' . $key . '"]')->count(), $key);
            }
            $this->assertSame(0, $crawler->filter('[data-step^="obtain."]')->count());
            $this->assertStringContainsString('indispensable', (string) $this->client->getResponse()->getContent(), 'sans clé ni caissier : étapes rouges');
            $this->assertGreaterThan(0, $crawler->filter('[data-step][data-status="todo"] a[href]')->count(), 'chaque étape à faire mène à son écran exact');
            $this->assertSame(1, $crawler->filter('[data-caisse-resume]')->count());
            $this->assertSame('todo', $crawler->filter('[data-caisse-cheminement]')->attr('data-level'));
        } finally {
            $master->prepare("DELETE FROM tenant_entitlements WHERE tenant_code = ? AND feature_key = 'module.pos'")->execute([MV_TEST_TENANT_CODE]);
            $master->exec("DELETE FROM tenant_entitlement_events WHERE feature_key = 'module.pos'");
            $db->executeStatement('UPDATE entreprise SET is_pos_active = FALSE');
            static::getContainer()->get(\App\Services\TenantCacheService::class)->invalidateTenant();
        }
    }

    private function loginAs(array $roles): void
    {
        $container = static::getContainer();
        $provider = $container->get(TenantEntityManagerProvider::class);
        $email = 'caisse-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Caisse')->setLastname('Test')->setRoles($roles)->setIsVerified(true);
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, 'Mot-de-passe-de-test-1!'));
        $provider->getEntityManager()->persist($user);
        $provider->getEntityManager()->flush();
        $this->client->getCookieJar()->clear();
        $this->client->loginUser($user, 'main');
        OtpTestHelper::validateIfRequired($this->client, $user);
    }
}
