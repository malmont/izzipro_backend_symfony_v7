<?php

namespace App\Gestion\Services;

use App\Entity\Collections;
use App\Gestion\Entity\GestionBudgetLine;
use App\Services\BoutiqueSettingsService\TenantCurrencyProvider;
use App\Services\ContentAuditService\ContentAuditRecorder;
use App\Services\TenantEntityManagerProvider;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Budget par poste d'une période de gestion (lot B1a, 11/11/2026, `docs/budgets-gestion.md` § 1) : prévu saisi par le gérant, réel calculé
 * selon les règles du résultat de période (devise du site) ; postes purchases (commandes datées dans la période), trip (notes de frais
 * rattachées à un voyage), shipping (frais de port), advertising (campagnes classées dans la période), other (autres notes de frais).
 * La somme des réels égale investment.purchases + expenses.total du résultat. Indépendant du budget global de la période.
 */
class GestionBudgetService
{
    public const WARNING_RATIO = 0.8;

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly PurchaseMetricsCalculator $purchases,
        private readonly PeriodResultCalculator $results,
        private readonly PeriodCalendar $calendar,
        private readonly PeriodGuard $guard,
        private readonly TranslatorInterface $translator,
        private readonly ContentAuditRecorder $audit,
        private readonly ?TenantCurrencyProvider $currency = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function get(Collections $period, string $locale): array
    {
        $this->assertPeriod($period);
        $conn = $this->emProvider->getEntityManager()->getConnection();
        $totals = $this->purchases->periodTotals($period->getStartDateCollection(), $period->getEndDateCollection());
        $ads = $this->results->advertising($period);
        $planned = $this->planned((int) $period->getId());
        $notes = $conn->fetchAllAssociative(
            'SELECT (n.trip_id IS NOT NULL) AS trip, t.id AS type_id, t.name AS type_name, COALESCE(SUM(n.montant), 0) AS amount'
            . ' FROM note_de_frais n LEFT JOIN type_note_de_frais t ON t.id = n.type_note_de_frais_id'
            . ' WHERE n.date >= :from AND n.date < :to GROUP BY (n.trip_id IS NOT NULL), t.id, t.name ORDER BY t.name',
            ['from' => $period->getStartDateCollection()->format('Y-m-d'), 'to' => \DateTimeImmutable::createFromInterface($period->getEndDateCollection())->modify('+1 day')->format('Y-m-d')]
        );
        $noteDetails = ['trip' => [], 'other' => []];
        foreach ($notes as $r) {
            $key = $r['trip'] ? 'trip' : 'other';
            $name = (string) ($r['type_name'] ?? '');
            $noteDetails[$key][] = ['key' => 'type:' . ($r['type_id'] ?? 0), 'label' => $name === '' ? '—' : $this->referential($name, $locale), 'actual' => (int) $r['amount']];
        }
        $actual = [
            'purchases' => [(int) ($totals['purchaseCost'] ?? 0), []],
            'trip' => [array_sum(array_column($noteDetails['trip'], 'actual')), $noteDetails['trip']],
            'shipping' => [(int) ($totals['shippingCost'] ?? 0), []],
            'advertising' => [$ads['amount'], array_map(fn ($c) => ['key' => 'campaign:' . $c['id'], 'label' => $c['name'], 'actual' => $c['amount']], $ads['campaigns'])],
            'other' => [array_sum(array_column($noteDetails['other'], 'actual')), $noteDetails['other']],
        ];
        $lines = [];
        foreach (GestionBudgetLine::CATEGORIES as $category) {
            [$a, $details] = $actual[$category];
            $lines[] = ['category' => $category, 'label' => $this->translator->trans("budget.categories.$category", [], 'gestion', $locale)] + $this->figures($planned[$category] ?? 0, $a) + ['details' => $details];
        }

        return [
            'period' => ['id' => $period->getId(), 'name' => $period->getNomCollection(), 'periodKey' => $period->getPeriodKey()],
            'currency' => $this->currency?->code() ?? TenantCurrencyProvider::DEFAULT,
            'globalBudget' => $period->getBudgetCollection(),
            'lines' => $lines,
            'total' => $this->figures(array_sum(array_column($lines, 'planned')), array_sum(array_column($lines, 'actual'))),
        ];
    }

    /** Remplace le prévu des postes donnés (les autres gardent le leur ; 0 efface) */
    public function put(Collections $period, array $body): void
    {
        $this->assertPeriod($period);
        $this->guard->assertCollectionWritable($period);
        $lines = $body['lines'] ?? null;
        if (!is_array($lines) || !array_is_list($lines)) {
            throw new GestionValidationException(['lines' => 'MUST_BE_LIST']);
        }
        $errors = [];
        $values = [];
        foreach ($lines as $i => $line) {
            $category = is_array($line) && is_string($line['category'] ?? null) ? $line['category'] : null;
            $planned = is_array($line) ? ($line['planned'] ?? null) : null;
            if ($category === null || !in_array($category, GestionBudgetLine::CATEGORIES, true)) {
                $errors["lines[$i].category"] = 'INVALID_CATEGORY';
            } elseif (array_key_exists($category, $values)) {
                $errors["lines[$i].category"] = 'DUPLICATE_CATEGORY';
            }
            if (!is_int($planned) || $planned < 0) {
                $errors["lines[$i].planned"] = 'MUST_BE_INTEGER_CENTS';
            }
            if ($category !== null && is_int($planned)) {
                $values[$category] ??= $planned;
            }
        }
        if ($errors !== []) {
            throw new GestionValidationException($errors, 'Budget invalide : ' . implode(', ', array_keys($errors)));
        }
        $before = $this->planned((int) $period->getId());
        $this->write((int) $period->getId(), $values);
        $this->audit->record('gestion-budget', (int) $period->getId(), 'update', $before, $this->planned((int) $period->getId()));
    }

