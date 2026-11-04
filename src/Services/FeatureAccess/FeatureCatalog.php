<?php

namespace App\Services\FeatureAccess;

/**
 * Registre des fonctionnalités d'un site (console de pilotage, lot 2, 04/11/2026) : une entrée par réglage ou module, avec sa
 * portée (`site` : l'administrateur du site le règle ; `platform` : accordé et facturé par la plateforme, super administrateur
 * seulement ; `service` : état d'un service externe ou d'un quota, en lecture ici, réglé sur son écran), son type, son groupe
 * d'affichage, et pour les clés `site` l'endroit où la valeur est écrite (`entreprise.<clé du PUT /api/entreprise>` ou
 * `commerce.<clé des réglages de la boutique>`). Les valeurs, états et prérequis sont calculés par SiteFeatureStateService.
 * Les applications mobiles (`app.*`) et la caisse (`module.pos`, même indicateur `is_pos_active` que l'application Caisse) ne
 * s'écrivent pas ici : la fiche Entreprise génère et révoque leurs clés.
 * Contrat : docs/console-pilotage.md ; les clés sont stables, les applications et l'éditeur traduisent les libellés s'ils le veulent.
 */
final class FeatureCatalog
{
    public const SCOPE_SITE = 'site';
    public const SCOPE_PLATFORM = 'platform';
    public const SCOPE_SERVICE = 'service';

