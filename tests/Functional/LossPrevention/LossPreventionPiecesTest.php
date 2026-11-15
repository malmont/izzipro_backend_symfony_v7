<?php

namespace App\Tests\Functional\LossPrevention;

use App\LossPrevention\Entity\LossPiece;
use App\LossPrevention\Services\LossPieceService;
use App\LossPrevention\Services\LossSite;
use App\LossPrevention\Services\LossTagIdentifier;
use App\LossPrevention\Services\LossTexts;
use App\Services\BarcodeService\CatalogBarcodeService;
use App\Services\Platform\PlatformModeService;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

/**
 * Pièces et puces, lot L2-a (15/11/2026, `docs/prevention-pertes.md` § 13) : ce que la base garantit seule (identifiant unique, une
 * puce associée ne change jamais de pièce, faits en ajout seul, suppression réservée à l'exercice) ; les routes (créer, associer d'un
 * geste, chercher, retirer, synthèse) ; rien n'est écrit dans le stock ni le catalogue ; pièces d'exercice et puces simulées à part.
 *
 * Les vraies pièces ne se suppriment pas : elles restent dans la base de test. Chaque test crée donc ses propres articles et ne
 * regarde que ses variantes (ou des écarts avant / après).
 */
class LossPreventionPiecesTest extends LossPreventionTestCase
{
    private const PIECES = self::BASE . '/pieces';
    private const TAGS = self::BASE . '/puces';
    private const EX = self::BASE . '/exercices';

    private string $s;

    protected function setUp(): void
    {
        parent::setUp();
        $this->s = strtoupper(bin2hex(random_bytes(3)));
        $this->flags(true, true);
    }

    protected function tearDown(): void
    {
        // Pièces d'exercice : effacées par la porte d'exercice ; articles du test : retirés du catalogue (une pièce n'a pas de clé étrangère)
        $this->db->beginTransaction();
        $this->provider->getEntityManager()->getRepository(LossPiece::class)->deleteExercise();
        $this->db->commit();
        $ids = $this->db->fetchFirstColumn('SELECT id FROM product WHERE name LIKE ?', ['% L2-' . $this->s]);
        if ($ids !== []) {
            $this->db->executeStatement('DELETE FROM product_variant WHERE product_id IN (?)', [$ids], [ArrayParameterType::INTEGER]);
            $this->db->executeStatement('DELETE FROM product WHERE id IN (?)', [$ids], [ArrayParameterType::INTEGER]);
        }
        parent::tearDown();
    }

    public function testTheDatabaseAloneKeepsIdentityHistoryAndTheExerciseDoor(): void
    {
        [, $variant] = $this->article('Base', 1);
        $now = '2026-11-15 12:00:00';
        $piece = fn (bool $exercise = false): int => (int) $this->db->fetchOne(
            "INSERT INTO loss_piece (number, variant_id, product_id, status, exercise, created_at, updated_at) VALUES (nextval('loss_piece_number_seq'), ?, 1, 'created', CAST(? AS boolean), ?, ?) RETURNING id",
            [$variant[0], $exercise ? 'true' : 'false', $now, $now]);
        $tag = fn (string $identifier, ?int $pieceId, string $technology = 'nfc', string $status = 'associated', bool $exercise = false): int => (int) $this->db->fetchOne(
            'INSERT INTO loss_tag (identifier, raw, technology, status, piece_id, exercise, created_at, updated_at) VALUES (?, ?, ?, ?, ?, CAST(? AS boolean), ?, ?) RETURNING id',
            [$identifier, $identifier, $technology, $status, $pieceId, $exercise ? 'true' : 'false', $now, $now]);
        $refused = function (callable $write, string $why): void {
            $this->db->beginTransaction();
            try {
                $write();
                $this->fail($why . ' : la base devait refuser');
            } catch (DbalException $e) {
                $this->addToAssertionCount(1);
            } finally {
                $this->db->rollBack();
            }
        };
        $p1 = $piece();
        $p2 = $piece();
        $t1 = $tag("04A1B2C3D4$this->s", $p1);
        $event = (int) $this->db->fetchOne("INSERT INTO loss_piece_event (type, occurred_at, piece_id, data) VALUES ('PIECE_CREATED', ?, ?, '{}') RETURNING id", [$now, $p1]);

        // Un identifiant désigne une seule puce ; forme canonique ; valeurs connues
        try {
            $tag("04A1B2C3D4$this->s", $p2);
            $this->fail('le même identifiant sur deux puces');
        } catch (UniqueConstraintViolationException) {
            $this->addToAssertionCount(1);
        }
        $refused(fn () => $tag(strtolower("0b$this->s") . 'aa', $p1), 'identifiant en minuscules');
        $refused(fn () => $tag(" 0C{$this->s}AA", $p1), 'identifiant avec un espace autour');
        $refused(fn () => $tag("0D{$this->s}AA", $p1, 'rfid'), 'technologie inconnue');
        $refused(fn () => $tag("0E{$this->s}AA", null), 'puce associée sans pièce');
        $refused(fn () => $this->db->executeStatement("UPDATE loss_piece SET status = 'ailleurs' WHERE id = ?", [$p1]), 'état de pièce inconnu');
        // Une puce associée ne change jamais de pièce ; identité figée ; une puce retirée ne revient pas
        $refused(fn () => $this->db->executeStatement('UPDATE loss_tag SET piece_id = ? WHERE id = ?', [$p2, $t1]), 'changer de pièce');
        $refused(fn () => $this->db->executeStatement('UPDATE loss_tag SET piece_id = NULL WHERE id = ?', [$t1]), 'détacher la pièce');
        $refused(fn () => $this->db->executeStatement("UPDATE loss_tag SET identifier = 'FFFFFFFF' WHERE id = ?", [$t1]), 'changer d\'identifiant');
        $refused(fn () => $this->db->executeStatement("UPDATE loss_tag SET technology = 'uhf' WHERE id = ?", [$t1]), 'changer de technologie');
        $this->db->executeStatement("UPDATE loss_tag SET status = 'retired', retired_reason = 'lost' WHERE id = ?", [$t1]);
        $refused(fn () => $this->db->executeStatement("UPDATE loss_tag SET status = 'associated' WHERE id = ?", [$t1]), 'une puce retirée ne revient pas');
        // Une pièce garde son numéro, sa variante et son genre ; une pièce retirée ne revient pas et ne reçoit plus de puce
        $refused(fn () => $this->db->executeStatement('UPDATE loss_piece SET number = number + 100000 WHERE id = ?', [$p1]), 'changer de numéro');
        $refused(fn () => $this->db->executeStatement('UPDATE loss_piece SET variant_id = variant_id + 1 WHERE id = ?', [$p1]), 'changer de variante');
        $refused(fn () => $this->db->executeStatement('UPDATE loss_piece SET exercise = true WHERE id = ?', [$p1]), 'marquer une vraie pièce exercice');
        $this->db->executeStatement("UPDATE loss_piece SET status = 'retired' WHERE id = ?", [$p2]);
        $refused(fn () => $this->db->executeStatement("UPDATE loss_piece SET status = 'created' WHERE id = ?", [$p2]), 'une pièce retirée ne revient pas');
        $refused(fn () => $tag("0F{$this->s}AA", $p2), 'puce sur une pièce retirée');
        $refused(fn () => $tag("10{$this->s}AA", 2000000000), 'puce sur une pièce inconnue');
        // Vraies puces et puces simulées ne se mélangent pas
        $ex = $piece(true);
        $refused(fn () => $tag("11{$this->s}AA", $ex), 'vraie puce sur une pièce d\'exercice');
        $refused(fn () => $tag("SIM-{$this->s}AAAAAA", $p1, 'simulated', 'associated', true), 'puce simulée sur une vraie pièce');
        $refused(fn () => $tag("SIM-{$this->s}BBBBBB", $ex, 'simulated', 'associated', false), 'puce simulée non marquée exercice');
        $refused(fn () => $tag("12{$this->s}AA", $ex, 'nfc', 'associated', true), 'vraie technologie marquée exercice');
        $sim = $tag("SIM-{$this->s}CCCCCC", $ex, 'simulated', 'associated', true);
        $exEvent = (int) $this->db->fetchOne("INSERT INTO loss_piece_event (type, occurred_at, piece_id, source, exercise, data) VALUES ('PIECE_CREATED', ?, ?, 'exercise', true, '{}') RETURNING id", [$now, $ex]);

        // Un fait ne se modifie pas ; rien ne se supprime ni ne se vide
        $refused(fn () => $this->db->executeStatement("UPDATE loss_piece_event SET data = '{\"x\":1}' WHERE id = ?", [$event]), 'modifier un fait');
        $refused(fn () => $this->db->executeStatement('UPDATE loss_piece_event SET exercise = true WHERE id = ?', [$event]), 'marquer un fait exercice');
        foreach (['loss_piece_event' => $event, 'loss_tag' => $t1, 'loss_piece' => $p1] as $table => $id) {
            $refused(fn () => $this->db->executeStatement("DELETE FROM $table WHERE id = ?", [$id]), "supprimer dans $table");
            $refused(fn () => $this->db->executeStatement("TRUNCATE $table"), "vider $table");
            // … même par la porte d'exercice : elle ne laisse partir qu'une ligne marquée exercice
            $refused(function () use ($table, $id): void {
                $this->db->executeStatement("SET LOCAL loss.purge = 'exercise'");
                $this->db->executeStatement("DELETE FROM $table WHERE id = ?", [$id]);
            }, "supprimer une vraie ligne de $table par la porte d'exercice");
        }
        foreach (['loss_piece_event' => $exEvent, 'loss_tag' => $sim, 'loss_piece' => $ex] as $table => $id) {
            $refused(fn () => $this->db->executeStatement("DELETE FROM $table WHERE id = ?", [$id]), "supprimer une ligne d'exercice de $table sans la porte");
        }
        $this->db->beginTransaction();
        $this->db->executeStatement("SET LOCAL loss.purge = 'exercise'");
        foreach (['loss_piece_event' => $exEvent, 'loss_tag' => $sim, 'loss_piece' => $ex] as $table => $id) {
            $this->assertSame(1, (int) $this->db->executeStatement("DELETE FROM $table WHERE id = ?", [$id]), "la porte laisse partir la ligne d'exercice de $table");
        }
        $this->db->commit();
        $this->assertSame([1, 1, 1], [(int) $this->db->fetchOne('SELECT COUNT(*) FROM loss_piece WHERE id = ?', [$p1]), (int) $this->db->fetchOne('SELECT COUNT(*) FROM loss_tag WHERE id = ?', [$t1]),
            (int) $this->db->fetchOne('SELECT COUNT(*) FROM loss_piece_event WHERE id = ?', [$event])], 'les vraies lignes sont toujours là');

        // Deux lectures simultanées de la même puce : la seconde attend la première, puis la base la refuse
        $other = DriverManager::getConnection($this->db->getParams());
        try {
            $other->beginTransaction();
            $other->executeStatement('INSERT INTO loss_tag (identifier, raw, technology, status, piece_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)', ["13{$this->s}AA", 'x', 'nfc', 'associated', $p1, $now, $now]);
            $this->db->executeStatement("SET lock_timeout = '2s'");
            try {
                $tag("13{$this->s}AA", $p1);
                $this->fail('la seconde écriture devait attendre');
            } catch (DbalException $e) {
                $this->assertStringContainsString('lock timeout', $e->getMessage(), 'la seconde lecture attend la première : le même identifiant ne passe pas deux fois');
            }
        } finally {
            $this->db->executeStatement('SET lock_timeout = 0');
            $other->rollBack();
            $other->close();
        }
    }

