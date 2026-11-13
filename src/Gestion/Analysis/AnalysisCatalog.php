<?php

namespace App\Gestion\Analysis;

use App\Entity\Collections;
use App\Gestion\Pricing\CoefficientRule;
use App\Gestion\Services\GestionAdviceService;
use App\Gestion\Services\GestionBudgetService;
use App\Gestion\Services\GestionCashService;
use App\Gestion\Services\GestionStatisticsService;
use App\Gestion\Services\GestionStockSnapshotService;
use App\Gestion\Services\GestionYearService;
use App\Gestion\Services\HomeTilesService;
use App\Gestion\Services\MissingCostQuery;
use App\Gestion\Services\PeriodCalendar;
use App\Gestion\Services\PeriodResultCalculator;
use App\Services\TenantEntityManagerProvider;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Catalogue des ANALYSES NOMMÉES de la gestion (lot W2, 13/11/2026, `docs/console-gestion.md` § 2) : une LISTE BLANCHE. Une analyse est
 * un calcul du serveur, sans IA, en lecture seule : une clé, des paramètres déclarés, une sortie chiffrée et sa source (écran, route).
 * La console IA choisit dans ce catalogue ; une clé absente d'ici ne peut pas être appelée. Chaque analyse enveloppe un calcul existant :
 * aucun chiffre n'est recalculé autrement que par le service de l'écran qu'elle cite.
 *
 * Sortie : `{ key, title, period { from, to, label } | null, figures [{ id, label, value, unit, previous?, lastYear?, delta?,
 * deltaPercent?, rank? }], rows, more { count, total } | null, source { screen, route, params } }`. Unités : `money` (cents de la
 * devise du site), `percent`, `count`, `days`, `date`, `ratio`, `text`. 30 chiffres et 50 lignes au plus.
 */
class AnalysisCatalog
{
    public const MAX_FIGURES = 30;
    public const MAX_ROWS = 50;

    /** Clé => paramètres admis (les obligatoires dans `required`), écran et route d'où viennent les chiffres */
    public const DEFINITIONS = [
        'period.result' => ['params' => ['periodId'], 'required' => [], 'screen' => 'periode.resultat', 'route' => 'GET /api/periodes/{periodId}/resultat'],
        'sales.overview' => ['params' => ['range', 'periodId'], 'required' => [], 'screen' => 'statistiques', 'route' => 'GET /api/gestion/statistiques'],
        'year.overview' => ['params' => ['year'], 'required' => [], 'screen' => 'statistiques.annee', 'route' => 'GET /api/gestion/statistiques/annee'],
        'cash.projection' => ['params' => ['days'], 'required' => [], 'screen' => 'tresorerie', 'route' => 'GET /api/gestion/tresorerie'],
        'budget.status' => ['params' => ['periodId'], 'required' => [], 'screen' => 'periode.budget', 'route' => 'GET /api/periodes/{periodId}/budget'],
        'stock.overview' => ['params' => [], 'required' => [], 'screen' => 'stock', 'route' => 'GET /api/gestion/statistiques'],
        'articles.missing_cost' => ['params' => [], 'required' => [], 'screen' => 'articles', 'route' => 'GET /api/gestion/articles?filter=missing-cost'],
        'articles.vs_similar' => ['params' => ['productId'], 'required' => ['productId'], 'screen' => 'article', 'route' => null],
        'articles.new' => ['params' => ['periodId'], 'required' => [], 'screen' => 'articles', 'route' => null],
        'advice.current' => ['params' => [], 'required' => [], 'screen' => 'conseils', 'route' => 'GET /api/gestion/conseils'],
    ];

