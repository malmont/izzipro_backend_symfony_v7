<?php

namespace App\Tests\Functional\LandingPage;

use App\Entity\User;
use App\Services\LandingConfigService\LandingConfigStore;
use App\Services\LandingPageSettingsService\ReglableCompositionValidator;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use App\Tests\Fake\FakeFrontendConfigHttpClient;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Synchronisation de la configuration des landing pages depuis le frontend (faux frontend, dossier de test).
 */
class LandingConfigSyncTest extends WebTestCase
{
    private const TOKEN = 'jeton-de-deploiement-de-test-0123456789abcdef';
    private const PASSWORD = 'Mot-de-passe-de-test-1!';
    private const REFUSED_ANCHOR = 'ancre-refusee';

    private KernelBrowser $client;
    private ?string $originalConfiguration = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        FakeFrontendConfigHttpClient::clear();
        exec('rm -rf ' . escapeshellarg(static::getContainer()->getParameter('landing_config.dir')));
        $this->master()->exec('DELETE FROM landing_config_sync');
        $this->master()->exec("DELETE FROM tenants WHERE code = 'illisible'");
        static::getContainer()->get('limiter.landing_config_token')->create('ip:127.0.0.1')->reset();
        $this->originalConfiguration = $this->db()->fetchOne('SELECT configuration FROM landing_page_setting ORDER BY id LIMIT 1') ?: null;
    }

    protected function tearDown(): void
    {
        if ($this->originalConfiguration !== null) {
            $this->db()->executeStatement('UPDATE landing_page_setting SET configuration = ? WHERE id = (SELECT MIN(id) FROM landing_page_setting)', [$this->originalConfiguration]);
        }
        exec('rm -rf ' . escapeshellarg(static::getContainer()->getParameter('landing_config.dir')));
        parent::tearDown();
    }

    public function testSyncActivatesTheNewVersionAndKeepsThePreviousOne(): void
    {
        FakeFrontendConfigHttpClient::publish('2026.10.01-1', [LandingConfigStore::SCHEMA => $this->schemaVariant(fn ($s) => $s->{'$comment'} = 'version de test')]);
        $this->assertFalse($this->status()->upToDate);
        $this->assertSame('bundled', $this->status()->active->version);

        $response = $this->call('POST', '/api/landingpage-config/sync', self::TOKEN, json_encode(['files' => ['landingpage-reglable.schema.json' => '{}']]));

        $this->assertSame(200, $response->getStatusCode(), $response->getContent());
        $body = json_decode($response->getContent());
        $this->assertSame('activated', $body->result);
        $this->assertSame('2026.10.01-1', $body->active->version);
        $this->assertSame('bundled', $body->previous->version);
        $this->assertGreaterThan(0, $body->checked, 'compositions et modèles contrôlés');
        $this->assertSame(['GET ' . FakeFrontendConfigHttpClient::BASE_URL . 'manifest.json'], array_slice(FakeFrontendConfigHttpClient::$requested, 0, 1), 'contenu lu chez le frontend, jamais dans la requête');

        $store = static::getContainer()->get(LandingConfigStore::class);
        $this->assertStringContainsString('/versions/2026.10.01-1-', $store->path(LandingConfigStore::SCHEMA));
        $this->assertSame(FakeFrontendConfigHttpClient::$published[LandingConfigStore::SCHEMA], file_get_contents($store->path(LandingConfigStore::SCHEMA)));
        $status = $this->status();
        $this->assertTrue($status->upToDate);
        $this->assertSame(['sync', '2026.10.01-1', 'jeton de déploiement', 'activated'], [$status->history[0]->action, $status->history[0]->version, $status->history[0]->author, $status->history[0]->result]);

        $again = json_decode($this->call('POST', '/api/landingpage-config/sync', self::TOKEN)->getContent());
        $this->assertSame('up_to_date', $again->result);
    }

    public function testEditorLabelsAreSyncedAsAnOptionalFile(): void
    {
        $store = static::getContainer()->get(LandingConfigStore::class);
        $system = fn () => static::getContainer()->get(\App\Services\LandingAiService\LandingAiPromptBuilder::class)
            ->createPayload('claude-sonnet-5', 'Contact', 'Une section contact.', 'fr', [], [], ['colors' => [], 'fonts' => []], false, false, [], null)['system'][0]['text'];
        $bundled = $store->path(LandingConfigStore::EDITOR_LABELS);
        $this->assertStringContainsString('Section → « 🗂 Calques »', $system(), 'copie du dépôt sans version installée');

        // manifeste sans le fichier facultatif : accepté, la copie du dépôt sert
        FakeFrontendConfigHttpClient::publish('2026.10.07-1');
        $this->assertSame(200, $this->call('POST', '/api/landingpage-config/sync', self::TOKEN)->getStatusCode());
        $this->assertSame($bundled, $store->path(LandingConfigStore::EDITOR_LABELS));

        // publié par le frontend : version suivante, lue par l'assistant sans redémarrage
        FakeFrontendConfigHttpClient::publish('2026.10.07-2', [LandingConfigStore::EDITOR_LABELS => "Ajouter une section : Panneau → « Nouveau libellé »\n"]);
        $response = $this->call('POST', '/api/landingpage-config/sync', self::TOKEN);
        $this->assertSame(200, $response->getStatusCode(), $response->getContent());
        $this->assertSame('activated', json_decode($response->getContent())->result);
        $this->assertStringContainsString('/versions/2026.10.07-2-', $store->path(LandingConfigStore::EDITOR_LABELS));
        $this->assertStringContainsString('Panneau → « Nouveau libellé »', $system());
        $this->assertStringNotContainsString('🗂 Calques', $system());
        $this->assertTrue($this->status()->upToDate);

        // trop long (lu à chaque demande) : rien n'est activé
        FakeFrontendConfigHttpClient::publish('2026.10.07-3', [LandingConfigStore::EDITOR_LABELS => str_repeat('x', 40001)]);
        $response = $this->call('POST', '/api/landingpage-config/sync', self::TOKEN);
        $this->assertSame(422, $response->getStatusCode(), $response->getContent());
        $this->assertSame(LandingConfigStore::EDITOR_LABELS, json_decode($response->getContent())->errors[0]->path);
        $this->assertStringContainsString('/versions/2026.10.07-2-', $store->path(LandingConfigStore::EDITOR_LABELS));
    }

    public function testNewSchemaIsUsedByTheValidatorWithoutRestart(): void
    {
        $validator = static::getContainer()->get(ReglableCompositionValidator::class);
        $composition = (object) ['schemaVersion' => 2, 'anchor' => self::REFUSED_ANCHOR, 'blocks' => []];
        $this->assertSame([], $validator->validateComposition($composition));

        FakeFrontendConfigHttpClient::publish('2026.10.01-2', [LandingConfigStore::SCHEMA => $this->refusingSchema()]);
        $this->assertSame(200, $this->call('POST', '/api/landingpage-config/sync', self::TOKEN)->getStatusCode());

        $this->assertNotSame([], $validator->validateComposition($composition), 'même instance, nouvelle version lue');
    }

    public function testWrongHashActivatesNothing(): void
    {
        FakeFrontendConfigHttpClient::publish('2026.10.01-3', [], [LandingConfigStore::CATALOGUE => str_repeat('a', 64)]);

        $response = $this->call('POST', '/api/landingpage-config/sync', self::TOKEN);

        $this->assertSame(422, $response->getStatusCode(), $response->getContent());
        $body = json_decode($response->getContent());
        $this->assertSame('Empreinte invalide', $body->error);
        $this->assertSame('files.' . LandingConfigStore::CATALOGUE, $body->errors[0]->path);
        $this->assertSame('bundled', $this->status()->active->version);
        $this->assertSame('rejected_hash', $this->status()->history[0]->result);
    }

    public function testInvalidManifestIsRejected(): void
    {
        FakeFrontendConfigHttpClient::publish('../../etc', []);

        $response = $this->call('POST', '/api/landingpage-config/sync', self::TOKEN);

        $this->assertSame(422, $response->getStatusCode(), $response->getContent());
        $this->assertSame('version', json_decode($response->getContent())->errors[0]->path);
        $this->assertSame('bundled', $this->status()->active->version);
    }

    public function testRefusedCompositionGives409AndActivatesNothing(): void
    {
        $this->storeCompositionWithAnchor(self::REFUSED_ANCHOR);
        FakeFrontendConfigHttpClient::publish('2026.10.01-4', [LandingConfigStore::SCHEMA => $this->refusingSchema()]);

        $response = $this->call('POST', '/api/landingpage-config/sync', self::TOKEN);

        $this->assertSame(409, $response->getStatusCode(), $response->getContent());
        $body = json_decode($response->getContent());
        $this->assertSame('Compositions refusées', $body->error);
        $this->assertSame(1, $body->refusedCount);
        $this->assertSame([MV_TEST_TENANT_CODE, 'navbar.reglableConfig.anchor'], [$body->errors[0]->tenants, $body->errors[0]->path]);
        $this->assertSame('bundled', $this->status()->active->version, 'rien n\'est activé');
        $this->assertSame('rejected_compositions', $this->status()->history[0]->result);
        $this->assertSame([], glob(static::getContainer()->getParameter('landing_config.dir') . '/versions/2026.10.01-4-*'), 'version refusée supprimée');
    }

    public function testRollbackRestoresThePreviousVersion(): void
    {
        $this->assertSame(409, $this->call('POST', '/api/landingpage-config/rollback', self::TOKEN)->getStatusCode(), 'aucune version précédente');

        FakeFrontendConfigHttpClient::publish('v1', [LandingConfigStore::SCHEMA => $this->schemaVariant(fn ($s) => $s->{'$comment'} = 'v1')]);
        $this->call('POST', '/api/landingpage-config/sync', self::TOKEN);
        FakeFrontendConfigHttpClient::publish('v2', [LandingConfigStore::SCHEMA => $this->schemaVariant(fn ($s) => $s->{'$comment'} = 'v2')]);
        $this->call('POST', '/api/landingpage-config/sync', self::TOKEN);
        $this->assertSame(['v2', 'v1'], [$this->status()->active->version, $this->status()->previous->version]);

        $response = $this->call('POST', '/api/landingpage-config/rollback', self::TOKEN);

        $this->assertSame(200, $response->getStatusCode(), $response->getContent());
        $this->assertSame(['rolled_back', 'v1', 'v2'], [json_decode($response->getContent())->result, $this->status()->active->version, $this->status()->previous->version]);
        $store = static::getContainer()->get(LandingConfigStore::class);
        $this->assertStringContainsString('"v1"', file_get_contents($store->path(LandingConfigStore::SCHEMA)));
        $this->assertSame('rolled_back', $this->status()->history[0]->result);

        $this->call('POST', '/api/landingpage-config/rollback', self::TOKEN);
        $this->assertSame('v2', $this->status()->active->version, 'un second retour revient à la version quittée');
    }

    public function testRollbackIsRefusedWhenAStoredCompositionWouldBeRefused(): void
    {
        FakeFrontendConfigHttpClient::publish('stricte', [LandingConfigStore::SCHEMA => $this->refusingSchema()]);
        $this->call('POST', '/api/landingpage-config/sync', self::TOKEN);
        FakeFrontendConfigHttpClient::publish('souple', [LandingConfigStore::SCHEMA => $this->schemaVariant(fn ($s) => $s->{'$comment'} = 'souple')]);
        $this->call('POST', '/api/landingpage-config/sync', self::TOKEN);
        $this->storeCompositionWithAnchor(self::REFUSED_ANCHOR);

        $response = $this->call('POST', '/api/landingpage-config/rollback', self::TOKEN);

        $this->assertSame(409, $response->getStatusCode(), $response->getContent());
        $this->assertSame('souple', $this->status()->active->version);
    }

    public function testUnreadableTenantDatabaseBlocksTheSync(): void
    {
        $this->master()->exec("INSERT INTO tenants (code, name, dbname) VALUES ('illisible', 'Base absente', 'db_qui_n_existe_pas')");
        FakeFrontendConfigHttpClient::publish('2026.10.02-1', [LandingConfigStore::SCHEMA => $this->schemaVariant(fn ($s) => $s->{'$comment'} = 'x')]);

        try {
            $response = $this->call('POST', '/api/landingpage-config/sync', self::TOKEN);
        } finally {
            $this->master()->exec("DELETE FROM tenants WHERE code = 'illisible'");
        }

        $this->assertSame(409, $response->getStatusCode(), $response->getContent());
        $body = json_decode($response->getContent());
        $this->assertSame('Bases illisibles', $body->error);
        $this->assertSame('db_qui_n_existe_pas', $body->errors[0]->path);
        $this->assertSame('bundled', $this->status()->active->version, 'rien n\'est activé');
        $this->assertSame('rejected_unreadable', $this->status()->history[0]->result);
    }

    public function testRepeatedInvalidTokensAreRateLimited(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->assertSame(401, $this->call('GET', '/api/landingpage-config/status', 'mauvais-jeton-' . $i)->getStatusCode());
        }

        $response = $this->call('GET', '/api/landingpage-config/status', self::TOKEN);

        $this->assertSame(429, $response->getStatusCode(), 'même le bon jeton est refusé pendant la pénalité');
        $this->assertGreaterThan(0, (int) $response->headers->get('Retry-After'));
    }

    public function testAccessIsReservedToThePlatformOwner(): void
    {
        FakeFrontendConfigHttpClient::publish('2026.10.01-5');

        $this->assertSame(401, $this->call('GET', '/api/landingpage-config/status')->getStatusCode(), 'anonyme');
        $this->assertSame(401, $this->call('POST', '/api/landingpage-config/sync', 'mauvais-jeton')->getStatusCode(), 'mauvais jeton');
        $admin = $this->login(['ROLE_ADMIN', 'ROLE_USER_INTERNET']);
        $this->assertSame(403, $this->call('POST', '/api/landingpage-config/sync', null, null, $admin)->getStatusCode(), 'administrateur d\'un tenant');
        $this->assertSame(403, $this->call('POST', '/api/landingpage-config/rollback', null, null, $admin)->getStatusCode());
        $this->assertSame('bundled', $this->status()->active->version);

        $owner = $this->login(['ROLE_SUPER_ADMIN', 'ROLE_ADMIN', 'ROLE_USER_INTERNET']);
        $this->assertSame(200, $this->call('GET', '/api/landingpage-config/status', null, null, $owner)->getStatusCode());
        $response = $this->call('POST', '/api/landingpage-config/sync', null, null, $owner);
        $this->assertSame(200, $response->getStatusCode(), $response->getContent());
        $this->assertStringEndsWith('(' . MV_TEST_TENANT_CODE . ')', $this->status()->history[0]->author);
    }

    /** Schéma du dépôt modifié par $change */
    private function schemaVariant(callable $change): string
    {
        $schema = json_decode(file_get_contents(dirname(__DIR__, 3) . '/config/landingpage/' . LandingConfigStore::SCHEMA));
        $change($schema);

        return json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }

    /** Schéma qui refuse l'ancre REFUSED_ANCHOR (aucun modèle du catalogue ne l'utilise) */
    private function refusingSchema(): string
    {
        return $this->schemaVariant(fn ($s) => $s->properties->anchor->not = (object) ['const' => self::REFUSED_ANCHOR]);
    }

    private function storeCompositionWithAnchor(string $anchor): void
    {
        $configuration = json_decode((string) $this->originalConfiguration) ?: new \stdClass();
        $configuration->navbar = (object) ['reglableConfig' => (object) ['schemaVersion' => 2, 'anchor' => $anchor, 'blocks' => []]];
        $this->db()->executeStatement('UPDATE landing_page_setting SET configuration = ? WHERE id = (SELECT MIN(id) FROM landing_page_setting)', [json_encode($configuration)]);
    }

    private function status(): object
    {
        $response = $this->call('GET', '/api/landingpage-config/status', self::TOKEN);
        $this->assertSame(200, $response->getStatusCode(), $response->getContent());

        return json_decode($response->getContent());
    }

    private function call(string $method, string $path, ?string $token = null, ?string $body = null, array $session = []): Response
    {
        $jar = $this->client->getCookieJar();
        $jar->clear();
        foreach ($session as $name => $value) {
            $jar->set(new \Symfony\Component\BrowserKit\Cookie($name, $value, null, '/', MV_TEST_TENANT_HOST, true));
        }
        $this->client->request($method, 'https://' . MV_TEST_TENANT_HOST . $path, [], [], array_filter([
            'HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST,
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_DEPLOY_TOKEN' => $token,
            'HTTP_X_XSRF_TOKEN' => $session['XSRF-TOKEN_' . MV_TEST_TENANT_CODE] ?? null,
        ]), $body);

        return $this->client->getResponse();
    }

    private function login(array $roles): array
    {
        $email = 'landing-config-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $container = static::getContainer();
        $provider = $container->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Config')->setLastname('Sync')->setRoles($roles)->setIsVerified(true);
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD));
        $provider->getEntityManager()->persist($user);
        $provider->getEntityManager()->flush();

        $this->client->getCookieJar()->clear();
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . '/api/login', [], [], [
            'HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
        ], json_encode(['username' => $email, 'password' => self::PASSWORD, 'platform' => 'web']));
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());
        $session = [];
        foreach ($this->client->getResponse()->headers->getCookies() as $cookie) {
            $session[$cookie->getName()] = $cookie->getValue();
        }

        return $session;
    }

    private function db(): \Doctrine\DBAL\Connection
    {
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);

        return $provider->getEntityManager()->getConnection();
    }

    private function master(): \PDO
    {
        return static::getContainer()->get(TenantConnectionManager::class)->getPdoMaster();
    }
}
