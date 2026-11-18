<?php

namespace App\Tests\Functional\Group;

use App\Entity\User;
use App\Services\Group\GroupService;
use App\Services\Platform\TenantDatabaseConnector;
use App\Services\TenantCacheService;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lot G-1a (18/11/2026, contrat validé par le chef, 4 ajouts) : le multi-boutiques.
 * - « Passer en multi-boutiques » exige le module ; le site devient le siège.
 * - Inviter rend la MÊME réponse que le site existe ou non, soit le siège, soit déjà invité ; 10 demandes par jour et par groupe.
 * - Le courriel part aux seuls administrateurs du site invité (ni simple compte, ni compte de la plateforme) et dit le consentement en clair.
 * - Accepter exige le texte de consentement ; jamais d'ajout silencieux. Quitter ne supprime rien ; le siège seul dissout.
 */
class GroupApiTest extends WebTestCase
{
    private KernelBrowser $client;
    private \PDO $master;
    private string $tag;
    /** @var array<string, string> */
    private array $tokens = [];

    protected function setUp(): void
    {
        // un expéditeur de la plateforme (sinon aucun courriel ne part en essai), comme AgencySitesTest
        $_ENV['MAILER_DSN'] = $_SERVER['MAILER_DSN'] = 'null://plateforme-envoi%40example.invalid:x@default';
        $this->client = static::createClient();
        $this->master = static::getContainer()->get(TenantConnectionManager::class)->getPdoMaster();
        $this->tag = bin2hex(random_bytes(3));
        $this->clean();
        $this->tokens = [
            'siege' => $this->token(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE, 'siege', ['ROLE_ADMIN'], 'Hélène'),
            'boutique' => $this->token(MV_TEST_TENANT2_DB, MV_TEST_TENANT2_CODE, 'boutique', ['ROLE_ADMIN'], 'Marc'),
            'employe' => $this->token(MV_TEST_TENANT2_DB, MV_TEST_TENANT2_CODE, 'employe', ['ROLE_USER'], 'Léa'),
            'plateforme' => $this->token(MV_TEST_TENANT2_DB, MV_TEST_TENANT2_CODE, 'plateforme', ['ROLE_ADMIN', 'ROLE_SUPER_ADMIN'], 'Plateforme'),
        ];
    }

    protected function tearDown(): void
    {
        $this->clean();
        unset($_ENV['MAILER_DSN'], $_SERVER['MAILER_DSN']);
        parent::tearDown();
    }

