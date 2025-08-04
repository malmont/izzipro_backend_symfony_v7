<?php

namespace App\Services\LandingPageSettingsService;

use App\Entity\LandingPageSetting;
use App\Services\TenantEntityManagerProvider;

class LandingPageSettingsService
{
    private TenantEntityManagerProvider $emProvider;

    public function __construct(TenantEntityManagerProvider $emProvider)
    {
        $this->emProvider = $emProvider;
    }


    public function findOrCreateSettings(): LandingPageSetting
    {
        $tenantEm = $this->emProvider->getEntityManager();
        $setting = $tenantEm->getRepository(LandingPageSetting::class)->findOneBy([]);

        if (!$setting) {
            $setting = new LandingPageSetting();
            $tenantEm->persist($setting);
        }

        return $setting;
    }


    public function updateSettings(LandingPageSetting $setting, array $newConfig): void
    {
        $tenantEm = $this->emProvider->getEntityManager();
        $setting->setConfiguration($newConfig);
        $tenantEm->flush();
    }
}