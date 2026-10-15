<?php

namespace App\UseCase\AppKeyUseCase;

use App\Dto\AppKeyOutputDto;
use App\Services\AppKeyService\AppKeyService;
use App\Services\TenantConnectionProvider;

/** Clés d'activation du site courant (sans les clés en clair) */
class ListAppKeysUseCase
{
    public function __construct(private readonly AppKeyService $keys, private readonly TenantConnectionProvider $tenant)
    {
    }

    /** @return list<array<string, mixed>> */
    public function execute(): array
    {
        $tenantCode = $this->tenant->getTenantCode() ?? throw new \RuntimeException('Site non identifié');

        return array_map([AppKeyOutputDto::class, 'toArray'], $this->keys->list($tenantCode));
    }
}
