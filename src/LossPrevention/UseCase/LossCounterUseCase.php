<?php

namespace App\LossPrevention\UseCase;

use App\LossPrevention\Services\LossCounterService;

/** Ce que la caisse demande au module avant un paiement (lot L3-b1, `docs/prevention-pertes.md` § 16.15) : une lecture ; et la suite d'une alerte montrée (lot L5-a, § 18.2) */
class LossCounterUseCase
{
    public function __construct(private readonly LossCounterService $counter)
    {
    }

    /** @param array<string, mixed> $body @return array<string, mixed> */
    public function unread(array $body, string $locale = 'fr'): array
    {
        return $this->counter->unread($body, $locale);
    }

    /** Lot L5-a : la suite d'une alerte montrée en caisse (compteurs du poste et du jour) @param array<string, mixed> $body */
    public function after(array $body): void
    {
        $this->counter->after($body);
    }
}
