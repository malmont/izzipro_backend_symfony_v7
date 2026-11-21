<?php

namespace App\Tests\Functional\Group;

use App\Entity\User;
use App\Services\Agency\AgencyService;
use App\Services\Group\GroupRepository;
use App\Services\Group\GroupService;
use App\Services\Group\Message\SendGroupInvitationMessage;
use App\Services\Group\MessageHandler\SendGroupInvitationHandler;
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
        $this->assertSame(['created', 'invitation_requested', 'invited', 'joined', 'left', 'dissolved', 'created'], $this->master->query('SELECT event FROM shop_group_events ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN));
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

    /**
     * Lot G-INVITES (21/11/2026, contrat validé par le chef) : la liste du siège ne dit rien de l'existence du site. Un code inconnu et un vrai
     * site en attente se lisent EXACTEMENT pareil, avant comme après le passage de la file, et encore après un refus.
     */
    public function testTheHeadListsAnUnknownCodeAndARealPendingShopExactlyAlikeBeforeAndAfterTheQueue(): void
    {
        $this->module(true);
        $group = (int) $this->call('POST', '/api/group', null, 'siege', 201)['group']['id'];
        $this->assertSame(['invitations' => []], $this->call('GET', '/api/group/invitations', null, 'siege', 200));
        $repository = static::getContainer()->get(GroupRepository::class);
        $unknown = 'Site-Inconnu-' . $this->tag;
        // AVANT la file : les deux demandes écrites comme la requête du siège les écrit (code normalisé par le service)
        $fake = $repository->askInvitation($group, mb_strtolower($unknown), 10, 'Hélène');
        $real = $repository->askInvitation($group, MV_TEST_TENANT2_CODE, 10, 'Hélène');
        // demandes vieillies de 3 jours : l'échéance de l'invitation se compte depuis la demande, jamais depuis le passage de la file
        $this->master->exec("UPDATE shop_group_events SET created_at = created_at - INTERVAL '3 days' WHERE id IN ($fake, $real)");
        $before =$this->call('GET', '/api/group/invitations', null, 'siege', 200)['invitations'];
        $this->assertSame([[$real, MV_TEST_TENANT2_CODE], [$fake, mb_strtolower($unknown)]], array_map(static fn (array $i): array => [$i['id'], $i['code']], $before), 'le code tapé, le plus récent d\'abord');
        $shape = static fn (array $i): array => [array_keys($i), $i['state'], strtotime($i['expiresAt']) - strtotime($i['sentAt'])];
        $this->assertSame($shape($before[0]), $shape($before[1]));
        $this->assertSame([['id', 'code', 'sentAt', 'expiresAt', 'state'], 'pending', GroupService::INVITATION_DAYS * 86400], $shape($before[0]));

        // la file passe : une vraie invitation naît pour le vrai site, notée du numéro de sa demande ; la liste ne change pas d'un octet
        $handler = static::getContainer()->get(SendGroupInvitationHandler::class);
        $handler(new SendGroupInvitationMessage($group, mb_strtolower($unknown), 'Hélène', 'fr', $fake));
        $handler(new SendGroupInvitationMessage($group, MV_TEST_TENANT2_CODE, 'Hélène', 'fr', $real));
        $this->assertSame([$real], array_map('intval', $this->master->query('SELECT request_id FROM shop_group_invitations')->fetchAll(\PDO::FETCH_COLUMN)));
        $this->assertSame(1, (int) $this->master->query("SELECT COUNT(*) FROM shop_group_invitations i JOIN shop_group_events e ON e.id = i.request_id WHERE i.expires_at = e.created_at + INTERVAL '14 days'")->fetchColumn(), 'échéance comptée depuis la demande, comme la liste');
        // un message sérialisé par l'ancien code (sans requestId, en file au moment de la bascule) se lit sans erreur
        $class = SendGroupInvitationMessage::class;
        $old = unserialize(sprintf('O:%d:"%s":4:{s:7:"groupId";i:%d;s:8:"siteCode";s:%d:"%s";s:2:"by";N;s:6:"locale";s:2:"fr";}', strlen($class), $class, $group, strlen('ancien-' . $this->tag), 'ancien-' . $this->tag));
        $this->assertNull($old->requestId);
        $handler($old);
        $this->assertSame('unknown', $this->master->query("SELECT payload->>'reason' FROM shop_group_events WHERE event = 'invitation_skipped' ORDER BY id DESC LIMIT 1")->fetchColumn(), 'l\'ancien message suit l\'ancien chemin');
        $this->assertSame($before, $this->call('GET', '/api/group/invitations', null, 'siege', 200)['invitations']);

        // la boutique refuse : toujours « en attente » pour le siège, exactement comme avant
        $id = $this->call('GET', '/api/site/group-invitations', null, 'boutique', 200)['invitations'][0]['id'];
        $this->assertSame(204, $this->raw('POST', "/api/site/group-invitations/$id/refuse", null, 'boutique')->getStatusCode());
        $this->assertSame($before, $this->call('GET', '/api/group/invitations', null, 'siege', 200)['invitations']);

        // par l'API : la demande est écrite dans la requête, avant la file, avec le code tapé normalisé
        $this->call('POST', '/api/group/invitations', ['site' => '  ' . $unknown . '  '], 'siege', 202);
        $this->assertSame(mb_strtolower($unknown), $this->call('GET', '/api/group/invitations', null, 'siege', 200)['invitations'][0]['code']);
    }

    public function testAtExpiryARefusedAnUnknownAndAnUnansweredRequestAreAllExpiredThenHiddenAndAnAcceptedOneDisappears(): void
    {
        $this->module(true);
        $this->call('POST', '/api/group', null, 'siege', 201);
        $this->call('POST', '/api/group/invitations', ['site' => 'site-inconnu-' . $this->tag], 'siege', 202);
        $this->call('POST', '/api/group/invitations', ['site' => MV_TEST_TENANT2_CODE], 'siege', 202);
        $id = $this->call('GET', '/api/site/group-invitations', null, 'boutique', 200)['invitations'][0]['id'];
        $this->assertSame(204, $this->raw('POST', "/api/site/group-invitations/$id/refuse", null, 'boutique')->getStatusCode());
        $this->call('POST', '/api/group/invitations', ['site' => MV_TEST_TENANT2_CODE], 'siege', 202); // une nouvelle invitation, sans réponse
        $this->assertSame(['pending', 'pending', 'pending'], array_column($this->call('GET', '/api/group/invitations', null, 'siege', 200)['invitations'], 'state'));

        $this->master->exec("UPDATE shop_group_events SET created_at = NOW() - INTERVAL '15 days' WHERE event = 'invitation_requested'");
        $this->master->exec("UPDATE shop_group_invitations SET expires_at = NOW() - INTERVAL '1 day'");
        $expired = $this->call('GET', '/api/group/invitations', null, 'siege', 200)['invitations'];
        $this->assertSame(['expired', 'expired', 'expired'], array_column($expired, 'state'), 'refusée, code inconnu, sans réponse : pareil');
        $this->assertCount(1, array_unique(array_map(static fn (array $i): string => json_encode(array_keys($i)) . (strtotime($i['expiresAt']) - strtotime($i['sentAt'])), $expired)));
        $this->master->exec("UPDATE shop_group_events SET created_at = NOW() - INTERVAL '43 days' WHERE event = 'invitation_requested'");
        $this->assertSame(['expired', 'expired', 'expired'], array_column($this->call('GET', '/api/group/invitations', null, 'siege', 200)['invitations'], 'state'), 'encore là 29 jours après l\'échéance');
        $this->master->exec("UPDATE shop_group_events SET created_at = NOW() - INTERVAL '45 days' WHERE event = 'invitation_requested'");
        $this->assertSame([], $this->call('GET', '/api/group/invitations', null, 'siege', 200)['invitations'], 'cachées 30 jours après l\'échéance');

        // une invitation acceptée retire sa demande ; celle du code inconnu reste
        $this->master->exec('DELETE FROM shop_group_events');
        $this->master->exec('DELETE FROM shop_group_invitations');
        $this->call('POST', '/api/group/invitations', ['site' => 'site-inconnu-' . $this->tag], 'siege', 202);
        $this->call('POST', '/api/group/invitations', ['site' => MV_TEST_TENANT2_CODE], 'siege', 202);
        $id = $this->call('GET', '/api/site/group-invitations', null, 'boutique', 200)['invitations'][0]['id'];
        $this->call('POST', "/api/site/group-invitations/$id/accept", ['consent' => GroupService::CONSENT_VERSION], 'boutique', 200);
        $this->assertSame(['site-inconnu-' . $this->tag], array_column($this->call('GET', '/api/group/invitations', null, 'siege', 200)['invitations'], 'code'));
    }

    public function testCancellingAnswersTheSameForARealAndAnUnknownCodeAndTheRealInvitationNoLongerAccepts(): void
    {
        $this->module(true);
        $group = (int) $this->call('POST', '/api/group', null, 'siege', 201)['group']['id'];
        $this->call('POST', '/api/group/invitations', ['site' => 'site-inconnu-' . $this->tag], 'siege', 202);
        $this->call('POST', '/api/group/invitations', ['site' => MV_TEST_TENANT2_CODE], 'siege', 202);
        [$real, $fake] = array_column($this->call('GET', '/api/group/invitations', null, 'siege', 200)['invitations'], 'id');
        $invitation = $this->call('GET', '/api/site/group-invitations', null, 'boutique', 200)['invitations'][0]['id'];

        $answers = [];
        foreach ([$fake, $real] as $request) {
            $response = $this->raw('DELETE', "/api/group/invitations/$request", null, 'siege');
            $answers[] = [$response->getStatusCode(), (string) $response->getContent()];
        }
        $this->assertSame([[204, ''], [204, '']], $answers, 'la même réponse, que le code existe ou non');
        $this->assertSame([], $this->call('GET', '/api/group/invitations', null, 'siege', 200)['invitations']);
        $this->assertSame([], $this->call('GET', '/api/site/group-invitations', null, 'boutique', 200)['invitations'], 'la boutique ne la voit plus');
        $this->assertSame([404, 'GROUP_INVITATION_NOT_FOUND'], $this->refusal('POST', "/api/site/group-invitations/$invitation/accept", ['consent' => GroupService::CONSENT_VERSION], 'boutique'), 'elle ne s\'accepte plus');
        $this->assertSame(0, (int) $this->master->query("SELECT COUNT(*) FROM shop_group_sites WHERE tenant_code = '" . MV_TEST_TENANT2_CODE . "'")->fetchColumn());
        // sous le verrou de addShop : l'invitation close entre la lecture et l'entrée n'y fait JAMAIS entrer la boutique
        $this->assertSame('gone', static::getContainer()->get(GroupRepository::class)->addShop($group, MV_TEST_TENANT2_CODE, 'associated', 'essai', null, (int) $invitation));
        $this->assertSame(0, (int) $this->master->query("SELECT COUNT(*) FROM shop_group_sites WHERE tenant_code = '" . MV_TEST_TENANT2_CODE . "'")->fetchColumn());
        // la création sous le verrou relit l'annulation : une demande annulée ne donne aucune invitation
        $this->assertSame('cancelled', static::getContainer()->get(GroupRepository::class)->inviteForRequest($group, (int) $real, MV_TEST_TENANT2_CODE, hash('sha256', 'x' . $this->tag), 'essai', 14));
        $this->assertSame(2, (int) $this->master->query("SELECT COUNT(*) FROM shop_group_events WHERE event = 'invitation_cancelled' AND actor = 'Hélène'")->fetchColumn(), 'qui et quand');
        $this->assertSame([404, 'GROUP_INVITATION_NOT_FOUND'], $this->refusal('DELETE', "/api/group/invitations/$real", null, 'siege'), 'déjà annulée');
        $this->assertSame([404, 'GROUP_INVITATION_NOT_FOUND'], $this->refusal('DELETE', '/api/group/invitations/' . ($real + 100000), null, 'siege'), 'inconnue');

        // annulée AVANT le passage de la file : aucune invitation ne naît
        $repository = static::getContainer()->get(GroupRepository::class);
        $early = $repository->askInvitation($group, MV_TEST_TENANT2_CODE, 10, 'Hélène');
        $this->assertSame(204, $this->raw('DELETE', "/api/group/invitations/$early", null, 'siege')->getStatusCode());
        $before = (int) $this->master->query('SELECT COUNT(*) FROM shop_group_invitations')->fetchColumn();
        static::getContainer()->get(SendGroupInvitationHandler::class)(new SendGroupInvitationMessage($group, MV_TEST_TENANT2_CODE, 'Hélène', 'fr', $early));
        $this->assertSame($before, (int) $this->master->query('SELECT COUNT(*) FROM shop_group_invitations')->fetchColumn());
        $this->assertSame('cancelled', $this->master->query("SELECT payload->>'reason' FROM shop_group_events WHERE event = 'invitation_skipped' ORDER BY id DESC LIMIT 1")->fetchColumn());

        // la demande d'un AUTRE groupe : 404, et elle reste à son groupe
        $this->call('POST', '/api/group', null, 'boutique', 201);
        $this->call('POST', '/api/group/invitations', ['site' => 'autre-' . $this->tag], 'boutique', 202);
        $other = $this->call('GET', '/api/group/invitations', null, 'boutique', 200)['invitations'][0]['id'];
        $this->assertSame([404, 'GROUP_INVITATION_NOT_FOUND'], $this->refusal('DELETE', "/api/group/invitations/$other", null, 'siege'));
        $this->assertCount(1, $this->call('GET', '/api/group/invitations', null, 'boutique', 200)['invitations']);
    }

    public function testOnlyTheHeadAdministratorReadsOrCancelsNeverTheDirectorTheAgencyOrThePlatform(): void
    {
        $this->assertSame([403, 'GROUP_HEAD_ONLY'], $this->refusal('GET', '/api/group/invitations', null, 'siege'), 'sans groupe');
        $this->module(true);
        $this->call('POST', '/api/group', null, 'siege', 201);
        $this->call('POST', '/api/group/invitations', ['site' => MV_TEST_TENANT2_CODE], 'siege', 202);
        $request = $this->call('GET', '/api/group/invitations', null, 'siege', 200)['invitations'][0]['id'];
        $invitation = $this->call('GET', '/api/site/group-invitations', null, 'boutique', 200)['invitations'][0]['id'];
        $this->call('POST', "/api/site/group-invitations/$invitation/accept", ['consent' => GroupService::CONSENT_VERSION], 'boutique', 200);
        $this->call('POST', '/api/group/invitations', ['site' => 'site-inconnu-' . $this->tag], 'siege', 202);
        $request = $this->call('GET', '/api/group/invitations', null, 'siege', 200)['invitations'][0]['id'];

        // une boutique du groupe n'est pas le siège ; un simple compte n'est pas administrateur
        $this->assertSame([403, 'GROUP_HEAD_ONLY'], $this->refusal('GET', '/api/group/invitations', null, 'boutique'));
        $this->assertSame([403, 'GROUP_HEAD_ONLY'], $this->refusal('DELETE', "/api/group/invitations/$request", null, 'boutique'));
        $this->assertSame(403, $this->raw('GET', '/api/group/invitations', null, 'employe')->getStatusCode());
        // le compte de la plateforme, au siège même
        $this->tokens['siege-plateforme'] = $this->token(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE, 'siege-plateforme', ['ROLE_ADMIN', 'ROLE_SUPER_ADMIN'], 'Plateforme');
        $this->assertSame([403, 'GROUP_FORBIDDEN'], $this->refusal('GET', '/api/group/invitations', null, 'siege-plateforme'));
        $this->assertSame([403, 'GROUP_FORBIDDEN'], $this->refusal('DELETE', "/api/group/invitations/$request", null, 'siege-plateforme'));
        // le directeur entré dans une boutique
        $this->liveSession(MV_TEST_TENANT_DB, 'siege'); // l'entrée exige une session vivante
        $entered = json_decode((string) $this->raw('POST', '/api/me/contexts/enter', ['site' => MV_TEST_TENANT2_CODE], 'siege')->getContent(), true);
        $this->assertIsString($entered['token'] ?? null, 'le directeur entre dans la boutique');
        $this->tokens['entre'] = (string) $entered['token'];
        // la lecture passe la garde du mode et tombe sur la vérification du contrôleur (sans elle : GROUP_HEAD_ONLY) ; l'écriture est fermée par la garde
        $this->assertSame([403, 'GROUP_FORBIDDEN'], $this->refusal('GET', '/api/group/invitations', null, 'entre'));
        $this->assertSame([403, 'MODE_FORBIDDEN'], $this->refusal('DELETE', "/api/group/invitations/$request", null, 'entre'));
        // une agence entrée au siège
        $member = $this->token(MV_TEST_TENANT2_DB, MV_TEST_TENANT2_CODE, 'agence', ['ROLE_AGENCY_MEMBER'], 'Julie');
        $agencies = static::getContainer()->get(AgencyService::class);
        $agency = (int) $agencies->create(['code' => 'agence-g1-' . $this->tag, 'name' => 'Atelier', 'homeTenantCode' => MV_TEST_TENANT2_CODE, 'test' => true], 'essai')['id'];
        $agencies->attach($agency, MV_TEST_TENANT_CODE, 'essai');
        $this->tokens['agence'] = $member;
        $this->liveSession(MV_TEST_TENANT2_DB, 'agence');
        $operator = json_decode((string) $this->raw('POST', '/api/me/contexts/enter', ['site' => MV_TEST_TENANT_CODE], 'agence')->getContent(), true);
        $this->assertSame('agency', $operator['mode'] ?? null, 'l\'agence entre au siège');
        $this->tokens['siege-agence'] = (string) $operator['token'];
        $this->assertSame([403, 'MODE_FORBIDDEN'], $this->refusal('GET', '/api/group/invitations', null, 'siege-agence'));
        $this->assertSame([403, 'MODE_FORBIDDEN'], $this->refusal('DELETE', "/api/group/invitations/$request", null, 'siege-agence'));
        // rien n'a été annulé ; le module coupé ferme aussi la liste au siège
        $this->assertSame(0, (int) $this->master->query("SELECT COUNT(*) FROM shop_group_events WHERE event = 'invitation_cancelled'")->fetchColumn());
        $this->pdo(MV_TEST_TENANT_DB)->exec('UPDATE entreprise SET is_multi_shop_active = FALSE');
        static::getContainer()->get(TenantCacheService::class)->invalidateSite(MV_TEST_TENANT_CODE, MV_TEST_TENANT_DB);
        $this->assertSame([403, 'MODULE_INACTIVE'], $this->refusal('GET', '/api/group/invitations', null, 'siege'));
    }

    /** Une session vivante (refresh token) pour le compte de l'essai : l'entrée dans un autre site l'exige */
    private function liveSession(string $db, string $name): void
    {
        $this->pdo($db)->prepare("INSERT INTO refresh_tokens (id, refresh_token, username, valid) VALUES (nextval('refresh_tokens_id_seq'), ?, ?, NOW() + INTERVAL '1 day')")
            ->execute(['g1-' . bin2hex(random_bytes(30)), "g1-$name-{$this->tag}@example.invalid"]);
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
        $host = str_starts_with($who, 'siege') ? MV_TEST_TENANT_HOST : MV_TEST_TENANT2_HOST;
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
        // lot G-INVITES : l'agence de l'essai et les entrées (directeur, agence)
        $agencies = "(SELECT id FROM agencies WHERE code LIKE 'agence-g1-%')";
        $this->master->exec("DELETE FROM site_entries WHERE mode = 'group' OR agency_id IN $agencies");
        foreach (['assistance_grants', 'agency_site_requests', 'agency_events', 'agency_sites', 'agency_plans'] as $table) {
            $this->master->exec("DELETE FROM $table WHERE agency_id IN $agencies");
        }
        $this->master->exec("DELETE FROM agencies WHERE code LIKE 'agence-g1-%'");
        foreach ([MV_TEST_TENANT_DB, MV_TEST_TENANT2_DB] as $db) {
            $this->pdo($db)->exec("DELETE FROM content_audit_log WHERE resource = 'external_access' AND user_identifier LIKE ANY (ARRAY['Agence Atelier%', 'Groupe %'])");
            $this->pdo($db)->exec("DELETE FROM refresh_tokens WHERE username LIKE 'g1-%@example.invalid'");
        }
        foreach (['shop_group_item_links', 'shop_group_copies', 'shop_group_events', 'shop_group_invitations', 'shop_group_company_requests', 'shop_group_transfer_events', 'shop_group_transfer_lines', 'shop_group_transfers', 'shop_group_sites', 'shop_groups'] as $table) {
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