    /** @var array<string, array{label: string, group: string, scope: string, type: string, options?: list<string>, nullable?: bool, storage?: string, href?: string}> */
    public const FEATURES = [
        // Localisation
        'site.locale' => ['label' => 'Langue du site', 'group' => 'localisation', 'scope' => self::SCOPE_SITE, 'type' => 'enum', 'options' => ['fr', 'en'], 'nullable' => true, 'storage' => 'entreprise.siteLocale', 'href' => 'entreprise'],
        'site.timezone' => ['label' => 'Fuseau horaire', 'group' => 'localisation', 'scope' => self::SCOPE_SITE, 'type' => 'timezone', 'nullable' => true, 'storage' => 'entreprise.posTimezone', 'href' => 'entreprise'],
        'site.country' => ['label' => 'Pays du site', 'group' => 'localisation', 'scope' => self::SCOPE_SERVICE, 'type' => 'info', 'href' => 'entreprise'],
        'site.currency' => ['label' => 'Devise', 'group' => 'localisation', 'scope' => self::SCOPE_SITE, 'type' => 'currency', 'storage' => 'entreprise.currency', 'href' => 'entreprise'],
        'site.prices_include_tax' => ['label' => 'Prix taxes comprises', 'group' => 'localisation', 'scope' => self::SCOPE_SITE, 'type' => 'boolean', 'storage' => 'entreprise.pricesIncludeTax', 'href' => 'entreprise'],
        // Modules (plateforme)
        'module.landing' => ['label' => 'Landing Page', 'group' => 'modules', 'scope' => self::SCOPE_PLATFORM, 'type' => 'boolean', 'storage' => 'entreprise.isLandingPageActive', 'href' => 'entreprise'],
        'module.boutique' => ['label' => 'Boutique', 'group' => 'modules', 'scope' => self::SCOPE_PLATFORM, 'type' => 'boolean', 'storage' => 'entreprise.isBoutiqueActive', 'href' => 'entreprise'],
        'module.pos' => ['label' => 'Caisse (application et vente au comptoir)', 'group' => 'modules', 'scope' => self::SCOPE_PLATFORM, 'type' => 'boolean', 'href' => 'app_keys'],
        'module.memoires' => ['label' => 'Mémoires Vivantes', 'group' => 'modules', 'scope' => self::SCOPE_PLATFORM, 'type' => 'boolean', 'storage' => 'entreprise.isMemoireVivanteActive', 'href' => 'entreprise'],
        'module.boussole' => ['label' => 'Boussole ESG', 'group' => 'modules', 'scope' => self::SCOPE_PLATFORM, 'type' => 'boolean', 'storage' => 'entreprise.isBoussoleEsgActive', 'href' => 'entreprise'],
        // Applications mobiles (plateforme, lecture : clés gérées par la fiche Entreprise)
        'app.mobile_shop' => ['label' => 'Application e-commerce mobile', 'group' => 'apps', 'scope' => self::SCOPE_PLATFORM, 'type' => 'boolean', 'href' => 'app_keys'],
        'app.management' => ['label' => 'Application de gestion', 'group' => 'apps', 'scope' => self::SCOPE_PLATFORM, 'type' => 'boolean', 'href' => 'app_keys'],
        'app.living_memory' => ['label' => 'Application Living Memory', 'group' => 'apps', 'scope' => self::SCOPE_PLATFORM, 'type' => 'boolean', 'href' => 'app_keys'],
        // Commerce (réglages de la boutique, miroir du panneau « Commerce » de l'éditeur)
        'commerce.sales_modes' => ['label' => 'Modes de vente acceptés', 'group' => 'commerce', 'scope' => self::SCOPE_SITE, 'type' => 'list', 'options' => ['sale', 'rental', 'subscription'], 'storage' => 'commerce.salesModes', 'href' => 'boutique_settings'],
        'commerce.guest_checkout' => ['label' => 'Paiement sans compte', 'group' => 'commerce', 'scope' => self::SCOPE_SITE, 'type' => 'boolean', 'storage' => 'commerce.guestCheckout', 'href' => 'boutique_settings'],
        'commerce.subscriptions' => ['label' => 'Abonnements (paiement récurrent)', 'group' => 'commerce', 'scope' => self::SCOPE_SITE, 'type' => 'boolean', 'storage' => 'commerce.subscriptionsEnabled', 'href' => 'subscription_plans'],
        'commerce.tax_provider' => ['label' => 'Calcul des taxes', 'group' => 'commerce', 'scope' => self::SCOPE_SITE, 'type' => 'enum', 'options' => ['table', 'stripe'], 'storage' => 'commerce.taxProvider', 'href' => 'taxes'],
        // Services et quotas (lecture)
        'stripe.online' => ['label' => 'Paiements en ligne (Stripe)', 'group' => 'services', 'scope' => self::SCOPE_SERVICE, 'type' => 'info', 'href' => 'stripe_config'],
        'stripe.pos' => ['label' => 'Paiements de caisse (Stripe)', 'group' => 'services', 'scope' => self::SCOPE_SERVICE, 'type' => 'info', 'href' => 'pos_stripe'],
        'shipping.easypost' => ['label' => 'Tarifs de livraison (EasyPost)', 'group' => 'services', 'scope' => self::SCOPE_SERVICE, 'type' => 'info', 'href' => 'easypost'],
        'maps.google_places' => ['label' => 'Adresses (Google Places)', 'group' => 'services', 'scope' => self::SCOPE_SERVICE, 'type' => 'info', 'href' => 'google_places'],
        'email.smtp' => ['label' => 'Envoi des courriels (SMTP du site)', 'group' => 'services', 'scope' => self::SCOPE_SERVICE, 'type' => 'info', 'href' => 'email_configuration'],
        'ai.landing' => ['label' => 'Assistant IA des pages (crédits mensuels)', 'group' => 'services', 'scope' => self::SCOPE_SERVICE, 'type' => 'info', 'href' => 'ai_credits'],
        'ai.memoires' => ['label' => 'Quotas IA Mémoires Vivantes', 'group' => 'services', 'scope' => self::SCOPE_SERVICE, 'type' => 'info', 'href' => 'mv_ai_quotas'],
        'reviews.enabled' => ['label' => 'Avis clients', 'group' => 'services', 'scope' => self::SCOPE_SERVICE, 'type' => 'info', 'href' => 'review_settings'],
    ];

    /** Clés `platform` écrites par le super administrateur ; la caisse et les applications s'activent sur la fiche Entreprise (génération et révocation des clés) */
    public const PLATFORM_WRITABLE = ['module.landing', 'module.boutique', 'module.memoires', 'module.boussole'];

    public static function exists(string $key): bool
    {
        return isset(self::FEATURES[$key]);
    }

    /** @return array{label: string, group: string, scope: string, type: string, options?: list<string>, nullable?: bool, storage?: string, href?: string} */
    public static function get(string $key): array
    {
        return self::FEATURES[$key];
    }

    /** Écrivable par l'administrateur du site (`site`) ou par le super administrateur (`platform` listée) */
    public static function isWritable(string $key, bool $superAdmin): bool
    {
        $f = self::FEATURES[$key] ?? null;
        if ($f === null) {
            return false;
        }

        return $f['scope'] === self::SCOPE_SITE || ($superAdmin && in_array($key, self::PLATFORM_WRITABLE, true));
    }

    /** `entreprise.<clé>` ou `commerce.<clé>` → [cible, clé] */
    public static function storage(string $key): ?array
    {
        $storage = self::FEATURES[$key]['storage'] ?? null;
        if ($storage === null) {
            return null;
        }
        [$target, $field] = explode('.', $storage, 2);

        return [$target, $field];
    }
}
