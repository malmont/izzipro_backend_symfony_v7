<?php

namespace App\Gestion\Services\Conversation\Action;

use App\Entity\Collections;
use App\Gestion\Entity\GestionBudgetLine;
use App\Gestion\Services\GestionBudgetService;
use App\Gestion\Services\GestionConflictException;
use App\Gestion\Services\PeriodCalendar;
use App\Services\BoutiqueSettingsService\TenantCurrencyProvider;
use App\Services\TenantEntityManagerProvider;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * `budget.put` : fixer le prévu de postes du budget d'une période (les postes non donnés gardent le leur ; 0 efface). L'aperçu montre le
 * prévu avant et après par poste : l'empreinte couvre donc les montants actuels, et un budget modifié entre l'aperçu et la confirmation
 * donne `PREVIEW_CHANGED`. Annulation : remettre les montants d'avant, si le budget n'a pas été modifié depuis.
 */
class BudgetPutAction extends AbstractGestionAction
{
    public function __construct(TenantEntityManagerProvider $emProvider, TranslatorInterface $translator, TenantCurrencyProvider $currency,
        private readonly GestionBudgetService $budget, private readonly PeriodCalendar $calendar)
    {
        parent::__construct($emProvider, $translator, $currency);
    }

    public static function key(): string
    {
        return 'budget.put';
    }

    public function build(array $answers, string $locale): array
    {
        // Périodes proposées : ouvertes, de la plus récente à la plus ancienne ; la période en cours est proposée si elle existe (aucune n'est créée ici)
        $periods = [];
        $current = $this->currentPeriodId();
        foreach ($this->em()->getRepository(Collections::class)->findBy(['kind' => Collections::KIND_PERIOD], ['startDateCollection' => 'DESC'], 24) as $period) {
            if (!$period->isDel() && !$this->calendar->isClosed($period)) {
                $periods[] = ['value' => (int) $period->getId(), 'label' => PeriodCalendar::displayName($period, $locale), 'from' => $period->getStartDateCollection()?->format('Y-m-d'), 'to' => $period->getEndDateCollection()?->format('Y-m-d')];
            }
        }
        $issues = [];
        $periodId = $answers['periodId'] ?? $current;
        $period = $periodId !== null ? $this->em()->find(Collections::class, (int) $periodId) : null;
        if (isset($answers['periodId']) && ($period === null || !$period->isPeriod() || $this->calendar->isClosed($period))) {
            $issues[] = ['path' => 'periodId', 'code' => $period === null || !$period->isPeriod() ? 'PERIOD_NOT_FOUND' : 'PERIOD_CLOSED'];
        }
        $questions = [$this->question('periodId', true, ['type' => 'choice'], $periods, $periodId, $locale, [], !isset($answers['periodId']) && $periodId !== null)];
        $given = 0;
        foreach (GestionBudgetLine::CATEGORIES as $category) {
            $answer = $answers["planned:$category"] ?? null;
            $given += $answer !== null ? 1 : 0;
            $questions[] = ['title' => $this->tr("budget.categories.$category", $locale), 'category' => $category]
                + $this->question("planned:$category", false, ['type' => 'money', 'currency' => $this->site()], [], $answer, $locale, ['%category%' => $this->tr("budget.categories.$category", $locale)]);
        }
        if ($given === 0) {
            $issues[] = ['path' => 'planned', 'code' => 'REQUIRED']; // au moins un poste
        }

        return ['questions' => $questions, 'issues' => $issues];
    }

    public function accept(array $question, mixed $value, int $index, array $answers): mixed
    {
        if ($value === null) {
            return null;
        }
        if ($question['id'] === 'periodId') {
            $period = is_int($value) ? $this->em()->find(Collections::class, $value) : null;
            if ($period === null || !$period->isPeriod()) {
                $this->fail($index, 'PERIOD_NOT_FOUND');
            }

            return $this->calendar->isClosed($period) ? $this->fail($index, 'PERIOD_CLOSED') : $value;
        }

        return is_int($value) && $value >= 0 ? $value : $this->fail($index, 'MUST_BE_INTEGER_CENTS');
    }

