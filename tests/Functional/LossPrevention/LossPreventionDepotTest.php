<?php

namespace App\Tests\Functional\LossPrevention;

use App\LossPrevention\Entity\LossBox;
use App\LossPrevention\Entity\LossPiece;
use App\LossPrevention\Services\LossDepotService;
use App\Services\CaisseService\StoreClock;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lot L4-c (16/11/2026, `docs/prevention-pertes.md` § 17.15) : les heures d'ouverture et les trois constats du dépôt. Par jour et par
 * zone, jamais par personne ; jamais trop tôt ; sans heures confirmées, rien « hors des heures ».
 */
class LossPreventionDepotTest extends LossPreventionTestCase
{
    private const BOXES = self::BASE . '/boitiers';
    private const MACHINE = 'machine-du-depot-0123456789abcdef';
    private const RULES = "('STOCKROOM_NOT_SHELVED', 'BACK_DOOR', 'OUT_OF_HOURS')";

    private string $s;
    private string $ip;
    private int $n = 0;
    private string $token = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->s = strtoupper(bin2hex(random_bytes(3)));
        $this->ip = '10.' . random_int(1, 250) . '.' . random_int(1, 250) . '.' . random_int(1, 250);
        $this->flags(true, true);
        $this->db->executeStatement('DELETE FROM loss_rule WHERE code IN ' . self::RULES);
        $this->db->executeStatement('UPDATE loss_setting SET catch_up_seconds = 300, opening_hours = NULL, hours_margin_minutes = NULL, depot_cursor = NULL, back_door_cursor = NULL, exit_cursor = NULL');
    }

    protected function tearDown(): void
    {
        try {
            while ($this->db->isTransactionActive()) {
                $this->db->rollBack();
            }
            $this->db->executeStatement('UPDATE loss_setting SET catch_up_seconds = NULL, opening_hours = NULL, hours_margin_minutes = NULL');
            // la commande web de l'essai sort de toute fenêtre et est annulée : l'analyse d'un essai suivant ne la lit plus
            $this->db->executeStatement('UPDATE "order" SET order_date = \'2000-01-01 00:00:00\', status_id = 7 WHERE reference = ?', ["L4C-$this->s"]);
            // aucun mouvement de cet essai n'attend d'être jugé par l'analyse d'un essai suivant
            $this->db->executeStatement('UPDATE loss_setting SET exit_cursor = (SELECT COALESCE(MAX(id), 0) FROM loss_piece_event), depot_cursor = (SELECT COALESCE(MAX(id), 0) FROM loss_piece_event),'
                . ' back_door_cursor = (SELECT COALESCE(MAX(id), 0) FROM loss_piece_event)');
            $this->db->executeStatement('DELETE FROM loss_rule WHERE code IN ' . self::RULES);
            $this->db->executeStatement("UPDATE loss_box SET status = 'revoked', revoked_at = NOW() WHERE NOT exercise AND status <> 'revoked'");
            $this->db->executeStatement("UPDATE loss_device SET status = 'retired', cash_register_id = NULL WHERE NOT exercise AND status <> 'retired'");
            $this->db->beginTransaction();
            $this->provider->getEntityManager()->getRepository(LossPiece::class)->deleteExercise();
            $this->provider->getEntityManager()->getRepository(LossBox::class)->deleteExercise();
            $this->db->commit();
        } finally {
            parent::tearDown();
        }
    }

    public function testAPieceOutOfTheStockroomNotSeenOnTheFloorOpensOneFindingPerDayAndZoneAndOutOfHoursWaitsForConfirmedHours(): void
    {
        $variant = $this->article('Pull', 40);
        $other = $this->article('Gilet', 40);
        $p = [];
        foreach (range(1, 11) as $i) {
            $p[$i] = $this->tagged($i === 8 ? $other : $variant, sprintf('%02d', $i));
        }
        $this->install(['PORTE' => 'stockroom_door', 'RAYON' => 'floor', 'RESERVE' => 'stockroom']);
        $this->drain(); // premier passage : les curseurs se posent, rien d'avant n'est jugé
        $this->assertNotNull($this->db->fetchOne('SELECT depot_cursor FROM loss_setting'));
        $this->json($this->admin('PUT', self::BASE . '/regles/STOCKROOM_NOT_SHELVED', ['params' => ['minutes' => 5, 'minCount' => 2]]));
        // tout se passe HIER à midi (heure du magasin) : jamais à cheval sur minuit
        [$base, $day] = $this->yesterdayNoon();

        // Pièce 1 : sortie de la réserve, vue en rayon ; pièce 3 : revue DANS la réserve (une antenne ne dit pas le sens) ; pièce 7 : encore
        // présente en rayon (l'antenne du rayon ne redit pas une pièce qu'elle voit) ; pièce 8 : une vente de son article attend sa pièce ;
        // pièce 2 : plus rien (sa relecture à la porte est une arrivée redite, sans objet). Pièces 5 et 6 : sorties il y a un quart d'heure
        // (aujourd'hui), et le boîtier n'a encore rien envoyé d'après la fin du délai de la 5 (il rattrape peut-être son retard) : pas encore
        // mûre, le curseur s'arrête devant elle
        $this->move($p[7], 'RAYON', $base - 600);
        $this->move($p[1], 'PORTE', $base);
        $this->move($p[1], 'RAYON', $base + 100);
        $this->move($p[2], 'PORTE', $base);
        $this->move($p[3], 'PORTE', $base);
        $this->move($p[3], 'RESERVE', $base + 50);
        $this->move($p[7], 'PORTE', $base);
        $this->db->executeStatement('INSERT INTO loss_sale_unit (order_id, order_item_id, unit_no, variant_id, sold_at, audit_sequence, created_at) VALUES (?, ?, 1, ?, ?, 1, NOW())',
            [900000000 + random_int(1, 99999999), 900000000 + random_int(1, 99999999), $other, gmdate('Y-m-d H:i:s', time() + $base + 60)]);
        $this->move($p[8], 'PORTE', $base);
        $this->move($p[2], 'PORTE', $base + 10);
        $clock = static::getContainer()->get(StoreClock::class);
        if (time() - $clock->range($clock->day(new \DateTimeImmutable()), null)[0]->getTimestamp() < 1500) {
            $this->markTestSkipped('trop près de minuit : les pièces 5 et 6 tomberaient sur deux journées');
        }
        $this->move($p[5], 'PORTE', -900);
        $this->move($p[6], 'PORTE', -800);
        $this->drain();
        $finding = fn (string $rule): array => $this->db->fetchAllAssociative('SELECT * FROM loss_finding WHERE rule_code = ? AND NOT exercise AND store_day = ?', [$rule, $day]);
        $shelf = $finding('STOCKROOM_NOT_SHELVED');
        $this->assertCount(1, $shelf);
        $facts = json_decode((string) $shelf[0]['facts'], true);
        $this->assertSame(['zone', 0, 'stockroom_door', 1, [$this->piece($p[2])], 'low', []],
            [$shelf[0]['subject_type'], (int) $shelf[0]['subject_id'], $facts['zone'], $facts['count'], $facts['pieces'], $facts['level'], json_decode((string) $shelf[0]['on_duty'], true)]);
        $this->assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM loss_evidence WHERE finding_id = ?', [(int) $shelf[0]['id']]), 'l\'arrivée redite n\'est pas une preuve');
        $this->assertTrue(json_decode((string) $this->db->fetchOne("SELECT data FROM loss_piece_event WHERE piece_id = ? AND type = 'ZONE_SEEN' ORDER BY id DESC LIMIT 1", [$this->piece($p[2])]), true)['repeat']);
        $today = fn (): array => $this->db->fetchAllAssociative("SELECT * FROM loss_finding WHERE rule_code = 'STOCKROOM_NOT_SHELVED' AND NOT exercise AND store_day = ?",
            [$clock->day(new \DateTimeImmutable())]);
        $this->assertSame([], $today(), 'pas encore mûres : rien de jugé');
        // un fait récent du boîtier arrive : il a tout envoyé jusque-là ; la pièce 5 mûrit, la pièce 6 derrière elle aussi
        $this->move($p[10], 'RESERVE', -30);
        $this->drain();
        $facts = json_decode((string) $today()[0]['facts'], true);
        $this->assertSame([2, 'medium'], [$facts['count'], $facts['level']], 'seuil de 2 atteint');
        $detail = $this->json($this->admin('GET', self::BASE . '/constats/' . $shelf[0]['id']));
        $this->assertSame(['zone', 'Porte de la réserve', null], [$detail['subject']['type'], $detail['subject']['name'], $detail['onDutyNotice']]);
        $this->assertStringContainsString('Porte de la réserve', $detail['summary']);
        $this->assertContains('Arrivée', array_column($detail['evidence'][0]['lines'], 'value'));
        // une antenne du rayon en panne : la règle se tait (sans elle, toute sortie de la réserve serait un faux constat)
        $this->db->executeStatement("UPDATE loss_device SET status = 'down' WHERE serial = ?", ["RAYON-$this->s"]);
        $this->move($p[11], 'PORTE', $base + 2400);
        $this->drain();
        $this->assertSame(1, json_decode((string) $finding('STOCKROOM_NOT_SHELVED')[0]['facts'], true)['count']);
        $this->db->executeStatement("UPDATE loss_device SET status = 'ok' WHERE serial = ?", ["RAYON-$this->s"]);

        // Hors des heures : sans heures confirmées, rien ; tout fermé, un mouvement ouvre un constat par zone
        $this->assertSame([], $finding('OUT_OF_HOURS'));
        $settings = $this->json($this->admin('GET', self::BASE . '/reglages'))['openingHours'];
        $this->assertSame([false, null, 60], [$settings['confirmed'], $settings['days'], $settings['marginMinutes']]);
        $put = fn (mixed $hours, int $status = 200): array => $this->json($this->admin('PUT', self::BASE . '/reglages', ['openingHours' => $hours]), $status);
        $closed = array_fill_keys(['1', '2', '3', '4', '5', '6', '7'], null);
        $this->assertSame('REQUIRED', $put(['days' => array_slice($closed, 0, 6, true)], 422)['fields']['openingHours.days.7']);
        $this->assertSame('INVALID_DAY', $put(['days' => $closed + ['8' => null]], 422)['fields']['openingHours.days']);
        $this->assertSame('INVALID_RANGE', $put(['days' => ['1' => ['open' => '19:00', 'close' => '09:00']] + $closed], 422)['fields']['openingHours.days.1']);
        $this->assertSame('INVALID_RANGE', $put(['days' => ['2' => ['open' => '09:00', 'close' => '25:00']] + $closed], 422)['fields']['openingHours.days.2']);
        $this->assertSame('PARAM_OUT_OF_RANGE', $put(['marginMinutes' => 241], 422)['fields']['openingHours.marginMinutes']);
        $this->assertSame([true, null], [($h = $put(['days' => $closed, 'marginMinutes' => 0])['openingHours'])['confirmed'], $h['days']['3']]);
        $this->move($p[4], 'RAYON', $base + 3000);
        $this->move($p[4], 'RAYON', $base + 3010); // redite : sans objet
        $this->drain();
        $out = $finding('OUT_OF_HOURS');
        $this->assertSame([1, 'floor', 1, 'low'], [count($out), ($f = json_decode((string) $out[0]['facts'], true))['zone'], $f['count'], $f['level']]);
        // ouvert toute la journée : plus rien
        $all = array_fill_keys(['1', '2', '3', '4', '5', '6', '7'], ['open' => '00:00', 'close' => '24:00']);
        $this->assertSame(['00:00', '24:00'], array_values($put(['days' => $all])['openingHours']['days']['5']));
        $this->move($p[3], 'RAYON', $base + 3100);
        $this->drain();
        $this->assertSame(1, json_decode((string) $finding('OUT_OF_HOURS')[0]['facts'], true)['count']);
        $this->assertSame([false, null], [($h = $put(null)['openingHours'])['confirmed'], $h['days']]);

        // L'assistant : l'étape facultative des heures d'ouverture
        $step = fn (): array => array_values(array_filter($this->json($this->admin('GET', self::BASE . '/installation'))['optionalSteps'], static fn (array $s): bool => $s['code'] === 'opening_hours'))[0];
        $this->assertSame('todo', $step()['status']);
        $put(['days' => $all]);
        $this->assertSame('done', $step()['status']);

        // Le catalogue : trois règles de la vague 4, sans aperçu (elles lisent les antennes)
        $rules = array_column($this->json($this->admin('GET', self::BASE . '/regles'))['rules'], null, 'code');
        $this->assertSame([4, 4, 4], [$rules['STOCKROOM_NOT_SHELVED']['wave'], $rules['BACK_DOOR']['wave'], $rules['OUT_OF_HOURS']['wave']]);
        $this->assertSame('RULE_PREVIEW_UNAVAILABLE', $this->json($this->admin('POST', self::BASE . '/regles/apercu', ['code' => 'BACK_DOOR', 'params' => ['minCount' => 3]]), 409)['error']);
    }

    public function testTheBackDoorIsJudgedAtTheEndOfTheDayAndASameDayRetirementOrAShippedParcelExplainsIt(): void
    {
        $variant = $this->article('Veste', 40);
        $parcel = $this->article('Écharpe', 40);
        $p = [];
        foreach (range(1, 6) as $i) {
            $p[$i] = $this->tagged($variant, sprintf('%02d', 10 + $i));
        }
        $b1 = $this->tagged($parcel, '21');
        $this->webOrder($parcel, 48); // un colis expédié, payé il y a deux jours
        $this->install(['ARRIERE' => 'back_door', 'PORTE' => 'stockroom_door', 'RAYON' => 'floor']);
        $this->drain();
        $this->json($this->admin('PUT', self::BASE . '/regles/BACK_DOOR', ['params' => ['minCount' => 3]]));
        $clock = static::getContainer()->get(StoreClock::class);
        [$todayStart] = $clock->range($clock->day(new \DateTimeImmutable()), null);
        $evening = $todayStart->getTimestamp() - 6 * 3600 - time(); // hier, 18 h
        $yesterday = $clock->day(new \DateTimeImmutable('@' . (time() + $evening)));
        // Hier : pièce 1 ; pièce 3, retirée LE JOUR MÊME ; pièce 6, retirée un AUTRE jour
        $this->move($p[1], 'ARRIERE', $evening);
        $this->move($p[3], 'ARRIERE', $evening + 60);
        $this->db->executeStatement("UPDATE loss_piece SET status = 'retired', retired_reason = 'lost', retired_at = ? WHERE id = ?", [gmdate('Y-m-d H:i:s', time() + $evening + 1800), $this->piece($p[3])]);
        $this->move($p[6], 'ARRIERE', $evening + 90);
        $this->db->executeStatement("UPDATE loss_piece SET status = 'retired', retired_reason = 'lost', retired_at = NOW() WHERE id = ?", [$this->piece($p[6])]);
        $this->drain();
        $back = $this->db->fetchAllAssociative("SELECT * FROM loss_finding WHERE rule_code = 'BACK_DOOR' AND NOT exercise AND store_day = ?", [$yesterday]);
        $this->assertCount(1, $back);
        $facts = json_decode((string) $back[0]['facts'], true);
        $this->assertSame(['back_door', [$this->piece($p[1]), $this->piece($p[6])], 'medium'], [$facts['zone'], $facts['pieces'], $facts['level']],
            'un retrait du même jour explique, un retrait d\'un autre jour non');
        // Puis la pièce 2 et un colis web expédié, hier aussi : la pièce compte (seuil de 3 : haute), le colis est expliqué par sa vente
        $this->move($p[2], 'ARRIERE', $evening + 120);
        $this->move($b1, 'ARRIERE', $evening + 150);
        $this->drain();
        $facts = json_decode((string) $this->db->fetchOne('SELECT facts FROM loss_finding WHERE id = ?', [(int) $back[0]['id']]), true);
        $this->assertSame([[$this->piece($p[1]), $this->piece($p[6]), $this->piece($p[2])], 'high'], [$facts['pieces'], $facts['level']]);
        $this->assertSame('sold', $this->status($b1), 'le colis remis au transporteur par la porte arrière est relié à sa commande web');
        $covered = $this->db->fetchAllAssociative("SELECT facts FROM loss_finding WHERE rule_code = 'EXIT_COVERED' AND NOT exercise AND subject_id = ?", [$parcel]);
        $this->assertSame('low', json_decode((string) $covered[0]['facts'], true)['level'], 'article pas entièrement étiqueté : le doute est dit');
        // Aujourd'hui (à la première seconde) : la pièce 4, pas encore mûre (sa journée n'est pas finie), arrête son curseur ; la pièce 5 lue
        // derrière elle, hier, attend aussi. Derrière elles, une sortie de la réserve : son propre curseur ne les attend pas
        $this->move($p[4], 'ARRIERE', $todayStart->getTimestamp() + 1 - time());
        $this->move($p[5], 'ARRIERE', $evening + 200);
        $this->move($p[5], 'PORTE', $evening + 210);
        $this->drain();
        $facts = json_decode((string) $this->db->fetchOne('SELECT facts FROM loss_finding WHERE id = ?', [(int) $back[0]['id']]), true);
        $this->assertSame(3, $facts['count'], 'rien de jugé derrière la lecture d\'aujourd\'hui');
        $this->assertSame([], $this->db->fetchAllAssociative("SELECT id FROM loss_finding WHERE rule_code = 'BACK_DOOR' AND NOT exercise AND store_day = ?", [$clock->day(new \DateTimeImmutable())]));
        $this->assertCount(1, $this->db->fetchAllAssociative("SELECT id FROM loss_finding WHERE rule_code = 'STOCKROOM_NOT_SHELVED' AND NOT exercise AND (facts)::jsonb -> 'pieces' @> CAST(? AS jsonb)",
            [json_encode([$this->piece($p[5])])]));

        // La zone se règle sur une antenne ; une zone inconnue est refusée
        $box = (int) $this->db->fetchOne("SELECT box_id FROM loss_device WHERE serial = ?", ["ARRIERE-$this->s"]);
        $reader = (int) $this->db->fetchOne("SELECT id FROM loss_device WHERE serial = ?", ["RAYON-$this->s"]);
        $this->json($this->admin('PUT', self::BOXES . "/$box/appareils/$reader", ['zone' => 'jardin']), 422);
        $this->json($this->admin('PUT', self::BOXES . "/$box/appareils/$reader", ['zone' => 'back_door']));
        $refused = function (string $sql, array $params): void {
            $this->db->beginTransaction();
            try {
                $this->db->executeStatement($sql, $params);
                $this->fail('accepté par la base');
            } catch (\Doctrine\DBAL\Exception $e) {
                $this->addToAssertionCount(1);
            } finally {
                $this->db->rollBack();
            }
        };
        $refused("UPDATE loss_device SET zone = 'jardin' WHERE id = ?", [$reader]);

        // Les heures : une plage qui déborde sur le lendemain, marge comprise ; un jour fermé
        $tz = new \DateTimeZone('UTC');
        $hours = ['days' => [1 => ['open' => 540, 'close' => 1410], 2 => ['open' => 540, 'close' => 1410], 3 => null, 4 => null, 5 => null, 6 => null, 7 => null], 'margin' => 60];
        $at = fn (string $when): bool => LossDepotService::open($hours, new \DateTimeImmutable($when, $tz), $tz);
        $this->assertSame([true, false, false, true, true, true, false, false],
            [$at('2027-01-05 00:20'), $at('2027-01-05 00:40'), $at('2027-01-04 07:59'), $at('2027-01-04 08:00'), $at('2027-01-04 23:59'), $at('2027-01-06 00:29'), $at('2027-01-06 00:31'), $at('2027-01-04 00:20')],
            'lundi 4 et mardi 5 de 9 h à 23 h 30, une heure de marge ; dimanche 3 fermé');

        // La migration est rejouable et son script dit les mêmes instructions
        require_once dirname(__DIR__, 3) . '/migrations/Version20270124100000.php';
        $this->db->beginTransaction();
        foreach (\DoctrineMigrations\Version20270124100000::STATEMENTS as $sql) {
            $this->db->executeStatement($sql);
        }
        $this->assertSame('loss_device_guard_v2', $this->db->fetchOne("SELECT p.proname FROM pg_trigger t JOIN pg_proc p ON p.oid = t.tgfoid WHERE NOT t.tgisinternal AND t.tgname = 'loss_device_guard'"));
        $this->db->rollBack();
        $script = (string) @file_get_contents(dirname(__DIR__, 3) . '/scripts/migrate_all_v2_pertes_l4c.sh');
        if ($script !== '') {
            foreach (\DoctrineMigrations\Version20270124100000::STATEMENTS as $sql) {
                $this->assertStringContainsString($sql . ';', $script);
            }
        }
        $this->assertSame(9, count(\DoctrineMigrations\Version20270124100000::STATEMENTS));
    }

    /** @param array<string, string> $antennas numéro de série (sans suffixe) => zone */
    private function install(array $antennas): void
    {
        $created = $this->json($this->admin('POST', self::BOXES, ['name' => "Dépôt $this->s"]), 201);
        $enrolment = $this->json($this->channel('POST', '/inscription', ['key' => $created['key'], 'machine' => self::MACHINE, 'version' => '1.0.0', 'arch' => 'amd64'], null));
        $this->token = $enrolment['token'];
        $devices = array_map(fn (string $serial): array => ['serial' => "$serial-$this->s", 'kind' => 'reader', 'status' => 'ok'], array_keys($antennas));
        $settings = $this->json($this->channel('POST', '/signe-de-vie', ['now' => gmdate('Y-m-d\TH:i:s\Z'), 'machine' => self::MACHINE, 'lastServerTime' => $enrolment['serverTime'],
            'devices' => $devices], $this->token))['settings'];
        $ids = array_column($settings['devices'], 'id', 'serial');
        foreach ($antennas as $serial => $zone) {
            $this->json($this->admin('PUT', self::BOXES . '/' . $created['box']['id'] . '/appareils/' . $ids["$serial-$this->s"], ['zone' => $zone]));
        }
        $this->antennas = $antennas;
    }

    /** @var array<string, string> */
    private array $antennas = [];

    /** Une arrivée de la puce devant une antenne (sa zone), décalée de `$shift` secondes */
    private function move(string $identifier, string $antenna, int $shift): void
    {
        $fact = ['key' => 'depot-' . $this->s . '-' . str_pad((string) ++$this->n, 4, '0', STR_PAD_LEFT), 'type' => 'ZONE_SEEN', 'at' => gmdate('Y-m-d\TH:i:s\Z', time() + $shift),
            'device' => "$antenna-$this->s", 'zone' => $this->antennas[$antenna], 'data' => ['identifier' => $identifier]];
        $result = $this->json($this->channel('POST', '/faits', ['facts' => [$fact]], $this->token))['results'][0];
        $this->assertSame('accepted', $result['status'], json_encode($result));
    }

    /** @return array{0: int, 1: string} le décalage (secondes, depuis maintenant) d'hier midi à l'heure du magasin, et cette journée */
    private function yesterdayNoon(): array
    {
        $clock = static::getContainer()->get(StoreClock::class);
        $yesterday = (new \DateTimeImmutable($clock->day(new \DateTimeImmutable())))->modify('-1 day')->format('Y-m-d');
        [$start] = $clock->range($yesterday, null);

        return [$start->getTimestamp() + 12 * 3600 - time(), $yesterday];
    }

    private function status(string $identifier): string
    {
        return (string) $this->db->fetchOne('SELECT p.status FROM loss_piece p JOIN loss_tag t ON t.piece_id = p.id WHERE t.identifier = ?', [$identifier]);
    }

    /** Une commande web expédiée, d'un article, payée, passée il y a `$hours` heures */
    private function webOrder(int $variant, int $hours): void
    {
        $order = (int) $this->db->fetchOne(
            "INSERT INTO \"order\" (id, reference, order_date, order_type_id, status_id, order_source_id, user_id_id, total_amount, gift_card_applied) VALUES (nextval('order_id_seq'), ?, ?, 1, 3, 1, ?, 3000, 0) RETURNING id",
            ["L4C-$this->s", gmdate('Y-m-d H:i:s', time() - 3600 * $hours), $this->adminId]);
        $this->db->executeStatement("INSERT INTO order_items (id, order_associated_id, product_variant_id, quantity, unit_price, total_price, discount_amount, taxable) VALUES (nextval('order_items_id_seq'), ?, ?, 1, 3000, 3000, 0, true)",
            [$order, $variant]);
        $this->db->executeStatement("INSERT INTO payments (id, order_payment_id, statut_payment_id, payment_type_id, amount, payment_date) VALUES (nextval('payments_id_seq'), ?, 2, 2, 3000, NOW())", [$order]);
    }

    private function piece(string $identifier): int
    {
        return (int) $this->db->fetchOne('SELECT piece_id FROM loss_tag WHERE identifier = ?', [$identifier]);
    }

    /** Une vraie pièce et sa puce NFC, depuis 3 jours */
    private function tagged(int $variant, string $suffix): string
    {
        $nfc = '04' . $this->s . 'EE00' . $suffix;
        $at = gmdate('Y-m-d H:i:s', time() - 3 * 86400);
        $piece = (int) $this->db->fetchOne('INSERT INTO loss_piece (number, variant_id, product_id, status, exercise, created_at, updated_at) VALUES (?, ?,'
            . " (SELECT product_id FROM product_variant WHERE id = ?), 'created', false, ?, ?) RETURNING id", [900000000 + random_int(1, 99999999), $variant, $variant, $at, $at]);
        $this->db->executeStatement("INSERT INTO loss_tag (identifier, raw, technology, status, piece_id, exercise, associated_at, created_at, updated_at) VALUES (?, ?, 'nfc', 'associated', ?, false, ?, ?, ?)", [$nfc, $nfc, $piece, $at, $at, $at]);

        return $nfc;
    }

    private function channel(string $method, string $path, ?array $body, ?string $token): Response
    {
        $this->client->getCookieJar()->clear();
        $this->client->request($method, 'https://' . MV_TEST_TENANT_HOST . '/api/pertes/boitier' . $path, [], [], array_filter([
            'HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_LOSS_BOX' => $token,
        ]) + ['REMOTE_ADDR' => $this->ip], $body !== null ? json_encode($body) : null);

        return $this->client->getResponse();
    }

    /** Un article d'une variante, avec ce stock ; rend la variante */
    private function article(string $name, int $stock): int
    {
        $slug = 'l4c-' . bin2hex(random_bytes(4));
        $this->db->executeStatement("INSERT INTO product (id, name, price, isbestseller, quantity, created_at, slug, product_type, is_web, is_pos) VALUES (nextval('product_id_seq'), :n, 3000, false, 0, NOW(), :s, 'standard', false, true)",
            ['n' => "$name L4C-$this->s", 's' => $slug]);
        $product = (int) $this->db->fetchOne('SELECT id FROM product WHERE slug = ?', [$slug]);

        return (int) $this->db->fetchOne("INSERT INTO product_variant (id, product_id, stock_quantity) VALUES (nextval('product_variant_id_seq'), ?, ?) RETURNING id", [$product, $stock]);
    }
}
