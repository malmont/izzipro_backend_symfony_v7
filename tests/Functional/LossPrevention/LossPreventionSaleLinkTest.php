<?php

namespace App\Tests\Functional\LossPrevention;

use App\LossPrevention\Entity\LossBox;
use App\LossPrevention\Entity\LossPiece;
use App\LossPrevention\Services\LossSaleLinkService;
use Doctrine\DBAL\ArrayParameterType;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lot L3-b2a (16/11/2026, `docs/prevention-pertes.md` § 16.18) : la vente reliée aux pièces, après coup. L'analyse du module lit le
 * journal de caisse et relie chaque article suivi vendu à une pièce lue sur le tapis de son poste, ou rattrapée à la sortie ; un
 * retour ou une annulation rend la pièce. Rien n'est écrit dans une table de la caisse.
 */
class LossPreventionSaleLinkTest extends LossPreventionTestCase
{
    private const BOXES = self::BASE . '/boitiers';
    private const MACHINE = 'machine-de-vente-0123456789abcdef';

    private string $s;
    private string $ip;
    private int $n = 0;
    private string $token = '';
    private int $mat = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->s = strtoupper(bin2hex(random_bytes(3)));
        $this->ip = '10.' . random_int(1, 250) . '.' . random_int(1, 250) . '.' . random_int(1, 250);
        $this->flags(true, true);
        require_once dirname(__DIR__, 3) . '/migrations/Version20270116100000.php';
        $this->db->executeStatement('UPDATE loss_setting SET counter_window_seconds = NULL, catch_up_seconds = NULL, presence_retention_days = NULL');
    }

    protected function tearDown(): void
    {
        try {
            while ($this->db->isTransactionActive()) {
                $this->db->rollBack();
            }
            $this->db->executeStatement('UPDATE loss_setting SET counter_window_seconds = NULL, catch_up_seconds = NULL, presence_retention_days = NULL');
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

    public function testASaleIsLinkedAfterwardsToThePiecesReadOnTheMatOfItsRegisterOrCaughtUpAtTheExit(): void
    {
        [$a, $b, $c, $d] = $this->article('Robe', 4);
        [$a1, $a2, $a3, $b1] = [$this->tagged($a, '01'), $this->tagged($a, '02'), $this->tagged($a, '03'), $this->tagged($b, '04')];
        $this->install();
        $this->loginPos(['ROLE_USER_POS']);
        $this->openSession();
        $this->drain();
        $caisse = fn (): array => array_map('intval', $this->db->fetchNumeric('SELECT (SELECT COUNT(*) FROM "order"), (SELECT COUNT(*) FROM order_items), (SELECT COUNT(*) FROM pos_audit_log), (SELECT COUNT(*) FROM inventory_movements), (SELECT COUNT(*) FROM loss_finding)'));

        // Deux pièces posées sur le tapis, puis la vente : deux robes A, une robe B, une robe C (non suivie), une casquette (non suivie)
        $this->read('COUNTER_SEEN', $a1);
        $this->read('COUNTER_SEEN', $b1);
        $sale = $this->sold([[$a, 2], [$b, 1], [$c, 1], [$this->cap, 1]]);
        $this->assertSame([], $this->units($sale), 'la vente n\'écrit rien dans le module : c\'est l\'analyse qui relie, après coup');
        $before = $caisse();
        $this->drain();
        $this->assertSame($before, $caisse(), 'l\'analyse n\'écrit ni vente, ni ligne, ni journal de caisse, ni stock, ni constat');
        $units = $this->units($sale);
        $this->assertSame([[$a, 1, $this->piece($a1), 'counter'], [$a, 2, null, null], [$b, 1, $this->piece($b1), 'counter']], array_map(fn (array $u): array => [$u['variant_id'], $u['unit_no'], $u['piece_id'], $u['matched_by']], $units),
            'un article suivi vendu = une ligne ; la pièce lue sur le tapis du poste lui est reliée ; ni la robe C ni la casquette ne sont suivies');
        $this->assertSame([$this->register, $sale], [$units[0]['cash_register_id'], $units[0]['order_id']]);
        $this->assertSame(['sold', 'created', 'sold'], [$this->status($a1), $this->status($a2), $this->status($b1)]);
        $fact = $this->db->fetchAssociative("SELECT user_id, tag_id, source, exercise, data FROM loss_piece_event WHERE piece_id = ? AND type = 'PIECE_SOLD'", [$this->piece($a1)]);
        $data = json_decode((string) $fact['data'], true);
        $this->assertSame([null, null, false, 'counter', $sale, $this->register], [$fact['user_id'], $fact['tag_id'], (bool) $fact['exercise'], $data['by'], $data['orderId'], $data['cashRegisterId']]);
        $this->assertSame(['by', 'orderId', 'orderItemId', 'cashRegisterId', 'soldAt'], array_keys($data), 'aucun nom de personne dans le fait');
        // la fiche de la pièce dit sa vente : le ticket, le poste, par quoi
        $sheet = $this->json($this->admin('GET', self::BASE . '/pieces/' . $this->piece($a1)));
        $this->assertSame(['sold', 'Vendue', $sale, $this->register, 'counter', 'Lue sur le tapis du comptoir'],
            [$sheet['status'], $sheet['statusLabel'], $sheet['sale']['orderId'], $sheet['sale']['cashRegisterId'], $sheet['sale']['by'], $sheet['sale']['byLabel']]);
        $this->assertSame((string) $this->db->fetchOne('SELECT reference FROM "order" WHERE id = ?', [$sale]), $sheet['sale']['reference']);
        $this->assertSame('Vendue', array_slice(array_column($sheet['events'], 'label'), -1)[0]);
        $this->assertNull($this->json($this->admin('GET', self::BASE . '/pieces/' . $this->piece($a2)))['sale']);
        // relire ne change rien : la même vente n'est reliée qu'une fois
        $this->drain();
        $this->assertSame($units, $this->units($sale));
        $this->assertSame(2, (int) $this->db->fetchOne("SELECT COUNT(*) FROM loss_piece_event WHERE type = 'PIECE_SOLD' AND piece_id IN (?, ?, ?)", [$this->piece($a1), $this->piece($a2), $this->piece($b1)]));

        // Rattrapage à la sortie : la robe A restée sans pièce (vente 1), et une seconde vente sans rien poser. La pièce A2 est lue à la
        // sortie : elle est reliée à la vente la plus ANCIENNE qui attend, par « rattrapage »
        $second = $this->sold([[$a, 1]]);
        $this->drain();
        $this->assertSame([[$a, 1, null, null]], array_map(fn (array $u): array => [$u['variant_id'], $u['unit_no'], $u['piece_id'], $u['matched_by']], $this->units($second)));
        $this->assertSame(['accepted', null], $this->read('SPOT_CHECK', $a2, 'LECTEUR'), 'un contrôle au lecteur à main n\'est pas une sortie');
        $this->assertSame(['accepted', null], $this->read('ZONE_SEEN', $a2, 'LECTEUR', ['zone' => 'floor']), 'une lecture en rayon non plus');
        $this->assertSame(['accepted', null], $this->read('ZONE_SEEN', $a2, 'LECTEUR', ['zone' => 'exit', 'at' => gmdate('Y-m-d\TH:i:s\Z', time() + 900)]), 'ni une lecture à l\'heure douteuse');
        $this->drain();
        $this->assertSame(['created', null], [$this->status($a2), $this->units($sale)[1]['piece_id']]);
        $this->assertSame(['accepted', null], $this->read('ZONE_SEEN', $a2, 'LECTEUR', ['zone' => 'exit']));
        $before = $caisse();
        $this->drain();
        $this->assertSame($before, $caisse());
        $this->assertSame([[$this->piece($a2), 'catch_up'], [null, null], 'sold'], [[$this->units($sale)[1]['piece_id'], $this->units($sale)[1]['matched_by']], [$this->units($second)[0]['piece_id'], $this->units($second)[0]['matched_by']], $this->status($a2)],
            'une pièce par article vendu, la vente la plus ancienne d\'abord');
        $caught = json_decode((string) $this->db->fetchOne("SELECT data FROM loss_piece_event WHERE piece_id = ? AND type = 'PIECE_SOLD'", [$this->piece($a2)]), true);
        $this->assertSame(['catch_up', $sale, true], [$caught['by'], $caught['orderId'], is_int($caught['readEventId'])]);
        $this->assertSame('Rattrapée à la sortie', $this->json($this->admin('GET', self::BASE . '/pieces/' . $this->piece($a2)))['sale']['byLabel']);
        // dans l'autre sens : la lecture à la sortie est déjà là quand la vente est lue
        $this->read('ZONE_SEEN', $a3, 'LECTEUR', ['zone' => 'exit']);
        $this->drain();
        $this->assertSame([$this->piece($a3), 'catch_up'], [$this->units($second)[0]['piece_id'], $this->units($second)[0]['matched_by']], 'la seconde vente attendait : la lecture suivante est pour elle');
        // une pièce déjà vendue relue à la sortie ne se relie à rien d'autre (c'est le client qui sort)
        $this->tagged($a, '11'); // une quatrième robe A, pas encore vendue : l'article reste suivi
        $third = $this->sold([[$a, 1]]);
        $this->read('ZONE_SEEN', $a1, 'LECTEUR', ['zone' => 'exit']);
        $this->drain();
        $this->assertSame([null], array_column($this->units($third), 'piece_id'));

        // Les fenêtres : une lecture à la sortie plus de 30 minutes après la vente ne la rattrape pas ; la fenêtre se règle
        $late = $this->taggedSince($b, '05', 60); // cette pièce et sa puce existent depuis une heure : l'article était suivi il y a 40 minutes
        $old = $this->sold([[$b, 1]]);
        $this->db->executeStatement("UPDATE \"order\" SET order_date = order_date - INTERVAL '40 minutes' WHERE id = ?", [$old]); // la vente a eu lieu il y a 40 minutes (lue maintenant)
        $this->read('ZONE_SEEN', $late, 'LECTEUR', ['zone' => 'exit']);
        $this->drain();
        $this->assertSame([null, 'created'], [$this->units($old)[0]['piece_id'], $this->status($late)]);
        $this->assertEqualsWithDelta(time() - 2400, strtotime($this->units($old)[0]['sold_at'] . ' UTC'), 30, 'l\'heure d\'un article vendu est l\'heure RÉELLE de la vente, pas celle où le journal est lu');
        $this->json($this->admin('PUT', self::BASE . '/reglages', ['pieces' => ['catchUpMinutes' => 60]]));
        $this->drain();
        $this->assertSame([$this->piece($late), 'catch_up'], [$this->units($old)[0]['piece_id'], $this->units($old)[0]['matched_by']]);
        $this->assertSame($this->units($old)[0]['sold_at'], (string) $this->db->fetchOne("SELECT occurred_at FROM loss_piece_event WHERE piece_id = ? AND type = 'PIECE_SOLD'", [$this->piece($late)]),
            'le fait « vendue » est daté de l\'heure de la VENTE, pas de celle où l\'analyse la lit');
        // Une puce qui traînait sur le tapis et n'a été posée sur sa pièce qu'APRÈS la vente ne relie rien (l'article reste suivi
        // par une autre pièce, qui n'était pas sur le tapis)
        [$h] = $this->article('Bonnet');
        $this->taggedSince($h, '14', 60);
        $afterwards = $this->taggedSince($h, '15', 60, -60); // sa puce ne lui est associée que dans une heure
        $this->read('COUNTER_SEEN', $afterwards);
        $early = $this->sold([[$h, 1]]);
        $this->drain();
        $this->assertSame([[null, null]], array_map(fn (array $u): array => [$u['piece_id'], $u['matched_by']], $this->units($early)));
        // Une lecture à la sortie ne vaut que de 2 minutes avant la vente à la fin de sa fenêtre, pour CHAQUE article : deux pièces
        // sortent il y a 8 minutes ; la vente de ce moment-là en prend une, la vente de maintenant ne prend pas l'autre
        [$g] = $this->article('Ceinture');
        [$g1, $g2] = [$this->taggedSince($g, '12', 60), $this->taggedSince($g, '13', 60)];
        $then = $this->sold([[$g, 1]]);
        $this->db->executeStatement("UPDATE \"order\" SET order_date = order_date - INTERVAL '8 minutes' WHERE id = ?", [$then]);
        $this->read('ZONE_SEEN', $g1, 'LECTEUR', ['zone' => 'exit', 'at' => gmdate('Y-m-d\TH:i:s\Z', time() - 500)]);
        $this->read('ZONE_SEEN', $g2, 'LECTEUR', ['zone' => 'exit', 'at' => gmdate('Y-m-d\TH:i:s\Z', time() - 490)]);
        $nowSale = $this->sold([[$g, 1]]);
        $this->drain();
        $this->assertSame([[$this->piece($g1), 'catch_up'], [null, null]], [[$this->units($then)[0]['piece_id'], $this->units($then)[0]['matched_by']], [$this->units($nowSale)[0]['piece_id'], $this->units($nowSale)[0]['matched_by']]]);
        $this->assertSame('created', $this->status($g2));
        // le tapis : une pièce reprise plus de 10 minutes avant la vente ne compte pas ; à 15 minutes de fenêtre, oui
        $gone = $this->tagged($d, '06');
        $this->read('COUNTER_SEEN', $gone, 'TAPIS', ['at' => gmdate('Y-m-d\TH:i:s\Z', time() - 760)]);
        $this->read('COUNTER_LEFT', $gone, 'TAPIS', ['at' => gmdate('Y-m-d\TH:i:s\Z', time() - 700)]);
        $short = $this->sold([[$d, 1]]);
        $this->drain();
        $this->assertNull($this->units($short)[0]['piece_id']);
        $asked = fn (): array => $this->json($this->pos('POST', '/api/pos/pertes/articles-non-lus', ['cashRegisterId' => $this->register, 'items' => [['productVariantId' => $d, 'quantity' => 1]]]));
        $this->assertSame([true, 1, 0], [($unread = $asked())['active'], $unread['tracked'], $unread['read']]);
        $this->json($this->admin('PUT', self::BASE . '/reglages', ['pieces' => ['counterWindowMinutes' => 15]]));
        $this->assertSame([true, 1, 1], [($unread = $asked())['active'], $unread['tracked'], $unread['read']], 'la route de la caisse « articles non lus » suit la même fenêtre');
        $long = $this->sold([[$d, 1]]);
        $this->drain();
        $this->assertSame([$this->piece($gone), 'counter'], [$this->units($long)[0]['piece_id'], $this->units($long)[0]['matched_by']]);

        // Suivi à l'heure de la vente : un article dont la première pièce reçoit sa puce APRÈS la vente n'est pas compté pour elle
        [$e] = $this->article('Gilet');
        $untracked = $this->sold([[$e, 1]]);
        while (time() <= strtotime((string) $this->db->fetchOne('SELECT order_date FROM "order" WHERE id = ?', [$untracked]) . ' UTC')) {
            usleep(100000); // la puce est posée APRÈS la seconde de la vente
        }
        $this->tagged($e, '07');
        $this->drain();
        $this->assertSame([], $this->units($untracked));
        // Un article dont TOUTES les pièces à puce sont vendues n'est plus suivi (comme à la caisse) : le réassort vendu avant d'être
        // étiqueté n'est pas compté « sans lecture »
        [$f] = $this->article('Foulard');
        $only = $this->tagged($f, '09');
        $this->read('COUNTER_SEEN', $only);
        $lastTagged = $this->sold([[$f, 1]]);
        $this->drain();
        $this->assertSame([$this->piece($only)], array_column($this->units($lastTagged), 'piece_id'));
        $restock = $this->sold([[$f, 2]]);
        $this->drain();
        $this->assertSame([], $this->units($restock));
        // Une vente HORS LIGNE synchronisée plus tard : son heure est celle que la caisse a écrite dans le journal, avec son décalage
        // horaire (la colonne de la commande garde alors l'heure du magasin). Et une ancienne entrée sans poste écrit ses articles, sans pièce
        $this->taggedSince($b, '10', 600);
        $offline = $this->sold([[$b, 1]]);
        $link = static::getContainer()->get(LossSaleLinkService::class);
        $this->tenant();
        $link->begin();
        $link->handle(['type' => 'SALE', 'sequence' => 999999001, 'cashRegisterId' => null, 'occurredAt' => new \DateTimeImmutable('now', new \DateTimeZone('UTC')), 'subject' => ['type' => 'order', 'id' => $offline],
            'data' => ['kind' => 'sale', 'offline' => ['soldAt' => (new \DateTimeImmutable('-20 minutes', new \DateTimeZone('America/Toronto')))->format(\DateTimeInterface::ATOM)]]]);
        $this->assertSame([1, null, null], [count($units = $this->units($offline)), $units[0]['cash_register_id'], $units[0]['piece_id']]);
        $this->assertEqualsWithDelta(time() - 1200, strtotime($units[0]['sold_at'] . ' UTC'), 5, 'l\'instant de la vente, en UTC, quel que soit le fuseau de la tablette');
        $this->drain();
        $this->assertSame($units, $this->units($offline), 'l\'entrée du journal relue ne réécrit pas les mêmes articles');
        // Le tapis d'un AUTRE poste ne vaut pas pour cette vente
        $otherRegister = (int) $this->json($this->admin('POST', '/api/cash-registers', ['name' => "Caisse 2 $this->s"]), 201)['id'];
        $this->json($this->admin('PUT', self::BOXES . "/{$this->box}/appareils/$this->mat", ['cashRegisterId' => $otherRegister]));
        $elsewhere = $this->tagged($e, '08');
        $this->read('COUNTER_SEEN', $elsewhere);
        $apart = $this->sold([[$e, 1]]);
        $this->drain();
        $this->assertSame([null, 'created'], [$this->units($apart)[0]['piece_id'], $this->status($elsewhere)], 'la pièce est sur le tapis du poste 2, la vente est au poste 1');
    }

    public function testAReturnOrACancelledSaleGivesThePieceBackAndTheDatabaseGuardsItself(): void
    {
        [$a, $b] = $this->article('Manteau', 2);
        [$a1, $a2, $b1] = [$this->tagged($a, '01'), $this->tagged($a, '02'), $this->tagged($b, '03')];
        $this->install();
        $this->loginPos(['ROLE_USER_POS']);
        $this->openSession();
        $this->drain();
        $this->read('COUNTER_SEEN', $a2, 'TAPIS', ['at' => gmdate('Y-m-d\TH:i:s\Z', time() - 300)]); // posée depuis cinq minutes
        $this->read('COUNTER_SEEN', $b1);
        $sale = $this->sold([[$a, 2], [$b, 1]]);
        $this->read('ZONE_SEEN', $a1, 'LECTEUR', ['zone' => 'exit']); // le second manteau A n'a pas été posé sur le tapis : sa pièce est rattrapée à la sortie
        $this->drain();
        $this->assertSame(['sold', 'sold', 'sold'], [$this->status($a1), $this->status($a2), $this->status($b1)]);
        $item = fn (int $order, int $variant): int => (int) $this->db->fetchOne('SELECT id FROM order_items WHERE order_associated_id = ? AND product_variant_id = ?', [$order, $variant]);
        $return = fn (int $order, int $variant, int $quantity = 1): array => $this->json($this->pos('POST', '/api/pos/returns', ['orderId' => $order, 'lines' => [['orderItemId' => $item($order, $variant), 'quantity' => $quantity]],
            'reasonCode' => 'defectueux', 'refund' => ['method' => 'cash'], 'cashRegisterId' => $this->register]), 201);

        // Un manteau A revient. La pièce A2 a quitté le tapis depuis plus d'une minute (la fenêtre du tapis est réglée à une minute) ; A1,
        // elle, est posée sur le tapis au moment du retour : c'est SON article, le second de la ligne, qui revient
        $this->read('COUNTER_LEFT', $a2, 'TAPIS', ['at' => gmdate('Y-m-d\TH:i:s\Z', time() - 200)]);
        $this->json($this->admin('PUT', self::BASE . '/reglages', ['pieces' => ['counterWindowMinutes' => 1]]));
        $this->read('COUNTER_SEEN', $a1);
        $return($sale, $a);
        $this->drain();
        $units = $this->units($sale);
        $this->assertSame([[$this->piece($a2), false], [$this->piece($a1), true], [$this->piece($b1), false]], array_map(fn (array $u): array => [$u['piece_id'], $u['returned_at'] !== null], $units),
            'un seul article revient (la quantité du retour), celui dont la pièce est relue sur le tapis');
        $this->assertSame(['returned', 'sold', 'sold'], [$this->status($a1), $this->status($a2), $this->status($b1)]);
        $back = $this->db->fetchAssociative("SELECT user_id, data FROM loss_piece_event WHERE piece_id = ? AND type = 'PIECE_RETURNED'", [$this->piece($a1)]);
        $this->assertSame([null, 'return', true], [$back['user_id'], ($d = json_decode((string) $back['data'], true))['by'], $d['readOnCounter']]);
        $this->assertSame(['returned', 'Retournée', null, 'Revenue en magasin'], [($sheet = $this->json($this->admin('GET', self::BASE . '/pieces/' . $this->piece($a1))))['status'], $sheet['statusLabel'], $sheet['sale'],
            array_slice(array_column($sheet['events'], 'label'), -1)[0]]);
        // revenue, la pièce est de nouveau vendable. Restée sur le tapis depuis AVANT son retour, elle n'est pas prise pour la vente
        // suivante (échange du même article : c'est la pièce neuve qui sort) ; reposée, elle l'est, et c'est le passage suivant de
        // l'analyse qui la relie : les faits du tapis peuvent arriver après la vente
        $again = $this->sold([[$a, 1]]);
        $this->drain();
        $this->assertSame([null, 'returned'], [$this->units($again)[0]['piece_id'], $this->status($a1)]);
        while (time() <= strtotime($this->units($sale)[1]['returned_at'] . ' UTC')) {
            usleep(100000); // la pièce est reposée APRÈS la seconde de son retour
        }
        $this->read('COUNTER_LEFT', $a1);
        $this->read('COUNTER_SEEN', $a1);
        $this->drain();
        $this->assertSame([$this->piece($a1), 'counter', 'sold'], [$this->units($again)[0]['piece_id'], $this->units($again)[0]['matched_by'], $this->status($a1)]);
        // le premier manteau A revient sans que sa pièce soit relue : l'article est dit revenu, sa pièce aussi, et le fait le dit
        $return($sale, $a);
        $this->drain();
        $this->assertSame([true, 'returned', false], [$this->units($sale)[0]['returned_at'] !== null, $this->status($a2),
            json_decode((string) $this->db->fetchOne("SELECT data FROM loss_piece_event WHERE piece_id = ? AND type = 'PIECE_RETURNED'", [$this->piece($a2)]), true)['readOnCounter']]);
        $this->assertSame(1, (int) $this->db->fetchOne("SELECT COUNT(*) FROM loss_piece_event WHERE piece_id = ? AND type = 'PIECE_RETURNED'", [$this->piece($a2)]));

        // Une vente annulée : tous ses articles reviennent
        $this->json($this->pos('POST', '/api/order/cancel/' . $again, ['paymentMethod' => 2, 'cashRegisterId' => $this->register]));
        $this->drain();
        $this->assertSame([true, 'returned', 'cancellation'], [$this->units($again)[0]['returned_at'] !== null, $this->status($a1),
            json_decode((string) $this->db->fetchOne("SELECT data FROM loss_piece_event WHERE piece_id = ? AND type = 'PIECE_RETURNED' ORDER BY id DESC LIMIT 1", [$this->piece($a1)]), true)['by']]);
        $this->assertSame('sold', $this->status($b1), 'le manteau B de la première vente n\'est pas concerné');
        $this->assertSame(['return', 'return', 'cancellation'], [$this->units($sale)[0]['returned_by'], $this->units($sale)[1]['returned_by'], $this->units($again)[0]['returned_by']]);
        // Une vente annulée puis RESSAISIE retrouve sa pièce : A1 n'a pas quitté le tapis, une annulation ne l'a pas fait sortir
        // (la règle « posée après son dernier retour » ne vaut que pour un retour d'articles)
        $last = $this->sold([[$a, 1]]);
        $this->drain();
        $this->assertSame([$this->piece($a1), 'counter', 'sold'], [$this->units($last)[0]['piece_id'], $this->units($last)[0]['matched_by'], $this->status($a1)]);
        // une pièce revenue relue à la sortie quand aucune vente ne l'attend : rien
        $this->read('ZONE_SEEN', $a2, 'LECTEUR', ['zone' => 'exit']);
        $this->drain();
        $this->assertSame('returned', $this->status($a2));

        // Ce que la base garde elle-même
        $unit = $this->units($sale)[2]; // le manteau B, relié à B1, pas revenu
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
        $refused('un article vendu écrit deux fois', 'INSERT INTO loss_sale_unit (order_id, order_item_id, unit_no, variant_id, sold_at, audit_sequence, created_at) VALUES (?, ?, ?, ?, NOW(), 1, NOW())', [$sale, $unit['order_item_id'], 1, $b]);
        $refused('une pièce vendue deux fois', 'INSERT INTO loss_sale_unit (order_id, order_item_id, unit_no, variant_id, sold_at, audit_sequence, piece_id, matched_by, matched_at, created_at) VALUES (?, ?, 99, ?, NOW(), 1, ?, ?, NOW(), NOW())',
            [$sale, $unit['order_item_id'], $b, $this->piece($b1), 'counter']);
        $refused('une pièce d\'une autre variante', 'INSERT INTO loss_sale_unit (order_id, order_item_id, unit_no, variant_id, sold_at, audit_sequence, piece_id, matched_by, matched_at, created_at) VALUES (?, ?, 98, ?, NOW(), 1, ?, ?, NOW(), NOW())',
            [$sale, $unit['order_item_id'], $b, $this->piece($a1), 'counter']);
        $refused('une pièce reliée sans dire par quoi', 'INSERT INTO loss_sale_unit (order_id, order_item_id, unit_no, variant_id, sold_at, audit_sequence, piece_id, created_at) VALUES (?, ?, 97, ?, NOW(), 1, ?, NOW())', [$sale, $unit['order_item_id'], $a, $this->piece($a1)]);
        $refused('un moyen inconnu', 'INSERT INTO loss_sale_unit (order_id, order_item_id, unit_no, variant_id, sold_at, audit_sequence, piece_id, matched_by, matched_at, created_at) VALUES (?, ?, 96, ?, NOW(), 1, ?, ?, NOW(), NOW())',
            [$sale, $unit['order_item_id'], $a, $this->piece($a1), 'devine']);
        $refused('un rang nul', 'INSERT INTO loss_sale_unit (order_id, order_item_id, unit_no, variant_id, sold_at, audit_sequence, created_at) VALUES (?, ?, 0, ?, NOW(), 1, NOW())', [$sale, $unit['order_item_id'], $b]);
        $refused('la pièce changée', 'UPDATE loss_sale_unit SET piece_id = NULL, matched_by = NULL, matched_at = NULL WHERE id = ?', [$unit['id']]);
        $refused('la vente changée', "UPDATE loss_sale_unit SET sold_at = sold_at - INTERVAL '1 day' WHERE id = ?", [$unit['id']]);
        $refused('le poste changé', 'UPDATE loss_sale_unit SET cash_register_id = NULL WHERE id = ?', [$unit['id']]);
        $refused('un retour sans entrée du journal', "UPDATE loss_sale_unit SET returned_at = NOW(), returned_by = 'return' WHERE id = ?", [$unit['id']]);
        $refused('un retour qui ne dit pas par quoi', 'UPDATE loss_sale_unit SET returned_at = NOW(), return_sequence = 1 WHERE id = ?', [$unit['id']]);
        $refused('un retour par un moyen inconnu', "UPDATE loss_sale_unit SET returned_at = NOW(), return_sequence = 1, returned_by = 'perdu' WHERE id = ?", [$unit['id']]);
        $refused('un retour annulé', 'UPDATE loss_sale_unit SET returned_at = NULL, return_sequence = NULL, returned_by = NULL WHERE id = ?', [$this->units($sale)[0]['id']]);
        $refused('un retour changé en annulation', "UPDATE loss_sale_unit SET returned_by = 'cancellation' WHERE id = ?", [$this->units($sale)[0]['id']]);
        $refused('la date d\'un retour changée', "UPDATE loss_sale_unit SET returned_at = returned_at + INTERVAL '1 hour' WHERE id = ?", [$this->units($sale)[0]['id']]);
        $refused('une ligne supprimée', 'DELETE FROM loss_sale_unit WHERE id = ?', [$unit['id']]);
        $refused('la table vidée', 'TRUNCATE loss_sale_unit');
        // la migration se rejoue sans rien changer (dans une transaction annulée : aucune trace)
        $schema = fn (): array => [
            $this->db->fetchFirstColumn("SELECT indexname FROM pg_indexes WHERE indexname IN ('uniq_loss_sale_unit_item', 'uniq_loss_sale_unit_piece', 'idx_loss_sale_unit_open', 'idx_loss_sale_unit_order', 'idx_loss_sale_unit_register') ORDER BY 1"),
            $this->db->fetchFirstColumn("SELECT tgname FROM pg_trigger WHERE NOT tgisinternal AND tgname LIKE 'loss_sale_unit%' ORDER BY 1"),
            $this->db->fetchFirstColumn("SELECT column_name FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = 'loss_setting' AND column_name IN ('counter_window_seconds', 'catch_up_seconds', 'presence_retention_days') ORDER BY 1"),
            (int) $this->db->fetchOne('SELECT COUNT(*) FROM loss_sale_unit'),
        ];
        $was = $schema();
        $this->assertSame([5, 3, 3], [count($was[0]), count($was[1]), count($was[2])]);
        $this->db->beginTransaction();
        foreach (\DoctrineMigrations\Version20270116100000::STATEMENTS as $sql) {
            $this->db->executeStatement($sql);
        }
        $this->assertSame($was, $schema());
        $this->db->rollBack();
    }

    public function testTheWindowsAreSetThePresencesAreKeptNinetyDaysAndTheBoardSaysWhenNoTabletIsBound(): void
    {
        // Réglages : trois valeurs, leurs bornes, leurs valeurs par défaut, la règle de conservation en clair
        $view = fn (): array => $this->json($this->admin('GET', self::BASE . '/reglages'))['pieces'];
        $this->assertSame(['bounds' => ['counterWindowMinutes' => ['min' => 1, 'max' => 60], 'catchUpMinutes' => ['min' => 5, 'max' => 120], 'presenceRetentionDays' => ['min' => 30, 'max' => 730]],
            'defaults' => ['counterWindowMinutes' => 10, 'catchUpMinutes' => 30, 'presenceRetentionDays' => 90], 'counterWindowMinutes' => 10, 'catchUpMinutes' => 30, 'presenceRetentionDays' => 90,
            'note' => 'Les présences sur le tapis de comptoir fermées depuis plus de 90 jours sont effacées automatiquement. Les faits (lectures, ventes, retours) sont conservés.'], $view());
        $put = fn (mixed $pieces, int $status = 200): array => $this->json($this->admin('PUT', self::BASE . '/reglages', ['pieces' => $pieces]), $status);
        $this->assertSame(['pieces.counterWindowMinutes' => 'PARAM_OUT_OF_RANGE', 'pieces.catchUpMinutes' => 'MUST_BE_INTEGER', 'pieces.presenceRetentionDays' => 'PARAM_OUT_OF_RANGE'],
            $put(['counterWindowMinutes' => 61, 'catchUpMinutes' => '30', 'presenceRetentionDays' => 29], 422)['fields']);
        $this->assertSame(['pieces.counterWindowMinutes' => 'PARAM_OUT_OF_RANGE', 'pieces.catchUpMinutes' => 'PARAM_OUT_OF_RANGE', 'pieces.presenceRetentionDays' => 'PARAM_OUT_OF_RANGE'],
            $put(['counterWindowMinutes' => 0, 'catchUpMinutes' => 121, 'presenceRetentionDays' => 731], 422)['fields']);
        $this->assertSame(['pieces' => 'MUST_BE_OBJECT'], $put('dix', 422)['fields']);
        $set = $put(['counterWindowMinutes' => 1, 'catchUpMinutes' => 120, 'presenceRetentionDays' => 30])['pieces'];
        $this->assertSame([1, 120, 30, 'Les présences sur le tapis de comptoir fermées depuis plus de 30 jours sont effacées automatiquement. Les faits (lectures, ventes, retours) sont conservés.'],
            [$set['counterWindowMinutes'], $set['catchUpMinutes'], $set['presenceRetentionDays'], $set['note']]);
        $this->assertSame(['60', '7200', '30'], array_map('strval', $this->db->fetchNumeric('SELECT counter_window_seconds, catch_up_seconds, presence_retention_days FROM loss_setting')));
        $this->assertSame(['counter' => 60, 'catchUp' => 7200, 'presenceDays' => 30], static::getContainer()->get(LossSaleLinkService::class)->windows());
        $this->assertSame([10, 120, 30], [($reset = $put(['counterWindowMinutes' => null])['pieces'])['counterWindowMinutes'], $reset['catchUpMinutes'], $reset['presenceRetentionDays']], 'nul : la valeur par défaut ; les autres ne bougent pas');
        $put(['catchUpMinutes' => null, 'presenceRetentionDays' => null]);
        $this->loginPos(['ROLE_USER_POS']);
        $this->assertSame(403, $this->pos('PUT', self::BASE . '/reglages', ['pieces' => ['counterWindowMinutes' => 5]])->getStatusCode());

        // Conservation : une présence fermée depuis plus de 90 jours s'efface au passage de l'analyse ; ni une présence ouverte, ni une
        // présence fermée plus récente, ni aucun fait
        [$a] = $this->article('Écharpe');
        [$old, $recent, $open] = [$this->tagged($a, '01'), $this->tagged($a, '02'), $this->tagged($a, '03')];
        $this->install();
        $tag = fn (string $nfc): int => (int) $this->db->fetchOne('SELECT id FROM loss_tag WHERE identifier = ?', [$nfc]);
        // des présences anciennes, écrites telles que le boîtier les aurait laissées (un fait de plus de huit jours ne pose plus rien)
        foreach ([[$old, '92 days', '91 days'], [$recent, '90 days', '89 days'], [$open, '200 days', null]] as [$nfc, $since, $until]) {
            $this->db->executeStatement("INSERT INTO loss_counter_presence (device_id, tag_id, piece_id, since, until, exercise) VALUES (?, ?, ?, NOW() AT TIME ZONE 'UTC' - CAST(? AS interval), "
                . ($until !== null ? "NOW() AT TIME ZONE 'UTC' - CAST(? AS interval)" : 'NULL') . ', false)', array_merge([$this->mat, $tag($nfc), $this->piece($nfc), $since], $until !== null ? [$until] : []));
        }
        $facts = (int) $this->db->fetchOne('SELECT COUNT(*) FROM loss_piece_event');
        $kept = fn (): array => array_map('intval', $this->db->fetchFirstColumn('SELECT tag_id FROM loss_counter_presence WHERE tag_id IN (?) ORDER BY tag_id', [[$tag($old), $tag($recent), $tag($open)]], [ArrayParameterType::INTEGER]));
        $this->drain();
        $this->assertSame([[$tag($recent), $tag($open)], $facts], [$kept(), (int) $this->db->fetchOne('SELECT COUNT(*) FROM loss_piece_event')]);
        // la durée se règle : à 30 jours, la présence fermée depuis 89 jours part à son tour
        $this->json($this->admin('PUT', self::BASE . '/reglages', ['pieces' => ['presenceRetentionDays' => 30]]));
        $this->drain();
        $this->assertSame([$tag($open)], $kept());

        // Tableau de santé : sur un site à plusieurs postes, un tapis dont le poste n'a aucune tablette rattachée ne sert pas aux caissiers.
        // Et la liste des postes pour l'administrateur : leur tapis, leur tablette
        // Dans la suite complète, un essai d'avant peut avoir laissé une tablette rattachée à ce poste (les clés d'activation vivent dans
        // la base maître, que les essais se partagent) : celles-là ne comptent pas ici. L'essai en laisse une lui-même, pour le prouver
        $forgotten = $this->activateTerminal("Tablette oubliée $this->s");
        $this->json($this->call('PUT', '/api/app-keys/' . $forgotten, ['cashRegisterId' => $this->register], $this->adminToken));
        $this->terminal = null;
        static::getContainer()->get(\App\Services\TenantConnectionManager::class)->getPdoMaster()
            ->prepare("UPDATE tenant_app_keys SET revoked_at = NOW() WHERE tenant_code = ? AND app = 'pos' AND revoked_at IS NULL AND cash_register_id IS NOT NULL")->execute([MV_TEST_TENANT_CODE]);
        $health = fn (): array => array_column($this->json($this->admin('GET', self::BASE . '/sante'))['devices'], null, 'code')["mat-$this->mat"];
        $posts = fn (): array => $this->json($this->admin('GET', self::BASE . '/postes'));
        $post = fn (int $id): array => array_column($posts()['items'], null, 'id')[$id];
        $this->assertSame(['ok', null], [($line = $health())['state'], $line['hint']], 'un seul poste : la question ne se pose pas');
        $registerName = (string) $this->db->fetchOne('SELECT name FROM cash_register WHERE id = ?', [$this->register]);
        $this->assertSame([['items', 'terminalNeeded'], false, ['id' => $this->register, 'name' => $registerName, 'active' => true, 'matDeviceId' => $this->mat, 'terminalBound' => false]],
            [array_keys($posts()), $posts()['terminalNeeded'], $post($this->register)]);
        $this->assertSame((int) $this->db->fetchOne('SELECT COUNT(*) FROM cash_register'), count($posts()['items']), 'tous les postes, actifs ou non');
        $this->assertSame([false, null], [($idle = array_values(array_filter($posts()['items'], fn (array $p): bool => !$p['active']))[0] ?? ['active' => false, 'matDeviceId' => null])['active'], $idle['matDeviceId']]);
        $second = (int) $this->json($this->admin('POST', '/api/cash-registers', ['name' => "Caisse 2 $this->s"]), 201)['id'];
        $this->assertSame([true, ['id' => $second, 'name' => "Caisse 2 $this->s", 'active' => true, 'matDeviceId' => null, 'terminalBound' => false]], [$posts()['terminalNeeded'], $post($second)]);
        $this->assertSame(['pending', "Aucune tablette de caisse n'est rattachée au poste « $registerName » : le contrôle avant paiement est arrêté pour les caissiers de ce poste. Rattachez une tablette à ce poste (clés d'activation de la caisse)."],
            [($line = $health())['state'], $line['hint']]);
        $key = $this->activateTerminal("Tablette $this->s");
        $this->assertSame('pending', $health()['state'], 'une tablette activée mais rattachée à aucun poste ne change rien');
        $this->assertFalse($post($this->register)['terminalBound']);
        $this->json($this->call('PUT', '/api/app-keys/' . $key, ['cashRegisterId' => $this->register], $this->adminToken));
        $this->assertSame(['ok', null], [($line = $health())['state'], $line['hint']]);
        $this->assertSame([true, false], [$post($this->register)['terminalBound'], $post($second)['terminalBound']]);
        // sur un site qui EXIGE le terminal, la route de la caisse refuse l'administration du web ; celle de la gestion répond
        $this->terminal = null;
        $this->db->executeStatement('UPDATE entreprise SET pos_terminal_header_required = true');
        $this->assertSame([403, 200], [$this->call('GET', '/api/cash-registers', null, $this->adminToken)->getStatusCode(), $this->admin('GET', self::BASE . '/postes')->getStatusCode()]);
        $this->db->executeStatement('UPDATE entreprise SET pos_terminal_header_required = false');
        $this->loginPos(['ROLE_USER_POS']);
        $this->assertSame([403, 405], [$this->pos('GET', self::BASE . '/postes')->getStatusCode(), $this->admin('POST', self::BASE . '/postes', [])->getStatusCode()]);
        $this->json($this->call('DELETE', '/api/app-keys/' . $key, null, $this->adminToken));
        $this->terminal = null;
        $this->assertSame(['pending', false], [$health()['state'], $post($this->register)['terminalBound']], 'la tablette révoquée ne compte plus');

        // Sans le droit du module, l'analyse ne tourne pas : rien n'est relié
        $this->terminal = null;
        $this->loginPos(['ROLE_USER_POS']);
        $this->openSession();
        $this->drain();
        $this->flags(true, false);
        $sale = $this->sold([[$a, 1]]);
        $run = static::getContainer()->get(\App\LossPrevention\Services\LossAnalysisService::class);
        $this->tenant();
        $this->assertSame('blocked', $run->run()['status']);
        $this->assertSame([], $this->units($sale));
        $this->flags(true, true);
        $this->drain();
        $this->assertSame([$this->piece($open)], array_column($this->units($sale), 'piece_id'), 'le droit revenu, la vente est lue là où le curseur s\'était arrêté (la pièce restée posée sur le tapis lui est reliée)');
    }

    // ----- aides -----

    private int $box = 0;

    /** Un boîtier inscrit, avec un tapis relié au poste de caisse du test et un lecteur ; leurs numéros de série sont TAPIS-… et LECTEUR-… */
    private function install(): void
    {
        $created = $this->json($this->admin('POST', self::BOXES, ['name' => "Comptoir $this->s"]), 201);
        $enrolment = $this->json($this->channel('POST', '/inscription', ['key' => $created['key'], 'machine' => self::MACHINE, 'version' => '1.0.0', 'arch' => 'amd64'], null));
        $this->token = $enrolment['token'];
        $this->box = $created['box']['id'];
        $settings = $this->json($this->channel('POST', '/signe-de-vie', ['now' => gmdate('Y-m-d\TH:i:s\Z'), 'machine' => self::MACHINE, 'lastServerTime' => $enrolment['serverTime'],
            'devices' => [['serial' => "TAPIS-$this->s", 'kind' => 'counter_mat', 'status' => 'ok'], ['serial' => "LECTEUR-$this->s", 'kind' => 'reader', 'status' => 'ok']]], $this->token))['settings'];
        $this->mat = array_column($settings['devices'], 'id', 'serial')["TAPIS-$this->s"];
        $this->json($this->admin('PUT', self::BOXES . "/$this->box/appareils/$this->mat", ['cashRegisterId' => $this->register]));
    }

    /**
     * Un fait envoyé par le boîtier : une puce lue par le tapis (par défaut) ou par le lecteur.
     *
     * @param array<string, mixed> $over
     *
     * @return array{0: string, 1: ?string}
     */
    private function read(string $type, string $identifier, string $device = 'TAPIS', array $over = []): array
    {
        $fact = $over + ['key' => 'vente-' . $this->s . '-' . str_pad((string) ++$this->n, 4, '0', STR_PAD_LEFT), 'type' => $type, 'at' => gmdate('Y-m-d\TH:i:s\Z'), 'device' => "$device-$this->s", 'data' => ['identifier' => $identifier]];
        $result = $this->json($this->channel('POST', '/faits', ['facts' => [$fact]], $this->token))['results'][0];

        return [$result['status'], $result['code']];
    }

    /**
     * Une vente au comptoir, payée en espèces.
     *
     * @param list<array{0: int, 1: int}> $lines variante, quantité
     *
     * @return int l'identifiant de la commande
     */
    private function sold(array $lines): int
    {
        $body = ['items' => array_map(static fn (array $l): array => ['productVariantId' => $l[0], 'quantity' => $l[1]], $lines), 'cashRegisterId' => $this->register];
        $quote = $this->json($this->pos('POST', '/api/pos/quote', $body));

        return (int) $this->json($this->pos('POST', '/api/order/create-multi-payment', $body + ['paymentMethods' => [['type' => 2, 'amount' => $quote['cashTotal'] / 100]]]), 201)['orderId'];
    }

    /** @return list<array<string, mixed>> les articles suivis d'une vente, dans l'ordre de leurs lignes et de leur rang */
    private function units(int $orderId): array
    {
        return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'order_id' => (int) $r['order_id'], 'order_item_id' => (int) $r['order_item_id'], 'unit_no' => (int) $r['unit_no'], 'variant_id' => (int) $r['variant_id'],
            'cash_register_id' => $r['cash_register_id'] !== null ? (int) $r['cash_register_id'] : null, 'sold_at' => (string) $r['sold_at'], 'piece_id' => $r['piece_id'] !== null ? (int) $r['piece_id'] : null,
            'matched_by' => $r['matched_by'], 'returned_at' => $r['returned_at'], 'returned_by' => $r['returned_by']], $this->db->fetchAllAssociative('SELECT * FROM loss_sale_unit WHERE order_id = ? ORDER BY order_item_id, unit_no', [$orderId]));
    }

    private function piece(string $identifier): int
    {
        return (int) $this->db->fetchOne('SELECT piece_id FROM loss_tag WHERE identifier = ?', [$identifier]);
    }

    private function status(string $identifier): string
    {
        return (string) $this->db->fetchOne('SELECT p.status FROM loss_piece p JOIN loss_tag t ON t.piece_id = p.id WHERE t.identifier = ?', [$identifier]);
    }

    /** Une vraie pièce de cette variante, avec sa puce NFC ; rend l'identifiant de la puce */
    private function tagged(int $variant, string $suffix): string
    {
        $nfc = '04' . $this->s . 'BB00' . $suffix;
        $this->json($this->admin('POST', self::BASE . '/puces/associer', ['variantId' => $variant, 'identifier' => $nfc, 'technology' => 'nfc']));

        return $nfc;
    }

    /**
     * Une vraie pièce et sa puce NFC qui existent depuis `$minutes` minutes (écrites telles que le module les aurait créées alors) :
     * pour une vente dont l'heure réelle précède l'heure où elle est lue.
     */
    private function taggedSince(int $variant, string $suffix, int $minutes, ?int $tagMinutes = null): string
    {
        $nfc = '04' . $this->s . 'BB00' . $suffix;
        $at = gmdate('Y-m-d H:i:s', time() - $minutes * 60);
        $tagAt = gmdate('Y-m-d H:i:s', time() - ($tagMinutes ?? $minutes) * 60); // la puce a pu être posée sur la pièce plus tard qu'elle
        $piece = (int) $this->db->fetchOne('INSERT INTO loss_piece (number, variant_id, product_id, status, exercise, created_at, updated_at) VALUES (?, ?,'
            . " (SELECT product_id FROM product_variant WHERE id = ?), 'created', false, ?, ?) RETURNING id", [900000000 + random_int(1, 99999999), $variant, $variant, $at, $at]); // un numéro hors de la suite du module
        $this->db->executeStatement("INSERT INTO loss_tag (identifier, raw, technology, status, piece_id, exercise, associated_at, created_at, updated_at) VALUES (?, ?, 'nfc', 'associated', ?, false, ?, ?, ?)", [$nfc, $nfc, $piece, $tagAt, $at, $at]);

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

    /** @return list<int> les variantes d'un article créé pour ce test (une par défaut) */
    private function article(string $name, int $variants = 1): array
    {
        $slug = 'l3b2-' . bin2hex(random_bytes(4));
        $this->db->executeStatement("INSERT INTO product (id, name, price, isbestseller, quantity, created_at, slug, product_type, is_web, is_pos) VALUES (nextval('product_id_seq'), :n, 3000, false, 0, NOW(), :s, 'standard', false, true)",
            ['n' => "$name L3B2-$this->s", 's' => $slug]);
        $product = (int) $this->db->fetchOne('SELECT id FROM product WHERE slug = ?', [$slug]);
        $ids = [];
        for ($i = 0; $i < $variants; ++$i) {
            $ids[] = (int) $this->db->fetchOne("INSERT INTO product_variant (id, product_id, stock_quantity) VALUES (nextval('product_variant_id_seq'), ?, 50) RETURNING id", [$product]);
        }

        return $ids;
    }
}
