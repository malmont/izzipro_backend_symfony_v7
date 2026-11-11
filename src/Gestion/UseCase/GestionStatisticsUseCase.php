<?php

namespace App\Gestion\UseCase;

use App\Gestion\Services\GestionStatisticsService;

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
