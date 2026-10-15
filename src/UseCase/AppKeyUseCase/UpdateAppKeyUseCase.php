<?php

namespace App\UseCase\AppKeyUseCase;

use App\Dto\AppKeyOutputDto;
use App\Services\AppKeyService\AppKeyException;
use App\Services\AppKeyService\AppKeyService;
use App\Services\CaisseService\CashRegisterResolver;
use App\Services\TenantConnectionProvider;

/** PUT /api/app-keys/{id} {label?, cashRegisterId?: int|null} : libellé et poste de caisse (clé POS) d'une clé du site */
class UpdateAppKeyUseCase
{
    public function __construct(private readonly AppKeyService $keys, private readonly TenantConnectionProvider $tenant, private readonly CashRegisterResolver $cashRegisters)
    {
    }

    public function execute(int $id, ?string $json): array
    {
        $tenantCode = $this->tenant->getTenantCode() ?? throw new \RuntimeException('Site non identifié');
        $data = json_decode((string) $json, true);
        if (!is_array($data)) {
            throw new AppKeyException('VALIDATION_FAILED', 'Corps JSON invalide', 422, [['path' => '', 'message' => 'corps JSON invalide']]);
        }
        $label = null;
        if (array_key_exists('label', $data)) {
            $label = trim((string) $data['label']);
            if ($label === '' || mb_strlen($label) > 100) {
                throw new AppKeyException('VALIDATION_FAILED', 'label : 1 à 100 caractères', 422, [['path' => 'label', 'message' => '1 à 100 caractères']]);
            }
        }
        $setRegister = array_key_exists('cashRegisterId', $data);
        $register = null;
        if ($setRegister && $data['cashRegisterId'] !== null && $data['cashRegisterId'] !== '') {
            if (!is_numeric($data['cashRegisterId'])) {
                throw new AppKeyException('VALIDATION_FAILED', 'cashRegisterId : identifiant de poste attendu', 422, [['path' => 'cashRegisterId', 'message' => 'identifiant de poste attendu']]);
            }
            $register = GenerateAppKeyUseCase::cashRegister($this->cashRegisters, (int) $data['cashRegisterId']);
        }

        return AppKeyOutputDto::toArray($this->keys->update($id, $tenantCode, $label, $setRegister, $register));
    }
}
