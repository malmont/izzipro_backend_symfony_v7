<?php

namespace App\Tests\Functional\Group;

use App\Entity\Product;
use App\Entity\ProductVariant;
use App\Entity\User;
use App\Import\Message\RunCatalogImportMessage;
use App\Import\MessageHandler\RunCatalogImportHandler;
use App\Services\Group\GroupCompanyService;
use App\Services\Group\GroupService;
use App\Services\Platform\TenantDatabaseConnector;
use App\Services\TenantCacheService;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use App\Tests\Fake\FakeLandingAiClient;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lot G-1d (19/11/2026, contrat validé par le chef, décision du propriétaire) : le lien « même entreprise » d'une boutique ASSOCIÉE.
 * - À l'acceptation : case facultative, texte exigé à l'identique, vérifié AVANT l'entrée dans le groupe.
 * - Plus tard : le directeur DEMANDE (une seule en attente, 14 jours), la boutique confirme ou refuse elle-même ; ni le directeur entré, ni le
 *   compte de la plateforme, ni un simple compte ne confirment à sa place.
 * - Retrait : la boutique seule, tout de suite ; ou le directeur ; quitter le groupe efface le lien (le lien neuf repart à zéro).
 * - Traces : journal du groupe et des deux sites ; courriels aux administrateurs du site concerné, sans lien d'action.
 * - G-4 : le coût ne passe qu'entre boutiques de la même entreprise, relu à la confirmation.
 */
class GroupSameCompanyTest extends WebTestCase
{
    private KernelBrowser $client;
    private \PDO $master;
    private string $tag;
    /** @var array<string, string> */
    private array $tokens = [];

