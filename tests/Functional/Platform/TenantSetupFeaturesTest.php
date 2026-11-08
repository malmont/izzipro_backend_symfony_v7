<?php

namespace App\Tests\Functional\Platform;

use App\Entity\Entreprise;
use App\Services\Platform\EntitlementService;
use App\Services\Platform\FeaturePolicyRepository;
use App\Services\Platform\FeatureRequestRepository;
use App\Services\Platform\TenantSetupFeaturesService;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Étape « Votre activité » de la création d'un site (lot 5, 04/11/2026) : le formulaire la propose ; appliquée sur le second site de test */
class TenantSetupFeaturesTest extends WebTestCase
{
    private KernelBrowser $client;
    private TenantEntityManagerProvider $provider;
    private \PDO $master;
    private array $before;
    private ?string $boutiqueBefore;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $this->provider->switchTenant(MV_TEST_TENANT2_DB, MV_TEST_TENANT2_CODE);
        $db = $this->provider->getEntityManager()->getConnection();
        $this->before = $db->fetchAssociative('SELECT locale, pos_timezone, currency, is_landing_page_active, is_boutique_active, is_boussole_esg_active, is_memoire_vivante_active FROM entreprise ORDER BY id LIMIT 1');
        $this->boutiqueBefore = $db->fetchOne('SELECT configuration::text FROM boutique_setting ORDER BY id LIMIT 1') ?: null;
        $this->master = static::getContainer()->get(TenantConnectionManager::class)->getPdoMaster();
        $this->clean();
    }

    protected function tearDown(): void
    {
        $this->provider->switchTenant(MV_TEST_TENANT2_DB, MV_TEST_TENANT2_CODE);
        $db = $this->provider->getEntityManager()->getConnection();
        $b = $this->before;
        $db->executeStatement('UPDATE entreprise SET locale = ?, pos_timezone = ?, currency = ?, is_landing_page_active = ?, is_boutique_active = ?, is_boussole_esg_active = ?, is_memoire_vivante_active = ? WHERE id = (SELECT id FROM entreprise ORDER BY id LIMIT 1)',
            [$b['locale'], $b['pos_timezone'], $b['currency'], (bool) $b['is_landing_page_active'], (bool) $b['is_boutique_active'], (bool) $b['is_boussole_esg_active'], (bool) $b['is_memoire_vivante_active']],
            [\Doctrine\DBAL\ParameterType::STRING, \Doctrine\DBAL\ParameterType::STRING, \Doctrine\DBAL\ParameterType::STRING, \Doctrine\DBAL\ParameterType::BOOLEAN, \Doctrine\DBAL\ParameterType::BOOLEAN, \Doctrine\DBAL\ParameterType::BOOLEAN, \Doctrine\DBAL\ParameterType::BOOLEAN]);
        if ($this->boutiqueBefore !== null) {
            $db->executeStatement('UPDATE boutique_setting SET configuration = ?::json WHERE id = (SELECT id FROM boutique_setting ORDER BY id LIMIT 1)', [$this->boutiqueBefore]);
        } else {
            $db->executeStatement('DELETE FROM boutique_setting');
        }
        $this->clean();
        $this->master->exec("DELETE FROM platform_feature_policies WHERE feature_key = 'module.memoires'");
        static::getContainer()->get(\App\Services\TenantCacheService::class)->invalidateSite(MV_TEST_TENANT2_CODE, MV_TEST_TENANT2_DB);
        \App\Tests\Functional\Support\AiRightTestHelper::restoreFullAi(static::getContainer()); // le site de test garde « IA complète » (08/11/2026)
        parent::tearDown();
    }

    public function testTheCreationFormOffersTheActivityStep(): void
    {
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/setup/new-store?subdomain=nouveausite', [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $html = $this->client->getResponse()->getContent();
        foreach (['Votre activité', 'tenant_setup[siteLocale]', 'tenant_setup[timezone]', 'tenant_setup[currency]', 'tenant_setup[features][]', 'tenant_setup[salesModes][]', 'Mémoires Vivantes', 'Boussole ESG'] as $needle) {
            $this->assertStringContainsString($needle, $html);
        }
        $this->assertSame(2, preg_match_all('/name="tenant_setup\[features\]\[\]"[^>]*checked/', $html), 'Landing Page et Boutique cochées par défaut');
    }

    public function testTheActivityStepAppliesLocalisationModesTrialsAndRequests(): void
    {
        $this->master->prepare("INSERT INTO platform_feature_policies (feature_key, trial_allowed, trial_days, free_allowed, grace_days) VALUES ('module.memoires', FALSE, 30, TRUE, 7) ON CONFLICT (feature_key) DO UPDATE SET trial_allowed = FALSE")->execute();
        $em = $this->provider->getEntityManager();
        $entreprise = $em->getRepository(Entreprise::class)->findOneBy([], ['id' => 'ASC']);
        $outcome = static::getContainer()->get(TenantSetupFeaturesService::class)->apply(MV_TEST_TENANT2_CODE, $entreprise, 'en', 'America/Vancouver', 'usd', ['module.landing', 'module.boussole', 'module.memoires', 'inconnue.x'], ['sale', 'subscription'], 'createur@example.invalid');
        $this->assertSame([['module.landing', 'module.boussole'], ['module.memoires'], []], [$outcome['trials'], $outcome['requested'], $outcome['errors']]);
        $db = $em->getConnection();
        $this->assertSame(['en', 'America/Vancouver', 'USD'], array_values($db->fetchAssociative('SELECT locale, pos_timezone, currency FROM entreprise ORDER BY id LIMIT 1')));
        $this->assertSame(['sale', 'subscription'], json_decode((string) $db->fetchOne('SELECT configuration::text FROM boutique_setting ORDER BY id LIMIT 1'), true)['commerce']['salesModes']);
        $eff = static::getContainer()->get(EntitlementService::class)->effective(MV_TEST_TENANT2_CODE);
        $this->assertSame(['trial', 'trial', false, false], [$eff['module.landing']['source'], $eff['module.boussole']['source'], $eff['module.memoires']['enabled'], $eff['module.boutique']['enabled']]);
        $flags = $db->fetchAssociative('SELECT is_landing_page_active, is_boutique_active, is_boussole_esg_active, is_memoire_vivante_active FROM entreprise ORDER BY id LIMIT 1');
        $this->assertSame([true, false, true, false], array_map(fn ($v) => (bool) $v, array_values($flags)), 'projection : seuls les essais ouverts sont actifs');
        $this->assertSame(['module.memoires' => 'pending'], static::getContainer()->get(FeatureRequestRepository::class)->pendingFor(MV_TEST_TENANT2_CODE), 'essai interdit : demande');
        $this->assertGreaterThanOrEqual(2, count($this->getMailerMessages()), 'courriels d\'essai au contact du site');
    }

    private function clean(): void
    {
        foreach (['tenant_entitlement_events', 'tenant_entitlements', 'tenant_feature_requests'] as $table) {
            $this->master->prepare("DELETE FROM $table WHERE tenant_code = ?")->execute([MV_TEST_TENANT2_CODE]);
        }
    }
}
