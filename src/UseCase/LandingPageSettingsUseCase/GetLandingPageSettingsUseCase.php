<?php

namespace App\UseCase\LandingPageSettingsUseCase;

use App\Entity\LandingPageSetting;
use App\Services\LandingPageSettingsService\LandingPageSettingsService;

class GetLandingPageSettingsUseCase
{
    public function __construct(private LandingPageSettingsService $service)
    {
    }

    public function execute(): LandingPageSetting
    {
        return $this->service->findOrCreateSettings();
    }
}
