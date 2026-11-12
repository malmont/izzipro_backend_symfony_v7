<?php

namespace App\Gestion\Services;

use App\Services\BoutiqueSettingsService\TenantCurrencyProvider;
use App\Services\CaisseService\StoreClock;
use App\Services\FeatureAccess\ModuleAccessService;
use App\Services\OrderService\OrderDeskService;
use App\Services\TenantEntityManagerProvider;

/**
 * « Ma journée » (lot JOUR, contrat validé le 13/11/2026, `docs/journee-gestion.md`) : l'état du jour pour l'accueil de l'application de
 * gestion et du web. Rien n'est recalculé autrement qu'ailleurs : ventes aux règles des statistiques unifiées (S1), « à expédier » comme
 * l'écran « À traiter », trésorerie comme l'alerte, stock bas comme la vignette. Lecture seule.
 */
class GestionDayService
{
    public const CHANNELS = ['ecommerce' => 1, 'pos' => 2, 'mobile_app' => 3];
    public const COMPARISON_WEEKS = 4;
    public const SCREENS = ['sales' => 'statistiques', 'toShip' => 'commandes.a_expedier', 'support' => 'assistance', 'cash' => 'tresorerie', 'stock' => 'stock.low'];

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly PeriodResultCalculator $results,
        private readonly StoreClock $clock,
        private readonly GestionCashService $cash,
        private readonly HomeTilesService $tiles,
        private readonly OrderDeskService $desk,
        private readonly ModuleAccessService $modules,
        private readonly ?TenantCurrencyProvider $currency = null,
    ) {
    }

    public function overview(?\DateTimeImmutable $now = null): array
    {
        $tz = $this->clock->timezone();
        $now = ($now ?? new \DateTimeImmutable())->setTimezone($tz);
        $day = $now->format('Y-m-d');
        $today = $now->setTime(0, 0);

        // Ventes du jour, aux règles de S1 (GET /api/gestion/statistiques?range=today) : tous canaux, puis par canal
        $all = GestionStatisticsService::metrics($this->results->dailySales($day, $day));
        $byChannel = [];
        foreach (self::CHANNELS as $channel => $source) {
            $m = GestionStatisticsService::metrics($this->results->dailySales($day, $day, $source));
            $byChannel[] = ['channel' => $channel, 'total' => $m['revenue'], 'orders' => $m['orders']];
        }
        // Moyenne du même jour de semaine sur les 4 semaines précédentes, chaque jour arrêté à la même heure du magasin
        $sum = 0;
        for ($k = 1; $k <= self::COMPARISON_WEEKS; ++$k) {
            $start = $today->modify('-' . (7 * $k) . ' days');
            $end = $start->setTime((int) $now->format('H'), (int) $now->format('i'), (int) $now->format('s'));
            $utc = new \DateTimeZone('UTC');
            $sum += GestionStatisticsService::metrics($this->results->salesBetween($start->setTimezone($utc), $end->setTimezone($utc)))['revenue'];
        }
        $average = (int) round($sum / self::COMPARISON_WEEKS);

        $alert = $this->cash->alert($today);
        $receipts = $this->cash->receiptsSplit($day, $day);

        return [
            'date' => $day, 'timezone' => $tz->getName(), 'currency' => $this->currency?->code() ?? TenantCurrencyProvider::DEFAULT, 'generatedAt' => $now->format(\DateTimeInterface::ATOM),
            'sales' => ['total' => $all['revenue'], 'orders' => $all['orders'], 'byChannel' => $byChannel,
                'vsSameWeekdayAverage' => ['percent' => $average !== 0 ? round(($all['revenue'] - $average) / abs($average) * 100, 1) : null, 'average' => $average,
                    'weeks' => self::COMPARISON_WEEKS, 'asOf' => $now->format('H:i')]],
            // Encaissé du jour, taxes comprises (ce qui entre en caisse) : à ne pas confondre avec sales.total, hors taxes
            'receipts' => ['total' => $receipts['total'], 'byMethod' => [['method' => 'cash', 'total' => $receipts['cash']], ['method' => 'card', 'total' => $receipts['card']]]],
            'toShip' => $this->desk->toShipSummary(),
            'support' => $this->support($now),
            'cash' => $alert === null ? null : ['balance' => (int) $alert['balance']['amount'], 'balanceDate' => $alert['balance']['date'],
                'lowestIn14Days' => ['amount' => (int) $alert['low14']['amount'], 'date' => $alert['low14']['date']], 'state' => $alert['status']],
            'stock' => ['lowCount' => count($this->tiles->lowStock()['all'])],
            'screens' => self::SCREENS,
        ];
    }

    /** Demandes d'assistance ouvertes et en retard ; null si le module Assistance n'est pas accordé au site */
    private function support(\DateTimeImmutable $now): ?array
    {
        if (!$this->modules->isEnabled(ModuleAccessService::SUPPORT)) {
            return null;
        }
        $row = $this->emProvider->getEntityManager()->getConnection()->fetchAssociative(
            "SELECT COUNT(*) AS open, COUNT(*) FILTER (WHERE due_at IS NOT NULL AND due_at < :now) AS overdue FROM support_ticket WHERE status <> 'closed'",
            ['now' => $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s')]
        );

        return ['open' => (int) ($row['open'] ?? 0), 'overdue' => (int) ($row['overdue'] ?? 0)];
    }
}
