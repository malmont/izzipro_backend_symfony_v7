<?php

namespace App\Services\Platform;

use App\Services\TenantConnectionManager;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Mode de la plateforme (console de pilotage, lot 1 bis, 04/11/2026), table maître `platform_settings`, clé `mode` :
 * - `demo` (défaut, et valeur retenue si la table ou la ligne manque) : aucune restriction de module ni de droit d'usage ne
 *   bloque un service ; tout reste visible et réglable dans la console (essais du propriétaire comme avant la console) ;
 * - `live` : les garde-fous s'appliquent (ModuleAccessSubscriber, puis les droits d'usage du lot 3).
 * Réglé par le super administrateur (PUT /api/platform/settings, onglet « Plateforme »). Porte aussi `shop_url`, l'adresse de la boutique
 * de la plateforme (forfaits), vide tant qu'elle n'existe pas. Lu à chaque requête d'API gardée :
 * cache partagé 60 s, vidé à l'écriture.
 */
class PlatformModeService
{
    public const DEMO = 'demo';
    public const LIVE = 'live';
    public const MODES = [self::DEMO, self::LIVE];
    private const CACHE_KEY = 'platform.settings.mode';
    public const AI_DEFAULT_CREDITS = 100;
    public const AI_TRIAL_DAYS = 30;
    private const TTL = 60;

    public function __construct(private readonly TenantConnectionManager $tenants, private readonly CacheInterface $cache, private readonly LoggerInterface $logger)
    {
    }

    public function mode(): string
    {
        try {
            return $this->cache->get(self::CACHE_KEY, function (ItemInterface $item): string {
                $item->expiresAfter(self::TTL);

                return $this->read();
            });
        } catch (\Throwable $e) {
            $this->logger->warning('PlatformModeService : mode illisible, demo retenu : ' . $e->getMessage());

            return self::DEMO;
        }
    }

    public function isDemo(): bool
    {
        return $this->mode() === self::DEMO;
    }

    /** @return array{mode: string, updatedAt: ?string, updatedBy: ?string, shopUrl: ?string} */
    public function settings(): array
    {
        try {
            $rows = [];
            foreach ($this->tenants->getPdoMaster()->query("SELECT key, value, updated_at, updated_by FROM platform_settings WHERE key IN ('mode', 'shop_url', 'shop_tenant', 'ai_default_credits', 'ai_trial_days')")->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $r) {
                $rows[$r['key']] = $r;
            }
        } catch (\Throwable) {
            $rows = [];
        }
        $mode = $rows['mode'] ?? null;
        $shop = $rows['shop_url']['value'] ?? null;
        $shopTenant = $rows['shop_tenant']['value'] ?? null;
        $aiCredits = $rows['ai_default_credits']['value'] ?? null; // IA bornée (08/11/2026) : crédits mensuels d'un site sans réglage propre, jours d'essai « Aide IA » à la création
        $aiTrial = $rows['ai_trial_days']['value'] ?? null;

        return ['mode' => $mode && in_array($mode['value'], self::MODES, true) ? $mode['value'] : self::DEMO, 'updatedAt' => $mode['updated_at'] ?? null, 'updatedBy' => $mode['updated_by'] ?? null, 'shopUrl' => is_string($shop) && $shop !== '' ? $shop : null, 'shopTenant' => is_string($shopTenant) && $shopTenant !== '' ? $shopTenant : null,
            'aiDefaultCredits' => is_numeric($aiCredits) ? (int) $aiCredits : self::AI_DEFAULT_CREDITS, 'aiTrialDays' => is_numeric($aiTrial) ? (int) $aiTrial : self::AI_TRIAL_DAYS];
    }

    /** Adresse de la boutique de la plateforme (forfaits), null tant qu'elle n'existe pas : lien « Choisir un forfait » de Mon site et des courriels de demande */
    public function shopUrl(): ?string
    {
        return $this->settings()['shopUrl'];
    }

    /** Code du **seul** site dont les formules branchées produisent des droits d'usage (la boutique de forfaits) ; null tant qu'il n'est pas désigné */
    public function shopTenant(): ?string
    {
        return $this->settings()['shopTenant'];
    }

    /** Crédits IA mensuels d'un site sans réglage propre (page Plateforme) */
    public function aiDefaultCredits(): int
    {
        return $this->settings()['aiDefaultCredits'];
    }

