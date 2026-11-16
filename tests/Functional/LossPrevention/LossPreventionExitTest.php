<?php

namespace App\Tests\Functional\LossPrevention;

use App\LossPrevention\Entity\LossBox;
use App\LossPrevention\Entity\LossPiece;
use Doctrine\DBAL\ArrayParameterType;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lot L4-b (16/11/2026, `docs/prevention-pertes.md` § 17.13) : les règles de la sortie. Une pièce lue à la sortie qu'aucune vente
 * n'explique (EXIT_WITHOUT_SALE, par pièce et par jour, jamais par personne) ; une sortie rattrapée sur un article pas entièrement
 * étiqueté (EXIT_COVERED, par article et par semaine). Jamais trop tôt, jamais une pièce retirée ni d'exercice ; et « du nouveau
 * depuis la décision » sans dépendre de la seconde.
 */
class LossPreventionExitTest extends LossPreventionTestCase
{
    private const BOXES = self::BASE . '/boitiers';
    private const EX = self::BASE . '/exercices';
    private const MACHINE = 'machine-de-sortie-0123456789abcdef';

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
        // la fenêtre du rattrapage la plus courte (5 minutes) : une lecture vieille de plus de 5 minutes est jugée tout de suite
        $this->db->executeStatement('UPDATE loss_setting SET counter_window_seconds = NULL, catch_up_seconds = 300, presence_retention_days = NULL');
        // le curseur de la sortie repart de zéro : une lecture « trop récente » laissée par un essai précédent l'arrêterait (c'est voulu)
        $this->db->executeStatement('UPDATE loss_setting SET exit_cursor = NULL');
    }

    protected function tearDown(): void
    {
        try {
            while ($this->db->isTransactionActive()) {
                $this->db->rollBack();
            }
            $this->db->executeStatement('UPDATE loss_setting SET catch_up_seconds = NULL');
            // aucune lecture de cet essai n'attend d'être jugée par l'analyse d'un essai suivant (elle y ouvrirait des constats)
            $this->db->executeStatement('UPDATE loss_setting SET exit_cursor = (SELECT COALESCE(MAX(id), 0) FROM loss_piece_event)');
            $this->db->executeStatement("UPDATE loss_box SET status = 'revoked', revoked_at = NOW() WHERE NOT exercise AND status <> 'revoked'");
            $this->db->executeStatement("UPDATE loss_device SET status = 'retired', cash_register_id = NULL WHERE NOT exercise AND status <> 'retired'");
            $this->db->beginTransaction();
            $this->provider->getEntityManager()->getRepository(LossPiece::class)->deleteExercise();
            $this->provider->getEntityManager()->getRepository(LossBox::class)->deleteExercise();
            $this->db->commit();
            // les articles de l'essai restent (des ventes et des lignes de commande les désignent), comme dans l'essai de la vente reliée
        } finally {
            parent::tearDown();
        }
    }

    public function testAPieceReadAtTheExitWithoutASaleOpensOneFindingPerDayWithItsReadsAsEvidence(): void
    {
        $variant = $this->article('Manteau', 50);
        [$p1, $p2, $p3] = [$this->taggedSince($variant, '01'), $this->taggedSince($variant, '02'), $this->taggedSince($variant, '03')];
        $this->install();
        $this->drain(); // le premier passage pose le curseur : rien d'avant n'est jugé
        $this->assertNotNull($this->db->fetchOne('SELECT exit_cursor FROM loss_setting'));
        $findings = fn (string $rule): array => $this->db->fetchAllAssociative('SELECT * FROM loss_finding WHERE rule_code = ? AND NOT exercise AND subject_id IN (?) ORDER BY id',
            [$rule, [$this->piece($p1), $this->piece($p2), $this->piece($p3)]], [1 => ArrayParameterType::INTEGER]);

        // La pièce 1 passe la sortie (il y a 400 secondes) sans aucune vente : un constat, par pièce, sans personne
        $this->assertSame(['accepted', null], $this->exit($p1, -400));
        $this->drain();
        $open = $findings('EXIT_WITHOUT_SALE');
        $this->assertCount(1, $open);
        $facts = json_decode((string) $open[0]['facts'], true);
        $this->assertSame(['piece', $this->piece($p1), 3000, [], 1, 'high'], [$open[0]['subject_type'], (int) $open[0]['subject_id'], (int) $open[0]['amount_at_stake'],
            json_decode((string) $open[0]['on_duty'], true), $facts['reads'], $facts['level']]);
        $id = (int) $open[0]['id'];
        $detail = $this->json($this->admin('GET', self::BASE . "/constats/$id"));
        $this->assertSame(['EXIT_WITHOUT_SALE', 'Sortie sans vente'], [$detail['rule']['code'], $detail['rule']['label']]);
        $this->assertStringStartsWith('Pièce n° ' . $facts['pieceNumber'] . ' (Manteau L4B-' . $this->s . ') lue à la sortie, sans vente qui l\'explique.', $detail['summary']);
        $evidence = $this->db->fetchAllAssociative('SELECT kind, ref_type, ref_id, snapshot FROM loss_evidence WHERE finding_id = ? ORDER BY id', [$id]);
        $snapshot = json_decode((string) $evidence[0]['snapshot'], true);
        $this->assertSame(['exit_read', 'piece_event', "SORTIE-$this->s"], [$evidence[0]['kind'], $evidence[0]['ref_type'], $snapshot['device']]);
        $this->assertArrayNotHasKey('userId', $snapshot);
        $this->assertStringNotContainsString('Gerant', json_encode($detail), 'aucun nom de personne');

        // Elle repasse le même jour (l'antenne a dit son départ entre les deux) : le constat ne se rouvre pas, la lecture s'y ajoute comme
        // preuve. Sans ce départ, la seconde arrivée serait REDITE (lot L4-c : un boîtier qui redémarre) et ne compterait pas
        $this->assertSame(['accepted', null], $this->exit($p1, -395, 'exit', 'ZONE_LEFT'));
        $this->assertSame(['accepted', null], $this->exit($p1, -390));
        $this->drain();
        $this->assertCount(1, $findings('EXIT_WITHOUT_SALE'));
        $this->assertSame([2, 2], [json_decode((string) $findings('EXIT_WITHOUT_SALE')[0]['facts'], true)['reads'], (int) $this->db->fetchOne('SELECT COUNT(*) FROM loss_evidence WHERE finding_id = ?', [$id])]);
        // relire ne change rien
        $this->drain();
        $this->assertSame(2, (int) $this->db->fetchOne('SELECT COUNT(*) FROM loss_evidence WHERE finding_id = ?', [$id]));

        // « Du nouveau depuis la décision » ne dépend plus de la seconde : la décision est datée APRÈS la preuve qui la suit (horloges
        // égales à la seconde, ou décision juste avant), la preuve est quand même dite nouvelle
        $this->json($this->admin('PUT', self::BASE . "/constats/$id/decision", ['status' => 'watch']));
        $this->assertFalse($this->json($this->admin('GET', self::BASE . "/constats/$id"))['newEvidenceSinceDecision']);
        $this->db->executeStatement("UPDATE loss_finding SET decided_at = decided_at + INTERVAL '1 hour' WHERE id = ?", [$id]);
        $this->assertSame(['accepted', null], $this->exit($p1, -385, 'exit', 'ZONE_LEFT')); // un départ, puis un nouveau passage (pas une redite)
        $this->assertSame(['accepted', null], $this->exit($p1, -380));
        $this->drain();
        $this->assertTrue($this->json($this->admin('GET', self::BASE . "/constats/$id"))['newEvidenceSinceDecision']);
        $this->json($this->admin('PUT', self::BASE . "/constats/$id/decision", ['status' => 'dismissed'])); // (la même décision redite n'est pas réécrite)
        $this->assertFalse($this->json($this->admin('GET', self::BASE . "/constats/$id"))['newEvidenceSinceDecision'], 'une nouvelle décision remet à zéro');

        // Une pièce retirée n'ouvre jamais de constat
        $this->json($this->admin('POST', self::BASE . '/pieces/' . $this->piece($p2) . '/retirer', ['reason' => 'lost', 'confirm' => true]));
        $this->assertSame(['accepted', null], $this->exit($p2, -370));
        // une lecture à l'heure douteuse (une heure dans le futur) n'est jamais jugée, et n'arrête pas le curseur
        $this->assertSame(['accepted', null], $this->exit($p3, 3600));
        // une lecture en rayon n'est pas une sortie
        $this->assertSame(['accepted', null], $this->exit($p3, -360, 'floor'));
        $this->drain();
        $this->assertSame([[$this->piece($p1)], []], [array_map('intval', array_column($findings('EXIT_WITHOUT_SALE'), 'subject_id')), $findings('EXIT_COVERED')]);
        $lastExit = fn (): int => (int) $this->db->fetchOne("SELECT MAX(id) FROM loss_piece_event WHERE type = 'ZONE_SEEN' AND NOT exercise AND ((data)::jsonb ->> 'zone') = 'exit'");
        $this->assertSame($lastExit(), (int) $this->db->fetchOne('SELECT exit_cursor FROM loss_setting'), 'tout est jugé, l\'heure douteuse comprise');

        // Règle éteinte : rien de nouveau ; le curseur avance quand même (rallumée, elle ne rejuge pas le passé)
        $this->json($this->admin('PUT', self::BASE . '/regles/EXIT_WITHOUT_SALE', ['enabled' => false]));
        $this->assertSame(['accepted', null], $this->exit($p1, -355, 'exit', 'ZONE_LEFT')); // un nouveau passage, pas une redite
        $this->assertSame(['accepted', null], $this->exit($p1, -350));
        $before = (int) $this->db->fetchOne('SELECT COUNT(*) FROM loss_evidence WHERE finding_id = ?', [$id]);
        $this->drain();
        $this->assertSame($before, (int) $this->db->fetchOne('SELECT COUNT(*) FROM loss_evidence WHERE finding_id = ?', [$id]));

        $this->json($this->admin('PUT', self::BASE . '/regles/EXIT_WITHOUT_SALE', ['enabled' => true]));
        // Une pièce d'exercice lue à la sortie par le boîtier simulé n'ouvre aucun vrai constat
        $sim = $this->json($this->admin('POST', self::EX . '/boitiers'), 201)['box']['id'];
        $this->json($this->admin('POST', self::EX . '/pieces', ['variantId' => $variant, 'count' => 2]), 201);
        $this->json($this->admin('POST', self::EX . "/boitiers/$sim/scenarios", ['scenario' => 'print_ok']));
        $this->json($this->admin('POST', self::EX . "/boitiers/$sim/scenarios", ['scenario' => 'zone_exit']));
        $this->drain();
        $this->assertSame(0, (int) $this->db->fetchOne("SELECT COUNT(*) FROM loss_finding f JOIN loss_piece p ON p.id = f.subject_id WHERE f.rule_code = 'EXIT_WITHOUT_SALE' AND f.subject_type = 'piece' AND p.exercise"));
        // Jamais trop tôt : une lecture d'il y a deux minutes peut encore être expliquée par une vente ; elle attend, et le curseur
        // s'arrête devant elle
        $last = $lastExit();
        $this->assertSame(['accepted', null], $this->exit($p3, -120));
        $this->drain();
        $this->assertSame([], array_filter(array_column($findings('EXIT_WITHOUT_SALE'), 'subject_id'), fn ($s): bool => (int) $s === $this->piece($p3)));
        $this->assertSame($last, (int) $this->db->fetchOne('SELECT exit_cursor FROM loss_setting'));

        // … puis elle est jugée quand sa fenêtre est passée (ici, la fenêtre du site raccourcie à une minute) : un constat pour la pièce 3
        $this->db->executeStatement('UPDATE loss_setting SET catch_up_seconds = 60');
        $this->drain();
        $this->assertSame([$this->piece($p1), $this->piece($p3)], array_map('intval', array_column($findings('EXIT_WITHOUT_SALE'), 'subject_id')));
        $this->assertGreaterThan($last, (int) $this->db->fetchOne('SELECT exit_cursor FROM loss_setting'));
    }

    public function testAnExitCaughtUpOnAPartlyTaggedItemIsAWeakFindingAndAFullyTaggedItemStaysSilent(): void
    {
        $partly = $this->article('Gilet', 50);
        $fully = $this->article('Ceinture', 2);
        [$b1, $b2, $b3] = [$this->taggedSince($partly, '11'), $this->taggedSince($partly, '12'), $this->taggedSince($partly, '13')];
        [$c1, $c2] = [$this->taggedSince($fully, '21'), $this->taggedSince($fully, '22')];
        $this->install();
        $this->loginPos(['ROLE_USER_POS']);
        $this->openSession();
        $this->drain();
        $covered = fn (int $variant): array => $this->db->fetchAllAssociative("SELECT * FROM loss_finding WHERE rule_code = 'EXIT_COVERED' AND NOT exercise AND subject_id = ? ORDER BY id", [$variant]);
        // une vente il y a 8 minutes, sans rien poser sur le tapis ; la pièce est lue à la sortie 100 secondes plus tard
        $sellThenExit = function (int $variant, string $tag): int {
            $order = $this->sold($variant);
            $this->db->executeStatement("UPDATE \"order\" SET order_date = order_date - INTERVAL '8 minutes' WHERE id = ?", [$order]);
            $this->assertSame(['accepted', null], $this->exit($tag, -380));
            $this->drain();

            return $order;
        };

        // Article pas entièrement étiqueté (3 pièces à puce, 50 en stock) : la pièce est rattrapée sur la vente, et le doute est dit
        $sellThenExit($partly, $b1);
        $this->assertSame(['sold', 'catch_up'], [$this->status($b1), $this->db->fetchOne('SELECT matched_by FROM loss_sale_unit WHERE piece_id = ?', [$this->piece($b1)])]);
        $rows = $covered($partly);
        $this->assertCount(1, $rows);
        $facts = json_decode((string) $rows[0]['facts'], true);
        $this->assertSame(['product_variant', 1, 3, 'low', 3000], [$rows[0]['subject_type'], $facts['count'], $facts['minCount'], $facts['level'], (int) $rows[0]['amount_at_stake']]);
        $detail = $this->json($this->admin('GET', self::BASE . '/constats/' . $rows[0]['id']));
        $this->assertSame('Sortie rattrapée sur un article pas entièrement étiqueté', $detail['rule']['label']);
        $this->assertSame('Sorties rattrapées cette semaine sur « Gilet L4B-' . $this->s . ' », pas entièrement étiqueté : 1.', $detail['summary']);
        $this->assertNull($detail['onDutyNotice'], 'aucune personne : pas de phrase sur les personnes en poste');
        $this->assertSame(0, (int) $this->db->fetchOne("SELECT COUNT(*) FROM loss_finding WHERE rule_code = 'EXIT_WITHOUT_SALE' AND subject_id = ?", [$this->piece($b1)]), 'vendue : pas de sortie sans vente');
        $snapshot = json_decode((string) $this->db->fetchOne('SELECT snapshot FROM loss_evidence WHERE finding_id = ?', [$rows[0]['id']]), true);
        $this->assertSame([$this->register, true], [$snapshot['cashRegisterId'], isset($snapshot['soldAt'])]);
        $this->assertSame('Exit matched on a partly tagged item', $this->json($this->admin('GET', self::BASE . '/constats/' . $rows[0]['id'] . '?locale=en'))['rule']['label']);

        // Deuxième sortie rattrapée la même semaine : le même constat, compté ; au seuil (réglé à 2), la gravité passe à moyenne
        $this->json($this->admin('PUT', self::BASE . '/regles/EXIT_COVERED', ['params' => ['minCount' => 2]]));
        $sellThenExit($partly, $b2);
        $rows = $covered($partly);
        $this->assertCount(1, $rows);
        $this->assertSame([2, 'medium', 6000, 2], [json_decode((string) $rows[0]['facts'], true)['count'], json_decode((string) $rows[0]['facts'], true)['level'], (int) $rows[0]['amount_at_stake'],
            (int) $this->db->fetchOne('SELECT COUNT(*) FROM loss_evidence WHERE finding_id = ?', [$rows[0]['id']])]);
        $this->assertStringEndsWith('(seuil de 2 atteint).', $this->json($this->admin('GET', self::BASE . '/constats/' . $rows[0]['id']))['summary']);

        // Une cliente RAPPORTE la pièce 1 : elle passe le portique en entrant, puis le retour est fait en caisse. La lecture d'entrée a
        // eu lieu pendant que la pièce était vendue : ce n'est pas une sortie sans vente
        $order1 = (int) $this->db->fetchOne('SELECT order_id FROM loss_sale_unit WHERE piece_id = ?', [$this->piece($b1)]);
        $this->assertSame(['accepted', null], $this->exit($b1, -320));
        $item = (int) $this->db->fetchOne('SELECT id FROM order_items WHERE order_associated_id = ? AND product_variant_id = ?', [$order1, $partly]);
        $this->json($this->pos('POST', '/api/pos/returns', ['orderId' => $order1, 'lines' => [['orderItemId' => $item, 'quantity' => 1]],
            'reasonCode' => 'defectueux', 'refund' => ['method' => 'cash'], 'cashRegisterId' => $this->register]), 201);
        $this->drain();
        $this->assertSame(['returned', 0, 2], [$this->status($b1), (int) $this->db->fetchOne("SELECT COUNT(*) FROM loss_finding WHERE rule_code = 'EXIT_WITHOUT_SALE' AND subject_id = ?", [$this->piece($b1)]),
            json_decode((string) $covered($partly)[0]['facts'], true)['count']]);

        // Une vente que le rattrapage n'a pas vue (plus de 48 heures : hors de son horizon) explique encore la sortie de sa pièce : pas de
        // sortie sans vente ; l'article n'étant pas entièrement étiqueté, le doute est dit
        $late = $this->article('Bonnet', 50);
        $d1 = $this->taggedSince($late, '31', 72);
        $order = $this->sold($late);
        $this->db->executeStatement("UPDATE \"order\" SET order_date = order_date - INTERVAL '49 hours' WHERE id = ?", [$order]);
        $this->assertSame(['accepted', null], $this->exit($d1, -(49 * 3600 - 60)));
        $this->drain();
        $this->assertSame(['created', null], [$this->status($d1), $this->db->fetchOne('SELECT piece_id FROM loss_sale_unit WHERE order_id = ?', [$order])], 'le rattrapage ne l\'a pas reliée');
        $this->assertSame(0, (int) $this->db->fetchOne("SELECT COUNT(*) FROM loss_finding WHERE rule_code = 'EXIT_WITHOUT_SALE' AND subject_id = ?", [$this->piece($d1)]));
        $waiting = $covered($late);
        $this->assertCount(1, $waiting);
        $this->assertTrue(json_decode((string) $this->db->fetchOne('SELECT snapshot FROM loss_evidence WHERE finding_id = ?', [$waiting[0]['id']]), true)['saleWaiting']);
        // … mais une vente en attente n'explique qu'UNE sortie : une seconde pièce du même article, sortie juste après, ouvre la sortie sans vente
        // (jugée dans un autre passage : le compte ne dépend pas du passage)
        $d2 = $this->taggedSince($late, '32', 72);
        $this->assertSame(['accepted', null], $this->exit($d2, -(49 * 3600 - 90)));
        $this->drain();
        $this->assertSame([1, 1], [(int) $this->db->fetchOne("SELECT COUNT(*) FROM loss_finding WHERE rule_code = 'EXIT_WITHOUT_SALE' AND subject_id = ?", [$this->piece($d2)]),
            count($covered($late))]);

        // Article entièrement étiqueté (2 pièces à puce pour 2 en stock) : la vente sans lecture ne peut être qu'une lecture manquée,
        // la pièce est rattrapée en silence
        $sellThenExit($fully, $c1);
        $this->assertSame(['sold', []], [$this->status($c1), $covered($fully)]);

        // Règle éteinte : la pièce est toujours rattrapée, sans constat de plus
        $this->json($this->admin('PUT', self::BASE . '/regles/EXIT_COVERED', ['enabled' => false]));
        $sellThenExit($partly, $b3);
        $this->assertSame(['sold', 2], [$this->status($b3), json_decode((string) $covered($partly)[0]['facts'], true)['count']]);

        // Les deux règles sont au catalogue, vague 4, sans variante par personne ; l'aperçu ne rejoue pas les antennes, il le dit
        $rules = array_column($this->json($this->admin('GET', self::BASE . '/regles'))['rules'], null, 'code');
        $this->assertSame([4, 4, null, null, [], ['minCount']], [$rules['EXIT_WITHOUT_SALE']['wave'], $rules['EXIT_COVERED']['wave'], $rules['EXIT_WITHOUT_SALE']['personVariant'],
            $rules['EXIT_COVERED']['personVariant'], $rules['EXIT_WITHOUT_SALE']['params'], array_column($rules['EXIT_COVERED']['params'], 'key')]);
        $preview = $this->json($this->admin('POST', self::BASE . '/regles/apercu', ['code' => 'EXIT_COVERED', 'params' => ['minCount' => 4]]), 409);
        $this->assertSame('RULE_PREVIEW_UNAVAILABLE', $preview['error']);
        $this->assertStringContainsString('antennes', $preview['message']);
        // l'exercice a ses deux constats fictifs
        foreach (['exit_without_sale' => 'EXIT_WITHOUT_SALE', 'exit_covered' => 'EXIT_COVERED'] as $code => $rule) {
            $made = $this->json($this->admin('POST', self::EX, ['scenario' => $code]), 201)['finding'];
            $this->assertSame([true, $rule, []], [$made['exercise'], $made['rule']['code'], array_filter(array_column($made['evidence'] ?? [], 'kind'), static fn (string $k): bool => $k !== 'exit_read')]);
        }
        $this->json($this->admin('DELETE', self::EX)); // les constats d'exercice de cet essai ne restent pas pour les suivants

        // Une vente ANNULÉE après la sortie de sa pièce n'explique plus la sortie : l'annulation ne prouve pas que la pièce est revenue
        // (un retour, lui, le prouve)
        $hat = $this->article('Chapeau', 50);
        $e1 = $this->taggedSince($hat, '41');
        $voided = $this->sold($hat);
        $this->db->executeStatement("UPDATE \"order\" SET order_date = order_date - INTERVAL '4 minutes' WHERE id = ?", [$voided]);
        $this->assertSame(['accepted', null], $this->exit($e1, -100));
        $this->drain(); // la pièce est rattrapée sur la vente ; la lecture, trop récente, n'est pas encore jugée
        $this->assertSame('sold', $this->status($e1));
        $this->json($this->pos('POST', '/api/order/cancel/' . $voided, ['paymentMethod' => 2, 'cashRegisterId' => $this->register]));
        $this->db->executeStatement('UPDATE loss_setting SET catch_up_seconds = 60'); // la lecture devient jugeable
        $this->drain();
        $this->assertSame(['returned', 1], [$this->status($e1), (int) $this->db->fetchOne("SELECT COUNT(*) FROM loss_finding WHERE rule_code = 'EXIT_WITHOUT_SALE' AND subject_id = ?", [$this->piece($e1)])]);

        // La migration se rejoue sans rien casser, et son script porte les mêmes instructions
        require_once dirname(__DIR__, 3) . '/migrations/Version20270122100000.php';
        foreach (\DoctrineMigrations\Version20270122100000::STATEMENTS as $sql) {
            $this->db->executeStatement($sql);
        }
        $script = (string) @file_get_contents(dirname(__DIR__, 3) . '/scripts/migrate_all_v2_pertes_l4b.sh');
        if ($script !== '') {
            foreach (\DoctrineMigrations\Version20270122100000::STATEMENTS as $sql) {
                $this->assertStringContainsString($sql . ';', $script);
            }
        }
        $this->assertSame(4, count(\DoctrineMigrations\Version20270122100000::STATEMENTS));
    }

    private function install(): void
    {
        $created = $this->json($this->admin('POST', self::BOXES, ['name' => "Sortie $this->s"]), 201);
        $enrolment = $this->json($this->channel('POST', '/inscription', ['key' => $created['key'], 'machine' => self::MACHINE, 'version' => '1.0.0', 'arch' => 'amd64'], null));
        $this->token = $enrolment['token'];
        $settings = $this->json($this->channel('POST', '/signe-de-vie', ['now' => gmdate('Y-m-d\TH:i:s\Z'), 'machine' => self::MACHINE, 'lastServerTime' => $enrolment['serverTime'],
            'devices' => [['serial' => "SORTIE-$this->s", 'kind' => 'reader', 'status' => 'ok']]], $this->token))['settings'];
        $reader = array_column($settings['devices'], 'id', 'serial')["SORTIE-$this->s"];
        $this->json($this->admin('PUT', self::BOXES . '/' . $created['box']['id'] . "/appareils/$reader", ['zone' => 'exit']));
    }

    /** @return array{0: string, 1: ?string} une arrivée de la puce devant l'antenne de la sortie (ou d'une autre zone), décalée de `$shift` secondes */
    private function exit(string $identifier, int $shift, string $zone = 'exit', string $type = 'ZONE_SEEN'): array
    {
        $fact = ['key' => 'sortie-' . $this->s . '-' . str_pad((string) ++$this->n, 4, '0', STR_PAD_LEFT), 'type' => $type, 'at' => gmdate('Y-m-d\TH:i:s\Z', time() + $shift),
            'device' => "SORTIE-$this->s", 'zone' => $zone, 'data' => ['identifier' => $identifier]];
        $result = $this->json($this->channel('POST', '/faits', ['facts' => [$fact]], $this->token))['results'][0];

        return [$result['status'], $result['code']];
    }

    /** Une vente au comptoir d'un article, payée en espèces ; rend la commande */
    private function sold(int $variant): int
    {
        $body = ['items' => [['productVariantId' => $variant, 'quantity' => 1]], 'cashRegisterId' => $this->register];
        $quote = $this->json($this->pos('POST', '/api/pos/quote', $body));

        return (int) $this->json($this->pos('POST', '/api/order/create-multi-payment', $body + ['paymentMethods' => [['type' => 2, 'amount' => $quote['cashTotal'] / 100]]]), 201)['orderId'];
    }

    private function piece(string $identifier): int
    {
        return (int) $this->db->fetchOne('SELECT piece_id FROM loss_tag WHERE identifier = ?', [$identifier]);
    }

    private function status(string $identifier): string
    {
        return (string) $this->db->fetchOne('SELECT p.status FROM loss_piece p JOIN loss_tag t ON t.piece_id = p.id WHERE t.identifier = ?', [$identifier]);
    }

    /** Une vraie pièce et sa puce NFC, qui existent depuis `$hours` heures (la vente, reculée dans le passé, les trouve suivies) */
    private function taggedSince(int $variant, string $suffix, int $hours = 1): string
    {
        $nfc = '04' . $this->s . 'CC00' . $suffix;
        $at = gmdate('Y-m-d H:i:s', time() - 3600 * $hours);
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
        $slug = 'l4b-' . bin2hex(random_bytes(4));
        $this->db->executeStatement("INSERT INTO product (id, name, price, isbestseller, quantity, created_at, slug, product_type, is_web, is_pos) VALUES (nextval('product_id_seq'), :n, 3000, false, 0, NOW(), :s, 'standard', false, true)",
            ['n' => "$name L4B-$this->s", 's' => $slug]);
        $product = (int) $this->db->fetchOne('SELECT id FROM product WHERE slug = ?', [$slug]);

        return (int) $this->db->fetchOne("INSERT INTO product_variant (id, product_id, stock_quantity) VALUES (nextval('product_variant_id_seq'), ?, ?) RETURNING id", [$product, $stock]);
    }
}