    protected function setUp(): void
    {
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

    public function testTheBoxAtAcceptanceNeedsItsOwnTextAndIsTracedOnBothSites(): void
    {
        $id = $this->invited();
        $list = $this->call('GET', '/api/site/group-invitations', null, 'boutique', 200)['invitations'];
        $this->assertSame(GroupCompanyService::CONSENT_VERSION, $list[0]['sameCompanyConsentVersion']);
        $this->assertStringContainsString('même entreprise que le siège', $list[0]['sameCompanyConsent']);

        // la case cochée sans son texte, ou avec un autre : refusé, et la boutique n'est PAS entrée sans le lien qu'elle a coché
        foreach ([['sameCompany' => true], ['sameCompany' => true, 'sameCompanyConsent' => 'g1d-v0'], ['sameCompany' => 'oui', 'sameCompanyConsent' => GroupCompanyService::CONSENT_VERSION]] as $extra) {
            $this->assertSame(422, $this->refusal('POST', "/api/site/group-invitations/$id/accept", ['consent' => GroupService::CONSENT_VERSION] + $extra, 'boutique')[0]);
        }
        $this->assertSame(0, (int) $this->master->query("SELECT COUNT(*) FROM shop_group_sites WHERE tenant_code = '" . MV_TEST_TENANT2_CODE . "'")->fetchColumn());

        $this->call('POST', "/api/site/group-invitations/$id/accept", ['consent' => GroupService::CONSENT_VERSION, 'sameCompany' => true, 'sameCompanyConsent' => GroupCompanyService::CONSENT_VERSION], 'boutique', 200);
        $this->assertContains("g1d-siege-{$this->tag}@example.invalid", $this->mailedTo(), 'le siège apprend le lien posé à l\'acceptation');
        $this->assertSame(['confirmed', 'confirmed'], array_map(static fn (array $s): string => $s['sameCompany']['state'], $this->call('GET', '/api/group', null, 'siege', 200)['shops']));
        $this->assertSame([true, true], [$this->call('GET', '/api/site/group-same-company', null, 'boutique', 200)['sameCompany'], (bool) $this->master->query("SELECT same_company FROM shop_group_sites WHERE tenant_code = '" . MV_TEST_TENANT2_CODE . "'")->fetchColumn()]);
        $this->assertArrayNotHasKey('sameCompany', $this->call('GET', '/api/group', null, 'boutique', 200)['shops'][0], 'la boutique ne lit pas l\'état des autres');
        foreach ([MV_TEST_TENANT_DB, MV_TEST_TENANT2_DB] as $db) {
            $this->assertSame(['same_company_set_at_accept'], $this->journal($db), 'tracé au journal des deux sites');
        }
    }

    public function testTheShopConfirmsItselfAndRemovesAloneAndTheDirectorIsTold(): void
    {
        $id = $this->invited();
        $this->call('POST', "/api/site/group-invitations/$id/accept", ['consent' => GroupService::CONSENT_VERSION], 'boutique', 200);
        $path = '/api/group/shops/' . MV_TEST_TENANT2_CODE . '/same-company';

        $this->assertSame([403, 'GROUP_HEAD_ONLY'], $this->refusal('POST', '/api/group/shops/' . MV_TEST_TENANT_CODE . '/same-company', null, 'boutique'), 'la boutique ne demande pas');
        $this->assertSame([404, 'GROUP_SHOP_UNKNOWN'], $this->refusal('POST', '/api/group/shops/ailleurs-' . $this->tag . '/same-company', null, 'siege'));
        $this->assertSame(['asked' => true, 'expiresInDays' => GroupCompanyService::REQUEST_DAYS], $this->call('POST', $path, null, 'siege', 202));
        $mailed = $this->mailedTo(); // les courriels de LA dernière requête
        $this->assertContains("g1d-boutique-{$this->tag}@example.invalid", $mailed, 'courriel à l\'administrateur de la boutique');
        $this->assertNotContains("g1d-plateforme-{$this->tag}@example.invalid", $mailed);
        $this->assertNotContains("g1d-employe-{$this->tag}@example.invalid", $mailed);
        $this->assertSame([409, 'SAME_COMPANY_PENDING'], $this->refusal('POST', $path, null, 'siege'), 'une seule demande en attente');
        $this->assertSame('pending', $this->shopState());

        $request = $this->call('GET', '/api/site/group-same-company', null, 'boutique', 200)['request'];
        $this->assertSame(GroupCompanyService::CONSENT_VERSION, $request['consentVersion']);
        $confirm = '/api/site/group-same-company/' . $request['id'] . '/confirm';
        $this->assertSame([403, 'GROUP_FORBIDDEN'], $this->refusal('POST', $confirm, ['consent' => GroupCompanyService::CONSENT_VERSION], 'plateforme'), 'jamais le compte de la plateforme');
        $this->assertSame(403, $this->raw('POST', $confirm, ['consent' => GroupCompanyService::CONSENT_VERSION], 'employe')->getStatusCode(), 'administrateur seulement');
        $this->assertSame([422, 'GROUP_CONSENT_REQUIRED'], $this->refusal('POST', $confirm, ['consent' => 'g1d-v0'], 'boutique'));
        $this->assertSame('pending', $this->shopState(), 'rien n\'est posé sans le texte');

        $this->assertTrue($this->call('POST', $confirm, ['consent' => GroupCompanyService::CONSENT_VERSION], 'boutique', 200)['sameCompany']);
        $this->assertContains("g1d-siege-{$this->tag}@example.invalid", $this->mailedTo(), 'le directeur apprend la confirmation');
        $this->assertSame('confirmed', $this->shopState());
        $this->assertSame([404, 'SAME_COMPANY_REQUEST_NOT_FOUND'], $this->refusal('POST', $confirm, ['consent' => GroupCompanyService::CONSENT_VERSION], 'boutique'), 'déjà traitée');
        $this->assertSame([409, 'SAME_COMPANY_ALREADY'], $this->refusal('POST', $path, null, 'siege'));

        // la boutique retire SEULE, tout de suite ; le directeur est prévenu
        $this->assertFalse($this->call('DELETE', '/api/site/group-same-company', null, 'boutique', 200)['sameCompany']);
        $this->assertContains("g1d-siege-{$this->tag}@example.invalid", $this->mailedTo(), 'le directeur apprend le retrait');
        $this->assertSame('none', $this->shopState());
        $this->assertSame([409, 'SAME_COMPANY_NOT_SET'], $this->refusal('DELETE', '/api/site/group-same-company', null, 'boutique'));
        $this->assertSame(['same_company_requested', 'same_company_confirmed', 'same_company_removed'], $this->events());
        $this->assertSame(['same_company_requested', 'same_company_confirmed', 'same_company_removed'], $this->journal(MV_TEST_TENANT2_DB));
        $this->assertSame('shop', $this->master->query("SELECT payload->>'side' FROM shop_group_events WHERE event = 'same_company_removed'")->fetchColumn());

        // refus par la boutique, puis retrait d'une demande par le directeur, puis lien posé et retiré par le directeur
        $this->call('POST', $path, null, 'siege', 202);
        $second = $this->call('GET', '/api/site/group-same-company', null, 'boutique', 200)['request']['id'];
        $this->assertSame(204, $this->raw('POST', "/api/site/group-same-company/$second/refuse", null, 'boutique')->getStatusCode());
        $this->assertSame('none', $this->shopState());
        $this->call('POST', $path, null, 'siege', 202);
        $this->assertSame('none', $this->call('DELETE', $path, null, 'siege', 200)['sameCompany']['state'], 'le directeur retire sa demande');
        $this->call('POST', $path, null, 'siege', 202);
        $third = $this->call('GET', '/api/site/group-same-company', null, 'boutique', 200)['request']['id'];
        $this->call('POST', "/api/site/group-same-company/$third/confirm", ['consent' => GroupCompanyService::CONSENT_VERSION], 'boutique', 200);
        $this->assertSame('none', $this->call('DELETE', $path, null, 'siege', 200)['sameCompany']['state']);
        $this->assertSame('head', $this->master->query("SELECT payload->>'side' FROM shop_group_events WHERE event = 'same_company_removed' ORDER BY id DESC LIMIT 1")->fetchColumn());
    }

    public function testExpiryLeavingTheGroupAndAnEnteredDirectorNeverConfirm(): void
    {
        $id = $this->invited();
        $this->call('POST', "/api/site/group-invitations/$id/accept", ['consent' => GroupService::CONSENT_VERSION], 'boutique', 200);
        $path = '/api/group/shops/' . MV_TEST_TENANT2_CODE . '/same-company';
        $this->call('POST', $path, null, 'siege', 202);
        $request = (int) $this->call('GET', '/api/site/group-same-company', null, 'boutique', 200)['request']['id'];

        // le directeur entré dans la boutique ne confirme JAMAIS à sa place
        $entered = json_decode((string) $this->raw('POST', '/api/me/contexts/enter', ['site' => MV_TEST_TENANT2_CODE], 'siege')->getContent(), true);
        $this->assertIsString($entered['token'] ?? null, 'le directeur entre dans la boutique');
        $this->tokens['entre'] = (string) $entered['token'];
        $this->assertSame(403, $this->raw('POST', "/api/site/group-same-company/$request/confirm", ['consent' => GroupCompanyService::CONSENT_VERSION], 'entre')->getStatusCode());
        $this->assertSame(403, $this->raw('DELETE', '/api/site/group-same-company', null, 'entre')->getStatusCode());
        $this->assertSame(403, $this->raw('GET', '/api/site/group-same-company', null, 'entre')->getStatusCode(), 'ni même la lecture (refusée par le contrôleur : la garde du mode ne ferme que les écritures)');
        $this->assertSame('pending', $this->shopState());

        // échue : introuvable, et une nouvelle demande est possible
        $this->master->exec("UPDATE shop_group_company_requests SET expires_at = NOW() - INTERVAL '1 minute' WHERE id = $request");
        $this->assertNull($this->call('GET', '/api/site/group-same-company', null, 'boutique', 200)['request']);
        $this->assertSame([404, 'SAME_COMPANY_REQUEST_NOT_FOUND'], $this->refusal('POST', "/api/site/group-same-company/$request/confirm", ['consent' => GroupCompanyService::CONSENT_VERSION], 'boutique'));
        $this->call('POST', $path, null, 'siege', 202);
        $this->assertSame('expired', $this->master->query("SELECT state FROM shop_group_company_requests WHERE id = $request")->fetchColumn());

        // quitter le groupe close la demande ; revenir : le lien repart de zéro
        $pending = (int) $this->call('GET', '/api/site/group-same-company', null, 'boutique', 200)['request']['id'];
        $this->call('POST', '/api/group/leave', null, 'boutique', 200);
        $this->assertSame('cancelled', $this->master->query("SELECT state FROM shop_group_company_requests WHERE id = $pending")->fetchColumn());
        $this->master->exec("UPDATE shop_group_sites SET same_company = TRUE WHERE tenant_code = '" . MV_TEST_TENANT2_CODE . "' AND left_at IS NOT NULL"); // un ancien lien posé
        $this->call('POST', '/api/group/invitations', ['site' => MV_TEST_TENANT2_CODE], 'siege', 202);
        $again = $this->call('GET', '/api/site/group-invitations', null, 'boutique', 200)['invitations'][0]['id'];
        $this->call('POST', "/api/site/group-invitations/$again/accept", ['consent' => GroupService::CONSENT_VERSION], 'boutique', 200);
        $this->assertSame('none', $this->shopState(), 'le lien neuf repart à zéro');
    }

    public function testTheCostCrossesOnlyBetweenShopsOfTheSameCompany(): void
    {
        $id = $this->invited();
        $this->call('POST', "/api/site/group-invitations/$id/accept", ['consent' => GroupService::CONSENT_VERSION], 'boutique', 200);
        $pid = $this->product(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $shopPid = $this->product(MV_TEST_TENANT2_DB, MV_TEST_TENANT2_CODE);

        // relecture G-1d : une associée sans lien comme SOURCE n'envoie jamais son coût au siège
        $back = $this->call('POST', '/api/gestion/groupe/copies', ['source' => MV_TEST_TENANT2_CODE, 'target' => MV_TEST_TENANT_CODE, 'articles' => [$shopPid]], 'siege', 201);
        $this->assertSame([422, 'COPY_COST_ASSOCIATED_SHOP'], $this->refusal('POST', '/api/gestion/groupe/copies/' . $back['id'] . '/confirm', ['withCost' => true], 'siege'));
        $this->call('POST', '/api/gestion/groupe/copies/' . $back['id'] . '/cancel', null, 'siege', 200);

        // sans le lien : withCost refusé ; par défaut, sans coût
        $copy = $this->call('POST', '/api/gestion/groupe/copies', ['target' => MV_TEST_TENANT2_CODE, 'articles' => [$pid]], 'siege', 201);
        $this->assertSame([422, 'COPY_COST_ASSOCIATED_SHOP'], $this->refusal('POST', '/api/gestion/groupe/copies/' . $copy['id'] . '/confirm', ['withCost' => true], 'siege'));
        $this->assertFalse($this->call('POST', '/api/gestion/groupe/copies/' . $copy['id'] . '/confirm', [], 'siege', 202)['withCost']);
        $this->runCopy((int) $copy['import']['id']);
        $this->assertNull($this->copiedCost(), 'aucun coût vers une boutique associée sans le lien');
        $this->call('POST', '/api/gestion/groupe/copies/' . $copy['id'] . '/cancel', null, 'siege', 200);

        // avec le lien : le coût du siège passe, relu à la confirmation (jamais figé à l'aperçu vers une associée)
        $this->link();
        $copy = $this->call('POST', '/api/gestion/groupe/copies', ['target' => MV_TEST_TENANT2_CODE, 'articles' => [$pid]], 'siege', 201);
        $frozen = array_values(array_filter($this->groupJson(MV_TEST_TENANT2_DB)));
        $this->assertNotSame([], $frozen, 'le catalogue figé de la copie est lu');
        $this->assertSame([''], array_values(array_unique(array_map(static fn (array $p): string => (string) ($p['variants'][0]['costRaw'] ?? ''), end($frozen)))),
            'le coût du siège n\'est jamais figé chez une associée, même liée');
        $this->assertTrue($this->call('POST', '/api/gestion/groupe/copies/' . $copy['id'] . '/confirm', [], 'siege', 202)['withCost']);
        $this->runCopy((int) $copy['import']['id']);
        $this->assertSame(1234.0, $this->copiedCost());
        $this->call('POST', '/api/gestion/groupe/copies/' . $copy['id'] . '/cancel', null, 'siege', 200);

        // une associée LIÉE comme source : withCost relit son coût et l'écrit au siège
        $back = $this->call('POST', '/api/gestion/groupe/copies', ['source' => MV_TEST_TENANT2_CODE, 'target' => MV_TEST_TENANT_CODE, 'articles' => [$shopPid]], 'siege', 201);
        $this->assertTrue($this->call('POST', '/api/gestion/groupe/copies/' . $back['id'] . '/confirm', ['withCost' => true], 'siege', 202)['withCost']);
        $this->runCopy((int) $back['import']['id'], MV_TEST_TENANT_CODE);
        $this->assertSame(1234.0, $this->copiedCost(MV_TEST_TENANT_DB, 'Copie g1d boutique'));
        $this->call('POST', '/api/gestion/groupe/copies/' . $back['id'] . '/cancel', null, 'siege', 200);

        // lien retiré entre l'aperçu et la confirmation : relu, refusé
        $copy = $this->call('POST', '/api/gestion/groupe/copies', ['target' => MV_TEST_TENANT2_CODE, 'articles' => [$pid]], 'siege', 201);
        $this->call('DELETE', '/api/site/group-same-company', null, 'boutique', 200);
        $this->assertSame([422, 'COPY_COST_ASSOCIATED_SHOP'], $this->refusal('POST', '/api/gestion/groupe/copies/' . $copy['id'] . '/confirm', ['withCost' => true], 'siege'));
        $this->assertFalse($this->call('POST', '/api/gestion/groupe/copies/' . $copy['id'] . '/confirm', [], 'siege', 202)['withCost']);
    }

    /** Relecture G-1d, passe 2 : une demande faite AVANT l'entrée actuelle de la boutique (course avec une sortie) n'est jamais confirmable */
    public function testARequestOlderThanTheShopsReturnIsNeverConfirmable(): void
    {
        $id = $this->invited();
        $this->call('POST', "/api/site/group-invitations/$id/accept", ['consent' => GroupService::CONSENT_VERSION], 'boutique', 200);
        $path = '/api/group/shops/' . MV_TEST_TENANT2_CODE . '/same-company';
        $this->call('POST', $path, null, 'siege', 202);
        $request = (int) $this->master->query("SELECT id FROM shop_group_company_requests WHERE state = 'pending'")->fetchColumn();
        // la demande est datée d'avant l'entrée actuelle (ce que laisse une demande croisée avec une sortie puis un retour)
        $this->master->exec("UPDATE shop_group_company_requests SET requested_at = (SELECT joined_at FROM shop_group_sites WHERE tenant_code = '" . MV_TEST_TENANT2_CODE . "' AND left_at IS NULL) - INTERVAL '1 minute' WHERE id = $request");
        $this->assertNull($this->call('GET', '/api/site/group-same-company', null, 'boutique', 200)['request'], 'jamais montrée à la boutique');
        $this->assertSame('none', $this->shopState(), 'ni au siège');
        $this->assertSame([404, 'SAME_COMPANY_REQUEST_NOT_FOUND'], $this->refusal('POST', "/api/site/group-same-company/$request/confirm", ['consent' => GroupCompanyService::CONSENT_VERSION], 'boutique'));
        $this->assertSame([404, 'SAME_COMPANY_REQUEST_NOT_FOUND'], $this->refusal('POST', "/api/site/group-same-company/$request/refuse", null, 'boutique'));
        $this->assertFalse((bool) $this->master->query("SELECT same_company FROM shop_group_sites WHERE tenant_code = '" . MV_TEST_TENANT2_CODE . "' AND left_at IS NULL")->fetchColumn());
        // le directeur peut demander de nouveau : l'ancienne est close
        $this->call('POST', $path, null, 'siege', 202);
        $this->assertSame('cancelled', $this->master->query("SELECT state FROM shop_group_company_requests WHERE id = $request")->fetchColumn());
        $this->assertSame('pending', $this->shopState());
    }

    /** Relecture G-1d, passe 2 : l'assistant DEMANDE le lien (confirmé par le directeur) ; le coût qu'il annonce est celui qui part */
    public function testTheAssistantAsksTheLinkAndKeepsTheCostItAnnounced(): void
    {
        $id = $this->invited();
        $this->call('POST', "/api/site/group-invitations/$id/accept", ['consent' => GroupService::CONSENT_VERSION], 'boutique', 200);
        $pid = $this->product(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);

        // la copie proposée SANS le lien : « sans le coût d'achat »
        $copy = $this->assistant([['propose_group_copy', ['target' => MV_TEST_TENANT2_CODE, 'articles' => [$pid]]]]);
        $this->assertStringContainsString('sans le coût d\'achat', $copy['summary']);
        // la demande du lien par l'assistant : rien n'est demandé avant la confirmation du directeur ; la boutique confirme elle-même
        $ask = $this->assistant([['propose_group_same_company', ['site' => MV_TEST_TENANT2_CODE]]]);
        $this->assertSame('none', $this->shopState(), 'rien avant la confirmation');
        $this->call('POST', '/api/site/assistant/actions/' . $ask['id'] . '/confirm', [], 'siege', 200);
        $this->assertSame('pending', $this->shopState(), 'la demande part ; la boutique reste seule juge');
        $request = $this->call('GET', '/api/site/group-same-company', null, 'boutique', 200)['request']['id'];
        $this->call('POST', "/api/site/group-same-company/$request/confirm", ['consent' => GroupCompanyService::CONSENT_VERSION], 'boutique', 200);
        // le lien est maintenant posé : la copie confirmée garde ce qui a été annoncé, sans le coût
        $done = $this->call('POST', '/api/site/assistant/actions/' . $copy['id'] . '/confirm', [], 'siege', 200);
        $this->assertFalse($done['result']['withCost'], 'le coût annoncé (aucun) est celui qui part');
    }

    /** @param list<array{0: string, 1: array<string, mixed>}> $uses @return array<string, mixed> l'action proposée */
    private function assistant(array $uses): array
    {
        FakeLandingAiClient::reset();
        $content = [];
        foreach ($uses as $i => [$name, $input]) {
            $content[] = (object) ['type' => 'tool_use', 'id' => 'tu_' . $i, 'name' => $name, 'input' => (object) $input];
        }
        FakeLandingAiClient::$queue[] = (object) ['content' => $content, 'usage' => (object) ['input_tokens' => 10, 'output_tokens' => 5]];
        FakeLandingAiClient::$queue[] = (object) ['content' => [(object) ['type' => 'text', 'text' => 'Voici.']], 'usage' => (object) ['input_tokens' => 10, 'output_tokens' => 5]];
        $body = $this->call('POST', '/api/site/assistant', ['messages' => [['role' => 'user', 'content' => 'Mon groupe']]], 'siege', 200);
        $this->assertCount(1, $body['actions'], (string) json_encode(FakeLandingAiClient::$requests[1]['messages'][2]['content'] ?? null));

        return $body['actions'][0];
    }

    /**
     * Lot G-CONSENT (21/11/2026, contrat validé par le chef) : g1d-v2 exigé pour toute nouvelle pose (g1d-v1 refusé) ; un lien posé en g1d-v1
     * le garde ; au retrait du lien, `requested` est annulé, `send_failed` fermé sans écriture dans les sites, `sending` et `sent` intacts.
     */
    public function testTheNewTextIsRequiredOldLinksKeepTheirsAndRemovalClosesOnlyRequestedAndFailedTransfers(): void
    {
        $this->assertSame('g1d-v2', GroupCompanyService::CONSENT_VERSION);
        $this->assertStringContainsString('les transferts encore seulement demandés seront alors annulés', GroupCompanyService::consent('Groupe', 'fr'));
        $this->assertStringContainsString('transfers that are only requested are then cancelled', GroupCompanyService::consent('Group', 'en'));
        $id = $this->invited();
        $this->assertSame([422, 'GROUP_CONSENT_REQUIRED'], $this->refusal('POST', "/api/site/group-invitations/$id/accept", ['consent' => GroupService::CONSENT_VERSION, 'sameCompany' => true, 'sameCompanyConsent' => 'g1d-v1'], 'boutique'), 'l\'ancienne version à l\'acceptation');
        $this->call('POST', "/api/site/group-invitations/$id/accept", ['consent' => GroupService::CONSENT_VERSION], 'boutique', 200);
        $this->call('POST', '/api/group/shops/' . MV_TEST_TENANT2_CODE . '/same-company', null, 'siege', 202);
        $request = $this->call('GET', '/api/site/group-same-company', null, 'boutique', 200)['request'];
        $this->assertSame('g1d-v2', $request['consentVersion']);
        $this->assertSame([422, 'GROUP_CONSENT_REQUIRED'], $this->refusal('POST', "/api/site/group-same-company/{$request['id']}/confirm", ['consent' => 'g1d-v1'], 'boutique'), 'l\'ancienne version à la confirmation');
        $this->call('POST', "/api/site/group-same-company/{$request['id']}/confirm", ['consent' => 'g1d-v2'], 'boutique', 200);
        $site = "tenant_code = '" . MV_TEST_TENANT2_CODE . "' AND left_at IS NULL";
        $this->assertSame('g1d-v2', $this->master->query("SELECT same_company_consent_version FROM shop_group_sites WHERE $site")->fetchColumn());

        // un lien posé avant le lot garde sa version : rien ne la réécrit, il reste « confirmé »
        $this->master->exec("UPDATE shop_group_sites SET same_company_consent_version = 'g1d-v1' WHERE $site");
        $this->assertSame('confirmed', $this->call('GET', '/api/group', null, 'siege', 200)['shops'][1]['sameCompany']['state']);
        $this->assertSame('g1d-v1', $this->master->query("SELECT same_company_consent_version FROM shop_group_sites WHERE $site")->fetchColumn());

        // un transfert dans chaque état, dans les deux sens
        $group = (int) $this->master->query('SELECT id FROM shop_groups')->fetchColumn();
        $transfers = [];
        foreach (['requested' => [MV_TEST_TENANT2_CODE, MV_TEST_TENANT_CODE, 0], 'send_failed' => [MV_TEST_TENANT_CODE, MV_TEST_TENANT2_CODE, 1],
            'sending' => [MV_TEST_TENANT2_CODE, MV_TEST_TENANT_CODE, 1], 'sent' => [MV_TEST_TENANT_CODE, MV_TEST_TENANT2_CODE, 1]] as $state => [$source, $target, $attempt]) {
            $stmt = $this->master->prepare('INSERT INTO shop_group_transfers (uid, group_id, source_tenant_code, target_tenant_code, state, attempt) VALUES (?, ?, ?, ?, ?, ?) RETURNING id');
            $stmt->execute(["g1d-$state-{$this->tag}", $group, $source, $target, $state, $attempt]);
            $transfers[$state] = (int) $stmt->fetchColumn();
        }
        $this->assertFalse($this->call('DELETE', '/api/site/group-same-company', null, 'boutique', 200)['sameCompany']);
        $states = [];
        foreach ($transfers as $before => $tid) {
            $states[$before] = $this->master->query("SELECT state FROM shop_group_transfers WHERE id = $tid")->fetchColumn();
        }
        $this->assertSame(['requested' => 'cancelled', 'send_failed' => 'cancelled', 'sending' => 'sending', 'sent' => 'sent'], $states);
        $this->assertSame(['requested', 'send_failed'], $this->master->query("SELECT payload::json->>'from' FROM shop_group_transfer_events WHERE event = 'cancelled' ORDER BY transfer_id")->fetchAll(\PDO::FETCH_COLUMN));
        $this->assertSame('2', $this->master->query("SELECT payload::json->>'count' FROM shop_group_events WHERE event = 'transfers_cancelled'")->fetchColumn());
    }

    private function invited(): int
    {
        $this->module(true);
        $this->call('POST', '/api/group', null, 'siege', 201);
        $this->call('POST', '/api/group/invitations', ['site' => MV_TEST_TENANT2_CODE], 'siege', 202);

        return (int) $this->call('GET', '/api/site/group-invitations', null, 'boutique', 200)['invitations'][0]['id'];
    }

    private function link(): void
    {
        $this->call('POST', '/api/group/shops/' . MV_TEST_TENANT2_CODE . '/same-company', null, 'siege', 202);
        $request = $this->call('GET', '/api/site/group-same-company', null, 'boutique', 200)['request']['id'];
        $this->call('POST', "/api/site/group-same-company/$request/confirm", ['consent' => GroupCompanyService::CONSENT_VERSION], 'boutique', 200);
    }

    private function shopState(): string
    {
        foreach ($this->call('GET', '/api/group', null, 'siege', 200)['shops'] as $shop) {
            if ($shop['code'] === MV_TEST_TENANT2_CODE) {
                return (string) $shop['sameCompany']['state'];
            }
        }

        return 'absent';
    }

    /** @return list<string> */
    private function events(): array
    {
        return $this->master->query("SELECT event FROM shop_group_events WHERE event LIKE 'same_company%' ORDER BY id")->fetchAll(\PDO::FETCH_COLUMN);
    }

    /** @return list<string> les événements du lien au journal du site (« Accès extérieurs ») */
    private function journal(string $db): array
    {
        return array_map(static fn (string $json): string => (string) (json_decode($json, true)['event'] ?? ''),
            $this->pdo($db)->query("SELECT after_state FROM content_audit_log WHERE resource = 'external_access' AND action = 'same_company' ORDER BY id")->fetchAll(\PDO::FETCH_COLUMN));
    }

    /** @return list<string> */
    private function mailedTo(): array
    {
        $to = [];
        foreach (self::getMailerMessages() as $mail) {
            foreach ($mail->getTo() as $address) {
                $to[] = $address->getAddress();
            }
        }

        return $to;
    }

    private function product(string $db, string $code): int
    {
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant($db, $code);
        $em = $provider->getEntityManager();
        $label = $db === MV_TEST_TENANT_DB ? 'Copie g1d ' : 'Copie g1d boutique ';
        $product = (new Product())->setName($label . $this->tag)->setDescription('<p>Un article</p>')->setMoreinformations(null)->setPrice(2500.0)->setCode(($db === MV_TEST_TENANT_DB ? 'G1D-' : 'G1DB-') . $this->tag)
            ->setSlug(($db === MV_TEST_TENANT_DB ? 'copie-g1d-' : 'copie-g1d-boutique-') . $this->tag)->setTags(null)->setQuantity(5)->setIsWeb(true)->setIsPos(true)->setIsLandingPage(false)->setSaleEnabled(true)->setRentalEnabled(false)
            ->setSubscriptionEnabled(false)->setIsbestseller(false)->setIsnewarrival(false)->setIsfeatured(false)->setIsspecialoffer(false)->setIsPreOrder(false)->setIsAccessory(false)
            ->setCustomizable(false)->setRequiresShipping(true)->setCreatedAt(new \DateTimeImmutable())->setPurchasePrice(1234.0);
        $em->persist($product);
        $variant = (new ProductVariant())->setStockQuantity(3)->setPrice(2500);
        $product->addVariant($variant);
        $em->persist($variant);
        $em->flush();
        $id = (int) $product->getId();
        $em->clear();
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);

        return $id;
    }

    private function copiedCost(string $db = MV_TEST_TENANT2_DB, string $label = 'Copie g1d'): ?float
    {
        // la COPIE (la plus récente) : dans la base cible, l'article copié porte le nom de la source
        $cost = $this->pdo($db)->query("SELECT purchase_price FROM product WHERE name = '$label {$this->tag}' ORDER BY id DESC LIMIT 1")->fetchColumn();

        return $cost === false || $cost === null ? null : (float) $cost;
    }

    /** @return list<?array<mixed>> le catalogue figé de chaque copie du groupe reçue par ce site */
    private function groupJson(string $db): array
    {
        $code = $db === MV_TEST_TENANT_DB ? MV_TEST_TENANT_CODE : MV_TEST_TENANT2_CODE;
        $dir = static::getContainer()->getParameter('kernel.project_dir') . '/var/storage/private_media/imports/' . $code;
        $out = [];
        foreach ($this->pdo($db)->query("SELECT stored_name FROM import_job WHERE source = 'group' ORDER BY id")->fetchAll(\PDO::FETCH_COLUMN) as $name) {
            $path = $dir . '/' . basename((string) $name);
            $out[] = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        }

        return $out;
    }

    private function runCopy(int $jobId, string $code = MV_TEST_TENANT2_CODE): void
    {
        $container = static::getContainer();
        $container->get(RunCatalogImportHandler::class)(new RunCatalogImportMessage($jobId, $code, 'essai'));
        $container->get(TenantEntityManagerProvider::class)->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
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
        $host = in_array($who, ['siege'], true) ? MV_TEST_TENANT_HOST : MV_TEST_TENANT2_HOST;
        $this->client->getCookieJar()->clear();
        $this->client->request($method, 'https://' . $host . $path, [], [], ['HTTP_X_TENANT_HOST' => $host, 'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->tokens[$who], 'REMOTE_ADDR' => '10.93.' . random_int(1, 254) . '.' . random_int(1, 254)], $body === null ? (in_array($method, ['POST', 'DELETE'], true) ? '{}' : null) : json_encode($body));

        return $this->client->getResponse();
    }

    /** @param list<string> $roles */
    private function token(string $db, string $code, string $name, array $roles, string $firstName): string
    {
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant($db, $code);
        $em = $provider->getEntityManager();
        $email = "g1d-$name-{$this->tag}@example.invalid";
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname($firstName)->setLastname('Essai')->setRoles($roles)->setPassword('inutilisable')->setIsVerified(true);
        $em->persist($user);
        $em->flush();
        // une session vivante (l'entrée du directeur l'exige)
        $em->getConnection()->executeStatement("INSERT INTO refresh_tokens (id, refresh_token, username, valid) VALUES (nextval('refresh_tokens_id_seq'), ?, ?, NOW() + INTERVAL '1 day')", ['g1d-' . bin2hex(random_bytes(30)), $email]);
        $token = static::getContainer()->get('lexik_jwt_authentication.jwt_manager')->create($user);
        $em->clear();
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);

        return $token;
    }

    private function clean(): void
    {
        foreach ([MV_TEST_TENANT_DB, MV_TEST_TENANT2_DB] as $db) {
            $pdo = $this->pdo($db);
            $ids = array_map('intval', $pdo->query("SELECT id FROM product WHERE name LIKE 'Copie g1d %'")->fetchAll(\PDO::FETCH_COLUMN));
            if ($ids !== []) {
                $list = implode(',', $ids);
                foreach (['product_picture', 'product_translation', 'related_product'] as $table) {
                    $pdo->exec("DELETE FROM $table WHERE product_id IN ($list)");
                }
                $pdo->exec("DELETE FROM inventory_movements WHERE product_variant_id IN (SELECT id FROM product_variant WHERE product_id IN ($list))");
                $pdo->exec("DELETE FROM product_variant WHERE product_id IN ($list)");
                $pdo->exec("DELETE FROM product_categories WHERE product_id IN ($list)");
                $pdo->exec("DELETE FROM product WHERE id IN ($list)");
            }
            $pdo->exec("DELETE FROM import_item WHERE source = 'group'");
            $pdo->exec("DELETE FROM import_job WHERE source = 'group'");
            $pdo->exec("DELETE FROM notification_log WHERE kind = 'group_catalog_copied'");
            $pdo->exec("DELETE FROM content_audit_log WHERE resource = 'external_access' AND action IN ('same_company', 'entry', 'write', 'return')");
            $pdo->exec("DELETE FROM refresh_tokens WHERE username LIKE 'g1d-%@example.invalid'");
            $pdo->exec("DELETE FROM \"user\" WHERE email LIKE 'g1d-%@example.invalid'");
            $pdo->exec('UPDATE entreprise SET is_multi_shop_active = FALSE');
        }
        $this->master->exec("DELETE FROM site_entries WHERE mode = 'group'");
        foreach (['shop_group_item_links', 'shop_group_copies', 'shop_group_events', 'shop_group_invitations', 'shop_group_company_requests', 'shop_group_transfer_events', 'shop_group_transfer_lines', 'shop_group_transfers', 'shop_group_sites', 'shop_groups'] as $table) {
            $this->master->exec("DELETE FROM $table");
        }
        foreach ([[MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE], [MV_TEST_TENANT2_DB, MV_TEST_TENANT2_CODE]] as [$db, $code]) {
            static::getContainer()->get(TenantCacheService::class)->invalidateSite($code, $db);
        }
    }

    private function pdo(string $db): \PDO
    {
        return static::getContainer()->get(TenantDatabaseConnector::class)->pdo($db);
    }
}
