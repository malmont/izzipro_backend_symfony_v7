<?php

namespace App\Services\FeatureAccess;

use App\Entity\AiCreditSetting;
use App\Entity\EasyPostConfiguration;
use App\Entity\EmailConfiguration;
use App\Entity\Entreprise;
use App\Entity\GooglePlacesConfig;
use App\Entity\ReviewSetting;
use App\MemoiresVivantes\Services\AiQuotaService;
use App\Services\AppKeyService\AppKeyService;
use App\Services\BoutiqueSettingsService\BoutiqueConfigurationValidator;
use App\Services\BoutiqueSettingsService\BoutiqueSettingsService;
use App\Services\CaisseService\SiteLocaleResolver;
use App\Services\CaisseService\StoreClock;
use App\Services\Platform\CustomDomainService;
use App\Services\Platform\EntitlementService;
use App\Services\Platform\FeatureRequestRepository;
use App\Services\StripeService\StripeAccountResolver;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;

/**
 * État du registre pour le site courant (console de pilotage, lot 2, 04/11/2026) : pour chaque entrée de FeatureCatalog, la
 * valeur réglée, la valeur effective quand elle est déduite (langue, fuseau), les prérequis vérifiés (ok / manquant) et un
 * état : `ready` (en service), `incomplete` (activé mais un prérequis manque), `off` (désactivé), `info` (lecture seule).
 * Lu par GET /api/site/features (éditeur Next, panneau « Commerce ») et par les écrans EasyAdmin « Mon site » et « Plateforme ».
 * Aucune valeur secrète : les clés des services externes ne sont jamais rendues, seulement leur présence.
 */
