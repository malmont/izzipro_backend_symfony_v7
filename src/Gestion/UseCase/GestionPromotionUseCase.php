<?php

namespace App\Gestion\UseCase;

use App\Gestion\Services\GestionPromotionService;

/** Promotions datées (lot P5, `docs/promotions-gestion.md`) : aperçu, application, fin anticipée, liste, journal. */
class GestionPromotionUseCase
{
    public function __construct(private readonly GestionPromotionService $promotions)
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
        return $this->promotions->end($body['batchId'] ?? null, ($body['onlyUntouched'] ?? false) === true, $user);
    }

    public function current(mixed $status): array
    {
        return $this->promotions->current(is_string($status) && $status !== '' ? $status : 'all');
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
