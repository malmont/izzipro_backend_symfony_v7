<?php

namespace App\UseCase\GestionUseCase;

use App\Services\GestionService\GestionRentalGridService;
use App\Services\GestionService\GestionRentalPromotionService;
use App\Services\GestionService\GestionRentalStatisticsService;

/** Locations de la gestion (lot LOC, `docs/locations-gestion.md`) : statistiques (LOC1), promotions de location (LOC3), grilles (LOC4) */
class GestionRentalUseCase
{
    public function __construct(
        private readonly GestionRentalStatisticsService $statistics,
        private readonly GestionRentalPromotionService $promotions,
        private readonly GestionRentalGridService $grids,
    ) {
    }

    public function promotionPreview(array $body): array
    {
        return $this->promotions->preview($body);
    }

    public function promotionApply(array $body, ?string $user): array
    {
        return $this->promotions->apply($body, $user);
    }

    public function promotionEnd(array $body, ?string $user): array
    {
        return $this->promotions->end($body['batchId'] ?? null, $user);
    }

    public function promotions(): array
    {
        return $this->promotions->current();
    }

    public function promotionBatches(int $limit): array
    {
        return $this->promotions->batches($limit);
    }

    public function grids(): array
    {
        return $this->grids->list();
    }

    public function gridPreview(array $body): array
    {
        return $this->grids->preview($body);
    }

    public function gridApply(array $body, ?string $user): array
    {
        return $this->grids->apply($body, $user);
    }

    public function gridUndo(array $body, ?string $user): array
    {
        return $this->grids->undo($body['changeId'] ?? null, $user);
    }

    public function gridChanges(int $limit): array
    {
        return $this->grids->changes($limit);
    }

    public function statistics(array $query, string $locale): array
    {
        return $this->statistics->statistics($query, $locale);
    }
}
