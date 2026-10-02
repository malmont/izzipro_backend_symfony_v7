<?php

namespace App\Tests\Functional\LandingPage;

use App\Controller\Admin\AiCreditSettingCrudController;
use App\Controller\Admin\AiUsageCrudController;
use App\Entity\User;
use App\Services\LandingAiService\LandingAiQuotaService;
use App\Services\TenantEntityManagerProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Écrans EasyAdmin de l'assistant IA : historique en lecture seule et crédits mensuels du tenant.
 */
class LandingAiEasyAdminTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->db()->executeStatement('DELETE FROM ai_usage');
        $this->db()->executeStatement('DELETE FROM ai_credit_setting');
    }

    public function testHistoryIsReadOnlyNewestFirstWithMonthlyCredits(): void
    {
        $this->db()->executeStatement("INSERT INTO ai_usage (tenant, user_identifier, created_at, mode, component_key, status, credits, attempts, prompt_excerpt)
            VALUES ('mvtest', 'admin@example.invalid', NOW() - INTERVAL '1 hour', 'edit', 'PresentationGroup', 'success', 1, 1, 'Demande ancienne'),
                   ('mvtest', 'admin@example.invalid', NOW(), 'edit', 'Contact', 'failed', 1, 3, 'Demande récente')");
        $this->loginAs(['ROLE_ADMIN']);

        $crawler = $this->admin(AiUsageCrudController::class);

        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $html = $this->client->getResponse()->getContent();
        $this->assertLessThan(strpos($html, 'Demande ancienne'), strpos($html, 'Demande récente'), 'plus récentes d\'abord');
        $this->assertStringContainsString('1 utilisé(s) sur 100', $html, 'les demandes échouées ne comptent pas');
        $this->assertCount(0, $crawler->filter('a.action-new, a.action-edit, a.action-delete'), 'aucune création, modification ni suppression');

        $this->admin(AiUsageCrudController::class, 'new');
        $this->assertNotSame(200, $this->client->getResponse()->getStatusCode(), 'création désactivée');
    }

    public function testEstimatedCostIsShownToTheSuperAdminOnly(): void
    {
        // 100 000 jetons d'entrée dont 80 000 écrits en cache, 10 000 lus, 5 000 en sortie, sur claude-sonnet-5-5 :
        // (20 000 × 2 + 80 000 × 2 × 1,25 + 10 000 × 0,20 + 5 000 × 10) / 1 000 000 = 0,292 $
        $this->db()->executeStatement("INSERT INTO ai_usage (tenant, created_at, mode, component_key, status, credits, attempts, model, input_tokens, cache_write_tokens, cache_read_tokens, output_tokens, prompt_excerpt)
            VALUES ('mvtest', NOW(), 'page', '', 'success', 10, 1, 'claude-sonnet-5-5', 100000, 80000, 10000, 5000, 'Page payée'),
                   ('mvtest', NOW(), 'edit', 'Contact', 'failed', 1, 1, 'claude-sonnet-5', 10000, 0, 0, 800, 'Retouche échouée après un appel'),
                   ('mvtest', NOW(), 'edit', 'Contact', 'failed', 1, 0, NULL, 0, 0, 0, 0, 'Refusée avant tout appel')");

        $this->loginAs(['ROLE_ADMIN']);
        $this->admin(AiUsageCrudController::class);
        $html = $this->client->getResponse()->getContent();
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertStringNotContainsString('Coût estimé', $html, 'l\'administrateur d\'un site voit ses crédits, pas le coût de revient');

        $this->loginAs(['ROLE_ADMIN', 'ROLE_SUPER_ADMIN']);
        $this->admin(AiUsageCrudController::class);
        $html = $this->client->getResponse()->getContent();
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertStringContainsString('Coût estimé ($ US)', $html);
        $this->assertStringContainsString('0,292', $html, 'page : écriture du cache à 1,25 fois l\'entrée');
        $this->assertStringContainsString('0,028', $html, 'retouche échouée après un appel : payée quand même');
        $this->assertStringContainsString('Coût estimé du mois sur ce site : 0,32 $ US pour 2 demande(s)', $html);
    }

    public function testMonthlyCreditsCanBeSetOnceAndUpdated(): void
    {
        $this->loginAs(['ROLE_ADMIN']);

        $crawler = $this->admin(AiCreditSettingCrudController::class);
        $this->assertCount(1, $crawler->filter('a.action-new'), 'création proposée tant qu\'aucun réglage n\'existe');

        $crawler = $this->admin(AiCreditSettingCrudController::class, 'new');
        $form = $crawler->filter('form[name="AiCreditSetting"]')->form();
        $this->client->submit($form, ['AiCreditSetting[monthlyCredits]' => '250']);
        $this->assertTrue($this->client->getResponse()->isRedirect(), $this->client->getResponse()->getContent());

        $this->assertSame(250, $this->quota()->credits()['monthly']);
        $crawler = $this->admin(AiCreditSettingCrudController::class);
        $this->assertCount(0, $crawler->filter('a.action-new'), 'une seule ligne par site');
        $this->assertCount(0, $crawler->filter('a.action-delete'), 'pas de suppression');

        $crawler = $this->admin(AiCreditSettingCrudController::class, 'new');
        $this->client->submit($crawler->filter('form[name="AiCreditSetting"]')->form(), ['AiCreditSetting[monthlyCredits]' => '300']);
        $this->assertSame(1, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM ai_credit_setting'), 'une création en trop met à jour la ligne existante');
        $this->assertSame(300, $this->quota()->credits()['monthly']);
    }

    public function testNegativeCreditsAreRefused(): void
    {
        $this->loginAs(['ROLE_ADMIN']);

        $crawler = $this->admin(AiCreditSettingCrudController::class, 'new');
        $this->client->submit($crawler->filter('form[name="AiCreditSetting"]')->form(), ['AiCreditSetting[monthlyCredits]' => '-5']);

        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
        $this->assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM ai_credit_setting'));
    }

    public function testNonAdminCannotOpenTheScreens(): void
    {
        $this->loginAs(['ROLE_USER_INTERNET']);

        $this->admin(AiUsageCrudController::class);
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->admin(AiCreditSettingCrudController::class);
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    private function admin(string $crudController, string $action = 'index'): \Symfony\Component\DomCrawler\Crawler
    {
        return $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin?' . http_build_query([
            'crudAction' => $action,
            'crudControllerFqcn' => $crudController,
        ]), [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
    }

    private function loginAs(array $roles): void
    {
        $email = 'ea-ia-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Admin')->setLastname('IA')->setRoles($roles)->setIsVerified(true)->setPassword('x');
        $em = $this->em();
        $em->persist($user);
        $em->flush();
        $this->client->loginUser($user, 'main');
    }

    private function quota(): LandingAiQuotaService
    {
        $this->em()->clear();

        return static::getContainer()->get(LandingAiQuotaService::class);
    }

    private function em()
    {
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);

        return $provider->getEntityManager();
    }

    private function db(): \Doctrine\DBAL\Connection
    {
        return $this->em()->getConnection();
    }
}
