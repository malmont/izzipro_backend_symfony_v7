<?php

namespace App\Tests\Functional\LossPrevention;

use App\Entity\User;
use App\LossPrevention\Services\LossBoxLimits;
use App\LossPrevention\Services\LossBoxService;
use App\LossPrevention\Services\LossPairingCode;
use App\LossPrevention\Services\LossPairingService;
use App\Services\Platform\PlatformModeService;
use App\Services\TenantConnectionManager;
use App\Services\TenantPublicHostResolver;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Lot L3-a2 (16/11/2026, `docs/prevention-pertes.md` § 16.16) : jumelage d'un boîtier par code. Le boîtier dépose sa demande sans
 * site ni jeton ; un administrateur le jumelle à SON site ; le boîtier, sur la preuve de sa machine, reçoit son jeton.
 * Trois choses d'abord : personne ne jumelle un boîtier à un autre site que le sien ; un code volé ne détourne pas un boîtier déjà
 * jumelé ; aucun code ne se devine à la chaîne.
 */
class LossPreventionPairingTest extends LossPreventionTestCase
{
    private const BOXES = self::BASE . '/boitiers';
    /** Adresse de la plateforme : aucun site ne porte ce nom, et les deux routes du jumelage n'en résolvent aucun */
    private const PLATFORM = 'boitiers.example.invalid';
    private const MACHINE = 'machine-jumelee-0123456789abcdef';
    private const NEUTRAL = ['error' => 'PAIRING_CODE_INVALID', 'code' => 'PAIRING_CODE_INVALID', 'message' => 'Code de jumelage inconnu.'];

    private string $s;
    private string $ip;
    private \PDO $master;

    protected function setUp(): void
    {
        parent::setUp();
        $this->s = strtoupper(bin2hex(random_bytes(3)));
        $this->ip = self::address();
        $this->flags(true, true);
        $this->master = static::getContainer()->get(TenantConnectionManager::class)->getPdoMaster();
        $this->master->exec('DELETE FROM loss_box_pairing');
        self::resetPairingSiteLimit();
    }

    protected function tearDown(): void
    {
        try {
            $this->master->exec('DELETE FROM loss_box_pairing');
            self::resetPairingSiteLimit();
            foreach ([[MV_TEST_TENANT2_DB, MV_TEST_TENANT2_CODE], [MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE]] as [$db, $code]) {
                $this->provider->switchTenant($db, $code);
                $this->provider->getEntityManager()->getConnection()->executeStatement("UPDATE loss_box SET status = 'revoked', revoked_at = NOW() WHERE NOT exercise AND status <> 'revoked'");
            }
        } finally {
            parent::tearDown();
        }
    }

