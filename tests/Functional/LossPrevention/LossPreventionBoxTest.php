<?php

namespace App\Tests\Functional\LossPrevention;

use App\LossPrevention\Entity\LossBox;
use App\LossPrevention\Entity\LossPiece;
use App\LossPrevention\LossBoxGuardSubscriber;
use App\LossPrevention\Services\LossBoxChannelService;
use App\LossPrevention\Services\LossBoxException;
use App\LossPrevention\Services\LossBoxLimits;
use App\LossPrevention\Services\LossBoxService;
use App\LossPrevention\Services\LossBoxToken;
use App\Services\Platform\PlatformModeService;
use Doctrine\DBAL\ArrayParameterType;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lot L3-a (15/11/2026, `docs/prevention-pertes.md` § 16) : le boîtier de la boutique. Sa clé et son inscription, son jeton, son
 * signe de vie, ses lots de faits rejouables ; ce que l'administrateur en voit ; le boîtier simulé du mode exercice ; ce que la
 * base garde elle-même. Un vrai boîtier ne se supprime pas : chaque test révoque les siens à la fin (un boîtier révoqué ne compte
 * plus dans le plafond du site) et ne regarde que ceux qu'il a créés.
 */
class LossPreventionBoxTest extends LossPreventionTestCase
{
    private const BOXES = self::BASE . '/boitiers';
    private const EX = self::BASE . '/exercices';
    private const CHANNEL = '/api/pertes/boitier';
    private const MACHINE = 'machine-de-test-0123456789abcdef';
    /** @var array<string, string> lot L5-e : la machine d'un jeton qui n'est pas celle de `MACHINE` */
    private array $machines = [];

    private string $s;
    /** Adresse d'où ce test appelle le canal du boîtier : au hasard, pour que les plafonds d'essais (gardés d'un lancement à l'autre) ne se cumulent pas */
    private string $ip;

    protected function setUp(): void
    {
        parent::setUp();
        $this->s = strtoupper(bin2hex(random_bytes(3)));
        $this->ip = self::randomIp();
        $this->flags(true, true);
    }

    protected function tearDown(): void
    {
        try {
            while ($this->db->isTransactionActive()) {
                $this->db->rollBack();
            }
            $this->db->executeStatement("UPDATE loss_box SET status = 'revoked', revoked_at = NOW() WHERE NOT exercise AND status <> 'revoked'");
            $this->db->executeStatement('UPDATE loss_setting SET silent_after_seconds = NULL');
            $this->db->beginTransaction();
            $this->provider->getEntityManager()->getRepository(LossPiece::class)->deleteExercise();
            $this->provider->getEntityManager()->getRepository(LossBox::class)->deleteExercise();
            $this->db->commit();
            $ids = $this->db->fetchFirstColumn('SELECT id FROM product WHERE name LIKE ?', ['% L3A-' . $this->s]);
            if ($ids !== []) {
                $this->db->executeStatement('DELETE FROM product_variant WHERE product_id IN (?)', [$ids], [ArrayParameterType::INTEGER]);
                $this->db->executeStatement('DELETE FROM product WHERE id IN (?)', [$ids], [ArrayParameterType::INTEGER]);
            }
        } finally {
            parent::tearDown();
        }
    }

    public function testTheAdministratorManagesBoxesAndTheKeyIsShownOnlyOnce(): void
    {
        $outside = fn (): array => array_map('intval', $this->db->fetchNumeric('SELECT (SELECT COUNT(*) FROM loss_finding), (SELECT COUNT(*) FROM loss_journal), (SELECT COUNT(*) FROM loss_piece), (SELECT COUNT(*) FROM loss_tag)'));
        $outsideBefore = $outside();
        $keysBefore = (int) $this->db->fetchOne('SELECT COUNT(*) FROM loss_box');
        $list = $this->json($this->admin('GET', self::BOXES));
        $this->assertSame([0, 20, false, 'pertes.boitiers'], [count(array_filter($list['items'], fn (array $b): bool => $b['status'] !== 'revoked')), $list['limit'], $list['exercise'], $list['screen']]);

        // Créer : le nom est obligatoire ; rien n'est écrit par un refus
        $fields = fn (?array $body): array => $this->json($this->admin('POST', self::BOXES, $body), 422)['fields'];
        $this->assertSame(['name' => 'REQUIRED'], $fields([]));
        $this->assertSame(['name' => 'MUST_BE_STRING'], $fields(['name' => 12]));
        $this->assertSame(['name' => 'INVALID_VALUE'], $fields(['name' => '   ']));
        $this->assertSame(['name' => 'INVALID_VALUE'], $fields(['name' => str_repeat('a', 81)]));
        $this->assertSame(['place' => 'MUST_BE_STRING'], $fields(['name' => 'Comptoir', 'place' => ['x']]));
        $this->assertSame($keysBefore, (int) $this->db->fetchOne('SELECT COUNT(*) FROM loss_box'));

        $created = $this->json($this->admin('POST', self::BOXES, ['name' => "  Boîtier   du comptoir $this->s ", 'place' => ' Près de la caisse ']), 201);
        $this->assertSame(['box', 'key', 'enrolment'], array_keys($created));
        $key = $created['key'];
        $this->assertMatchesRegularExpression('/\AIZ-BOX-[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}\z/', $key);
        $box = $created['box'];
        $id = $box['id'];
        $this->assertSame(["Boîtier du comptoir $this->s", 'Près de la caisse', 'pending', 'En attente de sa machine', false, '—', null, null, null, null, null, null, false, substr($key, 0, 9) . '**-****-****', false, [], 'pertes.boitiers'],
            [$box['name'], $box['place'], $box['status'], $box['statusLabel'], $box['online'], $box['onlineLabel'], $box['lastSeenAt'], $box['silentSeconds'], $box['version'], $box['arch'], $box['enrolledAt'],
                $box['clockOffsetSeconds'], $box['clockDoubt'], $box['keyHint'], $box['exercise'], $box['devices'], $box['screen']]);
        $this->assertSame([['BOX_CREATED', 'Boîtier ajouté', null, true]], array_map(fn (array $e): array => [$e['type'], $e['label'], $e['device'], $e['byUser']], $box['events']));
        $this->assertSame(0, $box['factsReceived']);
        $this->assertSame([MV_TEST_TENANT_HOST, $key, MV_TEST_TENANT_HOST . '#' . $key], [$created['enrolment']['host'], $created['enrolment']['key'], $created['enrolment']['code']]);
        $this->assertStringContainsString('montrée qu\'une fois', $created['enrolment']['text']);
        // La base ne garde que l'empreinte ; aucune lecture ne rend la clé, ni l'empreinte, ni la machine
        $row = $this->db->fetchAssociative('SELECT * FROM loss_box WHERE id = ?', [$id]);
        $tokens = static::getContainer()->get(LossBoxToken::class);
        $this->assertSame([$tokens->keyHash(MV_TEST_TENANT_CODE, $key), 1, null, $this->adminId], [$row['key_hash'], (int) $row['generation'], $row['machine_hash'], (int) $row['created_by']]);
        $this->assertNotSame(hash('sha256', $key), $row['key_hash'], 'l\'empreinte dépend du secret de l\'application : une base copiée ne donne pas la clé');
        $this->assertNotSame($tokens->keyHash('un-autre-site', $key), $row['key_hash'], 'l\'empreinte dépend du site : la clé copiée avec une base ne vaut pas ailleurs');
        $this->assertStringNotContainsString($key, json_encode($row) . (string) $this->db->fetchOne('SELECT string_agg(data, \'\') FROM loss_box_event WHERE box_id = ?', [$id]));
        $secret = substr($key, 12);
        foreach ([$this->admin('GET', self::BOXES), $this->admin('GET', self::BOXES . "/$id"), $this->admin('PUT', self::BOXES . "/$id", ['name' => "Boîtier $this->s"])] as $response) {
            $content = (string) $response->getContent();
            $this->assertSame(200, $response->getStatusCode(), $content);
            $this->assertStringNotContainsString($secret, $content);
            $this->assertStringNotContainsString($row['key_hash'], $content);
            $this->assertStringNotContainsString('hash', strtolower($content));
        }
        // Renommer : le nom seul, l'endroit seul, l'endroit effacé ; en anglais
        $this->assertSame(["Boîtier $this->s", 'Près de la caisse'], array_values(array_intersect_key($this->json($this->admin('GET', self::BOXES . "/$id")), ['name' => 0, 'place' => 0])));
        $this->assertSame(["Boîtier $this->s", 'Réserve'], array_values(array_intersect_key($this->json($this->admin('PUT', self::BOXES . "/$id", ['place' => 'Réserve'])), ['name' => 0, 'place' => 0])));
        $this->assertNull($this->json($this->admin('PUT', self::BOXES . "/$id", ['place' => null]))['place']);
        $this->assertSame(['name' => 'INVALID_VALUE'], $this->json($this->admin('PUT', self::BOXES . "/$id", ['name' => '']), 422)['fields']);
        $this->assertSame(['Waiting for its machine', 'Box added'], [($en = $this->json($this->admin('GET', self::BOXES . "/$id?locale=en")))['statusLabel'], $en['events'][0]['label']]);

        // Remettre la clé à zéro : confirmation exigée ; nouvelle clé, la génération avance, l'ancienne clé n'inscrit plus personne
        $this->assertSame(['confirm' => 'REQUIRED'], $this->json($this->admin('POST', self::BOXES . "/$id/cle", []), 422)['fields']);
        $this->assertSame(['confirm' => 'MUST_BE_TRUE'], $this->json($this->admin('POST', self::BOXES . "/$id/cle", ['confirm' => 'oui']), 422)['fields']);
        $reset = $this->json($this->admin('POST', self::BOXES . "/$id/cle", ['confirm' => true]));
        $this->assertNotSame($key, $reset['key']);
        $this->assertSame(['pending', substr($reset['key'], 0, 9) . '**-****-****', ['BOX_KEY_RESET', 'BOX_CREATED']], [$reset['box']['status'], $reset['box']['keyHint'], array_column($reset['box']['events'], 'type')]);
        $this->assertSame([2, $tokens->keyHash(MV_TEST_TENANT_CODE, $reset['key'])], array_values($this->db->fetchNumeric('SELECT generation, key_hash FROM loss_box WHERE id = ?', [$id])));
        $enrol = fn (string $k): Response => $this->box('POST', '/inscription', ['key' => $k, 'machine' => self::MACHINE, 'version' => '1.0.0', 'arch' => 'amd64'], null);
        $this->assertSame('BOX_KEY_INVALID', $this->json($enrol($key), 404)['error'], 'l\'ancienne clé ne vaut plus rien');
        $this->assertSame($id, $this->json($enrol($reset['key']))['box']['id']);

        // Révoquer : confirmation exigée ; définitif ; révoquer deux fois ne fait rien ; une clé ne se remet pas à zéro sur un boîtier révoqué
        $this->assertSame(['confirm' => 'REQUIRED'], $this->json($this->admin('POST', self::BOXES . "/$id/revoquer", []), 422)['fields']);
        $this->assertSame('active', $this->json($this->admin('GET', self::BOXES . "/$id"))['status']);
        $revoked = $this->json($this->admin('POST', self::BOXES . "/$id/revoquer", ['confirm' => true]));
        $this->assertSame(['revoked', 'Révoqué', false, '—', 'BOX_REVOKED'], [$revoked['status'], $revoked['statusLabel'], $revoked['online'], $revoked['onlineLabel'], $revoked['events'][0]['type']]);
        $again = $this->json($this->admin('POST', self::BOXES . "/$id/revoquer", ['confirm' => true]));
        $this->assertSame(['revoked', 1], [$again['status'], count(array_filter($again['events'], fn (array $e): bool => $e['type'] === 'BOX_REVOKED'))]);
        $this->assertSame('BOX_REVOKED', $this->json($this->admin('POST', self::BOXES . "/$id/cle", ['confirm' => true]), 409)['error']);
        $this->assertSame('BOX_REVOKED', $this->json($enrol($reset['key']), 403)['error']);

        // Introuvable : identifiant inconnu, et un boîtier d'exercice par les routes réelles (et l'inverse)
        $simulated = $this->json($this->admin('POST', self::EX . '/boitiers'), 201)['box']['id'];
        foreach ([['GET', self::BOXES . '/999999999', null], ['PUT', self::BOXES . '/999999999', ['name' => 'x']], ['POST', self::BOXES . '/999999999/cle', ['confirm' => true]],
            ['POST', self::BOXES . '/999999999/revoquer', ['confirm' => true]], ['GET', self::BOXES . "/$simulated", null], ['PUT', self::BOXES . "/$simulated", ['name' => 'x']],
            ['POST', self::BOXES . "/$simulated/cle", ['confirm' => true]], ['POST', self::BOXES . "/$simulated/revoquer", ['confirm' => true]], ['GET', self::EX . "/boitiers/$id", null],
            ['POST', self::EX . "/boitiers/$id/scenarios", ['scenario' => 'heartbeat']]] as [$method, $path, $body]) {
            $this->assertSame('BOX_NOT_FOUND', $this->json($this->admin($method, $path, $body), 404)['error'], "$method $path");
        }
        $this->assertSame('active', $this->db->fetchOne('SELECT status FROM loss_box WHERE id = ?', [$simulated]), 'un boîtier simulé ne se révoque pas par la route réelle');
        $this->assertSame(['Boîtier simulé', 1], [$this->db->fetchOne('SELECT name FROM loss_box WHERE id = ?', [$simulated]), (int) $this->db->fetchOne("SELECT COUNT(*) FROM loss_box_event WHERE box_id = ? AND type = 'BOX_REVOKED'", [$id])],
            'ni renommé par la route réelle, ni le vrai boîtier touché par un scénario d\'exercice');

        // Plafond du site : 20 boîtiers non révoqués ; un boîtier révoqué rend sa place
        $ids = [];
        for ($i = 0; $i < LossBoxService::BOX_LIMIT; ++$i) {
            $ids[] = $this->json($this->admin('POST', self::BOXES, ['name' => "Plafond $i $this->s"]), 201)['box']['id'];
        }
        $full = $this->json($this->admin('POST', self::BOXES, ['name' => 'De trop']), 409);
        $this->assertSame(['BOX_LIMIT_REACHED', 20], [$full['error'], $full['limit']]);
        $this->json($this->admin('POST', self::BOXES . "/$ids[0]/revoquer", ['confirm' => true]));
        $this->json($this->admin('POST', self::BOXES, ['name' => "Place rendue $this->s"]), 201);

        // Droits : administrateur seulement ; sans le droit du module, les routes réelles sont fermées
        $this->assertSame(401, $this->call('GET', self::BOXES, null, null)->getStatusCode());
        $this->loginPos(['ROLE_USER_POS']);
        foreach ([['GET', self::BOXES], ['POST', self::BOXES], ['GET', self::BOXES . "/$id"], ['PUT', self::BOXES . "/$id"], ['POST', self::BOXES . "/$id/cle"], ['POST', self::BOXES . "/$id/revoquer"],
            ['GET', self::EX . '/boitiers'], ['POST', self::EX . '/boitiers'], ['DELETE', self::EX . '/boitiers']] as [$method, $path]) {
            $this->assertSame(403, $this->call($method, $path, $method === 'GET' ? null : [], $this->posToken)->getStatusCode(), "$method $path : un caissier");
        }
        static::getContainer()->get(PlatformModeService::class)->setMode('live', 'test');
        $this->flags(true, false);
        foreach ([['GET', self::BOXES], ['POST', self::BOXES], ['GET', self::BOXES . "/$id"], ['PUT', self::BOXES . "/$id"], ['POST', self::BOXES . "/$id/cle"], ['POST', self::BOXES . "/$id/revoquer"]] as [$method, $path]) {
            $closed = $this->admin($method, $path, $method === 'GET' ? null : ['name' => 'x', 'confirm' => true]);
            $this->assertSame([403, 'MODULE_INACTIVE'], [$closed->getStatusCode(), $this->json($closed, 403)['code']], "$method $path");
        }
        $this->flags(true, true);
        $this->assertSame($outsideBefore, $outside(), 'ni constat, ni journal du module, ni pièce, ni puce : les boîtiers n\'écrivent que chez eux');
    }

