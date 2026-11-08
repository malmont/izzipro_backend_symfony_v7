<?php

namespace App\UseCase\SiteCharterUseCase;

use App\Services\SiteCharter\SiteCharterService;

/** Charte et brief du site par l'API (08/11/2026) : lecture, écriture, dépôt et retrait du document PDF. */
class SiteCharterUseCase
{
    public function __construct(private readonly SiteCharterService $charters)
    {
    }

    /** @return array<string, mixed> */
    public function get(): array
    {
        return $this->charters->toArray($this->charters->get());
    }

    /**
     * @param array<string, mixed> $body clés acceptées : brief, charter, style, designMd (absente = inchangée, null = effacée)
     * @return array{errors: list<array{path: string, message: string}>, charter: array<string, mixed>}
     */
    public function update(array $body, ?string $by): array
    {
        $data = array_intersect_key($body, array_flip(['brief', 'charter', 'style', 'designMd']));
        $errors = $data === [] ? [['path' => '', 'message' => 'rien à enregistrer : brief, charter, style ou designMd attendu']] : $this->charters->save($data, $by);

        return ['errors' => $errors, 'charter' => $this->get()];
    }

    /** @return ?string message d'erreur */
    public function storeDocument(string $binary, string $name, ?string $by): ?string
    {
        return $this->charters->storeDocument($binary, $name, $by);
    }

    public function removeDocument(?string $by): void
    {
        $this->charters->removeDocument($by);
    }
}
