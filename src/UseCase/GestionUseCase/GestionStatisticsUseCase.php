<?php

namespace App\UseCase\GestionUseCase;

use App\Services\GestionService\GestionStatisticsService;

/** Statistiques unifiées de la gestion (lot S1, `docs/statistiques-gestion.md`) */
class GestionStatisticsUseCase
{
    public function __construct(private readonly GestionStatisticsService $statistics)
    {
    }

    public function overview(array $query, string $locale): array
    {
        return $this->statistics->overview($query, $locale);
    }
}
