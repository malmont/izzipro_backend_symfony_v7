<?php

namespace App\Dto;

/** Clé d'activation telle que listée (jamais la clé en clair : seulement son début, key_hint) */
class AppKeyOutputDto
{
    /** @param array<string, mixed> $row ligne de tenant_app_keys */
    public static function toArray(array $row): array
    {
        $date = fn (?string $value) => $value ? (new \DateTimeImmutable($value))->format(\DateTimeInterface::ATOM) : null;

        return [
            'id' => (int) $row['id'],
            'app' => $row['app'],
            'hint' => $row['key_hint'],
            'label' => $row['label'],
            'createdBy' => $row['created_by'],
            'createdAt' => $date($row['created_at']),
            'expiresAt' => $date($row['expires_at']),
            'revokedAt' => $date($row['revoked_at']),
            'lastUsedAt' => $date($row['last_used_at']),
            'lastDevice' => $row['last_device'],
            'useCount' => (int) $row['use_count'],
            'cashRegister' => ($row['cash_register_id'] ?? null) !== null ? ['id' => (int) $row['cash_register_id'], 'name' => (string) $row['cash_register_name']] : null,
            'isActive' => $row['revoked_at'] === null && ($row['expires_at'] === null || new \DateTimeImmutable($row['expires_at']) >= new \DateTimeImmutable()),
        ];
    }
}
