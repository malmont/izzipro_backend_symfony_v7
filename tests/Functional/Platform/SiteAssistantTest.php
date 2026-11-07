<?php

namespace App\Tests\Functional\Platform;

use App\Entity\User;
use App\Services\LandingAiService\LandingAiQuotaService;
use App\Services\Platform\FeatureRequestRepository;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use App\Tests\Fake\FakeLandingAiClient;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Assistant IA de configuration (05/11/2026) : conversation avec outils, propositions confirmées avant écriture (mêmes cas d'usage que
 * Mon site), outils immédiats, crédits consommés ou remboursés, validation et rôles. Fausse IA (FakeLandingAiClient).
 */
class SiteAssistantTest extends WebTestCase
{
    private KernelBrowser $client;
    private \Doctrine\DBAL\Connection $db;
    private \PDO $master;
    private array $before;
    private ?string $boutiqueBefore;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        FakeLandingAiClient::reset();
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $this->db = $provider->getEntityManager()->getConnection();
        $this->master = static::getContainer()->get(TenantConnectionManager::class)->getPdoMaster();
        $this->before = $this->db->fetchAssociative('SELECT locale, currency, is_boussole_esg_active FROM entreprise ORDER BY id LIMIT 1');
        $this->boutiqueBefore = $this->db->fetchOne('SELECT configuration::text FROM boutique_setting ORDER BY id LIMIT 1') ?: null;
        $this->db->executeStatement('DELETE FROM ai_usage');
        $this->master->prepare('DELETE FROM tenant_feature_requests WHERE tenant_code = ?')->execute([MV_TEST_TENANT_CODE]);
    }

    protected function tearDown(): void
    {
        $this->db->executeStatement('UPDATE entreprise SET locale = ?, currency = ?, is_boussole_esg_active = ? WHERE id = (SELECT id FROM entreprise ORDER BY id LIMIT 1)', [$this->before['locale'], $this->before['currency'], (bool) $this->before['is_boussole_esg_active']], [\Doctrine\DBAL\ParameterType::STRING, \Doctrine\DBAL\ParameterType::STRING, \Doctrine\DBAL\ParameterType::BOOLEAN]);
        if ($this->boutiqueBefore !== null) {
            $this->db->executeStatement('UPDATE boutique_setting SET configuration = ?::json WHERE id = (SELECT id FROM boutique_setting ORDER BY id LIMIT 1)', [$this->boutiqueBefore]);
        } else {
            $this->db->executeStatement('DELETE FROM boutique_setting');
        }
        $this->master->prepare('DELETE FROM tenant_feature_requests WHERE tenant_code = ?')->execute([MV_TEST_TENANT_CODE]);
        static::getContainer()->get(\App\Services\TenantCacheService::class)->invalidateTenant();
        parent::tearDown();
    }

    public function testTheAssistantProposesSettingsThatAreAppliedOnlyAfterConfirmation(): void
    {
        $this->assertSame(401, $this->api('POST', '/api/site/assistant', ['messages' => [['role' => 'user', 'content' => 'Bonjour']]], null)->getStatusCode());
        $this->assertSame(403, $this->api('POST', '/api/site/assistant', ['messages' => [['role' => 'user', 'content' => 'Bonjour']]], 'client')->getStatusCode());
        $this->assertSame(422, $this->api('POST', '/api/site/assistant', ['messages' => []], 'admin')->getStatusCode());
        $this->assertSame(422, $this->api('POST', '/api/site/assistant', ['messages' => [['role' => 'assistant', 'content' => 'x']]], 'admin')->getStatusCode(), 'le dernier message doit être celui de l\'utilisateur');

        // 1er tour : l'IA lit le cheminement puis propose un réglage ; 2e tour : elle conclut
        FakeLandingAiClient::$queue[] = (object) ['content' => [
            (object) ['type' => 'text', 'text' => 'Je regarde votre site.'],
            (object) ['type' => 'tool_use', 'id' => 'tu_1', 'name' => 'get_readiness', 'input' => (object) []],
            (object) ['type' => 'tool_use', 'id' => 'tu_2', 'name' => 'set_site_settings', 'input' => (object) ['values' => (object) ['site.locale' => 'en', 'commerce.sales_modes' => ['sale', 'rental']], 'summary' => 'Passer le site en anglais et retirer les abonnements']],
            (object) ['type' => 'tool_use', 'id' => 'tu_3', 'name' => 'open_screen', 'input' => (object) ['screen' => 'taxes']],
            (object) ['type' => 'tool_use', 'id' => 'tu_4', 'name' => 'set_site_settings', 'input' => (object) ['values' => (object) ['module.boutique' => true], 'summary' => 'interdit']],
            (object) ['type' => 'tool_use', 'id' => 'tu_5', 'name' => 'delegate_to_editor', 'input' => (object) ['task' => 'Composer la fiche produit de location', 'section' => 'pages', 'feature' => 'commerce.sales_modes']],
        ], 'usage' => (object) ['input_tokens' => 1200, 'output_tokens' => 80]];
        FakeLandingAiClient::$queue[] = (object) ['content' => [(object) ['type' => 'text', 'text' => 'Je vous propose de passer le site en anglais et de retirer le mode abonnement ; confirmez pour appliquer. Les taxes se règlent dans l\'écran Taxes.']], 'usage' => (object) ['input_tokens' => 1500, 'output_tokens' => 60]];
        $r = $this->api('POST', '/api/site/assistant', ['messages' => [['role' => 'user', 'content' => 'Je veux vendre en anglais, sans abonnement']]], 'admin');
        $this->assertSame(200, $r->getStatusCode(), $r->getContent());
        $body = json_decode((string) $r->getContent(), true);
        $this->assertStringContainsString('confirmez', $body['reply']);
        $this->assertSame(1, count($body['actions']), 'une seule proposition retenue : la clé plateforme est refusée');
        $this->assertSame(['set_site_settings', 'Passer le site en anglais et retirer les abonnements'], [$body['actions'][0]['tool'], $body['actions'][0]['summary']]);
        $this->assertSame([['label' => 'Taxes', 'screen' => 'taxes']], $body['links']);
        $this->assertSame([['task' => 'Composer la fiche produit de location', 'section' => 'pages', 'feature' => 'commerce.sales_modes']], $body['handoffs'], 'relais vers l\'assistant de l\'éditeur, rien côté serveur');
        $this->assertArrayHasKey('readiness', $body);
        $this->assertSame(1, $body['credits']['used'], 'un crédit consommé');
        $this->assertSame($this->before['locale'], $this->db->fetchOne('SELECT locale FROM entreprise ORDER BY id LIMIT 1'), 'rien n\'est écrit avant confirmation');
        $payload = FakeLandingAiClient::$requests[0];
        $this->assertSame('claude-sonnet-5-5', $payload['model']);
        $this->assertSame(['get_settings', 'get_readiness', 'open_screen', 'set_site_settings', 'get_domain_status', 'set_custom_domain', 'delegate_to_editor', 'estimate_product_dimensions', 'request_feature'], array_column($payload['tools'], 'name'));
        $this->assertStringContainsString('CHEMINEMENT', $payload['system']);
        $second = FakeLandingAiClient::$requests[1];
        $this->assertSame('tool_result', $second['messages'][2]['content'][0]['type'], 'résultats d\'outils renvoyés à l\'IA');
        $this->assertStringContainsString('réservée à la plateforme', json_encode($second['messages'][2]['content'][3]['content'], JSON_UNESCAPED_UNICODE));

        // confirmation : écriture par le cas d'usage du registre
        $id = $body['actions'][0]['id'];
        $r = $this->api('POST', "/api/site/assistant/actions/$id/confirm", null, 'admin');
        $this->assertSame(200, $r->getStatusCode(), $r->getContent());
        $this->assertSame(['site.locale', 'commerce.sales_modes'], json_decode((string) $r->getContent(), true)['result']['applied']);
        $this->assertSame('en', $this->db->fetchOne('SELECT locale FROM entreprise ORDER BY id LIMIT 1'));
        $this->assertSame(['sale', 'rental'], json_decode((string) $this->db->fetchOne('SELECT configuration::text FROM boutique_setting ORDER BY id LIMIT 1'), true)['commerce']['salesModes']);
        $this->assertSame(404, $this->api('POST', "/api/site/assistant/actions/$id/confirm", null, 'admin')->getStatusCode(), 'une action ne s\'applique qu\'une fois');
        $this->assertSame(404, $this->api('POST', '/api/site/assistant/actions/0123456789abcdef/confirm', null, 'admin')->getStatusCode());
    }

    public function testFeatureRequestsCreditsRefundAndFailures(): void
    {
        $this->db->executeStatement('UPDATE entreprise SET is_boussole_esg_active = false WHERE id = (SELECT id FROM entreprise ORDER BY id LIMIT 1)');
        static::getContainer()->get(\App\Services\TenantCacheService::class)->invalidateTenant();
        $this->master->prepare('DELETE FROM tenant_entitlements WHERE tenant_code = ? AND feature_key = ?')->execute([MV_TEST_TENANT_CODE, 'module.boussole']);
        FakeLandingAiClient::$queue[] = (object) ['content' => [(object) ['type' => 'tool_use', 'id' => 'tu_1', 'name' => 'request_feature', 'input' => (object) ['feature' => 'module.boussole', 'message' => 'via l\'assistant']]], 'usage' => (object) ['input_tokens' => 10, 'output_tokens' => 5]];
        FakeLandingAiClient::$queue[] = (object) ['content' => [(object) ['type' => 'text', 'text' => 'Je propose de demander la Boussole ESG.']], 'usage' => (object) ['input_tokens' => 10, 'output_tokens' => 5]];
        $body = json_decode((string) $this->api('POST', '/api/site/assistant', ['messages' => [['role' => 'user', 'content' => 'Je veux la Boussole']]], 'admin')->getContent(), true);
        $this->assertSame('request_feature', $body['actions'][0]['tool']);
        $r = $this->api('POST', '/api/site/assistant/actions/' . $body['actions'][0]['id'] . '/confirm', null, 'admin');
        $this->assertSame(200, $r->getStatusCode(), $r->getContent());
        $this->assertSame('trial', json_decode((string) $r->getContent(), true)['result']['kind'], 'politique par défaut : essai immédiat');
        $this->assertSame([], static::getContainer()->get(FeatureRequestRepository::class)->pendingFor(MV_TEST_TENANT_CODE));

        // échec de l'IA : 503, crédit remboursé
        FakeLandingAiClient::$queue[] = new \RuntimeException('API Anthropic injoignable.');
        $r = $this->api('POST', '/api/site/assistant', ['messages' => [['role' => 'user', 'content' => 'Encore']]], 'admin');
        $this->assertSame(503, $r->getStatusCode());
        $this->assertSame('AI_UNAVAILABLE', json_decode((string) $r->getContent(), true)['code']);
        $this->assertSame(1, static::getContainer()->get(LandingAiQuotaService::class)->credits()['used'], 'l\'échec ne coûte rien');
        $this->assertSame(1, json_decode((string) $this->api('GET', '/api/site/assistant/credits', null, 'admin')->getContent(), true)['credits']['used']);
    }

    /** Propriétaire de la plateforme (07/11/2026) : accès complet sur tout site, aucun crédit du site décompté, consigne adaptée */
    public function testThePlatformOwnerHasFullAccessWithoutSpendingTheSiteCredits(): void
    {
        $usedBefore = static::getContainer()->get(LandingAiQuotaService::class)->credits()['used'];
        FakeLandingAiClient::$queue[] = (object) ['content' => [(object) ['type' => 'text', 'text' => 'Bonjour, propriétaire.']], 'usage' => (object) ['input_tokens' => 100, 'output_tokens' => 10]];
        $r = $this->api('POST', '/api/site/assistant', ['messages' => [['role' => 'user', 'content' => 'Où en est ce site ?']]], 'super');
        $this->assertSame(200, $r->getStatusCode(), $r->getContent());
        $body = json_decode((string) $r->getContent(), true);
        $this->assertSame(['full', 'Bonjour, propriétaire.'], [$body['access'], $body['reply']]);
        $this->assertSame($usedBefore, $body['credits']['used'], 'aucun crédit du site décompté');
        $this->assertSame($usedBefore, static::getContainer()->get(LandingAiQuotaService::class)->credits()['used']);
        $system = (string) (end(FakeLandingAiClient::$requests)['system'] ?? '');
        $this->assertStringContainsString('propriétaire de la plateforme', $system, 'consigne système adaptée');

        FakeLandingAiClient::$queue[] = (object) ['content' => [(object) ['type' => 'text', 'text' => 'Bonjour.']], 'usage' => (object) ['input_tokens' => 100, 'output_tokens' => 10]];
        $body = json_decode((string) $this->api('POST', '/api/site/assistant', ['messages' => [['role' => 'user', 'content' => 'Bonjour']]], 'admin')->getContent(), true);
        $this->assertSame('site', $body['access'], 'un administrateur de site reste en accès site');
        $this->assertSame($usedBefore + 1, $body['credits']['used'], 'et consomme un crédit');
        $this->assertStringNotContainsString('propriétaire de la plateforme', (string) (end(FakeLandingAiClient::$requests)['system'] ?? ''));
    }

    private function api(string $method, string $path, ?array $body, ?string $as): \Symfony\Component\HttpFoundation\Response
    {
        $server = ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json', 'HTTP_X_XSRF_TOKEN' => 'jeton-xsrf-de-test'];
        if ($as !== null) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer ' . $this->token($as);
        }
        $this->client->request($method, 'https://' . MV_TEST_TENANT_HOST . $path . '?locale=fr', [], [], $server, $body !== null ? json_encode($body) : null);

        return $this->client->getResponse();
    }

    private function token(string $as): string
    {
        $container = static::getContainer();
        $container->get(TenantEntityManagerProvider::class)->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $em = $container->get(TenantEntityManagerProvider::class)->getEntityManager();
        $email = match ($as) { 'admin' => 'admin@arkanoa-media.com', 'super' => 'super-assistant@example.invalid', default => 'client-assistant@example.invalid' };
        $user = $em->getRepository(User::class)->findOneBy(['email' => $email]);
        if (!$user) {
            $roles = $as === 'super' ? ['ROLE_USER_INTERNET', 'ROLE_ADMIN', 'ROLE_SUPER_ADMIN'] : ['ROLE_USER_INTERNET'];
            $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Client')->setLastname('Test')->setRoles($roles)->setPassword('inutilisable')->setIsVerified(true);
            $em->persist($user);
            $em->flush();
        }

        return $container->get('lexik_jwt_authentication.jwt_manager')->create($user);
    }
}