    public function testCreatingAssociatingSearchingAndRetiringWithoutTouchingStockOrCatalogue(): void
    {
        [$jacket, [$v1, $v2]] = $this->article('Veste', 2, ["VESTE-M-$this->s", "VESTE-L-$this->s"]);
        [$bag, [$vBag]] = $this->article('Sac', 1, [null], "SAC-$this->s");
        [$bottle] = $this->article('Gourde', 0, [], "GOURDE-$this->s");
        $this->db->executeStatement('UPDATE product SET barcode = ? WHERE id = ?', ["VESTE-$this->s", $jacket]);
        $outside = fn (): array => [
            $this->db->fetchAllKeyValue('SELECT id, stock_quantity FROM product_variant WHERE id IN (?, ?, ?)', [$v1, $v2, $vBag]),
            (int) $this->db->fetchOne('SELECT COUNT(*) FROM inventory_movements'), (int) $this->db->fetchOne('SELECT COUNT(*) FROM product'),
            (int) $this->db->fetchOne('SELECT COUNT(*) FROM product_variant'), (int) $this->db->fetchOne('SELECT COUNT(*) FROM pos_audit_log'),
            (int) $this->db->fetchOne('SELECT COUNT(*) FROM loss_finding'), $this->db->fetchAllKeyValue('SELECT id, barcode FROM product_variant WHERE id IN (?, ?, ?)', [$v1, $v2, $vBag]),
        ];
        $events = fn (int $pieceId): array => $this->db->fetchFirstColumn('SELECT type FROM loss_piece_event WHERE piece_id = ? ORDER BY id', [$pieceId]);

        // Droits : administrateur seulement
        $this->assertSame(401, $this->call('GET', self::PIECES, null, null)->getStatusCode());
        $this->loginPos(['ROLE_USER_POS']);
        $this->assertSame(403, $this->call('POST', self::PIECES, ['variantId' => $v1, 'count' => 1], $this->posToken)->getStatusCode(), 'un caissier');
        $this->assertSame(403, $this->call('POST', self::TAGS . '/associer', ['variantId' => $v1, 'identifier' => '04A1B2C3D4E5F6', 'technology' => 'nfc'], $this->posToken)->getStatusCode());
        $this->assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM loss_piece WHERE variant_id IN (?, ?, ?)', [$v1, $v2, $vBag]));
        $before = $outside(); // après la connexion du caissier, qui écrit dans le journal de caisse

        // 1. Créer les pièces d'une variante : numéros uniques qui se suivent, un fait par pièce
        $created = $this->json($this->admin('POST', self::PIECES, ['variantId' => $v1, 'count' => 3]), 201);
        $this->assertSame(3, $created['count']);
        [$a, $b, $c] = $created['pieces'];
        $this->assertSame([$a['number'] + 1, $a['number'] + 2], [$b['number'], $c['number']]);
        $this->assertSame(['created', 'En service', null, false, $v1, $jacket, false, "Veste L2-$this->s", null, "VESTE-M-$this->s", 'variant', 'code128', [], false, null, null, null],
            [$a['status'], $a['statusLabel'], $a['zone'], $a['exercise'], $a['variantId'], $a['productId'], $a['variantMissing'], $a['name'], $a['variantLabel'], $a['barcode'], $a['barcodeOf'], $a['symbology'],
                $a['tags'], $a['tagged'], $a['commandeId'], $a['retiredReason'], $a['retiredAt']]);
        $this->assertSame(['PIECE_CREATED'], $events($a['id']));
        $this->assertSame($this->adminId, (int) $this->db->fetchOne('SELECT user_id FROM loss_piece_event WHERE piece_id = ?', [$a['id']]));
        $this->assertSame('In service', $this->json($this->admin('GET', self::PIECES . '/' . $a['id'] . '?locale=en'))['statusLabel']);
        // Par le code-barres de l'article quand il n'a qu'une variante ; le code de la pièce est alors celui de l'article
        $byBarcode = $this->json($this->admin('POST', self::PIECES, ['barcode' => " sac-$this->s "]), 201)['pieces'][0];
        $this->assertSame([$vBag, $bag, "SAC-$this->s", 'product'], [$byBarcode['variantId'], $byBarcode['productId'], $byBarcode['barcode'], $byBarcode['barcodeOf']]);
        // Refus
        $fields = fn (array $body): array => $this->json($this->admin('POST', self::PIECES, $body), 422)['fields'];
        $this->assertSame(['count' => 'OUT_OF_RANGE'], $fields(['variantId' => $v1, 'count' => 0]));
        $this->assertSame(['count' => 'OUT_OF_RANGE'], $fields(['variantId' => $v1, 'count' => 501]));
        $this->assertSame(['count' => 'OUT_OF_RANGE', 'commandeId' => 'MUST_BE_POSITIVE', 'target' => 'ONE_TARGET_REQUIRED'], $fields(['count' => '2', 'commandeId' => 0]));
        $this->assertSame(['target' => 'ONE_TARGET_REQUIRED'], $fields(['variantId' => $v1, 'barcode' => "VESTE-M-$this->s"]));
        $this->assertSame(['variantId' => 'MUST_BE_POSITIVE'], $fields(['variantId' => '12']));
        $refusal = fn (string $path, array $body, int $status): array => $this->json($this->admin('POST', $path, $body), $status);
        $this->assertSame('VARIANT_NOT_FOUND', $refusal(self::PIECES, ['variantId' => 2000000000], 404)['error']);
        $this->assertSame('COMMANDE_NOT_FOUND', $refusal(self::PIECES, ['variantId' => $v1, 'commandeId' => 2000000000], 404)['error']);
        $this->assertSame('BARCODE_NOT_FOUND', $refusal(self::PIECES, ['barcode' => "INCONNU-$this->s"], 404)['error']);
        $ambiguous = $refusal(self::PIECES, ['barcode' => "VESTE-$this->s"], 409);
        $this->assertSame(['BARCODE_AMBIGUOUS', $jacket, [$v1, $v2]], [$ambiguous['error'], $ambiguous['productId'], $ambiguous['variantIds']]);
        $this->assertSame(['NO_VARIANT', $bottle, []], array_values(array_intersect_key($refusal(self::PIECES, ['barcode' => "GOURDE-$this->s"], 409), array_flip(['error', 'productId', 'variantIds']))));

        // 2. Poser une puce sur une pièce : forme canonique, fait, rejouer ne fait rien
        $nfc = '04' . $this->s . 'C3D4E5'; // 14 caractères
        $read = implode(':', str_split(strtolower($nfc), 2));
        $set = $this->json($this->admin('POST', self::TAGS . '/associer', ['pieceId' => $a['id'], 'identifier' => " $read ", 'technology' => 'nfc']));
        $this->assertSame([false, true, $nfc, 'nfc', 'Puce NFC', 'associated', 'Posée', $a['id'], false, true, 1],
            [$set['created'], $set['associated'], $set['tag']['identifier'], $set['tag']['technology'], $set['tag']['technologyLabel'], $set['tag']['status'], $set['tag']['statusLabel'], $set['tag']['pieceId'],
                $set['tag']['exercise'], $set['piece']['tagged'], count($set['piece']['tags'])]);
        $this->assertSame($read, trim((string) $this->db->fetchOne('SELECT raw FROM loss_tag WHERE id = ?', [$set['tag']['id']])), 'ce que le lecteur a rendu est gardé');
        $this->assertSame(['PIECE_CREATED', 'TAG_ASSOCIATED'], $events($a['id']));
        $again = $this->json($this->admin('POST', self::TAGS . '/associer', ['pieceId' => $a['id'], 'identifier' => $nfc, 'technology' => 'nfc']));
        $this->assertSame([false, false, $set['tag']['id'], $a['id']], [$again['created'], $again['associated'], $again['tag']['id'], $again['piece']['id']]);
        $this->assertSame(['PIECE_CREATED', 'TAG_ASSOCIATED'], $events($a['id']), 'rejouer n\'écrit aucun fait');
        // La même puce ne se pose pas sur une autre pièce : on dit ce qui la porte
        $taken = $refusal(self::TAGS . '/associer', ['pieceId' => $b['id'], 'identifier' => $nfc, 'technology' => 'nfc'], 409);
        $this->assertSame(['TAG_ALREADY_ASSOCIATED', ['id' => $a['id'], 'number' => $a['number'], 'variantId' => $v1, 'productId' => $jacket, 'name' => "Veste L2-$this->s", 'variantLabel' => null],
            "Cette puce est déjà posée sur la pièce n° {$a['number']} (Veste L2-$this->s)."], [$taken['error'], $taken['holder'], $taken['message']]);
        $this->assertStringStartsWith('This tag is already attached to piece no. ' . $a['number'], $this->json($this->admin('POST', self::TAGS . '/associer?locale=en', ['variantId' => $v2, 'identifier' => $nfc, 'technology' => 'nfc']), 409)['message']);
        $this->assertSame([], $this->db->fetchFirstColumn('SELECT id FROM loss_piece WHERE variant_id = ?', [$v2]), 'le refus n\'a créé aucune pièce');

        // 3. LE geste : lire le code-barres, lire la puce ; la pièce est créée ; rejouer le geste rend la même pièce
        $uhf = 'E280' . $this->s . '00000000' . $this->s; // 24 caractères
        $gesture = $this->json($this->admin('POST', self::TAGS . '/associer', ['barcode' => strtolower("veste-l-$this->s"), 'identifier' => $uhf, 'technology' => 'uhf']));
        $this->assertSame([true, true, $v2, $uhf, 'uhf'], [$gesture['created'], $gesture['associated'], $gesture['piece']['variantId'], $gesture['tag']['identifier'], $gesture['tag']['technology']]);
        $this->assertSame(['PIECE_CREATED', 'TAG_ASSOCIATED'], $events($gesture['piece']['id']));
        $replay = $this->json($this->admin('POST', self::TAGS . '/associer', ['barcode' => "VESTE-L-$this->s", 'identifier' => $uhf, 'technology' => 'uhf']));
        $this->assertSame([false, false, $gesture['piece']['id']], [$replay['created'], $replay['associated'], $replay['piece']['id']]);
        $this->assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM loss_piece WHERE variant_id = ?', [$v2]), 'le geste rejoué ne crée pas une seconde pièce');
        $this->assertSame('TAG_ALREADY_ASSOCIATED', $refusal(self::TAGS . '/associer', ['barcode' => "VESTE-M-$this->s", 'identifier' => $uhf, 'technology' => 'uhf'], 409)['error'], 'la même puce pour une autre variante');
        // Lectures refusées
        $invalid = $refusal(self::TAGS . '/associer', ['pieceId' => $b['id'], 'identifier' => 'XYZ', 'technology' => 'nfc'], 422);
        $this->assertSame(['TAG_INVALID', 'nfc', "Cette lecture n'a pas la forme d'une puce NFC : 8, 14, 16 ou 20 caractères, chiffres et lettres de A à F."], [$invalid['error'], $invalid['technology'], $invalid['message']]);
        foreach ([['0011223344', 'uhf'], ['SIM-0123456789AB', 'nfc'], ['0412345', 'nfc'], [str_repeat('A', 68), 'uhf']] as [$bad, $technology]) {
            $this->assertSame('TAG_INVALID', $refusal(self::TAGS . '/associer', ['pieceId' => $b['id'], 'identifier' => $bad, 'technology' => $technology], 422)['error'], $bad);
        }
        $this->assertSame(['technology' => 'REQUIRED', 'identifier' => 'REQUIRED'], $refusal(self::TAGS . '/associer', ['pieceId' => $b['id']], 422)['fields']);
        $this->assertSame(['target' => 'ONE_TARGET_REQUIRED', 'technology' => 'INVALID_VALUE', 'identifier' => 'MUST_BE_STRING'], $refusal(self::TAGS . '/associer', ['identifier' => 12, 'technology' => 'simulated'], 422)['fields']);
        $this->assertSame('PIECE_NOT_FOUND', $refusal(self::TAGS . '/associer', ['pieceId' => 2000000000, 'identifier' => '04123456', 'technology' => 'nfc'], 404)['error']);
        // Le code-barres d'un article lu dans le champ de la puce (lecteur en clavier) : refusé, on dit de quoi il s'agit
        $hexCode = 'ABCDEF01' . $this->s . 'AA'; // 16 caractères : la forme d'une puce
        $this->db->executeStatement('UPDATE product_variant SET barcode = ? WHERE id = ?', [$hexCode, $vBag]);
        $isBarcode = $refusal(self::TAGS . '/associer', ['pieceId' => $b['id'], 'identifier' => $hexCode, 'technology' => 'uhf'], 409);
        $this->assertSame(['TAG_IS_BARCODE', 'variant', $vBag], [$isBarcode['error'], $isBarcode['holder']['type'], $isBarcode['holder']['id']]);
        $this->db->executeStatement('UPDATE product_variant SET barcode = NULL WHERE id = ?', [$vBag]);
        $this->assertSame(['PIECE_CREATED'], $events($b['id']), 'aucun refus n\'a écrit');

        // 4. Plusieurs puces sur une pièce (une paire), quatre au plus
        $pair = fn (int $n): string => sprintf('%02d', $n) . $this->s; // 8 caractères
        $second = $this->json($this->admin('POST', self::TAGS . '/associer', ['pieceId' => $a['id'], 'identifier' => $pair(1), 'technology' => 'nfc']));
        $this->assertSame(2, count($second['piece']['tags']));
        $this->assertSame(['PIECE_CREATED', 'TAG_ASSOCIATED', 'TAG_ASSOCIATED', 'PAIR_SET'], $events($a['id']));
        foreach ([2, 3] as $n) {
            $this->json($this->admin('POST', self::TAGS . '/associer', ['pieceId' => $a['id'], 'identifier' => $pair($n), 'technology' => 'nfc']));
        }
        $limit = $refusal(self::TAGS . '/associer', ['pieceId' => $a['id'], 'identifier' => $pair(4), 'technology' => 'nfc'], 409);
        $this->assertSame(['PIECE_TAG_LIMIT', 4], [$limit['error'], $limit['limit']]);
        $this->assertNull($this->db->fetchOne('SELECT id FROM loss_tag WHERE identifier = ?', [$pair(4)]) ?: null);

        // 5. Chercher ce que porte une puce
        $found = $this->json($this->admin('GET', self::TAGS . '/recherche?identifier=' . rawurlencode($read)));
        $this->assertSame([$nfc, true, null, $set['tag']['id'], $a['id'], $a['number']], [$found['identifier'], $found['found'], $found['refusal'], $found['tag']['id'], $found['piece']['id'], $found['piece']['number']]);
        $this->assertSame(['identifier' => 'AABBCCDD', 'found' => false, 'refusal' => null, 'tag' => null, 'piece' => null], $this->json($this->admin('GET', self::TAGS . '/recherche?identifier=aa-bb-cc-dd')));
        $this->assertSame([null, false, 'TAG_INVALID'], array_slice(array_values($this->json($this->admin('GET', self::TAGS . '/recherche?identifier=pas-une-puce'))), 0, 3));
        foreach (['', '?identifier=', '?identifier[]=A'] as $query) {
            $this->assertSame(['identifier' => 'REQUIRED'], $this->json($this->admin('GET', self::TAGS . '/recherche' . $query), 422)['fields'], $query);
        }

        // 6. Retirer une puce : confirmé, avec un motif ; elle ne revient sur aucune pièce
        $tagId = $set['tag']['id'];
        $this->assertSame('CONFIRMATION_REQUIRED', $refusal(self::TAGS . "/$tagId/retirer", ['reason' => 'detached'], 409)['error']);
        $this->assertSame('CONFIRMATION_REQUIRED', $refusal(self::TAGS . "/$tagId/retirer", ['reason' => 'detached', 'confirm' => false], 409)['error']);
        $this->assertSame(['reason' => 'INVALID_VALUE', 'confirm' => 'MUST_BE_BOOLEAN'], $refusal(self::TAGS . "/$tagId/retirer", ['reason' => 'damaged', 'confirm' => 'oui'], 422)['fields']);
        $this->assertSame(['reason' => 'REQUIRED'], $refusal(self::TAGS . "/$tagId/retirer", ['confirm' => true], 422)['fields']);
        $this->assertSame('associated', $this->db->fetchOne('SELECT status FROM loss_tag WHERE id = ?', [$tagId]));
        $retired = $refusal(self::TAGS . "/$tagId/retirer", ['reason' => 'detached', 'confirm' => true], 200);
        $this->assertSame([true, 'retired', 'Retirée', 'detached', 'Puce détachée', $a['id'], 3], [$retired['changed'], $retired['tag']['status'], $retired['tag']['statusLabel'], $retired['tag']['retiredReason'],
            $retired['tag']['retiredReasonLabel'], $retired['piece']['id'], count(array_filter($retired['piece']['tags'], fn (array $t) => $t['status'] === 'associated'))]);
        $this->assertNotNull($retired['tag']['retiredAt']);
        $this->assertSame(false, $refusal(self::TAGS . "/$tagId/retirer", ['reason' => 'lost', 'confirm' => true], 200)['changed'], 'retirer deux fois ne fait rien');
        $this->assertSame('detached', $this->db->fetchOne('SELECT retired_reason FROM loss_tag WHERE id = ?', [$tagId]));
        $this->assertSame(1, (int) $this->db->fetchOne("SELECT COUNT(*) FROM loss_piece_event WHERE tag_id = ? AND type = 'TAG_RETIRED'", [$tagId]));
        $this->assertSame('TAG_RETIRED', $refusal(self::TAGS . '/associer', ['pieceId' => $b['id'], 'identifier' => $nfc, 'technology' => 'nfc'], 409)['error']);
        $this->assertSame('TAG_RETIRED', $refusal(self::TAGS . '/associer', ['pieceId' => $a['id'], 'identifier' => $nfc, 'technology' => 'nfc'], 409)['error'], 'pas même sur sa propre pièce');
        $this->assertSame('TAG_NOT_FOUND', $refusal(self::TAGS . '/2000000000/retirer', ['reason' => 'lost', 'confirm' => true], 404)['error']);

        // 7. Retirer une pièce : ses puces actives partent avec elle ; elle ne reçoit plus de puce
        $this->assertSame('CONFIRMATION_REQUIRED', $refusal(self::PIECES . "/{$a['id']}/retirer", ['reason' => 'damaged'], 409)['error']);
        $this->assertSame(['reason' => 'INVALID_VALUE'], $refusal(self::PIECES . "/{$a['id']}/retirer", ['reason' => 'detached', 'confirm' => true], 422)['fields']);
        $gone = $refusal(self::PIECES . "/{$a['id']}/retirer", ['reason' => 'damaged', 'confirm' => true], 200);
        $this->assertSame([true, 'retired', 'Retirée', 'damaged', 'Pièce abîmée', false], [$gone['changed'], $gone['piece']['status'], $gone['piece']['statusLabel'], $gone['piece']['retiredReason'], $gone['piece']['retiredReasonLabel'], $gone['piece']['tagged']]);
        $this->assertSame(['detached', 'piece_retired', 'piece_retired', 'piece_retired'], array_column($gone['piece']['tags'], 'retiredReason'));
        $this->assertSame(['PIECE_CREATED', 'TAG_ASSOCIATED', 'TAG_ASSOCIATED', 'PAIR_SET', 'TAG_ASSOCIATED', 'PAIR_SET', 'TAG_ASSOCIATED', 'PAIR_SET', 'TAG_RETIRED', 'TAG_RETIRED', 'TAG_RETIRED', 'TAG_RETIRED', 'PIECE_RETIRED'], $events($a['id']));
        $this->assertSame(false, $refusal(self::PIECES . "/{$a['id']}/retirer", ['reason' => 'lost', 'confirm' => true], 200)['changed']);
        $this->assertSame('PIECE_RETIRED', $refusal(self::TAGS . '/associer', ['pieceId' => $a['id'], 'identifier' => $pair(5), 'technology' => 'nfc'], 409)['error']);
        $this->assertSame('PIECE_NOT_FOUND', $refusal(self::PIECES . '/2000000000/retirer', ['reason' => 'lost', 'confirm' => true], 404)['error']);

        // 8. Fiche, liste, synthèse
        $sheet = $this->json($this->admin('GET', self::PIECES . '/' . $a['id']));
        $this->assertSame(['PIECE_CREATED', 'Pièce créée', $this->adminId, null], [$sheet['events'][0]['type'], $sheet['events'][0]['label'], $sheet['events'][0]['userId'], $sheet['events'][0]['tagId']]);
        $this->assertSame(13, count($sheet['events']));
        $this->assertSame(['reason' => 'damaged'], $sheet['events'][12]['data']);
        $this->assertSame('PIECE_NOT_FOUND', $this->json($this->admin('GET', self::PIECES . '/2000000000'), 404)['error']);
        $list = $this->admin('GET', self::PIECES . "?variantId=$v1");
        $this->assertSame([[$c['id'], $b['id'], $a['id']], '3', '1', '50'], [array_column($this->json($list), 'id'), $list->headers->get('X-Total-Count'), $list->headers->get('X-Page'), $list->headers->get('X-Limit')], 'les plus récentes d\'abord');
        $this->assertSame([$a['id']], array_column($this->json($this->admin('GET', self::PIECES . "?variantId=$v1&status=retired")), 'id'));
        $this->assertSame([$gesture['piece']['id']], array_column($this->json($this->admin('GET', self::PIECES . "?productId=$jacket&tagged=1")), 'id'));
        $this->assertSame([$c['id'], $b['id']], array_column($this->json($this->admin('GET', self::PIECES . "?variantId=$v1&tagged=0&status=created&limit=2")), 'id'));
        $this->assertSame(['variantId' => 'MUST_BE_POSITIVE', 'status' => 'INVALID_VALUE', 'tagged' => 'INVALID_VALUE', 'page' => 'MUST_BE_POSITIVE', 'limit' => 'OUT_OF_RANGE'],
            $this->json($this->admin('GET', self::PIECES . '?variantId=abc&status=sold&tagged=oui&page=0&limit=101'), 422)['fields']);
        $this->db->executeStatement('UPDATE product_variant SET stock_quantity = 7 WHERE id = ?', [$v1]);
        $synthesis = $this->json($this->admin('GET', self::PIECES . '/synthese?limit=100'));
        $mine = array_column($synthesis['rows'], null, 'variantId');
        $this->assertSame(['variantId' => $v1, 'productId' => $jacket, 'variantMissing' => false, 'name' => "Veste L2-$this->s", 'variantLabel' => null, 'barcode' => "VESTE-M-$this->s", 'barcodeOf' => 'variant',
            'symbology' => 'code128', 'stock' => 7, 'pieces' => ['active' => 2, 'retired' => 1, 'tagged' => 0, 'untagged' => 2], 'gap' => 5], $mine[$v1]);
        $this->assertSame([['active' => 1, 'retired' => 0, 'tagged' => 1, 'untagged' => 0], ['active' => 1, 'retired' => 0, 'tagged' => 0, 'untagged' => 1]], [$mine[$v2]['pieces'], $mine[$vBag]['pieces']]);
        $this->assertGreaterThanOrEqual(4, $synthesis['totals']['pieces']);
        $this->assertSame($synthesis['totals']['pieces'], $synthesis['totals']['tagged'] + $synthesis['totals']['untagged']);
        $this->db->executeStatement('UPDATE product_variant SET stock_quantity = ? WHERE id = ?', [$before[0][$v1], $v1]);
        // Une variante retirée du catalogue : la pièce reste, et le dit
        $this->db->executeStatement('DELETE FROM product_variant WHERE id = ?', [$vBag]);
        $orphan = $this->json($this->admin('GET', self::PIECES . '/' . $byBarcode['id']));
        $this->assertSame([true, null, null, $vBag], [$orphan['variantMissing'], $orphan['name'], $orphan['barcode'], $orphan['variantId']]);
        $this->db->executeStatement("INSERT INTO product_variant (id, product_id, stock_quantity) VALUES (?, ?, ?)", [$vBag, $bag, $before[0][$vBag]]);

        // 9. Rien n'a été écrit hors des tables du module
        $this->assertSame($before, $outside(), 'stock, mouvements, catalogue, journal de caisse et constats : inchangés');
    }

