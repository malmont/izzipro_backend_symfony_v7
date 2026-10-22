<?php

namespace App\Services\PosDiscountService;

use App\Services\OrderService\CartQuoteException;

/**
 * Remises de caisse (F1, 24/10/2026), calcul pur sur les lignes d'un devis : remises de ligne (pourcentage ou montant,
 * plusieurs, appliquées dans l'ordre sur le net restant, plancher 0), puis remise de ticket (une seule) répartie au
 * prorata des nets de ligne, l'écart d'arrondi sur la dernière ligne. Les taxes sont ensuite calculées sur le NET.
 * Chaque ligne reçoit gross, discounts [{type, value, amount, reason, scope}], discountAmount ; total devient le net.
 */
final class PosDiscountCalculator
{
    public const PERCENT = 'percent';
    public const AMOUNT = 'amount';

    /** Une ligne porte-t-elle des remises, ou le ticket ? */
    public static function hasDiscounts(array $items, ?array $ticketDiscount): bool
    {
        if ($ticketDiscount !== null && $ticketDiscount !== []) {
            return true;
        }
        foreach ($items as $item) {
            if (!empty($item['discounts'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array> $lines lignes du devis (unitPrice, quantity, total) dans l'ordre des items
     * @param list<array> $items items d'entrée (discounts par ligne)
     * @return array{lines: list<array>, summary: array{lines: int, ticket: ?array, total: int, gross: int}}
     * @throws CartQuoteException 422 DISCOUNT_INVALID
     */
    public static function apply(array $lines, array $items, ?array $ticketDiscount): array
    {
        $lineDiscountTotal = 0;
        $gross = 0;
        foreach ($lines as $i => &$line) {
            if (($line['kind'] ?? 'sale') !== 'sale') {
                continue;
            }
            $line['gross'] = (int) $line['total'];
            $gross += $line['gross'];
            $net = $line['gross'];
            $line['discounts'] = [];
            foreach (self::list($items[$i]['discounts'] ?? null, "items[$i].discounts") as $k => $discount) {
                $amount = self::amountFor($discount, $net, "items[$i].discounts[$k]");
                $net -= $amount;
                $line['discounts'][] = $discount + ['amount' => $amount, 'scope' => 'line'];
                $lineDiscountTotal += $amount;
            }
            $line['discountAmount'] = $line['gross'] - $net;
            $line['total'] = $net;
        }
        unset($line);

        $ticket = null;
        if ($ticketDiscount !== null && $ticketDiscount !== []) {
            $discount = self::one($ticketDiscount, 'ticketDiscount');
            $base = (int) array_sum(array_map(fn ($l) => ($l['kind'] ?? 'sale') === 'sale' ? (int) $l['total'] : 0, $lines));
            $amount = self::amountFor($discount, $base, 'ticketDiscount');
            $ticket = $discount + ['amount' => $amount, 'scope' => 'ticket'];
            // Répartition au prorata des nets de ligne, écart d'arrondi sur la dernière ligne concernée
            $saleIndexes = array_keys(array_filter($lines, fn ($l) => ($l['kind'] ?? 'sale') === 'sale' && (int) $l['total'] > 0));
            $spread = 0;
            foreach ($saleIndexes as $n => $i) {
                $share = $n === count($saleIndexes) - 1 ? $amount - $spread : (int) round($amount * $lines[$i]['total'] / max(1, $base));
                $share = max(0, min($share, (int) $lines[$i]['total']));
                $spread += $share;
                if ($share > 0) {
                    $lines[$i]['discounts'][] = $ticket + ['amount' => $share];
                    $lines[$i]['discountAmount'] += $share;
                    $lines[$i]['total'] -= $share;
                }
            }
            $ticket['amount'] = $spread;
        }

        return ['lines' => $lines, 'summary' => ['lines' => $lineDiscountTotal, 'ticket' => $ticket, 'total' => $lineDiscountTotal + ($ticket['amount'] ?? 0), 'gross' => $gross]];
    }

    /** @return list<array{type: string, value: float|int, reasonCode: ?string, reasonText: ?string}> */
    public static function list(mixed $raw, string $path): array
    {
        if ($raw === null || $raw === []) {
            return [];
        }
        if (!is_array($raw) || array_keys($raw) !== range(0, count($raw) - 1)) {
            throw new CartQuoteException(422, "$path : liste de remises attendue", [['path' => $path, 'message' => 'liste attendue']]);
        }

        return array_map(fn ($d, $k) => self::one($d, "$path[$k]"), $raw, array_keys($raw));
    }

    /** @return array{type: string, value: float|int, reasonCode: ?string, reasonText: ?string} */
    public static function one(mixed $raw, string $path): array
    {
        if (!is_array($raw) || !in_array($raw['type'] ?? null, [self::PERCENT, self::AMOUNT], true) || !is_numeric($raw['value'] ?? null) || (float) $raw['value'] <= 0) {
            throw new CartQuoteException(422, "$path : {type: percent | amount, value > 0} attendu", [['path' => $path, 'message' => '{type: percent | amount, value > 0}']]);
        }
        $value = (float) $raw['value'];
        if ($raw['type'] === self::PERCENT && ($value > 100 || round($value, 2) !== $value)) {
            throw new CartQuoteException(422, "$path.value : pourcentage entre 0,01 et 100 (deux décimales au plus)", [['path' => "$path.value", 'message' => 'pourcentage invalide']]);
        }
        if ($raw['type'] === self::AMOUNT && (float) (int) $value !== $value) {
            throw new CartQuoteException(422, "$path.value : montant entier en cents attendu", [['path' => "$path.value", 'message' => 'cents attendus']]);
        }

        return ['type' => $raw['type'], 'value' => $raw['type'] === self::PERCENT ? $value : (int) $value,
            'reasonCode' => is_string($raw['reasonCode'] ?? null) && trim($raw['reasonCode']) !== '' ? trim($raw['reasonCode']) : null,
            'reasonText' => is_string($raw['reasonText'] ?? null) && trim($raw['reasonText']) !== '' ? mb_substr(trim(strip_tags($raw['reasonText'])), 0, 200) : null];
    }

    /** Montant (cents) d'une remise sur une base, plafonné à la base */
    public static function amountFor(array $discount, int $base, string $path): int
    {
        $amount = $discount['type'] === self::PERCENT ? (int) round($base * $discount['value'] / 100) : (int) $discount['value'];

        return max(0, min($amount, $base));
    }
}