class SiteFeatureStateService
{
    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly TenantConnectionManager $tenants,
        private readonly SiteLocaleResolver $locale,
        private readonly StoreClock $clock,
        private readonly ModuleAccessService $modules,
        private readonly AppKeyService $appKeys,
        private readonly BoutiqueSettingsService $boutique,
        private readonly StripeAccountResolver $stripe,
        private readonly AiQuotaService $mvQuotas,
        private readonly EntitlementService $entitlements,
        private readonly FeatureRequestRepository $requests,
        private readonly CustomDomainService $domains,
        private readonly ?\App\Services\ShippingService\ShippingSetupService $shippingSetup = null, // étiquettes par la plateforme (lot 2 Livraison, 07/11/2026)
        private readonly ?\App\Services\Help\HelpCatalogService $help = null, // aide intégrée (07/11/2026)
    ) {
    }

    /** @return list<array<string, mixed>> une entrée par fonctionnalité, dans l'ordre du registre */
    public function all(): array
    {
        $em = $this->emProvider->getEntityManager();
        $e = $em->getRepository(Entreprise::class)->findOneBy([], ['id' => 'ASC']);
        $flags = $this->modules->flags() ?? array_fill_keys(array_keys(ModuleAccessService::COLUMNS), true);
        $activeKeys = $this->activeKeysPerApp();
        $commerce = $this->commerce();
        [$entitlements, $pending] = $this->platformState();
        $out = [];
        foreach (FeatureCatalog::FEATURES as $key => $def) {
            $entry = $this->entry($key, $def, $e, $flags, $activeKeys, $commerce);
            $entry['helpKey'] = $this->help?->forSetting($key) ?? $this->help?->forScreen($def['href'] ?? null); // page d'aide (07/11/2026)
            if ($def['scope'] === FeatureCatalog::SCOPE_PLATFORM) {
                $ent = $entitlements[$key] ?? null;
                $entry['entitlement'] = $ent && $ent['enabled'] ? ['id' => $ent['id'], 'source' => $ent['source'], 'status' => $ent['status'], 'endsAt' => $ent['endsAt'], 'graceUntil' => $ent['graceUntil'], 'offerCode' => $ent['offerCode']] : null;
                $entry['request'] = $pending[$key] ?? null;
            }
            $out[] = $entry;
        }

        return $out;
    }

    /** @return array<string, mixed>|null */
    public function one(string $key): ?array
    {
        foreach ($this->all() as $entry) {
            if ($entry['key'] === $key) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * @param array<string, bool> $flags
     * @param array<string, int> $activeKeys
     * @param array<string, mixed> $commerce
     * @return array<string, mixed>
     */
    private function entry(string $key, array $def, ?Entreprise $e, array $flags, array $activeKeys, array $commerce): array
    {
        $base = ['key' => $key, 'label' => $def['label'], 'group' => $def['group'], 'scope' => $def['scope'], 'type' => $def['type'], 'options' => $def['options'] ?? null, 'nullable' => $def['nullable'] ?? false, 'href' => $def['href'] ?? null];
        $value = null;
        $effective = null;
        $prerequisites = [];
        $status = 'info';
        $details = null;
        $boutiqueOn = $flags['boutique'] ?? true;
        switch ($key) {
            case 'site.locale':
                $value = $e?->getLocale();
                $effective = $this->locale->siteLocale();
                $status = 'ready';
                break;
            case 'gestion.periods': // lot P1 périodes (10/11/2026)
                $value = $e?->getPurchasePeriodFrequency();
                $effective = $value ?? \App\Gestion\Services\PeriodCalendar::DEFAULT_FREQUENCY;
                $details = ['graceDays' => \App\Gestion\Services\PeriodCalendar::GRACE_DAYS, 'appliesAfterCurrentPeriod' => true];
                $status = 'ready';
                break;
            case 'site.timezone':
                $settings = $this->clock->settings();
                $value = $e?->getPosTimezone();
                $effective = $settings['timezone'] ?? null;
                $details = ['source' => $settings['source'] ?? null];
                $status = 'ready';
                break;
            case 'site.country':
                $value = $this->locale->siteCountry();
                $status = $value !== null ? 'ready' : 'incomplete';
                $prerequisites[] = $this->prereq('entreprise.address', 'Adresse du site renseignée', $value !== null);
                break;
            case 'site.domain':
                $code = (string) $this->tenants->getCurrentTenantCode();
                $d = $code !== '' ? $this->domains->status($code) : ['domain' => null, 'state' => 'none', 'platformHost' => null];
                $value = $d['domain'];
                $details = ['state' => $d['state'], 'platformHost' => $d['platformHost']];
                $status = $d['state'] === 'none' ? 'off' : ($d['state'] === 'live' ? 'ready' : 'incomplete');
                if ($d['state'] !== 'none') {
                    $prerequisites[] = $this->prereq('domain.dns', 'DNS pointé vers le serveur de la plateforme', $d['checks']['dns']['ok'] ?? false);
                }
                break;
            case 'site.currency':
                $value = $e?->getCurrency() ?? 'CAD';
                $status = 'ready';
                break;
            case 'site.prices_include_tax':
                $value = (bool) ($e?->isPricesIncludeTax() ?? false);
                $status = $value ? 'ready' : 'off';
                break;
            case 'module.landing': case 'module.boutique': case 'module.pos': case 'module.memoires': case 'module.boussole':
                $module = substr($key, 7);
                $value = (bool) ($flags[$module] ?? true);
                $status = $value ? 'ready' : 'off';
                if ($module === 'pos') {
                    $details = ['activeKeys' => $activeKeys['pos'] ?? 0];
                    $prerequisites[] = $this->prereq('app.key', 'Au moins une clé d\'activation valide', ($activeKeys['pos'] ?? 0) > 0);
                    $status = $value ? (($activeKeys['pos'] ?? 0) > 0 ? 'ready' : 'incomplete') : 'off';
                }
                break;
            case 'app.mobile_shop': case 'app.management': case 'app.living_memory':
                $app = ['app.mobile_shop' => 'mobile', 'app.management' => 'admin', 'app.living_memory' => 'livingmemory'][$key];
                $flag = ['mobile' => 'isMobileShopActive', 'admin' => 'isManagementAppActive', 'livingmemory' => 'isLivingMemoryActive'][$app];
                $value = (bool) ($e?->{$flag}() ?? false);
                $details = ['activeKeys' => $activeKeys[$app] ?? 0];
                $status = $value ? (($activeKeys[$app] ?? 0) > 0 ? 'ready' : 'incomplete') : 'off';
                $prerequisites[] = $this->prereq('app.key', 'Au moins une clé d\'activation valide', ($activeKeys[$app] ?? 0) > 0);
                break;
            case 'ai.help': case 'ai.full':
                // paliers de l'assistant IA (lot 6, 07/11/2026) : indicateur projeté depuis le droit d'usage ; en mode démo tout est ouvert
                $value = (bool) ($e?->{$key === 'ai.help' ? 'isAiHelpActive' : 'isAiFullActive'}() ?? false);
                $status = $value ? 'ready' : 'off';
                break;
            case 'commerce.sales_modes':
                $value = array_values($commerce['salesModes'] ?? BoutiqueConfigurationValidator::SALES_MODES);
                $prerequisites[] = $this->prereq('module.boutique', 'Module Boutique activé', $boutiqueOn);
                $status = $boutiqueOn ? 'ready' : 'incomplete';
                break;
            case 'commerce.guest_checkout':
                $value = ($commerce['guestCheckout'] ?? true) !== false;
                $prerequisites[] = $this->prereq('module.boutique', 'Module Boutique activé', $boutiqueOn);
                $status = !$value ? 'off' : ($boutiqueOn ? 'ready' : 'incomplete');
                break;
            case 'commerce.subscriptions':
                $value = ($commerce['subscriptionsEnabled'] ?? false) === true;
                $mode = $this->stripe->mode(StripeAccountResolver::CHANNEL_SHOP);
                $prerequisites[] = $this->prereq('module.boutique', 'Module Boutique activé', $boutiqueOn);
                $prerequisites[] = $this->prereq('commerce.sales_modes', 'Mode « abonnement » accepté par la boutique', in_array('subscription', $commerce['salesModes'] ?? BoutiqueConfigurationValidator::SALES_MODES, true));
                $prerequisites[] = $this->prereq('stripe.online', 'Compte Stripe du site prêt', $this->stripeReady($mode));
                $prerequisites[] = $this->prereq('subscription_plan.active', 'Au moins une formule d\'abonnement active', $this->count('subscription_plan', 'active = TRUE') > 0);
                $prerequisites[] = $this->prereq('carrier.fixed_price', 'Un transporteur à prix fixe ou gratuit', $this->count('carrier', 'carrier_account_id IS NULL') > 0);
                $status = !$value ? 'off' : ($this->allOk($prerequisites) ? 'ready' : 'incomplete');
                break;
            case 'commerce.tax_provider':
                $value = $commerce['taxProvider'] ?? 'table';
                $prerequisites[] = $this->prereq('module.boutique', 'Module Boutique activé', $boutiqueOn);
                if ($value === 'stripe') {
                    $prerequisites[] = $this->prereq('site.prices_include_tax', 'Prix hors taxes (Stripe Tax impossible en TTC)', !($e?->isPricesIncludeTax() ?? false));
                    $prerequisites[] = $this->prereq('stripe.online', 'Compte Stripe du site prêt', $this->stripeReady($this->stripe->mode(StripeAccountResolver::CHANNEL_SHOP)));
                } else {
                    $prerequisites[] = $this->prereq('tax.table', 'Au moins une taxe dans la table du site', $this->count('tax') > 0);
                }
                $status = $this->allOk($prerequisites) ? 'ready' : 'incomplete';
                break;
            case 'stripe.online':
                $mode = $this->stripe->mode(StripeAccountResolver::CHANNEL_SHOP);
                $value = $mode;
                $details = ['accountConfigured' => $this->stripe->isInternal() || $this->stripe->accountId($mode) !== null, 'keysConfigured' => $this->stripe->keysConfigured($mode)];
                $status = $this->stripeReady($mode) ? 'ready' : 'incomplete';
                break;
            case 'stripe.pos':
                $mode = $this->stripe->mode(StripeAccountResolver::CHANNEL_POS);
                $value = $mode;
                $prerequisites[] = $this->prereq('module.pos', 'Module Caisse activé', $flags['pos'] ?? true);
                $details = ['accountConfigured' => $this->stripe->isInternal() || $this->stripe->accountId($mode) !== null, 'keysConfigured' => $this->stripe->keysConfigured($mode)];
                $status = !($flags['pos'] ?? true) ? 'off' : ($this->stripeReady($mode) ? 'ready' : 'incomplete');
                break;
            case 'shipping.easypost':
                $env = $this->boutique->shippingSettings()['easypostEnvironment'];
                $value = $this->present(fn () => $this->emProvider->getEntityManager()->getRepository(EasyPostConfiguration::class)->findOneBy([]), [$env === 'live' ? 'getEasypostApiKeyProd' : 'getEasypostApiKeySandbox']);
                $details = ['environment' => $env];
                $status = $value ? 'ready' : 'off';
                break;
            case 'shipping.mode':
                $shipping = $this->boutique->shippingSettings();
                $value = $shipping['mode'];
                $prerequisites[] = $this->prereq('module.boutique', 'Module Boutique activé', $boutiqueOn);
                $prerequisites[] = $this->prereq('carrier.any', 'Au moins un transporteur ou le retrait en magasin', $this->count('carrier') > 0);
                if ($value === 'own_key') {
                    $env = $shipping['easypostEnvironment'];
                    $prerequisites[] = $this->prereq('shipping.easypost', 'Clé EasyPost du site pour l\'environnement ' . $env, $this->present(fn () => $this->emProvider->getEntityManager()->getRepository(EasyPostConfiguration::class)->findOneBy([]), [$env === 'live' ? 'getEasypostApiKeyProd' : 'getEasypostApiKeySandbox']));
                } elseif ($value === 'platform') {
                    $platform = $this->shippingSetup?->platform() ?? ['configured' => false, 'balanceCents' => 0];
                    $prerequisites[] = $this->prereq('shipping.platform', 'Clé EasyPost et comptes transporteurs de la plateforme', (bool) $platform['configured']);
                    $prerequisites[] = $this->prereq('shipping.balance', 'Solde prépayé d\'expédition positif', (int) $platform['balanceCents'] > 0);
                    $details = ['balanceCents' => (int) $platform['balanceCents'], 'currency' => $platform['currency'] ?? 'CAD'];
                }
                $status = $this->allOk($prerequisites) ? 'ready' : 'incomplete';
                break;
            case 'shipping.easypost_environment':
                $value = $this->boutique->shippingSettings()['easypostEnvironment'];
                $status = 'ready';
                break;
            case 'shipping.default_weight_kg':
                $value = $this->boutique->shippingSettings()['defaultWeightKg'];
                $details = ['productsWithoutWeight' => $this->count('product', 'requires_shipping = TRUE AND id NOT IN (SELECT product_id FROM product_shipping WHERE product_id IS NOT NULL)')];
                $status = 'ready';
                break;
            case 'shipping.free_above':
                $value = $this->boutique->shippingSettings()['freeShippingAbove'];
                $status = $value === null ? 'off' : 'ready';
                break;
            case 'maps.google_places':
                $value = $this->present(fn () => $this->emProvider->getEntityManager()->getRepository(GooglePlacesConfig::class)->findOneBy([]), ['getGoogleApiKeyProd', 'getGoogleApiKeyTest']);
                $status = $value ? 'ready' : 'off';
                break;
            case 'email.smtp':
                $value = $this->present(fn () => $this->emProvider->getEntityManager()->getRepository(EmailConfiguration::class)->findOneBy([]), ['getSmtpHost']);
                $status = $value ? 'ready' : 'off';
                $details = ['fallback' => 'serveur de la plateforme'];
                break;
            case 'ai.landing':
                $setting = $this->emProvider->getEntityManager()->getRepository(AiCreditSetting::class)->findOneBy([]);
                $value = $setting?->getMonthlyCredits();
                $status = ($value ?? 0) > 0 ? 'ready' : 'off';
                break;
            case 'ai.memoires':
                $q = $this->mvQuotas->settings();
                $value = ['transcriptionsPerDay' => $q->getTranscriptionsPerDay(), 'improvementsPerDay' => $q->getImprovementsPerDay(), 'regenerationsPerDay' => $q->getRegenerationsPerDay(), 'maxAudioSeconds' => $q->getMaxAudioSeconds(), 'applyToWebBooks' => $q->isApplyToWebBooks(), 'timezone' => $q->getTimezone()];
                $prerequisites[] = $this->prereq('module.memoires', 'Module Mémoires Vivantes activé', $flags['memoires'] ?? true);
                $status = ($flags['memoires'] ?? true) ? 'ready' : 'off';
                break;
            case 'reviews.enabled':
                $setting = $this->emProvider->getEntityManager()->getRepository(ReviewSetting::class)->findOneBy([]);
                $value = (bool) ($setting?->isEnabled() ?? false);
                $prerequisites[] = $this->prereq('module.boutique', 'Module Boutique activé', $boutiqueOn);
                $status = !$value ? 'off' : ($boutiqueOn ? 'ready' : 'incomplete');
                break;
        }

        return $base + ['value' => $value, 'effective' => $effective, 'details' => $details, 'status' => $status, 'prerequisites' => $prerequisites];
    }

    /** @return array{0: array<string, array<string, mixed>>, 1: array<string, string>} droits d'usage effectifs et demandes en attente du site (vides sans code de site) */
    private function platformState(): array
    {
        $code = $this->tenants->getCurrentTenantCode();
        if ($code === null || $code === '') {
            return [[], []];
        }
        try {
            return [$this->entitlements->effective($code), $this->requests->pendingFor($code)];
        } catch (\Throwable) {
            return [[], []];
        }
    }

    /** @return array<string, mixed> clés `commerce` des réglages de la boutique */
    private function commerce(): array
    {
        $setting = $this->emProvider->getEntityManager()->getRepository(\App\Entity\BoutiqueSetting::class)->findOneBy([], ['id' => 'ASC']); // même ligne que BoutiqueSettingsService (première par id)
        $commerce = $setting?->getConfiguration()['commerce'] ?? [];
        if (is_object($commerce)) {
            $commerce = json_decode(json_encode($commerce), true); // écrite dans la même requête en objets (UpdateBoutiqueSettingsUseCase), relue en tableau
        }

        return is_array($commerce) ? $commerce : [];
    }

    /** @return array<string, int> clés valides (ni révoquées ni expirées) par application */
    private function activeKeysPerApp(): array
    {
        $code = $this->tenants->getCurrentTenantCode();
        if ($code === null || $code === '') {
            return [];
        }
        $counts = [];
        try {
            foreach ($this->appKeys->list($code) as $k) {
                $expired = !empty($k['expires_at']) && strtotime((string) $k['expires_at']) < time();
                if (empty($k['revoked_at']) && !$expired) {
                    $counts[$k['app']] = ($counts[$k['app']] ?? 0) + 1;
                }
            }
        } catch (\Throwable) {
            return [];
        }

        return $counts;
    }

    private function stripeReady(string $mode): bool
    {
        try {
            return $this->stripe->keysConfigured($mode) && ($this->stripe->isInternal() || $this->stripe->accountId($mode) !== null);
        } catch (\Throwable) {
            return false;
        }
    }

    /** Présence d'une valeur non vide par l'un des accesseurs (jamais la valeur elle-même) */
    private function present(callable $find, array $getters): bool
    {
        try {
            $entity = $find();
            if ($entity === null) {
                return false;
            }
            foreach ($getters as $getter) {
                $v = $entity->{$getter}();
                if (is_string($v) && trim($v) !== '') {
                    return true;
                }
            }
        } catch (\Throwable) {
        }

        return false;
    }

    private function count(string $table, string $where = 'TRUE'): int
    {
        try {
            return (int) $this->emProvider->getEntityManager()->getConnection()->fetchOne("SELECT COUNT(*) FROM $table WHERE $where");
        } catch (\Throwable) {
            return 0;
        }
    }

    /** @return array{key: string, label: string, ok: bool} */
    private function prereq(string $key, string $label, bool $ok): array
    {
        return ['key' => $key, 'label' => $label, 'ok' => $ok];
    }

    /** @param list<array{ok: bool}> $prerequisites */
    private function allOk(array $prerequisites): bool
    {
        foreach ($prerequisites as $p) {
            if (!$p['ok']) {
                return false;
            }
        }

        return true;
    }
}
