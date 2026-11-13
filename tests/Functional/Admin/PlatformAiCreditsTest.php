<?php

namespace App\Tests\Functional\Admin;

use App\Entity\User;
use App\Services\TenantEntityManagerProvider;
use App\Tests\Functional\Support\OtpTestHelper;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Crédits IA réglés par la plateforme (07/11/2026) : la page Plateforme liste les sites et règle leurs crédits (super admin seulement),
 * l'administrateur ne peut pas les changer, l'étape du cheminement prévient quand ils manquent et mène à l'achat.
 */
class PlatformAiCreditsTest extends WebTestCase
{
    private KernelBrowser $client;
    private \Doctrine\DBAL\Connection $db;
    private mixed $before = null;
    private bool $hadRow = false;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $this->db = $provider->getEntityManager()->getConnection();
        $this->before = $this->db->fetchOne('SELECT monthly_credits FROM ai_credit_setting ORDER BY id LIMIT 1');
        $this->hadRow = $this->before !== false && $this->before !== null;
        $this->db->executeStatement('DELETE FROM ai_usage');
    }

    protected function tearDown(): void
    {
        if ($this->hadRow) {
            $this->db->executeStatement('UPDATE ai_credit_setting SET monthly_credits = ?', [$this->before]);
        } else {
            $this->db->executeStatement('DELETE FROM ai_credit_setting');
        }
        static::getContainer()->get(\App\Services\TenantCacheService::class)->invalidateTenant();
        parent::tearDown();
    }

    public function testOnlyThePlatformOwnerSetsCreditsAndTheSiteIsWarned(): void
    {
        // Administrateur du site : la page Plateforme est refusée, l'écran des crédits ne permet pas de les changer
        $this->loginAs(['ROLE_ADMIN', 'ROLE_USER_INTERNET']);
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin?routeName=admin_plateforme', [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $this->assertContains($this->client->getResponse()->getStatusCode(), [302, 403]);
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . '/admin/plateforme/ia-credits', ['tenant' => MV_TEST_TENANT_CODE, 'monthly' => 9999], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $this->assertContains($this->client->getResponse()->getStatusCode(), [302, 403]);
        $this->assertNotSame(9999, (int) $this->db->fetchOne('SELECT COALESCE((SELECT monthly_credits FROM ai_credit_setting ORDER BY id LIMIT 1), -1)'));
        $rowId = $this->db->fetchOne('SELECT id FROM ai_credit_setting ORDER BY id LIMIT 1');
        $formUrl = '/admin?crudControllerFqcn=' . urlencode(\App\Controller\Admin\AiCreditSettingCrudController::class) . ($rowId ? '&crudAction=edit&entityId=' . (int) $rowId : '&crudAction=new');
        $crawler = $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . $formUrl, [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertStringContainsString('réglés par la plateforme', html_entity_decode((string) $this->client->getResponse()->getContent()));
        $this->assertSame('disabled', $crawler->filter('input[name="AiCreditSetting[monthlyCredits]"]')->attr('disabled'), 'champ crédits verrouillé pour l\'administrateur');

        // Propriétaire de la plateforme : section « Crédits IA par site », réglage
        $this->loginAs(['ROLE_ADMIN', 'ROLE_SUPER_ADMIN', 'ROLE_USER_INTERNET']);
        $crawler = $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin?routeName=admin_plateforme', [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $row = $crawler->filter('[data-ai-credits-site="' . MV_TEST_TENANT_CODE . '"]');
        $this->assertSame(1, $row->count(), 'le site de test est listé');
        $token = $row->filter('input[name="_token"]')->attr('value');
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . '/admin/plateforme/ia-credits', ['_token' => $token, 'tenant' => MV_TEST_TENANT_CODE, 'monthly' => 7], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $this->assertSame(302, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
        $this->assertSame(7, (int) $this->db->fetchOne('SELECT monthly_credits FROM ai_credit_setting ORDER BY id LIMIT 1'));
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . '/admin/plateforme/ia-credits', ['_token' => $token, 'tenant' => MV_TEST_TENANT_CODE, 'monthly' => 200000], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $this->assertSame(7, (int) $this->db->fetchOne('SELECT monthly_credits FROM ai_credit_setting ORDER BY id LIMIT 1'), 'au-delà du plafond : refusé');

        // Cheminement du site : 7 crédits, 6 utilisés → il en reste 1 (moins de 20 %) : à acheter ; 0 crédit : épuisés
        $this->db->executeStatement("INSERT INTO ai_usage (tenant, user_identifier, created_at, mode, component_key, status, credits, model, attempts, input_tokens, output_tokens, cache_read_tokens, duration_ms, prompt_excerpt, cache_write_tokens) VALUES (?, 'test', NOW(), 'assistant', 'site-config', 'success', 6, 'claude-sonnet-5-5', 1, 0, 0, 0, 0, 'x', 0)", [MV_TEST_TENANT_CODE]);
        static::getContainer()->get(\App\Services\TenantCacheService::class)->invalidateTenant();
        $step = $this->readinessStep('ai.credits');
        $this->assertSame(['todo', false], [$step['status'], $step['blocking']]);
        $this->assertStringStartsWith('Il reste 1 crédit sur 7 :', $step['description'], 'accordé avec le nombre (13/11/2026)');
        $this->assertContains($step['action']['type'], ['shop', 'screen'], 'mène à l\'achat ou au changement de forfait');
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . '/admin/plateforme/ia-credits', ['_token' => $token, 'tenant' => MV_TEST_TENANT_CODE, 'monthly' => 0], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        static::getContainer()->get(\App\Services\TenantCacheService::class)->invalidateTenant();
        $this->assertStringContainsString('Aucun crédit IA', $this->readinessStep('ai.credits')['description']);
    }

    private function readinessStep(string $key): array
    {
        $container = static::getContainer();
        $user = $container->get(TenantEntityManagerProvider::class)->getEntityManager()->getRepository(User::class)->findOneBy(['email' => 'admin@arkanoa-media.com']);
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/api/site/readiness?locale=fr', [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $container->get('lexik_jwt_authentication.jwt_manager')->create($user)]);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $byKey = array_column(json_decode((string) $this->client->getResponse()->getContent(), true)['steps'], null, 'key');
        $this->assertArrayHasKey($key, $byKey);

        return $byKey[$key];
    }

    private function loginAs(array $roles): void
    {
        $container = static::getContainer();
        $provider = $container->get(TenantEntityManagerProvider::class);
        $email = 'credits-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Credits')->setLastname('Test')->setRoles($roles)->setIsVerified(true);
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, 'Mot-de-passe-de-test-1!'));
        $provider->getEntityManager()->persist($user);
        $provider->getEntityManager()->flush();
        $this->client->getCookieJar()->clear();
        $this->client->loginUser($user, 'main');
        OtpTestHelper::validateIfRequired($this->client, $user);
    }
}
