<?php

namespace App\Services\AccountingService;

use App\Entity\CashRegister;
use App\Entity\CashRegisterReport;
use App\Entity\Entreprise;
use App\Entity\User;
use App\Services\CaisseService\CashRegisterException;
use App\Services\CaisseService\StoreClock;
use App\Services\PosAuditService\PosApprovalService;
use App\Services\PosAuditService\PosAuditService;
use App\Services\TenantEntityManagerProvider;

/**
 * Export comptable de la caisse (F14, 29/10/2026) à partir des sessions CLÔTURÉES (rapports Z). Règle de la journée comptable :
 * un rapport Z entier est rattaché à la date d'OUVERTURE de sa session, dans le fuseau du magasin, jamais coupé sur deux jours.
 * Les montants d'une session viennent des commandes, paiements, retours et mouvements de la session (données figées à la
 * clôture) ; le rapport Z donne la pièce, les espèces comptées, l'écart et le dépôt en banque. Les rapports clôturés avant F9
 * et F8 (blocs cartes-cadeaux et clients absents) sont signalés (legacyReports). Écritures produites par le service commun
 * JournalEntryBuilder (source « pos »).
 */
class PosAccountingExportService
{
    public function __construct(private readonly TenantEntityManagerProvider $emProvider, private readonly StoreClock $clock, private readonly JournalEntryBuilder $entries, private readonly ChartOfAccounts $chart, private readonly PosAuditService $audit)
    {
    }

    /**
     * @return array{period: array, timezone: string, rule: string, days: list<array>, entries: list<array>, totals: array, reports: list<array>, legacyReports: list<int>, accounts: array}
     */
    public function build(User $actor, mixed $from, mixed $to, mixed $cashRegisterId, string $format = 'json'): array
    {
        if (!PosApprovalService::isManager($actor)) {
            throw new CashRegisterException('FORBIDDEN', 'Export comptable réservé au responsable de caisse', 403);
        }
        $em = $this->emProvider->getEntityManager();
        $conn = $em->getConnection();
        [$start, $end, $fromDay, $toDay] = $this->clock->range($from, $to);
        $register = is_numeric($cashRegisterId) ? $em->find(CashRegister::class, (int) $cashRegisterId) : null;
        if (is_numeric($cashRegisterId) && $register === null) {
            throw new CashRegisterException('CASH_REGISTER_NOT_FOUND', 'Poste de caisse introuvable', 404, [], ['cashRegisterId' => (int) $cashRegisterId]);
        }
        // Rapports Z dont la session a été OUVERTE dans la période (fuseau du magasin)
        $qb = $em->createQueryBuilder()->select('z')->from(CashRegisterReport::class, 'z')->join('z.session', 's')
            ->andWhere('s.createdAt >= :start')->andWhere('s.createdAt < :end')->setParameter('start', $start)->setParameter('end', $end)->orderBy('s.createdAt', 'ASC')->addOrderBy('z.id', 'ASC');
        if ($register) {
            $qb->andWhere('z.cashRegister = :register')->setParameter('register', $register);
        }
        /** @var list<CashRegisterReport> $reports */
        $reports = $qb->getQuery()->getResult();
        $days = [];
        $entries = [];
        $legacy = [];
        $reportRows = [];
        foreach ($reports as $z) {
            $day = $this->clock->day($z->getSession()->getCreatedAt());
            $amounts = $this->amountsForSession((int) $z->getSession()->getId(), $z);
            $data = $z->getData();
            if (!array_key_exists('giftCards', $data) || !array_key_exists('customers', $data)) {
                $legacy[] = $z->getId();
            }
            $piece = sprintf('Z-%d-%d', $z->getCashRegister()->getId(), $z->getNumber());
            $label = sprintf('Caisse %s, session %d', $z->getCashRegister()->getName(), $z->getSession()->getId());
            foreach ($this->entries->build($amounts, 'pos', $day, $piece, 'VT', $label) as $line) {
                $entries[] = $line;
            }
            $days[$day] ??= self::emptyDay($day);
            $days[$day] = self::addDay($days[$day], $amounts, $z);
            $reportRows[] = ['reportId' => $z->getId(), 'piece' => $piece, 'number' => $z->getNumber(), 'cashRegisterId' => $z->getCashRegister()->getId(), 'sessionId' => $z->getSession()->getId(), 'day' => $day,
                'openedAt' => \DateTimeImmutable::createFromInterface($z->getSession()->getCreatedAt())->format(\DateTimeInterface::ATOM), 'closedAt' => $z->getClosedAt()->format(\DateTimeInterface::ATOM),
                'countedCash' => $z->getCountedCash(), 'expectedCash' => $z->getExpectedCash(), 'discrepancy' => $z->getDiscrepancy(), 'bankDeposit' => $z->getBankDeposit(), 'legacy' => in_array($z->getId(), $legacy, true)];
        }
        ksort($days);
        $byDay = [];
        foreach ($entries as $line) {
            $byDay[$line['date']] ??= ['debit' => 0, 'credit' => 0];
            $byDay[$line['date']]['debit'] += $line['debit'];
            $byDay[$line['date']]['credit'] += $line['credit'];
        }
        $totals = JournalEntryBuilder::totals($entries) + ['byDay' => array_map(fn ($d) => $d + ['balanced' => $d['debit'] === $d['credit']], $byDay)];
        $this->audit->record(PosAuditService::EXPORT_GENERATED, ['format' => $format, 'from' => $fromDay, 'to' => $toDay, 'cashRegisterId' => $register?->getId(), 'reports' => count($reports), 'legacyReports' => count($legacy)], null, $register, 'export', null, $actor);

        return ['period' => ['from' => $fromDay, 'to' => $toDay, 'cashRegisterId' => $register?->getId()], 'timezone' => $this->clock->settings()['timezone'],
            'rule' => 'Un rapport Z entier est rattaché à la date d\'ouverture de sa session, dans le fuseau du magasin ; jamais coupé sur deux jours.',
            'days' => array_values($days), 'entries' => $entries, 'totals' => $totals, 'reports' => $reportRows, 'legacyReports' => $legacy,
            'legacyNote' => $legacy ? 'Rapports clôturés avant les lots F9 (cartes-cadeaux) et F8 (clients) : blocs absents comptés à 0' : null,
            'accounts' => $this->chart->accounts(), 'source' => 'pos'];
    }

