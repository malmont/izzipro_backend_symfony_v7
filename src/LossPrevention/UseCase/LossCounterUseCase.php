<?php

namespace App\LossPrevention\UseCase;

use App\LossPrevention\Services\LossCounterService;

/** Ce que la caisse demande au module avant un paiement (lot L3-b1, `docs/prevention-pertes.md` § 16.15) : une lecture, rien d'autre */
class LossCounterUseCase
{
    public function __construct(private readonly LossCounterService $counter)
    {
    }

    /** @param array<string, mixed> $body @return array<string, mixed> */
    public function unread(array $body): array
    {
        return $this->counter->unread($body);
    }
}
