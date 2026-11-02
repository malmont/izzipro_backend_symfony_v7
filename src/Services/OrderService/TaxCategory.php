<?php

namespace App\Services\OrderService;

/**
 * Catégories fiscales (F18 partie 2a, 01/11/2026) : sur le produit (`product.tax_category`, nullable = standard), sur une ligne de la
 * table `tax` (`tax.category`, nullable = la taxe vise tous les produits non exonérés) et figées sur chaque ligne de commande
 * (`order_items.tax_category`). Règle : une taxe sans catégorie s'applique à tous les produits non exonérés (comportement d'avant) ;
 * une taxe avec catégorie ne s'applique qu'aux produits de cette catégorie ; un produit sans catégorie est « standard » ;
 * « exempt » n'applique aucune taxe (comme l'article libre non taxable de F7). France : standard 20 %, intermediate 10 %,
 * reduced 5,5 %, super_reduced 2,1 %. États-Unis : une ligne au taux combiné de l'adresse du magasin, « exempt » pour les produits
 * non taxés. Québec : TPS et TVQ sans catégorie, produits sans catégorie → rien ne change.
 */
final class TaxCategory
{
    public const STANDARD = 'standard';
    public const INTERMEDIATE = 'intermediate';
    public const REDUCED = 'reduced';
    public const SUPER_REDUCED = 'super_reduced';
    public const EXEMPT = 'exempt';

    public const ALL = [self::STANDARD, self::INTERMEDIATE, self::REDUCED, self::SUPER_REDUCED, self::EXEMPT];

    /** Libellés des écrans EasyAdmin */
    public const LABELS = [
        self::STANDARD => 'Standard (taux normal)', self::INTERMEDIATE => 'Intermédiaire (10 % en France)', self::REDUCED => 'Réduit (5,5 % en France)',
        self::SUPER_REDUCED => 'Super réduit (2,1 % en France)', self::EXEMPT => 'Exonéré (aucune taxe)',
    ];

    public static function isValid(mixed $value): bool
    {
        return is_string($value) && in_array($value, self::ALL, true);
    }

    /** Catégorie effective d'un produit ou d'une ligne : vide → standard */
    public static function of(?string $value): string
    {
        return self::isValid($value) ? $value : self::STANDARD;
    }

    /**
     * Une taxe (catégorie $taxCategory, null = sans catégorie) s'applique-t-elle à une ligne de catégorie $lineCategory ?
     * Exonéré : jamais ; taxe sans catégorie : à tout le reste ; taxe avec catégorie : à cette catégorie seulement.
     */
    public static function applies(?string $taxCategory, ?string $lineCategory): bool
    {
        $line = self::of($lineCategory);
        if ($line === self::EXEMPT) {
            return false;
        }

        return $taxCategory === null || $taxCategory === '' || $taxCategory === $line;
    }
}