    /** Synthèse CSV par jour (« ; », virgule décimale, BOM) */
    public function summaryCsv(array $export): string
    {
        $header = ['Date', 'Rapports Z', 'Ventes (nombre)', 'Ventes HT brut', 'Remises', 'Récompenses fidélité', 'Ventes HT net', 'Arrondi espèces'];
        $taxLabels = [];
        foreach ($export['days'] as $d) {
            foreach ($d['taxes'] as $label => $_) {
                $taxLabels[$label] = true;
            }
        }
        $taxLabels = array_keys($taxLabels);
        foreach ($taxLabels as $l) {
            $header[] = 'Base ' . $l;
            $header[] = $l;
        }
        $header = array_merge($header, ['Ventes TTC', 'Encaissé espèces', 'Encaissé carte déclarée', 'Encaissé terminal carte', 'Encaissé Interac', 'Encaissé PayPal', 'Réglé par carte-cadeau', 'Réglé par avoir',
            'Retours (nombre)', 'Retours TTC', 'Remboursé espèces', 'Remboursé carte', 'Recrédit cartes-cadeaux', 'Avoirs émis', 'Cartes-cadeaux vendues (nombre)', 'Cartes-cadeaux vendues', 'Cartes-cadeaux remboursées', 'Écart de caisse', 'Dépôt en banque', 'Entrées de fond', 'Sorties de fond', 'Dépôts', 'Retraits', 'Coupons fabricant acceptés', 'Coupons fabricant annulés']); // deux colonnes ajoutées en fin (F17)
        $rows = [];
        $f = fn (int $c) => AccountingCsv::fr($c);
        foreach ($export['days'] as $d) {
            $row = [$d['date'], implode(' ', $d['pieces']), $d['salesCount'], $f($d['salesGrossHt']), $f($d['discounts']), $f($d['loyaltyRewards']), $f($d['salesNetHt']), $f($d['cashRounding'])];
            foreach ($taxLabels as $l) {
                $row[] = $f($d['taxes'][$l]['base'] ?? 0);
                $row[] = $f($d['taxes'][$l]['tax'] ?? 0);
            }
            $rows[] = array_merge($row, [$f($d['salesTtc']), $f($d['receipts']['cash']), $f($d['receipts']['declaredCard']), $f($d['receipts']['card']), $f($d['receipts']['interac']), $f($d['receipts']['paypal']), $f($d['receipts']['giftCard']), $f($d['receipts']['storeCredit']),
                $d['returnsCount'], $f($d['refundsTtc']), $f($d['refunds']['cash']), $f($d['refunds']['card'] + $d['refunds']['interac']), $f($d['refunds']['giftCard']), $f($d['refunds']['storeCredit']), $d['giftCardsSoldCount'], $f($d['giftCardsSold']), $f($d['giftCardsRefunded']),
                $f($d['discrepancy']), $f($d['bankDeposit']), $f($d['fundDeposits']), $f($d['fundWithdrawals']), $f($d['deposits']), $f($d['withdrawals']), $f($d['receipts']['coupon'] ?? 0), $f($d['refunds']['coupon'] ?? 0)]);
        }

        return AccountingCsv::write($header, $rows);
    }

