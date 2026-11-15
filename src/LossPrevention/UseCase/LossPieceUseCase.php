<?php

namespace App\LossPrevention\UseCase;

use App\LossPrevention\Services\LossPieceService;

/**
 * Pièces et puces de la prévention des pertes (lot L2-a, `docs/prevention-pertes.md` § 13). `$exercise` : les mêmes gestes sur des
 * pièces d'exercice et des puces simulées, par les routes d'exercice (ouvertes sans le droit du module).
 */
class LossPieceUseCase
{
    public function __construct(private readonly LossPieceService $pieces)
    {
    }

    /** @param array<string, mixed> $query @return array{rows: list<array<string, mixed>>, total: int, page: int, limit: int} */
    public function list(array $query, string $locale, bool $exercise = false): array
    {
        return $this->pieces->list($query, $locale, $exercise);
    }

    /** @return array<string, mixed> */
    public function sheet(int $id, string $locale, bool $exercise = false): array
    {
        return $this->pieces->sheet($id, $locale, $exercise);
    }

    /** @param array<string, mixed> $body @return array<string, mixed> */
    public function create(array $body, string $locale, int $userId, bool $exercise = false): array
    {
        return $this->pieces->create($body, $locale, $userId, $exercise);
    }

    /** @param array<string, mixed> $body @return array<string, mixed> */
    public function associate(array $body, string $locale, int $userId, bool $exercise = false): array
    {
        return $this->pieces->associate($body, $locale, $userId, $exercise);
    }

    /** @return array<string, mixed> */
    public function search(mixed $identifier, string $locale, bool $exercise = false): array
    {
        return $this->pieces->search($identifier, $locale, $exercise);
    }

    /** @param array<string, mixed> $body @return array<string, mixed> */
    public function retireTag(int $id, array $body, string $locale, int $userId, bool $exercise = false): array
    {
        return $this->pieces->retireTag($id, $body, $locale, $userId, $exercise);
    }

    /** @param array<string, mixed> $body @return array<string, mixed> */
    public function retirePiece(int $id, array $body, string $locale, int $userId, bool $exercise = false): array
    {
        return $this->pieces->retirePiece($id, $body, $locale, $userId, $exercise);
    }

    /** @param array<string, mixed> $query @return array<string, mixed> */
    public function synthesis(array $query, string $locale): array
    {
        return $this->pieces->synthesis($query, $locale);
    }

    /** @return array<string, mixed> */
    public function exerciseList(string $locale): array
    {
        return $this->pieces->exerciseList($locale);
    }

    /** @return array{deleted: int} */
    public function deleteExercise(int $userId): array
    {
        return $this->pieces->deleteExercise($userId);
    }
}
