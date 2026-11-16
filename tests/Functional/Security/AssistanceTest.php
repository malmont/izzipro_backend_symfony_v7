<?php

namespace App\Tests\Functional\Security;

use App\Entity\User;
use App\Services\Agency\AgencyService;
use App\Services\FeatureAccess\FeatureCatalog;
use App\Services\Platform\TenantDatabaseConnector;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lot AG-ASSIST (16/11/2026) : L'ACCÈS D'ASSISTANCE D'UNE AGENCE. Le COMMERÇANT ouvre une section fermée à l'agence qui gère son site,
 * en consultation seule ou en consultation et modification, pour une heure à partir de la première requête de l'opérateur ; il la ferme
 * à tout moment ; chaque écran consulté est tracé. L'agence ne peut ni la demander ni la prolonger.
 *
 * Le premier site de test est l'attache de l'agence ; elle gère le second (le site du commerçant).
 */
class AssistanceTest extends WebTestCase
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
        $this->sheet = $this->pdo(MV_TEST_TENANT2_DB)->query('SELECT id, ' . implode(', ', FeatureCatalog::GOVERNED) . ' FROM entreprise ORDER BY id LIMIT 1')->fetch(\PDO::FETCH_ASSOC) ?: [];
        foreach (FeatureCatalog::GOVERNED as $column) {
            $this->pdo(MV_TEST_TENANT2_DB)->exec("UPDATE entreprise SET $column = true");
        }
    }

    protected function tearDown(): void
    {
        if ($this->sheet !== []) {
            $stmt = $this->pdo(MV_TEST_TENANT2_DB)->prepare('UPDATE entreprise SET ' . implode(', ', array_map(static fn (string $c): string => "$c = :$c", FeatureCatalog::GOVERNED)) . ' WHERE id = :id');
            foreach ($this->sheet as $column => $value) {
                $column === 'id' ? $stmt->bindValue(':id', (int) $value, \PDO::PARAM_INT) : $stmt->bindValue(":$column", in_array($value, [true, 't', 1, '1'], true), \PDO::PARAM_BOOL);
            }
            $stmt->execute();
        }
        $this->clean();
        parent::tearDown();
    }

    public function testTheMerchantOpensOneSectionReadOnlyForAnHourAndSeesEveryScreenViewed(): void
    {
        $merchant = $this->account(MV_TEST_TENANT2_DB, MV_TEST_TENANT2_CODE, 'commercant', ['ROLE_ADMIN'], 'Claire');
        // aucune agence ne gère le site : rien à demander
        $this->assertSame([409, 'ASSISTANCE_NO_AGENCY'], $this->refusal('POST', '/api/site/assistance', $merchant, ['section' => 'commandes']));
        [$agencyId, $operator, $member] = $this->agencyInside();

        // LA DEMANDE : refusée à l'opérateur (fermée par la garde : jamais l'agence) ; validée pour le commerçant
        $this->assertSame([403, 'MODE_FORBIDDEN'], $this->refusal('POST', '/api/site/assistance', $operator, ['section' => 'commandes']));
        $this->assertSame([403, 'MODE_FORBIDDEN'], $this->refusal('GET', '/api/site/assistance', $operator));
        $this->assertSame([422, 'ASSISTANCE_SECTION_INVALID'], $this->refusal('POST', '/api/site/assistance', $merchant, ['section' => 'cles']));
        $this->assertSame([422, 'ASSISTANCE_MODE_INVALID'], $this->refusal('POST', '/api/site/assistance', $merchant, ['section' => 'commandes', 'mode' => 'tout']));
        $this->assertSame([422, 'ASSISTANCE_READ_ONLY'], $this->refusal('POST', '/api/site/assistance', $merchant, ['section' => 'pertes', 'mode' => 'write']), 'les pertes : consultation seulement');
        foreach (['caisse', 'stock', 'assistance'] as $later) {
            $this->assertSame([422, 'ASSISTANCE_SECTION_UNAVAILABLE'], $this->refusal('POST', '/api/site/assistance', $merchant, ['section' => $later]), "$later : avec EasyAdmin (AG-3c)");
        }
        // avant la demande, la section reste fermée à l'opérateur
        $this->assertSame([403, 'family'], $this->reason('GET', '/api/orders', $operator));

        $asked = $this->call('POST', '/api/site/assistance', $merchant, ['section' => 'commandes']);
        $this->assertSame(201, $asked->getStatusCode(), (string) $asked->getContent());
        $grant = json_decode((string) $asked->getContent(), true)['assistance'];
        $this->assertSame(['commandes', 'read', 'waiting', 'Claire Essai', 'Atelier Web', null], [$grant['section'], $grant['mode'], $grant['state'], $grant['requestedBy'], $grant['agency'], $grant['endsAt']]);
        $this->assertEqualsWithDelta(time() + 86400, (new \DateTimeImmutable($grant['requestExpiresAt']))->getTimestamp(), 60, 'sans entrée, la demande expire en 24 h');
        $this->assertSame([409, 'ASSISTANCE_ALREADY_OPEN'], $this->refusal('POST', '/api/site/assistance', $merchant, ['section' => 'commandes', 'mode' => 'write']), 'une demande vivante par section');
        // l'agence la voit depuis son site d'attache
        $seen = json_decode((string) $this->call('GET', '/api/agency/assistance', $member, null, MV_TEST_TENANT_HOST)->getContent(), true)['assistance'] ?? [];
        $this->assertSame([[MV_TEST_TENANT2_CODE, 'commandes', 'read', 'waiting']], array_map(static fn (array $g): array => [$g['site'], $g['section'], $g['mode'], $g['state']], $seen));

        // LA PREMIÈRE REQUÊTE de l'opérateur ouvre la fenêtre d'une heure
        $this->assertNotSame(403, $this->call('GET', '/api/orders', $operator)->getStatusCode(), 'la section ouverte se lit');
        $row = $this->grant((int) $grant['id']);
        $this->assertNotNull($row['started_at']);
        $this->assertEqualsWithDelta(3600, strtotime($row['ends_at'] . ' UTC') - strtotime($row['started_at'] . ' UTC'), 1, 'une heure à partir de la première requête');
        // consultation seulement : aucune écriture, même dans la section ; les autres sections restent fermées
        $this->assertSame([403, 'assistance_read_only'], $this->reason('POST', '/api/order/cancel/1', $operator));
        $this->assertSame([403, 'family'], $this->reason('GET', '/api/contacts', $operator), 'hors de la section : fermé');
        // une seconde requête ne prolonge pas la fenêtre
        $this->call('GET', '/api/payments', $operator);
        $this->assertSame($row['ends_at'], $this->grant((int) $grant['id'])['ends_at']);

        // LA TRACE : chaque écran consulté, au nom de l'agence et du prénom, avec le numéro de l'assistance
        $journal = json_decode((string) $this->call('GET', '/api/site/assistance/' . $grant['id'] . '/journal', $merchant)->getContent(), true)['journal'] ?? [];
        $this->assertSame([['GET', '/api/payments', 'assistance_read', null], ['POST', '/api/order/cancel/1', 'assistance_refused', 'assistance_read_only'], ['GET', '/api/orders', 'assistance_read', null]],
            array_map(static fn (array $l): array => [$l['method'], $l['path'], $l['action'], $l['reason']], $journal), 'chaque écran consulté ET chaque refus');
        $this->assertNotNull($journal[0]['status'], 'le statut de la réponse est ajouté à la ligne écrite avant de servir');
        $this->assertSame('Agence Atelier Web, Julie, pendant l\'assistance n° ' . $grant['id'], $journal[0]['account']);
        $this->assertSame(403, $this->call('GET', '/api/site/assistance/' . $grant['id'] . '/journal', $this->account(MV_TEST_TENANT2_DB, MV_TEST_TENANT2_CODE, 'client', ['ROLE_USER_INTERNET'], 'Paul'))->getStatusCode(), 'un client du site ne lit rien');
        $states = array_column(json_decode((string) $this->call('GET', '/api/site/assistance', $merchant)->getContent(), true)['assistance'], 'state', 'id');
        $this->assertSame('active', $states[$grant['id']]);

        // LA FERMETURE par le commerçant : immédiate
        $closed = json_decode((string) $this->call('POST', '/api/site/assistance/' . $grant['id'] . '/close', $merchant)->getContent(), true)['assistance'];
        $this->assertSame(['closed', 'Claire Essai'], [$closed['state'], $closed['closedBy']]);
        $this->assertSame([403, 'family'], $this->reason('GET', '/api/orders', $operator));
        $this->assertSame([404, 'ASSISTANCE_NOT_FOUND'], $this->refusal('POST', '/api/site/assistance/999999/close', $merchant));
        $this->assertSame([], json_decode((string) $this->call('GET', '/api/agency/assistance', $member, null, MV_TEST_TENANT_HOST)->getContent(), true)['assistance']);
        $this->assertGreaterThan(0, $agencyId);
    }

    public function testInEditModeTheAgencyWritesInThatSectionOnlyAndNeverAfterTheWindow(): void
    {
        $merchant = $this->account(MV_TEST_TENANT2_DB, MV_TEST_TENANT2_CODE, 'commercant', ['ROLE_ADMIN'], 'Claire');
        [, $operator] = $this->agencyInside();
        $asked = $this->call('POST', '/api/site/assistance', $merchant, ['section' => 'clients', 'mode' => 'write']);
        $this->assertSame(201, $asked->getStatusCode(), (string) $asked->getContent());
        $id = (int) json_decode((string) $asked->getContent(), true)['assistance']['id'];

        // un REFUS n'ouvre pas la fenêtre (supprimer un contact reste fermé, même en modifier)
        $this->assertSame([403, 'assistance_closed'], $this->reason('DELETE', '/api/contacts/999999', $operator));
        $this->assertNull($this->grant($id)['started_at'], 'une requête refusée ne fait pas partir l\'heure');
        // modifier : l'écriture de la section passe (la route répond selon la fiche), tracée comme une écriture
        $written = $this->call('PUT', '/api/contacts/999999', $operator, ['status' => 'read']);
        $this->assertNotSame('MODE_FORBIDDEN', json_decode((string) $written->getContent(), true)['code'] ?? null, (string) $written->getContent());
        $trace = $this->pdo(MV_TEST_TENANT2_DB)->query("SELECT user_identifier, action FROM content_audit_log WHERE resource = 'external_access' AND action LIKE 'assistance_%' ORDER BY id DESC LIMIT 1")->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame(['Agence Atelier Web, Julie, pendant l\'assistance n° ' . $id, 'assistance_write'], [$trace['user_identifier'], $trace['action']]);
        // toujours fermés, même en modifier : supprimer un client, un export, une autre section
        $this->assertSame([403, 'assistance_closed'], $this->reason('DELETE', '/api/contacts/999999', $operator));
        $this->assertSame([403, 'assistance_closed'], $this->reason('GET', '/api/form-submissions/export', $operator));
        $this->assertSame([403, 'assistance_closed'], $this->reason('GET', '/api/newsletter', $operator), 'la liste des adresses des abonnés : un export de fait');
        $this->assertSame([403, 'family'], $this->reason('POST', '/api/order/cancel/1', $operator), 'hors de la section : fermé');

        // la fenêtre passée : plus rien, ni lecture ni écriture
        $this->master->prepare("UPDATE assistance_grants SET started_at = NOW() - INTERVAL '2 hours', ends_at = NOW() - INTERVAL '1 hour' WHERE id = ?")->execute([$id]);
        $this->assertSame([403, 'family'], $this->reason('PUT', '/api/contacts/999999', $operator, ['status' => 'read']));
        $this->assertSame([403, 'family'], $this->reason('GET', '/api/contacts', $operator));
        $this->assertSame('ended', array_column(json_decode((string) $this->call('GET', '/api/site/assistance', $merchant)->getContent(), true)['assistance'], 'state', 'id')[$id]);
        // une demande échue ne bloque pas la suivante (elle est fermée d'abord)
        $this->assertSame(201, $this->call('POST', '/api/site/assistance', $merchant, ['section' => 'clients'])->getStatusCode());
        $this->assertSame(['ended', 'read'], [$this->grant($id)['closed_reason'], $this->grantOf('clients')['mode']]);
    }

    public function testARequestWithoutEntryExpiresAfterADayAndOnlyTheMerchantOpensIt(): void
    {
        $merchant = $this->account(MV_TEST_TENANT2_DB, MV_TEST_TENANT2_CODE, 'commercant', ['ROLE_ADMIN'], 'Claire');
        [, $operator] = $this->agencyInside();
        $this->assertSame(201, $this->call('POST', '/api/site/assistance', $merchant, ['section' => 'gestion'])->getStatusCode());
        $id = (int) $this->grantOf('gestion')['id'];
        $this->master->prepare("UPDATE assistance_grants SET requested_at = NOW() - INTERVAL '25 hours', request_expires_at = NOW() - INTERVAL '1 hour' WHERE id = ?")->execute([$id]);
        $this->assertSame([403, 'family'], $this->reason('GET', '/api/gestion/journee', $operator), 'personne n\'est entré dans les 24 h : expirée');
        $this->assertNull($this->grant($id)['started_at'], 'une demande expirée ne démarre jamais');
        $this->assertSame('expired', array_column(json_decode((string) $this->call('GET', '/api/site/assistance', $merchant)->getContent(), true)['assistance'], 'state', 'id')[$id]);
        // le compte de la plateforme ne demande ni ne ferme l'assistance à la place du commerçant
        $platform = $this->account(MV_TEST_TENANT2_DB, MV_TEST_TENANT2_CODE, 'plateforme', ['ROLE_SUPER_ADMIN', 'ROLE_ADMIN'], 'Plateforme');
        $this->assertSame([403, 'ASSISTANCE_FORBIDDEN'], $this->refusal('POST', '/api/site/assistance', $platform, ['section' => 'gestion']));
        $this->assertSame([403, 'ASSISTANCE_FORBIDDEN'], $this->refusal('POST', '/api/site/assistance/' . $id . '/close', $platform));
        $this->assertSame(200, $this->call('GET', '/api/site/assistance', $platform)->getStatusCode(), 'il lit');
    }

    /** En « modifier » dans les commandes : annuler une vente (un remboursement), acheter une étiquette restent fermés ; préparer un colis passe */
    public function testInEditModeTheOrdersSectionNeverMovesMoney(): void
    {
        $merchant = $this->account(MV_TEST_TENANT2_DB, MV_TEST_TENANT2_CODE, 'commercant', ['ROLE_ADMIN'], 'Claire');
        [$agencyId, $operator, $member] = $this->agencyInside();
        $this->assertSame(201, $this->call('POST', '/api/site/assistance', $merchant, ['section' => 'commandes', 'mode' => 'write'])->getStatusCode());
        $this->assertSame([403, 'assistance_closed'], $this->reason('POST', '/api/order/cancel/1', $operator), 'annuler une vente la rembourse');
        $this->assertSame([403, 'assistance_closed'], $this->reason('POST', '/api/shipping/buy', $operator, ['orderId' => 1]), 'acheter une étiquette dépense l\'argent du commerçant');
        $parcel = $this->call('POST', '/api/shipping/parcels', $operator, []);
        $this->assertNotSame('MODE_FORBIDDEN', json_decode((string) $parcel->getContent(), true)['code'] ?? null, (string) $parcel->getContent());

        // l'agence DÉTACHÉE du site n'a plus rien, même une demande ouverte d'avant
        static::getContainer()->get(AgencyService::class)->detach($agencyId, MV_TEST_TENANT2_CODE, 'essai');
        $this->assertSame([], json_decode((string) $this->call('GET', '/api/agency/assistance', $member, null, MV_TEST_TENANT_HOST)->getContent(), true)['assistance'] ?? null);
    }

    /**
     * FERMÉ PAR DÉFAUT : chaque écriture de l'API d'une section d'assistance est rangée, permise (`AssistanceSections::WRITES`) ou refusée,
     * dans le fichier témoin `tests/Fixtures/ecrans/assistance-ecritures.json`. Une route nouvelle fait échouer cet essai : la ranger, puis
     * régénérer le témoin (variable REGENERER_ASSISTANCE) et RELIRE son diff.
     */
    public function testEveryWriteOfASectionIsListed(): void
    {
        $computed = [];
        foreach (static::getContainer()->get('router')->getRouteCollection() as $route) {
            $template = $route->getPath();
            if (!str_starts_with($template, '/api/')) {
                continue;
            }
            $path = (string) preg_replace('/\{[^}]+\}/', '1', $template);
            foreach (array_intersect($route->getMethods() ?: ['POST'], ['POST', 'PUT', 'PATCH', 'DELETE']) as $method) {
                $screen = \App\Services\Screens\ScreenRegistry::classify($method, $path);
                if (!is_string($screen) || str_starts_with($screen, '@')) {
                    continue;
                }
                foreach (\App\Services\Agency\Assistance\AssistanceSections::of($screen, \App\Services\Screens\ScreenRegistry::familyOf($screen)) as $section) {
                    $computed[$section][\App\Services\Agency\Assistance\AssistanceSections::writeAllowed($section, $method, $path) ? 'allowed' : 'refused'][] = "$method $template";
                }
            }
        }
        ksort($computed);
        foreach ($computed as &$lists) {
            ksort($lists);
            foreach ($lists as &$list) {
                $list = array_values(array_unique($list));
                sort($list);
            }
        }
        unset($lists, $list);
        $file = __DIR__ . '/../../Fixtures/ecrans/assistance-ecritures.json';
        if (getenv('REGENERER_ASSISTANCE')) {
            file_put_contents((string) getenv('REGENERER_ASSISTANCE'), json_encode($computed, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE) . "\n");
            $this->markTestIncomplete('Témoin écrit dans ' . getenv('REGENERER_ASSISTANCE') . ' : le copier dans tests/Fixtures/ecrans/assistance-ecritures.json, puis relire son diff.');
        }
        $this->assertSame(json_decode((string) file_get_contents($file), true), $computed, 'écriture d\'une section non rangée : la permettre ou la refuser dans AssistanceSections::WRITES, puis régénérer le témoin');
        $this->assertSame([], $computed['pertes']['allowed'] ?? [], 'les pertes : aucune écriture');
        $this->assertNotContains('POST /api/order/cancel/{id}', $computed['commandes']['allowed'] ?? []);
        $this->assertNotContains('POST /api/shipping/buy', $computed['commandes']['allowed'] ?? []);
    }

    /** L'agence créée, le second site rattaché, un membre entré : [agence, jeton d'entrée de l'opérateur, jeton du membre sur son site d'attache] */
    private function agencyInside(): array
    {
        $member = $this->account(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE, 'membre', ['ROLE_AGENCY_MEMBER'], 'Julie');
        $agencies = static::getContainer()->get(AgencyService::class);
        $agencyId = (int) $agencies->create(['code' => 'agence-assist-' . $this->tag, 'name' => 'Atelier Web', 'homeTenantCode' => MV_TEST_TENANT_CODE, 'test' => true, 'contactEmail' => 'assist-contact@example.invalid'], 'essai')['id'];
        $agencies->attach($agencyId, MV_TEST_TENANT2_CODE, 'essai');
        $entered = $this->call('POST', '/api/me/contexts/enter', $member, ['site' => MV_TEST_TENANT2_CODE], MV_TEST_TENANT_HOST);
        $this->assertSame(200, $entered->getStatusCode(), (string) $entered->getContent());

        return [$agencyId, json_decode((string) $entered->getContent(), true)['token'], $member];
    }

    /** @return array{0: int, 1: ?string} */
    private function refusal(string $method, string $path, string $token, ?array $body = null, string $key = 'code'): array
    {
        $response = $this->call($method, $path, $token, $body);

        return [$response->getStatusCode(), json_decode((string) $response->getContent(), true)[$key] ?? null];
    }

    /** @return array{0: int, 1: ?string} statut et raison du refus de la garde du mode */
    private function reason(string $method, string $path, string $token, ?array $body = null): array
    {
        return $this->refusal($method, $path, $token, $body, 'reason');
    }

    private function call(string $method, string $path, string $token, ?array $body = null, string $host = MV_TEST_TENANT2_HOST): Response
    {
        $this->client->getCookieJar()->clear();
        $this->client->request($method, 'https://' . $host . $path, [], [], ['HTTP_X_TENANT_HOST' => $host, 'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'REMOTE_ADDR' => '10.87.' . random_int(1, 254) . '.' . random_int(1, 254)], $body !== null ? (string) json_encode($body) : null);

        return $this->client->getResponse();
    }

    /** Un compte d'un site de test (avec une session vivante : l'entrée l'exige), et son jeton sur ce site */
    private function account(string $db, string $code, string $name, array $roles, string $firstName): string
    {
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant($db, $code);
        $em = $provider->getEntityManager();
        $email = "assist-$name-{$this->tag}@example.invalid";
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname($firstName)->setLastname('Essai')->setRoles($roles)->setPassword('inutilisable')->setIsVerified(true);
        $em->persist($user);
        $em->flush();
        $em->getConnection()->executeStatement("INSERT INTO refresh_tokens (id, refresh_token, username, valid) VALUES (nextval('refresh_tokens_id_seq'), ?, ?, NOW() + INTERVAL '1 day')", ['assist-' . bin2hex(random_bytes(30)), $email]);
        $token = static::getContainer()->get('lexik_jwt_authentication.jwt_manager')->create($user);
        $em->clear();
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);

        return $token;
    }

    /** @return array<string, mixed> */
    private function grant(int $id): array
    {
        $stmt = $this->master->prepare('SELECT * FROM assistance_grants WHERE id = ?');
        $stmt->execute([$id]);

        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
    }

    /** @return array<string, mixed> la demande non fermée d'une section du second site */
    private function grantOf(string $section): array
    {
        $stmt = $this->master->prepare('SELECT * FROM assistance_grants WHERE tenant_code = ? AND section = ? AND closed_at IS NULL');
        $stmt->execute([MV_TEST_TENANT2_CODE, $section]);

        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
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
            $this->pdo($db)->exec("DELETE FROM refresh_tokens WHERE username LIKE 'assist-%@example.invalid'");
            $this->pdo($db)->exec("DELETE FROM \"user\" WHERE email LIKE 'assist-%@example.invalid'");
            $this->pdo($db)->exec("DELETE FROM content_audit_log WHERE resource = 'external_access'");
        }
    }

    private function pdo(string $db): \PDO
    {
        return static::getContainer()->get(TenantDatabaseConnector::class)->pdo($db);
    }
}