    public function testExercisePiecesAndSimulatedTagsStayApartAndOpenWithoutTheModule(): void
    {
        [, [$v1]] = $this->article('Exercice', 1, ["EXO-$this->s"]);
        $real = $this->json($this->admin('POST', self::PIECES, ['variantId' => $v1, 'count' => 1]), 201)['pieces'][0];
        $realTag = 'AB' . $this->s . 'CDEF0102'; // 16 caractères
        $this->json($this->admin('POST', self::TAGS . '/associer', ['pieceId' => $real['id'], 'identifier' => $realTag, 'technology' => 'uhf']));
        $counts = fn (): array => array_map('intval', $this->db->fetchNumeric('SELECT (SELECT COUNT(*) FROM loss_piece WHERE NOT exercise), (SELECT COUNT(*) FROM loss_tag WHERE NOT exercise), (SELECT COUNT(*) FROM loss_piece_event WHERE NOT exercise)'));
        $realBefore = $counts();
        $synthesisBefore = $this->json($this->admin('GET', self::PIECES . '/synthese'))['totals'];

        // Sans le droit du module, sur une plateforme en mode réel : les routes d'exercice s'ouvrent, les vraies restent fermées
        static::getContainer()->get(PlatformModeService::class)->setMode('live', 'test');
        $this->flags(true, false);
        foreach ([['GET', self::PIECES], ['POST', self::PIECES], ['GET', self::PIECES . '/synthese'], ['GET', self::PIECES . '/' . $real['id']], ['POST', self::PIECES . '/' . $real['id'] . '/retirer'],
            ['POST', self::TAGS . '/associer'], ['GET', self::TAGS . '/recherche?identifier=' . $realTag], ['POST', self::TAGS . '/1/retirer']] as [$method, $path]) {
            $closed = $this->admin($method, $path, $method === 'GET' ? null : ['variantId' => $v1, 'count' => 1, 'identifier' => '0412345678', 'technology' => 'nfc', 'reason' => 'lost', 'confirm' => true]);
            $this->assertSame([403, 'MODULE_INACTIVE', 'module.loss_prevention'], [$closed->getStatusCode(), $this->json($closed, 403)['code'], $this->json($closed, 403)['feature']], "$method $path : fermé sans le droit du module");
        }
        $this->assertSame($realBefore, $counts(), 'aucun de ces refus n\'a écrit');
        $empty = $this->json($this->admin('GET', self::EX . '/pieces'));
        $this->assertSame([[], 0, 30, 30, 10, 30], [$empty['pieces'], $empty['count'], $empty['limit'], $empty['remaining'], $empty['createLimit'], $empty['daily']['limit']]);
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT/', $empty['daily']['resetAt']);

        // Créer des pièces d'exercice, simuler une lecture, chercher, retirer
        $made = $this->json($this->admin('POST', self::EX . '/pieces', ['variantId' => $v1, 'count' => 2]), 201);
        [$e1, $e2] = $made['pieces'];
        $this->assertSame([true, true, 'created', "Exercice L2-$this->s", "EXO-$this->s"], [$e1['exercise'], $e2['exercise'], $e1['status'], $e1['name'], $e1['barcode']]);
        $simulated = $this->json($this->admin('POST', self::EX . '/puces/associer', ['pieceId' => $e1['id']]));
        $this->assertMatchesRegularExpression('/\ASIM-[0-9A-F]{12}\z/', $simulated['tag']['identifier']);
        $this->assertSame([false, true, 'simulated', 'Puce simulée', true, true], [$simulated['created'], $simulated['associated'], $simulated['tag']['technology'], $simulated['tag']['technologyLabel'], $simulated['tag']['exercise'], $simulated['piece']['tagged']]);
        $given = $this->json($this->admin('POST', self::EX . '/puces/associer', ['barcode' => "exo-$this->s", 'identifier' => ' sim-0123456789ab ']));
        $this->assertSame([true, 'SIM-0123456789AB', true], [$given['created'], $given['tag']['identifier'], $given['piece']['exercise']], 'le geste complet, en exercice : une pièce d\'exercice de plus');
        $this->assertSame('TAG_INVALID', $this->json($this->admin('POST', self::EX . '/puces/associer', ['pieceId' => $e2['id'], 'identifier' => '0412345678']), 422)['error'], 'une vraie lecture n\'entre pas dans l\'exercice');
        $this->assertSame('TAG_ALREADY_ASSOCIATED', $this->json($this->admin('POST', self::EX . '/puces/associer', ['pieceId' => $e2['id'], 'identifier' => 'SIM-0123456789AB']), 409)['error']);
        $found = $this->json($this->admin('GET', self::EX . '/puces/recherche?identifier=' . strtolower($simulated['tag']['identifier'])));
        $this->assertSame([true, $e1['id']], [$found['found'], $found['piece']['id']]);
        $this->assertSame(['PIECE_CREATED', 'TAG_ASSOCIATED'], array_column($this->json($this->admin('GET', self::EX . '/pieces/' . $e1['id']))['events'], 'type'));
        $this->assertSame('CONFIRMATION_REQUIRED', $this->json($this->admin('POST', self::EX . '/puces/' . $simulated['tag']['id'] . '/retirer', ['reason' => 'lost']), 409)['error']);
        $this->assertSame(true, $this->json($this->admin('POST', self::EX . '/puces/' . $simulated['tag']['id'] . '/retirer', ['reason' => 'lost', 'confirm' => true]))['changed']);
        $this->assertSame('retired', $this->json($this->admin('POST', self::EX . '/pieces/' . $e2['id'] . '/retirer', ['reason' => 'error', 'confirm' => true]))['piece']['status']);
        $this->assertSame(['exercise'], array_values(array_unique($this->db->fetchFirstColumn('SELECT source FROM loss_piece_event WHERE exercise'))), 'chaque fait d\'exercice dit d\'où il vient');
        $this->assertSame(3, $this->json($this->admin('GET', self::EX . '/pieces'))['count']);

        // Les deux mondes ne se croisent sur aucune route : un exercice est inconnu des vraies routes, et l'inverse
        $this->assertSame('PIECE_NOT_FOUND', $this->json($this->admin('GET', self::EX . '/pieces/' . $real['id']), 404)['error']);
        $this->assertSame('PIECE_NOT_FOUND', $this->json($this->admin('POST', self::EX . '/pieces/' . $real['id'] . '/retirer', ['reason' => 'error', 'confirm' => true]), 404)['error']);
        $this->assertSame('PIECE_NOT_FOUND', $this->json($this->admin('POST', self::EX . '/puces/associer', ['pieceId' => $real['id']]), 404)['error']);
        $this->assertSame(false, $this->json($this->admin('GET', self::EX . '/puces/recherche?identifier=' . $realTag))['found']);
        $this->assertSame('TAG_NOT_FOUND', $this->json($this->admin('POST', self::EX . '/puces/' . (int) $this->db->fetchOne('SELECT id FROM loss_tag WHERE identifier = ?', [$realTag]) . '/retirer', ['reason' => 'lost', 'confirm' => true]), 404)['error']);
        $this->flags(true, true);
        $this->assertSame('PIECE_NOT_FOUND', $this->json($this->admin('GET', self::PIECES . '/' . $e1['id']), 404)['error']);
        $this->assertSame('PIECE_NOT_FOUND', $this->json($this->admin('POST', self::PIECES . '/' . $e1['id'] . '/retirer', ['reason' => 'lost', 'confirm' => true]), 404)['error']);
        $this->assertSame('PIECE_NOT_FOUND', $this->json($this->admin('POST', self::TAGS . '/associer', ['pieceId' => $e1['id'], 'identifier' => '04123456', 'technology' => 'nfc']), 404)['error']);
        $this->assertSame('TAG_NOT_FOUND', $this->json($this->admin('POST', self::TAGS . '/' . $given['tag']['id'] . '/retirer', ['reason' => 'lost', 'confirm' => true]), 404)['error']);
        $this->assertSame([false, null], [$this->json($this->admin('GET', self::TAGS . '/recherche?identifier=SIM-0123456789AB'))['found'], $this->json($this->admin('GET', self::TAGS . '/recherche?identifier=SIM-0123456789AB'))['tag']]);
        $this->assertSame('TAG_INVALID', $this->json($this->admin('POST', self::TAGS . '/associer', ['pieceId' => $real['id'], 'identifier' => 'SIM-0123456789AB', 'technology' => 'nfc']), 422)['error']);
        // Jamais dans un chiffre réel : ni la liste, ni la synthèse
        $this->assertSame([$real['id']], array_column($this->json($this->admin('GET', self::PIECES . "?variantId=$v1")), 'id'));
        $this->assertSame($synthesisBefore, $this->json($this->admin('GET', self::PIECES . '/synthese'))['totals']);
        $this->assertSame($realBefore, $counts());

        // Plafonds : 10 par appel, 30 présentes, un nombre de créations par jour (effacer ne rend aucune création)
        $this->assertSame(['count' => 'OUT_OF_RANGE', 'commandeId' => 'NOT_ALLOWED'], $this->json($this->admin('POST', self::EX . '/pieces', ['variantId' => $v1, 'count' => 11, 'commandeId' => 1]), 422)['fields']);
        foreach ([10, 10, 7] as $count) {
            $this->json($this->admin('POST', self::EX . '/pieces', ['variantId' => $v1, 'count' => $count]), 201);
        }
        $full = $this->json($this->admin('POST', self::EX . '/pieces', ['variantId' => $v1, 'count' => 1]), 409);
        $this->assertSame(['EXERCISE_LIMIT_REACHED', 30, 'Vous avez déjà 30 pièces d\'exercice : effacez-les avant d\'en créer d\'autres.'], [$full['error'], $full['limit'], $full['message']]);
        $this->assertSame('EXERCISE_LIMIT_REACHED', $this->json($this->admin('POST', self::EX . '/puces/associer', ['variantId' => $v1]), 409)['error'], 'le geste qui créerait une pièce de trop');
        $this->assertSame([30, 0], [$this->json($this->admin('GET', self::EX . '/pieces'))['count'], $this->json($this->admin('GET', self::EX . '/pieces'))['remaining']]);
        $journal = fn (string $type): int => (int) $this->db->fetchOne('SELECT COUNT(*) FROM loss_journal WHERE type = ?', [$type]);
        $deletedBefore = $journal('EXERCISE_PIECES_DELETED');
        $createdToday = $journal('EXERCISE_PIECES_CREATED');
        $this->assertSame(['deleted' => 30], $this->json($this->admin('DELETE', self::EX . '/pieces')));
        $this->assertSame([0, 0, 0], array_map('intval', $this->db->fetchNumeric('SELECT (SELECT COUNT(*) FROM loss_piece WHERE exercise), (SELECT COUNT(*) FROM loss_tag WHERE exercise), (SELECT COUNT(*) FROM loss_piece_event WHERE exercise)')));
        $this->assertSame([$realBefore, $deletedBefore + 1, $createdToday], [$counts(), $journal('EXERCISE_PIECES_DELETED'), $journal('EXERCISE_PIECES_CREATED')], 'les vraies lignes restent ; le journal garde les créations');
        $this->assertSame(['deleted' => 0], $this->json($this->admin('DELETE', self::EX . '/pieces')));
        $this->assertSame($deletedBefore + 1, $journal('EXERCISE_PIECES_DELETED'), 'rien à effacer : pas de ligne');
        $this->assertSame('associated', $this->db->fetchOne('SELECT status FROM loss_tag WHERE identifier = ?', [$realTag]));
        // Plafond du jour : un service monté avec le plafond déjà atteint
        $capped = new LossPieceService($this->tenant(), static::getContainer()->get(CatalogBarcodeService::class), static::getContainer()->get(LossTexts::class), static::getContainer()->get(LossSite::class),
            min($createdToday, 30));
        try {
            $capped->create(['variantId' => $v1, 'count' => 1], 'fr', $this->adminId, true);
            $this->fail('le plafond du jour devait refuser');
        } catch (\App\Gestion\Services\GestionConflictException $e) {
            $this->assertSame(['EXERCISE_DAILY_LIMIT', min($createdToday, 30)], [$e->errorCode, $e->details['limit']]);
            $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT/', $e->details['resetAt']);
        }
        $this->assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM loss_piece WHERE exercise'));
        $this->assertFalse($this->db->isTransactionActive());

        // La garde de la gestion et le rôle d'administrateur restent exigés sur les routes d'exercice
        static::getContainer()->get(PlatformModeService::class)->setMode('live', 'test');
        $this->assertSame(401, $this->call('GET', self::EX . '/pieces', null, null)->getStatusCode());
        $this->flags(false, false);
        $this->assertSame('app.management', $this->json($this->admin('POST', self::EX . '/pieces', ['variantId' => $v1, 'count' => 1]), 403)['feature']);
        $this->assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM loss_piece WHERE exercise'));
    }

