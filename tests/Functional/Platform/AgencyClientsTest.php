<?php

namespace App\Tests\Functional\Platform;

use App\Entity\User;
use App\Services\Agency\AgencyClientsService;
use App\Services\Agency\AgencyService;
use App\Services\Platform\TenantDatabaseConnector;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lot AG-3d (18/11/2026, contrat validé par le chef) :
 * - « Tous mes clients » (`GET /api/agency/clients`) : un tableau technique des sites gérés, sans aucun chiffre ni destinataire (liste blanche des
 *   clés, sondée) ; lisible par une agence suspendue, sans « Entrer » ;
 * - « Taxes vérifiées par le commerçant » : posée par un administrateur DU site seulement (refusée au compte de la plateforme et à une agence
 *   entrée), elle tombe à TOUTE écriture d'une taxe (requête directe, Doctrine), et la liste d'ouverture le dit sans bloquer.
 */
class AgencyClientsTest extends WebTestCase
{
    private KernelBrowser $client;
    private \PDO $master;
    private string $tag;
    /** @var array<string, mixed> */
    private array $sheet = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->master = static::getContainer()->get(TenantConnectionManager::class)->getPdoMaster();
        $this->tag = bin2hex(random_bytes(3));
        $this->clean();
        $this->sheet = $this->pdo(MV_TEST_TENANT2_DB)->query('SELECT id, is_boutique_active, taxes_verified_at, taxes_verified_by FROM entreprise ORDER BY id LIMIT 1')->fetch(\PDO::FETCH_ASSOC) ?: [];
    }

    protected function tearDown(): void
    {
        if ($this->sheet !== []) {
            $this->pdo(MV_TEST_TENANT2_DB)->prepare('UPDATE entreprise SET is_boutique_active = ?, taxes_verified_at = ?, taxes_verified_by = ? WHERE id = ?')
                ->execute([in_array($this->sheet['is_boutique_active'], [true, 't', 1, '1'], true) ? 't' : 'f', $this->sheet['taxes_verified_at'], $this->sheet['taxes_verified_by'], $this->sheet['id']]);
        }
        $this->pdo(MV_TEST_TENANT2_DB)->exec("DELETE FROM tax WHERE name LIKE 'Taxe ag3d %'");
        $this->clean();
        parent::tearDown();
    }

    public function testAllMyClientsIsATechnicalTableWithoutAnyFigureOrRecipient(): void
    {
        $agencies = static::getContainer()->get(AgencyService::class);
        $agency = (int) $agencies->create(['code' => 'agence-ag3d-' . $this->tag, 'name' => 'Atelier Clients', 'homeTenantCode' => MV_TEST_TENANT_CODE, 'test' => true], 'essai')['id'];
        $agencies->attach($agency, MV_TEST_TENANT2_CODE, 'essai');
        $token = $this->token(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE, 'agence', ['ROLE_ADMIN', 'ROLE_AGENCY']);

        $started = microtime(true);
        $response = $this->call('GET', '/api/agency/clients', $token, MV_TEST_TENANT_HOST);
        $elapsed = (int) round((microtime(true) - $started) * 1000);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $body = json_decode((string) $response->getContent(), true);
        $this->assertTrue($body['canEnter']);
        $this->assertCount(1, $body['clients']);
        $row = $body['clients'][0];
        $this->assertSame(MV_TEST_TENANT2_CODE, $row['code']);
        $this->assertSame([], array_diff(array_keys($row), AgencyClientsService::KEYS), 'liste blanche des clés');
        $this->assertSame([], array_values(array_diff(AgencyClientsService::KEYS, array_keys($row))), 'toutes les colonnes du contrat');
        $json = (string) $response->getContent();
        $this->assertStringNotContainsString('@', $json, 'ni adresse, ni courriel');
        foreach (['revenue', 'totalAmount', 'orders', 'customers', 'reviews', 'amount', 'balance', 'Cents', 'sales'] as $figure) {
            $this->assertStringNotContainsStringIgnoringCase($figure, $json, 'aucun chiffre : ' . $figure);
        }
        $this->assertSame(['blocking', 'improvements', 'canOpen'], array_keys($row['checklist']));
        fwrite(\STDERR, "\n[AG-3d] « Tous mes clients » : 1 site en {$elapsed} ms (première lecture, sans cache)\n");

        // agence suspendue : lisible, sans « Entrer »
        $this->master->exec("UPDATE agencies SET status = 'suspended' WHERE id = $agency");
        $suspended = json_decode((string) $this->call('GET', '/api/agency/clients', $token, MV_TEST_TENANT_HOST)->getContent(), true);
        $this->assertSame([false, 1], [$suspended['canEnter'], count($suspended['clients'])]);
    }

    public function testOnlyTheMerchantVerifiesTheTaxesAndAnyTaxWriteMakesTheVerificationFall(): void
    {
        $this->pdo(MV_TEST_TENANT2_DB)->exec('UPDATE entreprise SET is_boutique_active = TRUE, taxes_verified_at = NULL, taxes_verified_by = NULL');
        $merchant = $this->token(MV_TEST_TENANT2_DB, MV_TEST_TENANT2_CODE, 'commercant', ['ROLE_ADMIN'], 'Claire');
        $this->assertSame(['taxes', false, false], $this->taxLine($merchant), 'à vérifier : la ligne avertit sans bloquer');

        // le compte de la plateforme ne vérifie pas à la place du commerçant
        $platform = $this->token(MV_TEST_TENANT2_DB, MV_TEST_TENANT2_CODE, 'plateforme', ['ROLE_ADMIN', 'ROLE_SUPER_ADMIN'], 'Plateforme');
        $this->assertSame([403, 'TAXES_VERIFY_FORBIDDEN'], $this->refusal('POST', '/api/site/taxes/verify', $platform));

        $verified = $this->call('POST', '/api/site/taxes/verify', $merchant, MV_TEST_TENANT2_HOST);
        $this->assertSame(200, $verified->getStatusCode(), (string) $verified->getContent());
        $this->assertSame([true, 'Claire'], [json_decode((string) $verified->getContent(), true)['taxes']['verified'], json_decode((string) $verified->getContent(), true)['taxes']['by']]);
        $this->assertSame(['taxes', true, false], $this->taxLine($merchant));
        $this->assertSame(1, (int) $this->pdo(MV_TEST_TENANT2_DB)->query("SELECT COUNT(*) FROM content_audit_log WHERE resource = 'taxes' AND action = 'verify'")->fetchColumn(), 'écrite au journal du site');

        // une taxe écrite par une requête directe : la vérification tombe
        $this->pdo(MV_TEST_TENANT2_DB)->exec("INSERT INTO tax (id, name, rate, type, country) VALUES (nextval('tax_id_seq'), 'Taxe ag3d directe', 5, 'percentage', 'CA')");
        $this->assertNull($this->pdo(MV_TEST_TENANT2_DB)->query('SELECT taxes_verified_at FROM entreprise ORDER BY id LIMIT 1')->fetchColumn());

        // … et par Doctrine (le chemin d'EasyAdmin et des services)
        $this->call('POST', '/api/site/taxes/verify', $merchant, MV_TEST_TENANT2_HOST);
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT2_DB, MV_TEST_TENANT2_CODE);
        $em = $provider->getEntityManager();
        $tax = $em->getRepository(\App\Entity\Tax::class)->findOneBy(['name' => 'Taxe ag3d directe']);
        $this->assertNotNull($tax);
        $tax->setName('Taxe ag3d modifiée');
        $em->flush();
        $em->clear();
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $this->assertNull($this->pdo(MV_TEST_TENANT2_DB)->query('SELECT taxes_verified_at FROM entreprise ORDER BY id LIMIT 1')->fetchColumn(), 'une taxe modifiée redemande la vérification');
        $this->assertSame(['taxes', false, false], $this->taxLine($merchant));
    }

    /** @return array{0: string, 1: bool, 2: bool} clé, ok, bloquante de la ligne des taxes de la liste d'ouverture */
    private function taxLine(string $token): array
    {
        $list = json_decode((string) $this->call('GET', '/api/site/storefront/checklist', $token, MV_TEST_TENANT2_HOST)->getContent(), true);
        $line = array_values(array_filter($list['items'] ?? [], static fn (array $i): bool => $i['key'] === 'taxes'))[0] ?? ['key' => 'absente', 'ok' => null, 'blocking' => null];

        return [$line['key'], $line['ok'], $line['blocking']];
    }

    /** @return array{0: int, 1: ?string} */
    private function refusal(string $method, string $path, string $token): array
    {
        $response = $this->call($method, $path, $token, MV_TEST_TENANT2_HOST);

        return [$response->getStatusCode(), json_decode((string) $response->getContent(), true)['code'] ?? null];
    }

    private function call(string $method, string $path, string $token, string $host): Response
    {
        $this->client->getCookieJar()->clear();
        $this->client->request($method, 'https://' . $host . $path, [], [], ['HTTP_X_TENANT_HOST' => $host, 'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'REMOTE_ADDR' => '10.89.' . random_int(1, 254) . '.' . random_int(1, 254)], $method === 'POST' ? '{}' : null);

        return $this->client->getResponse();
    }

    /** @param list<string> $roles */
    private function token(string $db, string $code, string $name, array $roles, string $firstName = 'Julie'): string
    {
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant($db, $code);
        $em = $provider->getEntityManager();
        $email = "ag3d-$name-{$this->tag}@example.invalid";
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname($firstName)->setLastname('Essai')->setRoles($roles)->setPassword('inutilisable')->setIsVerified(true);
        $em->persist($user);
        $em->flush();
        $token = static::getContainer()->get('lexik_jwt_authentication.jwt_manager')->create($user);
        $em->clear();
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);

        return $token;
    }

    private function clean(): void
    {
        $this->master->exec('DELETE FROM assistance_grants');
        $this->master->exec('DELETE FROM site_entries');
        foreach (['agency_events', 'agency_sites'] as $table) {
            $this->master->exec("DELETE FROM $table");
        }
        $this->master->exec('DELETE FROM agency_plans WHERE agency_id IS NOT NULL');
        $this->master->exec('DELETE FROM agencies');
        foreach ([MV_TEST_TENANT_DB, MV_TEST_TENANT2_DB] as $db) {
            $this->pdo($db)->exec("DELETE FROM \"user\" WHERE email LIKE 'ag3d-%@example.invalid'");
            $this->pdo($db)->exec("DELETE FROM content_audit_log WHERE resource = 'taxes'");
        }
    }

    private function pdo(string $db): \PDO
    {
        return static::getContainer()->get(TenantDatabaseConnector::class)->pdo($db);
    }
}
