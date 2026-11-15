<?php

namespace App\Tests\Functional\LossPrevention;

use App\LossPrevention\Entity\LossPiece;
use App\LossPrevention\Services\LossInstallationService;
use App\LossPrevention\Services\LossPieceLabelService;
use App\Services\Platform\PlatformModeService;
use Doctrine\DBAL\ArrayParameterType;

/**
 * Lot L2-b (15/11/2026, `docs/prevention-pertes.md` § 13.10) : étiquettes des pièces (planche A4 ou rouleau), assistant
 * d'installation dont l'avancement se calcule à partir des faits, gamme de matériel. Les vraies pièces et leurs faits ne se
 * suppriment pas : le parcours « depuis zéro » de l'assistant se joue en exercice (effaçable), et les vérifications du monde réel
 * se font par écarts avant / après.
 */
class LossPreventionInstallationTest extends LossPreventionTestCase
{
    private const PIECES = self::BASE . '/pieces';
    private const TAGS = self::BASE . '/puces';
    private const INSTALL = self::BASE . '/installation';
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
        try {
            while ($this->db->isTransactionActive()) {
                $this->db->rollBack();
            }
            $this->db->beginTransaction();
            $this->provider->getEntityManager()->getRepository(LossPiece::class)->deleteExercise();
            $this->db->commit();
            $ids = $this->db->fetchFirstColumn('SELECT id FROM product WHERE name LIKE ?', ['% L2B-' . $this->s]);
            if ($ids !== []) {
                $this->db->executeStatement('DELETE FROM product_variant WHERE product_id IN (?)', [$ids], [ArrayParameterType::INTEGER]);
                $this->db->executeStatement('DELETE FROM product WHERE id IN (?)', [$ids], [ArrayParameterType::INTEGER]);
            }
        } finally {
            parent::tearDown();
        }
    }

    public function testPieceLabelsOnSheetsAndOnARoll(): void
    {
        [, [$v1, $v2]] = $this->article('Manteau', 4200, ["MANTEAU-A-$this->s", null]);
        $this->db->executeStatement('UPDATE product_variant SET price = 5500 WHERE id = ?', [$v1]);
        $pieces = array_column($this->json($this->admin('POST', self::PIECES, ['variantId' => $v1, 'count' => 3]), 201)['pieces'], null, 'id');
        [$a, $b, $c] = array_keys($pieces);
        $bare = $this->json($this->admin('POST', self::PIECES, ['variantId' => $v2]), 201)['pieces'][0]; // variante sans code-barres
        $rows = fn (): array => array_map('intval', $this->db->fetchNumeric('SELECT (SELECT COUNT(*) FROM loss_piece), (SELECT COUNT(*) FROM loss_tag), (SELECT COUNT(*) FROM loss_piece_event), (SELECT COUNT(*) FROM loss_journal)'));
        $before = $rows();
        $pages = fn (): int => preg_match_all('#/Type\s*/Page\b#', (string) $this->client->getResponse()->getContent());
        $headers = fn (): array => [$this->client->getResponse()->getStatusCode(), $this->client->getResponse()->headers->get('Content-Type'), $this->client->getResponse()->headers->get('X-Labels-Count'), $this->client->getResponse()->headers->get('X-Labels-Format')];

        // Planche A4 (par défaut), puis rouleau : une étiquette par pièce ; en rouleau, une page par étiquette
        $this->admin('POST', self::PIECES . '/etiquettes/pdf', ['pieceIds' => [$b, $a], 'showPrice' => true]);
        $this->assertSame([200, 'application/pdf', '2', 'a4-50x30'], $headers(), (string) $this->client->getResponse()->getContent());
        $this->assertStringStartsWith('%PDF-', (string) $this->client->getResponse()->getContent());
        $this->assertSame(1, $pages());
        $this->admin('POST', self::PIECES . '/etiquettes/pdf', ['pieceIds' => [$a, $b, $c], 'format' => 'roll-50x30']);
        $this->assertSame([200, 'application/pdf', '3', 'roll-50x30'], $headers());
        $this->assertSame(3, $pages(), 'en rouleau : une page de 50 × 30 mm par étiquette, pas une de plus');
        // Ce qui part à l'impression : le code-barres de la variante, le numéro de la pièce, le prix seulement si on le demande
        $labels = $this->labels()->selection(['pieceIds' => [$b, $a], 'showPrice' => true], 'fr', false);
        $this->assertSame('a4-50x30', $labels['format']);
        $this->assertSame([['title' => "Manteau L2B-$this->s", 'subtitle' => null, 'barcode' => "MANTEAU-A-$this->s", 'copies' => 1, 'note' => 'Pièce n° ' . $pieces[$b]['number']],
            ['title' => "Manteau L2B-$this->s", 'subtitle' => null, 'barcode' => "MANTEAU-A-$this->s", 'copies' => 1, 'note' => 'Pièce n° ' . $pieces[$a]['number']]],
            array_map(fn (array $l): array => array_diff_key($l, ['price' => 0]), $labels['labels']), 'dans l\'ordre demandé');
        $this->assertMatchesRegularExpression('/^55,00/', (string) $labels['labels'][0]['price'], 'le prix propre de la variante');
        $english = $this->labels()->selection(['pieceIds' => [$a], 'format' => 'roll-50x30'], 'en', false);
        $this->assertSame(['roll-50x30', null, 'Piece no. ' . $pieces[$a]['number']], [$english['format'], $english['labels'][0]['price'], $english['labels'][0]['note']]);

        // Refus : rien n'est imprimé
        $fields = fn (array $body): array => $this->json($this->admin('POST', self::PIECES . '/etiquettes/pdf', $body), 422)['fields'];
        $this->assertSame(['pieceIds' => 'REQUIRED'], $fields([]));
        $this->assertSame(['format' => 'INVALID_VALUE', 'showPrice' => 'MUST_BE_BOOLEAN', 'pieceIds' => 'REQUIRED'], $fields(['pieceIds' => [], 'format' => 'a5', 'showPrice' => 'oui']));
        $this->assertSame(['pieceIds' => 'MUST_BE_DISTINCT_POSITIVE'], $fields(['pieceIds' => [$a, $a]]));
        $this->assertSame(['pieceIds' => 'MUST_BE_DISTINCT_POSITIVE'], $fields(['pieceIds' => [$a, '12']]));
        $this->assertSame(['pieceIds' => 'MUST_BE_DISTINCT_POSITIVE'], $fields(['pieceIds' => [3000000000]]));
        $this->assertSame(['pieceIds' => 'REQUIRED'], $fields(['pieceIds' => ['a' => $a]]));
        $refusal = fn (array $body, int $status, string $path = self::PIECES): array => $this->json($this->admin('POST', $path . '/etiquettes/pdf', $body), $status);
        $tooMany = $refusal(['pieceIds' => range(1, 201), 'format' => 'roll-50x30'], 422);
        $this->assertSame(['LABELS_LIMIT_EXCEEDED', 200, 201, 'Au plus 200 étiquettes par impression dans ce format.'], [$tooMany['error'], $tooMany['max'], $tooMany['requested'], $tooMany['message']]);
        $this->assertSame(['LABELS_LIMIT_EXCEEDED', 500], [$refusal(['pieceIds' => range(1, 501)], 422)['error'], $refusal(['pieceIds' => range(1, 501)], 422)['max']]);
        $this->assertSame(['PIECES_NOT_FOUND', [2000000000]], [$refusal(['pieceIds' => [$a, 2000000000]], 422)['error'], $refusal(['pieceIds' => [$a, 2000000000]], 422)['pieceIds']]);
        $missing = $refusal(['pieceIds' => [$a, $bare['id']]], 409);
        $this->assertSame(['BARCODE_MISSING', [$bare['id']], [$v2]], [$missing['error'], $missing['pieceIds'], $missing['variantIds']]);
        $this->json($this->admin('POST', self::PIECES . "/$c/retirer", ['reason' => 'damaged', 'confirm' => true]));
        $before = $rows();
        $gone = $refusal(['pieceIds' => [$a, $c]], 409);
        $this->assertSame(['PIECE_RETIRED', [$c]], [$gone['error'], $gone['pieceIds']]);

        // Pièces d'exercice : leur propre route (ouverte sans le droit du module), leur propre mention ; les deux mondes ne se croisent pas
        $exercise = $this->json($this->admin('POST', self::EX . '/pieces', ['variantId' => $v1, 'count' => 1]), 201)['pieces'][0];
        $before = $rows();
        $this->assertSame('Exercice n° ' . $exercise['number'], $this->labels()->selection(['pieceIds' => [$exercise['id']]], 'fr', true)['labels'][0]['note']);
        $this->assertSame(['PIECES_NOT_FOUND', [$exercise['id']]], [$refusal(['pieceIds' => [$exercise['id']]], 422)['error'], $refusal(['pieceIds' => [$exercise['id']]], 422)['pieceIds']]);
        $this->assertSame('PIECES_NOT_FOUND', $refusal(['pieceIds' => [$a]], 422, self::EX . '/pieces')['error']);
        static::getContainer()->get(PlatformModeService::class)->setMode('live', 'test');
        $this->flags(true, false);
        $this->admin('POST', self::EX . '/pieces/etiquettes/pdf', ['pieceIds' => [$exercise['id']], 'format' => 'roll-50x30']);
        $this->assertSame([200, 'application/pdf', '1', 'roll-50x30'], $headers());
        $closed = $this->admin('POST', self::PIECES . '/etiquettes/pdf', ['pieceIds' => [$a]]);
        $this->assertSame([403, 'MODULE_INACTIVE'], [$closed->getStatusCode(), $this->json($closed, 403)['code']]);
        $this->assertSame(401, $this->call('POST', self::EX . '/pieces/etiquettes/pdf', ['pieceIds' => [$exercise['id']]], null)->getStatusCode());

        // Une impression est une lecture : aucune ligne écrite
        $this->assertSame($before, $rows());
    }

    public function testTheInstallationAssistantFollowsTheDataAndNothingElse(): void
    {
        [, [$v1]] = $this->article('Gilet', 3000, ["GILET-$this->s"]);
        $rows = fn (): array => array_map('intval', $this->db->fetchNumeric('SELECT (SELECT COUNT(*) FROM loss_piece), (SELECT COUNT(*) FROM loss_tag), (SELECT COUNT(*) FROM loss_piece_event), (SELECT COUNT(*) FROM loss_journal)'));
        $statuses = fn (array $installation): array => array_column($installation['steps'], 'status', 'code');
        $events = fn (string $type, bool $exercise): int => (int) $this->db->fetchOne('SELECT COUNT(*) FROM loss_piece_event WHERE type = ? AND exercise = CAST(? AS boolean)', [$type, $exercise ? 'true' : 'false']);

        // ----- En exercice, depuis zéro (rien d'exercice ne reste d'un test à l'autre)
        $before = $rows();
        $start = $this->json($this->admin('GET', self::EX . '/installation'));
        $this->assertSame(['hardware' => 'current', 'test_label' => 'todo', 'test_tag' => 'todo', 'first_piece' => 'todo'], $statuses($start));
        $this->assertSame([['done' => 0, 'total' => 4], false, true, 'pertes.installation'], [$start['progress'], $start['complete'], $start['exercise'], $start['screen']]);
        $this->assertSame(['code' => 'hardware', 'title' => 'Choisir son matériel', 'image' => 'pertes/installation/hardware', 'status' => 'current', 'verify' => ['kind' => 'none', 'label' => 'Voir la gamme']],
            array_diff_key($start['steps'][0], ['text' => 0]));
        $this->assertSame(['kind' => 'data', 'label' => 'Vérifier'], $start['steps'][2]['verify']);
        $this->assertSame('Read a test tag', $this->json($this->admin('GET', self::EX . '/installation?locale=en'))['steps'][2]['title']);
        // « Vérifier » relit les données : pas faite, avec quoi essayer ; étape inconnue ; rien n'est écrit par ces lectures
        $verify = $this->json($this->admin('POST', self::EX . '/installation/etapes/test_tag/verifier', []));
        $this->assertSame(['test_tag', false], [$verify['code'], $verify['verified']]);
        $this->assertStringContainsString('Aucune puce n\'a encore été lue', $verify['hint']);
        $this->assertSame('STEP_NOT_FOUND', $this->json($this->admin('POST', self::EX . '/installation/etapes/antenne/verifier', []), 404)['error']);
        $this->assertSame($before, $rows(), 'lire l\'assistant et vérifier n\'écrivent rien');

        // 1. L'étiquette d'essai : l'étape est faite, et l'étape d'information qui la précède aussi
        $this->admin('POST', self::EX . '/installation/etiquette-test', ['format' => 'roll-50x30']);
        $this->assertSame([200, 'application/pdf', 'roll-50x30', 1], [$this->client->getResponse()->getStatusCode(), $this->client->getResponse()->headers->get('Content-Type'),
            $this->client->getResponse()->headers->get('X-Labels-Format'), preg_match_all('#/Type\s*/Page\b#', (string) $this->client->getResponse()->getContent())]);
        $this->assertSame(['format' => 'roll-50x30'], json_decode((string) $this->db->fetchOne("SELECT data FROM loss_piece_event WHERE type = 'TEST_LABEL_PRINTED' AND exercise ORDER BY id DESC LIMIT 1"), true));
        $this->assertSame(['format' => 'INVALID_VALUE'], $this->json($this->admin('POST', self::EX . '/installation/etiquette-test', ['format' => 'a5']), 422)['fields']);
        $this->assertSame(['hardware' => 'done', 'test_label' => 'done', 'test_tag' => 'current', 'first_piece' => 'todo'], $statuses($this->json($this->admin('GET', self::EX . '/installation'))));
        // 2. La lecture d'essai simulée : la puce est connue, pas encore posée
        $read = $this->json($this->admin('POST', self::EX . '/installation/puce-test', []));
        $this->assertMatchesRegularExpression('/\ASIM-[0-9A-F]{12}\z/', $read['identifier']);
        $this->assertSame([true, 'simulated', null, null, 'known', 'Lue, pas encore posée', null, true], [$read['ok'], $read['technology'], $read['refusal'], $read['hint'], $read['tag']['status'], $read['tag']['statusLabel'],
            $read['tag']['pieceId'], $read['tag']['exercise']]);
        $this->assertSame(['hardware' => 'done', 'test_label' => 'done', 'test_tag' => 'done', 'first_piece' => 'current'], $statuses($read['installation']), 'la réponse rend l\'assistant à jour');
        $this->assertSame(['ok' => false, 'refusal' => 'TAG_INVALID'], array_intersect_key($this->json($this->admin('POST', self::EX . '/installation/puce-test', ['identifier' => '04A1B2C3'])), ['ok' => 0, 'refusal' => 0]), 'une vraie lecture n\'est pas une puce simulée');
        // 3. La première pièce : la puce lue à l'essai se pose (elle était connue), l'installation est complète
        $posed = $this->json($this->admin('POST', self::EX . '/puces/associer', ['variantId' => $v1, 'identifier' => $read['identifier']]));
        $this->assertSame([true, $read['tag']['id'], 'associated'], [$posed['created'], $posed['tag']['id'], $posed['tag']['status']]);
        $done = $this->json($this->admin('GET', self::EX . '/installation'));
        $this->assertSame([['hardware' => 'done', 'test_label' => 'done', 'test_tag' => 'done', 'first_piece' => 'done'], ['done' => 4, 'total' => 4], true], [$statuses($done), $done['progress'], $done['complete']]);
        $this->assertSame(['code' => 'first_piece', 'verified' => true, 'hint' => null], $this->json($this->admin('POST', self::EX . '/installation/etapes/first_piece/verifier', [])));
        $again = $this->json($this->admin('POST', self::EX . '/installation/puce-test', ['identifier' => strtolower($read['identifier'])]));
        $this->assertSame([true, 'associated'], [$again['ok'], $again['tag']['status']]);
        $this->assertStringContainsString('déjà posée', (string) $again['hint']);
        $this->assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM loss_tag WHERE identifier = ?', [$read['identifier']]), 'relire une puce connue ne crée pas une seconde ligne');
        // Plafonds des essais d'exercice (routes ouvertes sans le droit du module)
        $this->db->executeStatement("INSERT INTO loss_piece_event (type, occurred_at, source, exercise, data) SELECT 'TEST_TAG_READ', NOW(), 'exercise', true, '{}' FROM generate_series(1, CAST(? AS integer))",
            [LossInstallationService::EXERCISE_TEST_LIMIT - $events('TEST_TAG_READ', true)]);
        $capped = $this->json($this->admin('POST', self::EX . '/installation/puce-test', []), 409);
        $this->assertSame(['EXERCISE_LIMIT_REACHED', 60], [$capped['error'], $capped['limit']]);
        $this->db->executeStatement("INSERT INTO loss_piece_event (type, occurred_at, source, exercise, data) SELECT 'TEST_LABEL_PRINTED', NOW(), 'exercise', true, '{}' FROM generate_series(1, CAST(? AS integer))",
            [LossInstallationService::EXERCISE_TEST_LIMIT - $events('TEST_LABEL_PRINTED', true)]);
        $this->assertSame('EXERCISE_LIMIT_REACHED', $this->json($this->admin('POST', self::EX . '/installation/etiquette-test', []), 409)['error']);

        // ----- Le monde réel : l'exercice ne l'a pas fait avancer d'un pas ; il suit ses propres faits
        $real = fn (): array => $statuses($this->json($this->admin('GET', self::INSTALL)));
        $expected = function () use ($events): array {
            $done = ['test_label' => $events('TEST_LABEL_PRINTED', false) > 0, 'test_tag' => $events('TEST_TAG_READ', false) > 0, 'first_piece' => $events('TAG_ASSOCIATED', false) > 0];
            $done = ['hardware' => in_array(true, $done, true)] + $done;
            $out = [];
            $current = false;
            foreach ($done as $code => $ok) {
                $out[$code] = $ok ? 'done' : ($current ? 'todo' : 'current');
                $current = $current || !$ok;
            }

            return $out;
        };
        $this->assertSame($expected(), $real(), 'les étapes réelles se lisent dans les vrais faits, pas dans ceux de l\'exercice');
        $this->assertFalse($this->json($this->admin('GET', self::INSTALL))['exercise']);
        // Lectures refusées : rien n'est écrit, et on dit quoi essayer
        $before = $rows();
        $bad = fn (array $body): array => $this->json($this->admin('POST', self::INSTALL . '/puce-test', $body));
        $decimal = $bad(['identifier' => '0012345678', 'technology' => 'nfc']);
        $this->assertSame([false, null, 'TAG_INVALID', null], [$decimal['ok'], $decimal['identifier'], $decimal['refusal'], $decimal['tag']]);
        $this->assertStringContainsString('en chiffres seulement', $decimal['hint']);
        $this->assertStringContainsString('autre chose que des chiffres', $bad(['identifier' => 'bonjour', 'technology' => 'nfc'])['hint']);
        $this->assertStringContainsString('longueur d\'une puce NFC', $bad(['identifier' => '04A1B2', 'technology' => 'nfc'])['hint']);
        $this->assertStringContainsString('16 à 64 caractères', $bad(['identifier' => '04A1B2C3', 'technology' => 'uhf'])['hint']);
        $asBarcode = $bad(['identifier' => "gilet-$this->s", 'technology' => 'nfc']);
        $this->assertSame('TAG_INVALID', $asBarcode['refusal'], 'un code-barres qui n\'a pas la forme d\'une puce : refusé pour sa forme');
        $hexCode = 'ABCDEF02' . $this->s . 'AA';
        $this->db->executeStatement('UPDATE product_variant SET barcode = ? WHERE id = ?', [$hexCode, $v1]);
        $isBarcode = $bad(['identifier' => $hexCode, 'technology' => 'uhf']);
        $this->assertSame([false, 'TAG_IS_BARCODE'], [$isBarcode['ok'], $isBarcode['refusal']]);
        $this->assertStringContainsString("Gilet L2B-$this->s", $isBarcode['hint']);
        $this->db->executeStatement('UPDATE product_variant SET barcode = ? WHERE id = ?', ["GILET-$this->s", $v1]);
        $this->assertSame(['technology' => 'REQUIRED', 'identifier' => 'REQUIRED'], $this->json($this->admin('POST', self::INSTALL . '/puce-test', []), 422)['fields']);
        $this->assertSame(['technology' => 'INVALID_VALUE', 'identifier' => 'MUST_BE_STRING'], $this->json($this->admin('POST', self::INSTALL . '/puce-test', ['identifier' => 12, 'technology' => 'simulated']), 422)['fields']);
        $this->assertSame($before, $rows(), 'aucune lecture refusée n\'a écrit');
        // Une vraie lecture : la puce est enregistrée « lue, pas encore posée », un fait prouve l'étape ; la relire n'ajoute pas de puce
        $nfc = '0E' . $this->s;
        $tagRead = $bad(['identifier' => ' 0e:' . strtolower($this->s) . ' ', 'technology' => 'nfc']);
        $this->assertSame([true, $nfc, 'nfc', null, null, 'known', false], [$tagRead['ok'], $tagRead['identifier'], $tagRead['technology'], $tagRead['refusal'], $tagRead['hint'], $tagRead['tag']['status'], $tagRead['tag']['exercise']]);
        $this->assertSame('done', $statuses($tagRead['installation'])['test_tag']);
        $this->assertSame([$this->adminId, $tagRead['tag']['id'], ['technology' => 'nfc']], [(int) $this->db->fetchOne('SELECT user_id FROM loss_piece_event WHERE tag_id = ?', [$tagRead['tag']['id']]),
            (int) $this->db->fetchOne('SELECT tag_id FROM loss_piece_event WHERE tag_id = ?', [$tagRead['tag']['id']]), json_decode((string) $this->db->fetchOne('SELECT data FROM loss_piece_event WHERE tag_id = ?', [$tagRead['tag']['id']]), true)]);
        $bad(['identifier' => $nfc, 'technology' => 'nfc']);
        $this->assertSame([1, 2], [(int) $this->db->fetchOne('SELECT COUNT(*) FROM loss_tag WHERE identifier = ?', [$nfc]), (int) $this->db->fetchOne('SELECT COUNT(*) FROM loss_piece_event WHERE tag_id = ?', [$tagRead['tag']['id']])]);
        $this->assertSame(['code' => 'test_tag', 'verified' => true, 'hint' => null], $this->json($this->admin('POST', self::INSTALL . '/etapes/test_tag/verifier', [])));
        // L'étiquette d'essai réelle, puis la première pièce : la puce de l'essai se pose sur elle
        $labelsBefore = $events('TEST_LABEL_PRINTED', false);
        $this->admin('POST', self::INSTALL . '/etiquette-test', []);
        $this->assertSame([200, 'a4-50x30', $labelsBefore + 1], [$this->client->getResponse()->getStatusCode(), $this->client->getResponse()->headers->get('X-Labels-Format'), $events('TEST_LABEL_PRINTED', false)]);
        $piece = $this->json($this->admin('POST', self::TAGS . '/associer', ['barcode' => "GILET-$this->s", 'identifier' => $nfc, 'technology' => 'nfc']));
        $this->assertSame([true, $tagRead['tag']['id'], 'associated'], [$piece['created'], $piece['tag']['id'], $piece['tag']['status']]);
        $complete = $this->json($this->admin('GET', self::INSTALL));
        $this->assertSame([['hardware' => 'done', 'test_label' => 'done', 'test_tag' => 'done', 'first_piece' => 'done'], true, ['done' => 4, 'total' => 4]], [$statuses($complete), $complete['complete'], $complete['progress']]);
        $posedRead = $bad(['identifier' => $nfc, 'technology' => 'nfc']);
        $this->assertSame([true, 'associated'], [$posedRead['ok'], $posedRead['tag']['status']]);
        $this->assertStringContainsString('prenez-en une libre', (string) $posedRead['hint']);

        // Droits : administrateur ; sans le droit du module, seul l'assistant d'exercice s'ouvre ; effacer l'exercice le remet à zéro
        $this->assertSame(401, $this->call('GET', self::INSTALL, null, null)->getStatusCode());
        static::getContainer()->get(PlatformModeService::class)->setMode('live', 'test');
        $this->flags(true, false);
        foreach ([['GET', self::INSTALL], ['POST', self::INSTALL . '/etapes/test_tag/verifier'], ['POST', self::INSTALL . '/puce-test'], ['POST', self::INSTALL . '/etiquette-test'], ['GET', self::BASE . '/materiel']] as [$method, $path]) {
            $closed = $this->admin($method, $path, $method === 'GET' ? null : ['identifier' => '04A1B2C3', 'technology' => 'nfc']);
            $this->assertSame([403, 'MODULE_INACTIVE'], [$closed->getStatusCode(), $this->json($closed, 403)['code']], "$method $path");
        }
        $this->assertSame(true, $this->json($this->admin('GET', self::EX . '/installation'))['complete']);
        $this->assertSame(401, $this->call('POST', self::EX . '/installation/puce-test', [], null)->getStatusCode());
        $this->json($this->admin('DELETE', self::EX . '/pieces'));
        $this->assertSame([['hardware' => 'current', 'test_label' => 'todo', 'test_tag' => 'todo', 'first_piece' => 'todo'], 0], [$statuses($this->json($this->admin('GET', self::EX . '/installation'))),
            (int) $this->db->fetchOne('SELECT COUNT(*) FROM loss_piece_event WHERE exercise')], 'l\'effacement emporte aussi les essais de l\'assistant');
        $this->flags(true, true);
        $this->assertSame(true, $this->json($this->admin('GET', self::INSTALL))['complete'], 'l\'installation réelle n\'a pas bougé');
    }

    public function testTheHardwareRangeHasThreeLevelsForEachNeedAndNoPrice(): void
    {
        $fr = $this->json($this->admin('GET', self::BASE . '/materiel'));
        $this->assertSame([['read', 'print', 'attach'], ['Lire une puce', 'Imprimer l\'étiquette', 'Poser la puce'], 'pertes.installation'], [array_column($fr['needs'], 'code'), array_column($fr['needs'], 'label'), $fr['screen']]);
        $this->assertSame([['pro', 'standard', 'diy'], ['Pro', 'Standard', 'Bricoleur'], [true, true, false]], [array_column($fr['levels'], 'code'), array_column($fr['levels'], 'label'), array_column($fr['levels'], 'soldWithHardware')],
            'le niveau bricoleur se vend sans matériel');
        $this->assertStringContainsString('sans matériel', $fr['note']);
        foreach ($fr['needs'] as $need) {
            $this->assertSame(['pro', 'standard', 'diy'], array_column($need['levels'], 'level'), $need['code']);
            foreach ($need['levels'] as $level) {
                $this->assertSame(['level', 'label', 'technology', 'what', 'allows', 'limits'], array_keys($level));
                foreach (['what', 'allows', 'limits'] as $key) {
                    $this->assertGreaterThan(10, mb_strlen($level[$key]), "{$need['code']}.{$level['level']}.$key");
                    $this->assertStringNotContainsString('hardware.', $level[$key], 'un texte, pas une clé');
                    $this->assertDoesNotMatchRegularExpression('/\d\s?(\$|€|dollars|euros)/u', $level[$key], 'aucun prix dans la gamme');
                }
            }
        }
        $this->assertSame(['uhf', 'uhf', 'nfc'], array_column($fr['needs'][0]['levels'], 'technology'));
        $this->assertSame([null, null, null], array_column($fr['needs'][1]['levels'], 'technology'));
        $en = $this->json($this->admin('GET', self::BASE . '/materiel?locale=en'));
        $this->assertSame(['Reading a tag', 'Do it yourself'], [$en['needs'][0]['label'], $en['levels'][2]['label']]);
        $this->assertNotSame($fr['needs'][0]['levels'][0]['what'], $en['needs'][0]['levels'][0]['what']);
        // Ouverte en exercice, sans le droit du module (démonstration avant achat) ; même contenu
        static::getContainer()->get(PlatformModeService::class)->setMode('live', 'test');
        $this->flags(true, false);
        $this->assertSame($fr, $this->json($this->admin('GET', self::EX . '/materiel')));
        $this->assertSame(403, $this->admin('GET', self::BASE . '/materiel')->getStatusCode());
        $this->assertSame(401, $this->call('GET', self::EX . '/materiel', null, null)->getStatusCode());
    }

    private function labels(): LossPieceLabelService
    {
        $this->tenant();

        return static::getContainer()->get(LossPieceLabelService::class);
    }

    /**
     * Article du test, une variante par code-barres donné (null : sans code).
     *
     * @param list<?string> $variantBarcodes
     *
     * @return array{int, list<int>}
     */
    private function article(string $name, int $price, array $variantBarcodes): array
    {
        $slug = 'l2b-' . bin2hex(random_bytes(4));
        $this->db->executeStatement("INSERT INTO product (id, name, price, isbestseller, quantity, created_at, slug, product_type, is_web, is_pos) VALUES (nextval('product_id_seq'), :n, :p, false, 0, NOW(), :s, 'standard', false, true)",
            ['n' => "$name L2B-$this->s", 'p' => $price, 's' => $slug]);
        $product = (int) $this->db->fetchOne('SELECT id FROM product WHERE slug = ?', [$slug]);
        $ids = [];
        foreach ($variantBarcodes as $barcode) {
            $ids[] = (int) $this->db->fetchOne("INSERT INTO product_variant (id, product_id, stock_quantity, barcode) VALUES (nextval('product_variant_id_seq'), ?, 5, ?) RETURNING id", [$product, $barcode]);
        }

        return [$product, $ids];
    }
}