    public function testTheMigrationReplaysAndItsRollbackRemovesOnlyItsOwnObjects(): void
    {
        $schema = fn (): array => [
            $this->db->fetchFirstColumn("SELECT c.relname || '.' || t.tgname FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid WHERE c.relname IN ('loss_piece', 'loss_tag', 'loss_piece_event') AND NOT t.tgisinternal ORDER BY 1"),
            $this->db->fetchFirstColumn("SELECT indexname FROM pg_indexes WHERE tablename IN ('loss_piece', 'loss_tag', 'loss_piece_event') ORDER BY indexname"),
            $this->db->fetchFirstColumn("SELECT proname FROM pg_proc WHERE proname IN ('loss_piece_guard', 'loss_tag_guard', 'loss_piece_rows_kept', 'loss_piece_event_frozen') ORDER BY proname"),
            (int) $this->db->fetchOne("SELECT COUNT(*) FROM pg_class WHERE relkind = 'S' AND relname = 'loss_piece_number_seq'"),
        ];
        $before = $schema();
        $this->assertSame([
            ['loss_piece.loss_piece_guard', 'loss_piece.loss_piece_no_delete', 'loss_piece.loss_piece_no_truncate', 'loss_piece_event.loss_piece_event_frozen', 'loss_piece_event.loss_piece_event_no_delete',
                'loss_piece_event.loss_piece_event_no_truncate', 'loss_tag.loss_tag_guard', 'loss_tag.loss_tag_no_delete', 'loss_tag.loss_tag_no_truncate'],
            ['idx_loss_piece_event_exercise', 'idx_loss_piece_event_piece', 'idx_loss_piece_event_tag', 'idx_loss_piece_exercise', 'idx_loss_piece_variant', 'idx_loss_tag_exercise', 'idx_loss_tag_piece',
                'loss_piece_event_pkey', 'loss_piece_pkey', 'loss_tag_pkey', 'uniq_loss_piece_event_key', 'uniq_loss_piece_number', 'uniq_loss_tag_identifier'],
            ['loss_piece_event_frozen', 'loss_piece_guard', 'loss_piece_rows_kept', 'loss_tag_guard'], 1,
        ], $before);
        [, [$variant]] = $this->article('Rejeu', 1);
        $piece = $this->json($this->admin('POST', self::PIECES, ['variantId' => $variant, 'count' => 1]), 201)['pieces'][0];
        $outside = fn (): array => array_map('intval', $this->db->fetchNumeric('SELECT (SELECT COUNT(*) FROM loss_finding), (SELECT COUNT(*) FROM loss_evidence), (SELECT COUNT(*) FROM loss_journal), (SELECT COUNT(*) FROM product_variant)'));
        $outsideBefore = $outside();
        $rows = fn (): array => array_map('intval', $this->db->fetchNumeric('SELECT (SELECT COUNT(*) FROM loss_piece), (SELECT COUNT(*) FROM loss_tag), (SELECT COUNT(*) FROM loss_piece_event), (SELECT last_value FROM loss_piece_number_seq)'));
        $rowsBefore = $rows();

        // La migration se rejoue sur une base qui la porte déjà : rien ne change, ni le schéma, ni les lignes, ni le numéro suivant
        $this->db->beginTransaction();
        foreach (\DoctrineMigrations\Version20270109100000::STATEMENTS as $sql) {
            $this->db->executeStatement($sql);
        }
        $this->db->commit();
        $this->assertSame([$before, $rowsBefore, $outsideBefore], [$schema(), $rows(), $outside()]);
        $this->assertSame($piece['number'] + 1, $this->json($this->admin('POST', self::PIECES, ['variantId' => $variant, 'count' => 1]), 201)['pieces'][0]['number'], 'le numéro suivant, pas un numéro redonné');

        // Le retour arrière retire ses tables, sa séquence, ses fonctions, et rien d'autre ; puis la migration repose le même schéma.
        // Le tout dans une transaction annulée à la fin : la base de test garde ses pièces
        $rowsBefore = $rows();
        $this->db->beginTransaction();
        try {
            foreach (\DoctrineMigrations\Version20270109100000::DOWN_STATEMENTS as $sql) {
                $this->db->executeStatement($sql);
            }
            $this->assertSame([[], [], [], 0], $schema());
            $this->assertSame(0, (int) $this->db->fetchOne("SELECT COUNT(*) FROM information_schema.tables WHERE table_name IN ('loss_piece', 'loss_tag', 'loss_piece_event')"));
            $this->assertSame($outsideBefore, $outside(), 'le retour arrière ne touche à rien d\'autre');
            foreach (\DoctrineMigrations\Version20270109100000::STATEMENTS as $sql) {
                $this->db->executeStatement($sql);
            }
            $this->assertSame($before, $schema());
        } finally {
            $this->db->rollBack();
        }
        $this->assertSame([$before, $rowsBefore, $outsideBefore], [$schema(), $rows(), $outside()]);
    }

