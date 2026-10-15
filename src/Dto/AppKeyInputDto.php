<?php

namespace App\Dto;

use App\Services\AppKeyService\AppKeyException;
use App\Services\AppKeyService\AppKeyService;

/** Corps de POST /api/app-keys : {app, label, expiresInDays?, sendEmail?} ; validé → VALIDATION_FAILED (422) */
class AppKeyInputDto
{
    public function __construct(public readonly string $app, public readonly string $label, public readonly ?\DateTimeImmutable $expiresAt, public readonly bool $sendEmail)
    {
    }

    public static function fromRequestBody(?string $json): self
    {
        $data = json_decode((string) $json, true);
        if (!is_array($data)) {
            throw new AppKeyException('VALIDATION_FAILED', 'Corps JSON invalide', 422, [['path' => '', 'message' => 'corps JSON invalide']]);
        }

        return self::fromArray($data);
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $errors = [];
        $app = strtolower(trim((string) ($data['app'] ?? '')));
        if (!isset(AppKeyService::APPS[$app])) {
            $errors[] = ['path' => 'app', 'message' => 'pos, mobile, admin ou livingmemory attendu'];
        }
        $label = trim((string) ($data['label'] ?? ''));
        if ($label === '') {
            $errors[] = ['path' => 'label', 'message' => 'le libellé (terminal, personne) est requis'];
        } elseif (mb_strlen($label) > 100) {
            $errors[] = ['path' => 'label', 'message' => '100 caractères au plus'];
        }
        $expiresAt = null;
        if (isset($data['expiresInDays']) && $data['expiresInDays'] !== '' && $data['expiresInDays'] !== null) {
            if (!is_numeric($data['expiresInDays']) || (int) $data['expiresInDays'] < 1 || (int) $data['expiresInDays'] > 3650) {
                $errors[] = ['path' => 'expiresInDays', 'message' => 'entre 1 et 3650 jours'];
            } else {
                $expiresAt = (new \DateTimeImmutable())->modify(sprintf('+%d days', (int) $data['expiresInDays']));
            }
        }
        if ($errors) {
            throw new AppKeyException('VALIDATION_FAILED', $errors[0]['path'] . ' : ' . $errors[0]['message'], 422, $errors);
        }

        return new self($app, $label, $expiresAt, !array_key_exists('sendEmail', $data) || filter_var($data['sendEmail'], FILTER_VALIDATE_BOOLEAN));
    }
}