    public function testTheHeadCreatesItsGroupAndAnInvitationAnswersTheSameWhateverTheTarget(): void
    {
        $this->assertSame([403, 'MODULE_INACTIVE'], $this->refusal('POST', '/api/group', null, 'siege'), 'le module d\'abord');
        $this->assertSame(['group' => null, 'shops' => [], 'canCreate' => false], $this->call('GET', '/api/group', null, 'siege', 200));
        $this->module(true);
        $this->assertTrue($this->call('GET', '/api/group', null, 'siege', 200)['canCreate']);
        $made = $this->call('POST', '/api/group', null, 'siege', 201);
        $this->assertSame(['head', MV_TEST_TENANT_CODE, 10], [$made['group']['role'], $made['group']['head'], $made['group']['maxShops']]);
        $this->assertSame([[MV_TEST_TENANT_CODE, 'head']], array_map(static fn (array $s): array => [$s['code'], $s['origin']], $made['shops']));
        $this->assertSame([409, 'GROUP_ALREADY_MEMBER'], $this->refusal('POST', '/api/group', null, 'siege'));
        $this->assertSame([403, 'GROUP_HEAD_ONLY'], $this->refusal('POST', '/api/group/invitations', ['site' => MV_TEST_TENANT_CODE], 'boutique'), 'un site sans groupe n\'invite pas');

        // la même réponse, que le site existe ou non, soit le siège ou déjà invité
        $answers = [];
        foreach (['site-inconnu-' . $this->tag, MV_TEST_TENANT2_CODE, MV_TEST_TENANT_CODE, MV_TEST_TENANT2_CODE, ''] as $target) {
            $this->client->getCookieJar()->clear();
            $response = $this->raw('POST', '/api/group/invitations', ['site' => $target], 'siege');
            $answers[] = [$response->getStatusCode(), (string) $response->getContent()];
        }
        $this->assertSame(array_fill(0, 5, [202, json_encode(['asked' => true, 'expiresInDays' => GroupService::INVITATION_DAYS])]), $answers);
        $this->assertSame(1, (int) $this->master->query("SELECT COUNT(*) FROM shop_group_invitations WHERE tenant_code = '" . MV_TEST_TENANT2_CODE . "'")->fetchColumn(), 'une seule invitation en attente');
        $this->assertSame(0, (int) $this->master->query("SELECT COUNT(*) FROM shop_group_invitations WHERE tenant_code <> '" . MV_TEST_TENANT2_CODE . "'")->fetchColumn());
        // relecture neuve : deux messages simultanés ne font jamais deux invitations ouvertes (index unique partiel)
        $groupId = (int) $this->master->query('SELECT id FROM shop_groups')->fetchColumn();
        $this->assertNull(static::getContainer()->get(\App\Services\Group\GroupRepository::class)->invite($groupId, MV_TEST_TENANT2_CODE, hash('sha256', 'double'), 'essai', new \DateTimeImmutable('+1 day')));
        $this->assertSame(['unknown', 'head', 'pending', 'unknown'], $this->master->query("SELECT payload->>'reason' FROM shop_group_events WHERE event = 'invitation_skipped' ORDER BY id")->fetchAll(\PDO::FETCH_COLUMN));

        $this->assertSame(1, (int) $this->master->query("SELECT COUNT(*) FROM shop_group_events WHERE event = 'invited' AND tenant_code = '" . MV_TEST_TENANT2_CODE . "'")->fetchColumn());

        // le courriel : aux seuls administrateurs du site invité, consentement en clair (l'envoi, appelé tel que la file l'appelle)
        $sent = static::getContainer()->get(\App\Services\Group\GroupInvitationMailer::class)->notify(MV_TEST_TENANT2_DB, 'Boutique invitée', 'Siège', 'Siège', GroupService::INVITATION_DAYS, 'fr');
        $mails = self::getMailerMessages();
        $this->assertGreaterThan(0, $sent);
        $to = [];
        foreach ($mails ?? [] as $mail) {
            foreach ($mail->getTo() as $address) {
                $to[] = $address->getAddress();
            }
        }
        $this->assertContains("g1-boutique-{$this->tag}@example.invalid", $to);
        $this->assertNotContains("g1-employe-{$this->tag}@example.invalid", $to, 'un simple compte ne reçoit rien');
        $this->assertNotContains("g1-plateforme-{$this->tag}@example.invalid", $to, 'le compte de la plateforme ne reçoit rien');
        $ours = array_values(array_filter($mails ?? [], fn ($m): bool => $m->getTo()[0]->getAddress() === "g1-boutique-{$this->tag}@example.invalid"))[0];
        $this->assertStringContainsString('pourra entrer dans votre boutique et y agir', html_entity_decode((string) $ours->getHtmlBody()));
    }

    public function testTenInvitationsADayPerGroup(): void
    {
        $this->module(true);
        $group = (int) $this->call('POST', '/api/group', null, 'siege', 201)['group']['id'];
        $this->master->exec("INSERT INTO shop_group_events (group_id, event, tenant_code) SELECT $group, 'invitation_asked', 'x' FROM generate_series(1, 9)");
        $this->call('POST', '/api/group/invitations', ['site' => 'site-inconnu'], 'siege', 202);
        $this->assertSame([429, 'GROUP_INVITE_LIMIT'], $this->refusal('POST', '/api/group/invitations', ['site' => MV_TEST_TENANT2_CODE], 'siege'));
        // relecture neuve, passe 2 : dissoudre puis recréer le groupe ne remet pas la limite à zéro (comptée par siège)
        $this->call('POST', '/api/group/leave', null, 'siege', 200);
        $this->call('POST', '/api/group', null, 'siege', 201);
        $this->assertSame([429, 'GROUP_INVITE_LIMIT'], $this->refusal('POST', '/api/group/invitations', ['site' => MV_TEST_TENANT2_CODE], 'siege'));
        $this->master->exec("UPDATE shop_group_events SET created_at = NOW() - INTERVAL '25 hours'");
        $this->call('POST', '/api/group/invitations', ['site' => MV_TEST_TENANT2_CODE], 'siege', 202);
    }

