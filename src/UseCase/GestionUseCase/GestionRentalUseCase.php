<?php

namespace App\UseCase\GestionUseCase;

use App\Services\GestionService\GestionRentalStatisticsService;

/** Locations de la gestion (lot LOC, `docs/locations-gestion.md`) : statistiques (LOC1) */
class GestionRentalUseCase
{
    public function __construct(private readonly GestionRentalStatisticsService $statistics)
    {
    }

    public function statistics(array $query, string $locale): array
    {
        return $this->statistics->statistics($query, $locale);
    }
}
