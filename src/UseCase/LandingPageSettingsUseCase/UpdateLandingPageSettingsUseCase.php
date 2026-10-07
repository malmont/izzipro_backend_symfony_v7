<?php

namespace App\UseCase\LandingPageSettingsUseCase;

use App\Dto\LandingPageSettingsInputDto;
use App\Entity\LandingPageSetting;
use App\Services\ContentAuditService\ContentAuditRecorder;
use App\Services\LandingPageSettingsService\LandingPageSettingsService;

class UpdateLandingPageSettingsUseCase
{
    public function __construct(private LandingPageSettingsService $service, private readonly ContentAuditRecorder $audit)
    {
    }

    public function execute(LandingPageSettingsInputDto $dto): LandingPageSetting
    {
        $setting = $this->service->findOrCreateSettings();
        $before = $this->service->getRawConfiguration($setting);
        $this->service->updateSettings($setting, $dto->configuration);
        // journal des écritures : configuration complète avant / après, et les parties modifiées
        $after = json_encode($dto->configuration, ContentAuditRecorder::JSON_FLAGS);
        $old = json_decode((string) $before, true) ?? [];
        $new = json_decode($after, true) ?? [];
        $changed = array_values(array_filter(array_unique([...array_keys($old), ...array_keys($new)]), fn ($key) => ($old[$key] ?? null) != ($new[$key] ?? null)));
        if ($changed || $before === null) {
            $this->audit->record('landingpage-settings', $setting->getId(), 'update', $before, $after, $changed);
        }

        return $setting;
    }
}
