<?php

namespace App\UseCase\AppKeyUseCase;

use App\Services\AppKeyService\AppKeyService;

/** POST /api/app/activate : de la clé saisie au site (code, nom, hôte complet pour X-Tenant-Host) */
class ActivateAppUseCase
{
    public function __construct(private readonly AppKeyService $keys)
    {
    }

    /** @param array<string, mixed> $data corps JSON {key, app?, deviceName?} */
    public function execute(array $data): array
    {
        $device = isset($data['deviceName']) && is_scalar($data['deviceName']) ? trim((string) $data['deviceName']) : null;

        return $this->keys->activate((string) ($data['key'] ?? ''), isset($data['app']) && is_scalar($data['app']) ? (string) $data['app'] : null, $device !== '' ? $device : null);
    }
}
