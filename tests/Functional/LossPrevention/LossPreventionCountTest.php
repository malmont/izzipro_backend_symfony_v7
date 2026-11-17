<?php

namespace App\Tests\Functional\LossPrevention;

use App\LossPrevention\Entity\LossBox;
use App\LossPrevention\Entity\LossPiece;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lot L4-d (16/11/2026, `docs/prevention-pertes.md` § 17.16) : le comptage au lecteur à main, la pièce absente à deux comptages de suite,
 * la pièce retrouvée, le comptage oublié qui se ferme sans conclusion, et le bilan du soir. Rien dans le stock ; jamais par personne.
 */
class LossPreventionCountTest extends LossPreventionTestCase
{
    private const BOXES = self::BASE . '/boitiers';
    private const COUNTS = self::BASE . '/comptages';
    private const MACHINE = 'machine-du-comptage-0123456789abcdef';
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
        $this->db->executeStatement("DELETE FROM loss_rule WHERE code = 'PIECE_MISSING'");
        // aucun comptage ouvert laissé par un essai précédent (il ne se ferme qu'en passant par l'état « fermé sans conclusion »)
        $this->db->executeStatement("UPDATE loss_count SET status = 'expired', closed_at = opened_at WHERE status = 'open' AND NOT exercise");
        // les vraies pièces d'un essai précédent sortent du compte : retirées (un comptage n'attend que les pièces en service)
        $this->db->executeStatement("UPDATE loss_piece SET status = 'retired', retired_reason = 'lost', retired_at = NOW() WHERE NOT exercise AND status <> 'retired' AND status <> 'sold'");
    }

    protected function tearDown(): void
    {
        try {
            while ($this->db->isTransactionActive()) {
                $this->db->rollBack();
            }
            $this->db->executeStatement("UPDATE loss_count SET status = 'expired', closed_at = opened_at WHERE status = 'open' AND NOT exercise");
            $this->db->executeStatement("UPDATE loss_piece SET status = 'retired', retired_reason = 'lost', retired_at = NOW() WHERE NOT exercise AND status <> 'retired' AND status <> 'sold'");
            $this->db->executeStatement('UPDATE loss_setting SET exit_cursor = (SELECT COALESCE(MAX(id), 0) FROM loss_piece_event)');
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

    public function testAPieceMissingAtTwoCountsInARowBecomesMissingAndGetsItsStateBackWhenReadAgain(): void
    {
        $variant = $this->article('Chemise', 40);
        $p = [];
        foreach (range(1, 4) as $i) {
            $p[$i] = $this->tagged($variant, sprintf('%02d', $i));
        }
        $this->install();
        $this->drain();

        // Comptage A : tout le magasin ; un seul ouvert à la fois
        $a = $this->json($this->admin('POST', self::COUNTS, ['scope' => 'all']), 201);
        $this->assertSame(['open', 'all', 'Tout le magasin', null], [$a['status'], $a['scope'], $a['scopeLabel'], $a['closedAt']]);
        $this->assertEqualsWithDelta(strtotime($a['openedAt']) + 12 * 3600, strtotime($a['expiresAt']), 2);
        $this->assertSame('COUNT_ALREADY_OPEN', $this->json($this->admin('POST', self::COUNTS, ['scope' => 'floor']), 409)['error']);
        $this->assertSame('INVALID_SCOPE', $this->json($this->admin('POST', self::COUNTS, ['scope' => 'jardin']), 422)['fields']['scope']);
        usleep(1_100_000); // une lecture de la seconde de l'ouverture compte ; on s'en écarte pour ne rien laisser au hasard
        $this->spot($p[1]);
        $this->spot($p[2]);
        $closed = $this->json($this->admin('POST', self::COUNTS . '/' . $a['id'] . '/fermer'));
        $this->assertSame(['closed', 4, 2, 2, 0], [$closed['status'], $closed['expected'], $closed['found'], $closed['absent'], $closed['missing']]);
        $absent = array_column($closed['absentPieces'], 'pieceId');
        sort($absent); // rendues dans l'ordre de leur numéro de pièce (tiré au hasard dans l'essai)
        $this->assertSame([$this->piece($p[3]), $this->piece($p[4])], $absent);
        $this->assertSame([[40, 4, 2]], array_map(static fn (array $g): array => [$g['catalogStock'], $g['expectedPieces'], $g['foundPieces']], $closed['stockGap']), 'l\'écart est lu, le stock ne change pas');
        $this->assertSame(40, (int) $this->db->fetchOne('SELECT stock_quantity FROM product_variant WHERE id = ?', [$variant]));
        $this->assertSame('COUNT_NOT_OPEN', $this->json($this->admin('POST', self::COUNTS . '/' . $a['id'] . '/fermer'), 409)['error']);

        usleep(1_100_000); // les heures sont à la seconde : les lectures de A ne tombent pas dans la fenêtre de B
        // Comptage B : la pièce 4, absente deux fois de suite, est dite absente ; la pièce 2, lue en A, ne l'est pas ; un constat par comptage
        $b = $this->json($this->admin('POST', self::COUNTS, []), 201);
        $this->spot($p[1]);
        $this->spot($p[3]);
        $closed = $this->json($this->admin('POST', self::COUNTS . '/' . $b['id'] . '/fermer'));
        $this->assertSame([2, 1], [$closed['absent'], $closed['missing']]);
        $this->assertSame(['missing', 'created', 'created'], [$this->status($p[4]), $this->status($p[2]), $this->status($p[3])]);
        $finding = $this->db->fetchAssociative("SELECT * FROM loss_finding WHERE rule_code = 'PIECE_MISSING' AND NOT exercise AND subject_id = ?", [(int) $b['id']]);
        $facts = json_decode((string) $finding['facts'], true);
        $this->assertSame(['count', 1, [$this->piece($p[4])], 'medium', []], [$finding['subject_type'], $facts['count'], $facts['pieces'], $facts['level'], json_decode((string) $finding['on_duty'], true)]);
        $detail = $this->json($this->admin('GET', self::BASE . '/constats/' . $finding['id']));
        $this->assertSame(['count', 'Comptage n° ' . $b['id'], null], [$detail['subject']['type'], $detail['subject']['name'], $detail['onDutyNotice']]);

        // Relue (par n'importe quelle lecture) : elle reprend son état, et le constat le dit
        usleep(1_100_000); // relue APRÈS son absence, à la seconde près
        $this->spot($p[4]);
        $this->drain();
        $this->assertSame('created', $this->status($p[4]));
        $facts = json_decode((string) $this->db->fetchOne('SELECT facts FROM loss_finding WHERE id = ?', [(int) $finding['id']]), true);
        $this->assertSame(1, $facts['found']);
        $this->assertSame(['count_absence', 'count_found'], $this->db->fetchFirstColumn('SELECT kind FROM loss_evidence WHERE finding_id = ? ORDER BY id', [(int) $finding['id']]));

        // Une zone : seules les pièces vues en dernier dans cette zone sont attendues (aucune ici)
        $zone = $this->json($this->admin('POST', self::COUNTS, ['scope' => 'floor']), 201);
        $this->assertSame([0, 0], array_values(array_intersect_key($this->json($this->admin('POST', self::COUNTS . '/' . $zone['id'] . '/fermer')), ['expected' => 1, 'missing' => 1])));

        // Oublié plus de 12 heures : fermé seul, sans conclusion (aucune pièce écrite, aucune absence)
        $forgotten = (int) $this->db->fetchOne("INSERT INTO loss_count (scope, status, exercise, opened_at) VALUES ('all', 'open', false, NOW() - interval '13 hours') RETURNING id");
        $list = $this->json($this->admin('GET', self::COUNTS));
        $row = array_values(array_filter($list['counts'], static fn (array $c): bool => $c['id'] === $forgotten))[0];
        $this->assertSame(['expired', 'Fermé sans conclusion', null, null], [$row['status'], $row['statusLabel'], $list['open'], $row['absent']]);
        $this->assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM loss_count_piece WHERE count_id = ?', [$forgotten]));
        $this->assertSame('COUNT_NOT_OPEN', $this->json($this->admin('POST', self::COUNTS . "/$forgotten/fermer"), 409)['error']);
        $this->assertSame('COUNT_NOT_FOUND', $this->json($this->admin('GET', self::COUNTS . '/99999999'), 404)['error']);

        // Ce que la base garde : un comptage fermé est figé et ne se supprime pas ; une pièce ne s'écrit pas dans un comptage fermé ; deux ouverts, non
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
        $refused('un comptage fermé modifié', 'UPDATE loss_count SET found = 99 WHERE id = ?', [(int) $a['id']]);
        $refused('un comptage supprimé', 'DELETE FROM loss_count WHERE id = ?', [(int) $a['id']]);
        $refused('une pièce dans un comptage fermé', 'INSERT INTO loss_count_piece (count_id, piece_id, found) VALUES (?, ?, true)', [(int) $a['id'], $this->piece($p[1])]);
        $refused('deux comptages ouverts', "INSERT INTO loss_count (scope, status, exercise, opened_at) VALUES ('all', 'open', false, NOW()), ('floor', 'open', false, NOW())");

        // Le bilan du soir : des nombres, sans nom
        $evening = $this->json($this->admin('GET', self::BASE . '/bilan'));
        $this->assertSame(['day', 'exercise', 'trackedSold', 'soldWithoutRead', 'exitsWithoutSale', 'leftStockroom', 'unseenDays', 'unseenPieces', 'missingPieces', 'devicesDown', 'countsClosed', 'counterAlerts', 'notice', 'screen'],
            array_keys($evening));
        $this->assertSame([30, 0, 3], [$evening['unseenDays'], $evening['missingPieces'], $evening['countsClosed']]);
        $this->assertSame('INVALID_DATE', $this->json($this->admin('GET', self::BASE . '/bilan?day=demain'), 422)['fields']['day']);
        $this->assertSame('PARAM_OUT_OF_RANGE', $this->json($this->admin('GET', self::BASE . '/bilan?days=0'), 422)['fields']['days']);

        // Le catalogue : la règle, vague 4, sans aperçu
        $rules = array_column($this->json($this->admin('GET', self::BASE . '/regles'))['rules'], null, 'code');
        $this->assertSame([4, ['minCount']], [$rules['PIECE_MISSING']['wave'], array_column($rules['PIECE_MISSING']['params'], 'key')]);
        $this->assertSame('RULE_PREVIEW_UNAVAILABLE', $this->json($this->admin('POST', self::BASE . '/regles/apercu', ['code' => 'PIECE_MISSING', 'params' => ['minCount' => 3]]), 409)['error']);

        // La migration est rejouable et son script dit les mêmes instructions
        require_once dirname(__DIR__, 3) . '/migrations/Version20270125100000.php';
        $this->db->beginTransaction();
        foreach (\DoctrineMigrations\Version20270125100000::STATEMENTS as $sql) {
            $this->db->executeStatement($sql);
        }
        $this->db->rollBack();
        $script = (string) @file_get_contents(dirname(__DIR__, 3) . '/scripts/migrate_all_v2_pertes_l4d.sh');
        if ($script !== '') {
            foreach (\DoctrineMigrations\Version20270125100000::STATEMENTS as $sql) {
                $this->assertStringContainsString($sql . ';', $script);
            }
        }
        $this->assertSame(22, count(\DoctrineMigrations\Version20270125100000::STATEMENTS));
    }

    public function testAPracticeCountSeesOnlyPracticePiecesAndARealCountNeverSeesThem(): void
    {
        $real = $this->json($this->admin('POST', self::COUNTS, []), 201);
        $practice = $this->json($this->admin('POST', self::BASE . '/exercices/comptages', []), 201, 'un comptage d\'exercice s\'ouvre à côté d\'un vrai');
        $this->assertTrue($practice['exercise']);
        $this->assertSame([$practice['id']], array_column($this->json($this->admin('GET', self::BASE . '/exercices/comptages'))['counts'], 'id'));
        $this->assertNotContains($practice['id'], array_column($this->json($this->admin('GET', self::COUNTS))['counts'], 'id'));
        $this->assertSame('COUNT_NOT_FOUND', $this->json($this->admin('POST', self::COUNTS . '/' . $practice['id'] . '/fermer'), 404)['error']);
        $this->assertSame(0, $this->json($this->admin('POST', self::BASE . '/exercices/comptages/' . $practice['id'] . '/fermer'))['expected']);
        $this->json($this->admin('POST', self::COUNTS . '/' . $real['id'] . '/fermer'));
        // le bilan de l'exercice compte ses comptages d'exercice, pas les vrais
        $this->assertSame([true, 1, 'pertes.exercices'], array_values(array_intersect_key($this->json($this->admin('GET', self::BASE . '/exercices/bilan')), ['exercise' => 1, 'countsClosed' => 1, 'screen' => 1])));
        // l'exercice s'efface avec ses comptages
        $this->json($this->admin('DELETE', self::BASE . '/exercices/pieces'));
        $this->assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM loss_count WHERE exercise'));
    }

    private function install(): void
    {
        $created = $this->json($this->admin('POST', self::BOXES, ['name' => "Comptage $this->s"]), 201);
        $enrolment = $this->json($this->channel('POST', '/inscription', ['key' => $created['key'], 'machine' => self::MACHINE, 'version' => '1.0.0', 'arch' => 'amd64'], null));
        $this->token = $enrolment['token'];
        $this->json($this->channel('POST', '/signe-de-vie', ['now' => gmdate('Y-m-d\TH:i:s\Z'), 'machine' => self::MACHINE, 'lastServerTime' => $enrolment['serverTime'],
            'devices' => [['serial' => "MAIN-$this->s", 'kind' => 'reader', 'status' => 'ok']]], $this->token));
    }

    /** Une lecture du lecteur à main, maintenant */
    private function spot(string $identifier): void
    {
        $fact = ['key' => 'compte-' . $this->s . '-' . str_pad((string) ++$this->n, 4, '0', STR_PAD_LEFT), 'type' => 'SPOT_CHECK', 'at' => gmdate('Y-m-d\TH:i:s\Z'),
            'device' => "MAIN-$this->s", 'data' => ['identifier' => $identifier]];
        $result = $this->json($this->channel('POST', '/faits', ['facts' => [$fact]], $this->token))['results'][0];
        $this->assertSame('accepted', $result['status'], json_encode($result));
    }

    private function piece(string $identifier): int
    {
        return (int) $this->db->fetchOne('SELECT piece_id FROM loss_tag WHERE identifier = ?', [$identifier]);
    }

    private function status(string $identifier): string
    {
        return (string) $this->db->fetchOne('SELECT p.status FROM loss_piece p JOIN loss_tag t ON t.piece_id = p.id WHERE t.identifier = ?', [$identifier]);
    }

    /** Une vraie pièce et sa puce NFC, depuis 3 jours */
    private function tagged(int $variant, string $suffix): string
    {
        $nfc = '04' . $this->s . 'FF00' . $suffix;
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
            'HTTP_X_LOSS_BOX_MACHINE' => $token !== null ? ($this->machines[$token] ?? self::MACHINE) : null, // lot L5-e : la preuve de la machine
        ]) + ['REMOTE_ADDR' => $this->ip], $body !== null ? json_encode($body) : null);

        return $this->client->getResponse();
    }

    /** Un article d'une variante, avec ce stock ; rend la variante */
    private function article(string $name, int $stock): int
    {
        $slug = 'l4d-' . bin2hex(random_bytes(4));
        $this->db->executeStatement("INSERT INTO product (id, name, price, isbestseller, quantity, created_at, slug, product_type, is_web, is_pos) VALUES (nextval('product_id_seq'), :n, 3000, false, 0, NOW(), :s, 'standard', false, true)",
            ['n' => "$name L4D-$this->s", 's' => $slug]);
        $product = (int) $this->db->fetchOne('SELECT id FROM product WHERE slug = ?', [$slug]);

        return (int) $this->db->fetchOne("INSERT INTO product_variant (id, product_id, stock_quantity) VALUES (nextval('product_variant_id_seq'), ?, ?) RETURNING id", [$product, $stock]);
    }
}
