<?php

namespace App\Services\AccountingService;

/**
 * Production d'écritures comptables équilibrées (F14, 29/10/2026), indépendante de la source. Entrée : montants par catégorie
 * (cents, positifs) et une source (« pos » aujourd'hui, « web » demain) ; sortie : lignes {date, journal, account, accountName,
 * accountKey, label, debit, credit, piece, source}. Un écart d'arrondi résiduel est porté au compte cash_rounding : chaque
 * pièce est équilibrée (débits = crédits).
 *
 * Montants attendus (toutes clés facultatives, cents) :
 *  salesByTax [{label, rate?, province?, base (HT net), tax}] ; salesGrossHt (brut HT avant remises) ; discounts ; loyaltyRewards ;
 *  shipping ; receipts {cash, card, interac, stripe, paypal, giftCard, storeCredit} (encaissements TTC) ; refunds {cash, card,
 *  interac, stripe, giftCard, storeCredit} (remboursements TTC) ; refundsHt ; refundsTaxes [{label, rate?, province?, tax}] ;
 *  giftCardsSold {cash, card, interac, stripe} ; storeCreditsIssued ; cashRounding (signé : + = perçu en plus) ;
 *  discrepancy (signé : + = excédent) ; bankDeposit ; stripeFees ; commission ; stripePayout.
 */
class JournalEntryBuilder
{
    public function __construct(private readonly ChartOfAccounts $chart)
    {
    }

