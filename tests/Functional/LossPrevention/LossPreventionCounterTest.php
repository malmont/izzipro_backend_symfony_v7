<?php

namespace App\Tests\Functional\LossPrevention;

use App\LossPrevention\Entity\LossBox;
use App\LossPrevention\Entity\LossPiece;
use App\Services\Platform\PlatformModeService;
use Doctrine\DBAL\ArrayParameterType;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lot L3-b1 (16/11/2026, `docs/prevention-pertes.md` § 16.15) : le tapis de comptoir. Ce que le boîtier en dit (une puce arrive,
 * une puce part), la présence qui en découle, le lien d'un tapis avec son poste de caisse, sa pastille et son étape, l'appareil
 * retiré ; et la route que la CAISSE appelle avant un paiement : une lecture, sur le tapis du poste du caissier et lui seul.
 */
class LossPreventionCounterTest extends LossPreventionTestCase
{
    private const BOXES = self::BASE . '/boitiers';
    private const EX = self::BASE . '/exercices';
    private const UNREAD = '/api/pos/pertes/articles-non-lus';
    private const MACHINE = 'machine-de-test-0123456789abcdef';

    private string $s;
    private string $ip;
    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->s = strtoupper(bin2hex(random_bytes(3)));
        $this->ip = '10.' . random_int(1, 250) . '.' . random_int(1, 250) . '.' . random_int(1, 250);
        $this->flags(true, true);
    }

    protected function tearDown(): void
    {
        try {
            while ($this->db->isTransactionActive()) {
                $this->db->rollBack();
            }
            $this->db->executeStatement("UPDATE loss_box SET status = 'revoked', revoked_at = NOW() WHERE NOT exercise AND status <> 'revoked'");
            $this->db->executeStatement("UPDATE loss_device SET status = 'retired', cash_register_id = NULL WHERE NOT exercise AND status <> 'retired'"); // un tapis retiré rend son poste aux tests suivants
            $this->db->beginTransaction();
            $this->provider->getEntityManager()->getRepository(LossPiece::class)->deleteExercise();
            $this->provider->getEntityManager()->getRepository(LossBox::class)->deleteExercise();
            $this->db->commit();
            $ids = $this->db->fetchFirstColumn('SELECT id FROM product WHERE name LIKE ?', ['% L3B-' . $this->s]);
            if ($ids !== []) {
                $this->db->executeStatement('DELETE FROM product_variant WHERE product_id IN (?)', [$ids], [ArrayParameterType::INTEGER]);
                $this->db->executeStatement('DELETE FROM product WHERE id IN (?)', [$ids], [ArrayParameterType::INTEGER]);
            }
        } finally {
            parent::tearDown();
        }
    }

    public function testACounterMatIsReadLinkedToItsRegisterShownInTheHealthBoardAndRetired(): void
    {
        [$variant] = $this->article('Chemise');
        $nfc = $this->tagged($variant, '01');
        [$id, $token, $devices] = $this->enrolled('Comptoir', [['serial' => "TAPIS-$this->s", 'kind' => 'counter_mat', 'status' => 'ok'], ['serial' => "LECTEUR-$this->s", 'kind' => 'reader', 'status' => 'ok'],
            ['serial' => "IMPR-$this->s", 'kind' => 'printer', 'status' => 'ok']]);
        [$mat, $reader, $printer] = [$devices["TAPIS-$this->s"], $devices["LECTEUR-$this->s"], $devices["IMPR-$this->s"]];
        $health = fn (string $base = self::BASE): array => array_column($this->json($this->admin('GET', $base . '/sante'))['devices'], null, 'code');
        $step = fn (string $base = self::BASE): array => array_column($this->json($this->admin('GET', $base . '/installation'))['optionalSteps'], null, 'code')['counter_mat'];
        $sheet = fn (): array => array_column($this->json($this->admin('GET', self::BOXES . "/$id"))['devices'], null, 'id');
        $events = fn (): array => array_map(fn (array $r): array => [$r['type'], $r['device_id'] !== null ? (int) $r['device_id'] : null, json_decode((string) $r['data'], true)],
            $this->db->fetchAllAssociative('SELECT type, device_id, data FROM loss_box_event WHERE box_id = ? ORDER BY id', [$id]));
        $presence = fn (): array => array_map(fn (array $r): array => [(int) $r['device_id'], $r['until'] === null], $this->db->fetchAllAssociative('SELECT device_id, until FROM loss_counter_presence WHERE device_id = ? ORDER BY id', [$mat]));

        // Un tapis branché, qui n'a encore rien lu : en attente, et l'étape dit quoi faire
        $line = $health()["mat-$mat"];
        $this->assertSame(['mat', 'Tapis de comptoir', 'counter', null, 'pending', 'En attente', null, null, 'Le tapis n\'a encore lu aucune puce. Posez dessus une pièce qui porte une puce.'],
            [$line['kind'], $line['label'], $line['zone'], $line['cashRegisterId'], $line['state'], $line['stateLabel'], $line['silentSince'], $line['silentSeconds'], $line['hint']]);
        $this->assertSame(['counter_mat', 'Brancher le tapis de comptoir', 'todo', 'pending', $line['hint']], [($s = $step())['code'], $s['title'], $s['status'], $s['state'], $s['hint']]);
        $this->assertSame([null, 0, null], [$sheet()[$mat]['cashRegister'], $sheet()[$mat]['onMat'], $sheet()[$reader]['onMat']]);

        // Le tapis dit ce qui arrive sur lui et ce qui en part ; un lecteur dit ce qu'il lit : chacun ses faits
        $this->assertSame(['refused', 'DEVICE_KIND_INVALID'], $this->fact($token, 'COUNTER_SEEN', "LECTEUR-$this->s", $nfc));
        $this->assertSame(['refused', 'DEVICE_KIND_INVALID'], $this->fact($token, 'SPOT_CHECK', "TAPIS-$this->s", $nfc));
        $this->assertSame(['refused', 'DEVICE_KIND_INVALID'], $this->fact($token, 'COUNTER_LEFT', "IMPR-$this->s", $nfc));
        $this->assertSame(['refused', 'ZONE_INVALID'], $this->fact($token, 'COUNTER_SEEN', "TAPIS-$this->s", $nfc, ['zone' => 'exit']));
        $this->assertSame([], $presence(), 'un fait refusé ne pose rien sur le tapis');
        $this->assertSame(['accepted', null], $this->fact($token, 'COUNTER_SEEN', "TAPIS-$this->s", $nfc, ['key' => "pose-$this->s-0001"]));
        $this->assertSame(['duplicate', null], $this->fact($token, 'COUNTER_SEEN', "TAPIS-$this->s", $nfc, ['key' => "pose-$this->s-0001"]), 'rejoué : déjà connu');
        $this->assertSame(['accepted', null], $this->fact($token, 'COUNTER_SEEN', "TAPIS-$this->s", $nfc, ['zone' => 'counter']), 'vue une seconde fois sans être partie : une seule présence');
        $this->assertSame([[[$mat, true]], 1], [$presence(), $sheet()[$mat]['onMat']]);
        $stored = $this->db->fetchAssociative("SELECT type, source, data, device_id FROM loss_piece_event WHERE event_key = ?", ["b$id:pose-$this->s-0001"]);
        $this->assertSame(['COUNTER_SEEN', 'box', ['zone' => 'counter'], $mat], [$stored['type'], $stored['source'], json_decode($stored['data'], true), (int) $stored['device_id']]);
        // lu, mais pas encore relié à un poste : toujours en attente, avec un autre conseil
        $this->assertSame(['pending', 'Ce tapis n\'est relié à aucun poste de caisse. Choisissez son poste dans la fiche du boîtier.'], [($line = $health()["mat-$mat"])['state'], $line['hint']]);

        // Relier : un poste pour un tapis, une zone pour un lecteur ; rien d'autre
        $assign = fn (int $device, array $body, int $status = 200, ?int $box = null): array => $this->json($this->admin('PUT', self::BOXES . '/' . ($box ?? $id) . "/appareils/$device", $body), $status);
        $before = count($events());
        $this->assertSame(['cashRegisterId' => 'REQUIRED'], $assign($mat, [], 422)['fields']);
        $this->assertSame(['zone' => 'NOT_FOR_THIS_DEVICE', 'cashRegisterId' => 'REQUIRED'], $assign($mat, ['zone' => 'counter'], 422)['fields']);
        $this->assertSame(['cashRegisterId' => 'MUST_BE_POSITIVE_INTEGER'], $assign($mat, ['cashRegisterId' => (string) $this->register], 422)['fields']);
        $this->assertSame(['cashRegisterId' => 'MUST_BE_POSITIVE_INTEGER'], $assign($mat, ['cashRegisterId' => 0], 422)['fields']);
        $this->assertSame(['cashRegisterId' => 'NOT_FOR_THIS_DEVICE', 'zone' => 'REQUIRED'], $assign($reader, ['cashRegisterId' => $this->register], 422)['fields']);
        $this->assertSame(['zone' => 'INVALID_VALUE'], $assign($reader, ['zone' => 'toilettes'], 422)['fields']);
        $this->assertSame(['cashRegisterId' => 'NOT_FOR_THIS_DEVICE', 'device' => 'NOT_ASSIGNABLE'], $assign($printer, ['cashRegisterId' => $this->register], 422)['fields']);
        $this->assertSame('CASH_REGISTER_NOT_FOUND', $assign($mat, ['cashRegisterId' => 999999999], 404)['error']);
        $this->assertSame('DEVICE_NOT_FOUND', $assign(999999999, ['cashRegisterId' => $this->register], 404)['error']);
        $this->assertSame('BOX_NOT_FOUND', $assign($mat, ['cashRegisterId' => $this->register], 404, 999999999)['error']);
        [$otherBox, , $otherDevices] = $this->enrolled('Autre', [['serial' => "TAPIS-B-$this->s", 'kind' => 'counter_mat', 'status' => 'ok']], 'autre-machine-0123456789abcdef');
        $this->assertSame('DEVICE_NOT_FOUND', $assign($mat, ['cashRegisterId' => $this->register], 404, $otherBox)['error'], 'l\'appareil d\'un autre boîtier');
        $this->assertSame($before, count($events()));
        $linked = $assign($mat, ['cashRegisterId' => $this->register]);
        $registerName = (string) $this->db->fetchOne('SELECT name FROM cash_register WHERE id = ?', [$this->register]);
        $this->assertSame([['id' => $this->register, 'name' => $registerName], 1], [($d = array_column($linked['devices'], null, 'id')[$mat])['cashRegister'], $d['onMat']]);
        $this->assertSame([['DEVICE_ASSIGNED', $mat, ['cashRegisterId' => $this->register]], $this->adminId],
            [$events()[$before], (int) $this->db->fetchOne("SELECT user_id FROM loss_box_event WHERE box_id = ? AND type = 'DEVICE_ASSIGNED'", [$id])]);
        $assign($mat, ['cashRegisterId' => $this->register]);
        $this->assertSame($before + 1, count($events()), 'le même poste : rien à écrire');
        $taken = $assign($otherDevices["TAPIS-B-$this->s"], ['cashRegisterId' => $this->register], 409, $otherBox);
        $this->assertSame(['REGISTER_ALREADY_HAS_MAT', $mat], [$taken['error'], $taken['deviceId']], 'un poste a au plus un tapis');
        $this->assertSame('exit', array_column($assign($reader, ['zone' => 'exit'])['devices'], null, 'id')[$reader]['zone']);
        $this->assertSame(['DEVICE_ASSIGNED', $reader, ['zone' => 'exit']], $events()[$before + 1]);
        $this->assertNull(array_column($assign($reader, ['zone' => null])['devices'], null, 'id')[$reader]['zone']);
        // lu et relié : en ordre ; l'étape est faite ; le boîtier lit le poste dans ses réglages
        $line = $health()["mat-$mat"];
        $this->assertSame(['ok', "Tapis de comptoir · $registerName", $this->register, null], [$line['state'], $line['label'], $line['cashRegisterId'], $line['hint']]);
        $this->assertSame(['done', 'ok', null], [($s = $step())['status'], $s['state'], $s['hint']]);
        $this->assertSame(['code' => 'counter_mat', 'verified' => true, 'hint' => null], $this->json($this->admin('POST', self::BASE . '/installation/etapes/counter_mat/verifier', [])));
        $this->assertSame($this->register, array_column($this->json($this->box('GET', '/reglages', null, $token))['devices'], null, 'id')[$mat]['cashRegisterId']);

        // La puce quitte le tapis : la présence se ferme ; partir deux fois ne casse rien
        // (le même départ redit, à la même heure : un second départ daté plus tard serait un autre départ, retenu à part)
        $leftAt = gmdate('Y-m-d\TH:i:s\Z');
        $this->assertSame(['accepted', null], $this->fact($token, 'COUNTER_LEFT', "TAPIS-$this->s", $nfc, ['at' => $leftAt]));
        $this->assertSame(['accepted', null], $this->fact($token, 'COUNTER_LEFT', "TAPIS-$this->s", $nfc, ['at' => $leftAt]));
        $this->assertSame([[[$mat, false]], 0], [$presence(), $sheet()[$mat]['onMat']]);
        // Un fait à l'heure douteuse est gardé, mais ne pose rien sur le tapis
        $this->assertSame(['accepted', null], $this->fact($token, 'COUNTER_SEEN', "TAPIS-$this->s", $nfc, ['at' => gmdate('Y-m-d\TH:i:s\Z', time() + 600), 'key' => "futur-$this->s-0001"]));
        $this->assertSame([[[$mat, false]], true], [$presence(), json_decode((string) $this->db->fetchOne('SELECT data FROM loss_piece_event WHERE event_key = ?', ["b$id:futur-$this->s-0001"]), true)['clockDoubt']]);

        // Un départ à l'heure douteuse vaut quand même : on ne garde pas « posée » une pièce qu'on nous dit partie
        $this->fact($token, 'COUNTER_SEEN', "TAPIS-$this->s", $nfc);
        $this->assertSame(['accepted', null], $this->fact($token, 'COUNTER_LEFT', "TAPIS-$this->s", $nfc, ['at' => gmdate('Y-m-d\TH:i:s\Z', time() + 600)]));
        $this->assertSame(0, $sheet()[$mat]['onMat']);
        // Faits arrivés dans le désordre (un lot renvoyé après un autre) : un départ plus ancien que l'arrivée connue ne ferme rien ;
        // une arrivée plus ancienne qu'un départ déjà connu ne pose pas une pièce fantôme
        $other = $this->tagged($variant, '02');
        $this->fact($token, 'COUNTER_SEEN', "TAPIS-$this->s", $other, ['at' => gmdate('Y-m-d\TH:i:s\Z', time() - 30)]);
        $this->fact($token, 'COUNTER_LEFT', "TAPIS-$this->s", $other, ['at' => gmdate('Y-m-d\TH:i:s\Z', time() - 300)]);
        $this->assertSame(1, $sheet()[$mat]['onMat'], 'le départ d\'il y a cinq minutes ne ferme pas l\'arrivée d\'il y a trente secondes');
        $this->fact($token, 'COUNTER_LEFT', "TAPIS-$this->s", $other, ['at' => gmdate('Y-m-d\TH:i:s\Z', time() - 10)]);
        $this->assertSame(0, $sheet()[$mat]['onMat']);
        $this->fact($token, 'COUNTER_SEEN', "TAPIS-$this->s", $other, ['at' => gmdate('Y-m-d\TH:i:s\Z', time() - 120)]);
        $this->assertSame(0, $sheet()[$mat]['onMat'], 'l\'arrivée d\'il y a deux minutes, reçue après le départ d\'il y a dix secondes, ne pose rien');
        $third = $this->tagged($variant, '03');
        $this->fact($token, 'COUNTER_LEFT', "TAPIS-$this->s", $third, ['at' => gmdate('Y-m-d\TH:i:s\Z', time() - 10)]);
        $this->fact($token, 'COUNTER_SEEN', "TAPIS-$this->s", $third, ['at' => gmdate('Y-m-d\TH:i:s\Z', time() - 40)]);
        $this->assertSame(0, $sheet()[$mat]['onMat'], 'même quand le départ est arrivé seul d\'abord (son arrivée perdue puis renvoyée)');
        $this->fact($token, 'COUNTER_SEEN', "TAPIS-$this->s", $third);
        $this->assertSame(1, $sheet()[$mat]['onMat'], 'reposée maintenant : elle est bien là');
        $this->assertSame(['accepted', null], $this->fact($token, 'COUNTER_LEFT', "TAPIS-$this->s", $third, ['key' => "depart-$this->s-0001"]));
        $this->fact($token, 'COUNTER_SEEN', "TAPIS-$this->s", $third, ['at' => gmdate('Y-m-d\TH:i:s\Z', time() + 2)]);
        $this->assertSame(['duplicate', null], $this->fact($token, 'COUNTER_LEFT', "TAPIS-$this->s", $third, ['key' => "depart-$this->s-0001", 'at' => gmdate('Y-m-d\TH:i:s\Z', time() + 3)]));
        $this->assertSame(1, $sheet()[$mat]['onMat'], 'un départ rejoué (même clé, quoi qu\'il porte) ne ferme pas la présence rouverte depuis');
        $this->fact($token, 'COUNTER_LEFT', "TAPIS-$this->s", $third, ['at' => gmdate('Y-m-d\TH:i:s\Z', time() + 4)]);

        // Tapis débranché : ce qu'on croyait posé ne l'est plus pour nous ; la pastille dit le câble ; revenu, le boîtier doit redire
        $this->fact($token, 'COUNTER_SEEN', "TAPIS-$this->s", $nfc);
        $this->assertSame(1, $sheet()[$mat]['onMat']);
        $all = [['serial' => "TAPIS-$this->s", 'kind' => 'counter_mat', 'status' => 'ok'], ['serial' => "LECTEUR-$this->s", 'kind' => 'reader', 'status' => 'ok']];
        $this->assertSame([], $this->beat($token, $all)['resendMats'], 'rien n\'a été fermé : rien à redire');
        // un tapis que le boîtier n'annonce plus : débranché, ses présences se ferment ; idem si son genre change
        $this->beat($token, [$all[1]]);
        $this->assertSame([0, 'down'], [$sheet()[$mat]['onMat'], $health()["mat-$mat"]['state']]);
        $this->assertSame(["TAPIS-$this->s"], $this->beat($token, $all)['resendMats'], 'revenu : le boîtier redit ce qui est posé sur ce tapis');
        $this->fact($token, 'COUNTER_SEEN', "TAPIS-$this->s", $nfc);
        $this->beat($token, [['serial' => "TAPIS-$this->s", 'kind' => 'reader', 'status' => 'ok'], $all[1]]);
        $this->assertSame([0, 'down'], [$sheet()[$mat]['onMat'], $health()["mat-$mat"]['state']], 'le numéro du tapis annoncé comme un lecteur : le tapis n\'est plus là');
        $this->beat($token, $all);
        $this->fact($token, 'COUNTER_SEEN', "TAPIS-$this->s", $nfc);
        $this->beat($token, [['serial' => "TAPIS-$this->s", 'kind' => 'counter_mat', 'status' => 'down'], ['serial' => "LECTEUR-$this->s", 'kind' => 'reader', 'status' => 'ok']]);
        $line = $health()["mat-$mat"];
        $this->assertSame([0, 'down', 'Le tapis ne répond plus. Vérifiez son câble sur le boîtier, puis son alimentation.'], [$sheet()[$mat]['onMat'], $line['state'], $line['hint']]);
        $this->assertNotNull($line['silentSince']);
        $this->assertSame(['todo', 'down'], [($s = $step())['status'], $s['state']]);
        // Il revient, une puce est posée, puis son BOÎTIER se tait : le tapis est dit silencieux avec lui, et au retour du boîtier les
        // présences d'avant sont fermées (le boîtier redit ce qui est posé)
        $this->beat($token, [['serial' => "TAPIS-$this->s", 'kind' => 'counter_mat', 'status' => 'ok']]);
        $fourth = $this->tagged($variant, '04');
        $this->fact($token, 'COUNTER_SEEN', "TAPIS-$this->s", $fourth, ['at' => gmdate('Y-m-d\TH:i:s\Z', time() - 900)]); // posée il y a un quart d'heure
        $this->db->executeStatement("UPDATE loss_box SET last_seen_at = NOW() AT TIME ZONE 'UTC' - INTERVAL '10 minutes' WHERE id = ?", [$id]);
        $line = $health()["mat-$mat"];
        $this->assertSame(['down', 'Son boîtier ne donne plus signe de vie : on ne sait plus ce qui est posé sur le tapis. Vérifiez le boîtier.'], [$line['state'], $line['hint']]);
        $lastSeen = (string) $this->db->fetchOne('SELECT last_seen_at FROM loss_box WHERE id = ?', [$id]);
        $this->answered[$token] = gmdate('Y-m-d\TH:i:s\Z', strtotime($lastSeen . ' UTC')); // le boîtier avait bien lu la réponse de ce signe de vie-là : seul le silence joue
        $this->assertSame(["TAPIS-$this->s"], $this->beat($token, [['serial' => "TAPIS-$this->s", 'kind' => 'counter_mat', 'status' => 'ok']])['resendMats']);
        $this->assertSame([0, 'ok'], [$sheet()[$mat]['onMat'], $health()["mat-$mat"]['state']]);
        $this->assertEqualsWithDelta(strtotime($lastSeen . ' UTC'), strtotime($this->db->fetchOne('SELECT until FROM loss_counter_presence WHERE device_id = ? ORDER BY id DESC LIMIT 1', [$mat]) . ' UTC'), 2,
            'fermée au dernier signe de vie d\'avant le silence, pas à l\'heure du retour');
        // La réponse d'un signe de vie se perd en route : le boîtier n'a pas lu `resendMats`. Au suivant, il renvoie l'heure de la
        // dernière réponse qu'il a lue : ce n'est pas la nôtre, donc tout se ferme et tout est redemandé, jusqu'à ce qu'il l'ait lu
        $mats = [['serial' => "TAPIS-$this->s", 'kind' => 'counter_mat', 'status' => 'ok']];
        $this->fact($token, 'COUNTER_SEEN', "TAPIS-$this->s", $nfc);
        $this->assertSame([[], 1], [$this->beat($token, $mats, 0, true)['resendMats'], $sheet()[$mat]['onMat']], 'cette réponse-là ne demandait rien, mais elle se perd');
        $this->assertSame([["TAPIS-$this->s"], 0], [$this->beat($token, $mats, 0, true)['resendMats'], $sheet()[$mat]['onMat']], 'réponse perdue : on ne sait plus ce que le boîtier sait');
        $this->assertSame(["TAPIS-$this->s"], $this->beat($token, $mats)['resendMats'], 'encore perdue : redemandé, tant qu\'il ne l\'a pas lu');
        $this->fact($token, 'COUNTER_SEEN', "TAPIS-$this->s", $nfc);
        $this->assertSame([[], 1], [$this->beat($token, $mats)['resendMats'], $sheet()[$mat]['onMat']], 'lue : plus rien à redire, ce qui a été redit reste posé');
        // Le boîtier redémarre : il ne sait plus quelle réponse il a lue, et ne renvoie rien. Même traitement
        $boot = $this->json($this->box('POST', '/signe-de-vie', ['now' => gmdate('Y-m-d\TH:i:s\Z'), 'machine' => self::MACHINE, 'devices' => $mats], $token));
        $this->answered[$token] = $boot['serverTime'];
        $this->assertSame([["TAPIS-$this->s"], 0], [$boot['resendMats'], $sheet()[$mat]['onMat']]);
        $this->assertSame(['lastServerTime' => 'INVALID_VALUE'], $this->json($this->box('POST', '/signe-de-vie', ['now' => gmdate('Y-m-d\TH:i:s\Z'), 'machine' => self::MACHINE, 'devices' => $mats, 'lastServerTime' => 'hier'], $token), 422)['fields']);
        // Horloge du boîtier déréglée : ce qu'on croyait posé ne l'est plus, ses arrivées ne posent rien, la pastille le dit ; remise
        // à l'heure, le boîtier redit
        $this->fact($token, 'COUNTER_SEEN', "TAPIS-$this->s", $nfc);
        $drift = fn (int $seconds): array => $this->beat($token, $mats, -$seconds);
        $this->assertSame([true, []], [($d = $drift(-300))['clockDoubt'], $d['resendMats']]);
        $line = $health()["mat-$mat"];
        $this->assertSame([0, 'down'], [$sheet()[$mat]['onMat'], $line['state']]);
        $this->assertStringContainsString('L\'horloge de son boîtier est déréglée', $line['hint']);
        $this->assertSame([null, null], [$line['silentSince'], $line['silentSeconds']], 'une horloge déréglée n\'est pas un silence : le tapis parle');
        $this->fact($token, 'COUNTER_SEEN', "TAPIS-$this->s", $nfc);
        $this->assertSame(0, $sheet()[$mat]['onMat']);
        $this->assertSame([false, ["TAPIS-$this->s"]], [($d = $drift(0))['clockDoubt'], $d['resendMats']]);
        $this->assertSame('ok', $health()["mat-$mat"]['state']);
        // Machine réinscrite, clé remise à zéro : ce que la plateforme croyait posé se ferme
        $this->fact($token, 'COUNTER_SEEN', "TAPIS-$this->s", $nfc);
        $token = $this->reenrol($id, $token);
        $this->assertSame(0, $sheet()[$mat]['onMat'], 'réinscrite : rien n\'est plus dit posé');
        $this->assertSame(['pending', 'Son boîtier vient d\'être inscrit : le tapis sera prêt à son premier signe de vie, dans la minute.', null], [($line = $health()["mat-$mat"])['state'], $line['hint'], $line['silentSince']],
            'horloge pas encore mesurée : rien n\'est en panne, on attend');
        $this->assertSame(["TAPIS-$this->s"], $this->beat($token, $mats)['resendMats'], 'premier signe de vie après la réinscription : le boîtier redit');
        $this->fact($token, 'COUNTER_SEEN', "TAPIS-$this->s", $nfc);
        $this->assertSame(1, $sheet()[$mat]['onMat']);
        // Un boîtier dont l'horloge RETARDE de 30 secondes (dans la tolérance). Ce que le serveur ferme de lui-même est daté à l'horloge
        // du boîtier : ce que le boîtier redit juste après n'est pas jugé « plus ancien qu'un départ connu »
        $this->fact($token, 'COUNTER_LEFT', "TAPIS-$this->s", $nfc);
        [$fifth, $sixth] = [$this->tagged($variant, '05'), $this->tagged($variant, '06')];
        $late = fn (string $identifier): array => $this->fact($this->current, 'COUNTER_SEEN', "TAPIS-$this->s", $identifier, ['at' => gmdate('Y-m-d\TH:i:s\Z', time() - 30)]);
        $this->current = $token;
        $this->assertEqualsWithDelta(-30, $this->beat($token, $mats, 30)['clockOffsetSeconds'], 2);
        $late($fifth);
        $late($sixth);
        $this->assertSame(2, $sheet()[$mat]['onMat']);
        $this->beat($token, [['serial' => "TAPIS-$this->s", 'kind' => 'counter_mat', 'status' => 'down']], 30); // le tapis tombe : tout se ferme
        $this->assertSame([0, ["TAPIS-$this->s"]], [$sheet()[$mat]['onMat'], $this->beat($token, $mats, 30)['resendMats']]);
        $late($fifth);
        $late($sixth);
        $this->assertSame(2, $sheet()[$mat]['onMat'], 'tapis revenu, boîtier en retard : ce qu\'il redit est bien posé');
        $token = $this->again($token); // la même machine se réinscrit avec la même clé (aucune remise à zéro), les pièces sont restées sur le tapis
        $this->current = $token;
        $this->assertSame([0, ["TAPIS-$this->s"]], [$sheet()[$mat]['onMat'], $this->beat($token, $mats, 30)['resendMats']]);
        $late($fifth);
        $this->assertSame(1, $sheet()[$mat]['onMat'], 'réinscrite, boîtier en retard : ce qu\'il redit est bien posé');
        // retiré par erreur, annoncé de nouveau aussitôt, toujours en retard : pareil
        $this->json($this->admin('POST', self::BOXES . "/$id/appareils/$mat/retirer", ['confirm' => true]));
        $this->assertSame([0, ["TAPIS-$this->s"]], [(int) $this->db->fetchOne('SELECT COUNT(*) FROM loss_counter_presence WHERE device_id = ? AND until IS NULL', [$mat]), $this->beat($token, $mats, 30)['resendMats']]);
        $late($fifth);
        $this->assertSame(1, $sheet()[$mat]['onMat'], 'retiré puis revenu, boîtier en retard : ce qu\'il redit est bien posé');
        $this->json($this->admin('PUT', self::BOXES . "/$id/appareils/$mat", ['cashRegisterId' => $this->register])); // son poste lui est rendu pour la suite
        $this->beat($token, $mats); // remis à l'heure

        // Retirer un appareil remplacé : confirmation ; il ne compte plus, n'envoie plus de faits, rend son poste ; annoncé de nouveau, il revient
        $retire = fn (int $device, array $body, int $status = 200): array => $this->json($this->admin('POST', self::BOXES . "/$id/appareils/$device/retirer", $body), $status);
        $this->assertSame(['confirm' => 'REQUIRED'], $retire($mat, [], 422)['fields']);
        $this->assertSame('DEVICE_NOT_FOUND', $retire(999999999, ['confirm' => true], 404)['error']);
        $before = count($events());
        $after = $retire($mat, ['confirm' => true]);
        $this->assertNotContains($mat, array_column($after['devices'], 'id'));
        $retire($mat, ['confirm' => true]);
        $this->assertSame([[['DEVICE_RETIRED', $mat, []]], 'retired', 0], [array_slice($events(), $before), $this->db->fetchOne('SELECT status FROM loss_device WHERE id = ?', [$mat]),
            (int) $this->db->fetchOne('SELECT COUNT(*) FROM loss_counter_presence WHERE device_id = ? AND until IS NULL', [$mat])]);
        $this->assertSame('DEVICE_NOT_FOUND', $assign($mat, ['cashRegisterId' => $this->register], 404)['error'], 'un appareil retiré ne se relie plus');
        $this->assertArrayNotHasKey("mat-$mat", $health());
        $this->assertNotContains($mat, array_column($this->json($this->box('GET', '/reglages', null, $token))['devices'], 'id'));
        $this->assertSame(['refused', 'DEVICE_UNKNOWN'], $this->fact($token, 'COUNTER_SEEN', "TAPIS-$this->s", $nfc));
        $this->assertSame($this->register, array_column($assign($otherDevices["TAPIS-B-$this->s"], ['cashRegisterId' => $this->register], 200, $otherBox)['devices'], null, 'id')[$otherDevices["TAPIS-B-$this->s"]]['cashRegister']['id'],
            'le poste est rendu : un autre tapis le prend');
        $this->beat($token, [['serial' => "LECTEUR-$this->s", 'kind' => 'reader', 'status' => 'ok']]);
        $this->assertSame('retired', $this->db->fetchOne('SELECT status FROM loss_device WHERE id = ?', [$mat]), 'un appareil retiré que le boîtier n\'annonce pas reste retiré');
        $before = count($events());
        $this->beat($token, [['serial' => "TAPIS-$this->s", 'kind' => 'counter_mat', 'status' => 'ok'], ['serial' => "LECTEUR-$this->s", 'kind' => 'reader', 'status' => 'ok']]);
        $this->assertSame(['ok', null, ['DEVICE_SEEN', $mat, ['kind' => 'counter_mat', 'again' => true]]],
            [$this->db->fetchOne('SELECT status FROM loss_device WHERE id = ?', [$mat]), $this->db->fetchOne('SELECT cash_register_id FROM loss_device WHERE id = ?', [$mat]), $events()[$before]],
            'il revient, sans poste : le sien a été repris par un autre tapis');
        $this->assertSame([], $this->beat($token, [...$mats, ['serial' => "LECTEUR-$this->s", 'kind' => 'reader', 'status' => 'ok']])['resendMats'], 'déjà redemandé à son retour : plus rien à redire');
        // Boîtier révoqué : ses tapis rendent leur poste (un autre tapis peut le prendre) et ne se relient plus
        $otherMat = $otherDevices["TAPIS-B-$this->s"];
        $this->json($this->admin('POST', self::BOXES . "/$otherBox/revoquer", ['confirm' => true]));
        $this->assertNull($this->db->fetchOne('SELECT cash_register_id FROM loss_device WHERE id = ?', [$otherMat]));
        $this->assertSame('DEVICE_NOT_FOUND', $assign($otherMat, ['cashRegisterId' => $this->register], 404, $otherBox)['error']);
        $this->assertSame($this->register, array_column($assign($mat, ['cashRegisterId' => $this->register])['devices'], null, 'id')[$mat]['cashRegister']['id'], 'le poste du boîtier révoqué est libre');

        // Droits : administrateur ; sans le droit du module, fermé
        $this->loginPos(['ROLE_USER_POS']);
        foreach ([['PUT', self::BOXES . "/$id/appareils/$reader"], ['POST', self::BOXES . "/$id/appareils/$reader/retirer"]] as [$method, $path]) {
            $this->assertSame(403, $this->call($method, $path, ['zone' => 'exit', 'confirm' => true], $this->posToken)->getStatusCode(), "$method $path : un caissier");
        }
        static::getContainer()->get(PlatformModeService::class)->setMode('live', 'test');
        $this->flags(true, false);
        $this->assertSame('MODULE_INACTIVE', $this->json($this->admin('PUT', self::BOXES . "/$id/appareils/$reader", ['zone' => 'exit']), 403)['code']);
        $this->assertSame('MODULE_INACTIVE', $this->json($this->admin('POST', self::BOXES . "/$id/appareils/$reader/retirer", ['confirm' => true]), 403)['code']);
        $this->flags(true, true);
    }

    public function testTheTillAsksWhichTrackedItemsWereNotReadOnTheMatOfItsOwnRegisterAndNothingIsWritten(): void
    {
        [$a, $b, $c, $d, $e, $f, $g] = $this->article('Veste', 7);
        $a1 = $this->tagged($a, '01');
        $a2 = $this->tagged($a, '02');
        $b1 = $this->tagged($b, '03');
        $this->json($this->admin('POST', self::BASE . '/pieces', ['variantId' => $d]), 201); // une pièce sans puce : son article n'est pas suivi
        $e1 = $this->tagged($e, '04');
        $f1 = $this->tagged($f, '05');
        [$id, $token, $devices] = $this->enrolled('Caisse 1', [['serial' => "TAPIS-$this->s", 'kind' => 'counter_mat', 'status' => 'ok']]);
        $mat = $devices["TAPIS-$this->s"];
        $this->json($this->admin('PUT', self::BOXES . "/$id/appareils/$mat", ['cashRegisterId' => $this->register]));
        $this->loginPos(['ROLE_USER_POS']);
        $this->openSession();
        $basket = [['productVariantId' => $a, 'quantity' => 1], ['productVariantId' => $b, 'quantity' => 1], ['productVariantId' => $c, 'quantity' => 1], ['productVariantId' => $d, 'quantity' => 3],
            ['productVariantId' => $a, 'quantity' => 1]];
        $ask = fn (array $items, ?int $register = null, int $status = 200): array => $this->json($this->pos('POST', self::UNREAD, ['cashRegisterId' => $register ?? $this->register, 'items' => $items]), $status);
        $writes = fn (): array => array_map('intval', $this->db->fetchNumeric('SELECT (SELECT COUNT(*) FROM loss_piece_event), (SELECT COUNT(*) FROM loss_box_event), (SELECT COUNT(*) FROM loss_journal), (SELECT COUNT(*) FROM loss_finding),'
            . ' (SELECT COUNT(*) FROM loss_counter_presence), (SELECT COUNT(*) FROM pos_audit_log), (SELECT COUNT(*) FROM "order"), (SELECT COUNT(*) FROM inventory_movements), (SELECT COUNT(*) FROM loss_piece WHERE status <> \'created\')'));
        $short = fn (array $answer): array => [$answer['active'], $answer['reason'], $answer['tracked'], $answer['read'], array_map(fn (array $u): array => [$u['productVariantId'], $u['quantity'], $u['missing']], $answer['unread']), $answer['extra']];

        // Rien sur le tapis : les deux articles suivis du panier manquent ; l'article sans pièce et celui dont la pièce n'a pas de puce ne sont pas suivis
        $before = $writes();
        $empty = $ask($basket);
        $this->assertSame(['active', 'reason', 'cashRegisterId', 'tracked', 'read', 'unread', 'extra', 'checkedAt', 'display', 'notice', 'checkId'], array_keys($empty));
        $this->assertSame([false, null, null], [$empty['display'], $empty['notice'], $empty['checkId']], 'l\'alerte en caisse est éteinte par défaut (lot L5-a)');
        $this->assertSame([true, null, 3, 0, [[$a, 2, 2], [$b, 1, 1]], 0], $short($empty));
        $this->assertSame([$this->register, ['productVariantId' => $a, 'name' => "Veste L3B-$this->s", 'variantLabel' => null, 'quantity' => 2, 'missing' => 2]], [$empty['cashRegisterId'], $empty['unread'][0]]);
        $this->assertEqualsWithDelta(time(), strtotime($empty['checkedAt']), 5);
        $this->assertSame([true, null, 0, 0, [], 0], $short($ask([])), 'un panier vide');
        $this->assertSame([true, null, 0, 0, [], 0], $short($ask([['productVariantId' => $c, 'quantity' => 2], ['productVariantId' => 999999999, 'quantity' => 1]])), 'aucun article suivi : rien à lire');

        // Les pièces arrivent sur le tapis
        $this->fact($token, 'COUNTER_SEEN', "TAPIS-$this->s", $a1);
        $this->assertSame([true, null, 3, 1, [[$a, 2, 1], [$b, 1, 1]], 0], $short($ask($basket)));
        $this->fact($token, 'COUNTER_SEEN', "TAPIS-$this->s", $a2);
        $this->fact($token, 'COUNTER_SEEN', "TAPIS-$this->s", $b1);
        $this->fact($token, 'COUNTER_SEEN', "TAPIS-$this->s", $e1);
        $this->assertSame([true, null, 3, 3, [], 1], $short($ask($basket)), 'tout est lu ; une pièce posée n\'est pas dans le panier : un nombre, sans dire laquelle');
        $this->assertSame([true, null, 1, 1, [], 3], $short($ask([['productVariantId' => $a, 'quantity' => 1]])), 'une pièce de trop du même article compte dans le surplus');
        $this->assertStringNotContainsString((string) $e, json_encode($ask($basket)['unread']));
        // Une pièce qui vient de quitter le tapis compte encore (10 minutes) ; partie depuis plus longtemps, non
        $this->fact($token, 'COUNTER_LEFT', "TAPIS-$this->s", $b1);
        $this->assertSame([true, null, 3, 3, [], 1], $short($ask($basket)));
        $this->fact($token, 'COUNTER_SEEN', "TAPIS-$this->s", $f1, ['at' => gmdate('Y-m-d\TH:i:s\Z', time() - 720)]);
        $this->fact($token, 'COUNTER_LEFT', "TAPIS-$this->s", $f1, ['at' => gmdate('Y-m-d\TH:i:s\Z', time() - 660)]);
        $this->assertSame([true, null, 1, 0, [[$f, 1, 1]], 3], $short($ask([['productVariantId' => $f, 'quantity' => 1]])), 'posée puis reprise il y a onze minutes : pas lue');
        $this->fact($token, 'COUNTER_SEEN', "TAPIS-$this->s", $f1, ['at' => gmdate('Y-m-d\TH:i:s\Z', time() - 540)]);
        $this->fact($token, 'COUNTER_LEFT', "TAPIS-$this->s", $f1, ['at' => gmdate('Y-m-d\TH:i:s\Z', time() - 480)]);
        $this->assertSame([true, null, 1, 1, [], 3], $short($ask([['productVariantId' => $f, 'quantity' => 1]])), 'reprise il y a huit minutes : lue');
        // Une pièce vendue ou retirée ne compte pas, une puce retirée non plus
        $pieceOf = fn (string $identifier): array => array_map('intval', $this->db->fetchNumeric('SELECT piece_id, id FROM loss_tag WHERE identifier = ?', [$identifier]));
        $this->db->executeStatement("UPDATE loss_piece SET status = 'sold' WHERE id = ?", [$pieceOf($a2)[0]]);
        $this->assertSame([true, null, 3, 2, [[$a, 2, 1]], 1], $short($ask($basket)), 'une pièce déjà vendue posée sur le tapis ne vaut pas pour une nouvelle vente');
        $this->db->executeStatement("UPDATE loss_piece SET status = 'created' WHERE id = ?", [$pieceOf($a2)[0]]);
        $this->json($this->admin('POST', self::BASE . '/puces/' . $pieceOf($a1)[1] . '/retirer', ['reason' => 'error', 'confirm' => true]));
        $this->assertSame([true, null, 3, 2, [[$a, 2, 1]], 1], $short($ask($basket)), 'une puce retirée ne lit plus sa pièce');

        // C'est une lecture : rien n'a été écrit par ces questions (les faits du tapis et la puce retirée viennent d'ailleurs)
        $now = $writes();
        $ask($basket);
        $ask($basket);
        $this->assertSame($now, $writes());
        $this->assertSame([$before[2], $before[3], $before[5], $before[6], $before[7], $before[8]], [$now[2], $now[3], $now[5], $now[6], $now[7], $now[8]], 'ni journal du module, ni constat, ni journal de caisse, ni vente, ni stock, ni état de pièce');

        // Corps invalide : refusé, sans rien lire
        $invalid = fn (mixed $body): array => array_column($this->json($this->pos('POST', self::UNREAD, $body), 422)['errors'], 'path');
        $this->assertSame(['items'], $invalid(['cashRegisterId' => $this->register]));
        $this->assertSame(['items'], $invalid(['items' => ['a' => 1]]));
        $this->assertSame(['items[0].productVariantId', 'items[1].quantity', 'items[2].productVariantId', 'items[3].quantity', 'items[4].productVariantId'],
            $invalid(['items' => [['quantity' => 1], ['productVariantId' => $a, 'quantity' => 0], 'x', ['productVariantId' => $a, 'quantity' => 10000], ['productVariantId' => (string) $a, 'quantity' => 1]]]));
        $this->assertSame(['items'], $invalid(['items' => array_fill(0, 201, ['productVariantId' => $a, 'quantity' => 1])]));
        $this->assertSame(['cashRegisterId'], $invalid(['cashRegisterId' => (string) $this->register, 'items' => []]));
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . self::UNREAD, [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $this->posToken], '{pas du json');
        $this->assertSame('VALIDATION_FAILED', $this->json($this->client->getResponse(), 422)['error']);

        // Le poste : celui où le caissier vend. Inconnu, fermé : refusé comme pour une vente
        $this->assertSame('CASH_REGISTER_NOT_FOUND', $ask($basket, 999999999, 404)['error']);
        // Un second poste arrive sur le site. Tant que la tablette du caissier n'est rattachée à aucun poste, on ne sait plus lequel est
        // le sien : pas de contrôle, pour aucun poste (sur un site à un seul poste, la question ne se posait pas)
        $second = (int) $this->json($this->admin('POST', '/api/cash-registers', ['name' => "Caisse 2 $this->s"]), 201)['id'];
        $this->assertSame('CASH_REGISTER_CLOSED', $ask($basket, $second, 409)['error']);
        $this->json($this->pos('POST', '/api/caisse/open', ['cashRegisterId' => $second, 'cashFund' => 10000]), 201);
        $this->assertSame([[false, 'TERMINAL_NOT_BOUND', 0, 0, [], 0], [false, 'TERMINAL_NOT_BOUND', 0, 0, [], 0]], [$short($ask($basket)), $short($ask($basket, $second))],
            'un caissier sans tablette rattachée ne lit le tapis d\'aucun poste');
        // Le responsable et l'administrateur lisent tous les postes, comme à la caisse
        $this->assertSame([true, false, 'NO_MAT'], [($byAdmin = $this->json($this->call('POST', self::UNREAD, ['cashRegisterId' => $this->register, 'items' => $basket], $this->adminToken)))['active'],
            ($noMat = $this->json($this->call('POST', self::UNREAD, ['cashRegisterId' => $second, 'items' => $basket], $this->adminToken)))['active'], $noMat['reason']]);
        $this->assertSame([$second, 0, []], [$noMat['cashRegisterId'], $noMat['tracked'], $noMat['unread']], 'un poste sans tapis : pas de contrôle ; le tapis du premier poste n\'y est pour rien');
        $this->assertSame(2, $byAdmin['read']);
        // Chaque tablette rattachée à son poste : son caissier lit le tapis de CE poste, et lui seul
        $first = $this->activateTerminal("Tablette 1 $this->s");
        $firstHeader = $this->terminal;
        $this->assertSame('TERMINAL_NOT_BOUND', $ask($basket)['reason'], 'une tablette activée mais rattachée à aucun poste : pas de contrôle');
        $this->json($this->call('PUT', '/api/app-keys/' . $first, ['cashRegisterId' => $this->register], $this->adminToken));
        $forbidden = $ask($basket, $second, 403);
        $this->assertSame(['CASH_REGISTER_FORBIDDEN', false], [$forbidden['error'], array_key_exists('unread', $forbidden)], 'la tablette du poste 1 ne lit pas le tapis du poste 2');
        $this->assertSame([true, null, 3, 2], array_slice($short($ask($basket)), 0, 4), 'son poste : oui');
        $this->terminal = null;
        $this->assertSame('TERMINAL_NOT_BOUND', $ask($basket)['reason'], 'le même caissier, sans l\'en-tête de sa tablette : pas de contrôle');
        $otherTerminal = $this->activateTerminal("Tablette 2 $this->s");
        $secondHeader = $this->terminal;
        $this->json($this->call('PUT', '/api/app-keys/' . $otherTerminal, ['cashRegisterId' => $second], $this->adminToken));
        $this->assertSame([false, 'NO_MAT'], array_slice($short($ask($basket, $second)), 0, 2));
        // Le poste 2 reçoit son tapis, d'un autre boîtier : chacun ne lit que le sien
        [$otherBox, $otherToken, $otherDevices] = $this->enrolled('Caisse 2', [['serial' => "TAPIS-2-$this->s", 'kind' => 'counter_mat', 'status' => 'ok']], 'seconde-machine-0123456789abcdef');
        $this->json($this->admin('PUT', self::BOXES . "/$otherBox/appareils/" . $otherDevices["TAPIS-2-$this->s"], ['cashRegisterId' => $second]));
        $this->assertSame([true, null, 3, 0, [[$a, 2, 2], [$b, 1, 1]], 0], $short($ask($basket, $second)), 'les pièces posées sur le tapis du poste 1 ne sont pas lues depuis le poste 2');
        $this->fact($otherToken, 'COUNTER_SEEN', "TAPIS-2-$this->s", $e1);
        $this->assertSame([true, null, 1, 1, [], 0], $short($ask([['productVariantId' => $e, 'quantity' => 1]], $second)));
        $this->terminal = $firstHeader;
        $this->assertSame(0, $ask($basket)['extra'], 'la pièce posée sur le second tapis n\'est plus comptée sur le premier : une puce n\'est que sur un tapis à la fois');
        // Un départ reçu seul (son arrivée s'est perdue) : le tapis a bien vu la puce, la pièce compte comme lue ; elle n'est pas « posée »
        $g1 = $this->tagged($g, '06');
        $this->assertSame([true, null, 1, 0, [[$g, 1, 1]], 1], $short($ask([['productVariantId' => $g, 'quantity' => 1]])));
        $this->fact($token, 'COUNTER_LEFT', "TAPIS-$this->s", $g1, ['at' => gmdate('Y-m-d\TH:i:s\Z', time() - 5)]);
        $this->assertSame([true, null, 1, 1, [], 1], $short($ask([['productVariantId' => $g, 'quantity' => 1]])), 'un départ seul vaut lecture, pas présence');

        // Pas de contrôle plutôt qu'un faux avis : tapis en panne, boîtier silencieux, horloge déréglée, tapis retiré, boîtier révoqué,
        // module absent. Toujours 200
        $this->beat($token, [['serial' => "TAPIS-$this->s", 'kind' => 'counter_mat', 'status' => 'down']]);
        $this->assertSame([false, 'MAT_SILENT', 0, 0, [], 0], $short($ask($basket)));
        $this->beat($token, [['serial' => "TAPIS-$this->s", 'kind' => 'counter_mat', 'status' => 'ok']]);
        $back = $ask($basket);
        $this->assertSame([true, null, 3, 2, 0], [...array_slice($short($back), 0, 4), $back['extra']],
            'revenu : ce qui était posé juste avant la panne compte encore comme lu (moins de 10 minutes), mais plus rien n\'est dit « posé » tant que le tapis ne l\'a pas redit');
        $this->db->executeStatement("UPDATE loss_box SET last_seen_at = NOW() AT TIME ZONE 'UTC' - INTERVAL '10 minutes' WHERE id = ?", [$id]);
        $this->assertSame([false, 'MAT_SILENT'], array_slice($short($ask($basket)), 0, 2));
        $this->db->executeStatement("UPDATE loss_box SET last_seen_at = NOW() AT TIME ZONE 'UTC' WHERE id = ?", [$id]);
        foreach ([90, null] as $offset) {
            $this->db->executeStatement('UPDATE loss_box SET clock_offset = ? WHERE id = ?', [$offset, $id]);
            $this->assertSame([false, 'MAT_SILENT'], array_slice($short($ask($basket)), 0, 2), 'horloge du boîtier déréglée ou pas encore mesurée : ses faits ne posent rien, donc pas d\'avis');
        }
        $this->db->executeStatement('UPDATE loss_box SET clock_offset = 0 WHERE id = ?', [$id]);
        $this->assertTrue($ask($basket)['active']);
        static::getContainer()->get(PlatformModeService::class)->setMode('live', 'test');
        $this->flags(true, false);
        $this->assertSame([false, 'MODULE_INACTIVE', 0, 0, [], 0], $short($ask($basket)), 'sans le module : 200, jamais un refus qui gênerait la caisse');
        $this->assertSame([false, 'MODULE_INACTIVE', 999999999], [($closed = $ask($basket, 999999999))['active'], $closed['reason'], $closed['cashRegisterId']], 'ni le poste ni le panier ne sont regardés plus loin');
        $this->flags(true, true);
        static::getContainer()->get(PlatformModeService::class)->setMode('demo', 'test');
        $this->json($this->admin('POST', self::BOXES . "/$id/appareils/$mat/retirer", ['confirm' => true]));
        $this->assertSame([false, 'NO_MAT'], array_slice($short($ask($basket)), 0, 2));
        $this->terminal = $secondHeader;
        $this->assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM loss_counter_presence WHERE device_id = ? AND until IS NULL', [$otherDevices["TAPIS-2-$this->s"]]));
        $this->json($this->admin('POST', self::BOXES . "/$otherBox/revoquer", ['confirm' => true]));
        $this->assertSame([false, 'NO_MAT'], array_slice($short($ask($basket, $second)), 0, 2));
        $this->assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM loss_counter_presence WHERE device_id = ? AND until IS NULL', [$otherDevices["TAPIS-2-$this->s"]]), 'boîtier révoqué : plus rien n\'est dit posé sur ses tapis');
        $this->terminal = null;

        // Droits : la caisse et l'administrateur ; ni un anonyme, ni un client du web, ni un boîtier
        $this->assertSame(401, $this->call('POST', self::UNREAD, ['items' => []], null)->getStatusCode());
        $this->assertSame(200, $this->call('POST', self::UNREAD, ['cashRegisterId' => $this->register, 'items' => []], $this->adminToken)->getStatusCode());
        $this->loginPos(['ROLE_USER_POS', 'ROLE_POS_MANAGER']);
        $this->assertSame(['NO_MAT', 'NO_MAT'], [$this->json($this->pos('POST', self::UNREAD, ['cashRegisterId' => $this->register, 'items' => []]))['reason'], $this->json($this->pos('POST', self::UNREAD, ['cashRegisterId' => $second, 'items' => []]))['reason']],
            'un responsable de caisse, sans tablette rattachée : tous les postes');
        $customer = $this->user(['ROLE_USER_INTERNET'], 'Cliente');
        $this->assertSame(403, $this->call('POST', self::UNREAD, ['items' => []], static::getContainer()->get('lexik_jwt_authentication.jwt_manager')->create($customer))->getStatusCode());
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . self::UNREAD, [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json', 'HTTP_X_LOSS_BOX' => $token], '{"items":[]}');
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
    }

    public function testTheTillShowsTheAlertOnlyWhenTheManagerTurnsItOnAndCountsEachCheckOncePerRegisterAndDay(): void
    {
        // Lot L5-a (17/11/2026, docs/prevention-pertes.md § 18.2, « B ») : l'alerte en caisse
        [$a] = $this->article('Gilet');
        $a1 = $this->tagged($a, '11');
        $a2 = $this->tagged($a, '12');
        [$id, $token, $devices] = $this->enrolled('Caisse 1', [['serial' => "TAPIS-$this->s", 'kind' => 'counter_mat', 'status' => 'ok']]);
        $mat = $devices["TAPIS-$this->s"];
        $this->json($this->admin('PUT', self::BOXES . "/$id/appareils/$mat", ['cashRegisterId' => $this->register]));
        $this->loginPos(['ROLE_USER_POS']);
        $this->openSession();
        $basket = ['cashRegisterId' => $this->register, 'items' => [['productVariantId' => $a, 'quantity' => 2]]];
        $ask = fn (string $query = ''): array => $this->json($this->pos('POST', self::UNREAD . $query, $basket));
        $after = function (mixed $body, int $status = 204): Response {
            $response = $this->pos('POST', self::UNREAD . '/suite', $body);
            $this->assertSame($status, $response->getStatusCode(), (string) $response->getContent());

            return $response;
        };
        $counters = fn (): array => array_map(fn (array $r): array => array_map('intval', $r), $this->db->fetchAllAssociative('SELECT cash_register_id, shown, proceeded FROM loss_counter_alert_day ORDER BY 1'));
        $writes = fn (): array => array_map('intval', $this->db->fetchNumeric('SELECT (SELECT COUNT(*) FROM loss_journal), (SELECT COUNT(*) FROM loss_finding), (SELECT COUNT(*) FROM pos_audit_log),'
            . ' (SELECT COUNT(*) FROM "order"), (SELECT COUNT(*) FROM inventory_movements)'));

        // Le réglage : permis partout, éteint par défaut ; la caisse ne montre rien
        $settings = $this->json($this->admin('GET', self::BASE . '/reglages'));
        $this->assertSame(['allowed', 'enabled', 'availableFrom', 'note'], array_keys($settings['cashierAlerts']));
        $this->assertSame([true, false, null], [$settings['cashierAlerts']['allowed'], $settings['cashierAlerts']['enabled'], $settings['cashierAlerts']['availableFrom']]);
        $this->assertStringContainsString('ne bloque jamais la vente', $settings['cashierAlerts']['note']);
        $off = $ask();
        $this->assertSame([true, 2, false, null, null], [$off['active'], $off['unread'][0]['missing'], $off['display'], $off['notice'], $off['checkId']], 'éteinte : rien à montrer, même s\'il manque des articles');
        // Écrit par le PUT des réglages, comme les autres ; une valeur qui n'est pas un booléen est refusée, rien n'est écrit
        foreach ([['enabled' => 'oui'], true, ['enabled' => null], []] as $wrong) {
            $this->assertSame(['cashierAlerts.enabled' => 'MUST_BE_BOOLEAN'], $this->json($this->admin('PUT', self::BASE . '/reglages', ['cashierAlerts' => $wrong]), 422)['fields']);
        }
        $this->assertFalse((bool) $this->db->fetchOne('SELECT cashier_alerts_enabled FROM loss_setting ORDER BY id LIMIT 1'));
        $this->assertTrue($this->json($this->admin('PUT', self::BASE . '/reglages', ['cashierAlerts' => ['enabled' => true]]))['cashierAlerts']['enabled']);
        $this->assertSame(['cashier_alerts_enabled'], json_decode((string) $this->db->fetchOne("SELECT data FROM loss_journal WHERE type = 'SETTINGS_CHANGED' ORDER BY id DESC LIMIT 1"), true)['fields']);

        // Allumée : il manque deux articles, la caisse montre le texte du serveur et reçoit une vérification
        $before = $writes();
        $first = $ask('?locale=fr');
        $this->assertSame([true, '2 articles suivis non lus : posez-les sur le tapis.'], [$first['display'], $first['notice']]);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $first['checkId']);
        $this->assertSame('2 tracked items not read: place them on the mat.', $ask('?locale=en')['notice']);
        $this->fact($token, 'COUNTER_SEEN', "TAPIS-$this->s", $a1);
        $one = $ask('?locale=fr');
        $this->assertSame([true, '1 article suivi non lu : posez-le sur le tapis.'], [$one['display'], $one['notice']]);
        $this->assertNotSame($first['checkId'], $one['checkId'], 'une vérification par question');
        $this->fact($token, 'COUNTER_SEEN', "TAPIS-$this->s", $a2);
        $all = $ask();
        $this->assertSame([[], false, null, null], [$all['unread'], $all['display'], $all['notice'], $all['checkId']], 'tout est lu : rien à montrer');

        // La suite, au paiement : une fois par vérification, pour son poste et le jour du magasin
        $this->assertSame([], $counters());
        $after(['checkId' => $first['checkId'], 'proceeded' => true]);
        $this->assertSame([['cash_register_id' => $this->register, 'shown' => 1, 'proceeded' => 1]], $counters());
        $after(['checkId' => $first['checkId'], 'proceeded' => true]);
        $after(['checkId' => str_repeat('ab', 16), 'proceeded' => true]);
        $this->assertSame([['cash_register_id' => $this->register, 'shown' => 1, 'proceeded' => 1]], $counters(), 'rejouée ou inconnue : 204, rien n\'est compté');
        $after(['checkId' => $one['checkId'], 'proceeded' => false]);
        $this->assertSame([['cash_register_id' => $this->register, 'shown' => 2, 'proceeded' => 1]], $counters(), 'relu puis payé : montrée, sans « continuer quand même »');
        $this->assertSame(['cash_register_id', 'store_day', 'shown', 'proceeded', 'updated_at'], array_keys($this->db->fetchAssociative('SELECT * FROM loss_counter_alert_day LIMIT 1')),
            'des compteurs : ni personne, ni vente, ni article');
        $this->assertSame($this->today(), (string) $this->db->fetchOne('SELECT store_day FROM loss_counter_alert_day LIMIT 1'));
        $this->assertSame($before, $writes(), 'ni journal du module, ni constat, ni journal de caisse, ni vente, ni stock (les deux faits du tapis viennent du boîtier)');
        // Corps invalide : 422, rien n'est compté
        $paths = fn (mixed $body): array => array_column($this->json($after($body, 422), 422)['errors'], 'path');
        $this->assertSame(['checkId', 'proceeded'], $paths([]));
        $this->assertSame(['checkId'], $paths(['checkId' => 'XYZ', 'proceeded' => true]));
        $this->assertSame(['checkId'], $paths(['checkId' => strtoupper(str_repeat('ab', 16)), 'proceeded' => true]));
        $this->assertSame(['proceeded'], $paths(['checkId' => str_repeat('ab', 16), 'proceeded' => 'oui']));
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . self::UNREAD . '/suite', [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $this->posToken], '{pas du json');
        $this->assertSame('VALIDATION_FAILED', $this->json($this->client->getResponse(), 422)['error']);
        $this->assertSame(2, (int) $this->db->fetchOne('SELECT shown FROM loss_counter_alert_day'));

        // Le bilan du soir : des postes, jamais une personne ; rien en exercice
        $evening = $this->json($this->admin('GET', self::BASE . '/bilan'))['counterAlerts'];
        $name = (string) $this->db->fetchOne('SELECT name FROM cash_register WHERE id = ?', [$this->register]);
        $this->assertSame(['shown' => 2, 'proceeded' => 1, 'byRegister' => [['cashRegisterId' => $this->register, 'name' => $name, 'shown' => 2, 'proceeded' => 1]]], $evening);
        $this->assertSame(['shown' => 0, 'proceeded' => 0, 'byRegister' => []], $this->json($this->admin('GET', self::BASE . '/bilan?day=' . (new \DateTimeImmutable('-3 days'))->format('Y-m-d')))['counterAlerts']);
        $this->assertSame(['shown' => 0, 'proceeded' => 0, 'byRegister' => []], $this->json($this->admin('GET', self::BASE . '/exercices/bilan'))['counterAlerts']);

        // Une vérification encore valable, gardée pour la suite (trois articles, deux pièces : il en manque une)
        $spare = $this->json($this->pos('POST', self::UNREAD, ['cashRegisterId' => $this->register, 'items' => [['productVariantId' => $a, 'quantity' => 3]]]))['checkId'];
        $this->assertNotNull($spare);
        // Éteinte de nouveau : plus rien à montrer ; rallumée ensuite
        $this->assertFalse($this->json($this->admin('PUT', self::BASE . '/reglages', ['cashierAlerts' => ['enabled' => false]]))['cashierAlerts']['enabled']);
        $this->assertSame([false, null], array_values(array_intersect_key($this->json($this->pos('POST', self::UNREAD, ['cashRegisterId' => $this->register, 'items' => [['productVariantId' => $a, 'quantity' => 3]]])),
            ['display' => 0, 'checkId' => 0])));
        $this->json($this->admin('PUT', self::BASE . '/reglages', ['cashierAlerts' => ['enabled' => true]]));
        // Pas de contrôle : rien à montrer, même allumée
        $this->json($this->admin('POST', self::BOXES . "/$id/appareils/$mat/retirer", ['confirm' => true]));
        $noMat = $ask();
        $this->assertSame([false, 'NO_MAT', false, null, null], [$noMat['active'], $noMat['reason'], $noMat['display'], $noMat['notice'], $noMat['checkId']]);
        // Sans le module : la suite d'une vérification encore valable ne compte rien (et ne gêne pas la caisse)
        // (le droit du module se lit en base quel que soit le mode de la plateforme : en mode « demo », aucune garde ne refuse avant)
        $this->flags(true, false);
        $after(['checkId' => $spare, 'proceeded' => true]);
        $this->flags(true, true);
        $this->assertSame(2, (int) $this->db->fetchOne('SELECT shown FROM loss_counter_alert_day'), 'sans le module : rien n\'est compté');
        $after(['checkId' => $spare, 'proceeded' => true]);
        $this->assertSame(3, (int) $this->db->fetchOne('SELECT shown FROM loss_counter_alert_day'), 'le module revenu : la vérification restée valable compte, une fois');

        // Droits : la caisse et l'administrateur ; ni un anonyme, ni un client du web
        $this->assertSame(401, $this->call('POST', self::UNREAD . '/suite', ['checkId' => str_repeat('ab', 16), 'proceeded' => true], null)->getStatusCode());
        $customer = $this->user(['ROLE_USER_INTERNET'], 'Cliente');
        $this->assertSame(403, $this->call('POST', self::UNREAD . '/suite', ['checkId' => str_repeat('ab', 16), 'proceeded' => true], static::getContainer()->get('lexik_jwt_authentication.jwt_manager')->create($customer))->getStatusCode());
        $this->assertSame(204, $this->call('POST', self::UNREAD . '/suite', ['checkId' => str_repeat('ab', 16), 'proceeded' => true], $this->adminToken)->getStatusCode());
    }

    public function testABoxAndATillWorkOnASiteStillUnderConstruction(): void
    {
        // Un commerçant installe son matériel AVANT d'ouvrir : sur un site neuf, fermé en entier (lot CONSTRUCTION, étendue « site »), le
        // boîtier s'inscrit, donne signe de vie et envoie ses faits, la caisse pose sa question, le gérant règle et lit son matériel
        [$variant] = $this->article('Robe');
        $nfc = $this->tagged($variant, '01');
        $kept = $this->db->fetchAssociative('SELECT storefront_state, is_boutique_active, storefront_opened_at FROM entreprise ORDER BY id LIMIT 1');
        $set = function (string $state, bool $shop, ?string $openedAt): void {
            $this->db->executeStatement('UPDATE entreprise SET storefront_state = ?, is_boutique_active = ?, storefront_opened_at = ?', [$state, $shop ? 'true' : 'false', $openedAt]);
            static::getContainer()->get(\App\Services\TenantCacheService::class)->invalidateSite(MV_TEST_TENANT_CODE, MV_TEST_TENANT_DB);
        };
        try {
            $set('construction', true, null);
            $front = $this->json($this->call('GET', '/api/site/storefront', null, null));
            $this->assertSame(['construction', 'site'], [$front['state'], $front['scope']], 'le site est fermé en entier');
            $this->assertSame('STOREFRONT_UNDER_CONSTRUCTION', $this->json($this->call('GET', '/api/landingpage-settings', null, null), 403)['code'] ?? null, 'témoin : le visiteur est bien refusé');

            // le boîtier : inscription par sa clé, signe de vie, faits ; le gérant relie le tapis à un poste et lit le tableau de santé
            [$id, $token, $devices] = $this->enrolled('Comptoir', [['serial' => "TAPIS-$this->s", 'kind' => 'counter_mat', 'status' => 'ok']]);
            $mat = $devices["TAPIS-$this->s"];
            $this->json($this->admin('PUT', self::BOXES . "/$id/appareils/$mat", ['cashRegisterId' => $this->register]));
            $this->assertSame(['accepted', null], $this->fact($token, 'COUNTER_SEEN', "TAPIS-$this->s", $nfc));
            $this->assertSame(200, $this->box('GET', '/reglages', null, $token)->getStatusCode());
            $line = array_column($this->json($this->admin('GET', self::BASE . '/sante'))['devices'], null, 'code')["mat-$mat"];
            $this->assertSame(['ok', $this->register], [$line['state'], $line['cashRegisterId']]);
            // un jeton inconnu reste refusé par la garde du boîtier, pas par la fermeture du site
            $refused = $this->box('POST', '/signe-de-vie', ['now' => gmdate('Y-m-d\TH:i:s\Z'), 'machine' => self::MACHINE, 'devices' => []], str_repeat('0', 64));
            $this->assertSame(401, $refused->getStatusCode());
            $this->assertNotSame('STOREFRONT_UNDER_CONSTRUCTION', $this->json($refused, 401)['code'] ?? null);

            // la caisse : connexion du caissier, session, question avant paiement (la vente en magasin n'est pas fermée par la construction)
            $this->loginPos(['ROLE_USER_POS']);
            $this->openSession();
            $answer = $this->json($this->pos('POST', self::UNREAD, ['cashRegisterId' => $this->register, 'items' => [['productVariantId' => $variant, 'quantity' => 1]]]));
            $this->assertSame([true, null, 1, 1, []], [$answer['active'], $answer['reason'], $answer['tracked'], $answer['read'], $answer['unread']]);
            $this->closeWith(0);
            // l'analyse passe et la synthèse du gérant répond
            $this->drain();
            $this->assertSame(200, $this->admin('GET', self::BASE)->getStatusCode());
        } finally {
            $set((string) $kept['storefront_state'], (bool) $kept['is_boutique_active'], $kept['storefront_opened_at']);
        }
    }

    public function testTheTillQuestionHasACeilingPerAccount(): void
    {
        $this->loginPos(['ROLE_USER_POS']);
        $this->openSession();
        // le plafond compte les LIGNES de panier (une question vide compte pour une) : 200 lignes par question, le plus qu'elle porte
        $ask = fn (int $lines): array => $this->json($this->pos('POST', self::UNREAD, ['cashRegisterId' => $this->register, 'items' => array_fill(0, $lines, ['productVariantId' => 999999999, 'quantity' => 1])]));
        $limit = \App\LossPrevention\Services\LossBoxLimits::LIMITS['till'];
        $this->assertSame(0, $limit % 200);
        for ($i = 0; $i < intdiv($limit, 200) - 1; ++$i) {
            if ($ask(200)['reason'] !== 'NO_MAT') {
                $this->fail("question $i : " . json_encode($ask(200)));
            }
        }
        $this->assertSame(['NO_MAT', 'NO_MAT'], [$ask(199)['reason'], $ask(0)['reason']], 'il reste 200 lignes : 199, puis une question vide, qui compte pour une');
        $this->assertSame([false, 'TOO_MANY_REQUESTS', $this->register, 0, []], [($over = $ask(1))['active'], $over['reason'], $over['cashRegisterId'], $over['tracked'], $over['unread']],
            'au-delà du plafond : pas de contrôle, toujours 200 (la caisse n\'est jamais gênée)');
        // un autre compte n'est pas touché
        $this->loginPos(['ROLE_USER_POS']);
        $this->assertSame('NO_MAT', $ask(1)['reason']);
    }

    public function testTheSimulatedMatPlaysItsScenariosAndIsErasedWithTheExercise(): void
    {
        $health = fn (): array => array_column($this->json($this->admin('GET', self::EX . '/sante'))['devices'], null, 'kind');
        $step = fn (): array => array_column($this->json($this->admin('GET', self::EX . '/installation'))['optionalSteps'], null, 'code')['counter_mat'];
        $this->assertSame(['todo', 'absent', 'Aucun tapis simulé. Créez un boîtier simulé : il a son tapis.'], [($s = $step())['status'], $s['state'], $s['hint']]);
        $box = $this->json($this->admin('POST', self::EX . '/boitiers'), 201)['box'];
        $id = $box['id'];
        $mat = array_column($box['devices'], null, 'serial')['SIM-TAPIS'];
        $this->assertSame(['counter_mat', 'Tapis de comptoir', null, 0], [$mat['kind'], $mat['kindLabel'], $mat['cashRegister'], $mat['onMat']]);
        $this->assertSame(['pending', 'Le tapis simulé n\'a encore rien lu. Jouez le scénario « Pièce posée sur le tapis ».'], [($line = $health()['mat'])['state'], $line['hint']]);
        $play = fn (string $scenario, int $status = 200): array => $this->json($this->admin('POST', self::EX . "/boitiers/$id/scenarios", ['scenario' => $scenario]), $status);
        $this->assertSame('EXERCISE_NO_PIECE', $play('counter_put', 409)['error']);
        [$variant] = $this->article('Jupe');
        $piece = $this->json($this->admin('POST', self::EX . '/puces/associer', ['variantId' => $variant]))['piece']['id'];
        $this->assertSame(0, $play('counter_take')['written'], 'rien sur le tapis : rien à reprendre');
        $put = $play('counter_put');
        $onMat = fn (array $answer): int => array_column($answer['box']['devices'], null, 'serial')['SIM-TAPIS']['onMat'];
        $this->assertSame([1, 1, 0], [$put['written'], $onMat($put), $play('counter_put')['written']]);
        $this->assertSame([['ok', null], ['done', 'ok']], [[($line = $health()['mat'])['state'], $line['hint']], [($s = $step())['status'], $s['state']]], 'un tapis simulé n\'a pas de poste de caisse : lu, il est en ordre');
        $this->assertSame('Posée sur le tapis de comptoir', array_slice(array_column($this->json($this->admin('GET', self::EX . "/pieces/$piece"))['events'], 'label'), -1)[0]);
        $play('counter_put');
        $this->assertSame([1, 0], [$onMat($play('heartbeat')), $onMat($play('network_cut'))], 'comme un vrai boîtier : au retour d\'une coupure, ce qui était posé ne l\'est plus pour nous');
        $play('counter_put');
        $this->json($this->admin('POST', self::EX . '/puces/associer', ['variantId' => $variant])); // une pièce d'exercice de plus, créée après : ce n'est pas elle qui est posée
        $take = $play('counter_take');
        $this->assertSame([1, 0, 'Reprise du tapis de comptoir'], [$take['written'], $onMat($take), array_slice(array_column($this->json($this->admin('GET', self::EX . "/pieces/$piece"))['events'], 'label'), -1)[0]]);
        // rien de tout cela n'existe pour la caisse : un tapis simulé n'a pas de poste, une pièce d'exercice n'est pas suivie
        $play('counter_put');
        $this->db->executeStatement("UPDATE loss_device SET cash_register_id = ? WHERE exercise AND kind = 'counter_mat'", [$this->register]); // même si la base lui portait un poste
        $this->loginPos(['ROLE_USER_POS']);
        $this->openSession();
        $answer = $this->json($this->pos('POST', self::UNREAD, ['cashRegisterId' => $this->register, 'items' => [['productVariantId' => $variant, 'quantity' => 1]]]));
        $this->assertSame([false, 'NO_MAT'], [$answer['active'], $answer['reason']]);
        // effacer les pièces d'exercice quand l'une est posée sur le tapis : la présence part avec elles ; puis les boîtiers
        $this->assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM loss_counter_presence WHERE exercise AND until IS NULL'));
        $this->json($this->admin('DELETE', self::EX . '/pieces'));
        $this->assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM loss_counter_presence WHERE exercise'));
        $this->json($this->admin('POST', self::EX . '/puces/associer', ['variantId' => $variant]));
        $play('counter_put');
        $this->assertSame(['deleted' => 1], $this->json($this->admin('DELETE', self::EX . '/boitiers')));
        $this->assertSame([0, 0], array_map('intval', $this->db->fetchNumeric('SELECT (SELECT COUNT(*) FROM loss_counter_presence WHERE exercise), (SELECT COUNT(*) FROM loss_device WHERE exercise)')));
    }

    public function testTheDatabaseGuardsTheMatItselfAndTheMigrationReplays(): void
    {
        $schema = fn (): array => [
            $this->db->fetchFirstColumn("SELECT indexname FROM pg_indexes WHERE indexname IN ('loss_counter_presence_pkey', 'uniq_loss_counter_presence_open', 'idx_loss_counter_presence_device', 'idx_loss_counter_presence_exercise', 'uniq_loss_device_register') ORDER BY 1"),
            $this->db->fetchFirstColumn("SELECT t.tgname FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid WHERE c.relname = 'loss_counter_presence' AND NOT t.tgisinternal ORDER BY 1"),
            (bool) $this->db->fetchOne("SELECT 1 FROM pg_proc WHERE proname = 'loss_counter_presence_guard'"),
            str_contains((string) $this->db->fetchOne("SELECT prosrc FROM pg_proc WHERE proname = 'loss_device_guard'"), "'retired'"),
            str_contains((string) $this->db->fetchOne("SELECT prosrc FROM pg_proc WHERE proname = 'loss_box_event_guard'"), 'DEVICE_RETIRED'),
            str_contains((string) $this->db->fetchOne("SELECT prosrc FROM pg_proc WHERE proname = 'loss_box_rows_kept'"), 'loss_counter_presence'),
        ];
        $before = $schema();
        $this->assertSame([['idx_loss_counter_presence_device', 'idx_loss_counter_presence_exercise', 'loss_counter_presence_pkey', 'uniq_loss_counter_presence_open', 'uniq_loss_device_register'],
            ['loss_counter_presence_guard'], true, true, true, true], $before);
        $rows = fn (): array => array_map('intval', $this->db->fetchNumeric('SELECT (SELECT COUNT(*) FROM loss_counter_presence), (SELECT COUNT(*) FROM loss_device), (SELECT COUNT(*) FROM loss_box), (SELECT COUNT(*) FROM loss_box_event),'
            . ' (SELECT COUNT(*) FROM loss_piece_event), (SELECT COUNT(*) FROM loss_tag), (SELECT COUNT(*) FROM cash_register)'));
        $rowsBefore = $rows();
        [$variant] = $this->article('Garde');
        $nfc = $this->tagged($variant, '09');
        [$tagId, $pieceId] = array_map('intval', $this->db->fetchNumeric('SELECT id, piece_id FROM loss_tag WHERE identifier = ?', [$nfc]));
        $rowsBefore = $rows();

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
            $box = fn (bool $exercise): int => (int) $this->db->fetchOne("INSERT INTO loss_box (name, key_hash, status, machine_hash, exercise, created_at, updated_at) VALUES ('x', ?, 'active', ?, CAST(? AS boolean), NOW(), NOW()) RETURNING id",
                [$exercise ? null : "cle-$this->s-" . ++$this->n, $exercise ? null : 'machine', $exercise ? 'true' : 'false']);
            $device = fn (int $boxId, string $kind, bool $exercise, ?int $register = null, string $status = 'ok'): int => (int) $this->db->fetchOne(
                'INSERT INTO loss_device (box_id, serial, kind, status, cash_register_id, exercise, first_seen_at, last_seen_at) VALUES (?, ?, ?, ?, ?, CAST(? AS boolean), NOW(), NOW()) RETURNING id',
                [$boxId, 'S' . ++$this->n, $kind, $status, $register, $exercise ? 'true' : 'false']);
            $real = $box(false);
            $sim = $box(true);
            $mat = $device($real, 'counter_mat', false, $this->register);
            $reader = $device($real, 'reader', false);
            $simMat = $device($sim, 'counter_mat', true);
            // Un poste a au plus un tapis en service ; seul un tapis se relie à un poste ; un tapis retiré rend son poste
            $insertDevice = 'INSERT INTO loss_device (box_id, serial, kind, status, cash_register_id, exercise, first_seen_at, last_seen_at) VALUES (?, ?, ?, ?, ?, false, NOW(), NOW())';
            $refused('deux tapis pour un poste', $insertDevice, [$real, 'DOUBLE', 'counter_mat', 'ok', $this->register]);
            $refused('un lecteur relié à un poste', $insertDevice, [$real, 'LECTEUR-POSTE', 'reader', 'ok', $this->register]);
            $refused('un état d\'appareil inconnu', $insertDevice, [$real, 'ETAT', 'reader', 'perdu', null]);
            $this->db->executeStatement("UPDATE loss_device SET status = 'retired' WHERE id = ?", [$mat]);
            $second = $device($real, 'counter_mat', false, $this->register);
            $refused('le tapis retiré qui revient sur un poste repris', "UPDATE loss_device SET status = 'ok' WHERE id = ?", [$mat]);
            // Présences
            $insert = 'INSERT INTO loss_counter_presence (device_id, tag_id, piece_id, since, until, exercise) VALUES (?, ?, ?, CAST(? AS timestamp), CAST(? AS timestamp), CAST(? AS boolean))';
            $at = gmdate('Y-m-d H:i:s');
            $refused('une présence sur un lecteur', $insert, [$reader, $tagId, $pieceId, $at, null, 'false']);
            $refused('une présence sur un appareil inconnu', $insert, [999999999, $tagId, $pieceId, $at, null, 'false']);
            $refused('une vraie puce sur un tapis d\'exercice', $insert, [$simMat, $tagId, $pieceId, $at, null, 'true']);
            $refused('une présence d\'exercice sur un vrai tapis', $insert, [$second, $tagId, $pieceId, $at, null, 'true']);
            $refused('une puce inconnue', $insert, [$second, 999999999, $pieceId, $at, null, 'false']);
            $refused('une autre pièce que celle de la puce', $insert, [$second, $tagId, 999999999, $at, null, 'false']);
            $refused('une présence fermée avant son début', $insert, [$second, $tagId, $pieceId, $at, gmdate('Y-m-d H:i:s', time() - 60), 'false']);
            $this->db->executeStatement($insert, [$second, $tagId, $pieceId, $at, null, 'false']);
            $refused('la même puce posée deux fois sur le même tapis', $insert, [$second, $tagId, $pieceId, $at, null, 'false']);
            $refused('changer le tapis d\'une présence', 'UPDATE loss_counter_presence SET device_id = ? WHERE device_id = ?', [$mat, $second]);
            $refused('changer le début d\'une présence', "UPDATE loss_counter_presence SET since = since - INTERVAL '1 hour' WHERE device_id = ?", [$second]);
            $this->db->executeStatement('UPDATE loss_counter_presence SET until = since WHERE device_id = ?', [$second]);
            $this->db->executeStatement($insert, [$second, $tagId, $pieceId, $at, null, 'false']); // partie, elle peut revenir
            // Deux genres de faits de boîtier de plus
            $this->db->executeStatement("INSERT INTO loss_box_event (box_id, device_id, type, occurred_at, exercise, data) VALUES (?, ?, 'DEVICE_ASSIGNED', NOW(), false, '{}'), (?, ?, 'DEVICE_RETIRED', NOW(), false, '{}')", [$real, $second, $real, $mat]);
            // Un appareil d'exercice ne part pas tant qu'une présence le désigne
            $this->db->executeStatement("SET LOCAL loss.purge = 'exercise'");
            $simTag = (int) $this->db->fetchOne("INSERT INTO loss_tag (identifier, raw, technology, status, exercise, created_at, updated_at) VALUES ('SIM-0123456789AB', 'SIM-0123456789AB', 'simulated', 'known', true, NOW(), NOW()) RETURNING id");
            $this->db->executeStatement($insert, [$simMat, $simTag, null, $at, null, 'true']);
            $refused('le tapis avant ses présences', 'DELETE FROM loss_device WHERE id = ?', [$simMat]);
            $this->db->executeStatement('DELETE FROM loss_counter_presence WHERE device_id = ?', [$simMat]);
            $this->assertSame(1, $this->db->executeStatement('DELETE FROM loss_device WHERE id = ?', [$simMat]));
        } finally {
            $this->db->rollBack();
        }
        $this->assertSame($rowsBefore, $rows());

        require_once dirname(__DIR__, 3) . '/migrations/Version20270114100000.php';
        // La migration se rejoue sur une base qui la porte déjà : rien ne change
        $this->db->beginTransaction();
        foreach (\DoctrineMigrations\Version20270114100000::STATEMENTS as $sql) {
            $this->db->executeStatement($sql);
        }
        $this->db->commit();
        $this->assertSame([$before, $rowsBefore], [$schema(), $rows()]);
        // Le retour arrière retire la table et l'index, remet la garde des suppressions du lot précédent, et ne touche à rien d'autre ;
        // puis la migration repose le même schéma. Dans une transaction annulée à la fin
        $this->db->beginTransaction();
        try {
            $this->db->executeStatement("SET LOCAL lock_timeout = '5s'");
            foreach (\DoctrineMigrations\Version20270114100000::DOWN_STATEMENTS as $sql) {
                $this->db->executeStatement($sql);
            }
            $this->assertSame([[], [], false, true, true, false], $schema(), 'la garde des suppressions ne lit plus la table retirée');
            $this->assertSame(array_slice($rowsBefore, 1), array_map('intval', $this->db->fetchNumeric('SELECT (SELECT COUNT(*) FROM loss_device), (SELECT COUNT(*) FROM loss_box), (SELECT COUNT(*) FROM loss_box_event),'
                . ' (SELECT COUNT(*) FROM loss_piece_event), (SELECT COUNT(*) FROM loss_tag), (SELECT COUNT(*) FROM cash_register)')));
            foreach (\DoctrineMigrations\Version20270114100000::STATEMENTS as $sql) {
                $this->db->executeStatement($sql);
            }
            $this->assertSame($before, $schema());
        } finally {
            $this->db->rollBack();
        }
        $this->assertSame([$before, $rowsBefore], [$schema(), $rows()]);
    }

    // ----- aides -----

    /**
     * Un boîtier créé, inscrit, qui a annoncé ses appareils.
     *
     * @param list<array<string, mixed>> $devices
     *
     * @return array{0: int, 1: string, 2: array<string, int>} identifiant, jeton, identifiants des appareils par numéro de série
     */
    private function enrolled(string $name, array $devices, string $machine = self::MACHINE): array
    {
        $created = $this->json($this->admin('POST', self::BOXES, ['name' => "$name $this->s"]), 201);
        $enrolment = $this->json($this->box('POST', '/inscription', ['key' => $created['key'], 'machine' => $machine, 'version' => '1.0.0', 'arch' => 'amd64'], null));
        $token = $enrolment['token'];
        $this->machines[$token] = $machine;
        $this->keys[$token] = $created['key'];
        $this->answered[$token] = $enrolment['serverTime'];
        $settings = $this->beat($token, $devices)['settings'];

        return [$created['box']['id'], $token, array_column($settings['devices'], 'id', 'serial')];
    }

    /** La même machine se réinscrit avec la clé du boîtier (remise à zéro pour l'occasion) ; rend son nouveau jeton */
    private function reenrol(int $id, string $oldToken): string
    {
        $reset = $this->json($this->admin('POST', self::BOXES . "/$id/cle", ['confirm' => true]));
        $this->assertSame([], array_filter(array_column($reset['box']['devices'], 'onMat')), 'clé remise à zéro : la machine d\'avant est détachée, rien n\'est plus dit posé');
        $key = $reset['key'];
        $machine = $this->machines[$oldToken];
        $enrolment = $this->json($this->box('POST', '/inscription', ['key' => $key, 'machine' => $machine, 'version' => '1.0.1', 'arch' => 'amd64'], null));
        $this->machines[$enrolment['token']] = $machine;
        $this->keys[$enrolment['token']] = $key;
        $this->answered[$enrolment['token']] = $enrolment['serverTime'];

        return $enrolment['token'];
    }

    /** La même machine se réinscrit avec la MÊME clé, sans remise à zéro (un boîtier réinstallé) ; rend son nouveau jeton */
    private function again(string $oldToken): string
    {
        $enrolment = $this->json($this->box('POST', '/inscription', ['key' => $this->keys[$oldToken], 'machine' => $this->machines[$oldToken], 'version' => '1.0.2', 'arch' => 'amd64'], null));
        $this->machines[$enrolment['token']] = $this->machines[$oldToken];
        $this->keys[$enrolment['token']] = $this->keys[$oldToken];
        $this->answered[$enrolment['token']] = $enrolment['serverTime'];

        return $enrolment['token'];
    }

    /** Le jeton du moment, pour une fonction définie avant une réinscription */
    private string $current = '';

    /** @var array<string, string> la clé du boîtier de chaque jeton */
    private array $keys = [];

    /** @var array<string, string> la machine de chaque jeton */
    private array $machines = [];

    /** @var array<string, string> l'heure du serveur lue dans la dernière réponse reçue par chaque boîtier d'essai (inscription, signe de vie) */
    private array $answered = [];

    /**
     * Un signe de vie. Le boîtier y renvoie l'heure du serveur de la dernière réponse qu'il a reçue.
     *
     * @param list<array<string, mixed>> $devices
     * @param int                        $late    retard de l'horloge du boîtier sur celle du serveur (secondes ; négatif : avance)
     * @param bool                       $lost    la réponse se perd en route : le boîtier ne la lit pas
     */
    private function beat(string $token, array $devices, int $late = 0, bool $lost = false): array
    {
        // en vrai une minute sépare deux signes de vie : une réponse qui va se perdre ne porte pas la même heure que la dernière lue
        while ($lost && time() <= strtotime($this->answered[$token])) {
            usleep(100000);
        }
        $answer = $this->json($this->box('POST', '/signe-de-vie', ['now' => gmdate('Y-m-d\TH:i:s\Z', time() - $late), 'machine' => $this->machines[$token], 'devices' => $devices,
            'lastServerTime' => $this->answered[$token]], $token));
        if (!$lost) {
            $this->answered[$token] = $answer['serverTime'];
        }

        return $answer;
    }

    /**
     * Un fait envoyé par un boîtier.
     *
     * @param array<string, mixed> $over
     *
     * @return array{0: string, 1: ?string} état et code de la réponse du fait
     */
    private function fact(string $token, string $type, string $serial, string $identifier, array $over = []): array
    {
        $fact = $over + ['key' => 'fait-' . $this->s . '-' . str_pad((string) ++$this->n, 4, '0', STR_PAD_LEFT), 'type' => $type, 'at' => gmdate('Y-m-d\TH:i:s\Z'), 'device' => $serial, 'data' => ['identifier' => $identifier]];
        $result = $this->json($this->box('POST', '/faits', ['facts' => [$fact]], $token))['results'][0];

        return [$result['status'], $result['code']];
    }

    /** Une vraie pièce de cette variante, avec sa puce NFC ; rend l'identifiant de la puce */
    private function tagged(int $variant, string $suffix): string
    {
        $nfc = '04' . $this->s . 'AA00' . $suffix;
        $this->json($this->admin('POST', self::BASE . '/puces/associer', ['variantId' => $variant, 'identifier' => $nfc, 'technology' => 'nfc']));

        return $nfc;
    }

    /** Un appel du boîtier : son jeton dans l'en-tête X-Loss-Box */
    private function box(string $method, string $path, ?array $body, ?string $token): Response
    {
        $this->client->getCookieJar()->clear();
        $this->client->request($method, 'https://' . MV_TEST_TENANT_HOST . '/api/pertes/boitier' . $path, [], [], array_filter([
            'HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_LOSS_BOX' => $token,
        ]) + ['REMOTE_ADDR' => $this->ip], $body !== null ? json_encode($body) : null);

        return $this->client->getResponse();
    }

    /** @return list<int> les variantes d'un article créé pour ce test (une par défaut) */
    private function article(string $name, int $variants = 1): array
    {
        $slug = 'l3b-' . bin2hex(random_bytes(4));
        $this->db->executeStatement("INSERT INTO product (id, name, price, isbestseller, quantity, created_at, slug, product_type, is_web, is_pos) VALUES (nextval('product_id_seq'), :n, 3000, false, 0, NOW(), :s, 'standard', false, true)",
            ['n' => "$name L3B-$this->s", 's' => $slug]);
        $product = (int) $this->db->fetchOne('SELECT id FROM product WHERE slug = ?', [$slug]);
        $ids = [];
        for ($i = 0; $i < $variants; ++$i) {
            $ids[] = (int) $this->db->fetchOne("INSERT INTO product_variant (id, product_id, stock_quantity) VALUES (nextval('product_variant_id_seq'), ?, 5) RETURNING id", [$product]);
        }

        return $ids;
    }
}