    public function testABoxEnrolsGivesSignsOfLifeAndSendsReplayableFactsAndReadsNothingElse(): void
    {
        [, [$variant]] = $this->article('Blouson', ["BLOUSON-$this->s"]);
        $nfc = '04' . $this->s . 'A1B2C3'; // 14 caractères
        $uhf = 'E280' . $this->s . '0000' . $this->s . '1234'; // 24 caractères
        $piece = $this->json($this->admin('POST', self::BASE . '/puces/associer', ['variantId' => $variant, 'identifier' => $nfc, 'technology' => 'nfc']))['piece']['id'];
        $this->json($this->admin('POST', self::BASE . '/puces/associer', ['pieceId' => $piece, 'identifier' => $uhf, 'technology' => 'uhf']));
        $created = $this->json($this->admin('POST', self::BOXES, ['name' => "Canal $this->s"]), 201);
        [$id, $key] = [$created['box']['id'], $created['key']];
        $watched = fn (): array => array_map('intval', $this->db->fetchNumeric('SELECT (SELECT COUNT(*) FROM "order"), (SELECT COUNT(*) FROM pos_audit_log), (SELECT COUNT(*) FROM loss_finding), (SELECT COUNT(*) FROM loss_journal),'
            . ' (SELECT COUNT(*) FROM loss_tag), (SELECT COUNT(*) FROM loss_piece), (SELECT COUNT(*) FROM inventory_movements)'));
        $alertsBefore = (int) $this->db->fetchOne("SELECT COUNT(*) FROM loss_journal WHERE type = 'ALERT_SENT'");
        $watchedBefore = $watched();
        $events = fn (int $box): array => array_map(fn (array $r): array => [$r['type'], json_decode((string) $r['data'], true)],
            $this->db->fetchAllAssociative('SELECT type, data FROM loss_box_event WHERE box_id = ? ORDER BY id', [$box]));

        // ----- Inscription : forme, clé, machine
        $enrol = fn (array $body): Response => $this->box('POST', '/inscription', $body, null);
        $good = ['key' => strtolower(str_replace('-', ' ', $key)), 'machine' => self::MACHINE, 'version' => '1.0.0', 'arch' => 'arm64'];
        $this->assertSame(['machine' => 'REQUIRED', 'version' => 'REQUIRED', 'arch' => 'REQUIRED', 'key' => 'REQUIRED'], $this->json($enrol([]), 422)['fields']);
        $this->assertSame(['machine' => 'INVALID_VALUE', 'version' => 'INVALID_VALUE', 'arch' => 'INVALID_VALUE'],
            $this->json($enrol(['key' => $key, 'machine' => 'court', 'version' => 'v 1', 'arch' => 'x86']), 422)['fields']);
        $this->assertSame(['machine' => 'INVALID_VALUE'], $this->json($enrol(['machine' => ['x']] + $good), 422)['fields']);
        foreach (['IZ-BOX-AAAA-AAAA-AAAA', 'IZ-POS-AAAA-AAAA-AAAA', 'pas une clé', 12, ['IZ-BOX'], str_repeat('A', 100)] as $wrong) {
            $refused = $this->json($enrol(['key' => $wrong] + $good), 404);
            $this->assertSame(['BOX_KEY_INVALID', 'Clé de boîtier inconnue.'], [$refused['error'], $refused['message']], 'même réponse pour une clé inconnue et pour une saisie qui n\'en est pas une');
        }
        $this->assertSame('pending', $this->db->fetchOne('SELECT status FROM loss_box WHERE id = ?', [$id]));
        $enrolled = $this->json($enrol($good));
        $this->assertSame(['box', 'token', 'expiresAt', 'serverTime', 'settings'], array_keys($enrolled));
        $this->assertSame(['id' => $id], $enrolled['box'], 'le boîtier ne reçoit même pas son nom');
        $this->assertSame(['heartbeatSeconds' => 60, 'silentAfterSeconds' => 300, 'facts' => ['types' => ['SPOT_CHECK', 'ZONE_SEEN', 'COUNTER_SEEN', 'COUNTER_LEFT', 'ZONE_LEFT', 'TAG_WRITTEN', 'TAG_WRITE_FAILED', 'EXIT_SIGNAL'], 'maxBatch' => 200], 'zones' => ['stockroom', 'stockroom_door', 'floor', 'counter', 'exit', 'back_door'], 'zonePresence' => ['arriveSeconds' => 3, 'leaveSeconds' => 30], 'devices' => [], 'exitSignal' => ['enabled' => false, 'counterGraceSeconds' => 600, 'fullRefreshSeconds' => 3600]], $enrolled['settings']);
        $token = $enrolled['token'];
        $this->assertMatchesRegularExpression('/\Ab' . $id . '\.2\.\d{10}\.[0-9a-f]{64}\z/', $token);
        $this->assertEqualsWithDelta(time() + 3600, strtotime($enrolled['expiresAt']), 5, 'lot L5-e : un jeton d\'une heure');
        $row = $this->db->fetchAssociative('SELECT status, generation, machine_hash, version, arch, enrolled_at IS NOT NULL AS enrolled, last_seen_at IS NOT NULL AS seen FROM loss_box WHERE id = ?', [$id]);
        $tokens = static::getContainer()->get(LossBoxToken::class);
        $this->assertNotSame(hash('sha256', self::MACHINE), $row['machine_hash']);
        $this->assertNotSame($tokens->machineHash('un-autre-site', self::MACHINE), $row['machine_hash'], 'l\'empreinte de la machine dépend du site');
        $this->assertSame(['active', 2, $tokens->machineHash(MV_TEST_TENANT_CODE, self::MACHINE), '1.0.0', 'arm64', true, true], [$row['status'], (int) $row['generation'], $row['machine_hash'], $row['version'], $row['arch'], $row['enrolled'], $row['seen']]);
        $this->assertSame([['BOX_CREATED', []], ['BOX_ENROLLED', ['version' => '1.0.0', 'arch' => 'arm64', 'again' => false]]], $events($id));
        // une autre machine avec la même clé : refusée ; la même machine : réinscrite, l'ancien jeton ne vaut plus rien
        $this->assertSame('BOX_KEY_ALREADY_ENROLLED', $this->json($enrol(['machine' => 'une-autre-machine-0123456789'] + $good), 409)['error']);
        $this->assertSame(2, (int) $this->db->fetchOne('SELECT generation FROM loss_box WHERE id = ?', [$id]));
        $second = $this->json($enrol(['version' => '1.0.1'] + $good))['token'];
        $this->assertSame('BOX_TOKEN_INVALID', $this->json($this->box('GET', '/reglages', null, $token), 401)['error']);
        $token = $second;
        $this->assertSame(['BOX_ENROLLED', ['version' => '1.0.1', 'arch' => 'arm64', 'again' => true]], $events($id)[2]);

        // ----- La garde : tout le préfixe est fermé sans jeton valable ; le jeton du boîtier n'ouvre rien d'autre
        foreach ([['GET', '/reglages'], ['POST', '/faits'], ['POST', '/signe-de-vie']] as [$method, $path]) {
            $this->assertSame([401, 'BOX_TOKEN_REQUIRED'], [($r = $this->box($method, $path, $method === 'GET' ? null : [], null))->getStatusCode(), $this->json($r, 401)['error']], "$method $path sans jeton");
        }
        // une adresse du préfixe qui n'est pas une route, ou une route sous une autre méthode : le routeur répond avant tout contrôleur
        foreach ([['GET', '/une-route-qui-n-existe-pas', 404], ['GET', '', 404], ['GET', '/inscription', 405], ['PUT', '/jeton', 405], ['POST', '/reglages', 405], ['DELETE', '/faits', 405]] as [$method, $path, $status]) {
            $this->assertSame($status, $this->box($method, $path, $method === 'GET' ? null : [], null)->getStatusCode(), "$method $path");
        }
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/api/pertes/%62oitier/reglages', [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'HTTP_ACCEPT' => 'application/json']);
        $this->assertSame(401, $this->client->getResponse()->getStatusCode(), 'chemin encodé : la même garde');
        $expired = $tokens->issue(MV_TEST_TENANT_CODE, $id, 3, time() - 2 * 86400)['token'];
        $wrong = [
            'du bruit' => ['n-importe-quoi', 'BOX_TOKEN_INVALID'],
            'signé pour un autre site' => [$tokens->issue('un-autre-site', $id, 3)['token'], 'BOX_TOKEN_INVALID'],
            'un autre boîtier sous la même signature' => [preg_replace('/\Ab\d+\./', 'b' . ($id + 1) . '.', $token), 'BOX_TOKEN_INVALID'],
            'une échéance repoussée à la main' => [preg_replace('/\.(\d{10})\./', '.' . (time() + 99999999) . '.', $token), 'BOX_TOKEN_INVALID'],
            'une autre génération' => [$tokens->issue(MV_TEST_TENANT_CODE, $id, 2)['token'], 'BOX_TOKEN_INVALID'],
            'un boîtier qui n\'existe pas' => [$tokens->issue(MV_TEST_TENANT_CODE, 999999999, 1)['token'], 'BOX_TOKEN_INVALID'],
            'expiré' => [$expired, 'BOX_TOKEN_EXPIRED'],
        ];
        foreach ($wrong as $what => [$bad, $code]) {
            $this->assertSame($code, $this->json($this->box('GET', '/reglages', null, $bad), 401)['error'], $what);
            $this->assertSame($code, $this->json($this->box('POST', '/faits', ['facts' => []], $bad), 401)['error'], $what);
        }
        $this->assertSame(401, $this->call('GET', self::CHANNEL . '/reglages', null, $this->adminToken)->getStatusCode(), 'un administrateur n\'est pas un boîtier');
        // Le jeton du boîtier, seul ou présenté comme un jeton d'utilisateur, ne lit ni les ventes, ni les clients, ni les constats, ni rien de la gestion
        foreach (['/api/gestion/pertes', '/api/gestion/pertes/constats', '/api/gestion/pertes/boitiers', '/api/gestion/pertes/pieces', '/api/pos/customers', '/api/orders', '/api/gestion/statistiques',
            '/api/app-keys', '/api/cash-registers', '/api/caisse/transactions', '/api/pos/statistics/cashiers'] as $path) {
            foreach ([['HTTP_X_LOSS_BOX' => $token], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token], ['HTTP_X_LOSS_BOX' => $token, 'HTTP_AUTHORIZATION' => 'Bearer ' . $token]] as $headers) {
                $this->client->getCookieJar()->clear();
                $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . $path, [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'HTTP_ACCEPT' => 'application/json'] + $headers);
                $this->assertSame(401, $this->client->getResponse()->getStatusCode(), "$path avec le jeton du boîtier : " . substr((string) $this->client->getResponse()->getContent(), 0, 200));
            }
        }
        // Ses réglages : ce qu'il a le droit de savoir, et rien d'autre
        $settings = $this->json($this->box('GET', '/reglages', null, $token));
        $this->assertSame(['box', 'serverTime', 'heartbeatSeconds', 'silentAfterSeconds', 'facts', 'zones', 'zonePresence', 'devices', 'exitSignal'], array_keys($settings));
        $this->assertSame(['id' => $id], $settings['box']);

        // ----- Signe de vie
        $beat = fn (array $body, ?string $with = null): Response => $this->box('POST', '/signe-de-vie', $body + ['machine' => self::MACHINE], $with ?? $token);
        $now = fn (int $shift = 0): string => gmdate('Y-m-d\TH:i:s\Z', time() + $shift);
        $this->assertSame(['now' => 'REQUIRED', 'devices' => 'REQUIRED'], $this->json($beat([]), 422)['fields'], 'la liste des appareils est obligatoire : sans elle, tous seraient dits débranchés');
        $this->assertSame(['now' => 'REQUIRED', 'machine' => 'REQUIRED', 'devices' => 'REQUIRED'], $this->json($this->box('POST', '/signe-de-vie', [], $token), 422)['fields']);
        $this->assertSame(['machine' => 'INVALID_VALUE'], $this->json($beat(['machine' => 'court', 'now' => gmdate('Y-m-d\TH:i:s\Z'), 'devices' => []]), 422)['fields']);
        $this->assertSame('BOX_TOKEN_INVALID', $this->json($beat(['machine' => 'une-autre-machine-0123456789', 'now' => gmdate('Y-m-d\TH:i:s\Z'), 'devices' => []]), 401)['error'], 'un jeton copié ne tient pas un boîtier en vie sans sa machine');
        $this->assertSame(['now' => 'INVALID_VALUE', 'version' => 'INVALID_VALUE', 'devices' => 'MUST_BE_LIST'], $this->json($beat(['now' => 'hier', 'version' => 'v 2', 'devices' => ['a' => 1]]), 422)['fields']);
        foreach (['1970-01-01T00:00:00Z', '2026-02-30T10:00:00Z', '2026-11-15T24:00:00Z', '2026-11-15 10:00:00', '2026-11-15T10:00:00'] as $notATime) {
            $this->assertSame(['now' => 'INVALID_VALUE'], $this->json($beat(['now' => $notATime, 'devices' => []]), 422)['fields'], $notATime);
        }
        $this->assertSame(['devices[0].serial' => 'REQUIRED', 'devices[0].kind' => 'REQUIRED', 'devices[0].status' => 'REQUIRED', 'devices[1]' => 'INVALID_VALUE', 'devices[2].serial' => 'INVALID_VALUE',
            'devices[2].kind' => 'INVALID_VALUE', 'devices[2].status' => 'INVALID_VALUE', 'devices[2].model' => 'INVALID_VALUE'],
            $this->json($beat(['now' => $now(), 'devices' => [[], 'x', ['serial' => 'a b', 'kind' => 'antenne', 'status' => 'bof', 'model' => '<script>'], ['serial' => 'L1', 'kind' => 'reader', 'status' => 'ok'],
                ['serial' => 'L1', 'kind' => 'reader', 'status' => 'ok']]]), 422)['fields']);
        $this->assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM loss_device WHERE box_id = ?', [$id]), 'un signe de vie refusé n\'écrit rien');
        $devices = [['serial' => "LECTEUR-$this->s", 'kind' => 'reader', 'status' => 'ok', 'model' => 'Lecteur de bureau (USB)'], ['serial' => "IMPR-$this->s", 'kind' => 'printer', 'status' => 'down']];
        $first = $this->json($beat(['now' => $now(), 'version' => '1.0.2', 'devices' => $devices]));
        $this->assertSame(['serverTime', 'clockOffsetSeconds', 'clockDoubt', 'token', 'refusedDevices', 'resendMats', 'settings'], array_keys($first));
        $this->assertSame([false, null, []], [$first['clockDoubt'], $first['token'], $first['refusedDevices']]);
        $this->assertEqualsWithDelta(0, $first['clockOffsetSeconds'], 3);
        $this->assertSame([["LECTEUR-$this->s", 'reader', null, null], ["IMPR-$this->s", 'printer', null, null]], array_map(fn (array $d): array => [$d['serial'], $d['kind'], $d['zone'], $d['cashRegisterId']], $first['settings']['devices']));
        $reader = $first['settings']['devices'][0]['id'];
        $this->assertSame([['DEVICE_SEEN', ['kind' => 'reader']], ['DEVICE_SEEN', ['kind' => 'printer']], ['SOURCE_DOWN', ['reason' => 'reported']]], array_slice($events($id), 3));
        $this->assertSame('1.0.2', $this->db->fetchOne('SELECT version FROM loss_box WHERE id = ?', [$id]));
        // le même signe de vie n'écrit aucun fait : seul un changement en écrit un
        $count = fn (): int => (int) $this->db->fetchOne('SELECT COUNT(*) FROM loss_box_event WHERE box_id = ?', [$id]);
        $before = $count();
        $this->json($beat(['now' => $now(), 'devices' => $devices]));
        $this->json($beat(['now' => $now(), 'devices' => $devices]));
        $this->assertSame($before, $count());
        // l'imprimante revient ; le lecteur n'est plus annoncé : en panne, une seule fois ; puis il revient
        $this->json($beat(['now' => $now(), 'devices' => [['serial' => "IMPR-$this->s", 'kind' => 'printer', 'status' => 'ok']]]));
        $this->json($beat(['now' => $now(), 'devices' => [['serial' => "IMPR-$this->s", 'kind' => 'printer', 'status' => 'ok']]]));
        $this->assertSame([['SOURCE_UP', ['reason' => 'reported']], ['SOURCE_DOWN', ['reason' => 'absent']]], array_slice($events($id), $before));
        $this->assertSame(['down', 'ok'], $this->db->fetchFirstColumn('SELECT status FROM loss_device WHERE box_id = ? ORDER BY id', [$id]));
        $this->json($beat(['now' => $now(), 'devices' => $devices]));
        $this->assertSame([['SOURCE_UP', ['reason' => 'reported']], ['SOURCE_DOWN', ['reason' => 'reported']]], array_slice($events($id), $before + 2));
        // Un appareil que la plateforme ne peut pas prendre est DIT, il n'empêche pas le signe de vie : le genre d'un appareil ne
        // change pas (l'appareil connu reste comme il est), et de deux appareils au même numéro de série le premier compte
        $before = $count();
        $this->db->executeStatement("UPDATE loss_box SET last_seen_at = NOW() AT TIME ZONE 'UTC' - INTERVAL '2 minutes' WHERE id = ?", [$id]);
        $mixed = ['now' => $now(), 'devices' => [['serial' => "LECTEUR-$this->s", 'kind' => 'camera', 'status' => 'ok'], $devices[1], ['serial' => "IMPR-$this->s", 'kind' => 'printer', 'status' => 'ok']]];
        $refusedDevices = $this->json($beat($mixed))['refusedDevices'];
        $this->assertSame([['serial' => "IMPR-$this->s", 'code' => 'DUPLICATE'], ['serial' => "LECTEUR-$this->s", 'code' => 'MISMATCH']], $refusedDevices);
        // le lecteur qu'on connaissait sous ce numéro n'est plus là : noté en panne, une seule fois, pour que l'administrateur le voie
        $this->json($beat($mixed));
        $this->assertSame([$before + 1, ['SOURCE_DOWN', ['reason' => 'mismatch']], [['reader', 'down'], ['printer', 'down']]], [$count(), $events($id)[$before],
            array_map(fn (array $r): array => [$r['kind'], $r['status']], $this->db->fetchAllAssociative('SELECT kind, status FROM loss_device WHERE box_id = ? ORDER BY id', [$id]))]);
        $this->assertEqualsWithDelta(time(), strtotime($this->db->fetchOne('SELECT last_seen_at FROM loss_box WHERE id = ?', [$id]) . ' UTC'), 5, 'le boîtier a bien donné signe de vie');
        // Horloge du boîtier : l'écart est mesuré ; au-delà d'une minute, il est dit
        $late = $this->json($beat(['now' => gmdate('Y-m-d\TH:i:s', time() + 7200 - 300) . '+02:00', 'devices' => $devices]));
        $this->assertEqualsWithDelta(-300, $late['clockOffsetSeconds'], 3);
        $this->assertTrue($late['clockDoubt']);
        $sheet = $this->json($this->admin('GET', self::BOXES . "/$id"));
        $this->assertSame([true, 'En ligne', null, true, '1.0.2', 'arm64'], [$sheet['online'], $sheet['onlineLabel'], $sheet['silentSeconds'], $sheet['clockDoubt'], $sheet['version'], $sheet['arch']]);
        $this->assertSame([["LECTEUR-$this->s", 'reader', 'Lecteur de puces', 'Lecteur de bureau (USB)', 'ok', 'En marche', null, null], ["IMPR-$this->s", 'printer', 'Imprimante à puce', null, 'down', 'En panne ou débranché', null, null]],
            array_map(fn (array $d): array => [$d['serial'], $d['kind'], $d['kindLabel'], $d['model'], $d['status'], $d['statusLabel'], $d['zone'], $d['zoneLabel']], $sheet['devices']));
        $this->assertSame("LECTEUR-$this->s", array_values(array_filter($sheet['events'], fn (array $e): bool => $e['type'] === 'DEVICE_SEEN'))[1]['device']);
        // Silence : sans signe de vie depuis plus de 5 minutes, le boîtier est dit silencieux ; son retour écrit UN fait qui dit le trou
        $this->db->executeStatement("UPDATE loss_box SET last_seen_at = NOW() AT TIME ZONE 'UTC' - INTERVAL '10 minutes' WHERE id = ?", [$id]);
        $silent = $this->json($this->admin('GET', self::BOXES . "/$id"));
        $this->assertSame([false, 'Silencieux'], [$silent['online'], $silent['onlineLabel']]);
        $this->assertEqualsWithDelta(600, $silent['silentSeconds'], 5);
        $before = $count();
        $this->json($beat(['now' => $now(), 'devices' => $devices]));
        $this->json($beat(['now' => $now(), 'devices' => $devices]));
        $back = array_slice($events($id), $before);
        $this->assertSame([1, 'BOX_BACK'], [count($back), $back[0][0]]);
        $this->assertEqualsWithDelta(600, $back[0][1]['silentSeconds'], 5);
        $this->assertSame(array_values(array_filter(array_column($this->json($this->admin('GET', self::BOXES . "/$id"))['events'], 'silentSeconds')))[0], $back[0][1]['silentSeconds']);
        $this->assertTrue($this->json($this->admin('GET', self::BOXES . "/$id"))['online']);
        // Jeton : renouvelé au signe de vie dès la mi-vie ; un jeton expiré s'échange, avec la machine, pendant 30 jours. Lot L5-e : une
        // heure, renouvelé à la demi-heure ; l'ancien jeton reste bon jusqu'à son échéance (aucun trou de service)
        $halfLife = $tokens->issue(MV_TEST_TENANT_CODE, $id, 3, time() - 31 * 60)['token'];
        $renewed = $this->json($beat(['now' => $now(), 'devices' => $devices], $halfLife))['token'];
        $this->assertEqualsWithDelta(time() + 3600, strtotime($renewed['expiresAt']), 5);
        $this->assertSame([200, 200], [$this->box('GET', '/reglages', null, $renewed['token'])->getStatusCode(), $this->box('GET', '/reglages', null, $halfLife)->getStatusCode()],
            'le neuf et l\'ancien, encore bon 29 minutes : le boîtier passe de l\'un à l\'autre sans trou');
        $this->assertNull($this->json($beat(['now' => $now(), 'devices' => $devices], $tokens->issue(MV_TEST_TENANT_CODE, $id, 3, time() - 29 * 60)['token']))['token'], 'avant la mi-vie : le jeton reste');
        $this->assertSame('BOX_TOKEN_EXPIRED', $this->json($this->box('GET', '/reglages', null, $tokens->issue(MV_TEST_TENANT_CODE, $id, 3, time() - 3601)['token']), 401)['error'], 'une heure passée : expiré');
        $this->ip = self::randomIp(); // les essais d'inscription de ce test ont déjà entamé le plafond de son adresse
        $renew = fn (?string $with, array $body): Response => $this->box('POST', '/jeton', $body, $with);
        $this->machines[$expired] = ''; // l'échange porte la machine dans son corps : sans l'en-tête, il passe (lot L5-e)
        $fresh = $this->json($renew($expired, ['machine' => self::MACHINE]));
        unset($this->machines[$expired]);
        $this->assertSame(200, $this->box('GET', '/reglages', null, $this->json($renew($tokens->issue(MV_TEST_TENANT_CODE, $id, 3, time() - 29 * 86400 - 3600)['token'], ['machine' => self::MACHINE]))['token'])->getStatusCode(),
            'expiré depuis 29 jours : il s\'échange encore');
        $this->assertSame(['token', 'expiresAt', 'serverTime'], array_keys($fresh));
        $this->assertSame(200, $this->box('GET', '/reglages', null, $fresh['token'])->getStatusCode());
        foreach (['sans jeton' => [null, ['machine' => self::MACHINE]], 'une autre machine' => [$expired, ['machine' => 'une-autre-machine-0123456789']], 'sans machine' => [$expired, []],
            'expiré depuis plus de 30 jours' => [$tokens->issue(MV_TEST_TENANT_CODE, $id, 3, time() - 32 * 86400)['token'], ['machine' => self::MACHINE]],
            'expiré depuis 30 jours et une heure' => [$tokens->issue(MV_TEST_TENANT_CODE, $id, 3, time() - 30 * 86400 - 7200)['token'], ['machine' => self::MACHINE]],
            'une génération passée' => [$tokens->issue(MV_TEST_TENANT_CODE, $id, 2)['token'], ['machine' => self::MACHINE]],
            'un autre site' => [$tokens->issue('un-autre-site', $id, 3)['token'], ['machine' => self::MACHINE]]] as $what => [$with, $body]) {
            $this->assertSame('BOX_TOKEN_INVALID', $this->json($renew($with, $body), 401)['error'], $what);
        }

        // ----- Faits : un lot, une réponse par fait ; rejouable
        $this->json($beat(['now' => $now(), 'devices' => $devices])); // l'horloge du boîtier est à l'heure
        $at = gmdate('Y-m-d\TH:i:s\Z', time() - 90);
        $zoneAt = time() - 60; // l'instant d'un fait daté dans un autre fuseau : comparé tel quel, sans dépendre de la durée de l'essai
        $fact = fn (string $k, array $over = []): array => $over + ['key' => "fait-$this->s-$k", 'type' => 'SPOT_CHECK', 'at' => $at, 'device' => "LECTEUR-$this->s", 'data' => ['identifier' => $nfc, 'technology' => 'nfc']];
        $batch = [
            $fact('a', ['seq' => 7]),
            $fact('b', ['type' => 'ZONE_SEEN', 'zone' => 'exit', 'data' => ['identifier' => implode(':', str_split(strtolower($uhf), 2))], 'at' => gmdate('Y-m-d\TH:i:s', $zoneAt + 7200) . '+02:00']),
            $fact('a'), // même clé dans le même lot
            $fact('c', ['type' => 'PIECE_SOLD']),
            ['type' => 'SPOT_CHECK'],
            $fact('d', ['key' => 'court']),
            $fact('e', ['at' => 'tout à l\'heure']),
            $fact('f', ['seq' => -1]),
            $fact('g', ['device' => 'INCONNU']),
            $fact('h', ['device' => "IMPR-$this->s"]),
            $fact('i', ['type' => 'ZONE_SEEN']),
            $fact('j', ['zone' => 'toilettes']),
            $fact('k', ['data' => ['identifier' => 'AABBCCDD', 'technology' => 'nfc']]),
            $fact('l', ['data' => ['identifier' => 'SIM-0123456789AB']]),
            $fact('m', ['data' => ['identifier' => 'pas une puce']]),
            $fact('n', ['data' => ['identifier' => $nfc, 'technology' => 'simulated']]),
            $fact('o', ['data' => 'x']),
            'pas un fait',
            $fact('p', ['zone' => 'floor']),
        ];
        $pieceEvents = fn (): int => (int) $this->db->fetchOne('SELECT COUNT(*) FROM loss_piece_event');
        $before = $pieceEvents();
        $sentFrom = time();
        $sent = $this->json($this->box('POST', '/faits', ['facts' => $batch], $token));
        $sentTo = time();
        $this->assertSame(['received', 'accepted', 'duplicates', 'refused', 'results', 'serverTime'], array_keys($sent));
        $this->assertSame([19, 3, 1, 15], [$sent['received'], $sent['accepted'], $sent['duplicates'], $sent['refused']]);
        $k = fn (string $x): string => "fait-$this->s-$x";
        $this->assertSame([
            [0, $k('a'), 'accepted', null], [1, $k('b'), 'accepted', null], [2, $k('a'), 'duplicate', null], [3, $k('c'), 'refused', 'FACT_TYPE_UNKNOWN'], [4, null, 'refused', 'FACT_INVALID'],
            [5, null, 'refused', 'FACT_INVALID'], [6, $k('e'), 'refused', 'FACT_INVALID'], [7, $k('f'), 'refused', 'FACT_INVALID'], [8, $k('g'), 'refused', 'DEVICE_UNKNOWN'],
            [9, $k('h'), 'refused', 'DEVICE_KIND_INVALID'], [10, $k('i'), 'refused', 'ZONE_INVALID'], [11, $k('j'), 'refused', 'ZONE_INVALID'], [12, $k('k'), 'refused', 'TAG_UNKNOWN'],
            [13, $k('l'), 'refused', 'TAG_SIMULATED'], [14, $k('m'), 'refused', 'TAG_INVALID'], [15, $k('n'), 'refused', 'TAG_INVALID'], [16, $k('o'), 'refused', 'TAG_INVALID'],
            [17, null, 'refused', 'FACT_INVALID'], [18, $k('p'), 'accepted', null],
        ], array_map(fn (array $r): array => [$r['index'], $r['key'], $r['status'], $r['code']], $sent['results']));
        $this->assertSame($before + 3, $pieceEvents());
        $written = $this->db->fetchAllAssociative('SELECT type, occurred_at, user_id, piece_id, tag_id, source, event_key, exercise, data, box_id, device_id, received_at FROM loss_piece_event WHERE box_id = ? ORDER BY id', [$id]);
        $tagIds = array_map('intval', $this->db->fetchAllKeyValue('SELECT identifier, id FROM loss_tag WHERE identifier IN (?, ?)', [$nfc, $uhf]));
        $this->assertSame([
            ['SPOT_CHECK', gmdate('Y-m-d H:i:s', strtotime($at)), null, $piece, $tagIds[$nfc], 'box', "b$id:" . $k('a'), false, ['seq' => 7], $id, $reader],
            ['ZONE_SEEN', '(vérifiée plus bas)', null, $piece, $tagIds[$uhf], 'box', "b$id:" . $k('b'), false, ['zone' => 'exit'], $id, $reader],
            ['SPOT_CHECK', gmdate('Y-m-d H:i:s', strtotime($at)), null, $piece, $tagIds[$nfc], 'box', "b$id:" . $k('p'), false, ['zone' => 'floor'], $id, $reader],
        ], array_map(fn (array $r): array => [$r['type'], $r['type'] === 'ZONE_SEEN' ? '(vérifiée plus bas)' : $r['occurred_at'], $r['user_id'], (int) $r['piece_id'], (int) $r['tag_id'], $r['source'], $r['event_key'],
            (bool) $r['exercise'], json_decode($r['data'], true), (int) $r['box_id'], (int) $r['device_id']], $written));
        // indépendant de l'horloge et de la charge (demande du chef, 18/11/2026) : l'instant exact du fait, et la réception entre le début et
        // la fin de l'envoi
        $this->assertSame($zoneAt, strtotime($written[1]['occurred_at'] . ' UTC'), 'l\'heure du boîtier, ramenée en UTC');
        $received = strtotime($written[0]['received_at'] . ' UTC');
        $this->assertTrue($received >= $sentFrom && $received <= $sentTo, 'l\'heure de réception, pendant l\'envoi');
        // Rejouer le même lot : rien de plus ; les faits reçus répondent « déjà connu »
        $replayed = $this->json($this->box('POST', '/faits', ['facts' => $batch], $token));
        $this->assertSame([19, 0, 4, 15, $before + 3], [$replayed['received'], $replayed['accepted'], $replayed['duplicates'], $replayed['refused'], $pieceEvents()]);
        // Aucun état ne change : la pièce et ses puces sont comme avant ; la fiche de la pièce raconte les lectures
        $this->assertSame(['created', null], array_values($this->db->fetchNumeric('SELECT status, zone FROM loss_piece WHERE id = ?', [$piece])));
        $this->assertSame(['associated', 'associated'], $this->db->fetchFirstColumn('SELECT status FROM loss_tag WHERE piece_id = ? ORDER BY id', [$piece]));
        $history = array_column($this->json($this->admin('GET', self::BASE . "/pieces/$piece"))['events'], 'label', 'type');
        $this->assertSame(['Lue par le lecteur à main du boîtier', 'Lue dans une zone'], [$history['SPOT_CHECK'], $history['ZONE_SEEN']]);
        $this->assertSame(3, $this->json($this->admin('GET', self::BOXES . "/$id"))['factsReceived']);
        foreach ([$this->box('GET', '/reglages', null, $token), $this->box('POST', '/faits', ['facts' => $batch], $token), $beat(['now' => $now(), 'devices' => $devices])] as $answer) {
            $this->assertStringNotContainsString('Blouson', (string) $answer->getContent(), 'aucune réponse faite au boîtier ne porte un nom d\'article');
            $this->assertStringNotContainsString("Canal $this->s", (string) $answer->getContent(), 'ni le nom du boîtier');
        }
        // Une puce retirée est lue quand même, et dite ; une date qui n'existe pas n'est pas une heure ; la clé va de 8 à 48 caractères
        $third = '04' . $this->s . 'FFEEDD';
        $thirdTag = $this->json($this->admin('POST', self::BASE . '/puces/associer', ['pieceId' => $piece, 'identifier' => $third, 'technology' => 'nfc']))['tag']['id'];
        $this->json($this->admin('POST', self::BASE . "/puces/$thirdTag/retirer", ['reason' => 'error', 'confirm' => true]));
        $long = str_pad("long-$this->s-", 48, 'x');
        $more = $this->json($this->box('POST', '/faits', ['facts' => [$fact('ret', ['data' => ['identifier' => $third]]), $fact('fev', ['at' => '2026-02-30T10:00:00Z']), $fact('x', ['key' => $long]), $fact('x', ['key' => $long . 'y'])]], $token));
        $this->assertSame([['accepted', null], ['refused', 'FACT_INVALID'], ['accepted', null], ['refused', 'FACT_INVALID']], array_map(fn (array $r): array => [$r['status'], $r['code']], $more['results']));
        $this->assertSame([['tagRetired' => true], "b$id:$long"], [json_decode((string) $this->db->fetchOne('SELECT data FROM loss_piece_event WHERE event_key = ?', ["b$id:" . $k('ret')]), true),
            $this->db->fetchOne('SELECT event_key FROM loss_piece_event WHERE box_id = ? ORDER BY id DESC LIMIT 1', [$id])]);
        $before = $pieceEvents() - 3; // la puce posée puis retirée a écrit ses propres faits : on repart du compte d'ici
        // Lot refusé en entier : forme, plafond, corps
        $this->assertSame(['facts' => 'REQUIRED'], $this->json($this->box('POST', '/faits', [], $token), 422)['fields']);
        $this->assertSame(['facts' => 'MUST_BE_LIST'], $this->json($this->box('POST', '/faits', ['facts' => []], $token), 422)['fields']);
        $this->assertSame(['facts' => 'MUST_BE_LIST'], $this->json($this->box('POST', '/faits', ['facts' => ['a' => $fact('q')]], $token), 422)['fields']);
        $tooMany = $this->json($this->box('POST', '/faits', ['facts' => array_fill(0, 201, $fact('r'))], $token), 422);
        $this->assertSame(['FACTS_LIMIT_EXCEEDED', 200, 201], [$tooMany['error'], $tooMany['max'], $tooMany['received']]);
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . self::CHANNEL . '/faits', [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json', 'HTTP_X_LOSS_BOX' => $token, 'HTTP_X_LOSS_BOX_MACHINE' => self::MACHINE], '{pas du json');
        $this->assertSame(['body' => 'INVALID_JSON'], $this->json($this->client->getResponse(), 422)['fields']);
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . self::CHANNEL . '/faits', [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json', 'HTTP_X_LOSS_BOX' => $token, 'HTTP_X_LOSS_BOX_MACHINE' => self::MACHINE],
            json_encode(['facts' => [$fact('s')], 'bourrage' => str_repeat('x', 262144)]));
        $this->assertSame('PAYLOAD_TOO_LARGE', $this->json($this->client->getResponse(), 413)['error']);
        $this->assertSame($before + 3, $pieceEvents());
        // Heure douteuse : horloge du boîtier décalée, ou fait daté du futur
        $this->json($beat(['now' => $now(-600), 'devices' => $devices]));
        $this->json($this->box('POST', '/faits', ['facts' => [$fact('t')]], $token));
        $this->json($beat(['now' => $now(), 'devices' => $devices]));
        $this->json($this->box('POST', '/faits', ['facts' => [$fact('u'), $fact('v', ['at' => $now(600)]), $fact('w9', ['at' => $now(-9 * 86400)]), $fact('w7', ['at' => $now(-7 * 86400)])]], $token));
        $this->assertSame([[$k('t'), true], [$k('u'), false], [$k('v'), true], [$k('w9'), true], [$k('w7'), false]], array_map(fn (array $r): array => [substr($r['event_key'], strlen("b$id:")), json_decode($r['data'], true)['clockDoubt'] ?? false],
            array_reverse($this->db->fetchAllAssociative('SELECT event_key, data FROM loss_piece_event WHERE box_id = ? ORDER BY id DESC LIMIT 5', [$id]))),
            'douteux : horloge décalée, fait daté du futur, fait plus vieux que la file du boîtier (8 jours)');
        // Deux boîtiers, la même clé de fait : chacun la sienne
        $this->ip = self::randomIp();
        $other = $this->json($this->admin('POST', self::BOXES, ['name' => "Second $this->s"]), 201);
        $otherToken = $this->json($enrol(['key' => $other['key'], 'machine' => 'seconde-machine-0123456789abcdef'] + $good))['token'];
        $this->machines[$otherToken] = 'seconde-machine-0123456789abcdef'; // lot L5-e : la preuve de SA machine
        $this->json($this->box('POST', '/signe-de-vie', ['now' => $now(), 'machine' => 'seconde-machine-0123456789abcdef', 'devices' => [['serial' => "LECTEUR-$this->s", 'kind' => 'reader', 'status' => 'ok']]], $otherToken));
        $this->assertSame('DEVICE_UNKNOWN', $this->json($this->box('POST', '/faits', ['facts' => [$fact('w', ['device' => "IMPR-$this->s"])]], $otherToken))['results'][0]['code'], 'l\'appareil d\'un autre boîtier');
        $this->assertSame('accepted', $this->json($this->box('POST', '/faits', ['facts' => [$fact('a')]], $otherToken))['results'][0]['status']);
        $this->assertSame([$other['box']['id'], 'b' . $other['box']['id'] . ':' . $k('a')], array_values($this->db->fetchNumeric('SELECT box_id, event_key FROM loss_piece_event ORDER BY id DESC LIMIT 1')));

        // Plus de 16 appareils dans un signe de vie : les 16 premiers sont pris, les suivants refusés et dits ; le signe de vie vaut
        $many = $this->json($beat(['now' => $now(), 'devices' => [...$devices, ...array_map(fn (int $i): array => ['serial' => "N$i-$this->s", 'kind' => 'reader', 'status' => 'ok'], range(1, 15))]]));
        $this->assertSame([[['serial' => "N15-$this->s", 'code' => 'TOO_MANY']], 16], [$many['refusedDevices'], (int) $this->db->fetchOne('SELECT COUNT(*) FROM loss_device WHERE box_id = ?', [$id])]);
        // Un boîtier qui a déjà annoncé 64 appareils (les remplacés comptent, rien ne se supprime) : le suivant est refusé et dit
        $this->db->executeStatement("INSERT INTO loss_device (box_id, serial, kind, status, exercise, first_seen_at, last_seen_at) SELECT ?, 'VIEUX-' || g, 'reader', 'down', false, NOW(), NOW() FROM generate_series(1, 48) g", [$id]);
        $crowded = $this->json($beat(['now' => $now(), 'devices' => [...$devices, ['serial' => "DE-TROP-$this->s", 'kind' => 'reader', 'status' => 'ok']]]));
        $this->assertSame([[['serial' => "DE-TROP-$this->s", 'code' => 'TOO_MANY']], 64], [$crowded['refusedDevices'], (int) $this->db->fetchOne('SELECT COUNT(*) FROM loss_device WHERE box_id = ?', [$id])]);
        $this->assertTrue($this->json($this->admin('GET', self::BOXES . "/$id"))['online']);
        // La fiche d'une pièce garde toute sa vie (créée, puces posées, retirée), et ses 200 dernières lectures seulement
        $life = array_values(array_filter(array_column($this->json($this->admin('GET', self::BASE . "/pieces/$piece"))['events'], 'type'), fn (string $t): bool => !in_array($t, ['SPOT_CHECK', 'ZONE_SEEN'], true)));
        $this->db->executeStatement("INSERT INTO loss_piece_event (type, occurred_at, piece_id, tag_id, source, event_key, exercise, data, box_id, device_id, received_at)"
            . " SELECT 'SPOT_CHECK', NOW(), ?, ?, 'box', 'b' || ? || ':masse-' || g, false, '{}', ?, ?, NOW() FROM generate_series(1, 205) g", [$piece, $tagIds[$nfc], $id, $id, $reader]);
        $full = array_column($this->json($this->admin('GET', self::BASE . "/pieces/$piece"))['events'], 'type');
        $this->assertSame([$life, 200, 'PIECE_CREATED'], [array_values(array_filter($full, fn (string $t): bool => !in_array($t, ['SPOT_CHECK', 'ZONE_SEEN'], true))),
            count(array_filter($full, fn (string $t): bool => in_array($t, ['SPOT_CHECK', 'ZONE_SEEN'], true))), $full[0]]);

        // ----- Rien d'autre n'a bougé : ni vente, ni journal de caisse, ni constat, ni pièce, ni stock (une puce de plus : celle retirée plus haut) ;
        // au journal du module, seulement l'alerte de sortie (lot L5-d) de la pièce non vendue lue à la sortie plus haut
        $alertsLogged = (int) $this->db->fetchOne("SELECT COUNT(*) FROM loss_journal WHERE type = 'ALERT_SENT'") - ($alertsBefore ?? 0);
        $this->assertSame(array_replace($watchedBefore, [3 => $watchedBefore[3] + $alertsLogged, 4 => $watchedBefore[4] + 1]), $watched());
        $this->assertLessThanOrEqual(1, $alertsLogged);

        // ----- Sans le droit du module : tout le canal est fermé, inscription comprise ; rien n'est écrit
        static::getContainer()->get(PlatformModeService::class)->setMode('live', 'test');
        $this->flags(true, false);
        $before = $pieceEvents();
        foreach ([['POST', '/inscription', $good, null], ['POST', '/jeton', ['machine' => self::MACHINE], $token], ['POST', '/signe-de-vie', ['now' => $now()], $token], ['GET', '/reglages', null, $token],
            ['POST', '/faits', ['facts' => [$fact('x')]], $token]] as [$method, $path, $body, $with]) {
            $closed = $this->json($this->box($method, $path, $body, $with), 403);
            $this->assertSame(['MODULE_INACTIVE', ['error', 'code', 'message']], [$closed['code'], array_keys($closed)], "$method $path : une réponse nue, sans rien dire de l'offre du site");
        }
        $this->assertSame($before, $pieceEvents());
        $this->flags(true, true);
        static::getContainer()->get(PlatformModeService::class)->setMode('demo', 'test');

        // ----- Clé remise à zéro, puis révocation : le jeton est refusé à l'appel suivant ; et un appel DÉJÀ authentifié, en route
        // pendant la révocation, n'écrit rien non plus (le boîtier est relu sous verrou avant toute écriture)
        $this->tenant();
        $channel = static::getContainer()->get(LossBoxChannelService::class);
        $stale = $channel->authenticate($token);
        $staleOther = $channel->authenticate($otherToken);
        $this->json($this->admin('POST', self::BOXES . '/' . $other['box']['id'] . '/cle', ['confirm' => true]));
        $this->assertSame('BOX_TOKEN_INVALID', $this->json($this->box('GET', '/reglages', null, $otherToken), 401)['error']);
        $this->assertSame('BOX_TOKEN_INVALID', $this->json($renew($otherToken, ['machine' => 'seconde-machine-0123456789abcdef']), 401)['error']);
        $this->json($this->admin('POST', self::BOXES . "/$id/revoquer", ['confirm' => true]));
        foreach ([['GET', '/reglages', null], ['POST', '/faits', ['facts' => [$fact('y')]]], ['POST', '/signe-de-vie', ['now' => $now()]], ['POST', '/jeton', ['machine' => self::MACHINE]]] as [$method, $path, $body]) {
            $this->assertSame('BOX_REVOKED', $this->json($this->box($method, $path, $body, $token), 403)['error'], "$method $path");
        }
        $this->assertSame('BOX_REVOKED', $this->json($enrol($good), 403)['error']);
        $this->tenant();
        $channel = static::getContainer()->get(LossBoxChannelService::class);
        $before = [$pieceEvents(), $count()];
        foreach (['un lot de faits après la révocation' => [fn () => $channel->facts($stale, ['facts' => [$fact('apres')]]), 'BOX_REVOKED', 403],
            'un signe de vie après la révocation' => [fn () => $channel->heartbeat($stale, ['now' => $now(), 'machine' => self::MACHINE, 'devices' => []]), 'BOX_REVOKED', 403],
            'un lot de faits après la remise à zéro de la clé' => [fn () => $channel->facts($staleOther, ['facts' => [$fact('apres')]]), 'BOX_TOKEN_INVALID', 401]] as $what => [$call, $error, $status]) {
            try {
                $call();
                $this->fail("$what : rien ne devait être écrit");
            } catch (LossBoxException $e) {
                $this->assertSame([$error, $status], [$e->error, $e->status], $what);
            }
        }
        $this->assertSame($before, [$pieceEvents(), $count()]);
    }

    public function testEnrolmentAttemptsAndBoxCallsAreRateLimited(): void
    {
        $ip = '11.' . random_int(1, 250) . '.' . random_int(1, 250) . '.' . random_int(1, 250);
        $try = fn (string $path): Response => $this->box('POST', $path, ['key' => 'IZ-BOX-AAAA-AAAA-AAAA', 'machine' => self::MACHINE, 'version' => '1', 'arch' => 'amd64'], null, ['REMOTE_ADDR' => $ip]);
        for ($i = 0; $i < LossBoxLimits::LIMITS['open']; ++$i) {
            $this->assertSame($i % 2 === 0 ? 404 : 401, $try($i % 2 === 0 ? '/inscription' : '/jeton')->getStatusCode(), "essai $i");
        }
        $limited = $try('/inscription');
        $this->assertSame([429, 'RATE_LIMITED'], [$limited->getStatusCode(), $this->json($limited, 429)['error']]);
        $this->assertGreaterThan(0, (int) $limited->headers->get('Retry-After'));
        $this->assertSame(429, $try('/jeton')->getStatusCode(), 'l\'échange de jeton partage le plafond');
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . '/api/pertes/boitier/%69nscription', [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $ip], '{}');
        $this->assertSame(429, $this->client->getResponse()->getStatusCode(), 'la route écrite avec un caractère encodé est la même route, sous le même plafond');
        $this->assertNotSame(429, $this->box('POST', '/inscription', [], null, ['REMOTE_ADDR' => '12.251.' . random_int(1, 250) . '.' . random_int(1, 250)])->getStatusCode(), 'une autre adresse n\'est pas touchée');

        // Les autres plafonds, avec des valeurs basses : par adresse tous sites confondus, jetons refusés, appels d'un boîtier, faits
        $limits = new LossBoxLimits(static::getContainer()->get('cache.rate_limiter'), ['open' => 1000, 'openGlobal' => 3, 'refused' => 2, 'calls' => 3, 'facts' => 5]);
        $site = "site-$this->s";
        $anIp = self::randomIp();
        $this->assertSame([null, null, null], [$limits->open("$site-a", $anIp), $limits->open("$site-b", $anIp), $limits->open("$site-c", $anIp)]);
        $this->assertGreaterThan(0, $limits->open("$site-d", $anIp), 'une adresse qui vise tous les sites est bornée');
        $this->assertNull($limits->open("$site-d", self::randomIp()));
        $this->assertSame([null, null], [$limits->call($site, 1), $limits->call($site, 2)]);
        $limits->call($site, 1);
        $limits->call($site, 1);
        $this->assertGreaterThan(0, $limits->call($site, 1), 'le quatrième appel du boîtier 1');
        $this->assertNull($limits->call($site, 2), 'le boîtier 2 a son propre compte');
        $this->assertSame([null, null], [$limits->facts($site, 1, 3), $limits->facts($site, 1, 2)]);
        $this->assertGreaterThan(0, $limits->facts($site, 1, 1), 'cinq faits envoyés : le sixième attend');
        $this->assertNull($limits->facts($site, 3, 5));
        // un jeton bien signé mais refusé : compté par boîtier ET par génération, jamais par adresse
        $this->assertNull($limits->refusedLeft($site, 1, 2));
        $limits->refused($site, 1, 2);
        $this->assertNull($limits->refusedLeft($site, 1, 2), 'lire le compte ne le fait pas avancer');
        $this->assertNull($limits->refusedLeft($site, 1, 2));
        $limits->refused($site, 1, 2);
        $this->assertGreaterThan(0, $limits->refusedLeft($site, 1, 2));
        $this->assertSame([null, null, null], [$limits->refusedLeft($site, 1, 3), $limits->refusedLeft($site, 2, 2), $limits->refusedLeft("$site-b", 1, 2)], 'une autre génération, un autre boîtier, un autre site : pas touchés');
        // des empreintes de machine fausses présentées avec un bon jeton : comptées par boîtier
        $narrow = new LossBoxLimits(static::getContainer()->get('cache.rate_limiter'), ['machine' => 2]);
        $this->assertNull($narrow->machineLeft($site, 1));
        $narrow->machineFailed($site, 1);
        $this->assertNull($narrow->machineLeft($site, 1));
        $narrow->machineFailed($site, 1);
        $this->assertGreaterThan(0, $narrow->machineLeft($site, 1));
        $this->assertNull($narrow->machineLeft($site, 2));

        // Le canal applique le plafond de faits par boîtier et par heure : le lot qui le dépasse est refusé en entier, sans rien écrire
        $quotaBox = $this->json($this->admin('POST', self::BOXES, ['name' => "Quota $this->s"]), 201);
        $quotaToken = $this->json($this->box('POST', '/inscription', ['key' => $quotaBox['key'], 'machine' => self::MACHINE, 'version' => '1.0.0', 'arch' => 'amd64'], null))['token'];
        $this->tenant();
        $c = static::getContainer();
        $metered = new LossBoxChannelService($c->get(\App\Services\TenantEntityManagerProvider::class), $c->get(\App\Services\TenantConnectionProvider::class), $c->get(LossBoxToken::class),
            $c->get(\App\LossPrevention\Services\LossHealthService::class), new LossBoxLimits($c->get('cache.rate_limiter'), ['facts' => 3]));
        $authenticated = $metered->authenticate($quotaToken);
        $two = ['facts' => [['key' => "quota-$this->s-1", 'type' => 'SPOT_CHECK'], ['key' => "quota-$this->s-2", 'type' => 'SPOT_CHECK']]];
        $this->assertSame(2, $metered->facts($authenticated, $two)['refused'], 'deux faits (refusés pour leur forme) : comptés quand même, ils ont été envoyés');
        try {
            $metered->facts($authenticated, $two);
            $this->fail('quatre faits envoyés sous un plafond de trois');
        } catch (LossBoxException $e) {
            $this->assertSame(['RATE_LIMITED', 429], [$e->error, $e->status]);
            $this->assertGreaterThan(0, $e->details['retryAfterSeconds']);
        }

        // Un jeton copié ne sert pas à deviner la machine : deux empreintes fausses, et les suivantes attendent. La bonne passe toujours
        // (lot L5-e, relecture : un voleur de jeton qui insiste ne fait pas taire le vrai boîtier)
        $guessing = new LossBoxChannelService($c->get(\App\Services\TenantEntityManagerProvider::class), $c->get(\App\Services\TenantConnectionProvider::class), $c->get(LossBoxToken::class),
            $c->get(\App\LossPrevention\Services\LossHealthService::class), new LossBoxLimits($c->get('cache.rate_limiter'), ['machine' => 2]));
        $beatAs = function (string $machine) use ($guessing, $authenticated): array {
            try {
                $guessing->heartbeat($authenticated, ['now' => gmdate('Y-m-d\TH:i:s\Z'), 'machine' => $machine, 'devices' => []]);

                return ['ok', 200];
            } catch (LossBoxException $e) {
                return [$e->error, $e->status];
            }
        };
        $this->assertSame([['ok', 200], ['BOX_TOKEN_INVALID', 401], ['BOX_TOKEN_INVALID', 401], ['RATE_LIMITED', 429], ['ok', 200]],
            [$beatAs(self::MACHINE), $beatAs('une-fausse-machine-0000000001'), $beatAs('une-fausse-machine-0000000002'), $beatAs('une-fausse-machine-0000000003'), $beatAs(self::MACHINE)]);

        // La garde applique ces plafonds. Appels d'un boîtier : le quatrième est arrêté, sans boîtier posé
        $created = $this->json($this->admin('POST', self::BOXES, ['name' => "Plafonds $this->s"]), 201);
        $token = $this->json($this->box('POST', '/inscription', ['key' => $created['key'], 'machine' => self::MACHINE, 'version' => '1.0.0', 'arch' => 'amd64'], null))['token'];
        $this->tenant();
        $guardIp = self::randomIp();
        $guardWith = fn (array $low): \Closure => function (?string $with) use ($c, $low, $guardIp): \Symfony\Component\HttpKernel\Event\RequestEvent {
            $guard = new LossBoxGuardSubscriber($c->get(LossBoxChannelService::class), $c->get(\App\Services\TenantConnectionProvider::class), new LossBoxLimits($c->get('cache.rate_limiter'), $low));
            $request = \Symfony\Component\HttpFoundation\Request::create('/api/pertes/boitier/reglages', 'GET', [], [], [], ['REMOTE_ADDR' => $guardIp] + ($with !== null ? ['HTTP_X_LOSS_BOX' => $with, 'HTTP_X_LOSS_BOX_MACHINE' => self::MACHINE] : []));
            $event = new \Symfony\Component\HttpKernel\Event\RequestEvent(static::$kernel, $request, \Symfony\Component\HttpKernel\HttpKernelInterface::MAIN_REQUEST);
            $guard->onKernelRequest($event);

            return $event;
        };
        $ask = $guardWith(['calls' => 3]);
        for ($i = 0; $i < 3; ++$i) {
            $event = $ask($token);
            $this->assertSame([false, $created['box']['id']], [$event->hasResponse(), (int) $event->getRequest()->attributes->get(LossBoxGuardSubscriber::ATTRIBUTE)['id']], "appel $i");
        }
        $fourth = $ask($token);
        $this->assertSame([429, null], [$fourth->getResponse()?->getStatusCode(), $fourth->getRequest()->attributes->get(LossBoxGuardSubscriber::ATTRIBUTE)], 'le quatrième appel du boîtier est arrêté, sans boîtier posé');

        // Jetons refusés. Du bruit (pas d'en-tête, jeton mal signé) n'entame aucun compte : envoyé depuis le réseau de la boutique,
        // il ne fait pas taire ses boîtiers. Un jeton bien signé d'une génération passée (l'ancienne machine qui insiste) est
        // arrêté au troisième appel, lui seul : le jeton de la génération en cours passe toujours
        $ask = $guardWith(['refused' => 2]);
        for ($i = 0; $i < 6; ++$i) {
            $this->assertSame([401, 401], [$ask('du-bruit')->getResponse()?->getStatusCode(), $ask(null)->getResponse()?->getStatusCode()], "bruit $i");
        }
        $this->assertFalse($ask($token)->hasResponse(), 'après le bruit, le bon jeton passe');
        $old = static::getContainer()->get(LossBoxToken::class)->issue(MV_TEST_TENANT_CODE, $created['box']['id'], 1)['token'];
        $this->assertSame([401, 401, 429], [$ask($old)->getResponse()?->getStatusCode(), $ask($old)->getResponse()?->getStatusCode(), $ask($old)->getResponse()?->getStatusCode()]);
        $this->assertFalse($ask($token)->hasResponse(), 'la machine qui a remplacé l\'ancienne n\'est pas arrêtée avec elle');
    }

    public function testTheChannelControllerRefusesToWorkWithoutTheBoxSetByTheGuard(): void
    {
        // Le contrôleur appelé SANS la garde (comme si elle avait été débranchée) : il ne travaille pas pour un inconnu
        $this->tenant();
        $controller = static::getContainer()->get(\App\LossPrevention\Controller\LossBoxChannelController::class);
        $before = array_map('intval', $this->db->fetchNumeric('SELECT (SELECT COUNT(*) FROM loss_piece_event), (SELECT COUNT(*) FROM loss_box_event), (SELECT COUNT(*) FROM loss_device)'));
        foreach (['settings', 'facts', 'heartbeat'] as $action) {
            foreach ([[], ['_loss_box' => 'un texte'], ['_loss_box' => ['id' => 1]]] as $attributes) {
                $r = \Symfony\Component\HttpFoundation\Request::create('/api/pertes/boitier/x', 'POST', [], [], [], [],
                    json_encode(['facts' => [['key' => "sans-garde-$this->s", 'type' => 'SPOT_CHECK']], 'now' => gmdate('Y-m-d\TH:i:s\Z'), 'machine' => self::MACHINE, 'devices' => []]));
                $r->attributes->add($attributes);
                $response = $controller->{$action}($r);
                $this->assertSame([401, 'BOX_TOKEN_REQUIRED'], [$response->getStatusCode(), json_decode((string) $response->getContent(), true)['error']], "$action sans boîtier posé par la garde");
            }
        }
        $this->assertSame($before, array_map('intval', $this->db->fetchNumeric('SELECT (SELECT COUNT(*) FROM loss_piece_event), (SELECT COUNT(*) FROM loss_box_event), (SELECT COUNT(*) FROM loss_device)')));
    }

    public function testTheSimulatedBoxPlaysScenariosIsErasedAndNeverMixesWithRealBoxes(): void
    {
        $realBefore = array_map('intval', $this->db->fetchNumeric('SELECT (SELECT COUNT(*) FROM loss_box WHERE NOT exercise), (SELECT COUNT(*) FROM loss_box_event WHERE NOT exercise), (SELECT COUNT(*) FROM loss_piece_event WHERE NOT exercise)'));
        $real = $this->json($this->admin('POST', self::BOXES, ['name' => "Réel $this->s"]), 201)['box']['id'];
        $start = $this->json($this->admin('GET', self::EX . '/boitiers'));
        $this->assertSame([[], 0, 3, true, 'pertes.boitiers', ['heartbeat', 'network_cut', 'device_down', 'device_up', 'spot_check', 'zone_exit', 'counter_put', 'counter_take', 'print_ok', 'print_refused', 'print_no_labels', 'zone_move']],
            [$start['items'], $start['total'], $start['limit'], $start['exercise'], $start['screen'], array_column($start['scenarios'], 'code')]);
        $this->assertSame(['code' => 'network_cut', 'label' => 'Coupure du réseau', 'text' => 'Le boîtier revient après deux heures sans réseau : un fait dit le trou.'], $start['scenarios'][1]);
        $install = fn (bool $exercise): array => array_intersect_key(array_column($this->json($this->admin('GET', $exercise ? self::EX . '/installation' : self::BASE . '/installation'))['optionalSteps'], 'status', 'code'), ['box' => 0]);
        $this->assertSame([['box' => 'todo'], ['box' => 'todo']], [$install(true), $install(false)]);

        $created = $this->json($this->admin('POST', self::EX . '/boitiers'), 201);
        $this->assertSame(['box', 'scenarios'], array_keys($created));
        $box = $created['box'];
        $id = $box['id'];
        $this->assertSame(['Boîtier simulé', 'Exercice', 'active', true, 'En ligne', null, true, ['BOX_CREATED', 'BOX_ENROLLED', 'DEVICE_SEEN', 'DEVICE_SEEN'], 0],
            [$box['name'], $box['place'], $box['status'], $box['online'], $box['onlineLabel'], $box['keyHint'], $box['exercise'], array_slice(array_reverse(array_column($box['events'], 'type')), 0, 4), $box['factsReceived']]);
        $this->assertSame([['SIM-LECTEUR-SORTIE', 'reader', 'ok', 'exit', 'Sortie'], ['SIM-LECTEUR-MAIN', 'reader', 'ok', null, null], ['SIM-TAPIS', 'counter_mat', 'ok', null, null],
            ['SIM-IMPRIMANTE', 'printer', 'ok', null, null], // l'imprimante à puce simulée (lot L3-c)
            ['SIM-ANTENNE-RESERVE', 'reader', 'ok', 'stockroom', 'Réserve'], ['SIM-ANTENNE-RAYON', 'reader', 'ok', 'floor', 'Rayon'], // les antennes de zone simulées (lot L4-a)
            ['SIM-AVERTISSEUR', 'signal', 'ok', null, null]], // l'avertisseur du portique simulé (lot L5-d)
            array_map(fn (array $d): array => [$d['serial'], $d['kind'], $d['status'], $d['zone'], $d['zoneLabel']], $box['devices']));
        $this->assertSame([null, null, true], array_values($this->db->fetchNumeric('SELECT key_hash, machine_hash, exercise FROM loss_box WHERE id = ?', [$id])), 'ni clé ni machine : personne ne s\'y inscrit');
        // l'assistant d'exercice voit le boîtier simulé ; l'assistant réel, non (son boîtier attend encore sa machine)
        $this->assertSame([['box' => 'done'], ['box' => 'todo']], [$install(true), $install(false)]);
        $this->assertSame(['code' => 'box', 'verified' => true, 'hint' => null], $this->json($this->admin('POST', self::EX . '/installation/etapes/box/verifier', [])));
        $verify = $this->json($this->admin('POST', self::BASE . '/installation/etapes/box/verifier', []));
        $this->assertSame(['box', false], [$verify['code'], $verify['verified']]);
        $this->assertStringContainsString('attend sa machine', $verify['hint'], 'l\'étape dit quoi essayer d\'après l\'état du boîtier');
        $steps = $this->json($this->admin('GET', self::EX . '/installation'));
        $this->assertSame([4, ['code' => 'box', 'title' => 'Brancher un boîtier', 'image' => 'pertes/installation/box', 'status' => 'done', 'verify' => ['kind' => 'data', 'label' => 'Vérifier'], 'state' => 'ok', 'hint' => null]],
            [$steps['progress']['total'], array_diff_key($steps['optionalSteps'][0], ['text' => 0])], 'une étape facultative ne compte pas dans l\'avancement');
        // un boîtier simulé n'a pas de jeton : même signé par le serveur, il est refusé
        $forged = static::getContainer()->get(LossBoxToken::class)->issue(MV_TEST_TENANT_CODE, $id, 1)['token'];
        $this->assertSame('BOX_TOKEN_INVALID', $this->json($this->box('GET', '/reglages', null, $forged), 401)['error']);
        $this->assertSame('BOX_TOKEN_INVALID', $this->json($this->box('POST', '/jeton', ['machine' => self::MACHINE], $forged), 401)['error']);

        // Scénarios
        $play = fn (mixed $scenario, int $status = 200): array => $this->json($this->admin('POST', self::EX . "/boitiers/$id/scenarios", ['scenario' => $scenario]), $status);
        $this->assertSame(['scenario' => 'REQUIRED'], $this->json($this->admin('POST', self::EX . "/boitiers/$id/scenarios", []), 422)['fields']);
        $this->assertSame(['scenario' => 'INVALID_VALUE'], $play('incendie', 422)['fields']);
        $this->assertSame(['scenario' => 'INVALID_VALUE'], $play(['heartbeat'], 422)['fields']);
        $this->db->executeStatement("UPDATE loss_box SET last_seen_at = NOW() AT TIME ZONE 'UTC' - INTERVAL '1 hour' WHERE id = ?", [$id]);
        $this->assertFalse($this->json($this->admin('GET', self::EX . "/boitiers/$id"))['online']);
        $beat = $play('heartbeat');
        $this->assertSame(['scenario', 'written', 'box'], array_keys($beat));
        $this->assertSame(['heartbeat', 0, true], [$beat['scenario'], $beat['written'], $beat['box']['online']]);
        $cut = $play('network_cut');
        $this->assertSame([1, 'BOX_BACK', 7200, 'Boîtier revenu après un silence'], [$cut['written'], $cut['box']['events'][0]['type'], $cut['box']['events'][0]['silentSeconds'], $cut['box']['events'][0]['label']]);
        $down = $play('device_down');
        $this->assertSame([1, 'SOURCE_DOWN', 'SIM-LECTEUR-SORTIE', 'down'], [$down['written'], $down['box']['events'][0]['type'], $down['box']['events'][0]['device'], $down['box']['devices'][0]['status']]);
        $this->assertSame(0, $play('device_down')['written'], 'déjà en panne : rien de plus');
        $up = $play('device_up');
        $this->assertSame([1, 'SOURCE_UP', 'ok'], [$up['written'], $up['box']['events'][0]['type'], $up['box']['devices'][0]['status']]);
        $this->assertSame(0, $play('device_up')['written']);
        // lectures : il faut une pièce d'exercice qui porte une puce
        $none = $play('spot_check', 409);
        $this->assertSame('EXERCISE_NO_PIECE', $none['error']);
        $this->assertStringContainsString('posez d\'abord une puce simulée', $none['message']);
        [, [$variant]] = $this->article('Robe', ["ROBE-$this->s"]);
        $pieces = [];
        for ($i = 0; $i < 4; ++$i) {
            $pieces[] = $this->json($this->admin('POST', self::EX . '/puces/associer', ['variantId' => $variant]))['piece']['id'];
        }
        $spot = $play('spot_check');
        $this->assertSame([3, 3], [$spot['written'], $spot['box']['factsReceived']]);
        $exit = $play('zone_exit');
        $this->assertSame([1, 5], [$exit['written'], $exit['box']['factsReceived']], 'la lecture à la sortie, et l\'avertisseur simulé qui sonne (lot L5-d)');
        $facts = $this->db->fetchAllAssociative('SELECT type, piece_id, source, event_key, exercise, data, box_id, device_id FROM loss_piece_event WHERE box_id = ? ORDER BY id', [$id]);
        $reader = array_column($exit['box']['devices'], 'id', 'serial');
        $this->assertSame([['SPOT_CHECK', $pieces[3], 'exercise', null, true, [], $reader['SIM-LECTEUR-MAIN']], ['SPOT_CHECK', $pieces[2], 'exercise', null, true, [], $reader['SIM-LECTEUR-MAIN']],
            ['SPOT_CHECK', $pieces[1], 'exercise', null, true, [], $reader['SIM-LECTEUR-MAIN']], ['ZONE_SEEN', $pieces[3], 'exercise', null, true, ['zone' => 'exit'], $reader['SIM-LECTEUR-SORTIE']],
            ['EXIT_SIGNAL', $pieces[3], 'exercise', null, true, ['zone' => 'exit'], $reader['SIM-AVERTISSEUR']]],
            array_map(fn (array $f): array => [$f['type'], (int) $f['piece_id'], $f['source'], $f['event_key'], (bool) $f['exercise'], json_decode($f['data'], true), (int) $f['device_id']], $facts));
        $this->assertSame(['Lue par le lecteur à main du boîtier', 'Lue dans une zone', 'Le portique a sonné'],
            array_slice(array_column($this->json($this->admin('GET', self::EX . "/pieces/$pieces[3]"))['events'], 'label'), -3), 'la fiche de la pièce d\'exercice raconte les lectures');
        $this->assertSame('created', $this->db->fetchOne('SELECT status FROM loss_piece WHERE id = ?', [$pieces[3]]), 'aucun état ne change');

        // Plafond : trois boîtiers simulés ; listes étanches ; ouvert sans le droit du module
        $this->json($this->admin('POST', self::EX . '/boitiers'), 201);
        $this->json($this->admin('POST', self::EX . '/boitiers?locale=en'), 201);
        $full = $this->json($this->admin('POST', self::EX . '/boitiers'), 409);
        $this->assertSame(['EXERCISE_LIMIT_REACHED', 3], [$full['error'], $full['limit']]);
        $this->assertSame([3, ['Boîtier simulé', 'Boîtier simulé', 'Simulated box']], [($list = $this->json($this->admin('GET', self::EX . '/boitiers')))['total'], array_column($list['items'], 'name')]);
        $this->assertSame([], array_filter($this->json($this->admin('GET', self::BOXES))['items'], fn (array $b): bool => $b['exercise']), 'la liste réelle ne montre aucun boîtier simulé');
        $this->assertNotContains($real, array_column($list['items'], 'id'));
        static::getContainer()->get(PlatformModeService::class)->setMode('live', 'test');
        $this->flags(true, false);
        $this->assertSame(200, $this->admin('GET', self::EX . '/boitiers')->getStatusCode());
        $this->assertSame(200, $this->admin('GET', self::EX . "/boitiers/$id")->getStatusCode());
        $this->assertSame(200, $this->admin('POST', self::EX . "/boitiers/$id/scenarios", ['scenario' => 'heartbeat'])->getStatusCode());
        $this->assertSame(409, $this->admin('POST', self::EX . '/boitiers')->getStatusCode(), 'ouverte : c\'est le plafond qui répond');
        $this->assertSame(401, $this->call('POST', self::EX . '/boitiers', [], null)->getStatusCode());
        $this->flags(true, true);
        static::getContainer()->get(PlatformModeService::class)->setMode('demo', 'test');

        // Effacer : les boîtiers simulés, leurs appareils et leurs faits partent ; les pièces d'exercice restent ; rien de réel ne bouge
        $deleted = $this->json($this->admin('DELETE', self::EX . '/boitiers'));
        $this->assertSame(['deleted' => 3], $deleted);
        $this->assertSame([0, 0, 0, 0, 4], array_map('intval', $this->db->fetchNumeric('SELECT (SELECT COUNT(*) FROM loss_box WHERE exercise), (SELECT COUNT(*) FROM loss_device WHERE exercise),'
            . ' (SELECT COUNT(*) FROM loss_box_event WHERE exercise), (SELECT COUNT(*) FROM loss_piece_event WHERE exercise AND box_id IS NOT NULL), (SELECT COUNT(*) FROM loss_piece WHERE exercise)')));
        $this->assertSame(['deleted' => 0], $this->json($this->admin('DELETE', self::EX . '/boitiers')));
        $this->assertSame(['box' => 'todo'], $install(true));
        static::getContainer()->get(PlatformModeService::class)->setMode('live', 'test');
        $this->flags(true, false);
        $this->assertSame(['deleted' => 0], $this->json($this->admin('DELETE', self::EX . '/boitiers')), 'effacer ses exercices reste ouvert sans le droit du module');
        $this->flags(true, true);
        static::getContainer()->get(PlatformModeService::class)->setMode('demo', 'test');
        // effacer les pièces d'exercice quand un boîtier simulé a lu leurs puces : les faits partent avec elles, le boîtier reste
        $again = $this->json($this->admin('POST', self::EX . '/boitiers'), 201)['box']['id'];
        $this->assertSame(3, $this->json($this->admin('POST', self::EX . "/boitiers/$again/scenarios", ['scenario' => 'spot_check']))['written']);
        $this->json($this->admin('DELETE', self::EX . '/pieces'));
        $this->assertSame(0, $this->json($this->admin('GET', self::EX . "/boitiers/$again"))['factsReceived']);
        $this->assertSame(['deleted' => 1], $this->json($this->admin('DELETE', self::EX . '/boitiers')));
        $this->assertSame([$realBefore[0] + 1, $realBefore[1] + 1, $realBefore[2]],
            array_map('intval', $this->db->fetchNumeric('SELECT (SELECT COUNT(*) FROM loss_box WHERE NOT exercise), (SELECT COUNT(*) FROM loss_box_event WHERE NOT exercise), (SELECT COUNT(*) FROM loss_piece_event WHERE NOT exercise)')),
            'le monde réel : le boîtier créé par ce test et son fait, rien d\'autre');

        // Les écritures d'exercice attendent le verrou des exercices (un effacement en cours ne laisse rien derrière lui)
        $service = static::getContainer()->get(LossBoxService::class);
        $conn = $this->tenant()->getEntityManager()->getConnection();
        $other = \Doctrine\DBAL\DriverManager::getConnection($conn->getParams());
        $conn->executeStatement("SET lock_timeout = '1s'");
        try {
            $other->executeStatement("SET idle_in_transaction_session_timeout = '20s'");
            $other->beginTransaction();
            $other->executeStatement('SELECT pg_advisory_xact_lock(' . \App\LossPrevention\Repository\LossPieceRepository::EXERCISE_LOCK . ')');
            foreach (['créer' => fn () => $service->createExercise('fr', $this->adminId), 'effacer' => fn () => $service->deleteExercise(), 'jouer' => fn () => $service->play(1, ['scenario' => 'heartbeat'], 'fr')] as $what => $write) {
                try {
                    $write();
                    $this->fail("$what : devait attendre le verrou des exercices");
                } catch (\Doctrine\DBAL\Exception $e) {
                    $this->assertStringContainsString('lock timeout', $e->getMessage(), $what);
                }
                $this->assertFalse($conn->isTransactionActive());
            }
            $other->rollBack();
            $this->assertSame(0, (int) $conn->fetchOne('SELECT COUNT(*) FROM loss_box WHERE exercise'));
        } finally {
            $conn->executeStatement('SET lock_timeout = 0');
            try {
                if ($other->isTransactionActive()) {
                    $other->rollBack();
                }
            } catch (\Throwable) {
                // connexion déjà coupée par la garde
            }
            $other->close();
        }
    }

    public function testTheHealthBoardShowsEachBoxStateAndTheSilenceThresholdIsAdjustable(): void
    {
        $board = fn (string $query = ''): array => $this->json($this->admin('GET', self::BASE . '/sante' . $query));
        $line = fn (array $d): array => [$d['code'], $d['kind'], $d['label'], $d['state'], $d['stateLabel']];
        $writes = fn (): array => array_map('intval', $this->db->fetchNumeric('SELECT (SELECT COUNT(*) FROM loss_box_event), (SELECT COUNT(*) FROM loss_journal), (SELECT COUNT(*) FROM loss_setting)'));

        // Aucun boîtier : une ligne « pas installé », et quoi faire
        $empty = $board();
        $this->assertSame(['devices', 'summary', 'thresholdSeconds', 'exercise', 'screen', 'checkedAt'], array_keys($empty));
        $this->assertSame([['code' => 'box', 'kind' => 'box', 'label' => 'Boîtier', 'zone' => null, 'cashRegisterId' => null, 'state' => 'absent', 'stateLabel' => 'Pas installé', 'lastSeenAt' => null,
            'silentSince' => null, 'silentSeconds' => null, 'hint' => 'Aucun boîtier n\'est installé. Ajoutez un boîtier, puis inscrivez sa machine avec la clé.']], $empty['devices']);
        $this->assertSame([['ok' => 0, 'pending' => 0, 'down' => 0, 'absent' => 1, 'allOk' => false, 'label' => 'Aucun appareil installé'], 300, false, 'pertes.boitiers'],
            [$empty['summary'], $empty['thresholdSeconds'], $empty['exercise'], $empty['screen']]);
        $this->assertEqualsWithDelta(time(), strtotime($empty['checkedAt']), 5);

        // Un boîtier qui attend sa machine, puis inscrit : en ordre ; le bandeau le dit
        $created = $this->json($this->admin('POST', self::BOXES, ['name' => "Santé $this->s"]), 201);
        $id = $created['box']['id'];
        $pending = $board();
        $this->assertSame([["box-$id", 'box', "Santé $this->s", 'pending', 'En attente']], array_map($line, $pending['devices']));
        $this->assertStringContainsString('attend sa machine', $pending['devices'][0]['hint']);
        $this->assertSame(['ok' => 0, 'pending' => 1, 'down' => 0, 'absent' => 0, 'allOk' => false, 'label' => '1 appareil à vérifier'], $pending['summary']);
        $token = $this->json($this->box('POST', '/inscription', ['key' => $created['key'], 'machine' => self::MACHINE, 'version' => '1.0.0', 'arch' => 'amd64'], null))['token'];
        $before = $writes();
        $ok = $board();
        $this->assertSame([[["box-$id", 'box', "Santé $this->s", 'ok', 'En ordre']], null, null, null, ['ok' => 1, 'pending' => 0, 'down' => 0, 'absent' => 0, 'allOk' => true, 'label' => 'Tout est en ordre']],
            [array_map($line, $ok['devices']), $ok['devices'][0]['hint'], $ok['devices'][0]['silentSince'], $ok['devices'][0]['silentSeconds'], $ok['summary']]);
        $this->assertEqualsWithDelta(time(), strtotime($ok['devices'][0]['lastSeenAt']), 5);
        $this->assertSame(['All good', 'Everything is in order'], [($en = $board('?locale=en'))['devices'][0]['stateLabel'], $en['summary']['label']]);
        $install = fn (): array => $this->json($this->admin('GET', self::BASE . '/installation'))['optionalSteps'][0];
        $this->assertSame(['done', 'ok', null], [($step = $install())['status'], $step['state'], $step['hint']]);
        $this->assertSame(['code' => 'box', 'verified' => true, 'hint' => null], $this->json($this->admin('POST', self::BASE . '/installation/etapes/box/verifier', [])));

        // Silencieux : depuis quand, et quoi essayer ; l'étape de l'assistant reprend l'état et le conseil
        $this->db->executeStatement("UPDATE loss_box SET last_seen_at = NOW() AT TIME ZONE 'UTC' - INTERVAL '630 seconds' WHERE id = ?", [$id]);
        $down = $board()['devices'][0];
        $this->assertSame([["box-$id", 'box', "Santé $this->s", 'down', 'Silencieux'], $down['lastSeenAt'], 'Silencieux depuis 10 min. Vérifiez son alimentation, son câble réseau et la connexion Internet de la boutique.'],
            [$line($down), $down['silentSince'], $down['hint']]);
        $this->assertEqualsWithDelta(630, $down['silentSeconds'], 5);
        $this->assertEqualsWithDelta(time() - 630, strtotime($down['silentSince']), 5);
        $this->assertSame(['todo', 'down', $down['hint']], [($step = $install())['status'], $step['state'], $step['hint']]);
        $this->assertSame(['code' => 'box', 'verified' => false, 'hint' => $down['hint']], $this->json($this->admin('POST', self::BASE . '/installation/etapes/box/verifier', [])));
        $this->assertSame('Silent for 10 min. Check its power supply, its network cable and the shop\'s Internet connection.', $board('?locale=en')['devices'][0]['hint']);
        foreach (['3 hours' => 'depuis 3 h.', '119 minutes' => 'depuis 119 min.', '50 hours' => 'depuis 2 j.'] as $interval => $said) {
            $this->db->executeStatement("UPDATE loss_box SET last_seen_at = NOW() AT TIME ZONE 'UTC' - INTERVAL '$interval' - INTERVAL '5 seconds' WHERE id = ?", [$id]);
            $this->assertStringContainsString($said, $board()['devices'][0]['hint'], $interval);
        }
        $this->db->executeStatement("UPDATE loss_box SET last_seen_at = NOW() AT TIME ZONE 'UTC' - INTERVAL '630 seconds' WHERE id = ?", [$id]);
        $this->assertSame($before, $writes(), 'lire le tableau et l\'assistant n\'écrit rien');

        // Le délai de silence se règle par site : 120 à 3600 secondes ; il vaut pour la pastille, « en ligne » et le fait du retour
        $set = fn (mixed $body, int $status = 200): array => $this->json($this->admin('PUT', self::BASE . '/sante/seuil', $body), $status);
        $this->assertSame(['silentAfterSeconds' => 'REQUIRED'], $set([], 422)['fields']);
        $this->assertSame(['silentAfterSeconds' => 'MUST_BE_INTEGER'], $set(['silentAfterSeconds' => '900'], 422)['fields']);
        $this->assertSame(['silentAfterSeconds' => 'PARAM_OUT_OF_RANGE'], $set(['silentAfterSeconds' => 179], 422)['fields']);
        $this->assertSame(['silentAfterSeconds' => 'PARAM_OUT_OF_RANGE'], $set(['silentAfterSeconds' => 3601], 422)['fields']);
        $this->assertSame($before, $writes());
        $this->assertSame(['thresholdSeconds' => 900, 'default' => 300, 'min' => 180, 'max' => 3600], $set(['silentAfterSeconds' => 900]));
        $this->assertSame([$before[0], $before[1] + 1, ['fields' => ['silent_after_seconds']], $this->adminId],
            [$writes()[0], $writes()[1], json_decode((string) $this->db->fetchOne("SELECT data FROM loss_journal WHERE type = 'SETTINGS_CHANGED' ORDER BY id DESC LIMIT 1"), true),
                (int) $this->db->fetchOne("SELECT user_id FROM loss_journal WHERE type = 'SETTINGS_CHANGED' ORDER BY id DESC LIMIT 1")]);
        $set(['silentAfterSeconds' => 900]);
        $this->assertSame($before[1] + 1, $writes()[1], 'la même valeur : rien au journal');
        $wide = $board();
        $this->assertSame([900, 'ok', true], [$wide['thresholdSeconds'], $wide['devices'][0]['state'], $wide['summary']['allOk']], 'dix minutes de silence : en ordre avec un délai de quinze');
        $this->assertTrue($this->json($this->admin('GET', self::BOXES . "/$id"))['online']);
        $beat = fn (): Response => $this->box('POST', '/signe-de-vie', ['now' => gmdate('Y-m-d\TH:i:s\Z'), 'machine' => self::MACHINE, 'devices' => []], $token);
        $backs = fn (): int => (int) $this->db->fetchOne("SELECT COUNT(*) FROM loss_box_event WHERE box_id = ? AND type = 'BOX_BACK'", [$id]);
        $this->json($beat());
        $this->assertSame(0, $backs(), 'dix minutes de silence sous un délai de quinze : pas de fait de retour');
        $this->assertSame(['thresholdSeconds' => 180, 'default' => 300, 'min' => 180, 'max' => 3600], $set(['silentAfterSeconds' => 180]));
        $this->db->executeStatement("UPDATE loss_box SET last_seen_at = NOW() AT TIME ZONE 'UTC' - INTERVAL '4 minutes' WHERE id = ?", [$id]);
        $this->assertSame(['down', false], [$board()['devices'][0]['state'], $this->json($this->admin('GET', self::BOXES . "/$id"))['online']]);
        $this->json($beat());
        $this->assertSame(1, $backs(), 'quatre minutes de silence sous un délai de trois : le retour est un fait');
        // une valeur hors bornes écrite en base par un autre chemin est ramenée dans les bornes à la lecture
        foreach ([5 => 180, 99999 => 3600] as $stored => $read) {
            $this->db->executeStatement('UPDATE loss_setting SET silent_after_seconds = ?', [$stored]);
            $this->assertSame($read, $board()['thresholdSeconds']);
        }
        $this->assertSame(300, $set(['silentAfterSeconds' => null])['thresholdSeconds'], 'null : le délai par défaut');
        $this->assertNull($this->db->fetchOne('SELECT silent_after_seconds FROM loss_setting ORDER BY id LIMIT 1'));
        // Une machine qui se réinscrit après un silence : le trou est un fait, comme au retour d'un signe de vie
        $this->db->executeStatement("UPDATE loss_box SET last_seen_at = NOW() AT TIME ZONE 'UTC' - INTERVAL '630 seconds' WHERE id = ?", [$id]);
        $this->json($this->box('POST', '/inscription', ['key' => $created['key'], 'machine' => self::MACHINE, 'version' => '1.0.1', 'arch' => 'amd64'], null));
        $this->assertSame([2, ['BOX_BACK', 'BOX_ENROLLED']], [$backs(), array_reverse($this->db->fetchFirstColumn('SELECT type FROM loss_box_event WHERE box_id = ? ORDER BY id DESC LIMIT 2', [$id]))]);
        $this->json($this->box('POST', '/inscription', ['key' => $created['key'], 'machine' => self::MACHINE, 'version' => '1.0.1', 'arch' => 'amd64'], null));
        $this->assertSame(2, $backs(), 'réinscrite sans silence : pas de fait de retour');

        // Plusieurs boîtiers : le bandeau n'est « en ordre » que si tous le sont ; un boîtier révoqué ne compte plus
        $second = $this->json($this->admin('POST', self::BOXES, ['name' => "Santé bis $this->s"]), 201)['box']['id'];
        $two = $board();
        $this->assertSame([['ok', 'pending'], ['ok' => 1, 'pending' => 1, 'down' => 0, 'absent' => 0, 'allOk' => false, 'label' => '1 appareil à vérifier']], [array_column($two['devices'], 'state'), $two['summary']]);
        $this->assertSame(['done', 'ok'], [($step = $install())['status'], $step['state']], 'un boîtier en ordre suffit à l\'étape');
        $this->db->executeStatement("UPDATE loss_box SET last_seen_at = NOW() AT TIME ZONE 'UTC' - INTERVAL '1 hour' WHERE id = ?", [$id]);
        $this->assertSame([['ok' => 0, 'pending' => 1, 'down' => 1, 'absent' => 0, 'allOk' => false, 'label' => '2 appareils à vérifier'], 'down'], [$board()['summary'], $install()['state']]);
        $this->json($this->admin('POST', self::BOXES . "/$id/revoquer", ['confirm' => true]));
        $this->assertSame(["box-$second"], array_column($board()['devices'], 'code'));
        $this->assertSame('pending', $install()['state']);

        // Exercice : le même tableau sur les boîtiers simulés, étanche
        $exercise = fn (): array => $this->json($this->admin('GET', self::EX . '/sante'));
        $this->assertSame([['absent'], true, false, 'Aucun appareil installé', 'Aucun boîtier simulé. Créez-en un pour voir le tableau vivre, sans aucun matériel.'],
            [array_column(($none = $exercise())['devices'], 'state'), $none['exercise'], $none['summary']['allOk'], $none['summary']['label'], $none['devices'][0]['hint']]);
        $simulated = $this->json($this->admin('POST', self::EX . '/boitiers'), 201)['box']['id'];
        // le boîtier simulé a son tapis simulé (lot L3-b1), son imprimante à puce simulée (lot L3-c) et trois antennes de zone (le lecteur
        // de la sortie, la réserve et le rayon : lot L4-a), qui n'ont encore rien lu ni écrit : cinq lignes de plus, en attente
        $this->assertSame(["box-$simulated", ['box', 'mat', 'printer', 'zone_reader', 'zone_reader', 'zone_reader', 'signal'], ['ok', 'pending', 'pending', 'pending', 'pending', 'pending', 'ok'], false], [($sim = $exercise())['devices'][0]['code'], array_column($sim['devices'], 'kind'), array_column($sim['devices'], 'state'), $sim['summary']['allOk']]);
        $this->assertNotContains("box-$simulated", array_column($board()['devices'], 'code'));
        $this->db->executeStatement("UPDATE loss_box SET last_seen_at = NOW() AT TIME ZONE 'UTC' - INTERVAL '630 seconds' WHERE id = ?", [$simulated]);
        $this->assertSame(['down', 'Silencieux depuis 10 min. Jouez le scénario « Signe de vie » pour le faire revenir.'], [($silent = $exercise()['devices'][0])['state'], $silent['hint']]);
        $this->json($this->admin('POST', self::EX . "/boitiers/$simulated/scenarios", ['scenario' => 'heartbeat']));
        $this->assertSame('ok', $exercise()['devices'][0]['state']);

        // Droits : administrateur ; sans le droit du module, seul le tableau d'exercice s'ouvre
        $this->assertSame(401, $this->call('GET', self::BASE . '/sante', null, null)->getStatusCode());
        $this->loginPos(['ROLE_USER_POS']);
        foreach ([['GET', self::BASE . '/sante'], ['PUT', self::BASE . '/sante/seuil'], ['GET', self::EX . '/sante']] as [$method, $path]) {
            $this->assertSame(403, $this->call($method, $path, $method === 'GET' ? null : ['silentAfterSeconds' => 600], $this->posToken)->getStatusCode(), "$method $path : un caissier");
        }
        static::getContainer()->get(PlatformModeService::class)->setMode('live', 'test');
        $this->flags(true, false);
        $this->assertSame('MODULE_INACTIVE', $this->json($this->admin('GET', self::BASE . '/sante'), 403)['code']);
        $this->assertSame('MODULE_INACTIVE', $this->json($this->admin('PUT', self::BASE . '/sante/seuil', ['silentAfterSeconds' => 600]), 403)['code']);
        $this->assertSame(200, $this->admin('GET', self::EX . '/sante')->getStatusCode());
        $this->assertNull($this->db->fetchOne('SELECT silent_after_seconds FROM loss_setting ORDER BY id LIMIT 1'));
        $this->flags(true, true);
    }

    public function testTheDatabaseGuardsBoxesItselfAndTheMigrationReplays(): void
    {
        $schema = fn (): array => [
            $this->db->fetchFirstColumn("SELECT c.relname || '.' || t.tgname FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid WHERE NOT t.tgisinternal"
                . " AND (c.relname IN ('loss_box', 'loss_device', 'loss_box_event') OR t.tgname = 'loss_piece_event_box_guard') ORDER BY 1"),
            // les objets de CE lot seulement, par leur nom : un lot suivant peut poser ses propres index sur ces tables (L3-b1 l'a fait)
            $this->db->fetchFirstColumn("SELECT indexname FROM pg_indexes WHERE indexname IN ('idx_loss_box_event_box', 'idx_loss_box_event_exercise', 'idx_loss_box_exercise', 'idx_loss_piece_event_box',"
                . " 'loss_box_event_pkey', 'loss_box_pkey', 'loss_device_pkey', 'uniq_loss_box_key', 'uniq_loss_device_box_serial') ORDER BY indexname"),
            $this->db->fetchFirstColumn("SELECT proname FROM pg_proc WHERE proname IN ('loss_box_guard', 'loss_device_guard', 'loss_box_event_guard', 'loss_box_event_frozen', 'loss_box_rows_kept', 'loss_piece_event_box_guard') ORDER BY proname"),
            $this->db->fetchFirstColumn("SELECT table_name || '.' || column_name FROM information_schema.columns WHERE (table_name = 'loss_piece_event' AND column_name IN ('box_id', 'device_id', 'received_at'))"
                . " OR (table_name = 'loss_setting' AND column_name = 'silent_after_seconds') ORDER BY 1"),
        ];
        $before = $schema();
        $this->assertSame([
            ['loss_box.loss_box_guard', 'loss_box.loss_box_no_delete', 'loss_box.loss_box_no_truncate', 'loss_box_event.loss_box_event_frozen', 'loss_box_event.loss_box_event_guard',
                'loss_box_event.loss_box_event_no_delete', 'loss_box_event.loss_box_event_no_truncate', 'loss_device.loss_device_guard', 'loss_device.loss_device_no_delete', 'loss_device.loss_device_no_truncate',
                'loss_piece_event.loss_piece_event_box_guard'],
            ['idx_loss_box_event_box', 'idx_loss_box_event_exercise', 'idx_loss_box_exercise', 'idx_loss_piece_event_box', 'loss_box_event_pkey', 'loss_box_pkey', 'loss_device_pkey', 'uniq_loss_box_key',
                'uniq_loss_device_box_serial'],
            ['loss_box_event_frozen', 'loss_box_event_guard', 'loss_box_guard', 'loss_box_rows_kept', 'loss_device_guard', 'loss_piece_event_box_guard'],
            ['loss_piece_event.box_id', 'loss_piece_event.device_id', 'loss_piece_event.received_at', 'loss_setting.silent_after_seconds'],
        ], $before);
        $outside = fn (): array => array_map('intval', $this->db->fetchNumeric('SELECT (SELECT COUNT(*) FROM loss_finding), (SELECT COUNT(*) FROM loss_evidence), (SELECT COUNT(*) FROM loss_journal), (SELECT COUNT(*) FROM loss_piece),'
            . ' (SELECT COUNT(*) FROM loss_tag), (SELECT COUNT(*) FROM loss_piece_event), (SELECT COUNT(*) FROM product), (SELECT COUNT(*) FROM "order")'));
        $outsideBefore = $outside();
        $rows = fn (): array => array_map('intval', $this->db->fetchNumeric('SELECT (SELECT COUNT(*) FROM loss_box), (SELECT COUNT(*) FROM loss_device), (SELECT COUNT(*) FROM loss_box_event)'));
        $rowsBefore = $rows();

        // ----- Ce que la base refuse d'elle-même, quel que soit le chemin (tout est annulé à la fin)
        $this->db->beginTransaction();
        try {
            $refused = function (string $what, string $sql, array $params = []): void {
                $this->db->executeStatement('SAVEPOINT garde');
                try {
                    $this->db->executeStatement($sql, $params);
                    $this->fail("$what : la base devait refuser");
                } catch (\Doctrine\DBAL\Exception $e) {
                    $this->db->executeStatement('ROLLBACK TO SAVEPOINT garde');
                    $this->assertMatchesRegularExpression('/prévention des pertes|uniq_loss_/u', $e->getMessage(), $what);
                }
            };
            $box = fn (string $name, ?string $key, bool $exercise, string $status = 'pending', ?string $machine = null): int => (int) $this->db->fetchOne(
                "INSERT INTO loss_box (name, key_hash, status, machine_hash, exercise, created_at, updated_at) VALUES (?, ?, ?, ?, CAST(? AS boolean), NOW(), NOW()) RETURNING id", [$name, $key, $status, $machine, $exercise ? 'true' : 'false']);
            $insertBox = "INSERT INTO loss_box (name, key_hash, status, machine_hash, exercise, created_at, updated_at) VALUES ('x', ?, ?, ?, CAST(? AS boolean), NOW(), NOW())";
            $refused('un vrai boîtier sans clé', $insertBox, [null, 'pending', null, 'false']);
            $refused('un boîtier d\'exercice avec une clé', $insertBox, ["cle-$this->s-x", 'active', null, 'true']);
            $refused('un état inconnu', $insertBox, ["cle-$this->s-y", 'perdu', null, 'false']);
            $refused('un boîtier actif sans machine', $insertBox, ["cle-$this->s-z", 'active', null, 'false']);
            $refused('une génération à zéro', "INSERT INTO loss_box (name, key_hash, generation, exercise, created_at, updated_at) VALUES ('x', ?, 0, false, NOW(), NOW())", ["cle-$this->s-g"]);
            $refused('un boîtier révoqué sans sa date', "INSERT INTO loss_box (name, key_hash, status, exercise, created_at, updated_at) VALUES ('x', ?, 'revoked', false, NOW(), NOW())", ["cle-$this->s-r"]);
            $refused('une date de révocation sur un boîtier qui ne l\'est pas', "INSERT INTO loss_box (name, key_hash, revoked_at, exercise, created_at, updated_at) VALUES ('x', ?, NOW(), false, NOW(), NOW())", ["cle-$this->s-d"]);
            $real = $box('Réel', "cle-$this->s-1", false, 'active', 'machine-1');
            $sim = $box('Simulé', null, true, 'active');
            $refused('deux boîtiers, la même clé', $insertBox, ["cle-$this->s-1", 'pending', null, 'false']);
            $refused('changer la marque d\'exercice', 'UPDATE loss_box SET exercise = true, key_hash = NULL WHERE id = ?', [$real]);
            $refused('changer la date de création', "UPDATE loss_box SET created_at = created_at - INTERVAL '1 day' WHERE id = ?", [$real]);
            $refused('reculer la génération', 'UPDATE loss_box SET generation = generation - 1 WHERE id = ?', [$real]);
            $refused('ôter la clé d\'un vrai boîtier', 'UPDATE loss_box SET key_hash = NULL WHERE id = ?', [$real]);
            $refused('ôter la machine d\'un boîtier actif', 'UPDATE loss_box SET machine_hash = NULL WHERE id = ?', [$real]);
            $this->db->executeStatement('UPDATE loss_box SET generation = generation + 1 WHERE id = ?', [$real]); // avancer : permis
            $refused('changer la clé sans avancer la génération', "UPDATE loss_box SET key_hash = 'autre-cle-$this->s' WHERE id = ?", [$real]);
            $refused('changer la machine sans avancer la génération', "UPDATE loss_box SET machine_hash = 'machine-2' WHERE id = ?", [$real]);
            $this->db->executeStatement("UPDATE loss_box SET machine_hash = 'machine-2', generation = generation + 1 WHERE id = ?", [$real]);
            // Appareils
            $insertDevice = "INSERT INTO loss_device (box_id, serial, kind, status, zone, exercise, first_seen_at, last_seen_at) VALUES (?, ?, ?, ?, ?, CAST(? AS boolean), NOW(), NOW())";
            $refused('un genre d\'appareil inconnu', $insertDevice, [$real, 'A1', 'antenne', 'ok', null, 'false']);
            $refused('un état d\'appareil inconnu', $insertDevice, [$real, 'A1', 'reader', 'bof', null, 'false']);
            $refused('une zone inconnue', $insertDevice, [$real, 'A1', 'reader', 'ok', 'toilettes', 'false']);
            $refused('un numéro de série vide', $insertDevice, [$real, '', 'reader', 'ok', null, 'false']);
            $refused('un boîtier qui n\'existe pas', $insertDevice, [999999999, 'A1', 'reader', 'ok', null, 'false']);
            $refused('un appareil d\'exercice sur un vrai boîtier', $insertDevice, [$real, 'A1', 'reader', 'ok', null, 'true']);
            $refused('un vrai appareil sur un boîtier d\'exercice', $insertDevice, [$sim, 'A1', 'reader', 'ok', null, 'false']);
            $this->db->executeStatement($insertDevice, [$real, 'A1', 'reader', 'ok', 'exit', 'false']);
            $this->db->executeStatement($insertDevice, [$sim, 'A1', 'reader', 'ok', null, 'true']);
            $realDevice = (int) $this->db->fetchOne('SELECT id FROM loss_device WHERE box_id = ?', [$real]);
            $simDevice = (int) $this->db->fetchOne('SELECT id FROM loss_device WHERE box_id = ?', [$sim]);
            $refused('deux appareils, le même numéro de série sur un boîtier', $insertDevice, [$real, 'A1', 'reader', 'ok', null, 'false']);
            $refused('changer le genre d\'un appareil', "UPDATE loss_device SET kind = 'camera' WHERE id = ?", [$realDevice]);
            $refused('changer le boîtier d\'un appareil', 'UPDATE loss_device SET box_id = ? WHERE id = ?', [$sim, $realDevice]);
            $refused('changer le numéro de série d\'un appareil', "UPDATE loss_device SET serial = 'B2' WHERE id = ?", [$realDevice]);
            $this->db->executeStatement("UPDATE loss_device SET status = 'down', zone = NULL WHERE id = ?", [$realDevice]); // état et zone : modifiables
            // Faits de boîtier
            $insertEvent = "INSERT INTO loss_box_event (box_id, device_id, type, occurred_at, exercise, data) VALUES (?, ?, 'SOURCE_DOWN', NOW(), CAST(? AS boolean), '{}')";
            $refused('un fait pour un boîtier inconnu', $insertEvent, [999999999, null, 'false']);
            $refused('un fait d\'exercice sur un vrai boîtier', $insertEvent, [$real, null, 'true']);
            $refused('un vrai fait sur un boîtier d\'exercice', $insertEvent, [$sim, null, 'false']);
            $refused('un fait qui désigne l\'appareil d\'un autre boîtier', $insertEvent, [$real, $simDevice, 'false']);
            $refused('un genre de fait de boîtier inconnu', "INSERT INTO loss_box_event (box_id, type, occurred_at, exercise, data) VALUES (?, 'BOX_VENDU', NOW(), false, '{}')", [$real]);
            $this->db->executeStatement($insertEvent, [$real, $realDevice, 'false']);
            $this->db->executeStatement($insertEvent, [$sim, $simDevice, 'true']);
            $refused('modifier un fait de boîtier', "UPDATE loss_box_event SET type = 'SOURCE_UP' WHERE box_id = ?", [$real]);
            // Faits de pièces venus d'un boîtier : source, heure de réception, clé d'idempotence au nom de CE boîtier, jamais un utilisateur
            $insertFact = "INSERT INTO loss_piece_event (type, occurred_at, source, exercise, data, box_id, device_id, received_at, event_key, user_id) VALUES ('SPOT_CHECK', NOW(), ?, CAST(? AS boolean), '{}', ?, ?, CAST(? AS timestamp), ?, ?)";
            $at = gmdate('Y-m-d H:i:s');
            $refused('un appareil sans son boîtier', $insertFact, ['app', 'false', null, $realDevice, null, null, null]);
            $refused('un fait de boîtier sans son boîtier', $insertFact, ['box', 'false', null, null, $at, "cle-$this->s", null]);
            $refused('un vrai fait de pièce venu d\'un boîtier d\'exercice', $insertFact, ['box', 'false', $sim, null, $at, "b$sim:k1", null]);
            $refused('un fait de pièce d\'exercice venu d\'un vrai boîtier', $insertFact, ['exercise', 'true', $real, null, $at, null, null]);
            $refused('un fait de pièce par l\'appareil d\'un autre boîtier', $insertFact, ['box', 'false', $real, $simDevice, $at, "b$real:k2", null]);
            $refused('un fait de pièce pour un boîtier inconnu', $insertFact, ['box', 'false', 999999999, null, $at, 'b999999999:k3', null]);
            $refused('un fait de boîtier qui se dit venu de l\'application', $insertFact, ['app', 'false', $real, null, $at, "b$real:k4", null]);
            $refused('un fait de boîtier au nom d\'un utilisateur', $insertFact, ['box', 'false', $real, null, $at, "b$real:k5", $this->adminId]);
            $refused('un fait de boîtier sans heure de réception', $insertFact, ['box', 'false', $real, null, null, "b$real:k6", null]);
            $refused('un fait d\'un vrai boîtier sans clé d\'idempotence', $insertFact, ['box', 'false', $real, null, $at, null, null]);
            $refused('la clé d\'un autre boîtier', $insertFact, ['box', 'false', $real, null, $at, "b$sim:k7", null]);
            $refused('la clé d\'un boîtier prise d\'avance par un fait sans boîtier', $insertFact, ['app', 'false', null, null, null, "b$real:k8", null]);
            $this->db->executeStatement($insertFact, ['box', 'false', $real, $realDevice, $at, "b$real:k9", null]);
            $this->db->executeStatement($insertFact, ['exercise', 'true', $sim, $simDevice, $at, null, null]);
            $this->db->executeStatement($insertFact, ['app', 'false', null, null, null, "une-cle-de-l-application-$this->s", $this->adminId]); // un fait de l'application : comme avant
            // Un boîtier révoqué ne revient pas
            $this->db->executeStatement("UPDATE loss_box SET status = 'revoked', revoked_at = NOW() WHERE id = ?", [$real]);
            $refused('faire revenir un boîtier révoqué', "UPDATE loss_box SET status = 'active' WHERE id = ?", [$real]);
            $refused('changer la clé d\'un boîtier révoqué', "UPDATE loss_box SET key_hash = 'autre-$this->s' WHERE id = ?", [$real]);
            $refused('changer la date de révocation', "UPDATE loss_box SET revoked_at = revoked_at - INTERVAL '1 day' WHERE id = ?", [$real]);
            $refused('avancer la génération d\'un boîtier révoqué', 'UPDATE loss_box SET generation = generation + 1 WHERE id = ?', [$real]);
            $this->db->executeStatement("UPDATE loss_box SET name = 'Révoqué, renommé' WHERE id = ?", [$real]); // le nom reste modifiable
            // Rien ne se supprime ni ne se vide ; l'exercice, par sa porte, dans l'ordre : faits, appareils, boîtiers
            foreach (['loss_box_event', 'loss_device', 'loss_box'] as $table) {
                $refused("vider $table", "TRUNCATE $table");
                $refused("supprimer dans $table sans la porte", "DELETE FROM $table WHERE exercise");
            }
            $this->db->executeStatement("SET LOCAL loss.purge = 'exercise'");
            $refused('supprimer un vrai boîtier par la porte d\'exercice', 'DELETE FROM loss_box WHERE id = ?', [$real]);
            $refused('supprimer un vrai appareil par la porte d\'exercice', 'DELETE FROM loss_device WHERE id = ?', [$realDevice]);
            $refused('supprimer un vrai fait par la porte d\'exercice', 'DELETE FROM loss_box_event WHERE box_id = ?', [$real]);
            $refused('le boîtier avant ses appareils', 'DELETE FROM loss_box WHERE id = ?', [$sim]);
            $refused('l\'appareil avant ses faits', 'DELETE FROM loss_device WHERE id = ?', [$simDevice]);
            $this->db->executeStatement('DELETE FROM loss_box_event WHERE box_id = ?', [$sim]);
            $refused('l\'appareil avant les faits de pièces qui le désignent', 'DELETE FROM loss_device WHERE id = ?', [$simDevice]);
            $this->db->executeStatement('DELETE FROM loss_piece_event WHERE box_id = ?', [$sim]);
            $this->db->executeStatement('DELETE FROM loss_device WHERE id = ?', [$simDevice]);
            $this->assertSame(1, $this->db->executeStatement('DELETE FROM loss_box WHERE id = ?', [$sim]));
        } finally {
            $this->db->rollBack();
        }
        $this->assertSame([$rowsBefore, $outsideBefore], [$rows(), $outside()]);

        require_once dirname(__DIR__, 3) . '/migrations/Version20270113100000.php';
        // La migration se rejoue sur une base qui la porte déjà : rien ne change, ni le schéma, ni les lignes. Dans une transaction
        // ANNULÉE : rejouée pour de bon, elle remettrait ses gardes à la place de celles qu'un lot suivant a élargies (L3-b1), et
        // les tests qui suivent tourneraient sur les anciennes
        $this->db->beginTransaction();
        try {
            foreach (\DoctrineMigrations\Version20270113100000::STATEMENTS as $sql) {
                $this->db->executeStatement($sql);
            }
            $this->assertSame([$before, $rowsBefore, $outsideBefore], [$schema(), $rows(), $outside()]);
        } finally {
            $this->db->rollBack();
        }

        // Le retour arrière retire ses tables, ses colonnes, ses fonctions, et rien d'autre ; puis la migration repose le même schéma.
        // Le tout dans une transaction annulée à la fin : la base de test garde ses boîtiers
        $this->db->beginTransaction();
        try {
            $this->db->executeStatement("SET LOCAL lock_timeout = '5s'"); // si une autre connexion tenait ces tables, le test échoue au lieu de se bloquer
            foreach (\DoctrineMigrations\Version20270113100000::DOWN_STATEMENTS as $sql) {
                $this->db->executeStatement($sql);
            }
            $this->assertSame([[], [], [], []], $schema());
            $this->assertSame(0, (int) $this->db->fetchOne("SELECT COUNT(*) FROM information_schema.tables WHERE table_name IN ('loss_box', 'loss_device', 'loss_box_event')"));
            $this->assertSame($outsideBefore, $outside(), 'le retour arrière ne touche à rien d\'autre : les faits de pièces gardent leurs lignes');
            foreach (\DoctrineMigrations\Version20270113100000::STATEMENTS as $sql) {
                $this->db->executeStatement($sql);
            }
            $this->assertSame($before, $schema());
        } finally {
            $this->db->rollBack();
        }
        $this->assertSame([$before, $rowsBefore, $outsideBefore], [$schema(), $rows(), $outside()]);
    }

    private static function randomIp(): string
    {
        return '10.' . random_int(1, 250) . '.' . random_int(1, 250) . '.' . random_int(1, 250);
    }

    /** Un appel du boîtier : son jeton dans l'en-tête X-Loss-Box, jamais de jeton d'utilisateur */
    private function box(string $method, string $path, ?array $body, ?string $token, array $server = []): Response
    {
        $this->client->getCookieJar()->clear();
        $this->client->request($method, 'https://' . MV_TEST_TENANT_HOST . self::CHANNEL . $path, [], [], array_filter([
            'HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_LOSS_BOX' => $token,
            'HTTP_X_LOSS_BOX_MACHINE' => $token !== null ? ($this->machines[$token] ?? self::MACHINE) : null, // lot L5-e : la preuve de la machine
        ]) + $server + ['REMOTE_ADDR' => $this->ip], $body !== null ? json_encode($body) : null);

        return $this->client->getResponse();
    }

    /**
     * @param list<?string> $variantBarcodes
     *
     * @return array{int, list<int>}
     */
    private function article(string $name, array $variantBarcodes): array
    {
        $slug = 'l3a-' . bin2hex(random_bytes(4));
        $this->db->executeStatement("INSERT INTO product (id, name, price, isbestseller, quantity, created_at, slug, product_type, is_web, is_pos) VALUES (nextval('product_id_seq'), :n, 3000, false, 0, NOW(), :s, 'standard', true, true)",
            ['n' => "$name L3A-$this->s", 's' => $slug]);
        $product = (int) $this->db->fetchOne('SELECT id FROM product WHERE slug = ?', [$slug]);
        $ids = [];
        foreach ($variantBarcodes as $barcode) {
            $ids[] = (int) $this->db->fetchOne("INSERT INTO product_variant (id, product_id, stock_quantity, barcode) VALUES (nextval('product_variant_id_seq'), ?, 5, ?) RETURNING id", [$product, $barcode]);
        }

        return [$product, $ids];
    }
}
