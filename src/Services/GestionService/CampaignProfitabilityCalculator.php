<?php

namespace App\Services\GestionService;

use App\Entity\MarketingCampaign;
use App\Repository\MarketingCampaignRepository;
use App\Services\TenantEntityManagerProvider;

/**
 * Rentabilité perçue d'une campagne (lot A1, 10/11/2026, `docs/publicite-gestion.md` § 3) : estimation avant / après, toujours marquée comme
 * telle. Les campagnes qui se chevauchent (de proche en proche) sont mesurées ensemble ; fenêtre = union de leurs dates bornée à aujourd'hui ;
 * référence = même nombre de jours juste avant, et la même fenêtre l'an dernier quand les ventes remontent jusque-là. Ventes et marge brute
 * selon les règles du résultat de période (`PeriodResultCalculator::salesMetrics`) ; gain pour 1 $ investi = hausse ÷ montant du groupe
 * (devise du site). Remarques en français simple pour le gérant.
 */
class CampaignProfitabilityCalculator
{
    public function __construct(private readonly TenantEntityManagerProvider $emProvider, private readonly PeriodResultCalculator $results, private readonly PeriodCalendar $calendar)
    {
    }

    /** @return array<string, mixed> */
    public function compute(MarketingCampaign $campaign): array
    {
        $em = $this->emProvider->getEntityManager();
        /** @var MarketingCampaignRepository $repo */
        $repo = $em->getRepository(MarketingCampaign::class);
        $group = $this->group($campaign, $repo->allByStart());
        $from = min(array_map(fn (MarketingCampaign $c) => $c->getStartDate(), $group));
        $to = max(array_map(fn (MarketingCampaign $c) => $c->getEndDate(), $group));
        $today = $this->calendar->today()->setTime(0, 0);
        $windowTo = $to > $today ? $today : $to;
        $complete = $to < $today;
        $started = $windowTo >= $from;
        $days = $started ? (int) $from->diff($windowTo)->days + 1 : 0;
        $beforeTo = $from->modify('-1 day');
        $beforeFrom = $from->modify('-' . $days . ' days');
        $productIds = $this->scope($group);
        $amount = array_sum(array_map(fn (MarketingCampaign $c) => $c->amountSite(), $group));

        $during = $started ? $this->results->salesMetrics($from, $windowTo, $productIds) : self::empty();
        $before = $started ? $this->results->salesMetrics($beforeFrom, $beforeTo, $productIds) : self::empty();
        $lastYear = null;
        $firstSale = $started ? $em->getConnection()->fetchOne('SELECT CAST(MIN(order_date) AS date) FROM "order" WHERE order_type_id = 1') : null;
        if ($firstSale !== false && $firstSale !== null && $firstSale <= $from->modify('-1 year')->format('Y-m-d')) {
            $lastYear = $this->results->salesMetrics($from->modify('-1 year'), $windowTo->modify('-1 year'), $productIds);
        }
        $uplift = ['revenue' => $during['revenue'] - $before['revenue'], 'grossMargin' => $during['grossMargin'] - $before['grossMargin']];
        $gain = $amount > 0 ? ['revenue' => round($uplift['revenue'] / $amount, 2), 'grossMargin' => round($uplift['grossMargin'] / $amount, 2)] : null;

        $caveats = [];
        if (!$started) {
            $caveats[] = 'Campagne pas encore commencée : rien à mesurer pour l\'instant.';
        } elseif (!$complete) {
            $caveats[] = 'Campagne en cours : chiffres provisoires.';
        }
        if (count($group) > 1) {
            $caveats[] = sprintf('Mesurée avec %d autre(s) campagne(s) aux dates communes : le gain est partagé.', count($group) - 1);
        }
        if ($started && $before['orders'] < 5) {
            $caveats[] = 'Peu de ventes avant la campagne : la comparaison est fragile.';
        }
        if ($during['unknownCostRevenue'] > 0) {
            $caveats[] = 'Une partie des ventes n\'a pas de coût d\'achat connu : la marge est calculée sans elles.';
        }
        if ($lastYear !== null) {
            $vsLastYear = $during['grossMargin'] - $lastYear['grossMargin'];
            $diverge = ($vsLastYear > 0) !== ($uplift['grossMargin'] > 0) || abs($vsLastYear - $uplift['grossMargin']) > max(abs($uplift['grossMargin']), abs($vsLastYear)) * 0.5;
            if ($diverge && ($vsLastYear !== 0 || $uplift['grossMargin'] !== 0)) {
                $caveats[] = 'Comparée à la même période l\'an dernier, la tendance est très différente : la saison pèse sans doute.';
            }
        }

        return [
            'campaign' => ['id' => $campaign->getId(), 'name' => $campaign->getName()], 'estimate' => true, 'method' => 'before_after',
            'group' => ['campaignIds' => array_map(fn (MarketingCampaign $c) => $c->getId(), $group), 'amount' => $amount, 'from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d'), 'shared' => count($group) > 1],
            'window' => ['from' => $from->format('Y-m-d'), 'to' => ($started ? $windowTo : $from)->format('Y-m-d'), 'days' => $days, 'complete' => $complete],
            'reference' => ['from' => $beforeFrom->format('Y-m-d'), 'to' => $beforeTo->format('Y-m-d'), 'days' => $days],
            'scope' => ['kind' => $productIds === null ? 'all' : 'targeted', 'productIds' => $productIds ?? []],
            'during' => $during, 'before' => $before, 'lastYear' => $lastYear,
            'uplift' => $uplift, 'gainPerDollar' => $gain, 'caveats' => $caveats,
        ];
    }

    /**
     * Campagnes reliées par chevauchement de dates, de proche en proche, à la campagne demandée.
     *
     * @param list<MarketingCampaign> $all
     * @return list<MarketingCampaign>
     */
    private function group(MarketingCampaign $campaign, array $all): array
    {
        $group = [$campaign->getId() => $campaign];
        $changed = true;
        while ($changed) {
            $changed = false;
            foreach ($all as $c) {
                if (isset($group[$c->getId()])) {
                    continue;
                }
                foreach ($group as $g) {
                    if ($c->getStartDate() <= $g->getEndDate() && $c->getEndDate() >= $g->getStartDate()) {
                        $group[$c->getId()] = $c;
                        $changed = true;
                        break;
                    }
                }
            }
        }
        $list = array_values($group);
        usort($list, fn ($a, $b) => [$a->getStartDate(), $a->getId()] <=> [$b->getStartDate(), $b->getId()]);

        return $list;
    }

    /**
     * Articles visés par le groupe (articles et articles des lignes d'achat des commandes visées) ; null = tout le magasin (une campagne sans cible)
     *
     * @param list<MarketingCampaign> $group
     * @return list<int>|null
     */
    private function scope(array $group): ?array
    {
        $ids = [];
        $conn = $this->emProvider->getEntityManager()->getConnection();
        foreach ($group as $c) {
            if ($c->getProducts()->isEmpty() && $c->getCommandes()->isEmpty()) {
                return null;
            }
            foreach ($c->getProducts() as $p) {
                $ids[$p->getId()] = (int) $p->getId();
            }
            foreach ($c->getCommandes() as $commande) {
                foreach ($conn->fetchFirstColumn('SELECT DISTINCT product_id FROM commande_line WHERE commande_id = ?', [$commande->getId()]) as $pid) {
                    $ids[(int) $pid] = (int) $pid;
                }
            }
        }
        sort($ids);

        return array_values($ids);
    }

    private static function empty(): array
    {
        return ['revenue' => 0, 'grossMargin' => 0, 'orders' => 0, 'items' => 0, 'unknownCostRevenue' => 0];
    }
}
