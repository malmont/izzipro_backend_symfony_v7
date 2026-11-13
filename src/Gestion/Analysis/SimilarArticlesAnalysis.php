<?php

namespace App\Gestion\Analysis;

use App\Entity\Collections;
use App\Gestion\Services\PeriodCalendar;
use App\Services\CaisseService\StoreClock;
use App\Services\TenantEntityManagerProvider;
use Doctrine\DBAL\ArrayParameterType;

/**
 * Analyse nommée « un article face à ses semblables » (lot IA-ACT-5, 13/11/2026, `docs/prix-gestion.md` § 6). Sans IA, en lecture seule :
 * une entrée, une sortie chiffrée. Le fil d'une facture s'en sert pour proposer un coefficient ; la console (lot W2) l'appellera telle quelle.
 *
 * SEMBLABLES d'un article : achetés chez le MÊME FOURNISSEUR, au coût d'achat à 30 % près, et de la MÊME CATÉGORIE quand l'article en a
 * une (un article créé par une facture n'en a pas encore : les deux premiers critères seuls). Seulement dans une saison TERMINÉE : la
 * même période l'année d'avant (les semblables de la saison en cours n'ont pas fini de se vendre, leur taux serait faussement bas).
 *
 * Chiffres, sur l'ensemble des semblables :
 * - part vendue = unités vendues pendant la saison ÷ unités achetées pour la saison ; un semblable jamais vendu y compte (0 vendu) ;
 * - délai = jours entre l'achat et la vente, en moyenne pondérée par les quantités, sur les SEULES unités vendues ;
 * - coefficient affiché = prix catalogue au moment de la vente ÷ coût ; pour un semblable jamais vendu, son prix actuel ÷ coût ;
 * - coefficient obtenu = prix réellement payé ÷ coût, sur les seules unités vendues (un semblable jamais vendu n'y entre pas) ;
 * - rabais = (valeur au prix affiché − prix payé) ÷ valeur au prix affiché, sur les unités vendues.
 * Ventes et retours aux règles du résultat de période (hors commandes annulées ou en attente, hors location et abonnement).
 */
class SimilarArticlesAnalysis
{
    public const KEY = 'articles.vs_similar';
    /** Écart de coût admis entre un article et ses semblables */
    public const COST_BAND = 0.30;
    /** Lignes rendues au plus (le compte et les chiffres portent sur tous les semblables) */
    public const MAX_ROWS = 50;

    public function __construct(private readonly TenantEntityManagerProvider $emProvider, private readonly PeriodCalendar $calendar, private readonly StoreClock $clock)
    {
    }

