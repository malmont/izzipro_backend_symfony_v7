<?php

namespace App\LossPrevention\UseCase;

use App\LossPrevention\Services\LossExitAlertService;

/** Les alertes de sortie du jour (lot L5-d, `docs/prevention-pertes.md` § 18.11) : une lecture, rien d'une personne */
class LossAlertUseCase
{
    public function __construct(private readonly LossExitAlertService $alerts)
    {
    }

    /** @return array<string, mixed> */
    public function today(?string $since, string $locale, bool $exercise, int $userId): array
    {
        return $this->alerts->today($since, $locale, $exercise, $userId);
    }
}
