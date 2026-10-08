<?php

namespace App\Services\BoutiqueSettingsService;

use App\Entity\BoutiqueSetting;
use App\Services\TenantEntityManagerProvider;

/**
 * Réglages publiés de la boutique du site courant (base du tenant) : une ligne JSON, lue telle qu'enregistrée et
 * remplacée en entier à chaque écriture. Le contrôle du contenu est fait avant, par BoutiqueConfigurationValidator.
 */
class BoutiqueSettingsService
{
    /** Octets de la configuration encodée (une page de démonstration pèse environ 120 Ko ; la boutique en compte une quinzaine) */
    public const MAX_CONFIGURATION_BYTES = 4194304;

    public function __construct(private readonly TenantEntityManagerProvider $emProvider)
    {
    }

    public function findOrCreateSettings(): BoutiqueSetting
    {
        $tenantEm = $this->emProvider->getEntityManager();
        $setting = $tenantEm->getRepository(BoutiqueSetting::class)->findOneBy([]);

        if (!$setting) {
            $setting = new BoutiqueSetting();
            $tenantEm->persist($setting);
        }

        return $setting;
    }

    /** Texte JSON tel qu'il est stocké (colonne json : le texte n'est pas réécrit par PostgreSQL) */
    public function getRawConfiguration(BoutiqueSetting $setting): ?string
    {
        if ($setting->getId() === null) {
            return null;
        }
        $tenantEm = $this->emProvider->getEntityManager();
        $table = $tenantEm->getClassMetadata(BoutiqueSetting::class)->getTableName();
        $raw = $tenantEm->getConnection()->fetchOne("SELECT configuration FROM $table WHERE id = ?", [$setting->getId()]);

        return is_string($raw) ? $raw : null;
    }

    /** commerce.guestCheckout des réglages publiés ; permis tant que rien n'est réglé */
    public function isGuestCheckoutAllowed(): bool
    {
        $setting = $this->emProvider->getEntityManager()->getRepository(BoutiqueSetting::class)->findOneBy([]);
        $value = $setting?->getConfiguration()['commerce']['guestCheckout'] ?? null;

        return $value !== false;
    }

    public function updateSettings(BoutiqueSetting $setting, array $newConfig): void
    {
        $tenantEm = $this->emProvider->getEntityManager();
        $setting->setConfiguration($newConfig);
        $tenantEm->flush();
    }
}
