<?php

namespace App\Tests\Functional\LossPrevention;

use App\Entity\User;
use App\Services\PosAuditService\PosPinService;
use App\Services\PosOfflineService\PosOfflineCalculator;
use App\Services\PosOfflineService\PosOfflineSyncService;

/**
 * Prévention des pertes, lot L1-b : la vague 2 des règles sur de vraies opérations de caisse (retour inhabituel, remises au motif vague, prix
 * baissés à la main, validations refusées, et les variantes par personne en Floride), l'aperçu d'un réglage qui n'écrit rien, et une vente
 * hors ligne synchronisée en retard, lue par le curseur et datée à son heure réelle.
 */
class LossPreventionWave2Test extends LossPreventionTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->db->executeStatement("UPDATE entreprise SET pos_return_without_receipt = true, pos_return_cash_for_card_sale = true, pos_return_max_days = NULL, pos_approval_return = NULL,
            pos_discount_enabled = true, pos_discount_ticket_enabled = true, pos_discount_reason_required = true, pos_discount_cashier_max_percent_bp = 1000, pos_discount_cashier_max_amount = 2000,
            pos_price_override_enabled = true, pos_price_override_max_amount = NULL, pos_price_override_reason_required = false,
            pos_offline_enabled = true, pos_offline_max_hours = 24, pos_offline_max_queued_sales = 100, pos_offline_max_queued_amount = 500000, pos_offline_price_tolerance = 5, pos_offline_pack_validity_hours = 24");
        $this->provider->getEntityManager()->clear();
    }

    public function testUnusualReturnsOpenAFindingAndAnOrdinaryReturnDoesNot(): void
    {
        $this->drain();
        $cashier = $this->loginPos(['ROLE_USER_POS', 'ROLE_POS_MANAGER']);
        $this->openSession();
        $found = fn (int $returnId) => (int) $this->db->fetchOne('SELECT COUNT(*) FROM loss_finding WHERE dedup_key = ?', ['REFUND_PATTERN:return:' . $returnId]);
        $line = fn (int $orderId) => $this->json($this->pos('GET', '/api/pos/returns/lookup?orderId=' . $orderId))['lines'][0]['orderItemId'];

        // Retour ordinaire : vente en espèces, ticket, remboursement en espèces : rien
        $sale = $this->sell();
        $ordinary = $this->json($this->pos('POST', '/api/pos/returns', ['orderId' => $sale['orderId'], 'lines' => [['orderItemId' => $line((int) $sale['orderId']), 'quantity' => 1]],
            'reasonCode' => 'defectueux', 'reasonText' => 'Couture défaite', 'cashRegisterId' => $this->register]), 201);
        $run = $this->json($this->admin('POST', self::BASE . '/analyse'));
        $this->assertSame([0, 0, 0], [$run['opened'], $run['skipped'], $found((int) $ordinary['returnId'])], json_encode($run));

        // Retour sans ticket
        $noReceipt = $this->json($this->pos('POST', '/api/pos/returns', ['items' => [['productVariantId' => $this->cap, 'quantity' => 1]], 'reasonCode' => 'autre',
            'reasonText' => 'Le client a perdu son ticket', 'refund' => ['method' => 'cash'], 'cashRegisterId' => $this->register]), 201);
        // Espèces rendues pour une vente payée par carte
        $card = $this->sell([], null, 1);
        $cashForCard = $this->json($this->pos('POST', '/api/pos/returns', ['orderId' => $card['orderId'], 'lines' => [['orderItemId' => $line((int) $card['orderId']), 'quantity' => 1]],
            'reasonCode' => 'ne_convient_pas', 'refund' => ['method' => 'cash'], 'cashRegisterId' => $this->register]), 201);
        $run = $this->json($this->admin('POST', self::BASE . '/analyse'));
        $this->assertSame([2, 0], [$run['opened'], $run['skipped']], json_encode($run));
        $this->assertSame([1, 1], [$found((int) $noReceipt['returnId']), $found((int) $cashForCard['returnId'])]);

        $id = fn (int $returnId) => (int) $this->db->fetchOne('SELECT id FROM loss_finding WHERE dedup_key = ?', ['REFUND_PATTERN:return:' . $returnId]);
        $first = $this->json($this->admin('GET', self::BASE . '/constats/' . $id((int) $noReceipt['returnId'])));
        $this->assertSame(['REFUND_PATTERN', 'return', (int) $noReceipt['returnId'], 'return'], [$first['rule']['code'], $first['subject']['type'], $first['subject']['id'], $first['evidence'][0]['kind']]);
        $this->assertMatchesRegularExpression('/^Retour sans ticket de /u', $first['summary']);
        $lines = array_column($first['evidence'][0]['lines'], 'value', 'label');
        $this->assertSame(['Non', 'Le client a perdu son ticket'], [$lines['Rattaché à une vente'], $lines['Motif du retour']]);
        $this->assertStringContainsString($this->mark, $lines['Retour fait par']);
        $this->assertStringNotContainsString('perdu son ticket', implode(' ', $this->db->fetchFirstColumn('SELECT snapshot FROM loss_evidence WHERE finding_id = ?', [$first['id']])), 'le motif écrit est lu dans la caisse, jamais recopié');
        $this->assertContains(['id' => $cashier->getId(), 'role' => 'operator'], array_map(fn ($p) => ['id' => $p['id'], 'role' => $p['role']], $first['onDuty']));
        $second = $this->json($this->admin('GET', self::BASE . '/constats/' . $id((int) $cashForCard['returnId'])));
        $this->assertMatchesRegularExpression('/rendus en espèces pour une vente qui n\'avait pas été payée en espèces/u', $second['summary']);
        $this->assertSame('Oui', array_column($second['evidence'][0]['lines'], 'value', 'label')['Rattaché à une vente']);
        $this->assertSame($second['amountAtStake'], (int) $cashForCard['amount']);
        $this->closeWith(0);
    }

    /**
     * Choix C (relecture du lot, 14/11/2026) : un retour ou une annulation d'APRÈS la notice aux salariés relit une vente d'origine qui peut
     * la précéder ; le vendeur de cette vente n'est alors pas nommé. Une vente d'après la notice garde son vendeur.
     */
    public function testTheSellerOfASaleMadeBeforeTheStaffNoticeIsNeverNamed(): void
    {
        $this->drain();
        $this->loginPos(['ROLE_USER_POS', 'ROLE_POS_MANAGER']);
        $this->place('CA', 'QC');
        $this->openSession();
        $line = fn (int $orderId) => $this->json($this->pos('GET', '/api/pos/returns/lookup?orderId=' . $orderId))['lines'][0]['orderItemId'];
        $cashForCard = fn (array $sale): int => (int) $this->json($this->pos('POST', '/api/pos/returns', ['orderId' => $sale['orderId'], 'lines' => [['orderItemId' => $line((int) $sale['orderId']), 'quantity' => 1]],
            'reasonCode' => 'ne_convient_pas', 'refund' => ['method' => 'cash'], 'cashRegisterId' => $this->register]), 201)['returnId'];
        $roles = fn (string $key): array => array_column(json_decode((string) $this->db->fetchOne('SELECT on_duty FROM loss_finding WHERE dedup_key = ?', [$key]), true) ?: [], 'role');

        // AVANT la notice : une vente par carte, une vente en espèces
        $cardBefore = $this->sell([], null, 1);
        $cashBefore = $this->sellCash();
        $this->json($this->admin('PUT', self::BASE . '/reglages', ['staffNoticeConfirmed' => true]));
        usleep(1100000); // le plancher est daté à la seconde : la suite se passe la seconde d'après
        // APRÈS la notice : une vente par carte, une vente en espèces
        $cardAfter = $this->sell([], null, 1);
        $cashAfter = $this->sellCash();
        // Après la notice : espèces rendues pour les deux ventes par carte, annulation rapide des deux ventes en espèces
        $returnBefore = $cashForCard($cardBefore);
        $returnAfter = $cashForCard($cardAfter);
        foreach ([$cashBefore, $cashAfter] as $sale) {
            $this->json($this->pos('POST', '/api/order/cancel/' . $sale['orderId'], ['paymentMethod' => 2, 'cashRegisterId' => $this->register]));
        }
        $run = $this->json($this->admin('POST', self::BASE . '/analyse'));
        $this->assertSame([4, 0], [$run['opened'], $run['skipped']], json_encode($run));

        $this->assertSame(['operator'], $roles('REFUND_PATTERN:return:' . $returnBefore), 'vente d\'avant la notice : son vendeur n\'est pas nommé');
        $this->assertSame(['operator', 'seller'], $roles('REFUND_PATTERN:return:' . $returnAfter), 'vente d\'après la notice : son vendeur est en poste');
        $this->assertNotContains('seller', $roles('VOID_AFTER_SALE:order:' . $cashBefore['orderId']), 'annulation d\'une vente d\'avant la notice : vendeur non nommé');
        $this->assertContains('seller', $roles('VOID_AFTER_SALE:order:' . $cashAfter['orderId']));
        $sellerOf = fn (array $sale) => json_decode((string) $this->db->fetchOne("SELECT e.snapshot FROM loss_evidence e JOIN loss_finding f ON f.id = e.finding_id WHERE f.dedup_key = ? AND e.ref_type = 'order'",
            ['VOID_AFTER_SALE:order:' . $sale['orderId']]), true)['sellerId'] ?? null;
        $this->assertNull($sellerOf($cashBefore), 'ni dans la copie de la preuve');
        $this->assertNotNull($sellerOf($cashAfter));
        $this->closeWith(0);
    }

    public function testVagueDiscountsAndManualPriceDropsInASession(): void
    {
        $this->drain();
        $this->loginPos(['ROLE_USER_POS']);
        $session = $this->openSession();
        $opened = fn () => $this->json($this->admin('POST', self::BASE . '/analyse'))['opened'];
        // Un motif ÉCRIT sans code est un motif : trois ventes remisées ainsi n'ouvrent rien
        foreach (range(1, 3) as $n) {
            $this->sell([], ['type' => 'percent', 'value' => 5, 'reasonText' => 'Cliente fidèle depuis dix ans']);
        }
        $this->assertSame(0, $opened(), 'trois remises au motif écrit, sans code : rien');
        // Remises : un motif précis ne compte pas ; trois ventes au motif « Autre » ouvrent le constat
        $this->sell([], ['type' => 'percent', 'value' => 5, 'reasonCode' => 'geste_commercial']);
        $this->sell([], ['type' => 'percent', 'value' => 5, 'reasonCode' => 'autre']);
        $this->sell([], ['type' => 'percent', 'value' => 5, 'reasonCode' => 'autre']);
        $this->assertSame(0, $opened(), 'deux ventes au motif « Autre » : rien');
        $this->sell([], ['type' => 'percent', 'value' => 5, 'reasonCode' => 'autre']);
        $this->assertSame(1, $opened());
        $discount = $this->json($this->admin('GET', self::BASE . '/constats/' . $this->db->fetchOne('SELECT id FROM loss_finding WHERE dedup_key = ?', ['DISCOUNT_PATTERN:session:' . $session])));
        $this->assertSame(['DISCOUNT_PATTERN', 3, 'discount'], [$discount['rule']['code'], count($discount['evidence']), $discount['evidence'][0]['kind']]);
        $this->assertMatchesRegularExpression('/^3 ventes remisées au motif « Autre » ou sans motif pendant une même session/u', $discount['summary']);
        $this->assertSame('autre', array_column($discount['evidence'][0]['lines'], 'value', 'label')['Motifs de la remise']);
        $this->assertSame(array_sum(array_map(fn ($e) => (int) json_decode((string) $e, true)['discount'], $this->db->fetchFirstColumn('SELECT snapshot FROM loss_evidence WHERE finding_id = ?', [$discount['id']]))), $discount['amountAtStake']);
        // Le code « Autre » avec un motif écrit : la vente compte ; la fiche relit le texte dans la caisse, la preuve n'en garde qu'un nombre
        $this->sell([], ['type' => 'percent', 'value' => 5, 'reasonCode' => 'autre', 'reasonText' => 'Bouton manquant sur la veste']);
        $this->json($this->admin('POST', self::BASE . '/analyse'));
        $again = $this->json($this->admin('GET', self::BASE . '/constats/' . $discount['id']));
        $this->assertCount(4, $again['evidence']);
        $this->assertSame('autre, Bouton manquant sur la veste', array_column(end($again['evidence'])['lines'], 'value', 'label')['Motifs de la remise'], 'le motif écrit est lu dans la caisse, à l\'affichage');
        $snapshots = $this->db->fetchFirstColumn('SELECT snapshot FROM loss_evidence WHERE finding_id = ? ORDER BY id', [$discount['id']]);
        $this->assertStringNotContainsString('Bouton manquant', implode(' ', $snapshots), 'jamais recopié dans la preuve');
        $this->assertSame([0, 1], [json_decode((string) $snapshots[0], true)['writtenReasons'], json_decode((string) end($snapshots), true)['writtenReasons']]);

        // Prix à la main : une hausse ne compte pas ; trois baisses ouvrent le constat, le montant en jeu est la somme des baisses
        $this->sell(['priceOverride' => ['unitPrice' => 3500, 'reasonText' => 'Erreur d\'étiquette']]);
        $this->sell(['priceOverride' => ['unitPrice' => 2400, 'reasonText' => 'Prix négocié avec la cliente']]);
        $this->sell(['priceOverride' => ['unitPrice' => 2000]]);
        $this->assertSame(0, $opened(), 'une hausse et deux baisses : rien');
        $this->sell(['priceOverride' => ['unitPrice' => 1900]]);
        $this->assertSame(1, $opened());
        $price = $this->json($this->admin('GET', self::BASE . '/constats/' . $this->db->fetchOne('SELECT id FROM loss_finding WHERE dedup_key = ?', ['PRICE_OVERRIDE:session:' . $session])));
        $this->assertSame([3, 500 + 900 + 1000, 'price_change'], [count($price['evidence']), $price['amountAtStake'], $price['evidence'][0]['kind']]);
        $this->assertMatchesRegularExpression('/^3 prix baissés à la main pendant une même session, 24,00/u', $price['summary']);
        $first = array_column($price['evidence'][0]['lines'], 'value', 'label');
        $this->assertSame('Prix négocié avec la cliente', $first['Motif du prix'], 'lu dans la ligne de vente, à l\'affichage');
        $this->assertMatchesRegularExpression('/^29,00/u', $first['Prix du catalogue']);
        $this->assertMatchesRegularExpression('/^24,00/u', $first['Prix saisi']);
        $this->assertStringNotContainsString('négocié', implode(' ', $this->db->fetchFirstColumn('SELECT snapshot FROM loss_evidence WHERE finding_id = ?', [$price['id']])));
        // Le nom de l'article : lu dans l'entrée du journal de caisse à l'affichage, jamais copié dans la preuve
        $this->assertGreaterThan(2, mb_strlen((string) $first['Article']));
        foreach ($this->db->fetchFirstColumn('SELECT snapshot FROM loss_evidence WHERE finding_id = ?', [$price['id']]) as $snapshot) {
            $this->assertArrayNotHasKey('article', json_decode((string) $snapshot, true));
            $this->assertStringNotContainsString((string) $first['Article'], (string) $snapshot);
        }
        $this->closeWith(0);
    }

    /** Un plafond du caissier à 0 n'est pas un plafond : sans cela, toute vente remisée compterait « proche du plafond » */
    public function testACashierCeilingOfZeroIsNeverReached(): void
    {
        $this->drain();
        $this->place('US', 'FL');
        $this->db->executeStatement('UPDATE entreprise SET pos_discount_cashier_max_percent_bp = 0, pos_discount_cashier_max_amount = 0');
        $this->provider->getEntityManager()->clear();
        $manager = $this->loginPos(['ROLE_USER_POS', 'ROLE_POS_MANAGER']); // un responsable : ses remises passent sans validation
        $this->openSession();
        foreach (range(1, 6) as $n) {
            $this->sell([], ['type' => 'percent', 'value' => 5, 'reasonCode' => 'fidelite']);
        }
        $run = $this->json($this->admin('POST', self::BASE . '/analyse'));
        $this->assertSame(0, $run['skipped'], json_encode($run));
        $this->assertSame(0, (int) $this->db->fetchOne("SELECT COUNT(*) FROM loss_finding WHERE rule_code = 'DISCOUNT_PATTERN' AND variant = 'person' AND subject_id = ?", [$manager->getId()]),
            'six remises de 5 %, plafonds du caissier à 0 : aucune n\'est « proche du plafond »');
        $this->closeWith(0);
    }

    public function testRefusedApprovalsOnTheSameTabletDuringTheDay(): void
    {
        $this->drain();
        $terminalId = $this->activateTerminal('Tablette des validations');
        $manager = $this->user(['ROLE_USER_POS', 'ROLE_POS_MANAGER'], 'Responsable');
        static::getContainer()->get(PosPinService::class)->set($manager, '4321');
        $cashier = $this->loginPos(['ROLE_USER_POS']);
        $this->openSession();
        $try = fn () => $this->pos('POST', '/api/pos/approvals', ['action' => 'drawer_open', 'managerId' => $manager->getId(), 'pin' => '0000', 'context' => ['cashRegisterId' => $this->register]]);
        $this->assertSame('PIN_INVALID', $this->json($try(), 400)['error']);
        $this->assertSame('PIN_INVALID', $this->json($try(), 400)['error']);
        $this->assertSame(0, $this->json($this->admin('POST', self::BASE . '/analyse'))['opened'], 'deux refus : rien');
        $this->assertSame('PIN_INVALID', $this->json($try(), 400)['error']);
        $run = $this->json($this->admin('POST', self::BASE . '/analyse'));
        $this->assertSame([1, 0], [$run['opened'], $run['skipped']], json_encode($run));
        $today = $this->today();
        $finding = $this->json($this->admin('GET', self::BASE . '/constats/' . $this->db->fetchOne('SELECT id FROM loss_finding WHERE dedup_key = ?', ["APPROVAL_PATTERN:terminal:$terminalId:day:$today"])));
        $this->assertSame(['APPROVAL_PATTERN', 'terminal', $terminalId, null, 3], [$finding['rule']['code'], $finding['subject']['type'], $finding['subject']['id'], $finding['amountAtStake'], count($finding['evidence'])]);
        $this->assertMatchesRegularExpression('/^3 validations d\'un responsable refusées sur une même tablette/u', $finding['summary']);
        $lines = array_column($finding['evidence'][0]['lines'], 'value', 'label');
        $this->assertSame(['Ouverture du tiroir', 'Code du responsable faux'], [$lines['Action à valider'], $lines['Raison du refus']]);
        $this->assertStringContainsString('Responsable', $lines['Responsable sollicité']);
        $this->assertSame([['id' => $cashier->getId(), 'role' => 'operator']], array_map(fn ($p) => ['id' => $p['id'], 'role' => $p['role']], $finding['onDuty']));
        $this->assertStringNotContainsString('@', (string) json_encode($finding));
        $this->closeWith(0);
    }

    public function testPersonVariantsOfTheSecondWaveInFlorida(): void
    {
        $this->drain();
        $this->place('US', 'FL');
        $persons = fn (string $rule, int $userId) => $this->db->fetchAllAssociative("SELECT id, facts, amount_at_stake FROM loss_finding WHERE rule_code = ? AND variant = 'person' AND subject_id = ?", [$rule, $userId]);
        // Retours : trois dans la journée par le même compte
        $cashier = $this->loginPos(['ROLE_USER_POS', 'ROLE_POS_MANAGER']);
        $this->openSession();
        $line = fn (int $orderId) => $this->json($this->pos('GET', '/api/pos/returns/lookup?orderId=' . $orderId))['lines'][0]['orderItemId'];
        foreach ([1, 2, 3] as $n) {
            $sale = $this->sell();
            $this->json($this->pos('POST', '/api/pos/returns', ['orderId' => $sale['orderId'], 'lines' => [['orderItemId' => $line((int) $sale['orderId']), 'quantity' => 1]], 'reasonCode' => 'defectueux',
                'cashRegisterId' => $this->register]), 201);
            $this->json($this->admin('POST', self::BASE . '/analyse'));
            $this->assertCount($n === 3 ? 1 : 0, $persons('REFUND_PATTERN', $cashier->getId()), "retour $n");
        }
        $returns = $persons('REFUND_PATTERN', $cashier->getId())[0];
        $detail = $this->json($this->admin('GET', self::BASE . '/constats/' . $returns['id']));
        $this->assertSame([true, 'user_window', 3], [$detail['personPattern'], $detail['subject']['type'], count($detail['evidence'])]);
        $this->assertMatchesRegularExpression('/^3 retours dans une même journée par un même compte/u', $detail['summary']);
        $this->assertStringNotContainsString($this->mark, $detail['summary']);

        // Remises proches du plafond du caissier (10 % ; seuil à 90 % du plafond) : cinq ventes en sept jours par le même compte
        $seller = $this->loginPos(['ROLE_USER_POS']);
        foreach ([1, 2, 3, 4, 5] as $n) {
            $this->sell([], ['type' => 'percent', 'value' => $n === 2 ? 5 : 10, 'reasonCode' => 'fidelite']);
        }
        $this->json($this->admin('POST', self::BASE . '/analyse'));
        $this->assertCount(0, $persons('DISCOUNT_PATTERN', $seller->getId()), 'quatre remises proches du plafond et une à 5 % : rien');
        $this->sell([], ['type' => 'percent', 'value' => 10, 'reasonCode' => 'fidelite']);
        $this->json($this->admin('POST', self::BASE . '/analyse'));
        $discounts = $persons('DISCOUNT_PATTERN', $seller->getId());
        $this->assertCount(1, $discounts);
        $this->assertSame([5, 7, 90], array_values(array_intersect_key(json_decode((string) $discounts[0]['facts'], true), ['count' => 1, 'days' => 1, 'share' => 1])));
        // Éteinte pour cette règle seulement : la vente suivante n'ajoute rien
        $this->json($this->admin('PUT', self::BASE . '/regles/DISCOUNT_PATTERN', ['personVariant' => ['enabled' => false]]));
        $this->sell([], ['type' => 'percent', 'value' => 10, 'reasonCode' => 'fidelite']);
        $run = $this->json($this->admin('POST', self::BASE . '/analyse'));
        $this->assertSame([0, 0], [$run['opened'], $run['completed']]);
        $this->closeWith(0);
    }

    public function testPreviewingAThresholdPlaysTheRealRulesAndWritesNothing(): void
    {
        $this->drain();
        $this->loginPos(['ROLE_USER_POS']);
        $preview = fn (array $body, int $status = 200) => $this->json($this->admin('POST', self::BASE . '/regles/apercu', $body), $status);
        $before = $preview(['code' => 'CASH_DISCREPANCY', 'params' => ['minAmount' => 100]]);
        $beforeHigh = $preview(['code' => 'CASH_DISCREPANCY', 'params' => ['minAmount' => 1000]]);
        foreach ([-300, -700, -1200] as $delta) {
            $this->openSession();
            $this->closeWith($delta, 'Aperçu');
        }
        $state = fn () => [(int) $this->db->fetchOne('SELECT COUNT(*) FROM loss_finding'), (int) $this->db->fetchOne('SELECT COUNT(*) FROM loss_evidence'), (int) $this->db->fetchOne('SELECT COUNT(*) FROM loss_journal'),
            (int) $this->db->fetchOne('SELECT COUNT(*) FROM loss_rule'), $this->db->fetchOne('SELECT cursor_sequence FROM loss_setting'), (int) $this->db->fetchOne('SELECT COUNT(*) FROM pos_audit_log')];
        $untouched = $state();
        $low = $preview(['code' => 'CASH_DISCREPANCY', 'params' => ['minAmount' => 100]]);
        $high = $preview(['code' => 'cash_discrepancy', 'params' => ['minAmount' => 1000]]);
        // Réglage actuel (500) : deux des trois clôtures ; à 100 : les trois ; à 1 000 : une seule
        $this->assertSame([2, 3, 1], [$low['current'] - $before['current'], $low['wouldOpen'] - $before['wouldOpen'], $high['wouldOpen'] - $beforeHigh['wouldOpen']]);
        $this->assertSame(['CASH_DISCREPANCY', 30, false, 0], [$low['code'], $low['days'], $low['truncated'], $low['skipped']]);
        $this->assertLessThanOrEqual(3, count($low['sample']));
        $this->assertNotEmpty($low['sample']);
        $this->assertMatchesRegularExpression('/^Clôture n° /u', $low['sample'][0]['summary']);
        $this->assertStringNotContainsString($this->mark, (string) json_encode($low), 'l\'aperçu ne nomme personne');
        $this->assertSame($untouched, $state(), 'l\'aperçu n\'écrit rien : ni constat, ni preuve, ni journal, ni réglage, ni curseur');
        $this->assertSame(0, $preview(['code' => 'CASH_DISCREPANCY', 'enabled' => false])['wouldOpen'], 'règle éteinte dans la proposition : rien');
        // L'aperçu d'une règle de session
        $this->assertSame(['DRAWER_NO_SALE', 30], array_values(array_intersect_key($preview(['code' => 'DRAWER_NO_SALE', 'params' => ['maxPerSession' => 0]]), ['code' => 1, 'days' => 1])));
        // Refus
        $this->assertSame('RULE_NOT_FOUND', $preview(['code' => 'NOPE'], 404)['error']);
        $this->assertSame(['params.minAmount' => 'PARAM_OUT_OF_RANGE'], $preview(['code' => 'CASH_DISCREPANCY', 'params' => ['minAmount' => -1]], 422)['fields']);
        $this->assertSame(['personVariant.enabled' => 'NOT_ALLOWED_IN_COUNTRY'], $preview(['code' => 'CASH_DISCREPANCY', 'personVariant' => ['enabled' => true]], 422)['fields']);
        $this->flags(false, false);
        $this->assertSame('MODULE_NOT_GRANTED', $preview(['code' => 'CASH_DISCREPANCY'], 409)['error'], 'pas d\'aperçu sans le droit du module : il lit la caisse');
        $this->flags(false, true);
        $this->place('CA', 'QC');
        $this->assertSame('STAFF_NOTICE_REQUIRED', $preview(['code' => 'CASH_DISCREPANCY'], 409)['error']);
        $this->place('CA', 'ON');
        $this->assertSame($untouched, $state());
        // Rôles : comme le reste de la section
        $this->assertSame(403, $this->call('POST', self::BASE . '/regles/apercu', ['code' => 'CASH_DISCREPANCY'], $this->posToken)->getStatusCode());
        $this->assertSame(401, $this->call('POST', self::BASE . '/regles/apercu', ['code' => 'CASH_DISCREPANCY'], null)->getStatusCode());
    }

    public function testAnOfflineSaleSyncedLateIsReadByTheCursorAndDatedAtItsRealTime(): void
    {
        $this->drain();
        $terminalId = $this->activateTerminal('Tablette hors ligne');
        $seller = $this->loginPos(['ROLE_USER_POS', 'ROLE_POS_MANAGER']);
        $this->openSession();
        $pack = $this->json($this->pos('GET', "/api/pos/offline-pack?cashRegisterId=$this->register"));
        $previousHash = '';
        $sync = function (string $provisional, string $soldAt) use ($seller, $pack, &$previousHash): int {
            $sale = $this->offlineSale($seller, $provisional, $pack, $soldAt, $previousHash);
            $previousHash = $sale['chain']['hash']; // les ventes d'une tablette sont chaînées
            $r = $this->json($this->pos('POST', '/api/pos/offline-sales', ['packVersion' => $pack['packVersion'], 'deviceTime' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM), 'clockOffsetSeconds' => 0, 'sales' => [$sale]]));
            $this->assertSame('accepted', $r['results'][0]['status'], json_encode($r['results'][0]));

            return (int) $r['results'][0]['orderId'];
        };
        // L'analyse est à jour AVANT la synchronisation : la vente n'existe pas encore pour le serveur
        $this->assertFalse($this->json($this->admin('POST', self::BASE . '/analyse'))['pending']);
        $found = fn (int $order) => (int) $this->db->fetchOne('SELECT COUNT(*) FROM loss_finding WHERE dedup_key = ?', ['VOID_AFTER_SALE:order:' . $order]);

        // Vendue hors ligne il y a deux heures, synchronisée maintenant, annulée aussitôt : à l'heure de la synchronisation ce serait une annulation
        // « rapide » ; à son heure réelle, elle a deux heures : aucun constat
        $old = $sync('P-' . $terminalId . '-1', '-2 hours');
        $soldAgo = time() - (new \DateTimeImmutable((string) $this->db->fetchOne('SELECT order_date FROM "order" WHERE id = ?', [$old]), new \DateTimeZone('UTC')))->getTimestamp();
        $this->assertGreaterThan(7000, $soldAgo, 'la vente garde son heure réelle');
        $this->json($this->pos('POST', '/api/order/cancel/' . $old, ['paymentMethod' => 2, 'cashRegisterId' => $this->register]));
        $run = $this->json($this->admin('POST', self::BASE . '/analyse'));
        $this->assertGreaterThanOrEqual(1, $run['read'], 'les entrées écrites à la synchronisation et à l\'annulation sont lues par le curseur');
        $this->assertSame([0, 0, 0], [$run['opened'], $run['skipped'], $found($old)], 'datée à son heure réelle : hors délai');

        // Vendue hors ligne il y a cinq minutes, synchronisée et annulée maintenant : dans le délai de dix minutes, constat
        $recent = $sync('P-' . $terminalId . '-2', '-5 minutes');
        $this->json($this->pos('POST', '/api/order/cancel/' . $recent, ['paymentMethod' => 2, 'cashRegisterId' => $this->register]));
        $run = $this->json($this->admin('POST', self::BASE . '/analyse'));
        $this->assertSame([1, 1], [$run['opened'], $found($recent)], json_encode($run));
        $facts = json_decode((string) $this->db->fetchOne('SELECT facts FROM loss_finding WHERE dedup_key = ?', ['VOID_AFTER_SALE:order:' . $recent]), true);
        $this->assertContains($facts['delayMinutes'], [4, 5], 'délai compté depuis l\'heure réelle de la vente, pas depuis la synchronisation');
        $this->assertFalse($this->json($this->admin('POST', self::BASE . '/analyse'))['pending']);
        $this->closeWith(0);
    }

    // ----- helpers -----

    /**
     * Une vente hors ligne d'une casquette, calculée et chaînée comme le fait la tablette (règle du pack).
     *
     * @param array<string, mixed> $pack
     * @return array<string, mixed>
     */
    private function offlineSale(User $seller, string $provisional, array $pack, string $soldAt, string $prevHash): array
    {
        $price = null;
        foreach ($pack['products'] as $p) {
            foreach ($p['variants'] as $v) {
                if ($v['id'] === $this->cap) {
                    $price = $v['price'] ?? $p['price'];
                }
            }
        }
        $this->assertNotNull($price, 'la casquette est dans le pack');
        $items = [['productVariantId' => $this->cap, 'quantity' => 1]];
        $r = PosOfflineCalculator::compute([['unitPrice' => $price, 'quantity' => 1, 'discounts' => []]], null, $pack['taxes'], $pack['site']['cashRoundingStep'], null);
        $sale = ['idempotencyKey' => self::uuid(), 'provisionalNumber' => $provisional, 'cashRegisterId' => $this->register, 'sellerId' => $seller->getId(), 'soldAt' => (new \DateTimeImmutable($soldAt))->format(\DateTimeInterface::ATOM),
            'items' => $items, 'ticketDiscount' => null, 'payments' => [['type' => 2, 'amount' => $r['cashTotal']]], 'cashReceived' => $r['cashTotal'],
            'totals' => ['subtotal' => $r['subtotal'], 'taxes' => array_map(fn ($t) => ['label' => $t['label'], 'amount' => $t['amount']], $r['taxes']), 'total' => $r['total'], 'cashTotal' => $r['cashTotal'], 'cashRounding' => $r['cashRounding']]];
        $sale['chain'] = ['seq' => (int) substr($provisional, strrpos($provisional, '-') + 1), 'prevHash' => $prevHash, 'hash' => PosOfflineSyncService::hashOf($sale, $prevHash)];

        return $sale;
    }
}