    public function preview(array $answers, string $locale): array
    {
        [$period, $lines] = $this->lines($answers);
        $before = $this->planned((int) $period->getId());
        $rows = [];
        foreach ($lines as $category => $planned) {
            $rows[] = ['category' => $category, 'label' => $this->tr("budget.categories.$category", $locale), 'before' => $before[$category] ?? 0, 'after' => $planned];
        }
        $totalBefore = array_sum($before);

        return ['period' => ['id' => $period->getId(), 'name' => PeriodCalendar::displayName($period, $locale)], 'currency' => $this->site(), 'lines' => $rows,
            'totalBefore' => $totalBefore, 'totalAfter' => $totalBefore + array_sum(array_map(fn (array $r) => $r['after'] - $r['before'], $rows))];
    }

    public function execute(array $answers, ?string $user): array
    {
        [$period, $lines] = $this->lines($answers);
        $before = $this->planned((int) $period->getId());
        $this->budget->put($period, ['lines' => array_map(fn (string $c, int $p) => ['category' => $c, 'planned' => $p], array_keys($lines), $lines)]); // 409 PERIOD_CLOSED ; journal gestion-budget

        return ['result' => ['periodId' => $period->getId()],
            'undo' => ['periodId' => $period->getId(), 'lines' => array_map(fn (string $c, int $p) => ['category' => $c, 'before' => $before[$c] ?? 0, 'after' => $p], array_keys($lines), $lines)]];
    }

    public function blockers(array $undo): array
    {
        $period = $this->em()->find(Collections::class, (int) $undo['periodId']);
        if ($period === null) {
            return [['code' => 'BUDGET_CHANGED', 'message' => 'La période de ce budget n\'existe plus.']];
        }
        $reasons = [];
        if ($this->calendar->isClosed($period)) {
            $reasons[] = ['code' => 'PERIOD_CLOSED', 'message' => 'La période de ce budget est fermée.'];
        }
        $now = $this->planned((int) $period->getId());
        foreach ($undo['lines'] as $line) {
            if (($now[$line['category']] ?? 0) !== (int) $line['after']) {
                $reasons[] = ['code' => 'BUDGET_CHANGED', 'message' => 'Le budget de cette période a été modifié depuis.'];
                break;
            }
        }

        return $reasons;
    }

    public function undo(array $undo): array
    {
        $period = $this->em()->find(Collections::class, (int) $undo['periodId']);
        if ($period === null) {
            throw new GestionConflictException('UNDO_BLOCKED', 'La période n\'existe plus.');
        }
        $this->budget->put($period, ['lines' => array_map(fn (array $l) => ['category' => $l['category'], 'planned' => (int) $l['before']], $undo['lines'])]);

        return ['restored' => ['budgetLines' => count($undo['lines'])]];
    }

    /** Période en cours, si elle existe et qu'elle est ouverte : la valeur proposée (lecture seule, aucune période n'est créée ici) */
    private function currentPeriodId(): ?int
    {
        $period = $this->calendar->findByDate($this->calendar->today());

        return $period !== null && !$this->calendar->isClosed($period) ? (int) $period->getId() : null;
    }

    /** @return array{0: Collections, 1: array<string, int>} la période (répondue, sinon celle proposée) et les postes donnés */
    private function lines(array $answers): array
    {
        $period = $this->em()->find(Collections::class, (int) ($answers['periodId'] ?? $this->currentPeriodId()));
        $lines = [];
        foreach (GestionBudgetLine::CATEGORIES as $category) {
            if (isset($answers["planned:$category"])) {
                $lines[$category] = (int) $answers["planned:$category"];
            }
        }

        return [$period, $lines];
    }

    /** @return array<string, int> prévu actuel par poste (lecture seule) */
    private function planned(int $periodId): array
    {
        $out = [];
        foreach ($this->conn()->fetchAllAssociative('SELECT category, planned_cents FROM gestion_budget_line WHERE period_id = ?', [$periodId]) as $r) {
            $out[(string) $r['category']] = (int) $r['planned_cents'];
        }

        return $out;
    }
}
