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
    public function settings(string $locale = 'fr'): array
    {
        return $this->settings->settings($locale);
    }

    /** @param array<string, mixed> $body @return array<string, mixed> */
    public function put(array $body, string $locale = 'fr'): array
    {
        return $this->settings->put($body, $locale);
    }
}
