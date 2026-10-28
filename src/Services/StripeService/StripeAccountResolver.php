<?php

namespace App\Services\StripeService;

use App\Entity\Entreprise;
use App\Entity\StripeConfig;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;

/**
 * Clé Stripe et compte Connect d'un site, par canal et par mode (M1). Réutilisable : la caisse s'en sert dès maintenant (réglage par
 * site « Paiements de caisse : test | réel ») ; la boutique en ligne garde sa clé et son compte actuels (canal « shop » : mode de la
 * clé de la plateforme) et pourra s'en servir le jour où elle passera en réel.
 *
 * Clés (variables d'environnement, jamais en base ni au journal) :
 * - test : STRIPE_SECRET_KEY / STRIPE_PUBLIC_KEY tant qu'elles sont de test (sinon STRIPE_TEST_SECRET_KEY / STRIPE_TEST_PUBLIC_KEY) ;
 * - réel : STRIPE_LIVE_SECRET_KEY / STRIPE_LIVE_PUBLIC_KEY (clés réservées à la caisse ; sinon STRIPE_SECRET_KEY si elle est réelle).
 * Comptes (stripe_config) : test = account_id / is_active / terminal_location_id ; réel = live_account_id / live_is_active /
 * live_terminal_location_id. Site interne : compte de la plateforme (options vides), comme avant.
 */
class StripeAccountResolver
{
    public const TEST = 'test';
    public const LIVE = 'live';
    public const CHANNEL_POS = 'pos';
    public const CHANNEL_SHOP = 'shop';

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly TenantConnectionManager $tenants,
    ) {
    }

    /** Mode d'un canal pour le site courant : caisse = réglage du site ; boutique = mode de la clé de la plateforme (inchangé) */
    public function mode(string $channel): string
    {
        if ($channel === self::CHANNEL_POS) {
            return $this->emProvider->getEntityManager()->getRepository(Entreprise::class)->findOneBy([])?->getPosStripeMode() ?? self::TEST;
        }

        return self::isLiveKey(self::env('STRIPE_SECRET_KEY')) ? self::LIVE : self::TEST;
    }

    /** Clé secrète d'un mode ; null si elle n'est pas configurée */
    public function secretKey(string $mode): ?string
    {
        // Une variable dédiée dont le préfixe ne correspond pas au mode est ignorée (relecture du 28/10/2026) : une clé de test dans
        // STRIPE_LIVE_SECRET_KEY ne doit jamais donner un mode « réel » qui n'encaisse rien, ni l'inverse
        $platform = self::env('STRIPE_SECRET_KEY');
        $live = self::env('STRIPE_LIVE_SECRET_KEY');
        $test = self::env('STRIPE_TEST_SECRET_KEY');
        if ($mode === self::LIVE) {
            return self::isLiveKey($live) ? $live : (self::isLiveKey($platform) ? $platform : null);
        }

        return $platform !== null && !self::isLiveKey($platform) ? $platform : ($test !== null && !self::isLiveKey($test) ? $test : null);
    }

    /** Clé publique d'un mode ; null si elle n'est pas configurée */
    public function publicKey(string $mode): ?string
    {
        $platform = self::env('STRIPE_PUBLIC_KEY');
        $livePublic = self::env('STRIPE_LIVE_PUBLIC_KEY');
        $testPublic = self::env('STRIPE_TEST_PUBLIC_KEY');
        $isLive = fn (?string $k) => $k !== null && str_starts_with($k, 'pk_live');
        if ($mode === self::LIVE) {
            return $isLive($livePublic) ? $livePublic : ($isLive($platform) ? $platform : null);
        }

        return $platform !== null && !$isLive($platform) ? $platform : ($testPublic !== null && !$isLive($testPublic) ? $testPublic : null);
    }

    public function keysConfigured(string $mode): bool
    {
        return $this->secretKey($mode) !== null;
    }

    public function isInternal(): bool
    {
        $code = $this->tenants->getCurrentTenantCode();

        return $code !== null && $this->tenants->isTenantInternal($code);
    }

    public function config(): ?StripeConfig
    {
        return $this->emProvider->getEntityManager()->getRepository(StripeConfig::class)->findOneBy([]);
    }

    /** Compte Connect d'un mode (identifiant, actif), null si aucun */
    public function accountId(string $mode): ?string
    {
        $config = $this->config();

        return $mode === self::LIVE ? $config?->getLiveAccountId() : $config?->getAccountId();
    }

    /**
     * Options Stripe d'un mode : [] pour un site interne (compte de la plateforme), ['stripe_account' => …] pour un compte connecté actif,
     * null si le site n'a pas de compte actif dans ce mode (paiement impossible). Même règle que StripeService::accountOptions en test.
     */
    public function accountOptions(string $mode): ?array
    {
        if ($this->tenants->getCurrentTenantCode() === null) {
            return null;
        }
        if ($this->isInternal()) {
            return [];
        }
        $config = $this->config();
        if ($mode === self::LIVE) {
            return $config?->getLiveAccountId() && $config->isLiveActive() ? ['stripe_account' => $config->getLiveAccountId()] : null;
        }

        return $config?->getAccountId() && $config->isActive() ? ['stripe_account' => $config->getAccountId()] : null;
    }

    public function terminalLocationId(string $mode): ?string
    {
        $config = $this->config();

        return $mode === self::LIVE ? $config?->getLiveTerminalLocationId() : $config?->getTerminalLocationId();
    }

    /** Emplacement Terminal d'un mode ; false si le site n'a pas encore de configuration Stripe */
    public function setTerminalLocationId(string $mode, string $locationId): bool
    {
        $config = $this->config();
        if ($config === null) {
            return false;
        }
        $mode === self::LIVE ? $config->setLiveTerminalLocationId($locationId) : $config->setTerminalLocationId($locationId);
        $this->emProvider->getEntityManager()->flush();

        return true;
    }

    /** Identifiant tronqué pour l'affichage et le journal (jamais l'identifiant complet hors de la base) */
    public static function mask(?string $id): ?string
    {
        return $id === null ? null : substr($id, 0, 9) . '…' . substr($id, -4);
    }

    public static function isLiveKey(?string $key): bool
    {
        return $key !== null && (str_starts_with($key, 'sk_live') || str_starts_with($key, 'rk_live'));
    }

    private static function env(string $name): ?string
    {
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