    /** Type de chaque paramètre : le seul vocabulaire que le modèle peut employer */
    public const PARAM_TYPES = ['periodId' => 'id', 'productId' => 'id', 'year' => 'year', 'days' => 'days', 'range' => 'range'];
    public const RANGES = ['today', '7d', 'month', 'year'];
    public const DAYS = [7, 180];

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly TranslatorInterface $translator,
        private readonly PeriodCalendar $calendar,
        private readonly PeriodResultCalculator $results,
        private readonly GestionStatisticsService $statistics,
        private readonly GestionYearService $year,
        private readonly GestionCashService $cash,
        private readonly GestionBudgetService $budgets,
        private readonly HomeTilesService $tiles,
        private readonly GestionStockSnapshotService $stock,
        private readonly MissingCostQuery $missing,
        private readonly SimilarArticlesAnalysis $similar,
        private readonly CoefficientSuggestion $suggestions,
        private readonly GestionAdviceService $advices,
    ) {
    }

    public function has(string $key): bool
    {
        return isset(self::DEFINITIONS[$key]);
    }

    /** @return list<array{key: string, title: string, params: list<array{name: string, type: string, required: bool}>, source: array{screen: string, route: ?string}}> */
    public function listed(string $locale): array
    {
        $out = [];
        foreach (self::DEFINITIONS as $key => $d) {
            $out[] = ['key' => $key, 'title' => $this->title($key, $locale),
                'params' => array_map(fn (string $p) => ['name' => $p, 'type' => self::PARAM_TYPES[$p], 'required' => in_array($p, $d['required'], true)], $d['params']),
                'source' => ['screen' => $d['screen'], 'route' => $d['route']]];
        }

        return $out;
    }

    public function title(string $key, string $locale): string
    {
        return $this->tr('console.analyses.' . str_replace('.', '_', $key), $locale);
    }

    /**
     * Contrôle des paramètres proposés pour une analyse : seuls les paramètres déclarés sont gardés, chacun vérifié contre les données du
     * site. Rend les paramètres à utiliser, ou null si l'analyse ne peut pas être lancée (clé inconnue, paramètre obligatoire absent ou
     * faux). Un paramètre facultatif faux est simplement écarté.
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>|null
     */
    public function accept(string $key, array $params): ?array
    {
        if (!$this->has($key)) {
            return null;
        }
        $conn = $this->emProvider->getEntityManager()->getConnection();
        $out = [];
        foreach (self::DEFINITIONS[$key]['params'] as $name) {
            $value = $params[$name] ?? null;
            $ok = match ($name) {
                'periodId' => is_int($value) && $conn->fetchOne("SELECT 1 FROM collections WHERE id = ? AND kind = 'period' AND del = false", [$value]) !== false,
                'productId' => is_int($value) && $conn->fetchOne('SELECT 1 FROM product WHERE id = ?', [$value]) !== false,
                'year' => is_int($value) && $value >= 2000 && $value <= (int) $this->calendar->today()->format('Y') + 1,
                'days' => is_int($value) && $value >= self::DAYS[0] && $value <= self::DAYS[1],
                'range' => is_string($value) && in_array($value, self::RANGES, true),
            };
            if ($ok) {
                $out[$name] = $value;
            } elseif (in_array($name, self::DEFINITIONS[$key]['required'], true)) {
                return null;
            }
        }

        return $out;
    }

    /**
     * Lance une analyse du catalogue avec des paramètres déjà contrôlés (`accept`).
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    public function run(string $key, array $params, string $locale): array
    {
        $result = match ($key) {
            'period.result' => $this->periodResult($params, $locale),
            'sales.overview' => $this->salesOverview($params, $locale),
            'year.overview' => $this->yearOverview($params, $locale),
            'cash.projection' => $this->cashProjection($params, $locale),
            'budget.status' => $this->budgetStatus($params, $locale),
            'stock.overview' => $this->stockOverview($locale),
            'articles.missing_cost' => $this->missingCost($locale),
            'articles.vs_similar' => $this->versusSimilar($params, $locale),
            'articles.new' => $this->newArticles($params, $locale),
            'advice.current' => $this->currentAdvice($locale),
        };
        $rows = $result['rows'] ?? [];
        $total = $result['total'] ?? count($rows);
        $d = self::DEFINITIONS[$key];

        return [
            'key' => $key, 'title' => $this->title($key, $locale), 'period' => $result['period'] ?? null,
            'figures' => array_slice(array_values(array_filter($result['figures'], fn (array $f) => $f['value'] !== null)), 0, self::MAX_FIGURES),
            'rows' => array_slice($rows, 0, self::MAX_ROWS),
            'more' => $total > self::MAX_ROWS ? ['count' => $total - min(count($rows), self::MAX_ROWS), 'total' => $total] : null,
            'source' => ['screen' => $d['screen'], 'route' => $d['route'], 'params' => ($result['params'] ?? $params) ?: new \stdClass()],
        ];
    }

    // ---- les analyses --------------------------------------------------------------------------------------------------------------

    private function periodResult(array $params, string $locale): array
    {
        $period = $this->period($params);
        $r = $this->results->compute($period, null);

        return ['period' => $this->periodOf($period, $locale), 'params' => ['periodId' => (int) $period->getId()], 'figures' => [
            $this->figure('revenue', $r['sales']['revenue'] ?? null, 'money', $locale),
            $this->figure('orders', $r['sales']['orders'] ?? null, 'count', $locale),
            $this->figure('cogs', $r['cogs']['amount'] ?? null, 'money', $locale),
            $this->figure('expenses', $r['expenses']['total'] ?? null, 'money', $locale),
            $this->figure('grossMargin', $r['result']['gross'] ?? null, 'money', $locale),
            $this->figure('netResult', $r['result']['net'] ?? null, 'money', $locale),
            $this->figure('marginRate', $r['result']['tauxMarque'] ?? null, 'percent', $locale),
            $this->figure('unknownCostRevenue', $r['sales']['unknownCostRevenue'] ?? null, 'money', $locale),
            $this->figure('unknownCostItems', $r['cogs']['unknownCostItems'] ?? null, 'count', $locale),
        ]];
    }

    private function salesOverview(array $params, string $locale): array
    {
        $query = isset($params['periodId']) ? ['periodId' => $params['periodId']] : ['range' => $params['range'] ?? 'month'];
        $o = $this->statistics->overview($query, $locale);
        $figures = [];
        foreach (['revenue', 'grossMargin', 'grossMarginRate', 'orders', 'averageBasket', 'returnRate', 'stockValue'] as $name) {
            $i = $o['indicators'][$name] ?? null;
            if ($i === null) {
                continue;
            }
            // les comparaisons sont fournies par l'analyse : le modèle ne calcule rien
            $figures[] = $this->figure($name, $i['value'], (string) $i['unit'], $locale, [
                'previous' => $i['previous'] ?? null, 'lastYear' => $i['lastYear'] ?? null,
                'delta' => isset($i['previous'], $i['value']) ? round($i['value'] - $i['previous'], 2) : null,
                'deltaPercent' => ($i['changeUnit'] ?? null) === 'percent' ? ($i['changeVsPrevious'] ?? null) : null,
                'deltaLastYear' => isset($i['lastYear'], $i['value']) ? round($i['value'] - $i['lastYear'], 2) : null,
                'deltaPercentLastYear' => ($i['changeUnit'] ?? null) === 'percent' ? ($i['changeVsLastYear'] ?? null) : null,
            ]);
        }

        return ['period' => ['from' => $o['range']['from'], 'to' => $o['range']['to'], 'label' => $o['comparison']['currentLabel'] ?? null], 'params' => $query, 'figures' => $figures];
    }

    private function yearOverview(array $params, string $locale): array
    {
        $o = $this->year->overview(isset($params['year']) ? ['year' => $params['year']] : [], $locale);
        $t = $o['totals'];
        $ly = $o['lastYear']['cumulative'] ?? null;
        $lastYear = fn (string $k) => is_array($ly) && isset($ly[$k]) && is_numeric($ly[$k]) ? $ly[$k] : null;
        $figures = [
            $this->figure('sales', $t['sales'] ?? null, 'money', $locale, ['lastYear' => $lastYear('sales')]),
            $this->figure('grossMargin', $t['grossMargin'] ?? null, 'money', $locale, ['lastYear' => $lastYear('grossMargin')]),
            $this->figure('marginRate', $t['grossMarginRate'] ?? null, 'percent', $locale),
            $this->figure('fixedCosts', $t['fixedCosts'] ?? null, 'money', $locale),
            $this->figure('netResult', $t['result'] ?? null, 'money', $locale, ['lastYear' => $lastYear('result')]),
            $this->figure('breakEvenSales', $o['profitability']['breakEven']['sales'] ?? null, 'money', $locale),
            $this->figure('gap', is_array($o['profitability']['gap'] ?? null) ? ($o['profitability']['gap']['amount'] ?? null) : null, 'money', $locale),
        ];
        // les mois, du meilleur au moins bon résultat : le rang est fourni
        $rows = [];
        foreach ($o['months'] as $m) {
            if (($m['result'] ?? null) !== null) {
                $rows[] = ['month' => sprintf('%04d-%02d', (int) ($m['year'] ?? 0), (int) ($m['month'] ?? 0)), 'sales' => $m['sales'] ?? null, 'grossMargin' => $m['grossMargin'] ?? null, 'result' => $m['result']];
            }
        }
        usort($rows, fn (array $a, array $b) => $b['result'] <=> $a['result']);
        foreach ($rows as $i => $row) {
            $rows[$i]['rank'] = $i + 1;
        }

        return ['period' => ['from' => $o['fiscalYear']['from'], 'to' => $o['fiscalYear']['to'], 'label' => (string) $o['fiscalYear']['label']], 'params' => ['year' => $o['year']], 'figures' => $figures, 'rows' => $rows];
    }

    private function cashProjection(array $params, string $locale): array
    {
        $days = $params['days'] ?? 30;
        $p = $this->cash->projection($days, $locale);
        $series = $p[$p['alertSeries']] ?? null;

        return ['params' => ['days' => $days], 'figures' => [
            $this->figure('balance', $p['balance']['amount'] ?? null, 'money', $locale),
            $this->figure('balanceDate', $p['balance']['date'] ?? null, 'date', $locale),
            $this->figure('threshold', $p['settings']['threshold'] ?? null, 'money', $locale),
            $this->figure('lowestAmount', $series['low']['amount'] ?? null, 'money', $locale),
            $this->figure('lowestDate', $series['low']['date'] ?? null, 'date', $locale),
            $this->figure('belowThresholdFrom', $series['belowThresholdFrom'] ?? null, 'date', $locale),
            $this->figure('endAmount', $series['end']['amount'] ?? null, 'money', $locale),
            $this->figure('endDate', $series['end']['date'] ?? null, 'date', $locale),
            $this->figure('cashStatus', $p['status'] ?? null, 'text', $locale),
        ]];
    }

    private function budgetStatus(array $params, string $locale): array
    {
        $period = $this->period($params);
        $b = $this->budgets->get($period, $locale);
        $rows = array_map(fn (array $l) => ['category' => $l['category'] ?? null, 'label' => $l['label'] ?? null, 'planned' => $l['planned'] ?? null, 'actual' => $l['actual'] ?? null,
            'remaining' => $l['remaining'] ?? null, 'status' => $l['status'] ?? null], $b['lines']);

        return ['period' => $this->periodOf($period, $locale), 'params' => ['periodId' => (int) $period->getId()], 'rows' => $rows, 'figures' => [
            $this->figure('planned', $b['total']['planned'] ?? null, 'money', $locale),
            $this->figure('actual', $b['total']['actual'] ?? null, 'money', $locale),
            $this->figure('remaining', $b['total']['remaining'] ?? null, 'money', $locale),
            $this->figure('budgetStatus', $b['total']['status'] ?? null, 'text', $locale),
        ]];
    }

    private function stockOverview(string $locale): array
    {
        $now = $this->stock->current($this->calendar->today());
        $low = $this->tiles->lowStock();

        return ['figures' => [
            $this->figure('stockSaleValue', $now['saleValue'] ?? null, 'money', $locale),
            $this->figure('stockCostValue', $now['costValue'] ?? null, 'money', $locale),
            $this->figure('lowStockItems', count($low['all']), 'count', $locale),
        ], 'rows' => array_map(fn (array $i) => ['productId' => $i['productId'], 'name' => $i['name'], 'stock' => $i['stock'], 'soldLast30Days' => $i['soldLast30Days'], 'daysLeft' => $i['daysLeft']], $low['items']),
            'total' => count($low['all'])];
    }

    private function missingCost(string $locale): array
    {
        $count = $this->missing->count();
        $page = $this->missing->page(null, null, null, self::MAX_ROWS, 0);

        return ['figures' => [
            $this->figure('missingCostItems', $page['total'], 'count', $locale),
            $this->figure('items', $count['total'] ?? null, 'count', $locale),
        ], 'rows' => array_map(fn (array $a) => ['productId' => $a['id'], 'name' => $a['name'], 'price' => $a['price'], 'stock' => $a['stock']], $page['items']), 'total' => $page['total']];
    }

    private function versusSimilar(array $params, string $locale): array
    {
        $review = $this->suggestions->review((int) $params['productId'], $this->calendar->today(), $locale);
        $s = $review['similar'] ?? null;

        return ['period' => $s === null ? null : ['from' => $s['season']['from'], 'to' => $s['season']['to'], 'label' => $s['season']['label']], 'figures' => [
            $this->figure('articleName', $review['product']['name'] ?? null, 'text', $locale),
            $this->figure('cost', $review['cost'] ?? null, 'money', $locale),
            $this->figure('price', $review['price'] ?? null, 'money', $locale),
            $this->figure('coefficient', $review['coefficient'] ?? null, 'ratio', $locale),
            $this->figure('proposedCoefficient', $review['proposal']['value'] ?? null, 'ratio', $locale),
            $this->figure('verdict', $review['verdict'] ?? null, 'text', $locale),
            $this->figure('similarCount', $s['count'] ?? null, 'count', $locale),
            $this->figure('similarSoldShare', isset($s['soldShare']) ? round($s['soldShare'] * 100, 1) : null, 'percent', $locale),
            $this->figure('similarWeeksToSell', $s['weeksToSell'] ?? null, 'count', $locale),
            $this->figure('similarDisplayedCoefficient', $s['displayedCoefficient'] ?? null, 'ratio', $locale),
            $this->figure('similarObtainedCoefficient', $s['obtainedCoefficient'] ?? null, 'ratio', $locale),
            $this->figure('similarDiscountRate', isset($s['discountRate']) ? round($s['discountRate'] * 100, 1) : null, 'percent', $locale),
        ], 'rows' => $s['rows'] ?? [], 'total' => $s['count'] ?? 0];
    }

    /**
     * Les articles NOUVEAUX d'une période : ceux dont le premier achat est daté dans la période. Pour chacun : coût, prix, coefficient,
     * ce que le serveur proposerait d'après ses semblables, et le VERDICT calculé par le serveur (`keep`, `raise`, `lower`, ou null sans
     * coût ou sans prix). Classés par nom.
     */
    private function newArticles(array $params, string $locale): array
    {
        $period = $this->period($params);
        $from = $period->getStartDateCollection()->format('Y-m-d 00:00:00');
        $to = \DateTimeImmutable::createFromInterface($period->getEndDateCollection())->modify('+1 day')->format('Y-m-d 00:00:00');
        $ids = array_map('intval', $this->emProvider->getEntityManager()->getConnection()->fetchFirstColumn(
            "SELECT l.product_id FROM commande_line l JOIN commande c ON c.id = l.commande_id JOIN product p ON p.id = l.product_id
             WHERE c.kind <> 'reprise' AND p.product_type = 'standard' AND COALESCE(p.rental_enabled, false) = false
             GROUP BY l.product_id, p.name HAVING MIN(c.date) >= :from AND MIN(c.date) < :to ORDER BY LOWER(p.name), l.product_id",
            ['from' => $from, 'to' => $to]
        ));
        $rows = [];
        $verdicts = ['keep' => 0, 'raise' => 0, 'lower' => 0];
        $day = \DateTimeImmutable::createFromInterface($period->getStartDateCollection());
        foreach ($ids as $id) {
            $review = $this->suggestions->review($id, $day, $locale);
            if ($review === null) {
                continue;
            }
            if ($review['verdict'] !== null) {
                ++$verdicts[$review['verdict']];
            }
            if (count($rows) < self::MAX_ROWS) {
                $rows[] = ['productId' => $id, 'name' => $review['product']['name'], 'cost' => $review['cost'], 'price' => $review['price'], 'coefficient' => $review['coefficient'],
                    'proposedCoefficient' => $review['proposal']['value'], 'proposalSource' => $review['proposal']['source'], 'why' => $review['proposal']['label'], 'verdict' => $review['verdict'],
                    'similarCount' => $review['similar']['count'] ?? 0, 'similarSoldShare' => isset($review['similar']['soldShare']) ? round($review['similar']['soldShare'] * 100, 1) : null];
            }
        }

        return ['period' => $this->periodOf($period, $locale), 'params' => ['periodId' => (int) $period->getId()], 'rows' => $rows, 'total' => count($ids), 'figures' => [
            $this->figure('newItems', count($ids), 'count', $locale),
            $this->figure('verdictKeep', $verdicts['keep'], 'count', $locale),
            $this->figure('verdictRaise', $verdicts['raise'], 'count', $locale),
            $this->figure('verdictLower', $verdicts['lower'], 'count', $locale),
            $this->figure('keepUnder', CoefficientRule::KEEP_UNDER, 'ratio', $locale),
        ]];
    }

    private function currentAdvice(string $locale): array
    {
        $period = $this->calendar->current();
        $list = $this->advices->listWithoutAi($period, 'new', $locale); // les conseils du guide tels quels, sans rien demander à l'IA
        $items = is_array($list['advices'] ?? null) ? $list['advices'] : [];

        return ['period' => $this->periodOf($period, $locale), 'figures' => [$this->figure('adviceCount', count($items), 'count', $locale)],
            'rows' => array_map(fn (array $a) => ['adviceId' => $a['id'], 'kind' => $a['kind'], 'title' => $a['title'], 'text' => $a['text'], 'effect' => $a['effect']['amount'] ?? null, 'confidence' => $a['confidence'] ?? null], $items),
            'total' => count($items)];
    }

    // ---- outils --------------------------------------------------------------------------------------------------------------------

    private function period(array $params): Collections
    {
        return isset($params['periodId']) ? $this->emProvider->getEntityManager()->find(Collections::class, (int) $params['periodId']) : $this->calendar->current();
    }

    /** @return array{from: string, to: string, label: string} */
    private function periodOf(Collections $period, string $locale): array
    {
        return ['from' => $period->getStartDateCollection()->format('Y-m-d'), 'to' => $period->getEndDateCollection()->format('Y-m-d'), 'label' => PeriodCalendar::displayName($period, $locale)];
    }

    /**
     * @param array<string, mixed> $comparisons `previous`, `lastYear`, `delta`, `deltaPercent`, `rank`… : seules les valeurs connues sont gardées
     *
     * @return array<string, mixed>
     */
    private function figure(string $name, mixed $value, string $unit, string $locale, array $comparisons = []): array
    {
        return ['id' => $name, 'label' => $this->tr("console.figures.$name", $locale), 'value' => $value, 'unit' => $unit] + array_filter($comparisons, fn ($v) => $v !== null);
    }

    private function tr(string $id, string $locale): string
    {
        return $this->translator->trans($id, [], 'gestion', $locale === 'en' ? 'en' : 'fr');
    }
}
