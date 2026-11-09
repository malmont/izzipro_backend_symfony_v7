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


    /** JSON enregistré du site sans créer de ligne (verrou de version, 09/11/2026) ; null sans réglage */
    public function currentRawConfiguration(): ?string
    {
        $setting = $this->emProvider->getEntityManager()->getRepository(LandingPageSetting::class)->findOneBy([], ['id' => 'ASC']);

        return $setting !== null && !empty($setting->getConfiguration()) ? $this->getRawConfiguration($setting) : null;
    }

    /** Texte JSON tel qu'il est stocké (colonne json : le texte n'est pas réécrit par PostgreSQL) */
    public function getRawConfiguration(LandingPageSetting $setting): ?string
    {
        if ($setting->getId() === null) {
            return null;
        }
        $tenantEm = $this->emProvider->getEntityManager();
        $table = $tenantEm->getClassMetadata(LandingPageSetting::class)->getTableName();
        $raw = $tenantEm->getConnection()->fetchOne("SELECT configuration FROM $table WHERE id = ?", [$setting->getId()]);

        return is_string($raw) ? $raw : null;
    }

    public function updateSettings(LandingPageSetting $setting, array $newConfig): void
    {
        $tenantEm = $this->emProvider->getEntityManager();
        $setting->setConfiguration($newConfig);
        $tenantEm->flush();
    }
}