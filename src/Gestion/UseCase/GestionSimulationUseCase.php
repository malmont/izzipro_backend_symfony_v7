<?php

namespace App\Gestion\UseCase;

use App\Entity\Collections;
use App\Gestion\Services\GestionSimulationService;

/** Simulateur de période (lot B1b-2, `docs/simulateur-gestion.md`) : aucune écriture. */
class GestionSimulationUseCase
{
    public function __construct(private readonly GestionSimulationService $simulation, private readonly ?\App\Gestion\Services\GestionSimulationCommentService $comments = null)
    {
    }

    /** Avis du guide sur une simulation (lot G1b-2) */
    public function comment(Collections $period, array $body, string $locale, ?string $user): array
    {
        return $this->comments->comment($period, $body, $locale, $user);
    }

    public function simulate(Collections $period, array $body, string $locale): array
    {
        return $this->simulation->simulate($period, $body, $locale);
    }
}
