<?php

namespace App\Tests\Functional\LossPrevention;

use App\LossPrevention\Entity\LossBox;
use App\LossPrevention\Entity\LossPiece;
use App\LossPrevention\Entity\LossSaleUnit;
use Doctrine\DBAL\ArrayParameterType;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lot L4-a (16/11/2026, `docs/prevention-pertes.md` § 17.12) : les antennes de zone. Ce que le boîtier en dit (une pièce arrive
 * dans une zone, elle en part), la présence par zone qui en découle, « où la pièce a été vue en dernier » sur sa fiche, la liste
 * et la synthèse, les deux durées servies au boîtier, la pastille et l'étape des antennes, les antennes simulées de l'exercice.
 * Aucun constat, rien dans la caisse, rien dans le stock : l'état d'une pièce ne change pas.
 */
class LossPreventionZoneTest extends LossPreventionTestCase
{
    private const BOXES = self::BASE . '/boitiers';
    private const EX = self::BASE . '/exercices';
    private const MACHINE = 'machine-de-zone-0123456789abcdef';

    private string $s;
    private string $ip;
    private int $n = 0;
    private string $machine = self::MACHINE;
    private string $answered = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->s = strtoupper(bin2hex(random_bytes(3)));
        $this->ip = '10.' . random_int(1, 250) . '.' . random_int(1, 250) . '.' . random_int(1, 250);
        $this->flags(true, true);
        $this->db->executeStatement('UPDATE loss_setting SET zone_arrive_seconds = NULL, zone_leave_seconds = NULL');
    }

    protected function tearDown(): void
    {
        try {
            while ($this->db->isTransactionActive()) {
                $this->db->rollBack();
            }
            $this->db->executeStatement('UPDATE loss_setting SET zone_arrive_seconds = NULL, zone_leave_seconds = NULL');
            $this->db->executeStatement("UPDATE loss_box SET status = 'revoked', revoked_at = NOW() WHERE NOT exercise AND status <> 'revoked'");
            $this->db->executeStatement("UPDATE loss_device SET status = 'retired', cash_register_id = NULL WHERE NOT exercise AND status <> 'retired'");
            $this->db->executeStatement("UPDATE loss_zone_presence SET until = GREATEST(since, NOW() AT TIME ZONE 'UTC') WHERE until IS NULL AND NOT exercise"); // rien de ce test ne reste « vu » pour les suivants
            $this->db->beginTransaction();
            $this->provider->getEntityManager()->getRepository(LossPiece::class)->deleteExercise();
            $this->provider->getEntityManager()->getRepository(LossBox::class)->deleteExercise();
            $this->db->commit();
            $ids = $this->db->fetchFirstColumn('SELECT id FROM product WHERE name LIKE ?', ['% L4A-' . $this->s]);
            if ($ids !== []) {
                $this->db->executeStatement('DELETE FROM product_variant WHERE product_id IN (?)', [$ids], [ArrayParameterType::INTEGER]);
                $this->db->executeStatement('DELETE FROM product WHERE id IN (?)', [$ids], [ArrayParameterType::INTEGER]);
            }
        } finally {
            parent::tearDown();
        }
    }

    public function testAnAntennaSaysWhereAPieceWasLastSeenWithoutChangingItsStatus(): void
    {
        $variant = $this->article('Parka');
        [$a, $pieceA] = $this->tagged($variant, '01');
        [$b, $pieceB] = $this->tagged($variant, '02');
        $serials = ['res' => "ANT-RES-$this->s", 'ray' => "ANT-RAY-$this->s", 'sortie' => "ANT-SORTIE-$this->s", 'main' => "LECTEUR-MAIN-$this->s"];
        $announced = array_map(static fn (string $serial): array => ['serial' => $serial, 'kind' => 'reader', 'status' => 'ok'], array_values($serials));
        [$box, $token, $devices] = $this->enrolled('Boutique', $announced);
        $health = fn (string $base = self::BASE): array => array_column($this->json($this->admin('GET', $base . '/sante'))['devices'], null, 'code');
        $step = fn (string $base = self::BASE): array => array_column($this->json($this->admin('GET', $base . '/installation'))['optionalSteps'], null, 'code')['zone_reader'];
        $sheet = fn (int $piece): array => $this->json($this->admin('GET', self::BASE . "/pieces/$piece"));
        $zones = fn (): array => array_column($this->json($this->admin('GET', self::BASE . "/pieces/synthese?variantId=$variant"))['zones'], 'pieces', 'zone');
        $presences = fn (int $piece): array => array_map(static fn (array $r): array => [(string) $r['zone'], $r['until'] !== null],
            $this->db->fetchAllAssociative('SELECT zone, until FROM loss_zone_presence WHERE piece_id = ? ORDER BY since, id', [$piece]));
        $at = static fn (int $shift): string => gmdate('Y-m-d\TH:i:s\Z', time() + $shift);

        // Un lecteur sans zone est un lecteur à main : ni pastille ni étape. Aucune antenne : l'étape dit quoi brancher
        $this->assertSame([], array_filter(array_keys($health()), static fn (string $code): bool => str_starts_with($code, 'reader-')));
        $this->assertSame(['zone_reader', 'Brancher les antennes de zone', 'todo', 'absent', 'Aucune antenne de zone. Branchez une antenne sur le boîtier, puis donnez-lui sa zone dans la fiche du boîtier.'],
            [($s = $step())['code'], $s['title'], $s['status'], $s['state'], $s['hint']]);
        foreach (['res' => 'stockroom', 'ray' => 'floor', 'sortie' => 'exit'] as $key => $zone) {
            $this->json($this->admin('PUT', self::BOXES . "/$box/appareils/" . $devices[$serials[$key]], ['zone' => $zone]));
        }
        // Une antenne branchée, qui n'a encore rien vu : en attente, son libellé et son conseil nomment sa zone
        $line = $health()['reader-' . $devices[$serials['res']]];
        $this->assertSame(['zone_reader', 'Antenne : Réserve', 'stockroom', null, 'pending', 'En attente', 'L\'antenne « Réserve » n\'a encore vu aucune puce. Passez devant elle une pièce qui porte une puce.'],
            [$line['kind'], $line['label'], $line['zone'], $line['cashRegisterId'], $line['state'], $line['stateLabel'], $line['hint']]);
        $this->assertArrayNotHasKey('reader-' . $devices[$serials['main']], $health());
        $this->assertSame(['todo', 'pending'], [($s = $step())['status'], $s['state']]);
        $this->assertSame('Antenna: Stockroom', array_column($this->json($this->admin('GET', self::BASE . '/sante?locale=en'))['devices'], null, 'code')['reader-' . $devices[$serials['res']]]['label']);

        // Les réglages servis au boîtier : les deux durées, par défaut puis réglées ; le fait de départ est accepté
        $settings = $this->beat($token, $announced)['settings'];
        $this->assertSame(['arriveSeconds' => 3, 'leaveSeconds' => 30], $settings['zonePresence']);
        $this->assertContains('ZONE_LEFT', $settings['facts']['types']);
        $view = $this->json($this->admin('GET', self::BASE . '/reglages'))['pieces'];
        $this->assertSame([3, 30, ['min' => 1, 'max' => 60], ['min' => 5, 'max' => 600], 3, 30],
            [$view['zoneArriveSeconds'], $view['zoneLeaveSeconds'], $view['bounds']['zoneArriveSeconds'], $view['bounds']['zoneLeaveSeconds'], $view['defaults']['zoneArriveSeconds'], $view['defaults']['zoneLeaveSeconds']]);
        $this->json($this->admin('PUT', self::BASE . '/reglages', ['pieces' => ['zoneArriveSeconds' => 5, 'zoneLeaveSeconds' => 60]]));
        $this->assertSame(['arriveSeconds' => 5, 'leaveSeconds' => 60], $this->beat($token, $announced)['settings']['zonePresence']);
        foreach ([['zoneArriveSeconds' => 0], ['zoneArriveSeconds' => 61], ['zoneLeaveSeconds' => 4], ['zoneLeaveSeconds' => 601]] as $bad) {
            $this->json($this->admin('PUT', self::BASE . '/reglages', ['pieces' => $bad]), 422);
        }
        $this->json($this->admin('PUT', self::BASE . '/reglages', ['pieces' => ['zoneArriveSeconds' => null, 'zoneLeaveSeconds' => null]]));
        $this->assertSame(['arriveSeconds' => 3, 'leaveSeconds' => 30], $this->beat($token, $announced)['settings']['zonePresence']);

        // Aucune antenne n'a rien vu : la fiche ne dit aucune zone
        $this->assertSame([null, null, null, null, null, 'created'], [($p = $sheet($pieceA))['zone'], $p['zoneLabel'], $p['zoneSeenAt'], $p['zoneLeftAt'], $p['zoneDevice'], $p['status']]);
        $this->assertSame(['stockroom' => 0, 'stockroom_door' => 0, 'floor' => 0, 'counter' => 0, 'exit' => 0], $zones());
        // une pièce d'un AUTRE article, vue à la réserve : elle ne compte pas dans la synthèse filtrée sur celui-ci
        [$other, $otherPiece] = $this->tagged($this->article('Bonnet'), '07');
        $this->assertSame(['accepted', null], $this->fact($token, 'ZONE_SEEN', $serials['res'], $other, ['zone' => 'stockroom', 'at' => $at(-90)]));
        $this->assertSame([0, 'stockroom'], [$zones()['stockroom'], $sheet($otherPiece)['zone']]);

        // La pièce A arrive à la réserve : sa fiche le dit, son état ne change pas
        $this->assertSame(['accepted', null], $this->fact($token, 'ZONE_SEEN', $serials['res'], $a, ['zone' => 'stockroom', 'at' => $at(-60)]));
        $p = $sheet($pieceA);
        $this->assertSame(['stockroom', 'Réserve', null, $serials['res'], 'created'], [$p['zone'], $p['zoneLabel'], $p['zoneLeftAt'], $p['zoneDevice'], $p['status']]);
        $this->assertSame(strtotime($at(-60)), strtotime((string) $p['zoneSeenAt']));
        $this->assertSame(1, $zones()['stockroom']);
        $this->assertSame('ok', $health()['reader-' . $devices[$serials['res']]]['state']);
        // l'étape n'est pas faite tant que CHAQUE antenne n'a pas vu une puce : elle nomme celle qui manque
        $this->assertSame(['todo', 'pending', true], [($s = $step())['status'], $s['state'], str_contains((string) $s['hint'], '« Rayon »')]);

        // Elle en part : la fiche garde la zone et dit l'heure du départ ; elle ne compte plus parmi les pièces vues à la réserve
        $this->assertSame(['accepted', null], $this->fact($token, 'ZONE_LEFT', $serials['res'], $a, ['zone' => 'stockroom', 'at' => $at(-40)]));
        $p = $sheet($pieceA);
        $this->assertSame(['stockroom', strtotime($at(-40))], [$p['zone'], strtotime((string) $p['zoneLeftAt'])]);
        $this->assertSame(0, $zones()['stockroom']);
        // Elle arrive en rayon ; la liste des pièces dit la même chose que la fiche
        $this->assertSame(['accepted', null], $this->fact($token, 'ZONE_SEEN', $serials['ray'], $a, ['zone' => 'floor', 'at' => $at(-20)]));
        $listed = array_column($this->json($this->admin('GET', self::BASE . "/pieces?variantId=$variant")), null, 'id')[$pieceA];
        $this->assertSame(['floor', 'Rayon', null, $serials['ray']], [$listed['zone'], $listed['zoneLabel'], $listed['zoneLeftAt'], $listed['zoneDevice']]);
        $this->assertSame([['stockroom', true], ['floor', false]], $presences($pieceA));
        $this->assertSame('Shop floor', $this->json($this->admin('GET', self::BASE . "/pieces/$pieceA?locale=en"))['zoneLabel']);

        // Un départ ou une arrivée dit sa zone, et une zone connue ; un fait rejoué ne pose rien une seconde fois
        $this->assertSame(['refused', 'ZONE_INVALID'], $this->fact($token, 'ZONE_LEFT', $serials['ray'], $a));
        $this->assertSame(['refused', 'ZONE_INVALID'], $this->fact($token, 'ZONE_LEFT', $serials['ray'], $a, ['zone' => 'nulle-part']));
        $this->assertSame(['accepted', null], $this->fact($token, 'ZONE_SEEN', $serials['res'], $b, ['zone' => 'stockroom', 'at' => $at(-30), 'key' => "rejoue-$this->s"]));
        $this->assertSame(['duplicate', null], $this->fact($token, 'ZONE_SEEN', $serials['res'], $b, ['zone' => 'stockroom', 'at' => $at(-30), 'key' => "rejoue-$this->s"]));
        $this->assertSame([['stockroom', false]], $presences($pieceB));
        // Une pièce n'est vue que par une antenne à la fois : arrivée à la sortie sans départ dit, ce que la réserve croyait voir se ferme
        $this->assertSame(['accepted', null], $this->fact($token, 'ZONE_SEEN', $serials['sortie'], $b, ['zone' => 'exit', 'at' => $at(-10)]));
        $this->assertSame([['stockroom', true], ['exit', false]], $presences($pieceB));
        $this->assertSame(['exit', 'Sortie', 'created'], [($p = $sheet($pieceB))['zone'], $p['zoneLabel'], $p['status']], 'vue à la sortie : son état ne change pas, aucun constat dans ce lot');
        // Une arrivée PLUS ANCIENNE, reçue en retard, ne ramène pas la pièce en arrière
        $this->assertSame(['accepted', null], $this->fact($token, 'ZONE_SEEN', $serials['ray'], $b, ['zone' => 'floor', 'at' => $at(-50)]));
        $this->assertSame([['stockroom', true], ['exit', false]], $presences($pieceB));
        // Une arrivée à l'heure douteuse (une heure dans le futur) est gardée comme fait, mais ne pose rien
        $this->assertSame(['accepted', null], $this->fact($token, 'ZONE_SEEN', $serials['ray'], $b, ['zone' => 'floor', 'at' => $at(3600)]));
        $this->assertSame('exit', $sheet($pieceB)['zone']);
        // Un départ plus ancien que l'arrivée connue ne ferme rien
        $this->assertSame(['accepted', null], $this->fact($token, 'ZONE_LEFT', $serials['sortie'], $b, ['zone' => 'exit', 'at' => $at(-15)]));
        $this->assertNull($sheet($pieceB)['zoneLeftAt']);

        // Chaque antenne a vu une puce : toutes en ordre, l'étape est faite
        $this->assertSame(['ok', 'ok', 'ok'], array_map(fn (string $key): string => $health()['reader-' . $devices[$serials[$key]]]['state'], ['res', 'ray', 'sortie']));
        $this->assertSame(['done', 'ok', null], [($s = $step())['status'], $s['state'], $s['hint']]);
        $this->assertSame(['stockroom' => 0, 'stockroom_door' => 0, 'floor' => 1, 'counter' => 0, 'exit' => 1], $zones());
        // Une antenne en panne : sa ligne et l'étape le disent, avec sa zone
        $down = $announced;
        $down[1]['status'] = 'down';
        $this->beat($token, $down);
        $line = $health()['reader-' . $devices[$serials['ray']]];
        $this->assertSame(['down', 'L\'antenne « Rayon » ne répond plus. Vérifiez son câble sur le boîtier, puis son alimentation.'], [$line['state'], $line['hint']]);
        $this->assertSame(['todo', 'down', $line['hint']], [($s = $step())['status'], $s['state'], $s['hint']]);
        $this->beat($token, $announced);

        // Une pièce retirée ne compte plus parmi les pièces vues dans une zone ; sa fiche garde sa dernière zone
        $this->json($this->admin('POST', self::BASE . "/pieces/$pieceA/retirer", ['reason' => 'lost', 'confirm' => true]));
        $this->assertSame([0, 'floor', 'retired'], [$zones()['floor'], ($p = $sheet($pieceA))['zone'], $p['status']]);

        // La conservation : les présences fermées anciennes s'effacent, jamais la DERNIÈRE d'une pièce (elle dit où on l'a vue en dernier)
        $tagB = (int) $this->db->fetchOne('SELECT id FROM loss_tag WHERE piece_id = ?', [$pieceB]);
        $this->db->executeStatement("UPDATE loss_zone_presence SET until = since WHERE piece_id = ? AND until IS NULL", [$pieceB]);
        $this->db->executeStatement("INSERT INTO loss_zone_presence (device_id, tag_id, piece_id, zone, since, until) VALUES (?, ?, ?, 'floor', NOW() AT TIME ZONE 'UTC' - INTERVAL '90 days', NOW() AT TIME ZONE 'UTC' - INTERVAL '89 days')",
            [$devices[$serials['ray']], $tagB, $pieceB]);
        $before = count($presences($pieceB));
        $purged = $this->provider->getEntityManager()->getRepository(LossSaleUnit::class)->purgePresences(gmdate('Y-m-d H:i:s', time() + 60), 100);
        $this->assertGreaterThanOrEqual($before - 1, $purged);
        $this->assertSame([['exit', true]], $presences($pieceB), 'toutes fermées et « anciennes » : seule la plus récente reste');
        $this->assertSame('exit', $sheet($pieceB)['zone']);
    }

    public function testTheSimulatedAntennasPlayTheirSceneAndTheDatabaseGuardsThePresences(): void
    {
        $variant = $this->article('Écharpe');
        [$real, $realPiece] = $this->tagged($variant, '09');
        $box = $this->json($this->admin('POST', self::EX . '/boitiers'), 201);
        $sim = $box['box']['id'];
        $byZone = array_column($box['box']['devices'], 'serial', 'zone');
        $this->assertSame(['SIM-ANTENNE-RESERVE', 'SIM-ANTENNE-RAYON', 'SIM-LECTEUR-SORTIE'], [$byZone['stockroom'], $byZone['floor'], $byZone['exit']]);
        $this->assertSame(['zone_move', 'De la réserve au rayon'], [($last = array_slice($box['scenarios'], -1)[0])['code'], $last['label']]);
        $play = fn (string $scenario, int $status = 200): array => $this->json($this->admin('POST', self::EX . "/boitiers/$sim/scenarios", ['scenario' => $scenario]), $status);
        $health = fn (): array => array_column($this->json($this->admin('GET', self::EX . '/sante'))['devices'], 'state', 'label');
        $step = fn (): array => array_column($this->json($this->admin('GET', self::EX . '/installation'))['optionalSteps'], null, 'code')['zone_reader'];
        $this->assertSame(['pending', 'pending', 'pending'], [$health()['Antenne : Réserve'], $health()['Antenne : Rayon'], $health()['Antenne : Sortie']]);
        $this->assertStringContainsString('Jouez le scénario « De la réserve au rayon »', (string) $step()['hint']);

        // Sans pièce d'exercice à puce, rien à déplacer
        $this->assertSame('EXERCISE_NO_PIECE', $play('zone_move', 409)['code']);
        $this->json($this->admin('POST', self::EX . '/pieces', ['variantId' => $variant, 'count' => 3]), 201);
        $play('print_ok'); // l'imprimante simulée pose une puce simulée sur les pièces d'exercice
        $moved = $play('zone_move');
        $this->assertSame(['zone_move', 3], [$moved['scenario'], $moved['written']]);
        $piece = (int) $this->db->fetchOne('SELECT piece_id FROM loss_zone_presence WHERE exercise ORDER BY id DESC LIMIT 1');
        $sheet = $this->json($this->admin('GET', self::EX . "/pieces/$piece"));
        $this->assertSame(['floor', 'Rayon', null, 'SIM-ANTENNE-RAYON', true], [$sheet['zone'], $sheet['zoneLabel'], $sheet['zoneLeftAt'], $sheet['zoneDevice'], $sheet['exercise']]);
        $this->assertSame(['ZONE_SEEN', 'ZONE_LEFT', 'ZONE_SEEN'], $this->db->fetchFirstColumn("SELECT type FROM loss_piece_event WHERE piece_id = ? AND type LIKE 'ZONE_%' ORDER BY occurred_at, id", [$piece]));
        $this->assertSame(['ok', 'ok', 'pending'], [$health()['Antenne : Réserve'], $health()['Antenne : Rayon'], $health()['Antenne : Sortie']]);
        $this->assertSame(['todo', 'pending'], [($s = $step())['status'], $s['state']]);
        $play('zone_exit');
        $this->assertSame(['done', 'ok'], [($s = $step())['status'], $s['state']]);
        // la lecture à la sortie pose elle aussi la présence : la pièce lue est vue en dernier à la sortie
        $this->assertSame(1, (int) $this->db->fetchOne("SELECT COUNT(*) FROM loss_zone_presence WHERE exercise AND zone = 'exit' AND until IS NULL"));
        // l'exercice ne se mêle pas au réel : ni dans la synthèse des vraies pièces, ni dans l'étape réelle
        $this->assertSame(0, array_sum(array_column($this->json($this->admin('GET', self::BASE . "/pieces/synthese?variantId=$variant"))['zones'], 'pieces')));
        $this->assertSame('absent', array_column($this->json($this->admin('GET', self::BASE . '/installation'))['optionalSteps'], null, 'code')['zone_reader']['state']);

        // La base garde elle-même les présences par zone
        [, $token, $devices] = $this->enrolled('Garde', [['serial' => "ANT-$this->s", 'kind' => 'reader', 'status' => 'ok'], ['serial' => "TAPIS-$this->s", 'kind' => 'counter_mat', 'status' => 'ok']]);
        [$reader, $mat] = [$devices["ANT-$this->s"], $devices["TAPIS-$this->s"]];
        $tag = (int) $this->db->fetchOne('SELECT id FROM loss_tag WHERE piece_id = ?', [$realPiece]);
        $simTag = (int) $this->db->fetchOne('SELECT id FROM loss_tag WHERE piece_id = ?', [$piece]);
        $this->assertSame(['accepted', null], $this->fact($token, 'ZONE_SEEN', "ANT-$this->s", $real, ['zone' => 'floor']));
        $this->assertSame(['refused', 'DEVICE_KIND_INVALID'], $this->fact($token, 'ZONE_LEFT', "TAPIS-$this->s", $real, ['zone' => 'floor']), 'un tapis ne dit pas un départ de zone');
        $refusedBy = function (string $sql, array $params, string $why): void {
            $this->db->executeStatement('SAVEPOINT garde');
            try {
                $this->db->executeStatement($sql, $params);
                $this->fail("la base a accepté : $why");
            } catch (\Doctrine\DBAL\Exception $e) {
                $this->assertStringContainsString('prévention des pertes', $e->getMessage(), $why);
            } finally {
                $this->db->executeStatement('ROLLBACK TO SAVEPOINT garde');
            }
        };
        $this->db->beginTransaction();
        $insert = "INSERT INTO loss_zone_presence (device_id, tag_id, piece_id, zone, since, until, exercise) VALUES (?, ?, ?, ?, NOW() AT TIME ZONE 'UTC' - INTERVAL '1 hour', NOW() AT TIME ZONE 'UTC' - INTERVAL '50 minutes', CAST(? AS boolean))";
        $refusedBy($insert, [$mat, $tag, $realPiece, 'floor', 'false'], 'une présence de zone sur un tapis');
        $refusedBy($insert, [$reader, $simTag, $piece, 'floor', 'false'], 'une puce d\'exercice vue par une vraie antenne');
        $refusedBy($insert, [$reader, $tag, $piece, 'floor', 'false'], 'une autre pièce que celle de la puce');
        $refusedBy($insert, [$reader, $tag, $realPiece, '', 'false'], 'une présence sans zone');
        $this->assertSame(1, $this->db->executeStatement($insert, [$reader, $tag, $realPiece, 'floor', 'false']));
        $id = (int) $this->db->fetchOne('SELECT MAX(id) FROM loss_zone_presence WHERE piece_id = ? AND until IS NOT NULL', [$realPiece]);
        $refusedBy("UPDATE loss_zone_presence SET zone = 'exit' WHERE id = ?", [$id], 'la zone d\'une présence changée');
        $refusedBy("UPDATE loss_zone_presence SET since = since - INTERVAL '1 hour' WHERE id = ?", [$id], 'le début d\'une présence changé');
        $refusedBy("UPDATE loss_zone_presence SET until = since - INTERVAL '1 second' WHERE id = ?", [$id], 'une présence fermée avant son début');
        foreach (['UPDATE loss_setting SET zone_arrive_seconds = 0' => 'une durée d\'arrivée nulle', 'UPDATE loss_setting SET zone_leave_seconds = 601' => 'un silence de départ hors bornes'] as $sql => $why) {
            $this->db->executeStatement('SAVEPOINT garde');
            try {
                $this->db->executeStatement($sql);
                $this->fail("la base a accepté : $why");
            } catch (\Doctrine\DBAL\Exception $e) {
                $this->assertStringContainsString('loss_setting_zone_seconds', $e->getMessage(), $why);
            } finally {
                $this->db->executeStatement('ROLLBACK TO SAVEPOINT garde');
            }
        }
        $this->db->rollBack();

        // La migration se rejoue sans rien casser, et son script porte les mêmes instructions
        require_once dirname(__DIR__, 3) . '/migrations/Version20270120100000.php';
        foreach (\DoctrineMigrations\Version20270120100000::STATEMENTS as $sql) {
            $this->db->executeStatement($sql);
        }
        $script = (string) @file_get_contents(dirname(__DIR__, 3) . '/scripts/migrate_all_v2_pertes_l4a.sh');
        if ($script !== '') { // le script n'est pas dans le dépôt (dossier ignoré) : comparé là où il existe
            foreach (\DoctrineMigrations\Version20270120100000::STATEMENTS as $sql) {
                $this->assertStringContainsString($sql . ';', $script);
            }
        }
        $this->assertSame(13, count(\DoctrineMigrations\Version20270120100000::STATEMENTS));

        // Effacer l'exercice efface ce que les antennes simulées ont vu, et rien du réel
        $this->json($this->admin('DELETE', self::EX . '/boitiers'));
        $this->assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM loss_zone_presence WHERE exercise'), 'effacer les boîtiers simulés efface ce que leurs antennes ont vu');
        $this->json($this->admin('DELETE', self::EX . '/pieces'));
        $this->assertSame([0, 1], [(int) $this->db->fetchOne('SELECT COUNT(*) FROM loss_zone_presence WHERE exercise'), (int) $this->db->fetchOne('SELECT COUNT(*) FROM loss_zone_presence WHERE piece_id = ?', [$realPiece])]);
    }

    /**
     * Un boîtier créé, inscrit, qui a annoncé ses appareils.
     *
     * @param list<array<string, mixed>> $devices
     *
     * @return array{0: int, 1: string, 2: array<string, int>} identifiant, jeton, identifiants des appareils par numéro de série
     */
    private function enrolled(string $name, array $devices): array
    {
        $this->machine = self::MACHINE . '-' . strtolower($this->s) . '-' . ++$this->n;
        $created = $this->json($this->admin('POST', self::BOXES, ['name' => "$name $this->s"]), 201);
        $enrolment = $this->json($this->box('POST', '/inscription', ['key' => $created['key'], 'machine' => $this->machine, 'version' => '1.0.0', 'arch' => 'amd64'], null));
        $this->answered = $enrolment['serverTime'];
        $settings = $this->beat($enrolment['token'], $devices)['settings'];

        return [$created['box']['id'], $enrolment['token'], array_column($settings['devices'], 'id', 'serial')];
    }

    /** @param list<array<string, mixed>> $devices */
    private function beat(string $token, array $devices): array
    {
        $answer = $this->json($this->box('POST', '/signe-de-vie', ['now' => gmdate('Y-m-d\TH:i:s\Z'), 'machine' => $this->machine, 'devices' => $devices, 'lastServerTime' => $this->answered], $token));
        $this->answered = $answer['serverTime'];

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
        $fact = $over + ['key' => 'zone-' . $this->s . '-' . str_pad((string) ++$this->n, 4, '0', STR_PAD_LEFT), 'type' => $type, 'at' => gmdate('Y-m-d\TH:i:s\Z'), 'device' => $serial, 'data' => ['identifier' => $identifier]];
        $result = $this->json($this->box('POST', '/faits', ['facts' => [$fact]], $token))['results'][0];

        return [$result['status'], $result['code']];
    }

    /** @return array{0: string, 1: int} une vraie pièce de cette variante avec sa puce NFC : l'identifiant de la puce, la pièce */
    private function tagged(int $variant, string $suffix): array
    {
        $nfc = '04' . $this->s . 'BB00' . $suffix;
        $this->json($this->admin('POST', self::BASE . '/puces/associer', ['variantId' => $variant, 'identifier' => $nfc, 'technology' => 'nfc']));

        return [$nfc, (int) $this->db->fetchOne('SELECT piece_id FROM loss_tag WHERE identifier = ?', [$nfc])];
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

    private function article(string $name): int
    {
        $slug = 'l4a-' . bin2hex(random_bytes(4));
        $this->db->executeStatement("INSERT INTO product (id, name, price, isbestseller, quantity, created_at, slug, product_type, is_web, is_pos) VALUES (nextval('product_id_seq'), :n, 3000, false, 0, NOW(), :s, 'standard', false, true)",
            ['n' => "$name L4A-$this->s", 's' => $slug]);
        $product = (int) $this->db->fetchOne('SELECT id FROM product WHERE slug = ?', [$slug]);

        return (int) $this->db->fetchOne("INSERT INTO product_variant (id, product_id, stock_quantity) VALUES (nextval('product_variant_id_seq'), ?, 5) RETURNING id", [$product]);
    }
}
