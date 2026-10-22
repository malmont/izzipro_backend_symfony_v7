<?php

namespace App\Services\PosOfflineService;

use App\Services\PosDiscountService\PosDiscountCalculator;

/**
 * Règle de calcul « pos-offline-v1 » (C5 partie 2, 25/10/2026), reproduite à l'identique par le POS hors ligne et rejouée
 * par le jeu de cas partagé config/pos/offline-cases.json. Tout en cents entiers ; round() = arrondi à l'entier le plus
 * proche, demi-cent vers le haut en valeur absolue (round() de PHP).
 *  1. brut de ligne = prix unitaire × quantité ;
 *  2. remises de ligne dans l'ordre : percent → round(netCourant × valeur / 100), amount → min(valeur, netCourant) ; plancher 0 ;
 *  3. remise de ticket : base = Σ nets ; montant idem ; répartie au prorata des nets (round), dernière ligne porte l'écart ;
 *  4. sous-total = Σ nets ; 5. taxe_i = round(sousTotal × taux_i) ; 6. total = sousTotal + Σ taxes ;
 *  7. cashTotal = round(total / pas) × pas ; cashRounding = cashTotal − total ; 8. monnaie = reçu − cashTotal.
 */
final class PosOfflineCalculator
{
    public const RULE = 'pos-offline-v1';

    /**
     * @param list<array{unitPrice: int, quantity: int, discounts?: list<array>}> $lines
     * @param list<array{label: string, rate: float}> $taxes
     * @return array{lines: list<array>, subtotal: int, discountTotal: int, ticketDiscount: ?array, taxes: list<array>, total: int, cashTotal: int, cashRounding: int, change: ?int}
     */
    public static function compute(array $lines, ?array $ticketDiscount, array $taxes, int $step, ?int $cashReceived = null): array
    {
        $quoteLines = [];
        $items = [];
        foreach ($lines as $i => $line) {
            $unit = (int) $line['unitPrice'];
            $qty = (int) $line['quantity'];
            $quoteLines[] = ['kind' => 'sale', 'unitPrice' => $unit, 'quantity' => $qty, 'total' => $unit * $qty];
            $items[] = ['discounts' => $line['discounts'] ?? []];
        }
        $applied = PosDiscountCalculator::apply($quoteLines, $items, $ticketDiscount !== null && $ticketDiscount !== [] ? $ticketDiscount : null);
        $out = [];
        $subtotal = 0;
        foreach ($applied['lines'] as $l) {
            $out[] = ['gross' => $l['gross'] ?? $l['total'], 'discountAmount' => $l['discountAmount'] ?? 0, 'net' => (int) $l['total']];
            $subtotal += (int) $l['total'];
        }
        $taxLines = [];
        $taxTotal = 0;
        foreach ($taxes as $tax) {
            $amount = (int) round($subtotal * (float) $tax['rate']);
            $taxLines[] = ['label' => (string) $tax['label'], 'rate' => (float) $tax['rate'], 'amount' => $amount];
            $taxTotal += $amount;
        }
        $total = $subtotal + $taxTotal;
        $step = max(1, $step);
        $cashTotal = $step > 1 ? (int) (round($total / $step) * $step) : $total;

        return [
            'lines' => $out, 'subtotal' => $subtotal, 'discountTotal' => $applied['summary']['total'], 'ticketDiscount' => $applied['summary']['ticket'],
            'taxes' => $taxLines, 'total' => $total, 'cashTotal' => $cashTotal, 'cashRounding' => $cashTotal - $total,
            'change' => $cashReceived !== null ? $cashReceived - $cashTotal : null,
        ];
    }
}