    /** QuickBooks Online n'importe pas plus de 1 000 lignes par fichier */
    public const QBO_MAX_LINES = 1000;

    /**
     * Écritures : « generic » (Date;Journal;Compte;Libellé du compte;Libellé;Débit;Crédit;Pièce;Source) ou « qbo » (QuickBooks Online :
     * JournalNo,JournalDate,AccountName,Description,Debits,Credits). Format qbo au-delà de $maxLines lignes : 413 EXPORT_TOO_LARGE avec
     * la période maximale conseillée (suggestedTo : dernier jour entier tenant dans la limite, les pièces ne sont jamais coupées).
     */
    public function entriesCsv(array $export, string $format, int $maxLines = self::QBO_MAX_LINES): string
    {
        if ($format === 'qbo' && count($export['entries']) > $maxLines) {
            $count = 0;
            $suggestedTo = null;
            foreach ($export['days'] as $day) {
                $dayLines = count(array_filter($export['entries'], fn ($l) => $l['date'] === $day['date']));
                if ($count + $dayLines > $maxLines) {
                    break;
                }
                $count += $dayLines;
                $suggestedTo = $day['date'];
            }
            throw new CashRegisterException('EXPORT_TOO_LARGE', sprintf('QuickBooks Online n\'importe pas plus de %d lignes par fichier : %d écritures sur la période. Réduisez la période (%s).', $maxLines, count($export['entries']), $suggestedTo ? 'au plus jusqu\'au ' . $suggestedTo : 'une seule journée dépasse déjà la limite : exportez-la au format generic'), 413, [],
                ['lines' => count($export['entries']), 'limit' => $maxLines, 'from' => $export['period']['from'], 'to' => $export['period']['to'], 'suggestedTo' => $suggestedTo, 'linesUntilSuggestedTo' => $count]);
        }
        if ($format === 'qbo') {
            $rows = [];
            foreach ($export['entries'] as $l) {
                $rows[] = [$l['piece'], (new \DateTimeImmutable($l['date']))->format('d/m/Y'), $l['accountName'], $l['label'], $l['debit'] > 0 ? AccountingCsv::en($l['debit']) : '', $l['credit'] > 0 ? AccountingCsv::en($l['credit']) : ''];
            }

            return AccountingCsv::write(['JournalNo', 'JournalDate', 'AccountName', 'Description', 'Debits', 'Credits'], $rows, ',', false);
        }
        $rows = [];
        foreach ($export['entries'] as $l) {
            $rows[] = [$l['date'], $l['journal'], $l['account'], $l['accountName'], $l['label'], AccountingCsv::fr($l['debit']), AccountingCsv::fr($l['credit']), $l['piece'], $l['source']];
        }

        return AccountingCsv::write(['Date', 'Journal', 'Compte', 'Libellé du compte', 'Libellé', 'Débit', 'Crédit', 'Pièce', 'Source'], $rows);
    }