    /**
     * @param list<int> $categoryIds catégories de l'article ; vide = critère de catégorie non appliqué
     * @param \DateTimeImmutable $day jour de l'achat de l'article (date de la facture) : fixe la saison comparable
     *
     * @return array{season: array{key: string, label: string, from: string, to: string}, criteria: array<string, mixed>, count: int, purchased: int, sold: int, soldShare: ?float,
     *               weeksToSell: ?float, displayedCoefficient: ?float, obtainedCoefficient: ?float, discountRate: float, rows: list<array<string, mixed>>, more: ?array{count: int, total: int}}
     */
    public function similar(int $supplierId, int $costCents, array $categoryIds, \DateTimeImmutable $day, ?int $excludeProductId = null, string $locale = 'fr'): array
    {
        // La saison comparable : la même période du calendrier du site, un an plus tôt ; toujours terminée
        $season = PeriodCalendar::definition($day->modify('-1 year'), $this->calendar->frequency());
        [$fromUtc, $toUtc] = $this->clock->range($season['start']->format('Y-m-d'), $season['end']->format('Y-m-d'));
        // en entiers : 14,30 × 0,7 vaut 10,01 et non 10,009999… (un flottant ferait entrer un article à 10,00 dans la bande)
        $band = (int) round(self::COST_BAND * 100);
        $low = intdiv($costCents * (100 - $band), 100);
        $high = intdiv($costCents * (100 + $band) + 99, 100);
        $params = ['supplier' => $supplierId, 'from' => $season['start']->format('Y-m-d 00:00:00'), 'to' => $season['end']->modify('+1 day')->format('Y-m-d 00:00:00'), 'low' => $low, 'high' => $high,
            'fromUtc' => $fromUtc->format('Y-m-d H:i:s'), 'toUtc' => $toUtc->format('Y-m-d H:i:s'), 'tz' => $this->clock->timezone()->getName()];
        $types = [];
        $filters = '';
        if ($excludeProductId !== null) {
            $filters .= ' AND p.id <> :exclude';
            $params['exclude'] = $excludeProductId;
        }
        $categoryIds = array_values(array_unique(array_map('intval', $categoryIds)));
        if ($categoryIds !== []) {
            $filters .= ' AND EXISTS (SELECT 1 FROM product_categories pc WHERE pc.product_id = p.id AND pc.categories_id IN (:categories))';
            $params['categories'] = $categoryIds;
            $types['categories'] = ArrayParameterType::INTEGER;
        }
        $cost = 'SUM(l.quantity_ordered * l.unit_cost_cents * COALESCE(c.exchange_rate, 1)) / NULLIF(SUM(l.quantity_ordered), 0)';
        $rows = $this->emProvider->getEntityManager()->getConnection()->fetchAllAssociative(
            "WITH semblables AS (
                SELECT p.id, p.name, p.price, SUM(l.quantity_ordered) AS purchased, $cost AS cost, MIN(CAST(c.date AS date)) AS bought_on
                FROM commande_line l JOIN commande c ON c.id = l.commande_id JOIN product p ON p.id = l.product_id
                WHERE c.fournisseur_id = :supplier AND c.kind <> 'reprise' AND c.date >= :from AND c.date < :to AND l.unit_cost_cents IS NOT NULL AND l.quantity_ordered > 0
                  AND p.product_type = 'standard' AND COALESCE(p.rental_enabled, false) = false$filters
                GROUP BY p.id, p.name, p.price
                HAVING $cost BETWEEN :low AND :high
            ), sales AS (
                SELECT pv.product_id AS id,
                    SUM(CASE WHEN o.order_type_id = 2 THEN -oi.quantity ELSE oi.quantity END) AS sold,
                    SUM(CASE WHEN o.order_type_id = 1 THEN oi.quantity ELSE 0 END) AS sold_gross,
                    SUM(CASE WHEN o.order_type_id = 1 THEN oi.quantity * COALESCE(oi.catalog_unit_price, oi.variant_catalog_unit_price, oi.unit_price) ELSE 0 END) AS list_value,
                    SUM(CASE WHEN o.order_type_id = 1 THEN oi.total_price ELSE 0 END) AS paid_value,
                    SUM(CASE WHEN o.order_type_id = 1 THEN oi.quantity * GREATEST(CAST((o.order_date AT TIME ZONE 'UTC') AT TIME ZONE :tz AS date) - s.bought_on, 0) ELSE 0 END) AS day_units
                FROM order_items oi JOIN \"order\" o ON o.id = oi.order_associated_id JOIN product_variant pv ON pv.id = oi.product_variant_id JOIN semblables s ON s.id = pv.product_id
                LEFT JOIN booking b ON b.order_item_id = oi.id
                WHERE o.order_date >= :fromUtc AND o.order_date < :toUtc AND o.order_type_id IN (1, 2) AND o.status_id NOT IN (1, 7) AND b.id IS NULL AND o.subscription_id IS NULL
                GROUP BY pv.product_id
            )
            SELECT s.id, s.name, s.price, s.purchased, s.cost, COALESCE(x.sold, 0) AS sold, COALESCE(x.sold_gross, 0) AS sold_gross, COALESCE(x.list_value, 0) AS list_value,
                   COALESCE(x.paid_value, 0) AS paid_value, COALESCE(x.day_units, 0) AS day_units
            FROM semblables s LEFT JOIN sales x ON x.id = s.id ORDER BY LOWER(s.name), s.id",
            $params,
            $types
        );