    /** Durée de l'essai « Aide IA » posé à la création d'un site (0 = aucun) */
    public function aiTrialDays(): int
    {
        return $this->settings()['aiTrialDays'];
    }

    /** @throws \InvalidArgumentException */
    public function setAiDefaults(?int $credits, ?int $trialDays, ?string $by): void
    {
        if ($credits !== null && ($credits < 0 || $credits > 100000)) {
            throw new \InvalidArgumentException('aiDefaultCredits : entre 0 et 100 000');
        }
        if ($trialDays !== null && ($trialDays < 0 || $trialDays > 365)) {
            throw new \InvalidArgumentException('aiTrialDays : entre 0 (aucun essai) et 365');
        }
        $pdo = $this->tenants->getPdoMaster();
        foreach (['ai_default_credits' => $credits, 'ai_trial_days' => $trialDays] as $key => $value) {
            if ($value === null) {
                continue;
            }
            $pdo->prepare('INSERT INTO platform_settings (key, value, updated_at, updated_by) VALUES (?, ?, NOW(), ?) ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value, updated_at = NOW(), updated_by = EXCLUDED.updated_by')->execute([$key, (string) $value, $by]);
        }
        $this->logger->notice(sprintf('IA par défaut : %s crédits / mois, essai %s jours (par %s)', $credits ?? '=', $trialDays ?? '=', $by ?? 'inconnu'));
    }

    public function setShopTenant(?string $code, ?string $by): void
    {
        $code = $code !== null ? trim($code) : '';
        if ($code !== '' && !is_array($this->tenants->findTenantByCode($code))) {
            throw new \InvalidArgumentException('shopTenant : code de site existant attendu, ou vide');
        }
        $pdo = $this->tenants->getPdoMaster();
        if ($code === '') {
            $pdo->exec("DELETE FROM platform_settings WHERE key = 'shop_tenant'");
        } else {
            $pdo->prepare("INSERT INTO platform_settings (key, value, updated_at, updated_by) VALUES ('shop_tenant', ?, NOW(), ?) ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value, updated_at = NOW(), updated_by = EXCLUDED.updated_by")->execute([$code, $by]);
        }
        $this->logger->notice(sprintf('Site de la boutique de la plateforme : %s (par %s)', $code === '' ? 'aucun' : $code, $by ?? 'inconnu'));
    }

    public function setShopUrl(?string $url, ?string $by): void
    {
        $url = $url !== null ? trim($url) : '';
        if ($url !== '' && (!filter_var($url, FILTER_VALIDATE_URL) || !str_starts_with($url, 'https://'))) {
            throw new \InvalidArgumentException('shopUrl : adresse https attendue, ou vide');
        }
        $pdo = $this->tenants->getPdoMaster();
        if ($url === '') {
            $pdo->exec("DELETE FROM platform_settings WHERE key = 'shop_url'");
        } else {
            $pdo->prepare("INSERT INTO platform_settings (key, value, updated_at, updated_by) VALUES ('shop_url', ?, NOW(), ?) ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value, updated_at = NOW(), updated_by = EXCLUDED.updated_by")->execute([mb_substr($url, 0, 500), $by]);
        }
        $this->logger->notice(sprintf('Boutique de la plateforme : %s (par %s)', $url === '' ? 'aucune' : $url, $by ?? 'inconnu'));
    }

    public function setMode(string $mode, ?string $by): void
    {
        if (!in_array($mode, self::MODES, true)) {
            throw new \InvalidArgumentException('mode : demo ou live attendu');
        }
        $stmt = $this->tenants->getPdoMaster()->prepare("INSERT INTO platform_settings (key, value, updated_at, updated_by) VALUES ('mode', ?, NOW(), ?) ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value, updated_at = NOW(), updated_by = EXCLUDED.updated_by");
        $stmt->execute([$mode, $by]);
        $this->cache->delete(self::CACHE_KEY);
        $this->logger->notice(sprintf('Mode de la plateforme : %s (par %s)', $mode, $by ?? 'inconnu'));
    }

    private function read(): string
    {
        $value = $this->tenants->getPdoMaster()->query("SELECT value FROM platform_settings WHERE key = 'mode'")->fetchColumn();

        return is_string($value) && in_array($value, self::MODES, true) ? $value : self::DEMO;
    }
}