    /** Montants d'une session clôturée (cents), pour JournalEntryBuilder et la synthèse */
    public function amountsForSession(int $caisseId, CashRegisterReport $z): array
    {
        $conn = $this->emProvider->getEntityManager()->getConnection();
        $orders = $conn->fetchAllAssociative('SELECT DISTINCT o.id, o.order_type_id, o.status_id, o.canceled_at, o.total_amount, o.cash_rounding, o.gift_card_applied, o.credit_applied, o.discount_amount, o.discount_reason_text FROM "order" o JOIN transaction_caisse t ON t.order_caisse_id = o.id WHERE t.caisse_id = ?', [$caisseId]);
        // PAYS-1b : transactions de la session par commande (vente, remboursement) : une annulation se lit dans la session de son remboursement
        $txTypes = [];
        foreach ($conn->fetchAllAssociative('SELECT order_caisse_id AS id, transaction_type_id AS type FROM transaction_caisse WHERE caisse_id = ? AND order_caisse_id IS NOT NULL', [$caisseId]) as $tx) {
            $txTypes[(int) $tx['id']][(int) $tx['type']] = true;
        }
        $saleIds = [];
        $returnIds = [];
        $giftIds = [];
        // PAYS-1b (12/11/2026) : vente annulée après PAYS-1b (canceled_at, montants gardés). Même session (vente et remboursement ici) : hors
        // ventes, encaissements et remboursement comptés ; vente d'ici annulée ailleurs : vente gardée, sans le remboursement de l'autre
        // session ; annulation ici d'une vente d'une autre session : contre-passation dans le bloc des retours, avec le remboursement.
        // Les annulations antérieures (montants remis à 0, canceled_at null) restent traitées comme avant.
        $receiptOnlyIds = []; // annulées dans la session : encaissements seulement
        $refundPayIds = []; // commandes de type 1 dont les paiements négatifs sont des remboursements de cette session
        $reversalIds = []; // contre-passations (annulation ici d'une vente d'une autre session)
        foreach ($orders as $o) {
            $id = (int) $o['id'];
            if ((int) $o['order_type_id'] === 1 && $o['canceled_at'] !== null) {
                $sale = isset($txTypes[$id][1]);
                $refund = isset($txTypes[$id][2]);
                if ($sale && $refund) {
                    $receiptOnlyIds[] = $id;
                    $refundPayIds[] = $id;
                } elseif ($sale) {
                    $saleIds[] = $id;
                } elseif ($refund) {
                    $reversalIds[] = $id;
                    $refundPayIds[] = $id;
                }
                continue;
            }
            match ((int) $o['order_type_id']) { 1 => $saleIds[] = $id, 2 => $returnIds[] = $id, 3 => $giftIds[] = $id, default => null };
            if ((int) $o['order_type_id'] === 1) {
                $refundPayIds[] = $id; // comme avant
            }
        }
        $receiptIds = array_merge($saleIds, $receiptOnlyIds);
        $in = fn (array $ids) => $ids ? implode(',', $ids) : '0';
        // Ventes : net HT (lignes), brut HT (lignes + remises), TTC, arrondi, remises, récompenses fidélité (remise de ticket « Récompense fidélité »)
        $sales = $conn->fetchAssociative('SELECT COUNT(*) AS n, COALESCE(SUM(total_amount), 0) AS ttc, COALESCE(SUM(cash_rounding), 0) AS rounding, COALESCE(SUM(gift_card_applied), 0) AS gift, COALESCE(SUM(manufacturer_coupon_applied), 0) AS coupon, COALESCE(SUM(credit_applied), 0) AS credit FROM "order" WHERE id IN (' . $in($saleIds) . ')');
        $items = $conn->fetchAssociative('SELECT COALESCE(SUM(total_price), 0) AS net, COALESCE(SUM(total_price + discount_amount), 0) AS gross, COALESCE(SUM(discount_amount), 0) AS discounts FROM order_items WHERE order_associated_id IN (' . $in($saleIds) . ')');
        $rewards = (int) $conn->fetchOne('SELECT COALESCE(SUM(amount), 0) FROM pos_loyalty_transaction WHERE kind = \'redeem\' AND order_id IN (' . $in($saleIds) . ')');
        $taxes = [];
        // Assiette par taxe (F18 partie 2a) : order_tax.base (HT de la taxe sur la commande) ; commande antérieure sans assiette : net HT taxable de la commande
        foreach ($conn->fetchAllAssociative('SELECT CASE WHEN ot.tax_source IS NOT NULL THEN ot.tax_label ELSE t.name END AS label, CASE WHEN ot.tax_source IS NOT NULL THEN ot.tax_rate_ppm::float8 / 1000000 ELSE t.rate END AS rate, CASE WHEN ot.tax_source IS NOT NULL THEN ot.tax_code END AS code, COALESCE(SUM(ot.amount), 0) AS tax, COALESCE(SUM(COALESCE(ot.base, (SELECT SUM(oi.total_price) FROM order_items oi WHERE oi.taxable AND oi.order_associated_id = ot.order_tax_id))), 0) AS base FROM order_tax ot JOIN tax t ON t.id = ot.tax_id WHERE ot.order_tax_id IN (' . $in($saleIds) . ') GROUP BY 1, 2, 3 ORDER BY MIN(t.id)') as $t) { // PAYS-1 : libellé, taux et code gelés à la vente
            $taxes[] = ['label' => $t['label'], 'rate' => (float) $t['rate'], 'base' => (int) round((float) $t['base']), 'tax' => (int) round((float) $t['tax'])] + ($t['code'] !== null ? ['code' => $t['code']] : []);
        }
        // Encaissements des ventes par moyen (paiements positifs), terminal / Interac / déclarée pour la carte
        $paid = $receiptOnlyIds === [] ? $sales : $conn->fetchAssociative('SELECT COALESCE(SUM(gift_card_applied), 0) AS gift, COALESCE(SUM(manufacturer_coupon_applied), 0) AS coupon FROM "order" WHERE id IN (' . $in($receiptIds) . ')');
        $receipts = ['cash' => 0, 'declaredCard' => 0, 'card' => 0, 'interac' => 0, 'paypal' => 0, 'giftCard' => (int) $paid['gift'], 'storeCredit' => 0, 'coupon' => (int) $paid['coupon']]; // coupon : F17
        $redeemed = $conn->fetchAllAssociative('SELECT c.kind, COALESCE(SUM(-tx.amount), 0) AS amount FROM pos_gift_card_transaction tx JOIN pos_gift_card c ON c.id = tx.gift_card_id WHERE tx.kind = \'redeem\' AND tx.order_id IN (' . $in($receiptIds) . ') GROUP BY c.kind');
        foreach ($redeemed as $r) {
            if ($r['kind'] === 'store_credit') {
                $receipts['storeCredit'] = (int) $r['amount'];
                $receipts['giftCard'] -= (int) $r['amount'];
            }
        }
        $this->splitPayments($conn, $receiptIds, true, $receipts);
        // Retours et annulations : commandes de type 2 de la session + paiements négatifs des ventes annulées
        $returns = $conn->fetchAssociative('SELECT COUNT(*) AS n, COALESCE(SUM(-total_amount), 0) AS ttc FROM "order" WHERE id IN (' . $in($returnIds) . ')');
        // Lignes au prix payé (positives) ; commande à prix TTC : la taxe incluse est retirée (avant le 12/11/2026, un retour TTC était
        // compté TTC en « Retours HT » et l'écart partait en « Écart d'arrondi »)
        $htOf = fn (array $ids) => abs((int) round((float) $conn->fetchOne('SELECT COALESCE(SUM(total_price), 0) FROM order_items WHERE order_associated_id IN (' . $in($ids) . ')')))
            - (int) round((float) $conn->fetchOne('SELECT COALESCE(SUM(ABS(ot.amount)), 0) FROM order_tax ot JOIN "order" o ON o.id = ot.order_tax_id WHERE o.prices_include_tax AND o.id IN (' . $in($ids) . ')'));
        $refundsHt = $htOf($returnIds);
        $refundTaxes = [];
        foreach ($conn->fetchAllAssociative('SELECT CASE WHEN ot.tax_source IS NOT NULL THEN ot.tax_label ELSE t.name END AS label, CASE WHEN ot.tax_source IS NOT NULL THEN ot.tax_rate_ppm::float8 / 1000000 ELSE t.rate END AS rate, CASE WHEN ot.tax_source IS NOT NULL THEN ot.tax_code END AS code, COALESCE(SUM(-ot.amount), 0) AS tax FROM order_tax ot JOIN tax t ON t.id = ot.tax_id WHERE ot.order_tax_id IN (' . $in($returnIds) . ') GROUP BY 1, 2, 3 ORDER BY MIN(t.id)') as $t) {
            $refundTaxes[] = ['label' => $t['label'], 'rate' => (float) $t['rate'], 'tax' => (int) round((float) $t['tax'])] + ($t['code'] !== null ? ['code' => $t['code']] : []);
        }
        $reversals = [];
        $reversalRounding = 0;
        if ($reversalIds !== []) { // PAYS-1b : contre-passation des ventes d'autres sessions annulées ici, aux valeurs gelées de la vente (PAYS-1)
            $rv = $conn->fetchAssociative('SELECT COUNT(*) AS n, COALESCE(SUM(total_amount), 0) AS ttc FROM "order" WHERE id IN (' . $in($reversalIds) . ')');
            $returns['n'] = (int) $returns['n'] + (int) $rv['n'];
            $returns['ttc'] = (float) $returns['ttc'] + (float) $rv['ttc'];
            $refundsHt += $htOf($reversalIds);
            foreach ($conn->fetchAllAssociative('SELECT CASE WHEN ot.tax_source IS NOT NULL THEN ot.tax_label ELSE t.name END AS label, CASE WHEN ot.tax_source IS NOT NULL THEN ot.tax_rate_ppm::float8 / 1000000 ELSE t.rate END AS rate, CASE WHEN ot.tax_source IS NOT NULL THEN ot.tax_code END AS code, COALESCE(SUM(ot.amount), 0) AS tax FROM order_tax ot JOIN tax t ON t.id = ot.tax_id WHERE ot.order_tax_id IN (' . $in($reversalIds) . ') GROUP BY 1, 2, 3 ORDER BY MIN(t.id)') as $t) {
                $merged = false;
                foreach ($refundTaxes as &$r) {
                    if ($r['label'] === $t['label'] && $r['rate'] === (float) $t['rate'] && ($r['code'] ?? null) === $t['code']) {
                        $r['tax'] += (int) round((float) $t['tax']);
                        $merged = true;
                    }
                }
                unset($r);
                if (!$merged) {
                    $refundTaxes[] = ['label' => $t['label'], 'rate' => (float) $t['rate'], 'tax' => (int) round((float) $t['tax'])] + ($t['code'] !== null ? ['code' => $t['code']] : []);
                }
            }
            // arrondi des espèces rendu par le remboursement (porté par sa transaction ; celui perçu à la vente reste dans la session de la vente)
            $reversalRounding = (int) $conn->fetchOne('SELECT COALESCE(SUM(cash_rounding), 0) FROM transaction_caisse WHERE caisse_id = ? AND transaction_type_id = 2 AND order_caisse_id IN (' . $in($reversalIds) . ')', [$caisseId]);
            foreach ($conn->fetchAllAssociative('SELECT r.number, cr.receipt_prefix FROM pos_receipt r JOIN cash_register cr ON cr.id = r.cash_register_id WHERE r.kind = \'ticket\' AND r.order_id IN (' . $in($reversalIds) . ') ORDER BY r.number') as $r) {
                $reversals[] = ($r['receipt_prefix'] ?? '') . $r['number'];
            }
        }
        $refunds = ['cash' => 0, 'card' => 0, 'interac' => 0, 'paypal' => 0, 'giftCard' => 0, 'storeCredit' => 0,
            'coupon' => (int) $conn->fetchOne("SELECT COALESCE(SUM(amount), 0) FROM pos_manufacturer_coupon WHERE voided_caisse_id = ? AND status IN ('voided', 'returned_after_claim')", [$caisseId])]; // coupons annulés par les retours de la session (F17)
        $pr = $conn->fetchAssociative('SELECT COALESCE(SUM(cash_refund), 0) AS cash, COALESCE(SUM(card_refund), 0) AS card, COALESCE(SUM(gift_card_refund), 0) AS gift, COALESCE(SUM(CASE WHEN store_credit_id IS NOT NULL THEN amount - credit_applied - cash_refund - card_refund - gift_card_refund ELSE 0 END), 0) AS credits FROM pos_return WHERE caisse_id = ?', [$caisseId]);
        $refunds['cash'] = (int) $pr['cash'];
        $refunds['card'] = (int) $pr['card'];
        $refunds['giftCard'] = (int) $pr['gift'];
        $refunds['storeCredit'] = (int) $pr['credits'];
        // Annulations (ventes passées au statut 7 dans cette session : paiements négatifs sur les ventes)
        $canceled = ['cash' => 0, 'declaredCard' => 0, 'card' => 0, 'interac' => 0, 'paypal' => 0, 'giftCard' => 0, 'storeCredit' => 0];
        $this->splitPayments($conn, $refundPayIds, false, $canceled); // PAYS-1b : remboursements des annulations de cette session seulement
        $refunds['cash'] += $canceled['cash'];
        $refunds['card'] += $canceled['card'] + $canceled['declaredCard'];
        $refunds['interac'] += $canceled['interac'];
        // Cartes-cadeaux vendues (commandes de type 3) par moyen ; annulations et soldes remboursés en espèces
        $giftSold = ['cash' => 0, 'declaredCard' => 0, 'card' => 0, 'interac' => 0, 'paypal' => 0, 'giftCard' => 0, 'storeCredit' => 0];
        $this->splitPayments($conn, $giftIds, true, $giftSold);
        $giftSoldCount = count($giftIds);
        $giftRefunded = (int) $conn->fetchOne('SELECT COALESCE(SUM(-amount), 0) FROM pos_gift_card_transaction WHERE kind IN (\'cancel\', \'cash_out\') AND caisse_id = ?', [$caisseId]);
        // Mouvements de la session
        $mv = $conn->fetchAssociative('SELECT COALESCE(SUM(CASE WHEN transaction_type_id = 3 THEN cash_amount END), 0) AS deposits, COALESCE(SUM(CASE WHEN transaction_type_id = 6 THEN -cash_amount END), 0) AS withdrawals, COALESCE(SUM(CASE WHEN transaction_type_id = 8 THEN cash_amount END), 0) AS fund_in, COALESCE(SUM(CASE WHEN transaction_type_id = 7 THEN -cash_amount END), 0) AS fund_out FROM transaction_caisse WHERE caisse_id = ?', [$caisseId]);
        $netHt = (int) round((float) $items['net']);
        $grossHt = (int) round((float) $items['gross']);
        $discountsHt = (int) round((float) $items['discounts']);
        // Ventes à prix TTC (F18 partie 2b) : salesNetHt = Σ order_tax.base (HT extrait), salesGrossHt = HT avant remises par la même extraction, remises en HT ; le TTC reste dans salesTtc
        if ($saleIds !== [] && ($ttc = $this->inclusiveHt($conn, $saleIds)) !== null) {
            $netHt = $ttc['netOther'] + $ttc['net'];
            $grossHt = $ttc['grossOther'] + $ttc['gross'];
            $discountsHt = $grossHt - $netHt;
        }

        return [
            'salesCount' => (int) $sales['n'], 'salesTtc' => (int) round((float) $sales['ttc']), 'salesNetHt' => $netHt, 'salesGrossHt' => $grossHt,
            'discounts' => $discountsHt, 'loyaltyRewards' => $rewards, 'salesByTax' => $taxes, 'cashRounding' => (int) $sales['rounding'] + $reversalRounding,
            'receipts' => ['cash' => $receipts['cash'], 'card' => $receipts['card'] + $receipts['declaredCard'], 'declaredCard' => $receipts['declaredCard'], 'interac' => $receipts['interac'], 'paypal' => $receipts['paypal'], 'giftCard' => max(0, $receipts['giftCard']), 'storeCredit' => $receipts['storeCredit'], 'coupon' => $receipts['coupon']], // coupon : F17
            'returnsCount' => (int) $returns['n'], 'refundsTtc' => (int) round((float) $returns['ttc']), 'refundsHt' => $refundsHt, 'refundsTaxes' => $refundTaxes, 'refunds' => $refunds,
            'giftCardsSoldCount' => $giftSoldCount, 'giftCardsSold' => ['cash' => $giftSold['cash'], 'card' => $giftSold['card'] + $giftSold['declaredCard'], 'interac' => $giftSold['interac'], 'stripe' => 0], 'giftCardsRefunded' => ['cash' => $giftRefunded],
            'discrepancy' => (int) ($z->getDiscrepancy() ?? 0), 'bankDeposit' => (int) ($z->getBankDeposit() ?? 0),
            'deposits' => (int) $mv['deposits'], 'withdrawals' => (int) $mv['withdrawals'], 'fundDeposits' => (int) $mv['fund_in'], 'fundWithdrawals' => (int) $mv['fund_out'],
        ] + ($reversals !== [] ? ['reversals' => $reversals] : []); // PAYS-1b : tickets des ventes contre-passées (annulées ici, vendues ailleurs)
    }

