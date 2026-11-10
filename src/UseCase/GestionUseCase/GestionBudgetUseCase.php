<?php

namespace App\UseCase\GestionUseCase;

use App\Entity\Collections;
use App\Services\GestionService\GestionBudgetService;

/** Budget par poste d'une période (lot B1a, `docs/budgets-gestion.md` § 1) : lecture, saisie du prévu, copie. */
class GestionBudgetUseCase
{
    public function __construct(private readonly GestionBudgetService $budgets)
    {
    }

    public function get(Collections $period, string $locale): array
    {
        return $this->budgets->get($period, $locale);
    }

    public function put(Collections $period, array $body, string $locale): array
    {
        $this->budgets->put($period, $body);

        return $this->budgets->get($period, $locale);
    }

    public function copy(Collections $period, ?int $fromPeriodId, string $locale): array
    {
        $this->budgets->copy($period, $fromPeriodId);

        return $this->budgets->get($period, $locale);
    }
}
