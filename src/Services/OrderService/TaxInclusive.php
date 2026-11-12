<?php

namespace App\Services\OrderService;

/**
 * Prix taxes comprises (F18 partie 2b, 02/11/2026) : extraction de la taxe d'un montant TTC en cents, en arithmétique entière, sans
 * flottant. Depuis PAYS-1c (12/11/2026), le taux est exprimé en millionièmes (20 % → 200000, 5,5 % → 55000, TVQ 9,975 % → 99750) ; avant,
 * en dix-millièmes (9,975 % → 997,5 : refusé). Pour un taux multiple de 0,01 %, les deux échelles donnent exactement le même résultat
 * (mêmes fractions). base = round_demi_haut(ttc × 10⁶ / (10⁶ + R)) ; taxe = ttc − base ; la somme des bases et des taxes redonne
 * exactement le TTC. Exemples : 0,03 € à 20 % → 2,5 → base 0,03, TVA 0,00 ; 0,09 € → 7,5 → base 0,08, TVA 0,01 ; 5,50 € à 5,5 % →
 * 521,3 → base 5,21, TVA 0,29.
 */
final class TaxInclusive
{
    public const SCALE = 1000000;

    /** Taux en millionièmes ; null si le taux n'est pas exact au millionième */
    public static function ratePpm(float $rate): ?int
    {
        $scaled = $rate * self::SCALE;
        $rounded = (int) round($scaled);

        return abs($scaled - $rounded) < 1e-4 ? $rounded : null;
    }

    /** Taux utilisable en prix TTC : exact au millionième (TVQ 9,975 % comprise) */
    public static function isExactRate(float $rate): bool
    {
        return self::ratePpm($rate) !== null;
    }

    /** Taux multiple exact de 0,01 % : calculable par les règles hors ligne v3 et v4 (dix-millièmes) ; sinon la règle v5 est exigée */
    public static function isLegacyExactRate(float $rate): bool
    {
        $ppm = self::ratePpm($rate);

        return $ppm !== null && $ppm % 100 === 0;
    }

    /** Assiette HT (cents) d'un TTC frappé d'un taux combiné R (millionièmes), arrondi demi vers le haut en valeur absolue */
    public static function base(int $ttc, int $ratePpm): int
    {
        $divisor = self::SCALE + $ratePpm;
        if ($divisor <= 0 || $ratePpm <= 0) {
            return $ttc;
        }
        $sign = $ttc < 0 ? -1 : 1;

        return $sign * intdiv(2 * abs($ttc) * self::SCALE + $divisor, 2 * $divisor);
    }

    /** Part d'une taxe (millionièmes) sur une assiette, arrondi demi vers le haut en valeur absolue */
    public static function taxOn(int $base, int $ratePpm): int
    {
        $sign = $base < 0 ? -1 : 1;

        return $sign * intdiv(2 * abs($base) * $ratePpm + self::SCALE, 2 * self::SCALE);
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
