<?php

namespace App\Gestion\UseCase;

use App\Gestion\Services\GestionPriceSettingsService;

/** Réglages de prix du site (lot IA-ACT-5) : coefficient par défaut, coefficient minimum, arrondi d'un prix calculé */
class GestionPriceSettingsUseCase
{
    public function __construct(private readonly GestionPriceSettingsService $settings)
    {
    }

    /** @return array<string, mixed> */
    public function settings(): array
    {
        return $this->settings->settings();
    }

    /** @param array<string, mixed> $body @return array<string, mixed> */
    public function put(array $body): array
    {
        return $this->settings->put($body);
    }
}
