<?php

namespace App\Services\CaisseService;

use App\Entity\Entreprise;
use App\Services\Platform\SiteReadinessService;
use App\Services\StripeService\StripeAccountResolver;
use App\Services\TenantEntityManagerProvider;

/**
 * Cheminement d'ouverture d'un magasin (page Caisse, 07/11/2026) : les étapes de la caisse du cheminement du site
 * (`SiteReadinessService`, groupe `module.pos`, liste de contrôle de docs/mise-en-production-caisse.md), le niveau atteint et un résumé
 * des réglages. Lecture seule : chaque étape mène à l'écran exact.
 */
class PosSetupService
{
    /** étape → écran exact où agir (la page du cheminement relie, le cheminement du site renvoie à la page) */
    public const STEP_SCREENS = [
        'pos.key' => 'app_keys', 'pos.register' => 'cash_registers', 'pos.cashiers' => 'users', 'pos.receipt' => 'entreprise', 'pos.terminal' => 'pos_stripe',
        'pos.alerts' => 'entreprise', 'pos.retention' => 'entreprise', 'pos.denominations' => 'denominations', 'pos.reader' => 'cash_registers', 'pos.first_sale' => null,
    ];

    public function __construct(private readonly TenantEntityManagerProvider $emProvider, private readonly SiteReadinessService $readiness, private readonly StripeAccountResolver $stripe)
    {
    }

    /** @return array{granted: bool, steps: list<array<string, mixed>>, level: string, levelLabel: string, summary: array<string, mixed>} */
    public function overview(): array
    {
        $all = $this->readiness->forCurrentSite();
        $obtain = array_values(array_filter($all['steps'], fn ($s) => $s['key'] === 'obtain.module.pos'));
        $steps = array_values(array_filter($all['steps'], fn ($s) => $s['group'] === 'module.pos' && !str_starts_with($s['key'], 'obtain.')));
        $granted = $steps !== [];
        foreach ($steps as $i => $s) {
            $steps[$i]['screen'] = self::STEP_SCREENS[$s['key']] ?? ($s['action']['screen'] ?? null);
        }
        $c = $this->emProvider->getEntityManager()->getConnection();
        $e = $this->emProvider->getEntityManager()->getRepository(Entreprise::class)->findOneBy([], ['id' => 'ASC']);
        $mode = $e?->getPosStripeMode() ?? StripeAccountResolver::TEST;
        $summary = [
            'stripeMode' => $mode, 'registers' => $this->count($c, 'SELECT COUNT(*) FROM cash_register WHERE is_active = TRUE'), 'readers' => $this->count($c, "SELECT COUNT(*) FROM cash_register WHERE is_active = TRUE AND terminal_enabled = TRUE AND COALESCE(stripe_reader_id, '') <> ''"),
            'cashiers' => $this->count($c, "SELECT COUNT(*) FROM \"user\" WHERE roles::text LIKE '%ROLE_USER_POS%' OR roles::text LIKE '%ROLE_POS_MANAGER%'"), 'counterSales' => $this->count($c, 'SELECT COUNT(*) FROM "order" WHERE order_source_id = 2'),
            'currency' => $e?->getCurrency() ?: 'CAD', 'denominations' => $this->count($c, sprintf("SELECT COUNT(*) FROM type_cash WHERE active = TRUE AND UPPER(COALESCE(currency, 'CAD')) = '%s'", strtoupper($e?->getCurrency() ?: 'CAD'))),
            'alertsEmail' => $e?->getPosAlertsEmail(), 'retentionYears' => $e?->getDataRetentionYears(),
        ];
        $blocking = count(array_filter($steps, fn ($s) => $s['status'] === 'todo' && ($s['blocking'] ?? true)));
        $level = !$granted ? 'none' : ($blocking > 0 ? 'todo' : ($mode === StripeAccountResolver::LIVE ? 'live' : 'test'));

        return ['granted' => $granted, 'obtain' => $obtain[0] ?? null, 'steps' => $steps, 'level' => $level, 'levelLabel' => ['none' => 'Caisse non accordée', 'todo' => 'À compléter avant d\'ouvrir', 'test' => 'Prête en mode test', 'live' => 'Prête, paiements réels'][$level], 'summary' => $summary, 'blockingTodo' => $blocking];
    }

    private function count(\Doctrine\DBAL\Connection $c, string $sql): int
    {
        try {
            return (int) $c->fetchOne($sql);
        } catch (\Throwable) {
            return 0;
        }
    }
}
