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

    /**
     * Lot G-2 : la réponse et ses sommes brutes (« Toutes les boutiques »)
     *
     * @return array{data: array<string, mixed>, sums: array<string, mixed>}
     */
    public function overviewWithSums(array $query, string $locale): array
    {
        [$data, $sums] = $this->statistics->overviewWithSums($query, $locale);

        return ['data' => $data, 'sums' => $sums];
    }
}