    /** Paiements (positifs = encaissements, négatifs = remboursements) des commandes données, ventilés espèces / carte déclarée / terminal / Interac / PayPal */
    private function splitPayments(\Doctrine\DBAL\Connection $conn, array $orderIds, bool $positive, array &$out): void
    {
        if ($orderIds === []) {
            return;
        }
        $rows = $conn->fetchAllAssociative('SELECT p.order_payment_id, p.payment_method_id, p.amount, p.stripe_payment_id FROM payments p WHERE p.order_payment_id IN (' . implode(',', $orderIds) . ') AND p.amount ' . ($positive ? '> 0' : '< 0'));
        $terminals = [];
        foreach ($conn->fetchAllAssociative('SELECT order_id, payment_method_type, stripe_payment_intent_id FROM pos_terminal_payment WHERE order_id IN (' . implode(',', $orderIds) . ')') as $t) {
            $terminals[$t['stripe_payment_intent_id']] = $t['payment_method_type'];
        }
        foreach ($rows as $p) {
            $amount = abs((int) round((float) $p['amount']));
            switch ((int) $p['payment_method_id']) {
                case 2: $out['cash'] += $amount; break;
                case 3: $out['paypal'] += $amount; break;
                case 1:
                    $type = $p['stripe_payment_id'] !== null ? ($terminals[$p['stripe_payment_id']] ?? 'card_present') : null;
                    if ($type === null) {
                        $out['declaredCard'] += $amount;
                    } elseif ($type === 'interac_present') {
                        $out['interac'] += $amount;
                    } else {
                        $out['card'] += $amount;
                    }
                    break;
            }
        }
    }