    /** Reprend le prévu d'une autre période (par défaut celle qui contient la même date un an plus tôt) ; remplace tout le prévu */
    public function copy(Collections $period, ?int $fromPeriodId): void
    {
        $this->assertPeriod($period);
        $em = $this->emProvider->getEntityManager();
        $source = $fromPeriodId !== null
            ? $em->find(Collections::class, $fromPeriodId)
            : $this->calendar->findByDate(\DateTimeImmutable::createFromInterface($period->getStartDateCollection())->modify('-1 year'));
        $sourcePlanned = $source instanceof Collections && $source->isPeriod() && $source->getId() !== $period->getId() ? $this->planned((int) $source->getId()) : [];
        if ($sourcePlanned === []) {
            throw new GestionConflictException('BUDGET_SOURCE_NOT_FOUND', 'Aucune période source avec un prévu.', 404, ['fromPeriodId' => $fromPeriodId ?? ($source?->getId())]);
        }
        $this->guard->assertCollectionWritable($period);
        $before = $this->planned((int) $period->getId());
        $this->write((int) $period->getId(), $sourcePlanned + array_fill_keys(GestionBudgetLine::CATEGORIES, 0)); // postes absents de la source effacés
        $this->audit->record('gestion-budget', (int) $period->getId(), 'copy', $before, ['fromPeriodId' => $source->getId()] + $this->planned((int) $period->getId()));
    }

    /** Postes en dépassement de la période en cours (vignette « budget-over ») ; [] si aucun prévu ou aucun dépassement */
    public function overCurrent(string $locale): array
    {
        $period = $this->calendar->current();
        if ($this->planned((int) $period->getId()) === []) {
            return [];
        }
        $budget = $this->get($period, $locale);

        return ['period' => $budget['period'], 'lines' => array_values(array_filter($budget['lines'], fn ($l) => $l['status'] === 'over'))];
    }

    // ----- interne -----

    /** @return array{planned: int, actual: int, remaining: int, ratio: ?float, status: string} */
    private function figures(int $planned, int $actual): array
    {
        $ratio = $planned > 0 ? round($actual / $planned, 2) : null;
        $status = match (true) {
            $planned <= 0 => 'none',
            $actual > $planned => 'over',
            $actual >= self::WARNING_RATIO * $planned => 'warning',
            default => 'ok',
        };

        return ['planned' => $planned, 'actual' => $actual, 'remaining' => $planned - $actual, 'ratio' => $ratio, 'status' => $status];
    }

    /** @return array<string, int> poste → prévu (postes saisis seulement) */
    private function planned(int $periodId): array
    {
        $out = [];
        foreach ($this->emProvider->getEntityManager()->getConnection()->fetchAllAssociative('SELECT category, planned_cents FROM gestion_budget_line WHERE period_id = ?', [$periodId]) as $r) {
            $out[(string) $r['category']] = (int) $r['planned_cents'];
        }

        return $out;
    }

    /** @param array<string, int> $values */
    private function write(int $periodId, array $values): void
    {
        $conn = $this->emProvider->getEntityManager()->getConnection();
        $conn->transactional(function () use ($conn, $periodId, $values): void {
            foreach ($values as $category => $planned) {
                if ($planned === 0) {
                    $conn->executeStatement('DELETE FROM gestion_budget_line WHERE period_id = ? AND category = ?', [$periodId, $category]);
                } else {
                    $conn->executeStatement(
                        'INSERT INTO gestion_budget_line (period_id, category, planned_cents, updated_at) VALUES (?, ?, ?, NOW()) ON CONFLICT (period_id, category) DO UPDATE SET planned_cents = EXCLUDED.planned_cents, updated_at = NOW()',
                        [$periodId, $category, $planned]
                    );
                }
            }
        });
    }

    private function assertPeriod(Collections $period): void
    {
        if (!$period->isPeriod()) {
            throw new GestionConflictException('NOT_A_PERIOD', 'Cette collection est manuelle : le budget par poste concerne les périodes.', 404, ['collectionId' => $period->getId()]);
        }
    }

    private function referential(string $name, string $locale): string
    {
        $key = 'referentiel.type_note_de_frais.' . $name;
        $t = $this->translator->trans($key, [], 'gestion', $locale);

        return $t === $key ? $name : $t;
    }
}
