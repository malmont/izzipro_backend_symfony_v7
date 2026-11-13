<?php

namespace App\Gestion\Lexique;

/**
 * STRUCTURE du lexique de la gestion (lot W2-APPRENDRE, A-1, 13/11/2026, `docs/lexique-gestion.md`) : pour chaque notion, les notions
 * liées, l'analyse du catalogue qui calcule les chiffres du site pour elle (clé, paramètres, chiffres à montrer), et les bonnes réponses
 * de ses questions de vérification. Les TEXTES (terme, définition, synonymes, illustration, questions) sont dans
 * `translations/lexique.{fr,en}.yaml`, une entrée par clé.
 *
 * Les clés sont celles du glossaire du mobile (avec leurs tirets) : elles ne changent JAMAIS, les applications s'y relient. L'ordre de
 * cette liste est l'ordre d'importance : il sert à choisir les leçons d'une réponse en mode « apprendre ».
 */
final class LexiqueCatalog
{
    public const CHOICES = ['a', 'b', 'c'];

    /** Notion => notions liées, analyse liée (`null` : aucun chiffre du site), bonne réponse de chaque question */
    public const ENTRIES = [
        'ventes' => ['related' => ['commandes', 'panier-moyen', 'marge'], 'analysis' => ['key' => 'sales.overview', 'params' => ['range' => 'month'], 'figures' => ['revenue', 'orders']], 'quiz' => []],
        'ventes-sans-cout' => ['related' => ['cout-d-achat', 'marge'], 'analysis' => ['key' => 'period.result', 'params' => [], 'figures' => ['unknownCostRevenue', 'unknownCostItems']], 'quiz' => []],
        'cout-articles-vendus' => ['related' => ['cout-d-achat', 'marge'], 'analysis' => ['key' => 'period.result', 'params' => [], 'figures' => ['cogs', 'revenue']], 'quiz' => []],
        'marge' => ['related' => ['taux-de-marque', 'coefficient', 'cout-articles-vendus'], 'analysis' => ['key' => 'sales.overview', 'params' => ['range' => 'month'], 'figures' => ['grossMargin', 'revenue']], 'quiz' => ['q1' => 'b', 'q2' => 'c']],
        'taux-de-marque' => ['related' => ['marge', 'coefficient', 'point-mort'], 'analysis' => ['key' => 'sales.overview', 'params' => ['range' => 'month'], 'figures' => ['grossMarginRate', 'revenue', 'grossMargin']], 'quiz' => ['q1' => 'a', 'q2' => 'b']],
        'coefficient' => ['related' => ['taux-de-marque', 'cout-d-achat', 'semblables'], 'analysis' => ['key' => 'articles.new', 'params' => [], 'figures' => ['newItems', 'verdictKeep', 'verdictRaise', 'verdictLower']], 'quiz' => ['q1' => 'c', 'q2' => 'a']],
        'frais' => ['related' => ['frais-variables', 'frais-fixes', 'resultat'], 'analysis' => ['key' => 'period.result', 'params' => [], 'figures' => ['expenses']], 'quiz' => []],
        'resultat' => ['related' => ['marge', 'frais', 'resultat-apres-frais-fixes'], 'analysis' => ['key' => 'period.result', 'params' => [], 'figures' => ['netResult', 'grossMargin', 'expenses']], 'quiz' => ['q1' => 'a', 'q2' => 'c']],
        'point-mort' => ['related' => ['taux-de-marque', 'frais', 'point-mort-apres-frais-fixes'], 'analysis' => ['key' => 'year.overview', 'params' => [], 'figures' => ['breakEvenSales', 'gap', 'sales']], 'quiz' => ['q1' => 'b']],
        'investi-stock' => ['related' => ['budget', 'cout-d-achat'], 'analysis' => ['key' => 'budget.status', 'params' => [], 'figures' => ['actual']], 'quiz' => []],
        'autres-revenus' => ['related' => ['revenu-location', 'recurrent'], 'analysis' => null, 'quiz' => []],
        'periode' => ['related' => ['exercice', 'budget', 'resultat'], 'analysis' => ['key' => 'period.result', 'params' => [], 'figures' => ['revenue', 'netResult']], 'quiz' => ['q1' => 'c']],
        'budget-global' => ['related' => ['budget', 'prevu'], 'analysis' => ['key' => 'budget.status', 'params' => [], 'figures' => ['planned', 'actual', 'remaining']], 'quiz' => []],
        'prevu' => ['related' => ['reel', 'reste', 'budget'], 'analysis' => ['key' => 'budget.status', 'params' => [], 'figures' => ['planned']], 'quiz' => []],
        'reel' => ['related' => ['prevu', 'reste', 'budget'], 'analysis' => ['key' => 'budget.status', 'params' => [], 'figures' => ['actual']], 'quiz' => []],
        'reste' => ['related' => ['prevu', 'reel', 'budget'], 'analysis' => ['key' => 'budget.status', 'params' => [], 'figures' => ['remaining', 'planned', 'actual']], 'quiz' => []],
        'solde' => ['related' => ['tresorerie', 'rapprochement', 'point-bas'], 'analysis' => ['key' => 'cash.projection', 'params' => [], 'figures' => ['balance', 'balanceDate']], 'quiz' => []],
        'point-bas' => ['related' => ['solde', 'seuil', 'decouvert'], 'analysis' => ['key' => 'cash.projection', 'params' => [], 'figures' => ['lowestAmount', 'lowestDate', 'threshold']], 'quiz' => []],
        'seuil' => ['related' => ['point-bas', 'decouvert', 'tresorerie'], 'analysis' => ['key' => 'cash.projection', 'params' => [], 'figures' => ['threshold', 'lowestAmount']], 'quiz' => ['q1' => 'b']],
        'ventes-a-venir' => ['related' => ['tresorerie', 'solde'], 'analysis' => null, 'quiz' => []],
        'rapprochement' => ['related' => ['solde', 'tresorerie'], 'analysis' => ['key' => 'cash.projection', 'params' => [], 'figures' => ['balance', 'balanceDate']], 'quiz' => ['q1' => 'c']],
        'frais-fixes' => ['related' => ['frais-variables', 'resultat-apres-frais-fixes', 'mouvement-prevu'], 'analysis' => ['key' => 'year.overview', 'params' => [], 'figures' => ['fixedCosts']], 'quiz' => ['q1' => 'c']],
        'resultat-apres-frais-fixes' => ['related' => ['resultat', 'frais-fixes'], 'analysis' => null, 'quiz' => []],
        'point-mort-apres-frais-fixes' => ['related' => ['point-mort', 'frais-fixes'], 'analysis' => ['key' => 'year.overview', 'params' => [], 'figures' => ['breakEvenSales', 'gap']], 'quiz' => []],
        'exercice' => ['related' => ['periode', 'resultat-entreprise', 'projection'], 'analysis' => ['key' => 'year.overview', 'params' => [], 'figures' => ['sales', 'netResult']], 'quiz' => ['q1' => 'b']],
        'resultat-entreprise' => ['related' => ['resultat', 'frais-fixes', 'frais-variables'], 'analysis' => ['key' => 'year.overview', 'params' => [], 'figures' => ['netResult', 'grossMargin', 'fixedCosts']], 'quiz' => []],
        'frais-variables' => ['related' => ['frais-fixes', 'frais'], 'analysis' => ['key' => 'period.result', 'params' => [], 'figures' => ['expenses']], 'quiz' => ['q1' => 'a']],
        'projection' => ['related' => ['exercice', 'leviers'], 'analysis' => null, 'quiz' => []],
        'leviers' => ['related' => ['point-mort', 'projection'], 'analysis' => null, 'quiz' => []],
        'mouvement-prevu' => ['related' => ['tresorerie', 'frais-fixes'], 'analysis' => null, 'quiz' => []],
        'decouvert' => ['related' => ['solde', 'seuil', 'point-bas'], 'analysis' => ['key' => 'cash.projection', 'params' => [], 'figures' => ['lowestAmount', 'cashStatus']], 'quiz' => []],
        'budget-voyage' => ['related' => ['budget', 'budget-a-ce-jour'], 'analysis' => null, 'quiz' => []],
        'budget-a-ce-jour' => ['related' => ['budget-voyage'], 'analysis' => null, 'quiz' => []],
        'arrondi' => ['related' => ['coefficient', 'promotion'], 'analysis' => null, 'quiz' => []],
        'sous-le-cout' => ['related' => ['cout-d-achat', 'marge'], 'analysis' => null, 'quiz' => []],
        'promotion' => ['related' => ['promotion-variante', 'arrondi', 'stock-dormant'], 'analysis' => null, 'quiz' => []],
        'promotion-variante' => ['related' => ['promotion', 'variante-prix-propre'], 'analysis' => null, 'quiz' => []],
        'variante-prix-propre' => ['related' => ['promotion-variante'], 'analysis' => null, 'quiz' => []],
        'confiance' => ['related' => ['effet', 'mesure'], 'analysis' => ['key' => 'advice.current', 'params' => [], 'figures' => ['adviceCount']], 'quiz' => []],
        'effet' => ['related' => ['confiance', 'mesure'], 'analysis' => ['key' => 'advice.current', 'params' => [], 'figures' => ['adviceCount']], 'quiz' => []],
        'mesure' => ['related' => ['effet', 'confiance'], 'analysis' => null, 'quiz' => []],
        'panier-moyen' => ['related' => ['ventes', 'commandes'], 'analysis' => ['key' => 'sales.overview', 'params' => ['range' => 'month'], 'figures' => ['averageBasket', 'revenue', 'orders']], 'quiz' => ['q1' => 'a']],
        'valeur-stock' => ['related' => ['stock-bas', 'stock-dormant', 'investi-stock'], 'analysis' => ['key' => 'stock.overview', 'params' => [], 'figures' => ['stockSaleValue', 'stockCostValue']], 'quiz' => []],
        'commandes' => ['related' => ['ventes', 'panier-moyen'], 'analysis' => ['key' => 'sales.overview', 'params' => ['range' => 'month'], 'figures' => ['orders', 'revenue']], 'quiz' => []],
        'taux-retour' => ['related' => ['ventes'], 'analysis' => ['key' => 'sales.overview', 'params' => ['range' => 'month'], 'figures' => ['returnRate']], 'quiz' => []],
        'evolution' => ['related' => ['ventes', 'marge'], 'analysis' => ['key' => 'sales.overview', 'params' => ['range' => 'month'], 'figures' => ['revenue']], 'quiz' => []],
        'gain-marge' => ['related' => ['marge'], 'analysis' => null, 'quiz' => []],
        'grille-location' => ['related' => ['promotion-location'], 'analysis' => null, 'quiz' => []],
        'promotion-location' => ['related' => ['grille-location'], 'analysis' => null, 'quiz' => []],
        'revenu-location' => ['related' => ['revenu-date-location', 'autres-revenus'], 'analysis' => null, 'quiz' => []],
        'revenu-date-location' => ['related' => ['revenu-location'], 'analysis' => null, 'quiz' => []],
        'occupation' => ['related' => ['revenu-location'], 'analysis' => null, 'quiz' => []],
        'recurrent' => ['related' => ['taux-depart', 'autres-revenus'], 'analysis' => null, 'quiz' => []],
        'taux-depart' => ['related' => ['recurrent'], 'analysis' => null, 'quiz' => []],
        'tresorerie' => ['related' => ['solde', 'point-bas', 'mouvement-prevu'], 'analysis' => ['key' => 'cash.projection', 'params' => [], 'figures' => ['balance', 'lowestAmount', 'endAmount', 'cashStatus']], 'quiz' => ['q1' => 'b']],
        'taxes' => ['related' => ['ventes'], 'analysis' => null, 'quiz' => ['q1' => 'b']],
        'stock-dormant' => ['related' => ['promotion', 'valeur-stock'], 'analysis' => ['key' => 'stock.overview', 'params' => [], 'figures' => ['stockSaleValue', 'stockCostValue']], 'quiz' => ['q1' => 'a']],
        'budget' => ['related' => ['prevu', 'reel', 'reste'], 'analysis' => ['key' => 'budget.status', 'params' => [], 'figures' => ['planned', 'actual', 'remaining', 'budgetStatus']], 'quiz' => ['q1' => 'a']],
        'cout-d-achat' => ['related' => ['coefficient', 'ventes-sans-cout', 'cout-articles-vendus'], 'analysis' => ['key' => 'articles.missing_cost', 'params' => [], 'figures' => ['missingCostItems', 'items']], 'quiz' => ['q1' => 'b']],
        'stock-bas' => ['related' => ['valeur-stock'], 'analysis' => ['key' => 'stock.overview', 'params' => [], 'figures' => ['lowStockItems']], 'quiz' => []],
        'semblables' => ['related' => ['coefficient', 'verdict'], 'analysis' => ['key' => 'articles.new', 'params' => [], 'figures' => ['newItems']], 'quiz' => []],
        'verdict' => ['related' => ['semblables', 'coefficient'], 'analysis' => ['key' => 'articles.new', 'params' => [], 'figures' => ['verdictKeep', 'verdictRaise', 'verdictLower']], 'quiz' => []],
    ];

