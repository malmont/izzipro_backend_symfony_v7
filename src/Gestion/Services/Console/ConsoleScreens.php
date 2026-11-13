<?php

namespace App\Gestion\Services\Console;

use App\Gestion\Analysis\AnalysisCatalog;

/**
 * Table de correspondance des ÉCRANS de l'application de gestion pour la console IA (lot W2-b, 13/11/2026, `docs/console-gestion.md`
 * § 4). L'application envoie le code de l'écran d'où part la question et ses paramètres ; le serveur les traduit LUI-MÊME :
 * - en THÈME (accueil, achats, ventes, argent, résultats, réglages) ;
 * - en ANALYSES PAR DÉFAUT du catalogue, avec les paramètres de l'écran qu'elles acceptent (`periodId`, `productId`, `year`, `range`).
 * Un écran sans analyse propre (fournisseurs, transporteurs…) reçoit celles de son thème : « explique-moi cet écran » parle de lui.
 * Un code inconnu est ignoré, sans erreur ; un paramètre inconnu ou mal formé est écarté.
 *
 * Les codes sont ceux de l'application web (37 codes relevés dans son code le 13/11/2026) et ceux de la première version du contrat
 * (`accueil`, `journee`, `periode.resultat`, `article`…), gardés pour une application qui les enverrait encore.
 *
 * Types de paramètre : `id` et `page` (entier > 0), `year` (2000 à 2100), `date` (AAAA-MM-JJ), `number`, `key` (texte court sans
 * espace), ou la liste des valeurs admises.
 */
final class ConsoleScreens
{
    public const THEMES = ['accueil', 'achats', 'ventes', 'argent', 'resultats', 'reglages'];

    /** Analyses d'un thème : celles d'un écran qui n'a pas les siennes */
    public const THEME_ANALYSES = [
        'accueil' => ['period.result', 'advice.current'],
        'achats' => ['budget.status', 'articles.new', 'articles.missing_cost'],
        'ventes' => ['sales.overview', 'stock.overview'],
        'argent' => ['cash.projection', 'budget.status'],
        'resultats' => ['period.result', 'year.overview'],
        'reglages' => ['year.overview', 'articles.missing_cost'],
    ];

    /** Paramètres admis sur TOUT écran (premier contrat, lot W2) : la période, l'article, la commande ou l'année que l'écran montre */
    public const COMMON_PARAMS = ['periodId' => 'id', 'productId' => 'id', 'commandeId' => 'id', 'year' => 'year'];

    private const HOME = [['sales.overview', ['range' => 'today']], 'advice.current', 'period.result'];
    private const STAT_RANGES = ['today', '7d', 'month', 'year', 'period', 'dates'];

