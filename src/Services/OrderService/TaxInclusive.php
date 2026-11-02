<?php

namespace App\Services\OrderService;

/**
 * Prix taxes comprises (F18 partie 2b, 02/11/2026) : extraction de la taxe d'un montant TTC en cents, en arithmétique entière, sans
 * flottant. Le taux est exprimé en dix-millièmes (20 % → 2000, 5,5 % → 550, 9,975 % → 997,5 : refusé, un taux doit être un multiple
 * exact de 0,01 %). base = round_demi_haut(ttc × 10000 / (10000 + R)) ; taxe = ttc − base ; la somme des bases et des taxes redonne
 * exactement le TTC. Exemples : 0,03 € à 20 % → 2,5 → base 0,03, TVA 0,00 ; 0,09 € → 7,5 → base 0,08, TVA 0,01 ; 5,50 € à 5,5 % →
 * 521,3 → base 5,21, TVA 0,29.
 */
final class TaxInclusive
{
    public const SCALE = 10000;

    /** Taux en dix-millièmes ; null si le taux n'est pas un multiple exact de 0,01 % */
    public static function rateTenThousandths(float $rate): ?int
    {
        $scaled = $rate * self::SCALE;
        $rounded = (int) round($scaled);

        return abs($scaled - $rounded) < 1e-6 ? $rounded : null;
    }

    public static function isExactRate(float $rate): bool
    {
        return self::rateTenThousandths($rate) !== null;
    }

    /** Assiette HT (cents) d'un TTC frappé d'un taux combiné R (dix-millièmes), arrondi demi vers le haut en valeur absolue */
    public static function base(int $ttc, int $rateTenThousandths): int
    {
        $divisor = self::SCALE + $rateTenThousandths;
        if ($divisor <= 0 || $rateTenThousandths <= 0) {
            return $ttc;
        }
        $sign = $ttc < 0 ? -1 : 1;

        return $sign * intdiv(2 * abs($ttc) * self::SCALE + $divisor, 2 * $divisor);
    }

    /** Part d'une taxe (dix-millièmes) sur une assiette, arrondi demi vers le haut en valeur absolue */
    public static function taxOn(int $base, int $rateTenThousandths): int
    {
        $sign = $base < 0 ? -1 : 1;

        return $sign * intdiv(2 * abs($base) * $rateTenThousandths + self::SCALE, 2 * self::SCALE);
    }

    /**
     * Répartition d'un montant (livraison TTC) entre des groupes au prorata de leurs TTC, écart sur le dernier groupe.
     *
     * @param array<string, int> $weights TTC de chaque groupe (clé = groupe)
     * @return array<string, int> part de chaque groupe (mêmes clés ; tout à 0 si les poids sont nuls)
     */
    public static function prorate(int $amount, array $weights): array
    {
        $total = (int) array_sum($weights);
        $out = [];
        $spread = 0;
        $keys = array_keys($weights);
        foreach ($keys as $n => $key) {
            if ($n === count($keys) - 1) {
                $out[$key] = $amount - $spread;
            } else {
                $out[$key] = $total > 0 ? (int) round($amount * $weights[$key] / $total) : 0;
                $spread += $out[$key];
            }
        }

        return $out;
    }
}
