<?php

namespace App\UseCase\CommandeUseCase;

use App\Entity\Commande;
use App\Services\CommandeService\CommandeProductAttachmentService;
use App\Services\CommandeService\CommandeProductException;
use App\Services\TenantEntityManagerProvider;

/** Rattachement de produits existants à une commande fournisseur (09/11/2026). */
class CommandeProductAttachmentUseCase
{
    public function __construct(private readonly CommandeProductAttachmentService $service, private readonly TenantEntityManagerProvider $emProvider)
    {
    }

    /** @return array{items: list<array<string, mixed>>, total: int, page: int, limit: int} */
    public function available(mixed $q, mixed $page, mixed $limit, string $productsBaseUrl, string $locale): array
    {
        return $this->service->available(is_string($q) ? $q : null, is_numeric($page) ? (int) $page : 1, is_numeric($limit) ? (int) $limit : 30, $productsBaseUrl, $locale);
    }

    /** @return array{attached: list<array<string, mixed>>} */
    public function attach(int $commandeId, mixed $body, ?string $by): array
    {
        $lines = is_array($body) && is_array($body['products'] ?? null) ? array_values($body['products']) : [];
        if ($lines === []) {
            throw new CommandeProductException('VALIDATION_FAILED', 'products : liste de { productId, purchasePrice?, coefficientMultiplier?, quantity? } attendue', 422, [['path' => 'products', 'message' => 'liste attendue']]);
        }

        return $this->service->attach($this->commande($commandeId), $lines, $by);
    }

    public function detach(int $commandeId, int $productId, ?string $by): void
    {
        $this->service->detach($this->commande($commandeId), $productId, $by);
    }

    public function commandeEntity(int $id): Commande
    {
        return $this->commande($id);
    }

    private function commande(int $id): Commande
    {
        return $this->emProvider->getEntityManager()->find(Commande::class, $id)
            ?? throw new CommandeProductException('COMMANDE_NOT_FOUND', 'Commande introuvable', 404, [], ['commandeId' => $id]);
    }
}
