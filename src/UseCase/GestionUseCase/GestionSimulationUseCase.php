<?php

namespace App\UseCase\GestionUseCase;

use App\Entity\Collections;
use App\Services\GestionService\GestionSimulationService;

/** Simulateur de période (lot B1b-2, `docs/simulateur-gestion.md`) : aucune écriture. */
class GestionSimulationUseCase
{
    public function __construct(private readonly GestionSimulationService $simulation)
    {
    }

    public function simulate(Collections $period, array $body, string $locale): array
    {
        return $this->simulation->simulate($period, $body, $locale);
    }
}