    /** @return list<array{date: string, journal: string, account: string, accountName: string, accountKey: string, label: string, debit: int, credit: int, piece: string, source: string}> */
    public function build(array $amounts, string $source, string $date, string $piece, string $journal = 'VT', string $labelPrefix = ''): array
    {
        $lines = [];
        $add = function (string $key, int $debit, int $credit, string $label) use (&$lines, $source, $date, $piece, $journal, $labelPrefix) {
            if ($debit === 0 && $credit === 0) {
                return;
            }
            $account = $this->chart->account($key);
            $lines[] = ['date' => $date, 'journal' => $journal, 'account' => $account['number'], 'accountName' => $account['name'], 'accountKey' => $account['key'], 'label' => trim($labelPrefix . ' ' . $label),
                'debit' => max(0, $debit), 'credit' => max(0, $credit), 'piece' => $piece, 'source' => $source];
        };
        $signed = fn (string $key, int $amount, string $label) => $amount >= 0 ? $add($key, $amount, 0, $label) : $add($key, 0, -$amount, $label);

        // Ventes HT : brut au crédit des ventes, remises et récompenses au débit des comptes de remise (net = brut − remises)
        $netHt = 0;
        foreach ($amounts['salesByTax'] ?? [] as $t) {
            $netHt = max($netHt, (int) ($t['base'] ?? 0)); // assiettes identiques au Québec (TPS et TVQ) : la plus grande = net HT
        }
        $netHt = (int) ($amounts['salesNetHt'] ?? $netHt);
        $discounts = (int) ($amounts['discounts'] ?? 0);
        $rewards = (int) ($amounts['loyaltyRewards'] ?? 0);
        $gross = (int) ($amounts['salesGrossHt'] ?? ($netHt + $discounts));
        if ($gross > 0 || $netHt > 0) {
            $add(count($amounts['salesByTax'] ?? []) === 1 && isset($amounts['salesByTax'][0]['rate']) ? 'sales_rate_' . rtrim(rtrim(number_format((float) $amounts['salesByTax'][0]['rate'] * 100, 2, '.', ''), '0'), '.') : 'sales', 0, $gross, 'Ventes HT');
            $add('discounts', max(0, $discounts - $rewards), 0, 'Remises accordées');
            $add('loyalty_rewards', $rewards, 0, 'Récompenses fidélité');
        }
        $add('shipping_revenue', 0, (int) ($amounts['shipping'] ?? 0), 'Livraison facturée');
        foreach ($amounts['salesByTax'] ?? [] as $t) {
            $add(ChartOfAccounts::taxKey((string) $t['label'], $t['province'] ?? null, isset($t['rate']) ? (float) $t['rate'] : null), 0, (int) ($t['tax'] ?? 0), $t['label'] . ' collectée');
        }
        // Encaissements TTC des ventes
        $receiptKeys = ['cash' => 'cash', 'card' => 'card_clearing', 'interac' => 'interac_clearing', 'stripe' => 'stripe_clearing', 'paypal' => 'paypal_clearing', 'giftCard' => 'gift_card_liability', 'storeCredit' => 'store_credit_liability', 'coupon' => 'coupon_receivable'];
        $receiptLabels = ['cash' => 'Encaissements espèces', 'card' => 'Encaissements carte', 'interac' => 'Encaissements Interac', 'stripe' => 'Encaissements Stripe', 'paypal' => 'Encaissements PayPal', 'giftCard' => 'Règlements par carte-cadeau', 'storeCredit' => 'Règlements par avoir', 'coupon' => 'Coupons de fabricant acceptés'];
        foreach ($receiptKeys as $k => $key) {
            $add($key, (int) ($amounts['receipts'][$k] ?? 0), 0, $receiptLabels[$k]);
        }
        $add('cash_rounding', 0, (int) ($amounts['cashRounding'] ?? 0) > 0 ? (int) $amounts['cashRounding'] : 0, 'Arrondi des espèces perçu');
        $add('cash_rounding', (int) ($amounts['cashRounding'] ?? 0) < 0 ? -(int) $amounts['cashRounding'] : 0, 0, 'Arrondi des espèces rendu');
        // Retours : ventes et taxes au débit, remboursements au crédit du moyen (avoir émis = passif)
        $add('returns', (int) ($amounts['refundsHt'] ?? 0), 0, 'Retours HT');
        foreach ($amounts['refundsTaxes'] ?? [] as $t) {
            $add(ChartOfAccounts::taxKey((string) $t['label'], $t['province'] ?? null, isset($t['rate']) ? (float) $t['rate'] : null), (int) ($t['tax'] ?? 0), 0, $t['label'] . ' sur retours');
        }
        $refundLabels = ['cash' => 'Remboursements espèces', 'card' => 'Remboursements carte', 'interac' => 'Remboursements Interac', 'stripe' => 'Remboursements Stripe', 'giftCard' => 'Recrédits de cartes-cadeaux', 'storeCredit' => 'Avoirs émis', 'coupon' => 'Coupons de fabricant annulés (retours)'];
        foreach ($refundLabels as $k => $label) {
            $add($receiptKeys[$k], 0, (int) ($amounts['refunds'][$k] ?? 0), $label);
        }
        // Cartes-cadeaux vendues : passif, pas un chiffre d'affaires
        foreach (['cash' => 'cash', 'card' => 'card_clearing', 'interac' => 'interac_clearing', 'stripe' => 'stripe_clearing'] as $k => $key) {
            $sold = (int) ($amounts['giftCardsSold'][$k] ?? 0);
            $add($key, $sold, 0, 'Cartes-cadeaux vendues (' . $k . ')');
            $add('gift_card_liability', 0, $sold, 'Cartes-cadeaux émises');
        }
        $refundedCards = (int) ($amounts['giftCardsRefunded']['cash'] ?? 0);
        $add('gift_card_liability', $refundedCards, 0, 'Cartes-cadeaux annulées ou soldes remboursés');
        $add('cash', 0, $refundedCards, 'Remboursement de cartes-cadeaux en espèces');
        // Écart de caisse (+ excédent : débit caisse, crédit écarts), dépôt en banque, frais et virements (web)
        $signed('cash', (int) ($amounts['discrepancy'] ?? 0), 'Écart de caisse (espèces)');
        $signed('cash_discrepancy', -(int) ($amounts['discrepancy'] ?? 0), 'Écart de caisse');
        $add('bank', (int) ($amounts['bankDeposit'] ?? 0), 0, 'Dépôt en banque');
        $add('cash', 0, (int) ($amounts['bankDeposit'] ?? 0), 'Dépôt en banque (sortie de caisse)');
        $add('stripe_fees', (int) ($amounts['stripeFees'] ?? 0), 0, 'Frais Stripe');
        $add('stripe_clearing', 0, (int) ($amounts['stripeFees'] ?? 0), 'Frais Stripe retenus');
        $add('commission', (int) ($amounts['commission'] ?? 0), 0, 'Commission de plateforme');
        $add('stripe_clearing', 0, (int) ($amounts['commission'] ?? 0), 'Commission retenue');
        $add('stripe_payout', (int) ($amounts['stripePayout'] ?? 0), 0, 'Virement Stripe reçu');
        $add('stripe_clearing', 0, (int) ($amounts['stripePayout'] ?? 0), 'Virement Stripe');

        // Équilibre : écart résiduel (arrondis de calcul) porté au compte d'arrondi
        $debit = (int) array_sum(array_column($lines, 'debit'));
        $credit = (int) array_sum(array_column($lines, 'credit'));
        if ($debit !== $credit) {
            $add('cash_rounding', max(0, $credit - $debit), max(0, $debit - $credit), 'Écart d\'arrondi');
        }

        return $lines;
    }

    /** @return array{debit: int, credit: int, balanced: bool} */
    public static function totals(array $lines): array
    {
        $debit = (int) array_sum(array_column($lines, 'debit'));
        $credit = (int) array_sum(array_column($lines, 'credit'));

        return ['debit' => $debit, 'credit' => $credit, 'balanced' => $debit === $credit];
    }
}
