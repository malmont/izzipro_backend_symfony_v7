<?php

namespace App\LossPrevention\UseCase;

use App\LossPrevention\Services\LossBoxService;
use App\LossPrevention\Services\LossHealthService;

/**
 * Boîtiers de la prévention des pertes vus par l'administrateur (lot L3-a, `docs/prevention-pertes.md` § 16). Les routes
 * d'exercice jouent un boîtier simulé, fabriqué par le serveur.
 */
class LossBoxUseCase
{
    public function __construct(private readonly LossBoxService $boxes, private readonly LossHealthService $health)
    {
    }

    /** @return array<string, mixed> */
    public function list(string $locale, bool $exercise = false): array
    {
        return $exercise ? $this->boxes->exerciseList($locale) : $this->boxes->list($locale, false);
    }

    /** @return array<string, mixed> */
    public function sheet(int $id, string $locale, bool $exercise = false): array
    {
        return $this->boxes->sheet($id, $locale, $exercise);
    }

    /** @param array<string, mixed> $body @return array<string, mixed> */
    public function create(array $body, string $locale, int $userId): array
    {
        return $this->boxes->create($body, $locale, $userId);
    }

    /** @param array<string, mixed> $body @return array<string, mixed> */
    public function update(int $id, array $body, string $locale): array
    {
        return $this->boxes->update($id, $body, $locale);
    }

    /** @param array<string, mixed> $body @return array<string, mixed> */
    public function resetKey(int $id, array $body, string $locale, int $userId): array
    {
        return $this->boxes->resetKey($id, $body, $locale, $userId);
    }

    /** @param array<string, mixed> $body @return array<string, mixed> */
    public function revoke(int $id, array $body, string $locale, int $userId): array
    {
        return $this->boxes->revoke($id, $body, $locale, $userId);
    }

    /** @return array<string, mixed> */
    public function createExercise(string $locale, int $userId): array
    {
        return $this->boxes->createExercise($locale, $userId);
    }

    /** @param array<string, mixed> $body @return array<string, mixed> */
    public function play(int $id, array $body, string $locale): array
    {
        return $this->boxes->play($id, $body, $locale);
    }

    /** @return array<string, mixed> le tableau de santé du matériel */
    public function health(string $locale, bool $exercise = false): array
    {
        return $this->health->board($locale, $exercise);
    }

    /** @param array<string, mixed> $body @return array{thresholdSeconds: int, default: int, min: int, max: int} */
    public function healthThreshold(array $body, int $userId): array
    {
        return $this->health->setThreshold($body, $userId);
    }

    /** @return array{deleted: int} */
    public function deleteExercise(): array
    {
        return $this->boxes->deleteExercise();
    }
}
