<?php

namespace App\Services\Group\AllShops;

use App\Entity\Collections;
use App\Gestion\Services\PeriodResultCalculator;
use App\Gestion\UseCase\GestionCashUseCase;
use App\Gestion\UseCase\GestionDayUseCase;
use App\Gestion\UseCase\GestionStatisticsUseCase;
use App\Gestion\UseCase\GestionYearUseCase;
use App\LossPrevention\UseCase\LossPreventionUseCase;
use App\UseCase\CommandeUseCase\CommandeLineUseCase;

/**
 * Lot IA-G (19/11/2026) : les lectures de « Toutes les boutiques » (G-2, G-3), en un seul endroit, pour les écrans (contrôleurs) et pour
 * l'assistant du directeur (`SiteAssistantService`) : l'écran et l'IA lisent exactement la même chose. Chaque méthode reçoit le groupe relu
 * par la garde (`GroupScopeSubscriber`) ou par `GroupDirector`, lit chaque boutique (`AllShopsReader` : READ ONLY, échéance, cache) et rend
 * la réponse réunie par devise. Les retraits de données personnelles y sont faits une fois : noms des notes de frais du résultat, salaires
 * regroupés, notes des soldes (ce dernier dans `AllShopsListMerger`).
 */
class GroupViews
{
    public function __construct(
        private readonly AllShopsReader $reader,
        private readonly GestionStatisticsUseCase $statistics,
        private readonly GestionDayUseCase $day,
        private readonly LossPreventionUseCase $losses,
        private readonly CommandeLineUseCase $lines,
        private readonly GestionCashUseCase $cash,
        private readonly GestionYearUseCase $years,
        private readonly PeriodResultCalculator $calculator,
    ) {
    }

    /** @param array<string, mixed> $query seuls `range`, `from`, `to`, `source` comptent (`GroupScope::STATISTICS_PARAMS`) */
    public function statistics(array $group, array $query, string $locale): array
    {
        $query = array_intersect_key($query, array_flip(GroupScope::STATISTICS_PARAMS));
        ksort($query);

        return $this->read($group, 'gestion_statistiques', ['query' => $query, 'locale' => $locale], fn (): array => $this->statistics->overviewWithSums($query, $locale), AllShopsMerger::statistics(...));
    }

    public function day(array $group): array
    {
        return $this->read($group, 'gestion_journee', [], fn (): array => ['data' => $this->day->overview()], AllShopsMerger::day(...));
    }

    /** Jamais d'analyse lancée ; par poste et par jour, jamais par personne */
    public function losses(array $group, array $query, string $locale): array
    {
        $query = ['analyse' => '0'] + $query;
        $query['analyse'] = '0';

        return $this->read($group, 'gestion_pertes', [], fn (): array => ['data' => AllShopsMerger::lossPart($this->losses->summary($query, $locale))], AllShopsMerger::losses(...));
    }

    /** Les `page × limite` premières lignes de chaque boutique, réunies, retriées, puis la page (500 lignes au plus par boutique : la garde) */
    public function toReceive(array $group, int $page, int $limit): array
    {
        $response = $this->read($group, 'commande_lines_to_receive', ['page' => $page, 'limit' => $limit], function () use ($page, $limit): array {
            [$rows, $total] = $this->lines->toReceive(1, $page * $limit);

            return ['data' => ['rows' => $rows, 'total' => $total]];
        }, static fn (array $shops): array => []);
        $merged = AllShopsListMerger::toReceive($response['shops'], $page, $limit);
        $response['shops'] = array_map(static fn (array $s): array => isset($s['data']) ? array_diff_key($s, ['data' => 1]) + ['total' => $s['data']['total']] : $s, $response['shops']);

        return $response + ['page' => $page, 'limit' => $limit] + $merged;
    }

    public function balances(array $group, int $limit): array
    {
        $response = $this->read($group, 'gestion_tresorerie_soldes', ['limit' => $limit], fn (): array => ['data' => $this->cash->balances($limit)],
            static fn (array $shops): array => AllShopsListMerger::balances($shops, $limit));
        $response['shops'] = self::withoutData($response['shops']); // les soldes sont dans `totals`

        return $response;
    }

    /** Salaires TOUJOURS regroupés (aucun nom), quel que soit le rôle */
    public function movements(array $group, ?string $category, string $locale): array
    {
        $response = $this->read($group, 'gestion_tresorerie_mouvements', ['category' => $category, 'locale' => $locale],
            fn (): array => ['data' => $this->cash->movements(is_string($category) ? strtolower(trim($category)) : null, $locale, false)], AllShopsListMerger::movements(...));
        $response['shops'] = self::withoutData($response['shops']); // les mouvements sont dans `totals`

        return $response;
    }

    /** Chaque boutique à son exercice EN COURS (`year` est refusé par la garde : une année n'existe pas dans tous les exercices) */
    public function year(array $group, string $locale): array
    {
        return $this->read($group, 'gestion_statistiques_annee', ['query' => [], 'locale' => $locale], fn (): array => ['data' => $this->years->overview([], $locale)], AllShopsListMerger::year(...));
    }

    /** Le résultat sur des dates (Y-m-d, validées par l'appelant), période NON enregistrée, dans la transaction READ ONLY */
    public function result(array $group, string $from, string $to): array
    {
        return $this->read($group, 'gestion_groupe_resultat', ['from' => $from, 'to' => $to], function () use ($from, $to): array {
            $period = (new Collections())->setNomCollection($from . ' – ' . $to)->setStartDateCollection(new \DateTime($from))->setEndDateCollection(new \DateTime($to));
            $data = $this->calculator->compute($period, null);
            // les remarques des frais fixes citent des notes de frais par leur nom (« Salaire … »), dans `notes` ET dans le texte : jamais au
            // directeur ; il reçoit le code et le nombre de notes, sans texte
            foreach ($data['fixedCosts']['caveats'] ?? [] as $i => $caveat) {
                if (array_key_exists('notes', $caveat)) {
                    $data['fixedCosts']['caveats'][$i] = ['code' => $caveat['code'] ?? null, 'text' => null, 'count' => count((array) $caveat['notes'])];
                }
            }

            return ['data' => $data];
        }, AllShopsListMerger::result(...));
    }

    /**
     * @param callable(): array<string, mixed> $one
     * @param callable(list<array<string, mixed>>): list<array<string, mixed>> $merge
     * @return array<string, mixed>
     */
    private function read(array $group, string $route, array $keyParts, callable $one, callable $merge): array
    {
        return AllShopsMerger::response($group, $this->reader->read($group, $route, GroupScope::ROUTES[$route]['path'], $keyParts, $one), $merge);
    }

    /** @param list<array<string, mixed>> $shops */
    private static function withoutData(array $shops): array
    {
        return array_map(static fn (array $s): array => array_diff_key($s, ['data' => 1]), $shops);
    }
}