    private static function emptyDay(string $day): array
    {
        return ['date' => $day, 'pieces' => [], 'salesCount' => 0, 'salesGrossHt' => 0, 'discounts' => 0, 'loyaltyRewards' => 0, 'salesNetHt' => 0, 'salesTtc' => 0, 'cashRounding' => 0, 'taxes' => [],
            'receipts' => ['cash' => 0, 'declaredCard' => 0, 'card' => 0, 'interac' => 0, 'paypal' => 0, 'giftCard' => 0, 'storeCredit' => 0, 'coupon' => 0], 'returnsCount' => 0, 'refundsTtc' => 0, 'refundsHt' => 0,
            'refunds' => ['cash' => 0, 'card' => 0, 'interac' => 0, 'paypal' => 0, 'giftCard' => 0, 'storeCredit' => 0, 'coupon' => 0], 'giftCardsSoldCount' => 0, 'giftCardsSold' => 0, 'giftCardsRefunded' => 0,
            'discrepancy' => 0, 'bankDeposit' => 0, 'deposits' => 0, 'withdrawals' => 0, 'fundDeposits' => 0, 'fundWithdrawals' => 0];
    }

    private static function addDay(array $d, array $a, CashRegisterReport $z): array
    {
        $d['pieces'][] = sprintf('Z-%d-%d', $z->getCashRegister()->getId(), $z->getNumber());
        foreach (['salesCount', 'salesGrossHt', 'discounts', 'loyaltyRewards', 'salesNetHt', 'salesTtc', 'cashRounding', 'returnsCount', 'refundsTtc', 'refundsHt', 'giftCardsSoldCount', 'discrepancy', 'bankDeposit', 'deposits', 'withdrawals', 'fundDeposits', 'fundWithdrawals'] as $k) {
            $d[$k] += (int) $a[$k];
        }
        foreach ($a['salesByTax'] as $t) {
            $d['taxes'][$t['label']] ??= ['rate' => $t['rate'], 'base' => 0, 'tax' => 0];
            $d['taxes'][$t['label']]['base'] += $t['base'];
            $d['taxes'][$t['label']]['tax'] += $t['tax'];
        }
        foreach ($a['receipts'] as $k => $v) {
            $d['receipts'][$k] = ($d['receipts'][$k] ?? 0) + $v;
        }
        $d['receipts']['card'] -= $a['receipts']['declaredCard']; // 'card' du jour = terminal seul ; la carte déclarée a sa colonne
        foreach ($a['refunds'] as $k => $v) {
            $d['refunds'][$k] = ($d['refunds'][$k] ?? 0) + $v;
        }
        $d['giftCardsSold'] += (int) array_sum($a['giftCardsSold']);
        $d['giftCardsRefunded'] += (int) $a['giftCardsRefunded']['cash'];

        return $d;
    }

