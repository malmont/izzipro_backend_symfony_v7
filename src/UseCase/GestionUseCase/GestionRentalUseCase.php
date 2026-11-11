<?php

namespace App\UseCase\GestionUseCase;

use App\Services\GestionService\GestionRentalPromotionService;
use App\Services\GestionService\GestionRentalStatisticsService;

/** Locations de la gestion (lot LOC, `docs/locations-gestion.md`) : statistiques (LOC1), promotions de location (LOC3) */
class GestionRentalUseCase
{
    public function __construct(private readonly GestionRentalStatisticsService $statistics, private readonly GestionRentalPromotionService $promotions)
    {
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

    public function statistics(array $query, string $locale): array
    {
        return $this->statistics->statistics($query, $locale);
    }
}