    /** Code d'écran => thème, paramètres admis (nom => type), analyses par défaut (absent : celles du thème) */
    public const SCREENS = [
        // Tableau de bord
        'gestion.home' => ['theme' => 'accueil', 'analyses' => self::HOME],
        'sequences' => ['theme' => 'accueil'],
        'sequences.detail' => ['theme' => 'accueil', 'params' => ['key' => 'key', 'instance' => 'key']],
        'conseils' => ['theme' => 'accueil', 'params' => ['adviceId' => 'id'], 'analyses' => ['advice.current']],
        'conversations' => ['theme' => 'accueil', 'params' => ['kind' => ['invoice', 'action']]],
        'conversation' => ['theme' => 'accueil', 'params' => ['conversationId' => 'id']],
        'theme' => ['theme' => null, 'params' => ['theme' => ['achats', 'ventes', 'argent', 'resultats', 'reglages']]], // le thème est son paramètre
        // Achats et stock
        'commandes' => ['theme' => 'achats', 'params' => ['periodId' => 'id', 'commandeId' => 'id'], 'analyses' => ['budget.status', 'articles.new']],
        'a-recevoir' => ['theme' => 'achats', 'analyses' => ['stock.overview', 'articles.new']],
        'a-classer' => ['theme' => 'achats', 'analyses' => ['articles.missing_cost', 'articles.new']],
        'fournisseurs' => ['theme' => 'achats'],
        'frais-de-port' => ['theme' => 'achats', 'params' => ['periodId' => 'id'], 'analyses' => ['budget.status', 'period.result']],
        'transporteurs' => ['theme' => 'achats'],
        'voyages' => ['theme' => 'achats', 'params' => ['page' => 'page'], 'analyses' => ['budget.status']],
        'voyages.suivi' => ['theme' => 'achats', 'params' => ['tripId' => 'id'], 'analyses' => ['budget.status']],
        'articles' => ['theme' => 'achats', 'params' => ['filter' => ['missing-cost'], 'from' => 'date', 'to' => 'date'], 'analyses' => ['articles.missing_cost', 'articles.new']],
        'documents.scan' => ['theme' => 'achats', 'params' => ['type' => ['facture', 'note', 'transport'], 'documentId' => 'id'], 'analyses' => ['articles.new', 'budget.status']],
        // Prix et ventes
        'prix' => ['theme' => 'ventes', 'params' => ['adviceId' => 'id'], 'analyses' => ['articles.new', 'articles.missing_cost']],
        'promotions' => ['theme' => 'ventes', 'params' => ['adviceId' => 'id']],
        'locations.prix' => ['theme' => 'ventes', 'params' => ['adviceId' => 'id'], 'analyses' => ['sales.overview']],
        'locations' => ['theme' => 'ventes', 'params' => ['range' => ['current', '30d', 'period', 'dates']], 'analyses' => ['sales.overview']],
        'publicite' => ['theme' => 'ventes', 'params' => ['page' => 'page', 'channel' => 'key'], 'analyses' => ['sales.overview', 'budget.status']],
        'publicite.campagne' => ['theme' => 'ventes', 'params' => ['campaignId' => 'id'], 'analyses' => ['sales.overview', 'budget.status']],
        'campagnes.new' => ['theme' => 'ventes', 'params' => ['fromCampaignId' => 'id'], 'analyses' => ['sales.overview', 'budget.status']],
        // Argent
        'tresorerie' => ['theme' => 'argent', 'analyses' => [['cash.projection', ['days' => 90]]]], // l'écran montre le point bas sur 14 et 90 jours et le solde dans 90 jours
        'frais-fixes' => ['theme' => 'argent', 'analyses' => ['cash.projection', 'year.overview']],
        'notes-de-frais' => ['theme' => 'argent', 'params' => ['periodId' => 'id'], 'analyses' => ['budget.status', 'period.result']],
        // Périodes et résultats
        'periodes' => ['theme' => 'resultats', 'params' => ['page' => 'page']],
        'periode' => ['theme' => 'resultats', 'params' => ['periodId' => 'id'], 'analyses' => ['period.result', 'sales.overview']],
        'periode.budget' => ['theme' => 'resultats', 'params' => ['periodId' => 'id'], 'analyses' => ['budget.status']],
        'periode.simulation' => ['theme' => 'resultats', 'params' => ['periodId' => 'id'], 'analyses' => ['period.result', 'budget.status']],
        'preparation' => ['theme' => 'resultats', 'analyses' => ['period.result', 'cash.projection', 'budget.status']],
        'statistiques' => ['theme' => 'resultats', 'params' => ['range' => self::STAT_RANGES, 'source' => ['all', 'ecommerce', 'pos', 'mobile_app'], 'periodId' => 'id'], 'analyses' => ['sales.overview']],
        'statistiques.annee' => ['theme' => 'resultats', 'params' => ['year' => 'year', 'pricePercent' => 'number'], 'analyses' => ['year.overview']],
        // Réglages
        'reglages' => ['theme' => 'reglages'],
        'reprise' => ['theme' => 'reglages'],

        // Codes de la première version du contrat (lot W2), gardés
        'accueil' => ['theme' => 'accueil', 'analyses' => self::HOME],
        'journee' => ['theme' => 'accueil', 'analyses' => [['sales.overview', ['range' => 'today']]]],
        'periode.resultat' => ['theme' => 'resultats', 'params' => ['periodId' => 'id'], 'analyses' => ['period.result', 'sales.overview']],
        'periode.preparation' => ['theme' => 'resultats', 'params' => ['periodId' => 'id'], 'analyses' => ['period.result', 'cash.projection', 'budget.status']],
        'commande' => ['theme' => 'achats', 'params' => ['periodId' => 'id', 'commandeId' => 'id'], 'analyses' => ['budget.status', 'articles.new']],
        'article' => ['theme' => 'ventes', 'params' => ['productId' => 'id'], 'analyses' => ['articles.vs_similar']],
        'stock' => ['theme' => 'achats', 'analyses' => ['stock.overview']],
        'tresorerie.rapprochements' => ['theme' => 'argent', 'analyses' => [['cash.projection', ['days' => 90]]]],
        'documents' => ['theme' => 'achats', 'analyses' => ['articles.new', 'budget.status']],
        'simulateur' => ['theme' => 'resultats', 'params' => ['periodId' => 'id'], 'analyses' => ['period.result', 'budget.status']],
        'console' => ['theme' => 'accueil'],
    ];