    /**
     * Ventes TTC du lot (F18 partie 2b) : HT net = Σ order_tax.base ; HT brut = Σ par ligne de TaxInclusive::base(TTC brut de la ligne, Σ taux des
     * taxes de la commande qui frappent sa catégorie) ; les ventes HT du même lot gardent net = Σ total_price et brut = Σ (total_price + remise).
     *
     * @return array{net: int, gross: int, netOther: int, grossOther: int}|null null si aucune vente TTC dans le lot
     */
    private function inclusiveHt(\Doctrine\DBAL\Connection $conn, array $saleIds): ?array
    {
        $in = implode(',', array_map('intval', $saleIds));
        $ttcIds = array_map('intval', $conn->fetchFirstColumn('SELECT id FROM "order" WHERE prices_include_tax AND id IN (' . $in . ')'));
        if ($ttcIds === []) {
            return null;
        }
        $ttcIn = implode(',', $ttcIds);
        $otherIds = array_values(array_diff(array_map('intval', $saleIds), $ttcIds));
        $other = $otherIds !== [] ? $conn->fetchAssociative('SELECT COALESCE(SUM(total_price), 0) AS net, COALESCE(SUM(total_price + discount_amount), 0) AS gross FROM order_items WHERE order_associated_id IN (' . implode(',', $otherIds) . ')') : ['net' => 0, 'gross' => 0];
        $net = (int) $conn->fetchOne('SELECT COALESCE(SUM(base), 0) FROM order_tax WHERE order_tax_id IN (' . $ttcIn . ')');
        $taxesByOrder = [];
        foreach ($conn->fetchAllAssociative('SELECT ot.order_tax_id AS order_id, CASE WHEN ot.tax_source IS NOT NULL THEN ot.tax_rate_ppm::float8 / 1000000 ELSE t.rate END AS rate, CASE WHEN ot.tax_source IS NOT NULL THEN ot.tax_category ELSE t.category END AS category FROM order_tax ot JOIN tax t ON t.id = ot.tax_id WHERE ot.order_tax_id IN (' . $ttcIn . ')') as $t) {
            $taxesByOrder[(int) $t['order_id']][] = $t;
        }
        $gross = 0;
        foreach ($conn->fetchAllAssociative('SELECT order_associated_id AS order_id, total_price, discount_amount, taxable, tax_category FROM order_items WHERE order_associated_id IN (' . $ttcIn . ')') as $item) {
            $ttcGross = (int) round((float) $item['total_price']) + (int) $item['discount_amount'];
            $category = $item['taxable'] ? \App\Services\OrderService\TaxCategory::of($item['tax_category']) : \App\Services\OrderService\TaxCategory::EXEMPT;
            $rate = 0;
            foreach ($taxesByOrder[(int) $item['order_id']] ?? [] as $t) {
                if (\App\Services\OrderService\TaxCategory::applies($t['category'], $category)) {
                    $rate += (int) \App\Services\OrderService\TaxCode::ppm((float) $t['rate']);
                }
            }
            $gross += \App\Services\OrderService\TaxInclusive::base($ttcGross, $rate);
        }

        return ['net' => $net, 'gross' => $gross, 'netOther' => (int) round((float) $other['net']), 'grossOther' => (int) round((float) $other['gross'])];
    }
}
