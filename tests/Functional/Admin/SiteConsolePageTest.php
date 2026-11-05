<?php

namespace App\Tests\Functional\Admin;

use App\Entity\User;
use App\Services\Platform\FeatureRequestRepository;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use App\Tests\Functional\Support\OtpTestHelper;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/** Page EasyAdmin « Mon site » (lot 5, 04/11/2026) : administrateur du site ; sections, formulaires Localisation et Commerce, demande d'une fonctionnalité */
class SiteConsolePageTest extends WebTestCase
{
    private KernelBrowser $client;
    private \Doctrine\DBAL\Connection $db;
    private \PDO $master;
    private array $before;
    private ?string $boutiqueBefore;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $this->db = $provider->getEntityManager()->getConnection();
        $this->master = static::getContainer()->get(TenantConnectionManager::class)->getPdoMaster();
        $this->before = $this->db->fetchAssociative('SELECT locale, pos_timezone, currency, is_boussole_esg_active FROM entreprise ORDER BY id LIMIT 1');
        $this->boutiqueBefore = $this->db->fetchOne('SELECT configuration::text FROM boutique_setting ORDER BY id LIMIT 1') ?: null;
        $this->master->prepare('DELETE FROM tenant_feature_requests WHERE tenant_code = ?')->execute([MV_TEST_TENANT_CODE]);
    }

    protected function tearDown(): void
    {
        $this->db->executeStatement('UPDATE entreprise SET locale = ?, pos_timezone = ?, currency = ?, is_boussole_esg_active = ? WHERE id = (SELECT id FROM entreprise ORDER BY id LIMIT 1)', [$this->before['locale'], $this->before['pos_timezone'], $this->before['currency'], (bool) $this->before['is_boussole_esg_active']], [\Doctrine\DBAL\ParameterType::STRING, \Doctrine\DBAL\ParameterType::STRING, \Doctrine\DBAL\ParameterType::STRING, \Doctrine\DBAL\ParameterType::BOOLEAN]);
        if ($this->boutiqueBefore !== null) {
            $this->db->executeStatement('UPDATE boutique_setting SET configuration = ?::json WHERE id = (SELECT id FROM boutique_setting ORDER BY id LIMIT 1)', [$this->boutiqueBefore]);
        } else {
            $this->db->executeStatement('DELETE FROM boutique_setting');
        }
        $this->master->prepare('DELETE FROM tenant_feature_requests WHERE tenant_code = ?')->execute([MV_TEST_TENANT_CODE]);
        $this->master->exec("DELETE FROM platform_feature_policies WHERE feature_key = 'module.boussole'");
        static::getContainer()->get(\App\Services\TenantCacheService::class)->invalidateTenant();
        parent::tearDown();
    }

    public function testTheAdminSeesAllSectionsAndSettlesLocalisationCommerceAndRequests(): void
    {
        $this->loginAs(['ROLE_USER_INTERNET']);
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin/mon-site', [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $this->assertContains($this->client->getResponse()->getStatusCode(), [302, 403], 'client : refusé');

        $this->loginAs(['ROLE_ADMIN', 'ROLE_USER_INTERNET']);
        $this->db->executeStatement('UPDATE entreprise SET is_boussole_esg_active = false WHERE id = (SELECT id FROM entreprise ORDER BY id LIMIT 1)');
        static::getContainer()->get(\App\Services\TenantCacheService::class)->invalidateTenant();
        $crawler = $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin/mon-site', [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $html = $this->client->getResponse()->getContent();
        foreach (['Localisation', 'Modules et applications', 'Commerce', 'Services et quotas', 'mon-site-localisation', 'mon-site-commerce', 'Boussole ESG', 'Paiements en ligne (Stripe)'] as $needle) {
            $this->assertStringContainsString($needle, $html);
        }
        $this->assertGreaterThanOrEqual(1, $crawler->filter('tr[data-key="module.boussole"] form')->count(), 'bouton Essayer ou Demander sur un module non accordé');
        $this->assertStringContainsString('Mettre à jour mon forfait', $html);
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin/mon-site/forfait', [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), 'page d\'attente tant que la boutique de forfaits n\'existe pas');
        $crawler = $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin/mon-site', [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $token = $crawler->filter('form.mon-site-localisation input[name="_token"]')->attr('value');

        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . '/admin/mon-site/regler', ['_token' => $token, 'section' => 'localisation', 'site_locale' => 'en', 'site_timezone' => 'America/Vancouver', 'site_currency' => 'USD', 'site_prices_include_tax' => '0'], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $this->assertSame(302, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['en', 'America/Vancouver', 'USD'], array_values($this->db->fetchAssociative('SELECT locale, pos_timezone, currency FROM entreprise ORDER BY id LIMIT 1')));
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . '/admin/mon-site/regler', ['_token' => $token, 'section' => 'localisation', 'site_locale' => '', 'site_timezone' => 'Mars/Olympus', 'site_currency' => 'USD'], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $this->client->followRedirect();
        $this->assertStringContainsString('site.timezone', $this->client->getResponse()->getContent(), 'erreur affichée dans le vocabulaire du registre');
        $this->assertSame('America/Vancouver', $this->db->fetchOne('SELECT pos_timezone FROM entreprise ORDER BY id LIMIT 1'), 'rien écrit');

        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . '/admin/mon-site/regler', ['_token' => $token, 'section' => 'commerce', 'commerce_sales_modes' => ['sale', 'rental'], 'commerce_guest_checkout' => '1', 'commerce_tax_provider' => 'table'], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $this->assertSame(302, $this->client->getResponse()->getStatusCode());
        $commerce = json_decode((string) $this->db->fetchOne('SELECT configuration::text FROM boutique_setting ORDER BY id LIMIT 1'), true)['commerce'];
        $this->assertSame([['sale', 'rental'], true, false, 'table'], [$commerce['salesModes'], $commerce['guestCheckout'], $commerce['subscriptionsEnabled'], $commerce['taxProvider']], 'cases absentes = faux');

        // essai interdit par la politique → demande (sinon « Demander » ouvre un essai immédiat, règle du 05/11/2026)
        $this->master->prepare("INSERT INTO platform_feature_policies (feature_key, trial_allowed, trial_days, free_allowed, grace_days) VALUES ('module.boussole', FALSE, 30, TRUE, 7) ON CONFLICT (feature_key) DO UPDATE SET trial_allowed = FALSE")->execute();
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . '/admin/mon-site/demander/module.boussole', ['_token' => $token], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $this->assertSame(['module.boussole' => 'pending'], static::getContainer()->get(FeatureRequestRepository::class)->pendingFor(MV_TEST_TENANT_CODE));
        $crawler = $this->client->followRedirect();
        $this->assertStringContainsString('demande en attente', $this->client->getResponse()->getContent());
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . '/admin/mon-site/demander/module.boussole', ['_token' => 'faux'], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $this->assertSame(1, (int) $this->master->query("SELECT COUNT(*) FROM tenant_feature_requests WHERE tenant_code = '" . MV_TEST_TENANT_CODE . "'")->fetchColumn(), 'jeton invalide : rien');
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin', [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $this->assertStringContainsString('Mon site : pilotage', $this->client->getResponse()->getContent(), 'entrée du menu');
    }

    private function loginAs(array $roles): void
    {
        $container = static::getContainer();
        $provider = $container->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $email = 'monsite-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Site')->setLastname('Test')->setRoles($roles)->setIsVerified(true);
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, 'Mot-de-passe-de-test-1!'));
        $provider->getEntityManager()->persist($user);
        $provider->getEntityManager()->flush();
        $this->client->loginUser($user, 'main');
        OtpTestHelper::validateIfRequired($this->client, $user);
    }
}
