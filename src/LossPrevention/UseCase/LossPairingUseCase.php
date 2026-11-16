<?php

namespace App\LossPrevention\UseCase;

use App\LossPrevention\Services\LossPairingService;

/**
 * Jumelage d'un boîtier par code (lot L3-a2, `docs/prevention-pertes.md` § 16.16) : le boîtier dépose sa demande et demande où
 * elle en est, sans site ni jeton ; un administrateur jumelle le boîtier à son site.
 */
class LossPairingUseCase
{
    public function __construct(private readonly LossPairingService $pairing)
    {
    }

    /** @param array<string, mixed> $body @return array<string, mixed> */
    public function deposit(array $body, string $ip): array
    {
        return $this->pairing->deposit($body, $ip);
    }

    /** @param array<string, mixed> $body @return array<string, mixed> */
    public function state(array $body, string $ip): array
    {
        return $this->pairing->state($body, $ip);
    }

    /** @param array<string, mixed> $body @return array<string, mixed> */
    public function pair(array $body, string $locale, int $userId, string $ip): array
    {
        return $this->pairing->pair($body, $locale, $userId, $ip);
    }
}
