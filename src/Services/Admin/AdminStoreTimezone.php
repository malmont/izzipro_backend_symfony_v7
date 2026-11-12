<?php

namespace App\Services\Admin;

use App\Controller\Admin\BaseTenantCrudController;
use App\Services\CaisseService\StoreClock;
use App\Services\TenantConnectionProvider;

/**
 * Fuseau du magasin pour les écrans EasyAdmin des sites (lot EA-FUSEAU, 12/11/2026, `docs/architecture.md`, « Dates et heures dans
 * EasyAdmin »). Les dates et heures sont en base en UTC ; l'administrateur les lit et les saisit à l'heure de son magasin. Le fuseau est
 * celui de l'horloge du magasin (`StoreClock` : réglage du site, sinon province ou pays de l'entreprise, sinon UTC).
 *
 * Branché une fois pour tous les écrans de `BaseTenantCrudController` par `StoreTimezoneFieldConfigurator` (champs) et
 * `StoreTimezoneFilterConfigurator` (filtres) : aucun écran n'a de ligne à écrire, et un champ ajouté plus tard est couvert.
 */
final class AdminStoreTimezone
{
    /** Mention ajoutée au libellé d'un champ date et heure, sur le formulaire et la fiche */
    public const STORE_MENTION = '(heure du magasin)';
    /** Site sans fuseau ni pays réglé : l'horloge retombe sur UTC, et le libellé le dit */
    public const UTC_MENTION = '(UTC)';

    /** @var array<string, array{timezone: string, source: string}> par site : une lecture par requête, pas une par champ et par ligne */
    private array $settings = [];

    public function __construct(private readonly StoreClock $clock, private readonly TenantConnectionProvider $tenants)
    {
    }

    /** Vrai pour un écran de site (classe de base des écrans par site) */
    public static function applies(?string $controllerFqcn): bool
    {
        return $controllerFqcn !== null && is_a($controllerFqcn, BaseTenantCrudController::class, true);
    }

    /** Nom du fuseau du magasin (« America/Toronto ») */
    public function name(): string
    {
        return $this->settings()['timezone'];
    }

    /** Mention du libellé : « (heure du magasin) », ou « (UTC) » quand le site n'a ni fuseau ni pays réglé */
    public function mention(): string
    {
        return $this->settings()['source'] === 'default' ? self::UTC_MENTION : self::STORE_MENTION;
    }

    /** @return array{timezone: string, source: string} */
    private function settings(): array
    {
        return $this->settings[(string) $this->tenants->getTenantCode()] ??= $this->clock->settings();
    }
}
