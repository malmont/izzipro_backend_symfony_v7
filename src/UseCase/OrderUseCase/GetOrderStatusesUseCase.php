<?php

namespace App\UseCase\OrderUseCase;

use App\Entity\StatusCommande;
use App\Services\TenantEntityManagerProvider;

/** GET /api/order-statuses : statuts de commande du site, dans la langue demandée (identifiants communs à tous les sites) */
class GetOrderStatusesUseCase
{
    public function __construct(private readonly TenantEntityManagerProvider $emProvider)
    {
    }

    /** @return list<array{id: int, name: string, description: ?string}> */
    public function execute(string $locale): array
    {
        $statuses = $this->emProvider->getEntityManager()->getRepository(StatusCommande::class)->findBy([], ['id' => 'ASC']);

        return array_map(fn (StatusCommande $status) => [
            'id' => (int) $status->getId(),
            'name' => (string) ($status->getTranslation($locale)?->getName() ?? $status->getName()),
            'description' => $status->getTranslation($locale)?->getDescription() ?? $status->getDescription(),
        ], $statuses);
    }
}
