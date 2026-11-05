<?php

namespace App\Tests\Functional\Admin;

use App\Entity\User;
use App\Services\Platform\EntitlementService;
use App\Services\Platform\PlatformModeService;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use App\Tests\Functional\Support\OtpTestHelper;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/** Page EasyAdmin « Plateforme » (lot 4, 04/11/2026) : réservée au super administrateur, grille, formulaires (CSRF), mode, offre */
class PlatformConsolePageTest extends WebTestCase
{
    private KernelBrowser $client;
    private \PDO $master;
    private \Doctrine\DBAL\Connection $db;
    private ?bool $boussoleBefore;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $this->db = $provider->getEntityManager()->getConnection();
        $this->master = static::getContainer()->get(TenantConnectionManager::class)->getPdoMaster();
        $this->boussoleBefore = (bool) $this->db->fetchOne('SELECT is_boussole_esg_active FROM entreprise ORDER BY id LIMIT 1');
        $this->clean();
    }

    protected function tearDown(): void
    {
        $this->db->executeStatement('UPDATE entreprise SET is_boussole_esg_active = ? WHERE id = (SELECT id FROM entreprise ORDER BY id LIMIT 1)', [$this->boussoleBefore], [\Doctrine\DBAL\ParameterType::BOOLEAN]);
        $this->clean();
        static::getContainer()->get(PlatformModeService::class)->setMode('demo', 'test');
        $this->master->exec("UPDATE platform_offers SET monthly_price_cents = 2900, trial_days = 30, is_active = TRUE WHERE code = 'landing'");
        static::getContainer()->get(\App\Services\TenantCacheService::class)->invalidateTenant();
        parent::tearDown();
    }

    public function testThePageIsReservedToTheSuperAdminAndItsFormsWork(): void
    {
        $this->loginAs(['ROLE_ADMIN', 'ROLE_USER_INTERNET']);
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin/plateforme', [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $this->assertSame(403, $this->client->getResponse()->getStatusCode(), 'administrateur de site : refusé');

        $this->loginAs(['ROLE_ADMIN', 'ROLE_SUPER_ADMIN']);
        $crawler = $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin/plateforme?site=' . MV_TEST_TENANT_CODE, [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $html = $this->client->getResponse()->getContent();
        $this->assertStringContainsString('Mode de la plateforme', $html);
        $this->assertStringContainsString('plateforme-grille', $html);
        $this->assertStringContainsString('plateforme-offres', $html);
        $this->assertStringContainsString('Boussole ESG', $html);
        $this->assertGreaterThanOrEqual(1, $crawler->filter('tr[data-site="' . MV_TEST_TENANT_CODE . '"]')->count());
        $token = $crawler->filter('form.plateforme-accorder input[name="_token"]')->attr('value');

        // accorder un essai depuis le formulaire
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . '/admin/plateforme/accorder', ['_token' => $token, 'tenant' => MV_TEST_TENANT_CODE, 'feature' => 'module.boussole', 'source' => 'trial', 'days' => '10', 'note' => 'depuis la page', 'site' => MV_TEST_TENANT_CODE], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $this->assertSame(302, $this->client->getResponse()->getStatusCode());
        $this->assertStringContainsString('routeName=admin_plateforme', (string) $this->client->getResponse()->headers->get('Location'), 'redirection vers la page habillée par EasyAdmin (05/11/2026)');
        $eff = static::getContainer()->get(EntitlementService::class)->effective(MV_TEST_TENANT_CODE)['module.boussole'];
        $this->assertSame([true, 'trial'], [$eff['enabled'], $eff['source']]);
        $crawler = $this->client->followRedirect();
        $this->assertStringContainsString('accordé', $this->client->getResponse()->getContent());
        $this->assertGreaterThanOrEqual(1, $crawler->filter('table.plateforme-droits')->count(), 'détail du site sélectionné');

        // jeton CSRF invalide : rien
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . '/admin/plateforme/droit/' . $eff['id'] . '/cancel', ['_token' => 'faux'], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $this->assertSame('active', static::getContainer()->get(EntitlementService::class)->find((int) $eff['id'])['status']);
        // suspendre puis prix d'une offre puis mode
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . '/admin/plateforme/droit/' . $eff['id'] . '/suspend', ['_token' => $token], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $this->assertSame('suspended', static::getContainer()->get(EntitlementService::class)->find((int) $eff['id'])['status']);
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . '/admin/plateforme/offre/landing', ['_token' => $token, 'monthlyPrice' => '34,50', 'yearlyPrice' => '', 'trialDays' => '14', 'isActive' => '1'], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $this->assertSame([3450, 14], array_map('intval', $this->master->query("SELECT monthly_price_cents, trial_days FROM platform_offers WHERE code = 'landing'")->fetch(\PDO::FETCH_NUM)));
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . '/admin/plateforme/mode', ['_token' => $token, 'mode' => 'live'], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $this->assertSame('live', static::getContainer()->get(PlatformModeService::class)->mode());
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . '/admin/plateforme/boutique', ['_token' => $token, 'shopUrl' => 'https://forfaits.example.invalid/'], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $this->assertSame('https://forfaits.example.invalid/', static::getContainer()->get(PlatformModeService::class)->shopUrl());
        static::getContainer()->get(PlatformModeService::class)->setShopUrl(null, 'test');
        // menu : entrée visible pour le super administrateur
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin', [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $this->assertStringContainsString('Plateforme : pilotage', $this->client->getResponse()->getContent());
    }

    private function clean(): void
    {
        foreach (['tenant_entitlement_events', 'tenant_entitlements', 'tenant_feature_requests'] as $table) {
            $this->master->prepare("DELETE FROM $table WHERE tenant_code = ?")->execute([MV_TEST_TENANT_CODE]);
        }
    }

    private function loginAs(array $roles): void
    {
        $container = static::getContainer();
        $provider = $container->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $email = 'console-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Console')->setLastname('Test')->setRoles($roles)->setIsVerified(true);
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, 'Mot-de-passe-de-test-1!'));
        $provider->getEntityManager()->persist($user);
        $provider->getEntityManager()->flush();
        $this->client->loginUser($user, 'main');
        OtpTestHelper::validateIfRequired($this->client, $user);
    }
}