        $items = [];
        $purchased = $sold = $soldGross = 0;
        $listValue = $paidValue = $dayUnits = $soldCost = 0.0;
        $displayed = [];
        foreach ($rows as $r) {
            $unitCost = (float) $r['cost'];
            $bought = (int) $r['purchased'];
            $units = max(0, min($bought, (int) $r['sold'])); // nets des retours, jamais plus que les achats de la saison
            $gross = (int) $r['sold_gross'];
            $list = (float) $r['list_value'];
            $paid = (float) $r['paid_value'];
            // prix affiché : le prix catalogue des ventes ; jamais vendu : le prix actuel de la fiche
            $displayedPrice = $gross > 0 ? $list / $gross : ($r['price'] !== null ? (float) $r['price'] : null);
            $itemDisplayed = $displayedPrice !== null && $unitCost > 0 ? $displayedPrice / $unitCost : null;
            if ($itemDisplayed !== null) {
                $displayed[] = $itemDisplayed;
            }
            $purchased += $bought;
            $sold += $units;
            if ($gross > 0) { // un semblable jamais vendu n'entre ni dans le coefficient obtenu, ni dans le délai, ni dans les rabais
                $soldGross += $gross;
                $listValue += $list;
                $paidValue += $paid;
                $dayUnits += (float) $r['day_units'];
                $soldCost += $gross * $unitCost;
            }
            $items[] = ['productId' => (int) $r['id'], 'name' => $r['name'], 'cost' => (int) round($unitCost), 'price' => $r['price'] !== null ? (int) round((float) $r['price']) : null, 'purchased' => $bought, 'sold' => $units,
                'displayedCoefficient' => $itemDisplayed === null ? null : round($itemDisplayed, 2), 'obtainedCoefficient' => $gross > 0 && $unitCost > 0 ? round($paid / ($gross * $unitCost), 2) : null];
        }
        $count = count($items);

        return [
            'season' => ['key' => $season['key'], 'label' => PeriodCalendar::displayNameFor($season['name'], Collections::KIND_PERIOD, $season['key'], $season['start'], $locale),
                'from' => $season['start']->format('Y-m-d'), 'to' => $season['end']->format('Y-m-d')],
            'criteria' => ['supplierId' => $supplierId, 'cost' => $costCents, 'costFrom' => $low, 'costTo' => $high, 'categoryIds' => $categoryIds, 'sameCategory' => $categoryIds !== []],
            'count' => $count, 'purchased' => $purchased, 'sold' => $sold,
            'soldShare' => $purchased > 0 ? round($sold / $purchased, 4) : null,
            'weeksToSell' => $soldGross > 0 ? round($dayUnits / $soldGross / 7, 1) : null,
            'displayedCoefficient' => $displayed === [] ? null : round(array_sum($displayed) / count($displayed), 2),
            'obtainedCoefficient' => $soldCost > 0 ? round($paidValue / $soldCost, 2) : null,
            'discountRate' => $listValue > 0 ? round(max(0.0, $listValue - $paidValue) / $listValue, 4) : 0.0,
            'rows' => array_slice($items, 0, self::MAX_ROWS),
            'more' => $count > self::MAX_ROWS ? ['count' => $count - self::MAX_ROWS, 'total' => $count] : null,
        ];
    }

    /**
     * Coefficient constaté chez un fournisseur : la moyenne du prix de vente ÷ coût de ses articles achetés (tous les achats au coût
     * connu), ou null sans article.
     *
     * @return array{coefficient: float, articles: int}|null
     */
    public function supplierCoefficient(int $supplierId, ?int $excludeProductId = null): ?array
    {
        $row = $this->emProvider->getEntityManager()->getConnection()->fetchAssociative(
            "SELECT COUNT(*) AS n, AVG(x.price / x.cost) AS coefficient FROM (
                SELECT p.id, p.price, SUM(l.quantity_ordered * l.unit_cost_cents * COALESCE(c.exchange_rate, 1)) / NULLIF(SUM(l.quantity_ordered), 0) AS cost
                FROM commande_line l JOIN commande c ON c.id = l.commande_id JOIN product p ON p.id = l.product_id
                WHERE c.fournisseur_id = :supplier AND c.kind <> 'reprise' AND l.unit_cost_cents IS NOT NULL AND l.quantity_ordered > 0 AND p.price > 0
                  AND p.product_type = 'standard' AND COALESCE(p.rental_enabled, false) = false AND p.id <> :exclude
                GROUP BY p.id, p.price) x WHERE x.cost > 0",
            ['supplier' => $supplierId, 'exclude' => $excludeProductId ?? 0]
        );

        return $row === false || (int) $row['n'] === 0 ? null : ['coefficient' => round((float) $row['coefficient'], 2), 'articles' => (int) $row['n']];
    }
}
