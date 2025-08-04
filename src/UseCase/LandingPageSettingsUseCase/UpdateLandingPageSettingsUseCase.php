<?php

namespace App\UseCase\LandingPageSettingsUseCase;

use App\Dto\LandingPageSettingsInputDto;
use App\Entity\LandingPageSetting;
use App\Services\LandingPageSettingsService\LandingPageSettingsService;

class UpdateLandingPageSettingsUseCase
{
    public function __construct(private LandingPageSettingsService $service)
    {
    }

    public function execute(LandingPageSettingsInputDto $dto): LandingPageSetting
    {
        $setting = $this->service->findOrCreateSettings();
        $this->service->updateSettings($setting, $dto->configuration);

        return $setting;
    }
}
