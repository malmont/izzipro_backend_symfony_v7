<?php

namespace App\Gestion\UseCase;

use App\Gestion\Aid\GestionAidService;

/** Aides de la gestion (lot FIS-2, 21/11/2026, `docs/aides-gestion.md`) : la fiche, ses conditions et ses justificatifs */
class GestionAidUseCase
{
    public function __construct(private readonly GestionAidService $aids)
    {
    }

    /** @return list<array<string, mixed>> */
    public function list(): array
    {
        return $this->aids->list();
    }

    /** @return array<string, mixed> */
    public function get(int $id): array
    {
        return $this->aids->get($id);
    }

    /** @return array<string, mixed> */
    public function create(array $body, ?string $by): array
    {
        return $this->aids->create($body, $by);
    }

    /** @return array<string, mixed> */
    public function update(int $id, array $body, ?string $by): array
    {
        return $this->aids->update($id, $body, $by);
    }

    public function delete(int $id): void
    {
        $this->aids->delete($id);
    }

    /** @return array<string, mixed> */
    public function setConditionState(int $id, int $index, array $body): array
    {
        return $this->aids->setConditionState($id, $index, $body['state'] ?? null);
    }

    /** @return array<string, mixed> */
    public function attachDocument(int $id, array $body): array
    {
        return $this->aids->attachDocument($id, $body['documentId'] ?? null);
    }

    /** @return array<string, mixed> */
    public function detachDocument(int $id, int $documentId): array
    {
        return $this->aids->detachDocument($id, $documentId);
    }
}
