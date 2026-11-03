<?php

namespace App\Tests\Functional\Security;

use App\Controller\Admin\EasyPostConfigurationCrudController;
use App\Controller\Admin\GooglePlacesConfigCrudController;
use App\Controller\Admin\UserCrudController;
use App\Entity\EasyPostConfiguration;
use App\Entity\GooglePlacesConfig;
use App\Entity\User;
use App\Security\RoleAssignmentPolicy;
use App\Services\TenantEntityManagerProvider;
use App\Tests\Functional\Support\OtpTestHelper;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Sous-lot 2b — Services externes et quotas IA :
 * 1. AiQuotaPageController (/admin/memoires/ai-quotas) : lecture et fuseau horaire (timezone) ouverts à ROLE_ADMIN,
 *    plafonds réservés à ROLE_SUPER_ADMIN (champs disabled + refus 403 dès qu'un plafond figure dans le POST d'un admin) ;
 * 2. GooglePlacesConfigCrudController et EasyPostConfigurationCrudController : lecture seule avec clés masquées (index et détail)
 *    pour ROLE_ADMIN, écriture réservée à ROLE_SUPER_ADMIN (403 sinon), clé existante conservée si le champ est laissé vide.
 */
class ExternalServicesAndAiQuotaGuardTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->setServerParameter('HTTP_X_TENANT_HOST', MV_TEST_TENANT_HOST);
    }

    public function testAiQuotaPageAllowsTimezoneOnlyForSiteAdminAndReservesQuotasToSuperAdmin(): void
    {
        $db = $this->db();
        $snapshot = $db->fetchAssociative('SELECT * FROM mv_ai_quota_setting ORDER BY id ASC LIMIT 1');

        try {
            $db->executeStatement('DELETE FROM mv_ai_quota_setting');
            $db->executeStatement(
                "INSERT INTO mv_ai_quota_setting (transcriptions_per_day, improvements_per_day, regenerations_per_day, max_audio_seconds, apply_to_web_books, timezone, updated_at)
                 VALUES (40, 60, 3, 300, false, 'America/Martinique', NOW())"
            );
            $this->em()->clear();

            // 1. Administrateur de site : lecture 200, plafonds disabled, timezone modifiable
            $this->loginAs(['ROLE_ADMIN']);
            $crawler = $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin/memoires/ai-quotas');
            $this->assertSame(200, $this->client->getResponse()->getStatusCode());

            foreach (['transcriptionsPerDay', 'improvementsPerDay', 'regenerationsPerDay', 'maxAudioSeconds', 'applyToWebBooks'] as $field) {
                $input = $crawler->filter(sprintf('input[name="%s"]', $field));
                $this->assertCount(1, $input, "champ $field présent");
                $this->assertNotNull($input->attr('disabled'), "champ $field verrouillé pour ROLE_ADMIN"); // attribut booléen : présent, valeur vide
            }
            $this->assertNull($crawler->filter('select[name="timezone"]')->attr('disabled'), 'timezone modifiable par ROLE_ADMIN');

            // Soumission normale par ROLE_ADMIN (seuls _token et timezone partent) -> 302, timezone mis à jour, plafonds intacts
            $form = $crawler->filter('form[action$="/admin/memoires/ai-quotas"]')->form();
            $this->client->submit($form, ['timezone' => 'Europe/Paris']);
            $this->assertSame(302, $this->client->getResponse()->getStatusCode());

            $row = $db->fetchAssociative('SELECT * FROM mv_ai_quota_setting ORDER BY id ASC LIMIT 1');
            $this->assertIsArray($row);
            $this->assertSame('Europe/Paris', $row['timezone']);
            $this->assertSame(40, (int) $row['transcriptions_per_day']);
            $this->assertSame(60, (int) $row['improvements_per_day']);
            $this->assertSame(3, (int) $row['regenerations_per_day']);
            $this->assertSame(300, (int) $row['max_audio_seconds']);
            $this->assertFalse((bool) $row['apply_to_web_books']);

            // POST forgé par ROLE_ADMIN contenant un plafond -> 403, base intacte
            $csrfToken = (string) $crawler->filter('form[action$="/admin/memoires/ai-quotas"] input[name="_token"]')->attr('value');
            $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . '/admin/memoires/ai-quotas', [
                '_token' => $csrfToken,
                'transcriptionsPerDay' => '999',
                'timezone' => 'America/Toronto',
            ]);
            $this->assertSame(403, $this->client->getResponse()->getStatusCode());
            $row = $db->fetchAssociative('SELECT * FROM mv_ai_quota_setting ORDER BY id ASC LIMIT 1');
            $this->assertSame(40, (int) $row['transcriptions_per_day']);
            $this->assertSame('Europe/Paris', $row['timezone']);

            // 2. Super administrateur : plafonds déverrouillés et modifiables
            $this->loginAs(['ROLE_ADMIN', RoleAssignmentPolicy::SUPER_ADMIN]);
            $crawler = $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin/memoires/ai-quotas');
            $this->assertSame(200, $this->client->getResponse()->getStatusCode());
            foreach (['transcriptionsPerDay', 'improvementsPerDay', 'regenerationsPerDay', 'maxAudioSeconds', 'applyToWebBooks'] as $field) {
                $this->assertNull($crawler->filter(sprintf('input[name="%s"]', $field))->attr('disabled'));
            }

            $form = $crawler->filter('form[action$="/admin/memoires/ai-quotas"]')->form();
            $this->client->submit($form, [
                'transcriptionsPerDay' => '25',
                'improvementsPerDay' => '35',
                'regenerationsPerDay' => '5',
                'maxAudioSeconds' => '600',
                'applyToWebBooks' => '1',
                'timezone' => 'America/Toronto',
            ]);
            $this->assertSame(302, $this->client->getResponse()->getStatusCode());

            $row = $db->fetchAssociative('SELECT * FROM mv_ai_quota_setting ORDER BY id ASC LIMIT 1');
            $this->assertIsArray($row);
            $this->assertSame(25, (int) $row['transcriptions_per_day']);
            $this->assertSame(35, (int) $row['improvements_per_day']);
            $this->assertSame(5, (int) $row['regenerations_per_day']);
            $this->assertSame(600, (int) $row['max_audio_seconds']);
            $this->assertTrue((bool) $row['apply_to_web_books']);
            $this->assertSame('America/Toronto', $row['timezone']);
        } finally {
            $db->executeStatement('DELETE FROM mv_ai_quota_setting');
            if (is_array($snapshot)) {
                $db->insert('mv_ai_quota_setting', $snapshot, self::types($snapshot));
            }
            $this->em()->clear();
        }
    }

    public function testGooglePlacesConfigMasksKeysAndReservesWriteActionsToSuperAdmin(): void
    {
        $em = $this->em();
        $cfg = (new GooglePlacesConfig())
            ->setGoogleApiKeyTest('AIzaSyTestSecretKey_2b_111')
            ->setGoogleApiKeyProd('AIzaSyProdSecretKey_2b_222');
        $em->persist($cfg);
        $em->flush();
        $id = (int) $cfg->getId();

        try {
            // 1. Administrateur de site : lecture index + detail 200, clés masquées, aucune action d'écriture, GET/POST d'écriture -> 403
            $this->loginAs(['ROLE_ADMIN']);
            $row = $this->indexRow(GooglePlacesConfigCrudController::class, 'google_places_config', $id);
            $indexHtml = (string) $this->client->getResponse()->getContent();
            $this->assertCount(1, $row);
            $this->assertStringContainsString('••••••••', $row->text());
            $this->assertStringNotContainsString('AIzaSyTestSecretKey_2b_111', $indexHtml, 'clé test jamais en clair dans l\'index');
            $this->assertStringNotContainsString('AIzaSyProdSecretKey_2b_222', $indexHtml, 'clé prod jamais en clair dans l\'index');
            $this->assertCount(0, $this->client->getCrawler()->filter('a.action-new, [data-action-name="new"], [data-action-name="batchDelete"]'));
            $this->assertCount(0, $row->filter('a.action-edit, [data-action-name="edit"], a.action-delete, [data-action-name="delete"]'));

            $this->crud(GooglePlacesConfigCrudController::class, 'detail', $id);
            $this->assertSame(200, $this->client->getResponse()->getStatusCode());
            $detailHtml = (string) $this->client->getResponse()->getContent();
            $this->assertStringContainsString('••••••••', $detailHtml);
            $this->assertStringNotContainsString('AIzaSyTestSecretKey_2b_111', $detailHtml, 'clé test jamais en clair dans le détail');
            $this->assertStringNotContainsString('AIzaSyProdSecretKey_2b_222', $detailHtml, 'clé prod jamais en clair dans le détail');

            foreach (['new', 'edit'] as $action) {
                $this->crud(GooglePlacesConfigCrudController::class, $action, $action === 'edit' ? $id : null);
                $this->assertSame(403, $this->client->getResponse()->getStatusCode(), "GET $action refusé à ROLE_ADMIN");
            }

            $deleteToken = $this->deleteCsrfToken();
            $this->client->request('POST', $this->crudUrl(GooglePlacesConfigCrudController::class, 'delete', $id), ['token' => $deleteToken]);
            $this->assertSame(403, $this->client->getResponse()->getStatusCode(), 'suppression forgée refusée en 403');
            $this->assertSame(1, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM google_places_config WHERE id = ?', [$id]));

            // 2. Super administrateur : modification effective, clé laissée vide conservée
            $this->loginAs(['ROLE_ADMIN', RoleAssignmentPolicy::SUPER_ADMIN]);
            $crawler = $this->crud(GooglePlacesConfigCrudController::class, 'edit', $id);
            $this->assertSame(200, $this->client->getResponse()->getStatusCode());
            $editHtml = (string) $this->client->getResponse()->getContent();
            $this->assertStringNotContainsString('AIzaSyTestSecretKey_2b_111', $editHtml);
            $this->assertStringNotContainsString('AIzaSyProdSecretKey_2b_222', $editHtml);

            // Laisse la clé test vide et change la clé prod -> la clé test est conservée
            $form = $crawler->filter('form[name="edit-GooglePlacesConfig-form"], form[name="GooglePlacesConfig"]')->form();
            $this->client->submit($form, [
                'GooglePlacesConfig[googleApiKeyTest]' => '',
                'GooglePlacesConfig[googleApiKeyProd]' => 'AIzaSyProdUpdated_2b_333',
            ]);
            $this->assertTrue($this->client->getResponse()->isRedirect(), (string) $this->client->getResponse()->getContent());

            $this->em()->clear();
            $reloaded = $this->em()->find(GooglePlacesConfig::class, $id);
            $this->assertInstanceOf(GooglePlacesConfig::class, $reloaded);
            $this->assertSame('AIzaSyTestSecretKey_2b_111', $reloaded->getGoogleApiKeyTest(), 'clé test laissée vide : conservée');
            $this->assertSame('AIzaSyProdUpdated_2b_333', $reloaded->getGoogleApiKeyProd(), 'clé prod mise à jour');

            // Laisse la clé prod vide et change la clé test -> la clé prod est conservée
            $crawler = $this->crud(GooglePlacesConfigCrudController::class, 'edit', $id);
            $form = $crawler->filter('form[name="edit-GooglePlacesConfig-form"], form[name="GooglePlacesConfig"]')->form();
            $this->client->submit($form, [
                'GooglePlacesConfig[googleApiKeyTest]' => 'AIzaSyTestUpdated_2b_444',
                'GooglePlacesConfig[googleApiKeyProd]' => '',
            ]);
            $this->assertTrue($this->client->getResponse()->isRedirect(), (string) $this->client->getResponse()->getContent());

            $this->em()->clear();
            $reloaded = $this->em()->find(GooglePlacesConfig::class, $id);
            $this->assertInstanceOf(GooglePlacesConfig::class, $reloaded);
            $this->assertSame('AIzaSyTestUpdated_2b_444', $reloaded->getGoogleApiKeyTest(), 'clé test mise à jour');
            $this->assertSame('AIzaSyProdUpdated_2b_333', $reloaded->getGoogleApiKeyProd(), 'clé prod laissée vide : conservée');
        } finally {
            $this->db()->executeStatement('DELETE FROM google_places_config WHERE id = ?', [$id]);
            $this->em()->clear();
        }
    }

    public function testEasyPostConfigurationMasksKeysAndReservesWriteActionsToSuperAdmin(): void
    {
        $em = $this->em();
        $cfg = (new EasyPostConfiguration())
            ->setEasypostApiKeySandbox('EZTK_sandbox_secret_2b_111')
            ->setEasypostApiKeyProd('EZAK_prod_secret_2b_222');
        $em->persist($cfg);
        $em->flush();
        $id = (int) $cfg->getId();

        try {
            // 1. Administrateur de site : lecture index + detail 200, clés masquées, GET/POST d'écriture -> 403
            $this->loginAs(['ROLE_ADMIN']);
            $row = $this->indexRow(EasyPostConfigurationCrudController::class, 'easy_post_configuration', $id);
            $indexHtml = (string) $this->client->getResponse()->getContent();
            $this->assertCount(1, $row);
            $this->assertStringContainsString('••••••••', $row->text());
            $this->assertStringNotContainsString('EZTK_sandbox_secret_2b_111', $indexHtml, 'clé sandbox jamais en clair dans l\'index');
            $this->assertStringNotContainsString('EZAK_prod_secret_2b_222', $indexHtml, 'clé prod jamais en clair dans l\'index');
            $this->assertCount(0, $this->client->getCrawler()->filter('a.action-new, [data-action-name="new"], [data-action-name="batchDelete"]'));
            $this->assertCount(0, $row->filter('a.action-edit, [data-action-name="edit"], a.action-delete, [data-action-name="delete"]'));

            $this->crud(EasyPostConfigurationCrudController::class, 'detail', $id);
            $this->assertSame(200, $this->client->getResponse()->getStatusCode());
            $detailHtml = (string) $this->client->getResponse()->getContent();
            $this->assertStringContainsString('••••••••', $detailHtml);
            $this->assertStringNotContainsString('EZTK_sandbox_secret_2b_111', $detailHtml, 'clé sandbox jamais en clair dans le détail');
            $this->assertStringNotContainsString('EZAK_prod_secret_2b_222', $detailHtml, 'clé prod jamais en clair dans le détail');

            foreach (['new', 'edit'] as $action) {
                $this->crud(EasyPostConfigurationCrudController::class, $action, $action === 'edit' ? $id : null);
                $this->assertSame(403, $this->client->getResponse()->getStatusCode(), "GET $action refusé à ROLE_ADMIN");
            }

            $deleteToken = $this->deleteCsrfToken();
            $this->client->request('POST', $this->crudUrl(EasyPostConfigurationCrudController::class, 'delete', $id), ['token' => $deleteToken]);
            $this->assertSame(403, $this->client->getResponse()->getStatusCode(), 'suppression forgée refusée en 403');
            $this->assertSame(1, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM easy_post_configuration WHERE id = ?', [$id]));

            // 2. Super administrateur : modification effective, clé laissée vide conservée
            $this->loginAs(['ROLE_ADMIN', RoleAssignmentPolicy::SUPER_ADMIN]);
            $crawler = $this->crud(EasyPostConfigurationCrudController::class, 'edit', $id);
            $this->assertSame(200, $this->client->getResponse()->getStatusCode());
            $editHtml = (string) $this->client->getResponse()->getContent();
            $this->assertStringNotContainsString('EZTK_sandbox_secret_2b_111', $editHtml);
            $this->assertStringNotContainsString('EZAK_prod_secret_2b_222', $editHtml);

            // Laisse la clé sandbox vide et change la clé prod -> la clé sandbox est conservée
            $form = $crawler->filter('form[name="edit-EasyPostConfiguration-form"], form[name="EasyPostConfiguration"]')->form();
            $this->client->submit($form, [
                'EasyPostConfiguration[easypostApiKeySandbox]' => '',
                'EasyPostConfiguration[easypostApiKeyProd]' => 'EZAK_prod_updated_2b_333',
            ]);
            $this->assertTrue($this->client->getResponse()->isRedirect(), (string) $this->client->getResponse()->getContent());

            $this->em()->clear();
            $reloaded = $this->em()->find(EasyPostConfiguration::class, $id);
            $this->assertInstanceOf(EasyPostConfiguration::class, $reloaded);
            $this->assertSame('EZTK_sandbox_secret_2b_111', $reloaded->getEasypostApiKeySandbox(), 'clé sandbox laissée vide : conservée');
            $this->assertSame('EZAK_prod_updated_2b_333', $reloaded->getEasypostApiKeyProd(), 'clé prod mise à jour');

            // Laisse la clé prod vide et change la clé sandbox -> la clé prod est conservée
            $crawler = $this->crud(EasyPostConfigurationCrudController::class, 'edit', $id);
            $form = $crawler->filter('form[name="edit-EasyPostConfiguration-form"], form[name="EasyPostConfiguration"]')->form();
            $this->client->submit($form, [
                'EasyPostConfiguration[easypostApiKeySandbox]' => 'EZTK_sandbox_updated_2b_444',
                'EasyPostConfiguration[easypostApiKeyProd]' => '',
            ]);
            $this->assertTrue($this->client->getResponse()->isRedirect(), (string) $this->client->getResponse()->getContent());

            $this->em()->clear();
            $reloaded = $this->em()->find(EasyPostConfiguration::class, $id);
            $this->assertInstanceOf(EasyPostConfiguration::class, $reloaded);
            $this->assertSame('EZTK_sandbox_updated_2b_444', $reloaded->getEasypostApiKeySandbox(), 'clé sandbox mise à jour');
            $this->assertSame('EZAK_prod_updated_2b_333', $reloaded->getEasypostApiKeyProd(), 'clé prod laissée vide : conservée');
        } finally {
            $this->db()->executeStatement('DELETE FROM easy_post_configuration WHERE id = ?', [$id]);
            $this->em()->clear();
        }
    }

    private function indexRow(string $controller, string $table, int $id): Crawler
    {
        $position = (int) $this->db()->fetchOne(sprintf('SELECT COUNT(*) FROM %s WHERE id <= ?', $table), [$id]);
        $crawler = $this->crud($controller, 'index', null, ['page' => intdiv($position - 1, 20) + 1]);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());

        return $crawler->filter(sprintf('tr[data-id="%d"]', $id));
    }

    private function deleteCsrfToken(): string
    {
        $crawler = $this->crud(UserCrudController::class, 'index');
        $token = (string) $crawler->filter('#delete-form input[name="token"]')->attr('value');
        $this->assertNotEmpty($token);

        return $token;
    }

    private function crud(string $controller, string $action, ?int $entityId = null, array $extra = []): Crawler
    {
        return $this->client->request('GET', $this->crudUrl($controller, $action, $entityId, $extra));
    }

    private function crudUrl(string $controller, string $action, ?int $entityId = null, array $extra = []): string
    {
        return 'https://' . MV_TEST_TENANT_HOST . '/admin?' . http_build_query(array_filter([
            'crudAction' => $action,
            'crudControllerFqcn' => $controller,
            'entityId' => $entityId,
        ]) + $extra);
    }

    private function loginAs(array $roles): User
    {
        $email = 'guard-2b-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $user = (new User())
            ->setEmail($email)
            ->setUsername($email)
            ->setFirstname('Test')
            ->setLastname('Lot2b')
            ->setRoles($roles)
            ->setIsVerified(true)
            ->setPassword('x');
        $em = $this->em();
        $em->persist($user);
        $em->flush();

        $this->client->loginUser($user, 'main');
        OtpTestHelper::validateIfRequired($this->client, $user);

        return $user;
    }

    private function em()
    {
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);

        return $provider->getEntityManager();
    }

    private function db(): Connection
    {
        return $this->em()->getConnection();
    }

    /** Types DBAL d'une ligne lue par fetchAssociative : les booléens doivent être liés comme tels (PDO pgsql lie false à '') */
    private static function types(array $row): array
    {
        return array_map(fn ($v) => is_bool($v) ? \Doctrine\DBAL\ParameterType::BOOLEAN : (is_int($v) ? \Doctrine\DBAL\ParameterType::INTEGER : \Doctrine\DBAL\ParameterType::STRING), $row);
    }
}