    public function testABoxIsPairedByItsCodeToTheSiteOfTheAdministratorAndToNoOther(): void
    {
        $code = LossPairingCode::generate();
        $request = ['code' => $code, 'machine' => self::MACHINE, 'version' => '1.0.0', 'arch' => 'arm64'];
        $proof = ['code' => $code, 'machine' => self::MACHINE];

        // Le boîtier dépose sa demande : sans site, sans jeton. Ce qu'il envoie est vérifié
        $this->assertSame(['machine' => 'REQUIRED', 'version' => 'REQUIRED', 'arch' => 'REQUIRED', 'code' => 'REQUIRED'], $this->json($this->box('', []), 422)['fields']);
        $this->assertSame(['machine' => 'INVALID_VALUE', 'arch' => 'INVALID_VALUE', 'code' => 'INVALID_VALUE'],
            $this->json($this->box('', ['code' => 'KX7-42P-9Q0', 'machine' => 'court', 'version' => '1.0.0', 'arch' => 'mips']), 422)['fields']);
        $this->assertSame(['machine' => 'REQUIRED', 'code' => 'INVALID_VALUE'], $this->json($this->box('/etat', ['code' => 'IZ-BOX-AAAA-AAAA-AAAA']), 422)['fields']);
        $this->assertSame(0, $this->requests());
        $waiting = $this->json($this->box('', $request));
        $this->assertSame(['status', 'expiresAt', 'pollSeconds', 'serverTime'], array_keys($waiting));
        $this->assertSame(['waiting', LossPairingService::POLL_SECONDS], [$waiting['status'], $waiting['pollSeconds']]);
        $this->assertEqualsWithDelta(time() + LossPairingService::WAIT_SECONDS, strtotime($waiting['expiresAt']), 5);
        // la base ne garde ni le code ni la machine : leurs empreintes
        $row = $this->row($code);
        $stored = json_encode($this->master->query('SELECT * FROM loss_box_pairing')->fetchAll(\PDO::FETCH_ASSOC));
        $this->assertSame(['waiting', null, null, 'arm64', '1.0.0'], [$row['status'], $row['tenant_code'], $row['box_id'], $row['arch'], $row['version']]);
        foreach ([$code, str_replace('-', '', $code), self::MACHINE] as $secret) {
            $this->assertStringNotContainsString($secret, $stored);
        }
        // saisie tolérante : minuscules, sans tirets, avec des espaces ; c'est le même code
        $this->assertSame('waiting', $this->json($this->box('/etat', ['code' => ' ' . strtolower(str_replace('-', ' ', $code)) . ' ', 'machine' => self::MACHINE]))['status']);
        $this->assertSame(1, $this->requests());

        // Le code seul ne donne rien : sans la preuve de la machine, le refus d'un code inconnu, mot pour mot
        $other = LossPairingCode::generate();
        $this->assertSame(self::NEUTRAL, $this->json($this->box('/etat', ['code' => $other, 'machine' => self::MACHINE]), 404), 'un code inconnu');
        $this->assertSame(self::NEUTRAL, $this->json($this->box('/etat', ['code' => $code, 'machine' => 'une-autre-machine-0123456789']), 404), 'le bon code, une autre machine');
        // et aucune autre machine ne prend un code déjà déposé
        $this->assertSame(self::NEUTRAL, $this->json($this->box('', ['machine' => 'une-autre-machine-0123456789'] + $request), 404));
        $this->assertSame($row['machine_hash'], $this->row($code)['machine_hash']);
        // le même boîtier redépose (il a redémarré) : la même demande, toujours en attente
        $this->assertSame(['waiting', 1], [$this->json($this->box('', ['version' => '1.0.1'] + $request))['status'], $this->requests()]);
        $this->assertSame('1.0.1', $this->row($code)['version']);

        // L'administrateur jumelle : le nom d'abord, puis le code. Un code inconnu, mal formé ou expiré : le même refus
        $refusal = fn (array $body, int $status = 404, ?string $token = null, string $host = MV_TEST_TENANT_HOST): array => $this->json($this->pair($body, $token ?? $this->adminToken, $host), $status);
        $this->assertSame(['name' => 'REQUIRED'], $refusal(['code' => $code], 422)['fields']);
        $unknown = $refusal(['code' => $other, 'name' => 'Comptoir']);
        $this->assertSame(['PAIRING_CODE_INVALID', 'Code inconnu, ou boîtier pas encore branché à Internet. Branchez le boîtier, attendez que son voyant clignote, puis réessayez.'], [$unknown['error'], $unknown['message']]);
        foreach ([[], ['code' => null], ['code' => 'pas-un-code'], ['code' => 'IZ-BOX-AAAA-AAAA-AAAA'], ['code' => str_repeat('A', 200)]] as $bad) {
            $this->assertSame($unknown, $refusal($bad + ['name' => 'Comptoir']), json_encode($bad));
        }
        $this->master->exec("UPDATE loss_box_pairing SET expires_at = NOW() AT TIME ZONE 'UTC' - INTERVAL '1 second'");
        $this->assertSame($unknown, $refusal(['code' => $code, 'name' => 'Comptoir']), 'une demande expirée : le boîtier ne s\'est plus manifesté depuis 30 jours');
        // … mais son code lui reste : une autre machine ne le prend pas, et dès que le boîtier se manifeste la demande revit
        $this->assertSame(self::NEUTRAL, $this->json($this->box('', ['machine' => 'une-autre-machine-0123456789'] + $request), 404));
        $back = $this->json($this->box('/etat', $proof));
        $this->assertSame('waiting', $back['status']);
        $this->assertEqualsWithDelta(time() + LossPairingService::WAIT_SECONDS, strtotime($back['expiresAt']), 5);
        $seen = $this->row($code)['expires_at'];
        $this->json($this->box('/etat', $proof));
        $this->assertSame($seen, $this->row($code)['expires_at'], 'une question n\'écrit pas à chaque fois : la demande se renouvelle au plus une fois par demi-journée');
        $this->assertSame(0, $this->boxes(), 'rien n\'a encore été créé dans le site');

        // Jumelé : le boîtier est créé dans le site de la SESSION de l'administrateur, il attend sa machine, personne ne voit de clé
        $paired = $this->json($this->pair(['code' => strtolower($code), 'name' => " Comptoir  $this->s ", 'place' => 'Caisse 1']), 201);
        $this->assertSame(['box'], array_keys($paired));
        $box = $paired['box'];
        $this->assertSame(["Comptoir $this->s", 'Caisse 1', 'pending', true, null, false, null], [$box['name'], $box['place'], $box['status'], $box['paired'], $box['keyHint'], $box['online'], $box['enrolledAt']]);
        $this->assertStringNotContainsString($code, json_encode($paired));
        $row = $this->row($code);
        $this->assertSame(['paired', MV_TEST_TENANT_CODE, $box['id']], [$row['status'], $row['tenant_code'], (int) $row['box_id']]);
        $event = $this->db->fetchAssociative('SELECT type, user_id, data FROM loss_box_event WHERE box_id = ? ORDER BY id', [$box['id']]);
        $this->assertSame(['BOX_CREATED', $this->adminId, ['pairing' => true]], [$event['type'], (int) $event['user_id'], json_decode($event['data'], true)]);
        $keyed = $this->keyed();
        $this->assertSame([$box['id'] => true, $keyed => false], array_intersect_key(array_column($this->json($this->admin('GET', self::BOXES))['items'], 'paired', 'id'), [$box['id'] => 1, $keyed => 1]),
            'un boîtier ajouté par sa clé n\'est pas « jumelé »');
        $this->json($this->admin('POST', self::BOXES . "/$keyed/revoquer", ['confirm' => true]));

        // Le code ne sert qu'une fois : ni le même administrateur, ni celui d'un AUTRE site ne le jumelle de nouveau (même refus)
        $this->assertSame($unknown, $refusal(['code' => $code, 'name' => 'Encore']));
        $elsewhere = $this->adminOfSecondSite();
        $this->assertSame($unknown, $refusal(['code' => $code, 'name' => 'Ailleurs'], 404, $elsewhere, MV_TEST_TENANT2_HOST), 'l\'administrateur d\'un autre site');
        $this->assertSame([1, 0, MV_TEST_TENANT_CODE], [$this->boxes(), $this->boxes(2), $this->row($code)['tenant_code']]);

        // Un code volé sur l'étiquette ne détourne rien : sans la preuve de la machine, rien n'est remis, rien n'est inscrit
        $this->assertSame(self::NEUTRAL, $this->json($this->box('/etat', ['code' => $code, 'machine' => 'machine-du-voleur-0123456789abcd']), 404));
        $this->assertSame(self::NEUTRAL, $this->json($this->box('', ['machine' => 'machine-du-voleur-0123456789abcd'] + $request), 404));
        $this->assertSame(404, $this->channel(MV_TEST_TENANT_HOST, 'POST', '/inscription', ['key' => $code] + $request, null)->getStatusCode(), 'le code n\'est pas une clé de boîtier');
        $this->assertSame(404, $this->channel(MV_TEST_TENANT_HOST, 'POST', '/inscription', ['key' => 'pairing|' . $code] + $request, null)->getStatusCode());
        $this->assertSame(['pending', null], [$this->sheet($box['id'])['status'], $this->db->fetchOne('SELECT machine_hash FROM loss_box WHERE id = ?', [$box['id']])]);

        // Le boîtier, sur sa preuve, reçoit l'adresse de son site et son jeton : il est inscrit, dans CE site et lui seul
        $this->assertSame('paired', $this->json($this->box('', $request))['status'], 'redéposer dit seulement où il en est : aucun jeton ici');
        $given = $this->json($this->box('/etat', $proof));
        $this->assertSame(['status', 'host', 'box', 'token', 'expiresAt', 'serverTime', 'settings'], array_keys($given));
        $this->assertSame(['paired', static::getContainer()->get(TenantPublicHostResolver::class)->describe(MV_TEST_TENANT_CODE)['host'], ['id' => $box['id']]], [$given['status'], $given['host'], $given['box']]);
        $this->assertNotNull($given['host']);
        $sheet = $this->sheet($box['id']);
        $this->assertSame(['active', true, '1.0.1', 'arm64', true], [$sheet['status'], $sheet['online'], $sheet['version'], $sheet['arch'], $sheet['paired']]);
        $enrolled = $this->db->fetchAssociative("SELECT user_id, data FROM loss_box_event WHERE box_id = ? AND type = 'BOX_ENROLLED'", [$box['id']]);
        $this->assertSame([null, ['version' => '1.0.1', 'arch' => 'arm64', 'again' => false, 'pairing' => true]], [$enrolled['user_id'], json_decode($enrolled['data'], true)]);
        $this->assertNotNull($this->row($code)['delivered_at']);
        $this->assertSame($box['id'], $this->json($this->channel(MV_TEST_TENANT_HOST, 'GET', '/reglages', null, $given['token']))['box']['id']);
        $this->assertSame(401, $this->channel(MV_TEST_TENANT2_HOST, 'GET', '/reglages', null, $given['token'])->getStatusCode(), 'son jeton ne vaut rien sur un autre site');
        $beat = $this->json($this->channel(MV_TEST_TENANT_HOST, 'POST', '/signe-de-vie', ['now' => gmdate('Y-m-d\TH:i:s\Z'), 'machine' => self::MACHINE, 'lastServerTime' => $given['serverTime'], 'devices' => []], $given['token']));
        $this->assertFalse($beat['clockDoubt'], 'la machine inscrite est bien celle qui a déposé le code');
        // la réponse s'est perdue : le boîtier redemande, avec sa preuve ; il reçoit un jeton neuf, l'ancien ne vaut plus rien
        $again = $this->json($this->box('/etat', $proof));
        $this->assertSame(['paired', $box['id']], [$again['status'], $again['box']['id']]);
        $this->assertNotSame($given['token'], $again['token']);
        $this->assertSame([401, 200], [$this->channel(MV_TEST_TENANT_HOST, 'GET', '/reglages', null, $given['token'])->getStatusCode(), $this->channel(MV_TEST_TENANT_HOST, 'GET', '/reglages', null, $again['token'])->getStatusCode()]);
        $this->assertSame([1, 0], [$this->boxes(), $this->boxes(2)], 'toujours un seul boîtier, dans le site de l\'administrateur');

        // Détacher (révoquer le boîtier sur son site) : le code ne vaut plus rien, pour personne ; le boîtier l'apprend et repartira d'un code neuf
        $this->json($this->admin('POST', self::BOXES . "/{$box['id']}/revoquer", ['confirm' => true]));
        $closed = $this->json($this->box('/etat', $proof));
        $this->assertSame(['status' => 'closed', 'expiresAt' => null, 'pollSeconds' => LossPairingService::POLL_SECONDS], array_diff_key($closed, ['serverTime' => 1]));
        $this->assertSame(['closed', 'closed'], [$this->row($code)['status'], $this->json($this->box('', $request))['status']]);
        $this->assertNotNull($this->row($code)['closed_at']);
        $this->assertSame($unknown, $refusal(['code' => $code, 'name' => 'Encore']));
        $this->assertSame($unknown, $refusal(['code' => $code, 'name' => 'Ailleurs'], 404, $elsewhere, MV_TEST_TENANT2_HOST));
        $this->assertSame('revoked', $this->sheet($box['id'])['status']);

        // Droits : administrateur du site ; ni anonyme, ni caissier ; sans le droit du module, fermé
        $this->assertSame(401, $this->pair(['code' => $code, 'name' => 'X'], null)->getStatusCode());
        $this->loginPos(['ROLE_USER_POS']);
        $this->assertSame(403, $this->pair(['code' => $code, 'name' => 'X'], $this->posToken)->getStatusCode());
        static::getContainer()->get(PlatformModeService::class)->setMode('live', 'test');
        $this->flags(true, false);
        $this->assertSame('MODULE_INACTIVE', $this->json($this->pair(['code' => $code, 'name' => 'X']), 403)['code']);
        $this->flags(true, true);
        // les deux routes du boîtier n'existent qu'en POST, et ne résolvent aucun site (un site inconnu dans l'en-tête ne change rien)
        $this->client->request('GET', 'https://' . self::PLATFORM . '/api/pertes/boitier/jumelage/etat');
        $this->assertSame(405, $this->client->getResponse()->getStatusCode());
        $this->client->request('POST', 'https://' . self::PLATFORM . '/api/pertes/boitier/jumelage/etat', [], [], ['HTTP_X_TENANT_HOST' => 'site-inconnu.example.invalid', 'CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $this->ip],
            json_encode(['code' => $other, 'machine' => self::MACHINE]));
        $this->assertSame(self::NEUTRAL, $this->json($this->client->getResponse(), 404));
        $this->client->request('POST', 'https://' . self::PLATFORM . '/api/pertes/boitier/jumelage', [], [], ['CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $this->ip], '{pas du json');
        $this->assertSame(['body' => 'INVALID_JSON'], $this->json($this->client->getResponse(), 422)['fields']);
    }

    public function testAPairingDiesWithItsBoxAndWaitsForTheModule(): void
    {
        // La clé remise à zéro : l'administrateur a choisi la clé, la machine d'avant est détachée pour de bon ; le jumelage ne vaut plus rien
        [$code, $request, $proof, $id] = $this->pairedBox('Remise');
        $key = $this->json($this->admin('POST', self::BOXES . "/$id/cle", ['confirm' => true]))['key'];
        $this->assertFalse($this->sheet($id)['paired']);
        $this->assertSame(['closed', 'closed', 'pending'], [$this->json($this->box('/etat', $proof))['status'], $this->row($code)['status'], $this->sheet($id)['status']]);
        // la clé, elle, inscrit une machine : celle qu'on veut
        $this->assertSame($id, $this->json($this->channel(MV_TEST_TENANT_HOST, 'POST', '/inscription', ['key' => $key, 'machine' => 'une-autre-machine-0123456789', 'version' => '1.0.0', 'arch' => 'amd64'], null))['box']['id']);

        // Révoqué avant même d'avoir reçu sa machine : rien n'est remis
        [$code, , $proof, $id] = $this->pairedBox('Révoqué');
        $this->json($this->admin('POST', self::BOXES . "/$id/revoquer", ['confirm' => true]));
        $this->assertSame(['closed', 'closed'], [$this->json($this->box('/etat', $proof))['status'], $this->row($code)['status']]);
        $this->assertNull($this->db->fetchOne('SELECT machine_hash FROM loss_box WHERE id = ?', [$id]));

        // Le site perd le module entre le jumelage et l'appel du boîtier : rien n'est remis, le boîtier patiente ; le module revenu, il est servi
        [$code, , $proof, $id] = $this->pairedBox('Sans module');
        static::getContainer()->get(PlatformModeService::class)->setMode('live', 'test');
        $this->flags(true, false);
        $held = $this->json($this->box('/etat', $proof));
        $this->assertSame(['waiting', null, false], [$held['status'], $held['expiresAt'], array_key_exists('token', $held)]);
        $this->flags(true, true);
        $this->assertSame(['pending', 'paired'], [$this->sheet($id)['status'], $this->row($code)['status']]);
        $this->assertSame(['paired', $id], [($given = $this->json($this->box('/etat', $proof)))['status'], $given['box']['id']]);
        static::getContainer()->get(PlatformModeService::class)->setMode('demo', 'test');

        // Le site n'existe plus : fermé, sans rien chercher ailleurs
        [$code, , $proof] = $this->pairedBox('Site disparu');
        $this->master->exec("UPDATE loss_box_pairing SET tenant_code = 'site-disparu' WHERE status = 'paired' AND delivered_at IS NULL");
        $this->assertSame(['closed', 'closed'], [$this->json($this->box('/etat', $proof))['status'], $this->row($code)['status']]);

        // Le plafond des boîtiers du site vaut aussi pour un jumelage ; le refus ne consomme pas le code
        $code = LossPairingCode::generate();
        $this->json($this->box('', ['code' => $code, 'machine' => self::MACHINE, 'version' => '1.0.0', 'arch' => 'amd64']));
        $this->db->executeStatement("UPDATE loss_box SET status = 'revoked', revoked_at = NOW() WHERE NOT exercise AND status <> 'revoked'");
        for ($i = 0; $i < LossBoxService::BOX_LIMIT; ++$i) {
            $this->keyed();
        }
        $this->assertSame(['BOX_LIMIT_REACHED', LossBoxService::BOX_LIMIT], [($full = $this->json($this->pair(['code' => $code, 'name' => 'De trop']), 409))['error'], $full['limit']]);
        $this->assertSame(['waiting', LossBoxService::BOX_LIMIT], [$this->row($code)['status'], $this->boxes()]);
        $this->db->executeStatement("UPDATE loss_box SET status = 'revoked', revoked_at = NOW() WHERE id = (SELECT MIN(id) FROM loss_box WHERE NOT exercise AND status <> 'revoked')");
        $this->assertSame('pending', $this->json($this->pair(['code' => $code, 'name' => 'La place rendue']), 201)['box']['status']);

        // Oubliée pour de bon un an après son expiration : son code redevient libre (ménage fait au dépôt suivant)
        $old = LossPairingCode::generate();
        $this->json($this->box('', ['code' => $old, 'machine' => self::MACHINE, 'version' => '1.0.0', 'arch' => 'amd64']));
        $almost = gmdate('Y-m-d H:i:s', time() - LossPairingService::FORGET_AFTER + 3600);
        $this->master->prepare('UPDATE loss_box_pairing SET expires_at = ? WHERE code_hash = ?')->execute([$almost, $this->row($old)['code_hash']]);
        $this->assertSame(self::NEUTRAL, $this->json($this->box('', ['code' => $old, 'machine' => 'une-autre-machine-0123456789', 'version' => '1.0.0', 'arch' => 'amd64']), 404), 'pas encore un an : le code reste au boîtier');
        $this->master->prepare('UPDATE loss_box_pairing SET expires_at = ? WHERE code_hash = ?')->execute([gmdate('Y-m-d H:i:s', time() - LossPairingService::FORGET_AFTER - 3600), $this->row($old)['code_hash']]);
        $kept = $this->requests();
        $this->assertSame('waiting', $this->json($this->box('', ['code' => $old, 'machine' => 'une-autre-machine-0123456789', 'version' => '1.0.0', 'arch' => 'amd64']))['status']);
        $this->assertSame($kept, $this->requests(), 'l\'ancienne demande est partie, la nouvelle a pris sa place ; une demande jumelée ou fermée ne s\'oublie pas');
    }

    public function testNoCodeIsGuessedByTryingThemInARow(): void
    {
        $code = LossPairingCode::generate();
        $request = ['code' => $code, 'machine' => self::MACHINE, 'version' => '1.0.0', 'arch' => 'amd64'];
        $this->json($this->box('', $request));
        $guess = fn (string $ip, ?string $with = null): Response => $this->pair(['code' => $with ?? LossPairingCode::generate(), 'name' => 'Essai'], $this->adminToken, MV_TEST_TENANT_HOST, $ip);

        // L'administrateur : 10 essais par site et par adresse et par quart d'heure
        [$first, $second, $third, $fourth] = [self::address(), self::address(), self::address(), self::address()];
        for ($i = 0; $i < LossBoxLimits::LIMITS['pairAdmin']; ++$i) {
            $this->assertSame(404, $guess($first)->getStatusCode(), "essai $i");
        }
        $over = $guess($first, $code);
        $this->assertSame([429, 'RATE_LIMITED', 'Trop d\'essais de code. Réessayez dans un quart d\'heure.'], [$over->getStatusCode(), ($body = $this->json($over, 429))['error'], $body['message']]);
        $this->assertGreaterThan(0, (int) $over->headers->get('Retry-After'));
        $this->assertSame([$body['retryAfterSeconds'], 'waiting', 0], [(int) $over->headers->get('Retry-After'), $this->row($code)['status'], $this->boxes()], 'au-delà du plafond, même le bon code ne passe pas');
        // … et 30 par site, d'où qu'ils viennent : changer d'adresse ne suffit pas
        foreach ([$second, $third] as $ip) {
            for ($i = 0; $i < LossBoxLimits::LIMITS['pairAdmin']; ++$i) {
                $this->assertSame(404, $guess($ip)->getStatusCode());
            }
        }
        $this->assertSame(LossBoxLimits::LIMITS['pairAdminSite'], 3 * LossBoxLimits::LIMITS['pairAdmin']);
        $this->assertSame([429, 'waiting'], [$guess($fourth, $code)->getStatusCode(), $this->row($code)['status']]);
        // le plafond d'un site n'arrête pas un autre site
        $this->assertSame(404, $this->pair(['code' => LossPairingCode::generate(), 'name' => 'Essai'], $this->adminOfSecondSite(), MV_TEST_TENANT2_HOST, $fourth)->getStatusCode());

        // Le boîtier : 30 codes inconnus ou mauvaises preuves par adresse ; au-delà, l'adresse est arrêtée avant toute lecture
        $noisy = self::address();
        for ($i = 0; $i < LossBoxLimits::LIMITS['pairFailed']; ++$i) {
            $body = $i % 2 === 0 ? ['code' => LossPairingCode::generate(), 'machine' => self::MACHINE] : ['code' => $code, 'machine' => "mauvaise-machine-$i-0123456789"];
            $this->assertSame(404, $this->box('/etat', $body, $noisy)->getStatusCode(), "essai $i");
        }
        $stopped = $this->box('/etat', ['code' => $code, 'machine' => self::MACHINE], $noisy);
        $this->assertSame([429, 'RATE_LIMITED'], [$stopped->getStatusCode(), $this->json($stopped, 429)['error']]);
        $this->assertGreaterThan(0, (int) $stopped->headers->get('Retry-After'));
        $this->assertSame(429, $this->box('', $request, $noisy)->getStatusCode());
        $this->assertSame('waiting', $this->json($this->box('/etat', ['code' => $code, 'machine' => self::MACHINE]))['status'], 'une autre adresse n\'est pas touchée');
        // un dépôt refusé (code pris par une autre machine) compte aussi
        $taker = self::address();
        for ($i = 0; $i < LossBoxLimits::LIMITS['pairOpen']; ++$i) {
            $this->assertSame(404, $this->box('', ['machine' => "machine-qui-insiste-$i-0123456789"] + $request, $taker)->getStatusCode());
        }
        $this->assertSame(429, $this->box('', ['code' => LossPairingCode::generate()] + $request, $taker)->getStatusCode(), '20 dépôts par adresse et par quart d\'heure');

        // Les questions d'un boîtier qui a fait sa preuve : 120 par demande et par 5 minutes ; une autre demande n'est pas touchée
        $polls = self::address();
        $already = 1; // la question posée plus haut depuis l'adresse du test
        for ($i = $already; $i < LossBoxLimits::LIMITS['pairPolls']; ++$i) {
            if ($this->box('/etat', ['code' => $code, 'machine' => self::MACHINE], $polls)->getStatusCode() !== 200) {
                $this->fail("question $i");
            }
        }
        $this->assertSame(429, $this->box('/etat', ['code' => $code, 'machine' => self::MACHINE], $polls)->getStatusCode());
        $otherCode = LossPairingCode::generate();
        $this->json($this->box('', ['code' => $otherCode] + $request, $polls));
        $this->assertSame('waiting', $this->json($this->box('/etat', ['code' => $otherCode, 'machine' => self::MACHINE], $polls))['status']);
    }

    // ----- aides -----

    /**
     * Une demande déposée puis jumelée par l'administrateur du premier site ; le boîtier n'a pas encore reçu son jeton.
     *
     * @return array{0: string, 1: array<string, string>, 2: array<string, string>, 3: int} code, demande, preuve, identifiant du boîtier
     */
    private function pairedBox(string $name): array
    {
        $code = LossPairingCode::generate();
        $request = ['code' => $code, 'machine' => self::MACHINE, 'version' => '1.0.0', 'arch' => 'amd64'];
        $this->json($this->box('', $request));
        $id = $this->json($this->pair(['code' => $code, 'name' => "$name $this->s"]), 201)['box']['id'];

        return [$code, $request, ['code' => $code, 'machine' => self::MACHINE], $id];
    }

    /** Un boîtier ajouté par sa clé (le chemin d'avant) ; rend son identifiant */
    private function keyed(): int
    {
        return $this->json($this->admin('POST', self::BOXES, ['name' => 'Par clé ' . bin2hex(random_bytes(3))]), 201)['box']['id'];
    }

    /** @return array<string, mixed> */
    private function sheet(int $id): array
    {
        return $this->json($this->admin('GET', self::BOXES . "/$id"));
    }

    /** Un appel du boîtier qui attend son jumelage : à la plateforme, sans site ni jeton */
    private function box(string $path, array $body, ?string $ip = null): Response
    {
        $this->client->getCookieJar()->clear();
        $this->client->request('POST', 'https://' . self::PLATFORM . '/api/pertes/boitier/jumelage' . $path, [], [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'REMOTE_ADDR' => $ip ?? $this->ip], json_encode($body));

        return $this->client->getResponse();
    }

    /** Un appel du canal du boîtier d'un site (jeton dans X-Loss-Box) */
    private function channel(string $host, string $method, string $path, ?array $body, ?string $token): Response
    {
        $this->client->getCookieJar()->clear();
        $this->client->request($method, 'https://' . $host . '/api/pertes/boitier' . $path, [], [], array_filter([
            'HTTP_X_TENANT_HOST' => $host, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_LOSS_BOX' => $token,
        ]) + ['REMOTE_ADDR' => $this->ip], $body !== null ? json_encode($body) : null);

        return $this->client->getResponse();
    }

    /** « Jumeler un boîtier » dans la gestion d'un site, depuis une adresse (au hasard par défaut : les plafonds se gardent d'un lancement à l'autre) */
    private function pair(array $body, ?string $token = 'admin', string $host = MV_TEST_TENANT_HOST, ?string $ip = null): Response
    {
        $this->client->getCookieJar()->clear();
        $token = $token === 'admin' ? $this->adminToken : $token;
        $this->client->request('POST', 'https://' . $host . self::BOXES . '/jumeler?locale=fr', [], [], array_filter([
            'HTTP_X_TENANT_HOST' => $host, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => $token ? 'Bearer ' . $token : null,
        ]) + ['REMOTE_ADDR' => $ip ?? self::address()], json_encode($body));

        return $this->client->getResponse();
    }

    /** Le jeton d'un administrateur du SECOND site d'essai (qui a le module, lui aussi) */
    private function adminOfSecondSite(): string
    {
        $this->provider->switchTenant(MV_TEST_TENANT2_DB, MV_TEST_TENANT2_CODE);
        $em = $this->provider->getEntityManager();
        $em->getConnection()->executeStatement('UPDATE entreprise SET is_management_app_active = true, is_loss_prevention_active = true');
        $email = 'pertes-site2-' . bin2hex(random_bytes(4)) . '@example.invalid';
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Gerante')->setLastname('Ailleurs')->setRoles(['ROLE_ADMIN'])->setIsVerified(true);
        $user->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD));
        $em->persist($user);
        $em->flush();
        $em->clear();
        $this->provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        // son jeton vient d'une vraie connexion à SON site (un jeton d'un site ne vaut pas sur un autre)
        $this->client->getCookieJar()->clear();
        $this->client->request('POST', 'https://' . MV_TEST_TENANT2_HOST . '/api/login', [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT2_HOST, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'REMOTE_ADDR' => self::address()], json_encode(['username' => $email, 'password' => self::PASSWORD, 'platform' => 'webadmin']));
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
        $token = null;
        foreach ($this->client->getResponse()->headers->getCookies() as $cookie) {
            $token = $cookie->getName() === 'auth_token_' . MV_TEST_TENANT2_CODE ? (string) $cookie->getValue() : $token;
        }
        $this->assertNotNull($token, 'aucun jeton d\'accès dans la réponse de la connexion');
        $this->provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);

        return $token;
    }

    /** Nombre de vrais boîtiers en service d'un site d'essai (1 ou 2) */
    private function boxes(int $site = 1): int
    {
        $this->provider->switchTenant($site === 1 ? MV_TEST_TENANT_DB : MV_TEST_TENANT2_DB, $site === 1 ? MV_TEST_TENANT_CODE : MV_TEST_TENANT2_CODE);
        $count = (int) $this->provider->getEntityManager()->getConnection()->fetchOne("SELECT COUNT(*) FROM loss_box WHERE NOT exercise AND status <> 'revoked'");
        $this->provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);

        return $count;
    }

    /** @return array<string, mixed> la demande d'un code, lue par son empreinte */
    private function row(string $code): array
    {
        $stmt = $this->master->prepare('SELECT * FROM loss_box_pairing WHERE code_hash = ?');
        $stmt->execute([static::getContainer()->get(\App\LossPrevention\Services\LossBoxToken::class)->pairingCodeHash($code)]);

        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
    }

    private function requests(): int
    {
        return (int) $this->master->query('SELECT COUNT(*) FROM loss_box_pairing')->fetchColumn();
    }

    private static function address(): string
    {
        return '10.' . random_int(1, 250) . '.' . random_int(1, 250) . '.' . random_int(1, 250);
    }
}