    /**
     * Chiffre d'une analyse => notion du lexique : toute carte de chiffre peut ouvrir sa définition (`term`). RÈGLE (lot A-4) : le
     * libellé de la carte reprend le terme du lexique (« Taux de marque », jamais « Taux de marge » sous une leçon « Taux de marque »),
     * avec ce qu'elle compte si besoin ; un chiffre qui mesure autre chose que la notion ne porte pas de `term`. La forme « analyse.chiffre »
     * l'emporte sur le nom du chiffre seul (le résultat de l'exercice n'est pas celui d'une période).
     */
    public const FIGURE_TERMS = [
        'year.overview.netResult' => 'resultat-entreprise',
        'revenue' => 'ventes', 'sales' => 'ventes', 'orders' => 'commandes', 'cogs' => 'cout-articles-vendus', 'expenses' => 'frais',
        'grossMargin' => 'marge', 'netResult' => 'resultat', 'marginRate' => 'taux-de-marque', 'grossMarginRate' => 'taux-de-marque',
        'unknownCostRevenue' => 'ventes-sans-cout', 'unknownCostItems' => 'ventes-sans-cout', 'averageBasket' => 'panier-moyen', 'returnRate' => 'taux-retour',
        'stockValue' => 'valeur-stock', 'stockSaleValue' => 'valeur-stock', 'stockCostValue' => 'valeur-stock', 'lowStockItems' => 'stock-bas',
        'fixedCosts' => 'frais-fixes', 'breakEvenSales' => 'point-mort', 'gap' => 'point-mort',
        'balance' => 'solde', 'balanceDate' => 'solde', 'threshold' => 'seuil', 'belowThresholdFrom' => 'seuil', 'lowestAmount' => 'point-bas', 'lowestDate' => 'point-bas',
        'lowest14Amount' => 'point-bas', 'lowest14Date' => 'point-bas', 'cashStatus' => 'tresorerie',
        'planned' => 'prevu', 'actual' => 'reel', 'remaining' => 'reste', 'budgetStatus' => 'budget',
        'missingCostItems' => 'cout-d-achat', 'cost' => 'cout-d-achat', 'coefficient' => 'coefficient', 'proposedCoefficient' => 'coefficient',
        'verdict' => 'verdict', 'verdictKeep' => 'verdict', 'verdictRaise' => 'verdict', 'verdictLower' => 'verdict', 'similarCount' => 'semblables',
    ];

    public static function has(mixed $key): bool
    {
        return is_string($key) && isset(self::ENTRIES[$key]);
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::ENTRIES);
    }

    /** Notion du lexique d'un chiffre d'une analyse, ou null */
    public static function termOf(string $analysis, string $figure): ?string
    {
        return self::FIGURE_TERMS["$analysis.$figure"] ?? self::FIGURE_TERMS[$figure] ?? null;
    }
}