    public function testTheShopJoinsOnlyWithTheConsentAndLeavesWithoutAnythingDeleted(): void
    {
        $this->module(true);
        $this->call('POST', '/api/group', null, 'siege', 201);
        $this->call('POST', '/api/group/invitations', ['site' => MV_TEST_TENANT2_CODE], 'siege', 202);

        $this->assertSame(403, $this->raw('GET', '/api/site/group-invitations', null, 'employe')->getStatusCode(), 'administrateur seulement');
        $this->assertSame([403, 'GROUP_FORBIDDEN'], $this->refusal('GET', '/api/site/group-invitations', null, 'plateforme'));
        $list = $this->call('GET', '/api/site/group-invitations', null, 'boutique', 200)['invitations'];
        $this->assertCount(1, $list);
        $this->assertSame([GroupService::CONSENT_VERSION, true], [$list[0]['consentVersion'], str_contains($list[0]['consent'], 'chaque geste sera tracé')]);
        $id = $list[0]['id'];

        $this->assertSame([403, 'GROUP_FORBIDDEN'], $this->refusal('POST', "/api/site/group-invitations/$id/accept", ['consent' => GroupService::CONSENT_VERSION], 'plateforme'), 'jamais à la place du commerçant');
        foreach ([null, ['consent' => true], ['consent' => 'g1-v0']] as $body) {
            $this->assertSame([422, 'GROUP_CONSENT_REQUIRED'], $this->refusal('POST', "/api/site/group-invitations/$id/accept", $body, 'boutique'));
        }
        $this->assertSame(0, (int) $this->master->query("SELECT COUNT(*) FROM shop_group_sites WHERE tenant_code = '" . MV_TEST_TENANT2_CODE . "'")->fetchColumn(), 'jamais d\'ajout silencieux');
        $this->assertSame([404, 'GROUP_INVITATION_NOT_FOUND'], $this->refusal('POST', '/api/site/group-invitations/' . ($id + 1000) . '/accept', ['consent' => GroupService::CONSENT_VERSION], 'boutique'));

        $joined = $this->call('POST', "/api/site/group-invitations/$id/accept", ['consent' => GroupService::CONSENT_VERSION], 'boutique', 200);
        $this->assertSame(['shop', MV_TEST_TENANT_CODE], [$joined['group']['role'], $joined['group']['head']]);
        $this->assertSame([404, 'GROUP_INVITATION_NOT_FOUND'], $this->refusal('POST', "/api/site/group-invitations/$id/accept", ['consent' => GroupService::CONSENT_VERSION], 'boutique'), 'déjà traitée');
        $this->assertSame([[MV_TEST_TENANT_CODE, 'head'], [MV_TEST_TENANT2_CODE, 'associated']], array_map(static fn (array $s): array => [$s['code'], $s['origin']], $this->call('GET', '/api/group', null, 'siege', 200)['shops']));

        // quitter
        $this->assertSame([409, 'GROUP_NOT_EMPTY'], $this->refusal('POST', '/api/group/leave', null, 'siege'));
        $this->assertSame([403, 'GROUP_HEAD_ONLY'], $this->refusal('POST', '/api/group/leave', ['shop' => MV_TEST_TENANT_CODE], 'boutique'));
        $this->assertNull($this->call('POST', '/api/group/leave', null, 'boutique', 200)['group']);
        $this->assertSame(1, (int) $this->master->query("SELECT COUNT(*) FROM shop_group_sites WHERE tenant_code = '" . MV_TEST_TENANT2_CODE . "' AND left_at IS NOT NULL")->fetchColumn(), 'rien n\'est supprimé');
        $this->assertNull($this->call('POST', '/api/group/leave', null, 'siege', 200)['group'], 'le siège seul dissout');
        $this->assertSame('dissolved', $this->master->query('SELECT status FROM shop_groups')->fetchColumn());
        $this->call('POST', '/api/group', null, 'siege', 201); // un nouveau groupe après la dissolution
        $this->assertSame(['created', 'invitation_asked', 'invited', 'joined', 'left', 'dissolved', 'created'], $this->master->query('SELECT event FROM shop_group_events ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN));
    }

    public function testARefusedOrFullGroupDoesNotTakeTheShop(): void
    {
        $this->module(true);
        $group = (int) $this->call('POST', '/api/group', null, 'siege', 201)['group']['id'];
        $this->call('POST', '/api/group/invitations', ['site' => MV_TEST_TENANT2_CODE], 'siege', 202);
        $id = $this->call('GET', '/api/site/group-invitations', null, 'boutique', 200)['invitations'][0]['id'];
        $this->assertSame(204, $this->raw('POST', "/api/site/group-invitations/$id/refuse", null, 'boutique')->getStatusCode());
        $this->assertSame([], $this->call('GET', '/api/site/group-invitations', null, 'boutique', 200)['invitations']);

        // forfait plein : la boutique n'entre pas
        $this->master->exec("UPDATE shop_groups SET max_shops = 1 WHERE id = $group");
        $this->call('POST', '/api/group/invitations', ['site' => MV_TEST_TENANT2_CODE], 'siege', 202);
        $id = $this->call('GET', '/api/site/group-invitations', null, 'boutique', 200)['invitations'][0]['id'];
        $this->assertSame([409, 'GROUP_SHOP_LIMIT'], $this->refusal('POST', "/api/site/group-invitations/$id/accept", ['consent' => GroupService::CONSENT_VERSION], 'boutique'));

        // groupe dissous : l'invitation ne s'accepte plus
        $this->call('POST', '/api/group/leave', null, 'siege', 200);
        $this->master->exec("UPDATE shop_group_invitations SET accepted_at = NULL, refused_at = NULL WHERE id = $id");
        $this->assertSame([404, 'GROUP_INVITATION_NOT_FOUND'], $this->refusal('POST', "/api/site/group-invitations/$id/accept", ['consent' => GroupService::CONSENT_VERSION], 'boutique'));
        $this->assertSame(0, (int) $this->master->query("SELECT COUNT(*) FROM shop_group_sites WHERE tenant_code = '" . MV_TEST_TENANT2_CODE . "'")->fetchColumn());
    }

    private function module(bool $on): void
    {
        foreach ([[MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE], [MV_TEST_TENANT2_DB, MV_TEST_TENANT2_CODE]] as [$db, $code]) {
            $this->pdo($db)->exec('UPDATE entreprise SET is_multi_shop_active = ' . ($on ? 'TRUE' : 'FALSE'));
            static::getContainer()->get(TenantCacheService::class)->invalidateSite($code, $db);
        }
    }

    /** @return array{0: int, 1: ?string} */
    private function refusal(string $method, string $path, ?array $body, string $who): array
    {
        $response = $this->raw($method, $path, $body, $who);

        return [$response->getStatusCode(), json_decode((string) $response->getContent(), true)['code'] ?? null];
    }

    /** @return array<string, mixed> */
    private function call(string $method, string $path, ?array $body, string $who, int $status): array
    {
        $response = $this->raw($method, $path, $body, $who);
        $this->assertSame($status, $response->getStatusCode(), $method . ' ' . $path . ' : ' . $response->getContent());

        return json_decode((string) $response->getContent(), true) ?? [];
    }

    private function raw(string $method, string $path, ?array $body, string $who): Response
    {
        $host = $who === 'siege' ? MV_TEST_TENANT_HOST : MV_TEST_TENANT2_HOST;
        $this->client->getCookieJar()->clear();
        $this->client->request($method, 'https://' . $host . $path, [], [], ['HTTP_X_TENANT_HOST' => $host, 'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->tokens[$who], 'REMOTE_ADDR' => '10.91.' . random_int(1, 254) . '.' . random_int(1, 254)], $body === null ? ($method === 'POST' ? '{}' : null) : json_encode($body));

        return $this->client->getResponse();
    }

    /** @param list<string> $roles */
    private function token(string $db, string $code, string $name, array $roles, string $firstName): string
    {
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant($db, $code);
        $em = $provider->getEntityManager();
        $email = "g1-$name-{$this->tag}@example.invalid";
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
        foreach (['shop_group_events', 'shop_group_invitations', 'shop_group_sites', 'shop_groups'] as $table) {
            $this->master->exec("DELETE FROM $table");
        }
        foreach ([[MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE], [MV_TEST_TENANT2_DB, MV_TEST_TENANT2_CODE]] as [$db, $code]) {
            $this->pdo($db)->exec("DELETE FROM \"user\" WHERE email LIKE 'g1-%@example.invalid'");
            $this->pdo($db)->exec('UPDATE entreprise SET is_multi_shop_active = FALSE');
            static::getContainer()->get(TenantCacheService::class)->invalidateSite($code, $db);
        }
    }

    private function pdo(string $db): \PDO
    {
        return static::getContainer()->get(TenantDatabaseConnector::class)->pdo($db);
    }
}
