<?php

namespace App\UseCase\AppKeyUseCase;

use App\Dto\AppKeyOutputDto;
use App\Services\AppKeyService\AppKeyService;
use App\Services\TenantConnectionProvider;

/** Révoque une clé du site courant : l'application qui l'utilise ne pourra plus s'activer (les sessions ouvertes restent) */
class RevokeAppKeyUseCase
{
    public function __construct(private readonly AppKeyService $keys, private readonly TenantConnectionProvider $tenant)
    {
    }

    public function execute(int $id): array
    {
        $tenantCode = $this->tenant->getTenantCode() ?? throw new \RuntimeException('Site non identifié');

        return AppKeyOutputDto::toArray($this->keys->revoke($id, $tenantCode));
    }
}
