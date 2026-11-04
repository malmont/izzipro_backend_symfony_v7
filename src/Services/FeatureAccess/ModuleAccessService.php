<?php

namespace App\Services\FeatureAccess;

use App\Cache\CacheTags;
use App\Services\TenantCacheService;
use App\Services\TenantEntityManagerProvider;

/**
 * Modules d'un site et respect côté serveur (console de pilotage, lot 1 bis, 04/11/2026). Jusqu'ici les indicateurs
 * `is_*_active` de la fiche Entreprise n'étaient lus que par les frontaux : un module « désactivé » restait entièrement servi
 * par l'API. Ici : lecture des cinq indicateurs de modules (une requête SQL, cache du site étiqueté « entreprise », donc
 * invalidé par toute écriture Doctrine sur la fiche) et correspondance chemin d'API → module (ModuleAccessSubscriber).
 * Les indicateurs des applications mobiles ne sont pas appliqués ici : les clés d'activation les gouvernent déjà et le relevé
 * du 04/11/2026 les a trouvés incohérents avec l'usage réel. Site sans fiche Entreprise (modèle `gmasuite`) : tout passe.
 * Les droits d'usage (lot 3) remplaceront la lecture des indicateurs sans changer cette interface.
 */
class ModuleAccessService
{
    public const LANDING = 'landing';
    public const BOUTIQUE = 'boutique';
    public const POS = 'pos';
    public const MEMOIRES = 'memoires';
    public const BOUSSOLE = 'boussole';

    /** module => colonne de la fiche Entreprise */
    public const COLUMNS = [
        self::LANDING => 'is_landing_page_active',
        self::BOUTIQUE => 'is_boutique_active',
        self::POS => 'is_pos_active',
        self::MEMOIRES => 'is_memoire_vivante_active',
        self::BOUSSOLE => 'is_boussole_esg_active',
    ];

    public const LABELS = [
        self::LANDING => 'Landing Page',
        self::BOUTIQUE => 'Boutique',
        self::POS => 'Caisse',
        self::MEMOIRES => 'Mémoires Vivantes',
        self::BOUSSOLE => 'Boussole ESG',
    ];

    /**
     * Chemins d'API soumis à un module (expression sur le chemin, sans la requête). Volontairement étroit : les lectures
     * publiques du catalogue restent servies (le site vitrine les affiche même sans boutique) ; sont gardées les routes qui
     * n'ont de sens qu'avec le module. `create-multi-payment` est la vente au comptoir (caisse), pas la boutique.
     * La caisse n'est pas gardée ici : `is_pos_active` est l'indicateur de l'application Caisse (clés d'activation), faux sur
     * des sites qui l'utilisent ; le droit d'usage du module Caisse viendra avec les entitlements (lot 3).
     * `stripe/webhook` n'est jamais gardé (appel de Stripe).
     */
    public const ROUTES = [
        '#^/api/memoires(/|$)#' => self::MEMOIRES,
        '#^/api/boussole(/|$)#' => self::BOUSSOLE,
        '#^/api/(cart/quote|order/create|order/create-guest|payment|stripe/create-intent|subscriptions)(/|$)#' => self::BOUTIQUE,
        '#^/api/landingpage-ai(/|$)#' => self::LANDING,
    ];

    private const CACHE_KEY = 'module_flags';
    private const CACHE_TTL = 600;

    public function __construct(private readonly TenantEntityManagerProvider $emProvider, private readonly TenantCacheService $cache)
    {
    }

    /** Module gardant ce chemin, null si le chemin n'est soumis à aucun module */
    public static function moduleForPath(string $path): ?string
    {
        foreach (self::ROUTES as $pattern => $module) {
            if (preg_match($pattern, $path)) {
                return $module;
            }
        }

        return null;
    }

    public function isEnabled(string $module): bool
    {
        $flags = $this->flags();

        return $flags === null || ($flags[$module] ?? true);
    }

    /** @return array<string, bool>|null null sans fiche Entreprise */
    public function flags(): ?array
    {
        return $this->cache->get(self::CACHE_KEY, function (): ?array {
            $row = $this->emProvider->getEntityManager()->getConnection()->fetchAssociative('SELECT ' . implode(', ', self::COLUMNS) . ' FROM entreprise ORDER BY id LIMIT 1');
            if ($row === false) {
                return null;
            }
            $flags = [];
            foreach (self::COLUMNS as $module => $column) {
                $flags[$module] = $row[$column] === null ? true : (bool) $row[$column];
            }

            return $flags;
        }, self::CACHE_TTL, CacheTags::listTags(CacheTags::ENTREPRISE)); // « entreprise.list » : étiquette vidée par toute écriture de la fiche (CacheInvalidationSubscriber)
    }
}
