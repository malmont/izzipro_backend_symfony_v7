<?php

namespace App\UseCase\BoutiqueSettingsUseCase;

use App\Services\BoutiqueSettingsService\BoutiqueSettingsService;

/** GET /api/boutique-settings : configuration restituée telle qu'enregistrée (JSON brut), null si rien n'est enregistré */
class GetBoutiqueSettingsUseCase
{
    public function __construct(private readonly BoutiqueSettingsService $service)
    {
    }

    /** @return string|null JSON */
    public function execute(): ?string
    {
        $setting = $this->service->findOrCreateSettings();
        if (empty($setting->getConfiguration())) {
            return null;
        }

        return $this->service->getRawConfiguration($setting) ?? json_encode($setting->getConfiguration(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
