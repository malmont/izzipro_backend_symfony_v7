<?php

namespace App\Tests\Functional\LossPrevention;

use App\LossPrevention\Entity\LossBox;
use App\LossPrevention\Entity\LossPiece;
use App\Services\CaisseService\StoreClock;
use Doctrine\DBAL\ArrayParameterType;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lot L4-b2 (16/11/2026, `docs/prevention-pertes.md` § 17.14) : une commande web payée, retirée en boutique ou expédiée, et une location
 * sont des cas normaux, qui n'ouvrent pas de « sortie sans vente ». Les commandes et les réservations sont lues seulement.
 */
class LossPreventionPickupRentalTest extends LossPreventionTestCase
{
    private const BOXES = self::BASE . '/boitiers';
    private const MACHINE = 'machine-de-retrait-0123456789abcdef';
    /** @var array<string, string> lot L5-e : la machine d'un jeton qui n'est pas celle de `MACHINE` */
    private array $machines = [];

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
        $this->db->executeStatement("DELETE FROM loss_rule WHERE code IN ('EXIT_WITHOUT_SALE', 'EXIT_COVERED')");
        $this->db->executeStatement('UPDATE loss_setting SET counter_window_seconds = NULL, catch_up_seconds = 300, presence_retention_days = NULL, web_window_days = NULL, exit_cursor = NULL');
    }

    protected function tearDown(): void
    {
        try {
            while ($this->db->isTransactionActive()) {
                $this->db->rollBack();
            }
            $this->db->executeStatement('UPDATE loss_setting SET catch_up_seconds = NULL, web_window_days = NULL');
            $this->db->executeStatement('UPDATE loss_setting SET exit_cursor = (SELECT COALESCE(MAX(id), 0) FROM loss_piece_event)');
            // les commandes de l'essai sortent de toute fenêtre et sont annulées : l'analyse d'un essai suivant ne les lit plus
            $this->db->executeStatement('UPDATE "order" SET order_date = \'2000-01-01 00:00:00\', status_id = 7 WHERE reference = ?', ["L4B2-$this->s"]);
            $this->db->executeStatement("UPDATE booking SET status = 'CANCELLED' WHERE order_item_id IN (SELECT i.id FROM order_items i JOIN \"order\" o ON o.id = i.order_associated_id WHERE o.reference = ?)", ["L4B2-$this->s"]);
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

    public function testAPaidWebOrderPickedUpOrShippedExplainsOneExitWithinItsWindowAndCancellingItReleasesThePiece(): void
    {
        $variant = $this->article('Sac', 50);
        [$p1, $p2, $p3] = [$this->taggedSince($variant, '01'), $this->taggedSince($variant, '02'), $this->taggedSince($variant, '03')];
        $pickup = $this->carrier('pickup');
        $a = $this->webOrder($pickup, $variant, 72, true);
        $b = $this->webOrder($pickup, $variant, 48, false); // pas encore payée
        $old = $this->webOrder($pickup, $variant, 20 * 24, true); // hors de la fenêtre de 14 jours
        $cancelled = $this->webOrder($pickup, $variant, 24, true, 7);
        // un colis EXPÉDIÉ (un autre article, une seule pièce) : lu lui aussi ; une vente de CAISSE avec un transporteur, jamais (elle passe par le journal)
        $parcel = $this->article('Ceinture', 50);
        $q1 = $this->taggedSince($parcel, '09');
        $shipped = $this->webOrder($this->carrier('fixed'), $parcel, 24, true);
        $till = $this->webOrder($this->carrier('fixed'), $parcel, 24, true, 3, 2);
        $this->install();
        $this->drain();

        // Les commandes web payées, dans leur fenêtre, sont lues : un article à relier d'origine « web », sans poste ni entrée du journal de caisse
        $units = fn (int $order): array => $this->db->fetchAllAssociative('SELECT * FROM loss_sale_unit WHERE order_id = ? ORDER BY id', [$order]);
        $this->assertSame([[], [], [], []], [$units($b), $units($old), $units($cancelled), $units($till)]);
        $this->assertSame([1, 'web', null], [count($units($shipped)), $units($shipped)[0]['origin'], $units($shipped)[0]['cash_register_id']]);
        // le colis passe le portique (remis au transporteur) : relié à sa commande, aucune sortie sans vente
        $this->exit($q1, -400);
        $this->drain();
        $this->assertSame([$this->piece($q1), 'sold'], [(int) $units($shipped)[0]['piece_id'], $this->status($q1)]);
        $this->assertSame([], $this->findings('EXIT_WITHOUT_SALE', [$this->piece($q1)]));
        $this->assertCount(1, $units($a));
        $this->assertSame(['web', 0, null, null, gmdate('Y-m-d H:i:s', $this->orderTime($a))],
            [$units($a)[0]['origin'], (int) $units($a)[0]['audit_sequence'], $units($a)[0]['cash_register_id'], $units($a)[0]['piece_id'], (string) $units($a)[0]['sold_at']]);

        // La pièce 1 sort : reliée à la commande par le rattrapage (trois jours après la commande, dans la fenêtre) ; pas de sortie sans
        // vente ; l'article n'est pas entièrement étiqueté, le doute est dit (constat faible)
        $this->exit($p1, -400);
        $this->drain();
        $this->assertSame([$this->piece($p1), 'catch_up', 'sold'], [(int) $units($a)[0]['piece_id'], $units($a)[0]['matched_by'], $this->status($p1)]);
        $this->assertSame([], $this->findings('EXIT_WITHOUT_SALE', [$this->piece($p1)]));
        $covered = $this->db->fetchAllAssociative("SELECT * FROM loss_finding WHERE rule_code = 'EXIT_COVERED' AND NOT exercise AND subject_id = ?", [$variant]);
        $this->assertSame([1, 'low'], [count($covered), json_decode((string) $covered[0]['facts'], true)['level']]);

        // La commande B est payée : lue au passage suivant ; la pièce 2 qui sort lui est reliée. La pièce 3, ensuite, n'a plus rien qui
        // l'explique : sortie sans vente
        $this->pay($b, 2, 2);
        $this->drain();
        $this->assertSame(['web', null], [$units($b)[0]['origin'], $units($b)[0]['piece_id']]);
        $this->exit($p2, -390);
        $this->exit($p3, -380);
        $this->drain();
        $this->assertSame([$this->piece($p2), 'sold'], [(int) $units($b)[0]['piece_id'], $this->status($p2)]);
        $this->assertSame([], $this->findings('EXIT_WITHOUT_SALE', [$this->piece($p2)]));
        $this->assertCount(1, $this->findings('EXIT_WITHOUT_SALE', [$this->piece($p3)]));

        // La fenêtre se règle (1 à 60 jours) : à 30 jours, la vieille commande est lue, et la pièce 3 (sortie dans ses 30 jours) lui est reliée
        $put = fn (mixed $value, int $status = 200): array => $this->json($this->admin('PUT', self::BASE . '/reglages', ['pieces' => ['webWindowDays' => $value]]), $status);
        foreach ([0, 61] as $bad) {
            $this->assertSame('PARAM_OUT_OF_RANGE', $put($bad, 422)['fields']['pieces.webWindowDays'] ?? null, (string) $bad);
        }
        $this->assertSame(30, $put(30)['pieces']['webWindowDays']);
        $this->drain();
        $this->assertSame(['web', $this->piece($p3), 'sold'], [$units($old)[0]['origin'], (int) $units($old)[0]['piece_id'], $this->status($p3)]);
        $this->assertSame([[], []], [$units($till), $units($cancelled)]);
        $this->assertSame(14, $put(null)['pieces']['webWindowDays']);

        // Annulée : son article est revenu (par annulation, sans entrée du journal), sa pièce redevient vendable
        $this->db->executeStatement('UPDATE "order" SET status_id = 7 WHERE id = ?', [$a]);
        $this->drain();
        $this->assertSame(['cancellation', 0, 'returned'], [$units($a)[0]['returned_by'], (int) $units($a)[0]['return_sequence'], $this->status($p1)]);
        $event = $this->db->fetchAssociative("SELECT * FROM loss_piece_event WHERE piece_id = ? AND type = 'PIECE_RETURNED' ORDER BY id DESC LIMIT 1", [$this->piece($p1)]);
        $this->assertSame('cancellation', json_decode((string) $event['data'], true)['by']);
        // Un remboursement sans retour de marchandise (geste commercial) ne libère rien ; un retour de la boutique libère sa ligne
        $this->pay($b, 2, 1, -500);
        $this->drain();
        $this->assertSame([null, 'sold'], [$units($b)[0]['returned_by'], $this->status($p2)]);
        $this->db->executeStatement("INSERT INTO order_return (order_id, lines, amount, restock, created_at) VALUES (?, ?, 3000, true, NOW())",
            [$b, json_encode([['orderItemId' => (int) $units($b)[0]['order_item_id'], 'name' => 'Sac', 'quantity' => 1, 'amountCents' => 3000]])]);
        $this->drain();
        $this->assertSame(['return', 0, 'returned'], [$units($b)[0]['returned_by'], (int) $units($b)[0]['return_sequence'], $this->status($p2)]);
        $this->drain();
        $this->assertCount(1, $this->db->fetchAllAssociative("SELECT id FROM loss_piece_event WHERE piece_id = ? AND type = 'PIECE_RETURNED'", [$this->piece($p2)]), 'un retour relu ne revient pas deux fois');
        // Deux exemplaires et une ligne d'un article sans puce : deux articles écrits, pas trois ; deux retours d'UN exemplaire : chacun n'en
        // libère qu'un, jamais deux fois d'un passage à l'autre
        $this->taggedSince($variant, '05'); // une pièce libre : sans elle, l'article n'est plus suivi (toutes ses pièces étaient vendues)
        $two = $this->webOrder($pickup, $variant, 12, true);
        $this->db->executeStatement('UPDATE order_items SET quantity = 2 WHERE order_associated_id = ?', [$two]);
        $this->item($two, $this->article('Bougie', 10), 1);
        $this->drain();
        $this->assertCount(2, $units($two));
        $returned = fn (): int => (int) $this->db->fetchOne("SELECT COUNT(*) FROM loss_sale_unit WHERE order_id = ? AND returned_by = 'return'", [$two]);
        $giveBack = fn () => $this->db->executeStatement('INSERT INTO order_return (order_id, lines, amount, restock, created_at) VALUES (?, ?, 3000, true, NOW())',
            [$two, json_encode([['orderItemId' => (int) $units($two)[0]['order_item_id'], 'name' => 'Sac', 'quantity' => 1, 'amountCents' => 3000]])]);
        $giveBack();
        $this->drain();
        $this->drain();
        $this->assertSame(1, $returned());
        $giveBack();
        $this->drain();
        $this->assertSame(2, $returned());

        // Ce que la base garde elle-même : un article de retrait n'a ni poste ni entrée du journal ; l'origine ne change pas
        $refused = function (string $what, string $sql, array $params = []): void {
            $this->db->beginTransaction();
            try {
                $this->db->executeStatement($sql, $params);
                $this->fail($what . ' : accepté par la base');
            } catch (\Doctrine\DBAL\Exception $e) {
                $this->addToAssertionCount(1);
            } finally {
                $this->db->rollBack();
            }
        };
        $item = (int) $units($a)[0]['order_item_id'];
        $refused('une commande web avec un poste', "INSERT INTO loss_sale_unit (order_id, order_item_id, unit_no, variant_id, cash_register_id, sold_at, audit_sequence, created_at, origin) VALUES (?, ?, 50, ?, ?, NOW(), 0, NOW(), 'web')",
            [$a, $item, $variant, $this->register]);
        $refused('une commande web avec une entrée du journal', "INSERT INTO loss_sale_unit (order_id, order_item_id, unit_no, variant_id, sold_at, audit_sequence, created_at, origin) VALUES (?, ?, 51, ?, NOW(), 7, NOW(), 'web')",
            [$a, $item, $variant]);
        $refused('une origine inconnue', "INSERT INTO loss_sale_unit (order_id, order_item_id, unit_no, variant_id, sold_at, audit_sequence, created_at, origin) VALUES (?, ?, 52, ?, NOW(), 1, NOW(), 'web')",
            [$a, $item, $variant]);
        $refused('une origine changée', "UPDATE loss_sale_unit SET origin = 'sale' WHERE id = ?", [(int) $units($a)[0]['id']]);

        // La migration est rejouable et son script dit les mêmes instructions
        require_once dirname(__DIR__, 3) . '/migrations/Version20270123100000.php';
        $this->db->beginTransaction();
        foreach (\DoctrineMigrations\Version20270123100000::STATEMENTS as $sql) {
            $this->db->executeStatement($sql);
        }
        $this->assertSame('loss_sale_unit_guard_v2', $this->db->fetchOne("SELECT p.proname FROM pg_trigger t JOIN pg_proc p ON p.oid = t.tgfoid WHERE NOT t.tgisinternal AND t.tgname = 'loss_sale_unit_guard'"));
        $this->db->rollBack();
        $script = (string) @file_get_contents(dirname(__DIR__, 3) . '/scripts/migrate_all_v2_pertes_l4b2.sh');
        if ($script !== '') {
            foreach (\DoctrineMigrations\Version20270123100000::STATEMENTS as $sql) {
                $this->assertStringContainsString($sql . ';', $script);
            }
        }
        $this->assertSame(7, count(\DoctrineMigrations\Version20270123100000::STATEMENTS));
    }

    public function testARentalExplainsAsManyPiecesOnADayAsUnitsRentedFromTheDayBeforeItsStartToTheDayAfterItsEnd(): void
    {
        $clock = static::getContainer()->get(StoreClock::class);
        // la journée du magasin des lectures (il y a 400 secondes) ; les réservations sont posées autour d'elle
        $day = $clock->day(new \DateTimeImmutable('@' . (time() - 400)));
        [$start] = $clock->range($day, $day);
        $at = fn (string $shift): string => $start->modify($shift)->format('Y-m-d H:i:s');
        $variants = [];
        foreach (['deux' => 2, 'hier' => 1, 'avant-hier' => 1, 'demain' => 1, 'apres-demain' => 1, 'web' => 1, 'web-impayee' => 1] as $name => $count) {
            $variants[$name] = $this->article("Vélo $name", 20);
        }
        // deux unités louées aujourd'hui (et une réservation annulée de cinq, qui ne compte pas)
        $this->rental($variants['deux'], $at('+2 hours'), $at('+30 hours'), 2, 'CONFIRMED');
        $this->rental($variants['deux'], $at('+2 hours'), $at('+30 hours'), 5, 'CANCELLED');
        // finie hier (rendue) : couvre aujourd'hui ; finie avant-hier : non
        $this->rental($variants['hier'], $at('-36 hours'), $at('-12 hours'), 1, 'RETURNED');
        $this->rental($variants['avant-hier'], $at('-60 hours'), $at('-36 hours'), 1, 'CONFIRMED');
        // commence demain : couvre aujourd'hui (la veille) ; après-demain : non
        $this->rental($variants['demain'], $at('+36 hours'), $at('+60 hours'), 1, 'CONFIRMED');
        $this->rental($variants['apres-demain'], $at('+60 hours'), $at('+84 hours'), 1, 'CONFIRMED');
        // réservée en ligne : la boutique laisse `PENDING_PAYMENT` après le paiement ; payée, elle compte ; pas payée, non
        $this->rental($variants['web'], $at('+2 hours'), $at('+30 hours'), 1, 'PENDING_PAYMENT', true);
        $this->rental($variants['web-impayee'], $at('+2 hours'), $at('+30 hours'), 1, 'PENDING_PAYMENT', false, true);
        $w = [$this->taggedSince($variants['deux'], '11'), $this->taggedSince($variants['deux'], '12'), $this->taggedSince($variants['deux'], '13')];
        $one = [];
        foreach (['hier' => '21', 'avant-hier' => '22', 'demain' => '23', 'apres-demain' => '24', 'web' => '25', 'web-impayee' => '26'] as $name => $suffix) {
            $one[$name] = $this->taggedSince($variants[$name], $suffix);
        }
        $this->install();
        $this->drain();

        // Deux pièces distinctes sortent (l'une deux fois : l'aller et le retour comptent une fois) : expliquées ; la troisième, non.
        // Toutes les lectures à la même heure (dans l'ordre de leur rang) : jamais à cheval sur minuit
        $this->exit($w[0], -400);
        $this->exit($w[0], -400);
        $this->exit($w[1], -400);
        $this->exit($w[2], -400);
        foreach ($one as $identifier) {
            $this->exit($identifier, -400);
        }
        $this->exit($one['hier'], -400); // l'aller et le retour d'une pièce louée à une unité
        $this->drain();
        $opened = fn (array $identifiers): array => array_map(fn (array $f): int => (int) $f['subject_id'],
            $this->findings('EXIT_WITHOUT_SALE', array_map(fn (string $i): int => $this->piece($i), $identifiers)));
        $this->assertSame([$this->piece($w[2])], $opened($w));
        $this->assertSame([$this->piece($one['avant-hier']), $this->piece($one['apres-demain']), $this->piece($one['web-impayee'])], $opened(array_values($one)));
        // une location ne relie aucune pièce et n'ouvre pas de doute : la pièce reste en service
        $this->assertSame(['created', 'created'], [$this->status($w[0]), $this->status($one['hier'])]);
        $this->assertSame(0, (int) $this->db->fetchOne("SELECT COUNT(*) FROM loss_finding WHERE rule_code = 'EXIT_COVERED' AND NOT exercise AND subject_id IN (?)",
            [array_values($variants)], [ArrayParameterType::INTEGER]));
        $this->assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM loss_sale_unit WHERE variant_id IN (?)', [array_values($variants)], [ArrayParameterType::INTEGER]));
    }

    /** @param list<int> $pieces */
    private function findings(string $rule, array $pieces): array
    {
        return $this->db->fetchAllAssociative('SELECT * FROM loss_finding WHERE rule_code = ? AND NOT exercise AND subject_id IN (?) ORDER BY subject_id', [$rule, $pieces], [1 => ArrayParameterType::INTEGER]);
    }

    private function carrier(string $kind): int
    {
        return (int) $this->db->fetchOne("INSERT INTO carrier (id, name, description, price, created_at, kind) VALUES (nextval('carrier_id_seq'), ?, 'L4B2-TEST', 0, NOW(), ?) RETURNING id",
            ["Transporteur $kind L4B2-$this->s", $kind]);
    }

    /** Une commande d'un article, passée il y a `$hours` heures, à ce transporteur ; payée (paiement « Complété ») ou non ; canal 1 (boutique en ligne) ou 2 (caisse) */
    private function webOrder(int $carrier, int $variant, int $hours, bool $paid, int $status = 3, int $source = 1): int
    {
        $order = (int) $this->db->fetchOne(
            "INSERT INTO \"order\" (id, reference, order_date, order_type_id, status_id, order_source_id, user_id_id, total_amount, gift_card_applied, carrier_id, shipping_cost) VALUES (nextval('order_id_seq'), ?, ?, 1, ?, ?, ?, 3000, 0, ?, 0) RETURNING id",
            ["L4B2-$this->s", gmdate('Y-m-d H:i:s', time() - 3600 * $hours), $status, $source, $this->adminId, $carrier]);
        $this->item($order, $variant, 1);
        $this->pay($order, $paid ? 2 : 1, 2);

        return $order;
    }

    private function item(int $order, int $variant, int $quantity): int
    {
        return (int) $this->db->fetchOne("INSERT INTO order_items (id, order_associated_id, product_variant_id, quantity, unit_price, total_price, discount_amount, taxable) VALUES (nextval('order_items_id_seq'), ?, ?, ?, 3000, 3000, 0, true) RETURNING id",
            [$order, $variant, $quantity]);
    }

    /** Un paiement de la commande : état (1 en cours, 2 complété), type (1 remboursement, 2 paiement) */
    private function pay(int $order, int $status, int $type, int $amount = 3000): void
    {
        $this->db->executeStatement("INSERT INTO payments (id, order_payment_id, statut_payment_id, payment_type_id, amount, payment_date) VALUES (nextval('payments_id_seq'), ?, ?, ?, ?, NOW())",
            [$order, $status, $type, $amount]);
    }

    /** Une location d'un article (sa ligne de commande porte la variante) ; sa commande payée en ligne si `$paid` */
    private function rental(int $variant, string $startAt, string $endAt, int $quantity, string $status, bool $paid = false, bool $pending = false): void
    {
        $order = (int) $this->db->fetchOne(
            "INSERT INTO \"order\" (id, reference, order_date, order_type_id, status_id, order_source_id, user_id_id, total_amount, gift_card_applied) VALUES (nextval('order_id_seq'), ?, NOW(), 1, 3, 2, ?, 3000, 0) RETURNING id",
            ["L4B2-$this->s", $this->adminId]);
        if ($paid || $pending) {
            $this->pay($order, $paid ? 2 : 1, 2); // payée (« Complété »), ou un paiement encore « En cours »
        }
        $item = $this->item($order, $variant, $quantity);
        $this->db->executeStatement("INSERT INTO booking (id, product_id, order_item_id, start_at, end_at, quantity, status, is_finalized, created_at) VALUES (nextval('booking_id_seq'), (SELECT product_id FROM product_variant WHERE id = ?), ?, ?, ?, ?, ?, false, NOW())",
            [$variant, $item, $startAt, $endAt, $quantity, $status]);
    }

    private function orderTime(int $order): int
    {
        return (new \DateTimeImmutable((string) $this->db->fetchOne('SELECT order_date FROM "order" WHERE id = ?', [$order]), new \DateTimeZone('UTC')))->getTimestamp();
    }

    private function install(): void
    {
        $created = $this->json($this->admin('POST', self::BOXES, ['name' => "Retrait $this->s"]), 201);
        $enrolment = $this->json($this->channel('POST', '/inscription', ['key' => $created['key'], 'machine' => self::MACHINE, 'version' => '1.0.0', 'arch' => 'amd64'], null));
        $this->token = $enrolment['token'];
        $settings = $this->json($this->channel('POST', '/signe-de-vie', ['now' => gmdate('Y-m-d\TH:i:s\Z'), 'machine' => self::MACHINE, 'lastServerTime' => $enrolment['serverTime'],
            'devices' => [['serial' => "RETRAIT-$this->s", 'kind' => 'reader', 'status' => 'ok']]], $this->token))['settings'];
        $reader = array_column($settings['devices'], 'id', 'serial')["RETRAIT-$this->s"];
        $this->json($this->admin('PUT', self::BOXES . '/' . $created['box']['id'] . "/appareils/$reader", ['zone' => 'exit']));
    }

    /** Une arrivée de la puce devant l'antenne de la sortie, décalée de `$shift` secondes */
    private function exit(string $identifier, int $shift): void
    {
        $fact = ['key' => 'retrait-' . $this->s . '-' . str_pad((string) ++$this->n, 4, '0', STR_PAD_LEFT), 'type' => 'ZONE_SEEN', 'at' => gmdate('Y-m-d\TH:i:s\Z', time() + $shift),
            'device' => "RETRAIT-$this->s", 'zone' => 'exit', 'data' => ['identifier' => $identifier]];
        $this->assertSame('accepted', $this->json($this->channel('POST', '/faits', ['facts' => [$fact]], $this->token))['results'][0]['status']);
    }

    private function piece(string $identifier): int
    {
        return (int) $this->db->fetchOne('SELECT piece_id FROM loss_tag WHERE identifier = ?', [$identifier]);
    }

    private function status(string $identifier): string
    {
        return (string) $this->db->fetchOne('SELECT p.status FROM loss_piece p JOIN loss_tag t ON t.piece_id = p.id WHERE t.identifier = ?', [$identifier]);
    }

    /** Une vraie pièce et sa puce NFC, qui existent depuis 30 jours (les commandes, reculées dans le passé, les trouvent suivies) */
    private function taggedSince(int $variant, string $suffix): string
    {
        $nfc = '04' . $this->s . 'DD00' . $suffix;
        $at = gmdate('Y-m-d H:i:s', time() - 3600 * 24 * 30);
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
            'HTTP_X_LOSS_BOX_MACHINE' => $token !== null ? ($this->machines[$token] ?? self::MACHINE) : null, // lot L5-e : la preuve de la machine
        ]) + ['REMOTE_ADDR' => $this->ip], $body !== null ? json_encode($body) : null);

        return $this->client->getResponse();
    }

    /** Un article d'une variante, avec ce stock ; rend la variante */
    private function article(string $name, int $stock): int
    {
        $slug = 'l4b2-' . bin2hex(random_bytes(4));
        $this->db->executeStatement("INSERT INTO product (id, name, price, isbestseller, quantity, created_at, slug, product_type, is_web, is_pos) VALUES (nextval('product_id_seq'), :n, 3000, false, 0, NOW(), :s, 'standard', true, true)",
            ['n' => "$name L4B2-$this->s", 's' => $slug]);
        $product = (int) $this->db->fetchOne('SELECT id FROM product WHERE slug = ?', [$slug]);

        return (int) $this->db->fetchOne("INSERT INTO product_variant (id, product_id, stock_quantity) VALUES (nextval('product_variant_id_seq'), ?, ?) RETURNING id", [$product, $stock]);
    }
}
