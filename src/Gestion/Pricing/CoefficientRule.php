<?php

namespace App\Gestion\Pricing;

/**
 * LA règle du coefficient proposé pour un article, à partir de ses SEMBLABLES d'une saison terminée (validée par le propriétaire le
 * 13/11/2026, lot IA-ACT-5, `docs/prix-gestion.md` § 6). Aucune IA : une fonction pure, la même pour le fil d'une facture, le bilan des
 * nouveaux articles et la console.
 *
 * Point de départ : le coefficient AFFICHÉ des semblables (prix de mise en vente ÷ coût).
 * - vendus à 70 % ou plus, sans rabais  → affiché + 0,2 ;
 * - vendus à 70 % ou plus, avec rabais  → coefficient OBTENU (prix net des rabais ÷ coût), sans autre ajustement ;
 * - vendus entre 40 % et 70 %           → affiché, inchangé ;
 * - vendus à moins de 40 %              → obtenu s'il y a eu des rabais, sinon affiché − 0,2.
 * Le rabais est déjà dans le coefficient obtenu : un article mal vendu n'est donc jamais pénalisé deux fois.
 *
 * Garde-fous : jamais sous le coefficient minimum du site (1,6 par défaut) ; au moins 5 semblables, sinon pas de proposition par cette
 * règle (l'appelant se rabat sur le coefficient constaté chez le fournisseur, puis sur le réglage du site).
 */
final class CoefficientRule
{
    public const SOLD_WELL = 0.70;
    public const SOLD_POORLY = 0.40;
    public const STEP = 0.2;
    public const MIN_SIMILAR = 5;
    /**
     * « Avec rabais » à partir de 5 % : la somme des rabais divisée par la valeur, au prix affiché, des semblables vendus. En dessous
     * (quelques unités soldées en fin de saison), l'article est dit « sans rabais » : un article bien vendu garde son +0,2.
     */
    public const DISCOUNT_FROM = 0.05;
    /** Bilan d'un article déjà en vente : « garder » quand l'écart avec la proposition est sous un demi-cran */
    public const KEEP_UNDER = 0.1;

    public const ADJUST_UP = 'up';             // affiché + 0,2
    public const ADJUST_OBTAINED = 'obtained'; // coefficient obtenu
    public const ADJUST_KEEP = 'keep';         // affiché, inchangé
    public const ADJUST_DOWN = 'down';         // affiché − 0,2

    /**
     * @param int $similar nombre de semblables
     * @param float $displayed coefficient affiché des semblables
     * @param float $obtained coefficient obtenu (net des rabais)
     * @param float $soldShare part du stock vendue, de 0 à 1
     * @param float $discountRate part du prix affiché cédée en rabais, de 0 à 1
     *
     * @return array{coefficient: float, adjustment: string, discounted: bool, floored: bool}|null null : pas assez de semblables
     */
    public static function propose(int $similar, float $displayed, float $obtained, float $soldShare, float $discountRate, float $minCoefficient): ?array
    {
        if ($similar < self::MIN_SIMILAR || $displayed <= 0) {
            return null;
        }
        $discounted = $discountRate >= self::DISCOUNT_FROM;
        [$coefficient, $adjustment] = match (true) {
            $soldShare >= self::SOLD_WELL => $discounted ? [$obtained, self::ADJUST_OBTAINED] : [$displayed + self::STEP, self::ADJUST_UP],
            $soldShare >= self::SOLD_POORLY => [$displayed, self::ADJUST_KEEP],
            default => $discounted ? [$obtained, self::ADJUST_OBTAINED] : [$displayed - self::STEP, self::ADJUST_DOWN],
        };
        $floored = $coefficient < $minCoefficient;

        return ['coefficient' => round($floored ? $minCoefficient : $coefficient, 2), 'adjustment' => $adjustment, 'discounted' => $discounted, 'floored' => $floored];
    }

    /**
     * Verdict d'un article déjà en vente face à la proposition : garder, monter ou baisser son coefficient (bilan des nouveaux articles).
     * Un écart de moins de la moitié d'un cran ne vaut pas un changement de prix.
     */
    public static function verdict(float $current, float $proposed): string
    {
        return round(abs($proposed - $current), 4) < self::KEEP_UNDER ? 'keep' : ($proposed > $current ? 'raise' : 'lower');
    }
}
