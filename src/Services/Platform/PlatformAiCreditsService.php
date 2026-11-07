<?php

namespace App\Services\Platform;

use App\Entity\AiCreditSetting;
use App\Services\LandingAiService\LandingAiQuotaService;
use App\Services\TenantConnectionManager;
use Psr\Log\LoggerInterface;

/**
 * Crédits IA par site, côté plateforme (07/11/2026) : seul le propriétaire de la plateforme fixe les crédits mensuels d'un site ; chaque
 * appel à l'IA d'un site (assistant, pages, fiches produit, poids, lots) réserve puis consomme ces crédits (`LandingAiQuotaService`). Cette
 * classe lit et règle les crédits de tous les sites depuis la page Plateforme, sans passer par le site.
 */
class PlatformAiCreditsService
{
    public function __construct(private readonly TenantConnectionManager $tenants, private readonly TenantDatabaseConnector $connector, private readonly LoggerInterface $logger)
    {
    }

    /** @return list<array{code: string, name: string, monthly: int, used: int, remaining: int, hasSetting: bool}> */
    public function overview(): array
    {
        $monthStart = (new \DateTimeImmutable('first day of this month 00:00:00', new \DateTimeZone(LandingAiQuotaService::TIMEZONE)))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $rows = [];
        foreach ($this->tenants->getAllTenants() as $tenant) {
            $code = (string) $tenant['code'];
            if ($code === 'gmasuite') {
                continue;
            }
            try {
                $pdo = $this->connector->pdo((string) $tenant['dbname']);
                $monthly = $pdo->query('SELECT monthly_credits FROM ai_credit_setting ORDER BY id LIMIT 1')->fetchColumn();
                $stmt = $pdo->prepare("SELECT COALESCE(SUM(credits), 0) FROM ai_usage WHERE created_at >= ? AND (status = 'success' OR (status = 'reserved' AND reserved_until >= NOW()))");
                $stmt->execute([$monthStart]);
                $used = (int) $stmt->fetchColumn();
                $m = $monthly === false || $monthly === null ? AiCreditSetting::DEFAULT_MONTHLY_CREDITS : (int) $monthly;
                $rows[] = ['code' => $code, 'name' => (string) $tenant['name'], 'monthly' => $m, 'used' => $used, 'remaining' => max(0, $m - $used), 'hasSetting' => $monthly !== false && $monthly !== null];
            } catch (\Throwable $e) {
                $this->logger->warning(sprintf('[Crédits IA] %s illisible : %s', $code, $e->getMessage()));
                $rows[] = ['code' => $code, 'name' => (string) $tenant['name'], 'monthly' => 0, 'used' => 0, 'remaining' => 0, 'hasSetting' => false];
            }
        }

        return $rows;
    }

    /** Règle les crédits mensuels d'un site (0 = assistant désactivé) ; la ligne est créée si le site n'en a pas */
    public function set(string $code, int $monthly, ?string $by): void
    {
        $tenant = $this->connector->tenant($code);
        if ($tenant === null) {
            throw new \InvalidArgumentException('Site inconnu.');
        }
        if ($monthly < 0 || $monthly > 100000) {
            throw new \InvalidArgumentException('Crédits mensuels : entre 0 et 100 000.');
        }
        $pdo = $this->connector->pdo((string) $tenant['dbname']);
        $id = $pdo->query('SELECT id FROM ai_credit_setting ORDER BY id LIMIT 1')->fetchColumn();
        if ($id === false || $id === null) {
            $pdo->prepare('INSERT INTO ai_credit_setting (monthly_credits) VALUES (?)')->execute([$monthly]);
        } else {
            $pdo->prepare('UPDATE ai_credit_setting SET monthly_credits = ? WHERE id = ?')->execute([$monthly, (int) $id]);
        }
        $this->connector->invalidateSiteCache($code, (string) $tenant['dbname']);
        $this->logger->notice(sprintf('[Crédits IA] %s : %d crédit(s) par mois (par %s)', $code, $monthly, $by ?? 'inconnu'));
    }
}