    public static function known(mixed $code): bool
    {
        return is_string($code) && isset(self::SCREENS[$code]);
    }

    /**
     * Paramètres d'un écran, contrôlés par leur TYPE (l'existence d'un identifiant du site est vérifiée par `ConsoleService::context`).
     * Un paramètre non déclaré pour cet écran, `null` ou mal formé est écarté.
     *
     * @return array<string, int|float|string>
     */
    public static function params(string $code, mixed $raw): array
    {
        $out = [];
        foreach ((self::SCREENS[$code]['params'] ?? []) + self::COMMON_PARAMS as $name => $type) {
            $value = is_array($raw) ? ($raw[$name] ?? null) : null;
            $ok = match (true) {
                is_array($type) => is_string($value) && in_array($value, $type, true),
                $type === 'id', $type === 'page' => is_int($value) && $value > 0,
                $type === 'year' => is_int($value) && $value >= 2000 && $value <= 2100,
                $type === 'date' => is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 && checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4)),
                $type === 'number' => (is_int($value) || is_float($value)) && abs($value) <= 1000000,
                $type === 'key' => is_string($value) && preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,59}$/', $value) === 1,
                default => false,
            };
            if ($ok) {
                $out[$name] = $value;
            }
        }

        return $out;
    }

    /** @param array<string, mixed> $params paramètres déjà contrôlés */
    public static function theme(string $code, array $params): string
    {
        return self::SCREENS[$code]['theme'] ?? (in_array($params['theme'] ?? null, self::THEMES, true) ? $params['theme'] : 'accueil');
    }

    /**
     * Analyses proposées par défaut pour un écran : les siennes, sinon celles de son thème ; chacune reprend, parmi les paramètres de
     * l'écran, ceux qu'elle déclare au catalogue (`periodId`, `productId`, `year`, et `range` s'il fait partie de ses plages).
     *
     * @param array<string, mixed> $params paramètres déjà contrôlés
     *
     * @return list<array{key: string, params: array<string, mixed>}>
     */
    public static function defaults(string $code, array $params): array
    {
        $out = [];
        foreach (self::SCREENS[$code]['analyses'] ?? self::THEME_ANALYSES[self::theme($code, $params)] as $entry) {
            [$key, $fixed] = is_array($entry) ? $entry : [$entry, []];
            $out[] = ['key' => $key, 'params' => self::own($key, $params) + $fixed];
        }

        return $out;
    }

    /**
     * Paramètres FIXES qu'un écran donne à une de ses analyses (l'horizon de 90 jours de la Trésorerie, « aujourd'hui » pour l'accueil) :
     * ils complètent aussi une analyse que le modèle choisit lui-même depuis cet écran, quand il laisse ce paramètre vide.
     *
     * @return array<string, mixed>
     */
    public static function fixed(string $code, string $key): array
    {
        foreach (self::SCREENS[$code]['analyses'] ?? [] as $entry) {
            if (is_array($entry) && $entry[0] === $key) {
                return $entry[1];
            }
        }

        return [];
    }

    /**
     * Parmi les paramètres d'un écran, ceux qu'une analyse déclare au catalogue. Une période désignée l'emporte sur une plage ; une
     * plage que l'analyse ne connaît pas (« period », « dates », « 30d ») est écartée.
     *
     * @param array<string, mixed> $params paramètres déjà contrôlés
     *
     * @return array<string, mixed>
     */
    public static function own(string $key, array $params): array
    {
        $own = [];
        foreach (AnalysisCatalog::DEFINITIONS[$key]['params'] ?? [] as $name) {
            if (isset($params[$name]) && ($name !== 'range' || in_array($params[$name], AnalysisCatalog::RANGES, true))) {
                $own[$name] = $params[$name];
            }
        }
        if (isset($own['periodId'])) {
            unset($own['range']);
        }

        return $own;
    }
}
