<?php

namespace App\Gestion\UseCase;

use App\Gestion\Services\GestionAiUsageService;

/** Unités IA de l'application de gestion (lot MOD, 11/11/2026) */
class GestionAiUsageUseCase
{
    public function __construct(private readonly GestionAiUsageService $usage)
    {
    }

    public function execute(bool $superAdmin): array
    {
        return $this->usage->usage($superAdmin);
    }
}