    public function testTheTagIdentifierRules(): void
    {
        $this->assertSame('04A1B2C3D4E5F6', LossTagIdentifier::canonical(' 04:a1:b2:c3:d4:e5:f6 ', 'nfc'));
        $this->assertSame('04A1B2C3', LossTagIdentifier::canonical('0x04a1b2c3', 'nfc'));
        $this->assertSame('E2801160600002054E1B7A2C', LossTagIdentifier::canonical('e280 1160 6000 0205 4e1b 7a2c', 'uhf'));
        foreach ([['04A1B2', 'nfc'], ['04A1B2C3D4', 'nfc'], ['E28011606000', 'uhf'], ['E2801160600002054E', 'uhf'], ['ZZ112233', 'nfc'], ['', 'nfc'], [null, 'nfc'], [12345678, 'nfc'], ['04A1B2C3', 'rfid'],
            ['SIM-0123456789AB', 'nfc'], ['SIM-0123456789AB', 'uhf'], ['04A1B2C3', 'simulated'], ['SIM-0123456789', 'simulated'], [str_repeat('A', 132), 'uhf']] as [$raw, $technology]) {
            $this->assertNull(LossTagIdentifier::canonical($raw, $technology), var_export($raw, true) . " ($technology)");
        }
        $this->assertSame('SIM-0123456789AB', LossTagIdentifier::canonical(' sim-0123456789ab ', 'simulated'));
        $this->assertSame(['SIM-0123456789AB', '04A1B2C3', 'E2801160600002054E1B7A2C', null], [LossTagIdentifier::loose('sim-0123456789ab'), LossTagIdentifier::loose('04-a1-b2-c3'), LossTagIdentifier::loose('E2801160600002054E1B7A2C'), LossTagIdentifier::loose('bonjour')]);
        $made = LossTagIdentifier::simulated();
        $this->assertSame($made, LossTagIdentifier::canonical($made, 'simulated'));
        $this->assertNotSame($made, LossTagIdentifier::simulated());
    }

