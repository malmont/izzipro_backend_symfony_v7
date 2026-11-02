<?php

namespace App\Services\BoutiqueSettingsService;

use App\Entity\BoutiqueSetting;
use App\Entity\ContentAuditLog;
use App\Services\TenantEntityManagerProvider;

/**
 * Réglages publiés de la boutique du site courant (base du tenant) : une ligne JSON, lue telle qu'enregistrée et
 * remplacée en entier à chaque écriture. Le contrôle du contenu est fait avant, par BoutiqueConfigurationValidator.
 */
class BoutiqueSettingsService
{
    /** Octets de la configuration encodée (une page de démonstration pèse environ 120 Ko ; la boutique en compte une quinzaine) */
    public const MAX_CONFIGURATION_BYTES = 4194304;

    /**
     * Clés de « commerce » servies en lecture seule par GET /api/boutique-settings depuis la fiche Entreprise (F18 partie 2a) :
     * pricesIncludeTax (entreprise.prices_include_tax), siteCountry (pays de l'adresse du site). Un PUT les ignore : jamais enregistrées,
     * donc jamais remises à faux parce que le front ne les renvoie pas.
     */
    public const READ_ONLY_COMMERCE = ['pricesIncludeTax', 'siteCountry'];

    public function __construct(private readonly TenantEntityManagerProvider $emProvider)
    {
    }

    /** Retire de la configuration reçue les clés de « commerce » en lecture seule (objet modifié en place, renvoyé) */
    public static function stripReadOnlyCommerce(object $configuration): object
    {
        if (isset($configuration->commerce) && is_object($configuration->commerce)) {
            foreach (self::READ_ONLY_COMMERCE as $key) {
                unset($configuration->commerce->$key);
            }
        }

        return $configuration;
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
        return $this->commerceSetting('guestCheckout') !== false;
    }

    /** Une clé de « commerce » des réglages publiés (guestCheckout, currency, subscriptionsEnabled, taxProvider…), null si absente */
    public function commerceSetting(string $key): mixed
    {
        $setting = $this->emProvider->getEntityManager()->getRepository(BoutiqueSetting::class)->findOneBy([]);

        return $setting?->getConfiguration()['commerce'][$key] ?? null;
    }

    /**
     * Charte publiée de la boutique (clé « charter » des réglages : primaryColor, accentColor, textColor, backgroundColor,
     * headingFont, bodyFont, radius, buttonStyle), [] si rien n'est réglé. Lue seule, en SQL : la configuration entière
     * pèse jusqu'à 114 Ko et n'a pas à être décodée pour cela (thème de l'application mobile, AppThemeService).
     *
     * @return array<string, mixed>
     */
    public function charter(): array
    {
        $tenantEm = $this->emProvider->getEntityManager();
        $table = $tenantEm->getClassMetadata(BoutiqueSetting::class)->getTableName();
        $raw = $tenantEm->getConnection()->fetchOne("SELECT configuration->'charter' FROM $table ORDER BY id ASC LIMIT 1");
        $charter = is_string($raw) ? json_decode($raw, true) : null;

        return is_array($charter) ? $charter : [];
    }

    /** Date du dernier enregistrement des réglages par l'éditeur (journal « boutique-settings »), null s'il n'y en a pas */
    public function lastEditorChangeAt(): ?\DateTimeImmutable
    {
        $tenantEm = $this->emProvider->getEntityManager();
        $table = $tenantEm->getClassMetadata(ContentAuditLog::class)->getTableName();
        $raw = $tenantEm->getConnection()->fetchOne("SELECT MAX(created_at) FROM $table WHERE resource = 'boutique-settings'");

        return is_string($raw) && $raw !== '' ? new \DateTimeImmutable($raw) : null;
    }

    public function updateSettings(BoutiqueSetting $setting, array $newConfig): void
    {
        $tenantEm = $this->emProvider->getEntityManager();
        $setting->setConfiguration($newConfig);
        $tenantEm->flush();
    }
}
