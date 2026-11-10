<?php

namespace App\UseCase\GestionUseCase;

use App\Services\GestionService\GestionTripService;

/** Voyages d'achat (lot B1a, `docs/budgets-gestion.md` §§ 2-3) : liste, création, lecture, modification, suppression, suivi, rattachement. */
class GestionTripUseCase
{
    public function __construct(private readonly GestionTripService $trips)
    {
    }

    /** @return array{0: list<array<string, mixed>>, 1: int} */
    public function list(?string $from, ?string $to, int $page, int $limit): array
    {
        return $this->trips->list($from, $to, $page, $limit);
    }

    public function create(array $data, ?string $by): array
    {
        return $this->trips->toArray($this->trips->create($data, $by));
    }

    public function get(int $id): array
    {
        return $this->trips->toArray($this->trips->find($id));
    }

    public function update(int $id, array $data): array
    {
        return $this->trips->toArray($this->trips->update($this->trips->find($id), $data));
    }

    public function delete(int $id): void
    {
        $this->trips->delete($this->trips->find($id));
    }

    public function follow(int $id): array
    {
        return $this->trips->follow($this->trips->find($id));
    }

    public function attach(array $body): array
    {
        $tripId = $body['tripId'] ?? null;
        if ($tripId !== null && !is_int($tripId)) {
            throw new \App\Services\GestionService\GestionValidationException(['tripId' => 'MUST_BE_INTEGER_OR_NULL']);
        }
        if (!array_key_exists('tripId', $body)) {
            throw new \App\Services\GestionService\GestionValidationException(['tripId' => 'REQUIRED']);
        }

        return $this->trips->attach($tripId, is_array($body['noteIds'] ?? null) ? $body['noteIds'] : [], is_array($body['commandeIds'] ?? null) ? $body['commandeIds'] : []);
    }
}