    /**
     * Article du test : `$variants` variantes, chacune avec son code-barres (null : sans code).
     *
     * @param list<?string> $variantBarcodes
     *
     * @return array{int, list<int>} identifiant de l'article, identifiants de ses variantes
     */
    private function article(string $name, int $variants, array $variantBarcodes = [], ?string $barcode = null): array
    {
        $slug = 'l2-' . bin2hex(random_bytes(4));
        $this->db->executeStatement("INSERT INTO product (id, name, price, isbestseller, quantity, created_at, slug, product_type, is_web, is_pos, barcode) VALUES (nextval('product_id_seq'), :n, 1990, false, 0, NOW(), :s, 'standard', false, true, :b)",
            ['n' => "$name L2-$this->s", 's' => $slug, 'b' => $barcode]);
        $product = (int) $this->db->fetchOne('SELECT id FROM product WHERE slug = ?', [$slug]);
        $ids = [];
        for ($i = 0; $i < $variants; ++$i) {
            $ids[] = (int) $this->db->fetchOne("INSERT INTO product_variant (id, product_id, stock_quantity, barcode) VALUES (nextval('product_variant_id_seq'), ?, 5, ?) RETURNING id", [$product, $variantBarcodes[$i] ?? null]);
        }

        return [$product, $ids];
    }
}
