<?php

namespace App\Gestion\Sequences;

use App\Entity\Collections;
use App\Entity\Commande;
use App\Gestion\Entity\GestionReprise;
use App\Gestion\Services\GestionCashService;
use App\Gestion\Services\GestionFixedCostService;
use App\Gestion\Services\GestionReconciliationService;
use App\Gestion\Services\MissingCostQuery;
use App\Gestion\Services\PeriodCalendar;
use App\Services\TenantEntityManagerProvider;
use Doctrine\DBAL\Connection;

/**
 * Constats du guide des séquences (lot IA-SEQ, 13/11/2026) : une méthode par étape des séquences qui reviennent (`SequenceCatalog`,
 * champ `fact`), qui dit à partir des données si l'étape est faite.
 *
 * RÈGLE DU LOT : lecture seule, et surtout aucun gel. Aucune méthode n'appelle une lecture qui écrit : ni le résultat ni le tableau de
 * bord d'une période fermée (ils la figent), ni l'écran Année, ni le budget d'une période, ni la création d'une période manquante
 * (`PeriodCalendar::current()`, `ensurePeriods()`). Seulement des requêtes de constat. Le test `SequencesApiTest` compte les lignes de
 * toutes les tables avant et après.
 *
 * Chaque méthode reçoit l'instance (`from`, `to` en jours du magasin, `periodId`…) et rend :
 * `done` (bool), `applicable` (faux = sans objet), `figures` (liste de [clé, valeur, unité]) et `params` (ajoutés à l'écran de l'étape).
 */
