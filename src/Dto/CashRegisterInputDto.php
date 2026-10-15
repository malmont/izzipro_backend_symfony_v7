<?php

namespace App\Dto;

use App\Services\CaisseService\CashRegisterException;

/** Corps de POST et PUT /api/cash-registers : {name?, isActive?} ; validé → VALIDATION_FAILED (422) */
class CashRegisterInputDto
{
    public function __construct(public readonly ?string $name, public readonly ?bool $isActive, public readonly bool $hasName, public readonly bool $hasIsActive)
    {
    }

    public static function fromRequestBody(?string $json, bool $nameRequired): self
    {
        $data = json_decode((string) $json, true);
        if (!is_array($data)) {
            throw new CashRegisterException('VALIDATION_FAILED', 'Corps JSON invalide', 422, [['path' => '', 'message' => 'corps JSON invalide']]);
        }
        $errors = [];
        $hasName = array_key_exists('name', $data);
        $name = $hasName ? trim((string) $data['name']) : null;
        if (($nameRequired || $hasName) && ($name === null || $name === '')) {
            $errors[] = ['path' => 'name', 'message' => 'le nom est requis'];
        } elseif ($name !== null && mb_strlen($name) > 100) {
            $errors[] = ['path' => 'name', 'message' => '100 caractères au plus'];
        }
        $hasIsActive = array_key_exists('isActive', $data);
        if ($hasIsActive && !is_bool($data['isActive'])) {
            $errors[] = ['path' => 'isActive', 'message' => 'booléen attendu'];
        }
        if ($errors) {
            throw new CashRegisterException('VALIDATION_FAILED', $errors[0]['path'] . ' : ' . $errors[0]['message'], 422, $errors);
        }

        return new self($name, $hasIsActive ? (bool) $data['isActive'] : null, $hasName, $hasIsActive);
    }
}
