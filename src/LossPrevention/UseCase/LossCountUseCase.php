<?php

namespace App\LossPrevention\UseCase;

use App\LossPrevention\Services\LossCountService;

/**
 * Comptages et bilan du soir (lot L4-d, `docs/prevention-pertes.md` § 17.16). `exercise` : les mêmes gestes sur des pièces d'exercice et les
 * lectures du boîtier simulé, ouverts sans le droit du module ; un vrai comptage ne voit jamais une pièce d'exercice, ni l'inverse.
 */
class LossCountUseCase
{
    public const SCREEN = 'pertes.pieces';
    public const EVENING_SCREEN = 'pertes.home';

    public function __construct(private readonly LossCountService $counts)
    {
    }

    /** @return array<string, mixed> */
    public function list(bool $exercise, string $locale): array
    {
        return $this->counts->list($exercise, $locale) + ['screen' => self::SCREEN];
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function open(array $body, int $userId, bool $exercise, string $locale): array
    {
        return $this->counts->open($body, $userId, $exercise, $locale) + ['screen' => self::SCREEN];
    }

    /** @return array<string, mixed> */
    public function detail(int $id, bool $exercise, string $locale): array
    {
        return $this->counts->detail($id, $exercise, $locale) + ['screen' => self::SCREEN];
    }

    /** @return array<string, mixed> */
    public function close(int $id, int $userId, bool $exercise, string $locale): array
    {
        return $this->counts->close($id, $userId, $exercise, $locale) + ['screen' => self::SCREEN];
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function evening(array $query, string $locale, bool $exercise = false): array
    {
        return $this->counts->evening($query, $locale, $exercise) + ['screen' => $exercise ? 'pertes.exercices' : self::EVENING_SCREEN];
    }
}