class SequenceFacts
{
    public const CASH_BALANCE_MAX_AGE_DAYS = 30;

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly PeriodCalendar $calendar,
        private readonly GestionCashService $cash,
        private readonly GestionFixedCostService $fixedCosts,
        private readonly GestionReconciliationService $reconciliation,
        private readonly MissingCostQuery $missingCost,
    ) {
    }

    // ---- period.prepare ------------------------------------------------------------------------------------------------------------

    /** Budgets de la période validés : au moins un poste avec un montant prévu (écrit par « Valider » de la préparation ou par le budget) */
    public function periodBudget(array $instance): array
    {
        $planned = $instance['periodId'] === null ? 0
            : (int) $this->conn()->fetchOne('SELECT COALESCE(SUM(planned_cents), 0) FROM gestion_budget_line WHERE period_id = ?', [$instance['periodId']]);

        return self::fact($planned > 0, [['planned', $planned, 'money']]);
    }

    /** Voyage d'achat dont les dates touchent la période (facultatif) */
    public function periodTrip(array $instance): array
    {
        $trips = (int) $this->conn()->fetchOne('SELECT COUNT(*) FROM gestion_trip WHERE start_date <= :to AND end_date >= :from', ['from' => $instance['from'], 'to' => $instance['to']]);

        return self::fact($trips > 0, [['trips', $trips, 'count']]);
    }

    /** Dernier solde de trésorerie saisi depuis moins de 30 jours */
    public function recentCashBalance(array $instance): array
    {
        $today = $this->calendar->today();
        $last = $this->conn()->fetchOne('SELECT MAX(balance_date) FROM gestion_cash_balance WHERE balance_date <= ?', [$today->format('Y-m-d')]);
        if ($last === null || $last === false) {
            return self::fact(false, []);
        }
        $day = substr((string) $last, 0, 10);
        $age = (int) (new \DateTimeImmutable($day))->diff($today)->format('%a');

        return self::fact($age <= self::CASH_BALANCE_MAX_AGE_DAYS, [['lastBalanceDate', $day, 'date'], ['ageDays', $age, 'days']]);
    }

    /** Frais fixes renseignés sur les jours de la période */
    public function fixedCostsEntered(array $instance): array
    {
        return $this->fixedCostsBetween($instance['from'], $instance['to']);
    }

    /** Au moins une commande fournisseur datée dans la période (hors commandes créées par la reprise d'historique) */
    public function periodPurchaseOrder(array $instance): array
    {
        $orders = (int) $this->conn()->fetchOne(
            'SELECT COUNT(*) FROM commande WHERE kind = :kind AND date >= :from AND date < :after',
            ['kind' => Commande::KIND_STANDARD, 'from' => $instance['from'], 'after' => (new \DateTimeImmutable($instance['to']))->modify('+1 day')->format('Y-m-d')]
        );

        return self::fact($orders > 0, [['orders', $orders, 'count']]);
    }

    // ---- fiscal_year.close ---------------------------------------------------------------------------------------------------------

    /** Toutes les périodes qui commencent dans l'exercice sont fermées ; sans aucune période : sans objet */
    public function yearPeriodsClosed(array $instance): array
    {
        $periods = $this->emProvider->getEntityManager()->createQuery(
            'SELECT c FROM ' . Collections::class . ' c WHERE c.kind = :kind AND (c.del = false OR c.del IS NULL) AND c.startDateCollection >= :from AND c.startDateCollection <= :to'
        )->setParameter('kind', Collections::KIND_PERIOD)->setParameter('from', $instance['from'])->setParameter('to', $instance['to'])->getResult();
        $closed = count(array_filter($periods, fn (Collections $c) => $this->calendar->isClosed($c)));

        return self::fact($periods !== [] && $closed === count($periods), [['closedPeriods', $closed, 'count'], ['periods', count($periods), 'count']], $periods !== []);
    }

    /** Aucun article vendu pendant l'exercice sans coût d'achat (règle partagée `MissingCostQuery`) */
    public function yearPurchaseCosts(array $instance): array
    {
        return $this->missingCosts($instance['from'], $instance['to']);
    }

    /** Un solde de trésorerie saisi dans chaque mois de l'exercice, et aucun écart de rapprochement resté ouvert */
    public function yearReconciliation(array $instance): array
    {
        $rows = $this->conn()->fetchAllAssociative('SELECT id, balance_date FROM gestion_cash_balance WHERE balance_date >= :from AND balance_date <= :to ORDER BY balance_date', ['from' => $instance['from'], 'to' => $instance['to']]);
        $months = [];
        $open = 0;
        foreach ($rows as $row) {
            $months[substr((string) $row['balance_date'], 0, 7)] = true;
            if (($this->reconciliation->forBalance((int) $row['id'])['status'] ?? null) === 'open') {
                ++$open;
            }
        }
        $expected = self::monthsBetween($instance['from'], $instance['to']);

        return self::fact(count($months) >= $expected && $open === 0, [['monthsWithBalance', count($months), 'count'], ['months', $expected, 'count'], ['openGaps', $open, 'count']]);
    }

    /** Frais fixes renseignés sur les jours de l'exercice */
    public function yearFixedCosts(array $instance): array
    {
        return $this->fixedCostsBetween($instance['from'], $instance['to']);
    }

    // ---- history.recovery ----------------------------------------------------------------------------------------------------------

    /**
     * Des ventes existent avant le premier achat saisi (ou une reprise est déjà appliquée) : la reprise a un objet. Sinon toute la
     * séquence est sans objet.
     */
    public function recoveryNeeded(array $instance): array
    {
        $needed = $instance['reprise'] !== null || ($instance['firstSale'] !== null && ($instance['firstPurchase'] === null || $instance['firstSale'] < $instance['firstPurchase']));

        return self::fact($needed, array_values(array_filter([
            $instance['firstSale'] !== null ? ['firstSaleDate', $instance['firstSale'], 'date'] : null,
            $instance['firstPurchase'] !== null ? ['firstPurchaseDate', $instance['firstPurchase'], 'date'] : null,
        ])), $needed);
    }

    /** Reprise d'historique appliquée */
    public function recoveryApplied(array $instance): array
    {
        $reprise = $instance['reprise'];

        return self::fact($reprise !== null, $reprise === null ? [] : [['months', (int) $reprise['months'], 'count'], ['from', $reprise['from'], 'date'], ['to', $reprise['to'], 'date']]);
    }

    /** Plus aucun article vendu sans coût sur le créneau de la reprise (appliquée : le sien ; sinon : des premières ventes à la veille de la période en cours) */
    public function recoveryPurchaseCosts(array $instance): array
    {
        if ($instance['from'] === null || $instance['to'] === null) {
            return self::fact(true, [], false);
        }

        return $this->missingCosts($instance['from'], $instance['to']);
    }

    // ---- données des instances (lectures de constat) --------------------------------------------------------------------------------

    /** Période en base pour une clé du calendrier, sans la créer */
    public function periodRow(string $periodKey): ?array
    {
        $row = $this->conn()->fetchAssociative("SELECT id, nom_collection FROM collections WHERE kind = 'period' AND period_key = ? AND COALESCE(del, false) = false ORDER BY id LIMIT 1", [$periodKey]);

        return $row === false ? null : $row;
    }

    /** @return array{months: int, from: string, to: string}|null dernière reprise d'historique appliquée */
    public function reprise(): ?array
    {
        $reprise = $this->emProvider->getEntityManager()->getRepository(GestionReprise::class)->findOneBy([], ['id' => 'DESC']);

        return $reprise === null ? null : ['months' => (int) $reprise->getMonths(), 'from' => $reprise->getStartDate()->format('Y-m-d'), 'to' => $reprise->getEndDate()->format('Y-m-d')];
    }

    /** Jour (du magasin) de la première vente, null sans vente */
    public function firstSaleDay(): ?string
    {
        $first = $this->conn()->fetchOne('SELECT MIN(order_date) FROM "order" WHERE order_type_id = 1 AND status_id NOT IN (1, 7)');

        return $first === null || $first === false ? null : (new \DateTimeImmutable((string) $first, new \DateTimeZone('UTC')))->setTimezone($this->calendar->today()->getTimezone())->format('Y-m-d');
    }

    /** Jour du premier achat saisi (commande fournisseur hors reprise), null sans achat */
    public function firstPurchaseDay(): ?string
    {
        $first = $this->conn()->fetchOne('SELECT MIN(date) FROM commande WHERE kind = ?', [Commande::KIND_STANDARD]);

        return $first === null || $first === false ? null : substr((string) $first, 0, 10);
    }

    // ---- outils --------------------------------------------------------------------------------------------------------------------

    private function missingCosts(string $from, string $to): array
    {
        $count = $this->missingCost->count($from, $to);

        return self::fact($count['missing'] === 0, [['missing', $count['missing'], 'count'], ['total', $count['total'], 'count']], true, ['filter' => 'missing-cost', 'from' => $from, 'to' => $to]);
    }

    private function fixedCostsBetween(string $from, string $to): array
    {
        $total = (int) $this->fixedCosts->between($from, $to)['total'];

        return self::fact($total > 0, [['fixedCosts', $total, 'money']]);
    }

    /** Nombre de mois civils touchés par l'intervalle de jours */
    private static function monthsBetween(string $from, string $to): int
    {
        $a = new \DateTimeImmutable($from);
        $b = new \DateTimeImmutable($to);

        return ((int) $b->format('Y') - (int) $a->format('Y')) * 12 + (int) $b->format('n') - (int) $a->format('n') + 1;
    }

    /**
     * @param list<array{0: string, 1: mixed, 2: string}> $figures
     * @param array<string, mixed> $params
     *
     * @return array{done: bool, applicable: bool, figures: list<array{0: string, 1: mixed, 2: string}>, params: array<string, mixed>}
     */
    private static function fact(bool $done, array $figures, bool $applicable = true, array $params = []): array
    {
        return ['done' => $done, 'applicable' => $applicable, 'figures' => $figures, 'params' => $params];
    }

    private function conn(): Connection
    {
        return $this->emProvider->getEntityManager()->getConnection();
    }
}
