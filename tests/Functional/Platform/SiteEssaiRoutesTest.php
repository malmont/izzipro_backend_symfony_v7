<?php

namespace App\Tests\Functional\Platform;

use App\Entity\User;
use App\Services\Platform\Essai\EssaiJobStore;
use App\Services\Platform\Essai\Message\RunEssaiMessage;
use App\Services\Platform\Essai\MessageHandler\RunEssaiHandler;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Lot ESSAI-DONNÉES, bouton du web (17/11/2026) : `POST /api/site/essai/remplir` met le remplissage en file sur le worker `media` pour un
 * site d'essai seulement (422 `ESSAI_NOT_A_TEST_SITE` ailleurs), une exécution à la fois (409 `ESSAI_RUNNING`) ; `GET` rend l'état et le
 * compte rendu. Administrateur du site seulement. Le gestionnaire note l'échec dans l'état, sans relance.
 */
class SiteEssaiRoutesTest extends WebTestCase
{
    private KernelBrowser $client;
    private \PDO $master;
    private string $tag;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->master = static::getContainer()->get(TenantConnectionManager::class)->getPdoMaster();
        $this->tag = bin2hex(random_bytes(3));
        $this->forget();
    }

    protected function tearDown(): void
    {
        $this->forget();
        $this->master->exec("DELETE FROM agency_sites WHERE agency_id IN (SELECT id FROM agencies WHERE code LIKE 'essai-routes-%')");
        $this->master->exec("DELETE FROM agencies WHERE code LIKE 'essai-routes-%'");
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT2_DB, MV_TEST_TENANT2_CODE);
        $db = $provider->getEntityManager()->getConnection();
        $db->executeStatement("DELETE FROM refresh_tokens WHERE username LIKE 'essai-routes-%@example.invalid'");
        $db->executeStatement("DELETE FROM \"user\" WHERE email LIKE 'essai-routes-%@example.invalid'");
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        parent::tearDown();
    }

    public function testOnlyATestSiteIsFilledOneRunAtATimeAndTheStateIsRead(): void
    {
        $admin = $this->account('admin', ['ROLE_ADMIN']);
        $member = $this->account('membre', ['ROLE_USER']);

        // le second site de test n'est ni demo ni le site d'une agence de test : refusé, rien en file
        $this->assertSame([422, 'ESSAI_NOT_A_TEST_SITE'], $this->refusal('POST', $admin));
        $this->assertCount(0, $this->transport()->getSent());
        $state = $this->json('GET', $admin);
        $this->assertSame([false, null], [$state['allowed'], $state['job']]);
        $this->assertSame(403, $this->call('POST', $member)->getStatusCode(), 'administrateur seulement');
        $this->assertSame(401, $this->call('POST', null)->getStatusCode());

        // le même site, géré par une agence de test : en file sur media, une seule fois
        $agency = (int) $this->master->query("INSERT INTO agencies (code, name, home_tenant_code, status, max_sites, is_test) VALUES ('essai-routes-{$this->tag}', 'Agence de test', 'agfict-essai-{$this->tag}', 'trial', 3, TRUE) RETURNING id")->fetchColumn();
        $this->master->prepare('INSERT INTO agency_sites (agency_id, tenant_code, origin) VALUES (?, ?, ?)')->execute([$agency, MV_TEST_TENANT2_CODE, 'attached']);
        $response = $this->call('POST', $admin);
        $this->assertSame(202, $response->getStatusCode(), (string) $response->getContent());
        $this->assertSame(['queued', 'Admin'], [json_decode((string) $response->getContent(), true)['job']['state'], json_decode((string) $response->getContent(), true)['job']['requestedBy']]);
        $sent = $this->transport()->getSent();
        $this->assertCount(1, $sent);
        $this->assertInstanceOf(RunEssaiMessage::class, $sent[0]->getMessage());
        $this->assertSame(MV_TEST_TENANT2_CODE, $sent[0]->getMessage()->tenantCode);

        $state = $this->json('GET', $admin);
        $this->assertSame([true, 'queued'], [$state['allowed'], $state['job']['state']]);
        $this->assertSame([409, 'ESSAI_RUNNING'], $this->refusal('POST', $admin));
        $this->assertCount(0, $this->transport()->getSent(), 'rien de relancé');

        // une exécution perdue (worker arrêté depuis plus d'une heure) ne bloque plus
        $store = static::getContainer()->get(EssaiJobStore::class);
        $store->put(MV_TEST_TENANT2_CODE, ['since' => time() - EssaiJobStore::STALE_AFTER - 1] + $store->get(MV_TEST_TENANT2_CODE));
        $this->assertSame(202, $this->call('POST', $admin)->getStatusCode());
    }

    public function testTheHandlerWritesTheFailureInTheStateAndNeverThrows(): void
    {
        $store = static::getContainer()->get(EssaiJobStore::class);
        $store->put(MV_TEST_TENANT2_CODE, ['state' => 'queued', 'since' => time(), 'requestedAt' => gmdate(\DATE_ATOM), 'requestedBy' => 'Admin', 'report' => [], 'error' => null]);
        // l'agence de test a disparu entre la demande et l'exécution : le verrou est relu, rien n'est écrit
        (static::getContainer()->get(RunEssaiHandler::class))(new RunEssaiMessage(MV_TEST_TENANT2_CODE, 'Admin'));
        $job = $store->get(MV_TEST_TENANT2_CODE);
        $this->assertSame(['failed', 'Admin'], [$job['state'], $job['requestedBy']]);
        $this->assertStringContainsString('refusé', (string) $job['error']);
        $this->assertNotNull($job['finishedAt']);
        $this->assertFalse($store->busy(MV_TEST_TENANT2_CODE));
    }

    private function forget(): void
    {
        $cache = static::getContainer()->get('cache.app');
        $cache->delete('essai_job_' . MV_TEST_TENANT2_CODE);
    }

    /** @return array{0: int, 1: ?string} */
    private function refusal(string $method, ?string $token): array
    {
        $response = $this->call($method, $token);

        return [$response->getStatusCode(), json_decode((string) $response->getContent(), true)['code'] ?? null];
    }

    /** @return array<string, mixed> */
    private function json(string $method, ?string $token): array
    {
        $response = $this->call($method, $token);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        return json_decode((string) $response->getContent(), true);
    }

    private function call(string $method, ?string $token): Response
    {
        $this->client->getCookieJar()->clear();
        $server = ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT2_HOST, 'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => '10.88.' . random_int(1, 254) . '.' . random_int(1, 254)];
        if ($token !== null) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
        }
        $this->client->request($method, 'https://' . MV_TEST_TENANT2_HOST . '/api/site/essai/remplir', [], [], $server, $method === 'POST' ? '{}' : null);

        return $this->client->getResponse();
    }

    private function transport(): InMemoryTransport
    {
        return $this->client->getContainer()->get('messenger.transport.media');
    }

    private function account(string $name, array $roles): string
    {
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT2_DB, MV_TEST_TENANT2_CODE);
        $em = $provider->getEntityManager();
        $email = "essai-routes-$name-{$this->tag}@example.invalid";
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname(ucfirst($name))->setLastname('Essai')->setRoles($roles)->setPassword('inutilisable')->setIsVerified(true);
        $em->persist($user);
        $em->flush();
        $em->getConnection()->executeStatement("INSERT INTO refresh_tokens (id, refresh_token, username, valid) VALUES (nextval('refresh_tokens_id_seq'), ?, ?, NOW() + INTERVAL '1 day')", ['essai-routes-' . bin2hex(random_bytes(30)), $email]);
        $token = static::getContainer()->get('lexik_jwt_authentication.jwt_manager')->create($user);
        $em->clear();
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);

        return $token;
    }
}
