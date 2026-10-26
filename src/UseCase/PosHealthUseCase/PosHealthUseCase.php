<?php

namespace App\UseCase\PosHealthUseCase;

use App\Services\PosStripeService\PosHealthService;

/** Surveillance des paiements de caisse (M1) : rapport du site pour GET /api/pos/health (administrateur) */
class PosHealthUseCase
{
    public function __construct(private readonly PosHealthService $health)
    {
    }

    public function report(): array
    {
        return $this->health->report(true);
    }
}
