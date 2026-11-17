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
    private const SALE = '/api/platform/pertes/materiel';

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
        $this->assertMatchesRegularExpression('#/MediaBox\s*\[0\.0+ 0\.0+ 141\.7\d* 85\.0\d*\]#', (string) $this->client->getResponse()->getContent(), 'la page a la taille de l\'étiquette (50 × 30 mm en points), pas celle d\'une feuille A4');
        // Ce qui part à l'impression : le code-barres de la variante, le numéro de la pièce, le prix seulement si on le demande
        $labels = $this->labels()->selection(['pieceIds' => [$b, $a], 'showPrice' => true], 'fr', false);
        $this->assertSame('a4-50x30', $labels['format']);
        $this->assertSame([['title' => "Manteau L2B-$this->s", 'subtitle' => null, 'barcode' => "MANTEAU-A-$this->s", 'copies' => 1, 'note' => 'Pièce n° ' . $pieces[$b]['number']],
            ['title' => "Manteau L2B-$this->s", 'subtitle' => null, 'barcode' => "MANTEAU-A-$this->s", 'copies' => 1, 'note' => 'Pièce n° ' . $pieces[$a]['number']]],
            array_map(fn (array $l): array => array_diff_key($l, ['price' => 0]), $labels['labels']), 'dans l\'ordre demandé');
        $this->assertMatchesRegularExpression('/^55,00/', (string) $labels['labels'][0]['price'], 'le prix propre de la variante');
        $english = $this->labels()->selection(['pieceIds' => [$a], 'format' => 'roll-50x30'], 'en', false);
        $this->assertSame(['roll-50x30', null, 'Piece no. ' . $pieces[$a]['number']], [$english['format'], $english['labels'][0]['price'], $english['labels'][0]['note']]);
        // Le prix : celui de la variante, sinon celui de l'article ; un prix à zéro vaut « pas de prix »
        $this->db->executeStatement('UPDATE product_variant SET price = NULL WHERE id = ?', [$v1]);
        $this->assertMatchesRegularExpression('/^42,00/', (string) $this->labels()->selection(['pieceIds' => [$a], 'showPrice' => true, 'format' => 'roll-50x30'], 'fr', false)['labels'][0]['price'], 'le prix de l\'article');
        $this->db->executeStatement('UPDATE product SET price = 0 WHERE id = (SELECT product_id FROM product_variant WHERE id = ?)', [$v1]);
        $this->assertNull($this->labels()->selection(['pieceIds' => [$a], 'showPrice' => true], 'fr', false)['labels'][0]['price']);
        $this->db->executeStatement('UPDATE product_variant SET price = 5500 WHERE id = ?', [$v1]);
        // Le numéro de la pièce est bien imprimé, et la première étiquette seule ne commence pas par un saut de page
        $printer = static::getContainer()->get(\App\Services\BarcodeService\BarcodeLabelPrinter::class);
        $html = $printer->html($labels['labels'], 'x', 'roll-50x30');
        $this->assertSame([2, 1, 1, 1], [substr_count($html, 'class="label"'), substr_count($html, 'id="first-label"'), substr_count($html, 'Pièce n° ' . $pieces[$a]['number'] . '<'), substr_count($html, 'Pièce n° ' . $pieces[$b]['number'] . '<')]);
        $this->assertSame([200, 500], [$printer::maxLabels('roll-50x30'), $printer::maxLabels()]);
        try {
            $printer->html([['title' => 'x', 'subtitle' => null, 'barcode' => 'X1', 'price' => null, 'copies' => 201]], 'x', 'roll-50x30');
            $this->fail('plus de 200 étiquettes en rouleau');
        } catch (\LengthException) {
            $this->addToAssertionCount(1);
        }
        $this->assertStringContainsString('class="label"', $printer->html([['title' => 'x', 'subtitle' => null, 'barcode' => 'X1', 'price' => null, 'copies' => 201]], 'x'), 'la planche A4 garde son plafond de 500');

        // Refus : rien n'est imprimé
        $fields = fn (array $body): array => $this->json($this->admin('POST', self::PIECES . '/etiquettes/pdf', $body), 422)['fields'];
        $this->assertSame(['pieceIds' => 'REQUIRED'], $fields([]));
        $this->assertSame(['format' => 'INVALID_VALUE', 'showPrice' => 'MUST_BE_BOOLEAN', 'pieceIds' => 'REQUIRED'], $fields(['pieceIds' => [], 'format' => 'a5', 'showPrice' => 'oui']));
        $this->assertSame(['pieceIds' => 'MUST_BE_DISTINCT_POSITIVE'], $fields(['pieceIds' => [$a, $a]]));
        $this->assertSame(['pieceIds' => 'MUST_BE_DISTINCT_POSITIVE'], $fields(['pieceIds' => [$a, '12']]));
        $this->assertSame(['pieceIds' => 'MUST_BE_DISTINCT_POSITIVE'], $fields(['pieceIds' => [3000000000]]));
        $this->assertSame(['pieceIds' => 'REQUIRED'], $fields(['pieceIds' => ['a' => $a]]));
        $this->assertSame(['pieceIds' => 'MUST_BE_DISTINCT_POSITIVE'], $fields(['pieceIds' => [0]]));
        $this->assertSame(['pieceIds' => 'MUST_BE_DISTINCT_POSITIVE'], $fields(['pieceIds' => [-1]]));
        $this->assertSame(['format' => 'INVALID_VALUE'], $fields(['pieceIds' => [$a], 'format' => ['a4-50x30']]));
        $refusal = fn (array $body, int $status, string $path = self::PIECES): array => $this->json($this->admin('POST', $path . '/etiquettes/pdf', $body), $status);
        $tooMany = $refusal(['pieceIds' => range(1, 201), 'format' => 'roll-50x30'], 422);
        $this->assertSame(['LABELS_LIMIT_EXCEEDED', 200, 201, 'Au plus 200 étiquettes par impression dans ce format.'], [$tooMany['error'], $tooMany['max'], $tooMany['requested'], $tooMany['message']]);
        $this->assertSame(['LABELS_LIMIT_EXCEEDED', 500], [$refusal(['pieceIds' => range(1, 501)], 422)['error'], $refusal(['pieceIds' => range(1, 501)], 422)['max']]);
        // … exactement au plafond, la demande passe le plafond (ces pièces n'existent pas : c'est ce que dit le refus)
        $this->assertSame('PIECES_NOT_FOUND', $refusal(['pieceIds' => range(2000000001, 2000000200), 'format' => 'roll-50x30'], 422)['error']);
        $this->assertSame('PIECES_NOT_FOUND', $refusal(['pieceIds' => range(2000000001, 2000000500)], 422)['error']);
        $this->assertSame(['PIECES_NOT_FOUND', [2000000000]], [$refusal(['pieceIds' => [$a, 2000000000]], 422)['error'], $refusal(['pieceIds' => [$a, 2000000000]], 422)['pieceIds']]);
        $missing = $refusal(['pieceIds' => [$a, $bare['id']]], 409);
        $this->assertSame(['BARCODE_MISSING', [$bare['id']], [$v2]], [$missing['error'], $missing['pieceIds'], $missing['variantIds']]);
        // Une pièce dont la variante n'est plus au catalogue n'a plus de code à imprimer
        $this->db->executeStatement('DELETE FROM product_variant WHERE id = ?', [$v2]);
        $this->assertSame(['BARCODE_MISSING', [$bare['id']]], [$refusal(['pieceIds' => [$bare['id']]], 409)['error'], $refusal(['pieceIds' => [$bare['id']]], 409)['pieceIds']]);
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
        $exercise = fn (): array => $this->json($this->admin('GET', self::EX . '/installation'));
        // L'assistant réel et ce que les seules données réelles en disent (preuves des essais, pièce qui porte une puce)
        $real = fn (): array => $statuses($this->json($this->admin('GET', self::INSTALL)));
        $expected = function () use ($events): array {
            $done = ['hardware' => false, 'test_label' => $events('TEST_LABEL_PRINTED', false) > 0, 'test_tag' => $events('TEST_TAG_READ', false) > 0,
                'first_piece' => (bool) $this->db->fetchOne("SELECT 1 FROM loss_tag t JOIN loss_piece p ON p.id = t.piece_id WHERE t.status = 'associated' AND p.status <> 'retired' AND NOT t.exercise LIMIT 1")];
            $codes = array_keys($done);
            for ($i = count($codes) - 2; $i >= 0; --$i) {
                $done[$codes[$i]] = $done[$codes[$i]] || $done[$codes[$i + 1]];
            }
            $out = [];
            $current = false;
            foreach ($done as $code => $ok) {
                $out[$code] = $ok ? 'done' : ($current ? 'todo' : 'current');
                $current = $current || !$ok;
            }

            return $out;
        };

        // ----- En exercice, depuis zéro (rien d'exercice ne reste d'un test à l'autre)
        $before = $rows();
        $start = $exercise();
        $this->assertSame(['hardware' => 'current', 'test_label' => 'todo', 'test_tag' => 'todo', 'first_piece' => 'todo'], $statuses($start));
        $this->assertSame([['done' => 0, 'total' => 4], false, true, 'pertes.installation'], [$start['progress'], $start['complete'], $start['exercise'], $start['screen']]);
        $this->assertSame(['code' => 'hardware', 'title' => 'Choisir son matériel', 'image' => 'pertes/installation/hardware', 'status' => 'current', 'verify' => ['kind' => 'none', 'label' => 'Voir la gamme']],
            array_diff_key($start['steps'][0], ['text' => 0]));
        $this->assertSame([['kind' => 'data', 'label' => 'Vérifier'], ['kind' => 'data', 'label' => 'Vérifier']], [$start['steps'][2]['verify'], $start['steps'][3]['verify']]);
        $this->assertSame('Read a test tag', $this->json($this->admin('GET', self::EX . '/installation?locale=en'))['steps'][2]['title']);
        // « Vérifier » relit les données : pas faite, avec quoi essayer ; étape inconnue ; rien n'est écrit par ces lectures
        $verify = $this->json($this->admin('POST', self::EX . '/installation/etapes/test_tag/verifier', []));
        $this->assertSame(['test_tag', false], [$verify['code'], $verify['verified']]);
        $this->assertStringContainsString('Aucune puce n\'a encore été lue', $verify['hint']);
        $this->assertSame('STEP_NOT_FOUND', $this->json($this->admin('POST', self::EX . '/installation/etapes/antenne/verifier', []), 404)['error']);
        $this->assertSame($before, $rows(), 'lire l\'assistant et vérifier n\'écrivent rien');

        // 1. L'étiquette d'essai : l'étape est faite, et l'étape d'information qui la précède aussi ; la preuve s'écrit UNE fois
        $this->admin('POST', self::EX . '/installation/etiquette-test', ['format' => 'roll-50x30']);
        $this->assertSame([200, 'application/pdf', 'roll-50x30', '1', 1], [$this->client->getResponse()->getStatusCode(), $this->client->getResponse()->headers->get('Content-Type'),
            $this->client->getResponse()->headers->get('X-Labels-Format'), $this->client->getResponse()->headers->get('X-Labels-Count'), preg_match_all('#/Type\s*/Page\b#', (string) $this->client->getResponse()->getContent())]);
        $this->assertSame(['format' => 'roll-50x30'], json_decode((string) $this->db->fetchOne("SELECT data FROM loss_piece_event WHERE type = 'TEST_LABEL_PRINTED' AND exercise ORDER BY id DESC LIMIT 1"), true));
        $this->admin('POST', self::EX . '/installation/etiquette-test', []);
        $this->assertSame([200, 'a4-50x30', 1], [$this->client->getResponse()->getStatusCode(), $this->client->getResponse()->headers->get('X-Labels-Format'), $events('TEST_LABEL_PRINTED', true)], 'une seconde étiquette d\'essai n\'ajoute pas de fait');
        $this->assertSame(['format' => 'INVALID_VALUE'], $this->json($this->admin('POST', self::EX . '/installation/etiquette-test', ['format' => 'a5']), 422)['fields']);
        $this->assertSame(['format' => 'INVALID_VALUE'], $this->json($this->admin('POST', self::EX . '/installation/etiquette-test', ['format' => ['a4-50x30']]), 422)['fields']);
        $this->assertSame(['hardware' => 'done', 'test_label' => 'done', 'test_tag' => 'current', 'first_piece' => 'todo'], $statuses($exercise()));
        // Ce que porte l'étiquette d'essai : un code qui ne peut désigner aucun article (préfixe réservé aux documents de la caisse)
        $this->tenant();
        $row = static::getContainer()->get(LossInstallationService::class)->testLabelRow('fr');
        $this->assertSame(['Étiquette d\'essai', 'IZ-ESSAI-ETIQUETTE', null, 1, 'Ne désigne aucun article'], [$row['title'], $row['barcode'], $row['price'], $row['copies'], $row['note']]);
        $this->assertSame(\App\Services\BarcodeService\CatalogBarcode::RESERVED, \App\Services\BarcodeService\CatalogBarcode::refusal($row['barcode']), 'le catalogue refuse ce code : aucun article ne le portera');
        // 2. La lecture d'essai simulée : l'étape est prouvée, la puce n'est PAS enregistrée (elle le sera en étant posée)
        $read = $this->json($this->admin('POST', self::EX . '/installation/puce-test', []));
        $this->assertMatchesRegularExpression('/\ASIM-[0-9A-F]{12}\z/', $read['identifier']);
        $this->assertSame([true, 'simulated', null, null, null], [$read['ok'], $read['technology'], $read['refusal'], $read['hint'], $read['tag']]);
        $this->assertSame(['hardware' => 'done', 'test_label' => 'done', 'test_tag' => 'done', 'first_piece' => 'current'], $statuses($read['installation']), 'la réponse rend l\'assistant à jour');
        $this->assertSame([0, ['technology' => 'simulated', 'identifier' => $read['identifier']]], [(int) $this->db->fetchOne('SELECT COUNT(*) FROM loss_tag WHERE identifier = ?', [$read['identifier']]),
            json_decode((string) $this->db->fetchOne("SELECT data FROM loss_piece_event WHERE type = 'TEST_TAG_READ' AND exercise"), true)]);
        $this->json($this->admin('POST', self::EX . '/installation/puce-test', []));
        $this->assertSame([1, 0], [$events('TEST_TAG_READ', true), (int) $this->db->fetchOne('SELECT COUNT(*) FROM loss_tag WHERE exercise')], 'relire n\'ajoute ni fait ni puce : une preuve suffit');
        $notSimulated = $this->json($this->admin('POST', self::EX . '/installation/puce-test', ['identifier' => '04A1B2C3']));
        $this->assertSame([false, 'TAG_INVALID', 'Cette lecture n\'est pas celle d\'une puce simulée.'], [$notSimulated['ok'], $notSimulated['refusal'], $notSimulated['hint']], 'une vraie lecture n\'est pas une puce simulée');
        $this->assertSame(['identifier' => 'MUST_BE_STRING'], $this->json($this->admin('POST', self::EX . '/installation/puce-test', ['identifier' => '   ']), 422)['fields']);
        // 3. La première pièce : la puce lue à l'essai se pose comme une autre ; l'installation est complète
        $posed = $this->json($this->admin('POST', self::EX . '/puces/associer', ['variantId' => $v1, 'identifier' => $read['identifier']]));
        $this->assertSame([true, true, 'associated'], [$posed['created'], $posed['associated'], $posed['tag']['status']]);
        $done = $exercise();
        $this->assertSame([['hardware' => 'done', 'test_label' => 'done', 'test_tag' => 'done', 'first_piece' => 'done'], ['done' => 4, 'total' => 4], true], [$statuses($done), $done['progress'], $done['complete']]);
        $this->assertSame(['code' => 'first_piece', 'verified' => true, 'hint' => null], $this->json($this->admin('POST', self::EX . '/installation/etapes/first_piece/verifier', [])));
        $again = $this->json($this->admin('POST', self::EX . '/installation/puce-test', ['identifier' => strtolower($read['identifier'])]));
        $this->assertSame([true, 'associated', $posed['tag']['id']], [$again['ok'], $again['tag']['status'], $again['tag']['id']]);
        $this->assertStringContainsString('déjà posée', (string) $again['hint']);
        // Une puce retirée ne prouve plus la première pièce (les essais, eux, restent faits) ; la relire dit d'en prendre une libre
        $this->json($this->admin('POST', self::EX . '/puces/' . $posed['tag']['id'] . '/retirer', ['reason' => 'error', 'confirm' => true]));
        $this->assertSame(['hardware' => 'done', 'test_label' => 'done', 'test_tag' => 'done', 'first_piece' => 'current'], $statuses($exercise()));
        $this->assertStringContainsString('Aucune pièce ne porte encore de puce', (string) $this->json($this->admin('POST', self::EX . '/installation/etapes/first_piece/verifier', []))['hint']);
        $retired = $this->json($this->admin('POST', self::EX . '/installation/puce-test', ['identifier' => $read['identifier']]));
        $this->assertSame([true, 'retired'], [$retired['ok'], $retired['tag']['status']]);
        $this->assertStringContainsString('a été retirée', (string) $retired['hint']);
        // Les preuves d'exercice ne comptent que pour l'exercice : l'assistant réel ne lit que les siennes
        $this->assertSame([1, 1, $expected()], [$events('TEST_LABEL_PRINTED', true), $events('TEST_TAG_READ', true), $real()], 'les essais d\'exercice ne font pas avancer l\'installation réelle');
        // Un site qui pose déjà ses puces n'a pas à refaire les essais : une étape suivante faite fait les précédentes
        $this->json($this->admin('DELETE', self::EX . '/pieces'));
        $this->assertSame([['hardware' => 'current', 'test_label' => 'todo', 'test_tag' => 'todo', 'first_piece' => 'todo'], 0], [$statuses($exercise()), (int) $this->db->fetchOne('SELECT COUNT(*) FROM loss_piece_event WHERE exercise')],
            'l\'effacement emporte aussi les essais de l\'assistant');
        $this->json($this->admin('POST', self::EX . '/puces/associer', ['variantId' => $v1]));
        $equipped = $exercise();
        $this->assertSame([['hardware' => 'done', 'test_label' => 'done', 'test_tag' => 'done', 'first_piece' => 'done'], true, 0], [$statuses($equipped), $equipped['complete'], $events('TEST_TAG_READ', true)]);

        // ----- Le monde réel : l'exercice ne l'a pas fait avancer d'un pas ; il suit ses propres données
        $this->assertSame($expected(), $real(), 'les étapes réelles se lisent dans les vraies données, pas dans celles de l\'exercice');
        $this->assertFalse($this->json($this->admin('GET', self::INSTALL))['exercise']);
        // Lectures refusées : rien n'est écrit, et on dit quoi essayer
        $before = $rows();
        $bad = fn (array $body): array => $this->json($this->admin('POST', self::INSTALL . '/puce-test', $body));
        $decimal = $bad(['identifier' => '0012345678', 'technology' => 'nfc']);
        $this->assertSame([false, null, 'TAG_INVALID', null], [$decimal['ok'], $decimal['identifier'], $decimal['refusal'], $decimal['tag']]);
        $this->assertStringContainsString('en chiffres seulement', $decimal['hint']);
        $this->assertStringContainsString('autre chose que des chiffres', $bad(['identifier' => 'bonjour', 'technology' => 'nfc'])['hint']);
        $this->assertStringContainsString('longueur d\'une puce NFC', $bad(['identifier' => '04A1B2', 'technology' => 'nfc'])['hint']);
        $this->assertStringContainsString('par groupes de 4', $bad(['identifier' => '04A1B2C3', 'technology' => 'uhf'])['hint']);
        // Le code-barres d'un article lu à la place de la puce : on le dit, que la lecture ait ou non la forme d'une puce
        $asBarcode = $bad(['identifier' => " gilet-$this->s ", 'technology' => 'nfc']);
        $this->assertSame([false, 'TAG_IS_BARCODE'], [$asBarcode['ok'], $asBarcode['refusal']], 'un code-barres qui n\'a pas la forme d\'une puce');
        $this->assertStringContainsString("Gilet L2B-$this->s", $asBarcode['hint']);
        $this->db->executeStatement('UPDATE product_variant SET barcode = ? WHERE id = ?', ['2900000000017', $v1]);
        $this->assertSame('TAG_IS_BARCODE', $bad(['identifier' => '2900000000017', 'technology' => 'uhf'])['refusal'], 'un code généré du catalogue, tout en chiffres : pas le conseil « décimal »');
        $hexCode = 'ABCDEF02' . $this->s . 'AA';
        $this->db->executeStatement('UPDATE product_variant SET barcode = ? WHERE id = ?', [$hexCode, $v1]);
        $this->assertSame('TAG_IS_BARCODE', $bad(['identifier' => implode(':', str_split(strtolower($hexCode), 2)), 'technology' => 'uhf'])['refusal'], 'reconnu sous sa forme nettoyée de puce');
        $this->db->executeStatement('UPDATE product_variant SET barcode = ? WHERE id = ?', ["GILET-$this->s", $v1]);
        $this->assertSame(['technology' => 'REQUIRED', 'identifier' => 'REQUIRED'], $this->json($this->admin('POST', self::INSTALL . '/puce-test', []), 422)['fields']);
        $this->assertSame(['technology' => 'INVALID_VALUE', 'identifier' => 'MUST_BE_STRING'], $this->json($this->admin('POST', self::INSTALL . '/puce-test', ['identifier' => 12, 'technology' => 'simulated']), 422)['fields']);
        $this->assertSame($before, $rows(), 'aucune lecture refusée n\'a écrit');
        // Une vraie lecture : l'étape est prouvée, une fois ; la puce n'est pas enregistrée, sa technologie n'est figée par rien
        $nfc = '0E' . $this->s . '11223344'; // 16 caractères : la longueur valide dans les deux technologies
        $readsBefore = $events('TEST_TAG_READ', false);
        $tagRead = $bad(['identifier' => ' ' . strtolower($nfc) . ' ', 'technology' => 'uhf']);
        $this->assertSame([true, $nfc, 'uhf', null, null, null], [$tagRead['ok'], $tagRead['identifier'], $tagRead['technology'], $tagRead['refusal'], $tagRead['hint'], $tagRead['tag']]);
        $this->assertSame('done', $statuses($tagRead['installation'])['test_tag']);
        $bad(['identifier' => $nfc, 'technology' => 'nfc']);
        $this->assertSame([0, max(1, $readsBefore)], [(int) $this->db->fetchOne('SELECT COUNT(*) FROM loss_tag WHERE identifier = ?', [$nfc]), $events('TEST_TAG_READ', false)], 'aucune puce enregistrée ; une seule preuve, quel que soit le nombre d\'essais');
        if ($readsBefore === 0) {
            $this->assertSame([$this->adminId, ['technology' => 'uhf', 'identifier' => $nfc]], [(int) $this->db->fetchOne("SELECT user_id FROM loss_piece_event WHERE type = 'TEST_TAG_READ' AND NOT exercise"),
                json_decode((string) $this->db->fetchOne("SELECT data FROM loss_piece_event WHERE type = 'TEST_TAG_READ' AND NOT exercise"), true)]);
        }
        $this->assertSame(['code' => 'test_tag', 'verified' => true, 'hint' => null], $this->json($this->admin('POST', self::INSTALL . '/etapes/test_tag/verifier', [])));
        // L'étiquette d'essai réelle, puis la première pièce : la puce de l'essai se pose avec la technologie donnée CE JOUR-LÀ
        $labelsBefore = $events('TEST_LABEL_PRINTED', false);
        $this->admin('POST', self::INSTALL . '/etiquette-test', []);
        $this->admin('POST', self::INSTALL . '/etiquette-test', []);
        $this->assertSame([200, 'a4-50x30', max(1, $labelsBefore)], [$this->client->getResponse()->getStatusCode(), $this->client->getResponse()->headers->get('X-Labels-Format'), $events('TEST_LABEL_PRINTED', false)]);
        $piece = $this->json($this->admin('POST', self::TAGS . '/associer', ['barcode' => "GILET-$this->s", 'identifier' => $nfc, 'technology' => 'nfc']));
        $this->assertSame([true, 'associated', 'nfc', ['technology' => 'nfc']], [$piece['created'], $piece['tag']['status'], $piece['tag']['technology'],
            json_decode((string) $this->db->fetchOne("SELECT data FROM loss_piece_event WHERE tag_id = ? AND type = 'TAG_ASSOCIATED'", [$piece['tag']['id']]), true)], 'la lecture d\'essai en UHF n\'a rien figé');
        $complete = $this->json($this->admin('GET', self::INSTALL));
        $this->assertSame([['hardware' => 'done', 'test_label' => 'done', 'test_tag' => 'done', 'first_piece' => 'done'], true, ['done' => 4, 'total' => 4]], [$statuses($complete), $complete['complete'], $complete['progress']]);
        $posedRead = $bad(['identifier' => $nfc, 'technology' => 'nfc']);
        $this->assertSame([true, 'associated'], [$posedRead['ok'], $posedRead['tag']['status']]);
        $this->assertStringContainsString('prenez-en une libre', (string) $posedRead['hint']);

        // Droits : administrateur ; sans le droit du module, seul l'assistant d'exercice s'ouvre, toutes ses routes comprises
        $this->assertSame(401, $this->call('GET', self::INSTALL, null, null)->getStatusCode());
        $this->loginPos(['ROLE_USER_POS']);
        foreach ([['GET', self::INSTALL], ['POST', self::INSTALL . '/puce-test'], ['POST', self::INSTALL . '/etiquette-test'], ['POST', self::PIECES . '/etiquettes/pdf'], ['GET', self::BASE . '/materiel'], ['POST', self::EX . '/installation/puce-test']] as [$method, $path]) {
            $this->assertSame(403, $this->call($method, $path, $method === 'GET' ? null : [], $this->posToken)->getStatusCode(), "$method $path : un caissier");
        }
        static::getContainer()->get(PlatformModeService::class)->setMode('live', 'test');
        $this->flags(true, false);
        foreach ([['GET', self::INSTALL], ['POST', self::INSTALL . '/etapes/test_tag/verifier'], ['POST', self::INSTALL . '/puce-test'], ['POST', self::INSTALL . '/etiquette-test'], ['GET', self::BASE . '/materiel']] as [$method, $path]) {
            $closed = $this->admin($method, $path, $method === 'GET' ? null : ['identifier' => '04A1B2C3', 'technology' => 'nfc']);
            $this->assertSame([403, 'MODULE_INACTIVE'], [$closed->getStatusCode(), $this->json($closed, 403)['code']], "$method $path");
        }
        $this->assertSame(true, $exercise()['complete']);
        $this->assertSame(true, $this->json($this->admin('POST', self::EX . '/installation/etapes/first_piece/verifier', []))['verified']);
        $this->assertSame(true, $this->json($this->admin('POST', self::EX . '/installation/puce-test', []))['ok']);
        $this->assertSame(200, $this->admin('POST', self::EX . '/installation/etiquette-test', [])->getStatusCode());
        $this->assertSame(401, $this->call('POST', self::EX . '/installation/puce-test', [], null)->getStatusCode());
        $this->flags(true, true);
        $this->assertSame(true, $this->json($this->admin('GET', self::INSTALL))['complete'], 'l\'installation réelle n\'a pas bougé');

        // Les essais d'exercice attendent le verrou des exercices (un effacement en cours ne laisse rien derrière lui)
        $this->json($this->admin('DELETE', self::EX . '/pieces'));
        // Et dans l'autre sens : le réel a ses deux preuves, l'exercice effacé repart de zéro (chaque genre ne compte que ses faits)
        $this->assertSame([true, true, ['hardware' => 'current', 'test_label' => 'todo', 'test_tag' => 'todo', 'first_piece' => 'todo']],
            [$events('TEST_LABEL_PRINTED', false) > 0, $events('TEST_TAG_READ', false) > 0, $statuses($exercise())], 'les preuves réelles ne font pas avancer l\'exercice');
        $service = static::getContainer()->get(LossInstallationService::class);
        // La connexion DU SERVICE (le noyau a été relancé par les requêtes : ce n'est plus celle du début du test) : c'est sur elle que
        // l'attente de verrou doit être bornée. L'autre connexion est coupée d'office par la base si elle reste ouverte : rien ici ne
        // peut tenir le verrou commun des tests
        $conn = $this->tenant()->getEntityManager()->getConnection();
        $other = \Doctrine\DBAL\DriverManager::getConnection($conn->getParams());
        $conn->executeStatement("SET lock_timeout = '1s'");
        try {
            $other->executeStatement("SET idle_in_transaction_session_timeout = '20s'");
            $other->beginTransaction();
            $other->executeStatement('SELECT pg_advisory_xact_lock(' . \App\LossPrevention\Repository\LossPieceRepository::EXERCISE_LOCK . ')');
            foreach (['lecture d\'essai' => fn () => $service->testRead([], 'fr', $this->adminId, true), 'étiquette d\'essai' => fn () => $service->testLabel([], 'fr', $this->adminId, true)] as $what => $write) {
                try {
                    $write();
                    $this->fail("$what : devait attendre le verrou des exercices");
                } catch (\Doctrine\DBAL\Exception $e) {
                    $this->assertStringContainsString('lock timeout', $e->getMessage(), $what);
                }
                $this->assertFalse($conn->isTransactionActive());
            }
            $other->rollBack();
            $this->assertSame(0, (int) $conn->fetchOne('SELECT COUNT(*) FROM loss_piece_event WHERE exercise'));
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

    /**
     * Lot L6-a (17/11/2026, `docs/prevention-pertes.md` § 19) : le matériel vendu par la plateforme. Posé inactif et sans prix ; seul le
     * super administrateur fixe le prix, le lien de la fiche et le met en vente ; les sites le lisent dans la gamme, dans leur langue.
     */
    public function testThePlatformSellsTheHardwareAtThePricesOnlyTheSuperAdminSets(): void
    {
        $master = static::getContainer()->get(\App\Services\TenantConnectionManager::class)->getPdoMaster();
        $saved = $master->query('SELECT code, price_cents, buy_url, is_active FROM loss_hardware_products')->fetchAll(\PDO::FETCH_ASSOC);
        try {
            $platform = static::getContainer()->get('lexik_jwt_authentication.jwt_manager')->create($this->user(['ROLE_ADMIN', 'ROLE_SUPER_ADMIN'], 'Plateforme'));
            $put = fn (string $code, mixed $body, ?string $token): \Symfony\Component\HttpFoundation\Response => $this->call('PUT', self::SALE . "/$code", $body, $token);
            // Au départ : tout est inactif et sans prix ; rien n'est en vente ; les deux offres du module existent, inactives, hors du catalogue public
            $range = $this->json($this->admin('GET', self::BASE . '/materiel'));
            $this->assertSame([[], [false, false, false]], [$range['products'], array_column($range['levels'], 'forSale')]);
            $all = $this->json($this->call('GET', self::SALE, null, $platform))['products'];
            $this->assertSame(['box', 'hand_reader', 'counter_mat', 'zone_antenna', 'printer', 'signal', 'tags'], array_column($all, 'code'));
            $this->assertSame([[false], [null]], [array_values(array_unique(array_column($all, 'isActive'))), array_values(array_unique(array_column($all, 'priceCents')))]);
            $offers = $master->query("SELECT code, is_active, features FROM platform_offers WHERE code LIKE 'loss_prevention%' ORDER BY code")->fetchAll(\PDO::FETCH_ASSOC);
            $this->assertSame([['loss_prevention', false], ['loss_prevention_hardware', false]], array_map(fn (array $o): array => [$o['code'], in_array($o['is_active'], [true, 't', 1, '1'], true)], $offers));
            $this->assertSame([['module.loss_prevention'], ['module.loss_prevention']], array_map(fn (array $o): array => json_decode((string) $o['features'], true), $offers));
            $this->assertSame([], array_values(array_filter(array_column($this->json($this->call('GET', '/api/offers', null, null))['offers'], 'code'), fn (string $c): bool => str_starts_with($c, 'loss_prevention'))));
            // Seul le super administrateur lit et écrit
            $this->assertSame([403, 403, 401], [$this->call('GET', self::SALE, null, $this->adminToken)->getStatusCode(), $put('box', ['priceCents' => 1], $this->adminToken)->getStatusCode(),
                $put('box', ['priceCents' => 1], null)->getStatusCode()]);
            // Ce qu'il refuse
            foreach (['buyUrl' => [['buyUrl' => 'http://boutique.example/boitier'], ['buyUrl' => 'https://moi:secret@boutique.example/b'], ['buyUrl' => 'https:///sans-hote'], ['buyUrl' => 'https://boutique.example/a b']],
                'priceCents' => [['priceCents' => -1], ['priceCents' => 12.5], ['priceCents' => '4900']], 'isActive' => [['isActive' => true], ['isActive' => 'oui']],
                'kind' => [['kind' => 'grille-pain']], 'level' => [['level' => 'diy']], 'currency' => [['currency' => 'cad']], 'labelFr' => [['labelFr' => ' ']], 'bidule' => [['bidule' => 1]]] as $path => $bodies) {
                foreach ($bodies as $body) {
                    $refused = $this->json($put('box', $body, $platform), 422);
                    $this->assertSame(['VALIDATION_FAILED', $path], [$refused['error'], $refused['errors'][0]['path']], json_encode($body));
                }
            }
            $this->assertSame('VALIDATION_FAILED', $this->json($put('box', [1, 2], $platform), 422)['error'], 'une liste n\'est pas un objet');
            $this->assertNull($this->json($this->call('GET', self::SALE, null, $platform))['products'][0]['priceCents'], 'rien n\'a été écrit');
            // Le prix, le lien, la mise en vente : la gamme du site les montre, dans sa langue ; un niveau en vente le dit
            $box = $this->json($put('box', ['priceCents' => 49900, 'buyUrl' => 'https://boutique.example/produits/boitier', 'isActive' => true], $platform))['product'];
            $this->assertSame([49900, 'https://boutique.example/produits/boitier', true, 'Boîtier prêt à brancher'], [$box['priceCents'], $box['buyUrl'], $box['isActive'], $box['labelFr']]);
            $range = $this->json($this->admin('GET', self::BASE . '/materiel'));
            $this->assertSame([['code', 'kind', 'level', 'label', 'description', 'price', 'buyUrl']], array_values(array_unique(array_map('array_keys', $range['products']), SORT_REGULAR)));
            $this->assertSame(['box', 'standard', 'Boîtier prêt à brancher', ['amountCents' => 49900, 'currency' => 'CAD'], 'https://boutique.example/produits/boitier'],
                [$range['products'][0]['code'], $range['products'][0]['level'], $range['products'][0]['label'], array_slice($range['products'][0]['price'], 0, 2), $range['products'][0]['buyUrl']]);
            $this->assertMatchesRegularExpression('/499,00\s?\$/u', $range['products'][0]['price']['display']);
            $this->assertSame([false, true, false], array_column($range['levels'], 'forSale'));
            $en = $this->json($this->admin('GET', self::BASE . '/materiel?locale=en'));
            $this->assertSame(['Plug-and-play box', '$499.00'], [$en['products'][0]['label'], $en['products'][0]['price']['display']]);
            // les exemples du contrat pour le web : la gamme d'un site avec un article en vente, et la liste du super administrateur
            $this->saleExample('gamme-avec-materiel-en-vente', 'GET', '/api/gestion/pertes/materiel', $range);
            $this->saleExample('plateforme-materiel', 'GET', self::SALE, $this->json($this->call('GET', self::SALE, null, $platform)));
            // Un article neuf, sans lien : en vente sans bouton « Acheter » ; retiré de la vente, il disparaît
            $this->json($put('cable_test', ['kind' => 'tags', 'level' => 'pro', 'labelFr' => 'Essai', 'labelEn' => 'Trial', 'priceCents' => 0, 'isActive' => true, 'position' => 5], $platform));
            $range = $this->json($this->admin('GET', self::BASE . '/materiel'));
            $this->assertSame([['cable_test', null], ['box', 'https://boutique.example/produits/boitier']], array_map(fn (array $p): array => [$p['code'], $p['buyUrl']], $range['products']), 'par position');
            $this->assertSame([true, true, false], array_column($range['levels'], 'forSale'));
            $this->json($put('cable_test', ['isActive' => false], $platform));
            $this->assertSame(['box'], array_column($this->json($this->admin('GET', self::BASE . '/materiel'))['products'], 'code'));
        } finally {
            $master->exec("DELETE FROM loss_hardware_products WHERE code = 'cable_test'");
            $restore = $master->prepare('UPDATE loss_hardware_products SET price_cents = ?, buy_url = ?, is_active = ? WHERE code = ?');
            foreach ($saved as $row) {
                $restore->execute([$row['price_cents'], $row['buy_url'], in_array($row['is_active'], [true, 't', 1, '1'], true) ? 'true' : 'false', $row['code']]);
            }
        }
    }

    /**
     * Un exemple JSON du lot L6-a (`tests/Fixtures/pertes/vente/`) : réécrit avec `UPDATE_LOSS_EXAMPLES=1`, sinon sa forme (clés) est comparée.
     *
     * @param array<string, mixed> $body
     */
    private function saleExample(string $name, string $method, string $path, array $body): void
    {
        $file = dirname(__DIR__, 2) . "/Fixtures/pertes/vente/$name.json";
        if (getenv('UPDATE_LOSS_EXAMPLES') === '1') {
            if (!is_dir(dirname($file))) {
                mkdir(dirname($file), 0775, true);
            }
            file_put_contents($file, json_encode(['request' => ['method' => $method, 'path' => $path], 'status' => 200, 'body' => $body], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");

            return;
        }
        $stored = json_decode((string) file_get_contents($file), true);
        $keys = fn (array $b): array => [array_keys($b), array_keys(($b['products'] ?? [])[0] ?? [])];
        $this->assertSame($keys($stored['body']), $keys($body), "forme de l'exemple $name");
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
