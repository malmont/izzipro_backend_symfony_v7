<?php

namespace App\Gestion\UseCase;

use App\Gestion\Services\GestionVariantPromotionService;

/** Promotions par variante de la gestion (lot VAR, 11/11/2026, `docs/promotions-gestion.md` § 8) */
class GestionVariantPromotionUseCase
{
    public function __construct(private readonly GestionVariantPromotionService $promotions)
    {
    }

    public function preview(array $body): array
    {
        return $this->promotions->preview($body);
    }

    public function apply(array $body, ?string $user): array
    {
        return $this->promotions->apply($body, $user);
    }

    public function end(array $body, ?string $user): array
    {
        return $this->promotions->end($body['batchId'] ?? null, $user);
    }

    public function current(string $status): array
    {
        return $this->promotions->current($status);
    }

    public function batches(int $limit): array
    {
        return $this->promotions->batches($limit);
    }

    public function batch(int $id): array
    {
        return $this->promotions->batch($id);
    }
}
