<?php

namespace App\Gestion\UseCase;

use App\Gestion\Services\GestionPriceService;

/** Appliquer un prix (lot P4, `docs/prix-gestion.md`) : aperçu, application, annulation, journal. */
class GestionPriceUseCase
{
    public function __construct(private readonly GestionPriceService $prices)
    {
    }

    public function preview(array $body): array
    {
        return $this->prices->preview($body);
    }

    public function apply(array $body, ?string $user): array
    {
        return $this->prices->apply($body, $user);
    }

    public function undo(array $body, ?string $user): array
    {
        return $this->prices->undo($body['batchId'] ?? null, ($body['onlyUntouched'] ?? false) === true, $user);
    }

    public function batches(int $limit): array
    {
        return $this->prices->batches($limit);
    }

    public function batch(int $id): array
    {
        return $this->prices->batch($id);
    }
}
