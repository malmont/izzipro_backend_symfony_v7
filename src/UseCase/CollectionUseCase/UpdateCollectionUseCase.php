<?php

namespace App\UseCase\CollectionUseCase;

use App\Entity\Collections;
use App\Services\CollectionService\CollectionService;
use App\Gestion\Services\PeriodGuard;

/** PATCH /api/collections/{id} et PATCH /api/periodes/{id} : budget (et nom d'une collection manuelle), lot P1 (10/11/2026) */
class UpdateCollectionUseCase
{
    public function __construct(private readonly CollectionService $collectionService)
    {
    }

    /** @param array<string, mixed> $fields champs validés présents seulement (budget, nomCollection) */
    public function execute(Collections $collection, array $fields, PeriodGuard $guard): Collections
    {
        return $this->collectionService->updateCollection($collection, $fields, $guard);
    }
}
