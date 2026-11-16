<?php

namespace App\LossPrevention\UseCase;

use App\LossPrevention\Services\LossPrintService;

/**
 * Travaux d'impression de l'imprimante à puce, vus par l'administrateur (lot L3-c, `docs/prevention-pertes.md` § 16.20) : les
 * lister, en créer un, le suivre, l'annuler. `$exercise` : les travaux de l'imprimante simulée, en lecture.
 */
class LossPrintUseCase
{
    public function __construct(private readonly LossPrintService $prints)
    {
    }

    /** @param array<string, mixed> $query @return array<string, mixed> */
    public function list(array $query, string $locale, bool $exercise = false): array
    {
        return $this->prints->list($query, $locale, $exercise);
    }

    /** @return array<string, mixed> */
    public function sheet(int $id, string $locale, bool $exercise = false): array
    {
        return $this->prints->sheet($id, $locale, $exercise);
    }

    /** @param array<string, mixed> $body @return array<string, mixed> */
    public function create(array $body, string $locale, int $userId): array
    {
        return $this->prints->create($body, $locale, $userId);
    }

    /** @return array<string, mixed> */
    public function cancel(int $id, string $locale, int $userId): array
    {
        return $this->prints->cancel($id, $locale, $userId, false);
    }
}
