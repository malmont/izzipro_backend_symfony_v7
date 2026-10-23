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
 *  4. sous-total = Σ nets ; 5. taxe_i = round(sousTotalTaxable × taux_i) où sousTotalTaxable = Σ nets des lignes taxables
 *     (une ligne libre « taxable: false » en est exclue ; sans ligne libre, sousTotalTaxable = sousTotal) ; 6. total = sousTotal + Σ taxes ;
 *  7. cashTotal = round(total / pas) × pas ; cashRounding = cashTotal − total ; 8. monnaie = reçu − cashTotal.
 * Lignes (F7, 26/10/2026, ajouts facultatifs) : {custom: {label, unitPrice, taxable}} = article libre (prix = custom.unitPrice,
 * pas de prix du pack) ; {priceOverride: {unitPrice}} = prix manuel (remplace le prix du pack avant les remises). Les cas
 * existants donnent le même résultat qu'avant.
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
        $taxable = [];
        foreach ($lines as $i => $line) {
            // Article libre : prix saisi ; prix manuel : prix remplacé ; sinon prix de la ligne (pack)
            $unit = isset($line['custom']) ? (int) $line['custom']['unitPrice'] : (isset($line['priceOverride']) ? (int) $line['priceOverride']['unitPrice'] : (int) $line['unitPrice']);
            $qty = (int) $line['quantity'];
            $quoteLines[] = ['kind' => 'sale', 'unitPrice' => $unit, 'quantity' => $qty, 'total' => $unit * $qty];
            $items[] = ['discounts' => $line['discounts'] ?? []];
            $taxable[] = !isset($line['custom']) || ($line['custom']['taxable'] ?? true) !== false;
        }
        $applied = PosDiscountCalculator::apply($quoteLines, $items, $ticketDiscount !== null && $ticketDiscount !== [] ? $ticketDiscount : null);
        $out = [];
        $subtotal = 0;
        $taxableSubtotal = 0;
        foreach ($applied['lines'] as $i => $l) {
            $out[] = ['gross' => $l['gross'] ?? $l['total'], 'discountAmount' => $l['discountAmount'] ?? 0, 'net' => (int) $l['total']] + ($taxable[$i] ? [] : ['taxable' => false]);
            $subtotal += (int) $l['total'];
            $taxableSubtotal += $taxable[$i] ? (int) $l['total'] : 0;
        }
        $taxLines = [];
        $taxTotal = 0;
        foreach ($taxes as $tax) {
            $amount = (int) round($taxableSubtotal * (float) $tax['rate']);
            $taxLines[] = ['label' => (string) $tax['label'], 'rate' => (float) $tax['rate'], 'amount' => $amount];
            $taxTotal += $amount;
        }
        $total = $subtotal + $taxTotal;
        $step = max(1, $step);
        $cashTotal = $step > 1 ? (int) (round($total / $step) * $step) : $total;

        return [
            'lines' => $out, 'subtotal' => $subtotal, 'discountTotal' => $applied['summary']['total'], 'ticketDiscount' => $applied['summary']['ticket'],
            'taxableSubtotal' => $taxableSubtotal, 'taxes' => $taxLines, 'total' => $total, 'cashTotal' => $cashTotal, 'cashRounding' => $cashTotal - $total,
            'change' => $cashReceived !== null ? $cashReceived - $cashTotal : null,
        ];
    }
}
