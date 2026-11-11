<?php

namespace App\Gestion\Services;

use App\Entity\Collections;
use App\Services\TenantEntityManagerProvider;
use App\Gestion\UseCase\GetPeriodResultUseCase;

/**
 * Preuve du lot L0 (10/11/2026, `docs/lignes-achat-gestion.md`) : pour le site courant, chaque tableau de bord d'achat (commandes,
 * collections, périodes, combinés) et chaque résultat de période est calculé deux fois, avec le calcul d'avant (produit rattaché) et
 * avec les lignes d'achat. Lecture seule. Tableaux de bord : aucune différence admise. Résultats de période : une différence n'est admise
 * que si la période contient des ventes d'articles antérieures à la date de leur commande (règle du coût à la date de la vente).
 */
class CommandeLinesVerifier
{
    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly PurchaseMetricsCalculator $metrics,
        private readonly PeriodResultCalculator $results,
        private readonly PeriodCalendar $calendar,
        private readonly GetPeriodResultUseCase $periodResult,
    ) {
    }

    /**
     * @return array{dashboards: int, dashboardDiffs: list<string>, periods: int, periodDiffs: list<array{period: string, fields: list<string>, salesBeforePurchase: int}>, unexplained: int, lines: int, products: int}
     */
    public function verify(): array
    {
        $conn = $this->emProvider->getEntityManager()->getConnection();
        $diffs = [];
        $count = 0;
        $compare = function (string $label, callable $compute) use (&$diffs, &$count): void {
            $count++;
            $old = $this->metrics->legacy($compute);
            $new = $compute();
            if (json_encode($old) !== json_encode($new)) {
                $fields = array_keys(array_filter($new, fn ($v, $k) => json_encode($v) !== json_encode($old[$k] ?? null), ARRAY_FILTER_USE_BOTH));
                $diffs[] = sprintf('%s : %s (avant %s, après %s)', $label, implode(', ', $fields), json_encode(array_intersect_key($old, array_flip($fields))), json_encode(array_intersect_key($new, array_flip($fields))));
            }
        };
        foreach ($conn->fetchFirstColumn('SELECT id FROM commande ORDER BY id') as $id) {
            $compare("commande $id", fn () => $this->metrics->commandeTotals((int) $id));
        }
        foreach ($conn->fetchFirstColumn('SELECT id FROM collections WHERE del = false ORDER BY id') as $id) {
            $compare("collection $id", fn () => $this->metrics->collectionTotals((int) $id));
        }
        $compare('combiné des collections', fn () => $this->metrics->allCollectionsTotals());
        $compare('combiné des périodes', fn () => $this->metrics->allPeriodsTotals());
        $periods = $this->emProvider->getEntityManager()->getRepository(Collections::class)->findBy(['kind' => Collections::KIND_PERIOD, 'del' => false], ['startDateCollection' => 'ASC']);
        foreach ($periods as $period) {
            $compare('période ' . $period->getPeriodKey(), fn () => $this->metrics->periodTotals($period->getStartDateCollection(), $period->getEndDateCollection()));
        }
        $periodDiffs = [];
        $unexplained = 0;
        foreach ($periods as $period) {
            $cutoff = $this->calendar->isClosed($period) ? $this->periodResult->costCutoff($period) : null;
            $old = $this->results->compute($period, $cutoff, true);
            $new = $this->results->compute($period, $cutoff);
            $fields = [];
            foreach (['sales', 'otherRevenue', 'cogs', 'expenses', 'result', 'investment'] as $block) {
                foreach ((array) ($new[$block] ?? []) as $key => $value) {
                    if (json_encode($value) !== json_encode($old[$block][$key] ?? null)) {
                        $fields[] = "$block.$key";
                    }
                }
            }
            if ($fields === []) {
                continue;
            }
            $before = (int) $conn->fetchOne(
                'SELECT COUNT(*) FROM order_items oi JOIN "order" o ON o.id = oi.order_associated_id JOIN product_variant v ON v.id = oi.product_variant_id'
                . ' WHERE o.order_date >= :from AND o.order_date < :to AND EXISTS (SELECT 1 FROM commande_line l JOIN commande c ON c.id = l.commande_id WHERE l.product_id = v.product_id AND CAST(c.date AS date) > CAST(o.order_date AS date))',
                ['from' => $period->getStartDateCollection()->format('Y-m-d 00:00:00'), 'to' => \DateTimeImmutable::createFromInterface($period->getEndDateCollection())->modify('+1 day')->format('Y-m-d 00:00:00')]
            );
            if ($before === 0) {
                $unexplained++;
            }
            $periodDiffs[] = ['period' => (string) $period->getPeriodKey(), 'fields' => $fields, 'salesBeforePurchase' => $before];
        }

        return [
            'dashboards' => $count, 'dashboardDiffs' => $diffs, 'periods' => count($periods), 'periodDiffs' => $periodDiffs, 'unexplained' => $unexplained,
            'lines' => (int) $conn->fetchOne('SELECT COUNT(*) FROM commande_line'), 'products' => (int) $conn->fetchOne('SELECT COUNT(*) FROM product WHERE commande_id IS NOT NULL'),
        ];
    }
}
