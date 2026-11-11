<?php

namespace App\Gestion\Services;

use App\Entity\Collections;
use App\Gestion\Entity\GestionBudgetLine;
use App\Services\BoutiqueSettingsService\TenantCurrencyProvider;
use App\Services\TenantEntityManagerProvider;

/**
 * Préparer une période entière (lot P6, 11/11/2026, `docs/preparation-periode.md`) : estimation des ventes (an dernier, moyenne des 4 dernières
 * périodes, rythme des 30 jours), plan simulé sans écriture (objectif plafonné au stock disponible, marge au prorata stock existant / achats prévus,
 * voyage simulé, frais par poste, trésorerie au fil des jours depuis aujourd'hui), prévu par période (un achat ou un voyage daté avant la période
 * visée compte dans la période de sa date), « Valider » : budgets et voyage en une transaction. Les candidats de l'avis du guide sont calculés ici.
 */
class GestionPreparationService
{
    public const AVERAGE_PERIODS = 4;
    public const PACE_DAYS = 30;
    public const TREND_GAP = 0.25;
    public const DEFAULT_COEFFICIENT = 2.0;
    public const MAX_PURCHASES = 50;
    public const ENOUGH_STOCK_MARGIN = 0.2;
    public const TAX_WINDOW_DAYS = 90;
    public const METHODS = ['lastYear', 'average', 'pace', 'custom'];

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly PeriodCalendar $calendar,
        private readonly PeriodResultCalculator $results,
        private readonly GestionBudgetService $budgets,
        private readonly GestionTripService $trips,
        private readonly GestionCashService $cash,
        private readonly GestionSimulationService $simulation,
        private readonly ?TenantCurrencyProvider $currency = null,
        private readonly ?\Symfony\Contracts\Translation\TranslatorInterface $translator = null,
        private readonly ?GestionFixedCostService $fixedCosts = null, // lot FF2
    ) {
    }

    /** Période visée : `periodId`, ou la période qui contient `date` (créée au besoin) ; 404 / 409 PERIOD_CLOSED */
    public function period(mixed $periodId, mixed $date): Collections
    {
        if ($periodId !== null && $periodId !== '') {
            if (!(is_int($periodId) || (is_string($periodId) && ctype_digit($periodId)))) {
                throw new GestionValidationException(['periodId' => 'INVALID_ID']);
            }
            $period = $this->emProvider->getEntityManager()->find(Collections::class, (int) $periodId);
            if ($period === null || !$period->isPeriod()) {
                throw new GestionConflictException('PERIOD_NOT_FOUND', 'Période introuvable.', 404, ['periodId' => (int) $periodId]);
            }
        } else {
            $day = is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : null;
            if ($day === null) {
                throw new GestionValidationException(['periodId' => 'REQUIRED']);
            }
            $period = $this->calendar->periodFor(new \DateTimeImmutable($day));
            if ($period === null) {
                throw new GestionConflictException('PERIOD_NOT_FOUND', 'Aucune période pour cette date.', 404, ['date' => $day]);
            }
        }
        if ($this->calendar->isClosed($period)) {
            throw new GestionConflictException('PERIOD_CLOSED', 'Cette période est fermée : il n\'y a plus rien à préparer.', 409, ['periodId' => $period->getId()]);
        }

        return $period;
    }

    /** @return array<string, mixed> estimations des ventes de la période et méthode conseillée */
    public function estimations(Collections $period, ?\DateTimeImmutable $today = null): array
    {
        $today ??= $this->calendar->today();
        $start = \DateTimeImmutable::createFromInterface($period->getStartDateCollection());
        $end = \DateTimeImmutable::createFromInterface($period->getEndDateCollection());
        $days = AdviceRules::days($start->format('Y-m-d'), $end->format('Y-m-d'));
        $ly = $this->results->salesMetrics($start->modify('-1 year'), $end->modify('-1 year'));
        $lastYear = $ly['orders'] === 0 && $ly['revenue'] === 0 ? null : ['revenue' => $ly['revenue'], 'grossMargin' => $ly['grossMargin'], 'units' => $ly['items'],
            'from' => $start->modify('-1 year')->format('Y-m-d'), 'to' => $end->modify('-1 year')->format('Y-m-d')];
        $previous = $this->emProvider->getEntityManager()->getConnection()->fetchAllAssociative(
            "SELECT id, nom_collection, start_date_collection, end_date_collection FROM collections WHERE kind = 'period' AND end_date_collection < ? ORDER BY end_date_collection DESC LIMIT " . self::AVERAGE_PERIODS,
            [$today->format('Y-m-d')]
        );
        $average = null;
        if ($previous !== []) {
            $list = [];
            $sumRevenue = 0;
            $sumDays = 0;
            foreach ($previous as $p) {
                $f = substr((string) $p['start_date_collection'], 0, 10);
                $t = substr((string) $p['end_date_collection'], 0, 10);
                $r = $this->results->salesMetrics(new \DateTimeImmutable($f), new \DateTimeImmutable($t))['revenue'];
                $d = AdviceRules::days($f, $t);
                $list[] = ['id' => (int) $p['id'], 'name' => (string) $p['nom_collection'], 'revenue' => $r, 'days' => $d];
                $sumRevenue += $r;
                $sumDays += $d;
            }
            $average = ['revenue' => $sumDays > 0 ? (int) round($sumRevenue / $sumDays * $days) : 0, 'periods' => $list];
        }
        $paceRevenue = $this->results->salesMetrics($today->modify('-' . self::PACE_DAYS . ' days'), $today->modify('-1 day'))['revenue'];
        $pace = ['revenue' => (int) round($paceRevenue / self::PACE_DAYS * $days), 'last30Days' => $paceRevenue];
        $trendGap = null;
        if ($lastYear !== null) {
            $lyPace = $this->results->salesMetrics($today->modify('-' . self::PACE_DAYS . ' days')->modify('-1 year'), $today->modify('-1 day')->modify('-1 year'))['revenue'];
            $trendGap = $lyPace > 0 ? round($paceRevenue / $lyPace - 1, 4) : null;
        }
        if ($lastYear !== null) {
            $method = 'lastYear';
            $reason = 'La même période l\'an dernier porte la saison.';
            if ($trendGap !== null && abs($trendGap) > self::TREND_GAP) {
                $reason .= sprintf(' Attention : les 30 derniers jours sont %s de %d %% par rapport aux mêmes jours l\'an dernier.', $trendGap > 0 ? 'au-dessus' : 'en dessous', (int) round(abs($trendGap) * 100));
            }
        } elseif ($average !== null && count($average['periods']) >= 2) {
            [$method, $reason] = ['average', 'Pas de même période l\'an dernier : moyenne des dernières périodes, ramenée au nombre de jours.'];
        } else {
            [$method, $reason] = ['pace', 'Pas assez d\'historique : rythme des 30 derniers jours, ramené au nombre de jours.'];
        }

        return ['period' => self::periodRow($period), 'days' => $days, 'lastYear' => $lastYear, 'average' => $average, 'pace' => $pace, 'trendGap' => $trendGap,
            'recommended' => ['method' => $method, 'reason' => $reason]];
    }

    /**
     * Plan simulé, sans écriture (hors création d'une période manquante, comme le budget).
     *
     * @return array<string, mixed>
     */
    public function simulate(array $body, ?\DateTimeImmutable $today = null): array
    {
        $today ??= $this->calendar->today();
        $period = $this->period($body['periodId'] ?? null, $body['date'] ?? null);
        $in = $this->input($body, $period, $today);
        $est = $this->estimations($period, $today);
        $target = match ($in['method']) {
            'custom' => $in['amount'],
            'lastYear' => (int) ($est['lastYear']['revenue'] ?? 0),
            'average' => (int) ($est['average']['revenue'] ?? 0),
            default => (int) $est['pace']['revenue'],
        };
        $caveats = [];
        if ($in['method'] === 'lastYear' && $est['lastYear'] === null) {
            $caveats[] = 'NO_LAST_YEAR';
        }

        // Stock disponible : stock actuel (prix affiché, coût courant L0) + achats prévus × coefficient
        $stock = ['sale' => 0.0, 'cost' => 0.0, 'knownSale' => 0.0];
        foreach ($this->simulation->stockByVariant($today->format('Y-m-d')) as $s) {
            $stock['sale'] += $s['stock'] * $s['price'];
            if ($s['cost'] !== null) {
                $stock['cost'] += $s['stock'] * $s['cost'];
                $stock['knownSale'] += $s['stock'] * $s['price'];
            }
        }
        $coefDefault = $this->referenceCoefficient($today);
        $purchaseCost = 0;
        $purchaseSale = 0.0;
        foreach ($in['purchases'] as &$p) {
            $p['coefficient'] ??= $coefDefault;
            $purchaseCost += $p['amount'];
            $purchaseSale += $p['amount'] * $p['coefficient'];
        }
        unset($p);
        $available = $stock['sale'] + $purchaseSale;
        $sales = $target;
        if ($target > $available) {
            $sales = (int) floor($available);
            $caveats[] = 'TARGET_EXCEEDS_STOCK';
        }
        // Marge au prorata du disponible : stock existant à son taux de marque au coût connu, achats prévus à 1 − 1/coefficient
        $fromStock = $available > 0 ? $sales * $stock['sale'] / $available : 0.0;
        $fromPurchases = $sales - $fromStock;
        $unknownSales = $stock['sale'] > 0 ? $fromStock * (1 - $stock['knownSale'] / $stock['sale']) : 0.0;
        $stockRate = $stock['knownSale'] > 0 ? ($stock['knownSale'] - $stock['cost']) / $stock['knownSale'] : 0.0;
        $purchaseRate = $purchaseSale > 0 ? 1 - $purchaseCost / $purchaseSale : 0.0;
        $gross = (int) round(($fromStock - $unknownSales) * $stockRate + $fromPurchases * $purchaseRate);
        if ($unknownSales > 0.5) {
            $caveats[] = 'UNKNOWN_COST';
        }
        $effective = (int) round($sales - $unknownSales);

        // Prévu par période : chaque achat et le voyage dans la période de leur date ; postes de frais dans la période visée
        $trip = $in['trip'];
        $tripBudget = $trip === null ? 0 : $this->tripBudgetSite($trip);
        $tripPeriod = $trip === null ? null : $this->periodOf($trip['startDate']);
        $byPeriod = [];
        $add = function (Collections $p, string $category, int $amount) use (&$byPeriod, $period): void {
            $id = (int) $p->getId();
            $byPeriod[$id] ??= ['period' => self::periodRow($p), 'target' => $id === (int) $period->getId(), 'purchases' => 0, 'trip' => 0, 'shipping' => 0, 'advertising' => 0, 'other' => 0];
            $byPeriod[$id][$category] += $amount;
        };
        $add($period, 'purchases', 0);
        foreach ($in['purchases'] as $p) {
            $add($this->periodOf($p['date']), 'purchases', $p['amount']);
        }
        if ($trip !== null) {
            $add($tripPeriod, 'trip', $tripBudget);
        }
        foreach (['shipping', 'advertising', 'other'] as $c) {
            $add($period, $c, $in['planned'][$c]);
        }
        $tripInOther = $trip !== null && (int) $tripPeriod->getId() !== (int) $period->getId();
        if ($tripInOther) {
            $caveats[] = 'TRIP_IN_OTHER_PERIOD';
        }

        // Frais de la période visée : prévu, au moins le réel déjà engagé (comme le simulateur)
        $budget = $this->budgets->get($period, 'fr');
        $actual = array_column($budget['lines'], 'actual', 'category');
        $expenses = [];
        foreach (['trip', 'shipping', 'advertising', 'other'] as $c) {
            $expenses[] = ['category' => $c, 'amount' => max($byPeriod[(int) $period->getId()][$c], (int) ($actual[$c] ?? 0))];
        }
        $expensesTotal = array_sum(array_column($expenses, 'amount'));
        $breakEven = $gross > 0 ? (int) ceil($expensesTotal * $effective / $gross) : null;
        // lot FF2 : frais fixes de la période visée au prorata (FF1), en variante « après frais fixes » ; le résultat principal ne change pas
        $fixed = $this->fixedCosts?->between($period->getStartDateCollection()->format('Y-m-d'), $period->getEndDateCollection()->format('Y-m-d'))['total'] ?? 0;
        $breakEvenFixed = $gross > 0 ? (int) ceil(($expensesTotal + $fixed) * $effective / $gross) : null;
        if ($fixed > 0) {
            $caveats[] = 'FIXED_COSTS_INCLUDED';
        }

        // Trésorerie, d'aujourd'hui à la fin de la période visée
        [$cash, $taxCaveat] = $this->cashPath($period, $today, $in, $sales, $byPeriod[(int) $period->getId()], $actual, $tripBudget);
        $caveats[] = 'SALES_SPREAD_EVENLY';
        $caveats[] = $taxCaveat;
        if ($cash === null) {
            $caveats[] = 'NO_CASH_BALANCE';
        }

        $soldCost = ($fromStock - $unknownSales) * (1 - $stockRate) + ($purchaseSale > 0 ? $fromPurchases * $purchaseCost / $purchaseSale : 0.0);
        $lyPeriod = $this->calendar->findByDate(\DateTimeImmutable::createFromInterface($period->getStartDateCollection())->modify('-1 year'));
        $lastYear = $est['lastYear'] === null ? null : ['revenue' => $est['lastYear']['revenue'], 'grossMargin' => $est['lastYear']['grossMargin'],
            'result' => $lyPeriod !== null && $lyPeriod->getId() !== $period->getId() ? (int) $this->results->compute($lyPeriod, null)['result']['net'] : null];
        $scenario = [
            'sales' => ['target' => $target, 'method' => $in['method'], 'planned' => $sales, 'capped' => $sales < $target],
            'grossMargin' => $gross, 'tauxMarque' => $effective > 0 ? round($gross / $effective * 100, 2) : null,
            'expenses' => ['byCategory' => $expenses, 'total' => $expensesTotal, 'tripInOtherPeriod' => $tripInOther],
            'result' => $gross - $expensesTotal,
            'breakEven' => $breakEven === null ? null : ['revenue' => $breakEven, 'reached' => $effective >= $breakEven],
            'fixedCosts' => $fixed, 'resultAfterFixed' => $gross - $expensesTotal - $fixed,
            'breakEvenAfterFixed' => $breakEvenFixed === null ? null : ['revenue' => $breakEvenFixed, 'reached' => $effective >= $breakEvenFixed],
            'purchases' => ['total' => $purchaseCost, 'saleValue' => (int) round($purchaseSale), 'coefficient' => $purchaseCost > 0 ? round($purchaseSale / $purchaseCost, 2) : $coefDefault, 'referenceCoefficient' => $coefDefault],
            'stockStart' => ['costValue' => (int) round($stock['cost']), 'saleValue' => (int) round($stock['sale'])],
            'stockEnd' => ['costValue' => (int) round(max(0.0, $stock['cost'] + $purchaseCost - $soldCost)), 'saleValue' => (int) round(max(0.0, $available - $sales))],
            'cash' => $cash,
        ];
        $hash = hash('sha256', (string) json_encode([$in, $target, (int) round($stock['sale']), (int) round($stock['cost']), $cash['start'] ?? null,
            array_map(fn ($p) => [$p['period']['id'], $this->savedPlanned($p['period']['id'])], array_values($byPeriod))]));

        return [
            'period' => self::periodRow($period), 'currency' => $this->currency?->code() ?? TenantCurrencyProvider::DEFAULT,
            'estimations' => $est,
            'plan' => $scenario,
            'lastYear' => $lastYear,
            'delta' => $lastYear === null ? null : ['sales' => $sales - $lastYear['revenue'], 'grossMargin' => $gross - $lastYear['grossMargin'], 'result' => $lastYear['result'] === null ? null : $gross - $expensesTotal - $lastYear['result']],
            'planned' => array_values(array_map(fn ($p) => $p + ['mode' => $p['target'] ? 'replace' : 'add'], $byPeriod)),
            'trip' => $trip === null ? null : $trip + ['budgetSite' => $tripBudget, 'periodId' => (int) $tripPeriod->getId()],
            'previewHash' => $hash,
            'caveats' => array_map(fn (string $c) => ['code' => $c, 'text' => $this->translator?->trans("preparation.caveats.$c", [], 'gestion', is_string($body['locale'] ?? null) ? $body['locale'] : 'fr') ?? $c],
                array_values(array_unique($caveats))),
        ];
    }

    /**
     * Valider : prévu des postes de chaque période (la visée remplacée comme le PUT, les autres : achats et voyage ajoutés à l'existant) et voyage
     * créé (règles des voyages, TRIP_OVERLAP), en une transaction ; 409 PRICES_CHANGED si l'aperçu a changé.
     *
     * @return array<string, mixed>
     */
    public function validate(array $body, ?string $user, ?\DateTimeImmutable $today = null): array
    {
        $sim = $this->simulate($body, $today);
        $hash = $body['previewHash'] ?? null;
        if (!is_string($hash) || $hash === '') {
            throw new GestionValidationException(['previewHash' => 'REQUIRED']);
        }
        if (!hash_equals($sim['previewHash'], $hash)) {
            throw new GestionConflictException('PRICES_CHANGED', 'Le stock, les coûts, la trésorerie ou le prévu ont changé depuis la simulation : voici le plan à jour.', 409, ['simulation' => $sim]);
        }
        $em = $this->emProvider->getEntityManager();
        $conn = $em->getConnection();
        $conn->beginTransaction();
        try {
            foreach ($sim['planned'] as $p) {
                $collection = $em->find(Collections::class, $p['period']['id']);
                $saved = $this->savedPlanned($p['period']['id']);
                $lines = [];
                foreach (GestionBudgetLine::CATEGORIES as $c) {
                    if ($p['mode'] === 'replace') {
                        $lines[] = ['category' => $c, 'planned' => (int) $p[$c]];
                    } elseif (in_array($c, ['purchases', 'trip'], true) && $p[$c] > 0) {
                        $lines[] = ['category' => $c, 'planned' => (int) ($saved[$c] ?? 0) + (int) $p[$c]];
                    }
                }
                if ($lines !== []) {
                    $this->budgets->put($collection, ['lines' => $lines]);
                }
            }
            $trip = null;
            if ($sim['trip'] !== null) {
                $t = $sim['trip'];
                $trip = $this->trips->toArray($this->trips->create([
                    'name' => $t['name'] ?? ('Voyage d\'achat' . (($t['place'] ?? null) ? ' à ' . $t['place'] : '')), 'place' => $t['place'] ?? null,
                    'startDate' => $t['startDate'], 'endDate' => $t['endDate'], 'budgetTotal' => $t['budgetTotal'], 'budgetDaily' => $t['budgetDaily'] ?? null,
                    'currency' => $t['currency'] ?? null, 'exchangeRate' => $t['exchangeRate'] ?? null,
                ], $user));
            }
            $conn->commit();
        } catch (\Throwable $e) {
            if ($conn->isTransactionActive()) {
                $conn->rollBack();
            }
            $em->clear();
            throw $e;
        }

        return ['period' => $sim['period'], 'planned' => $sim['planned'], 'budget' => $this->budgets->get($em->find(Collections::class, $sim['period']['id']), 'fr'), 'trip' => $trip];
    }

    // ----- interne -----

    /** @return array<string, mixed> */
    private function input(array $body, Collections $period, \DateTimeImmutable $today): array
    {
        $errors = [];
        $todayKey = $today->format('Y-m-d');
        $endKey = $period->getEndDateCollection()->format('Y-m-d');
        $inWindow = fn (?string $d) => $d !== null && $d >= $todayKey && $d <= $endKey;
        $date = fn (mixed $v) => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && \DateTimeImmutable::createFromFormat('!Y-m-d', $v) !== false ? $v : null;
        $sales = is_array($body['sales'] ?? null) ? $body['sales'] : [];
        $method = $sales['method'] ?? 'lastYear';
        if (!in_array($method, self::METHODS, true)) {
            $errors['sales.method'] = 'INVALID_METHOD';
        }
        $amount = null;
        if ($method === 'custom') {
            $amount = $sales['amount'] ?? null;
            if ($amount === null) {
                $errors['sales.amount'] = 'REQUIRED';
            } elseif (!is_int($amount) || $amount < 0) {
                $errors['sales.amount'] = 'MUST_BE_INTEGER_CENTS';
            }
        }
        $purchases = [];
        $raw = $body['purchases'] ?? [];
        if (!is_array($raw) || !array_is_list($raw)) {
            $errors['purchases'] = 'MUST_BE_LIST';
        } elseif (count($raw) > self::MAX_PURCHASES) {
            $errors['purchases'] = 'TOO_MANY';
        } else {
            foreach ($raw as $i => $p) {
                $d = $date($p['date'] ?? null);
                if ($d === null) {
                    $errors["purchases[$i].date"] = 'INVALID_DATE';
                } elseif (!$inWindow($d)) {
                    $errors["purchases[$i].date"] = 'OUT_OF_RANGE';
                }
                $a = $p['amount'] ?? null;
                if (!is_int($a) || $a <= 0) {
                    $errors["purchases[$i].amount"] = 'MUST_BE_POSITIVE';
                }
                $c = $p['coefficient'] ?? null;
                if ($c !== null && (!(is_int($c) || is_float($c)) || $c < 1 || $c > 20)) {
                    $errors["purchases[$i].coefficient"] = 'OUT_OF_RANGE';
                }
                $purchases[] = ['date' => $d, 'amount' => is_int($a) ? $a : 0, 'coefficient' => $c === null ? null : (float) $c];
            }
        }
        $trip = null;
        if (($body['trip'] ?? null) !== null) {
            $t = $body['trip'];
            $s = $date($t['startDate'] ?? null);
            $e = $date($t['endDate'] ?? null);
            if ($s === null) {
                $errors['trip.startDate'] = 'INVALID_DATE';
            } elseif (!$inWindow($s)) {
                $errors['trip.startDate'] = 'OUT_OF_RANGE';
            }
            if ($e === null) {
                $errors['trip.endDate'] = 'INVALID_DATE';
            } elseif ($s !== null && $e < $s) {
                $errors['trip.endDate'] = 'BEFORE_START';
            }
            $total = $t['budgetTotal'] ?? null;
            $daily = $t['budgetDaily'] ?? null;
            if ($total === null && $daily === null) {
                $errors['trip.budgetTotal'] = 'REQUIRED';
            }
            foreach (['budgetTotal' => $total, 'budgetDaily' => $daily] as $k => $v) {
                if ($v !== null && (!is_int($v) || $v < 0)) {
                    $errors["trip.$k"] = 'MUST_BE_INTEGER_CENTS';
                }
            }
            $rate = $t['exchangeRate'] ?? null;
            if (($t['currency'] ?? null) !== null && ($rate === null || !is_numeric($rate) || (float) $rate <= 0)) {
                $errors['trip.exchangeRate'] = 'EXCHANGE_RATE_REQUIRED';
            }
            if ($s !== null && $e !== null && $total === null && is_int($daily)) {
                $total = $daily * AdviceRules::days($s, $e);
            }
            $trip = ['name' => is_string($t['name'] ?? null) ? mb_substr(trim($t['name']), 0, 255) : null, 'place' => is_string($t['place'] ?? null) ? mb_substr(trim($t['place']), 0, 255) : null,
                'startDate' => $s, 'endDate' => $e, 'budgetTotal' => is_int($total) ? $total : 0, 'budgetDaily' => is_int($daily) ? $daily : null,
                'currency' => is_string($t['currency'] ?? null) ? strtoupper($t['currency']) : null, 'exchangeRate' => $rate === null ? null : (float) $rate];
        }
        $planned = [];
        foreach (['shipping', 'advertising', 'other'] as $c) {
            $v = $body['planned'][$c] ?? 0;
            if (!is_int($v) || $v < 0) {
                $errors["planned.$c"] = 'MUST_BE_INTEGER_CENTS';
            }
            $planned[$c] = is_int($v) ? $v : 0;
        }
        if ($errors !== []) {
            throw new GestionValidationException($errors, 'Plan invalide');
        }
        usort($purchases, fn ($a, $b) => strcmp($a['date'], $b['date']));

        return ['method' => $method, 'amount' => $amount, 'purchases' => $purchases, 'trip' => $trip, 'planned' => $planned];
    }

    /**
     * Trésorerie d'aujourd'hui à la fin de la période visée : projection B1b-1 (solde, mouvements prévus, dépenses saisies) + achats prévus à leur
     * date + frais du voyage jour par jour + reste des autres postes et ventes à venir étalés sur les jours restants de la période (ventes en TTC
     * approché au taux réel des 90 derniers jours).
     *
     * @return array{0: ?array<string, mixed>, 1: string}
     */
    private function cashPath(Collections $period, \DateTimeImmutable $today, array $in, int $sales, array $targetPlanned, array $actual, int $tripBudget): array
    {
        $taxRate = $this->taxRate($today);
        $caveat = $taxRate === null ? 'TAXES_EXCLUDED' : 'TAXES_ESTIMATED';
        $todayKey = $today->format('Y-m-d');
        $start = max($todayKey, $period->getStartDateCollection()->format('Y-m-d'));
        $end = $period->getEndDateCollection()->format('Y-m-d');
        $days = AdviceRules::days($todayKey, $end);
        $p = $this->cash->projection(max(GestionCashService::MIN_DAYS, min(GestionCashService::MAX_DAYS, $days)), 'fr', $today);
        if ($p['balance'] === null) {
            return [null, $caveat];
        }
        $flows = [];
        foreach ($in['purchases'] as $x) {
            $flows[$x['date']] = ($flows[$x['date']] ?? 0) - $x['amount'];
        }
        if ($in['trip'] !== null && $tripBudget > 0) {
            $tripDays = AdviceRules::days($in['trip']['startDate'], $in['trip']['endDate']);
            for ($i = 0; $i < $tripDays; ++$i) {
                $d = (new \DateTimeImmutable($in['trip']['startDate']))->modify("+$i days")->format('Y-m-d');
                $flows[$d] = ($flows[$d] ?? 0) - $tripBudget / $tripDays;
            }
        }
        $realized = $period->getStartDateCollection()->format('Y-m-d') <= $todayKey ? (int) $this->results->compute($period, null)['sales']['revenue'] : 0;
        $remainingSales = max(0, $sales - $realized) * (1 + ($taxRate ?? 0.0));
        $remainingSpend = 0;
        foreach (['shipping', 'advertising', 'other'] as $c) {
            $remainingSpend += max(0, $targetPlanned[$c] - (int) ($actual[$c] ?? 0));
        }
        $spreadDays = AdviceRules::days($start, $end);
        $low = null;
        $below = null;
        $rows = [];
        $threshold = $p['settings']['threshold'];
        $base = array_column($p['projection']['days'], 'balance', 'date');
        $extra = 0.0;
        for ($d = new \DateTimeImmutable($todayKey); ($k = $d->format('Y-m-d')) <= $end; $d = $d->modify('+1 day')) {
            $in_ = 0.0;
            $out = 0.0;
            if ($k >= $start) {
                $in_ += $remainingSales / $spreadDays;
                $out += $remainingSpend / $spreadDays;
            }
            $f = $flows[$k] ?? 0;
            $f < 0 ? $out -= $f : $in_ += $f;
            $extra += $in_ - $out;
            $baseBalance = $base[$k] ?? end($base);
            $b = (int) round($baseBalance + $extra);
            $rows[] = ['date' => $k, 'in' => (int) round($in_), 'out' => (int) round($out), 'balance' => $b];
            if ($low === null || $b < $low['amount']) {
                $low = ['date' => $k, 'amount' => $b];
            }
            if ($below === null && $b < $threshold) {
                $below = $k;
            }
        }
        return [['start' => ['date' => $todayKey, 'amount' => (int) ($base[$todayKey] ?? $p['balance']['amount'])], 'low' => $low, 'end' => $rows === [] ? null : ['date' => $rows[array_key_last($rows)]['date'], 'amount' => $rows[array_key_last($rows)]['balance']],
            'belowThresholdFrom' => $below, 'threshold' => $threshold, 'taxRate' => $taxRate, 'days' => $rows], $caveat];
    }

    /** Taux moyen réel des taxes des 90 derniers jours (taxes ÷ ventes hors taxes), null sans vente taxée */
    private function taxRate(\DateTimeImmutable $today): ?float
    {
        $r = $this->emProvider->getEntityManager()->getConnection()->fetchAssociative(
            'SELECT COALESCE(SUM(o.total_tax), 0) AS tax, COALESCE(SUM(o.total_amount - COALESCE(o.total_tax, 0)), 0) AS net FROM "order" o'
            . ' WHERE o.order_type_id = 1 AND o.status_id NOT IN (1, 7) AND o.order_date >= ? AND o.order_date < ?',
            [$today->modify('-' . self::TAX_WINDOW_DAYS . ' days')->format('Y-m-d'), $today->format('Y-m-d')]
        );

        return (float) $r['tax'] > 0 && (float) $r['net'] > 0 ? round((float) $r['tax'] / (float) $r['net'], 4) : null;
    }

    /** Coefficient de référence : valeur de vente ÷ coût des lignes d'achat des 365 derniers jours (au moins 20), sinon 2 */
    public function referenceCoefficient(\DateTimeImmutable $today): float
    {
        $r = $this->emProvider->getEntityManager()->getConnection()->fetchAssociative(
            'SELECT COUNT(*) AS n, COALESCE(SUM(l.quantity_ordered * COALESCE(l.unit_cost_cents, p.purchase_price) * COALESCE(c.exchange_rate, 1)), 0) AS cost,'
            . ' COALESCE(SUM(l.quantity_ordered * COALESCE(pv.price, p.price)), 0) AS sale FROM commande_line l JOIN commande c ON c.id = l.commande_id JOIN product p ON p.id = l.product_id'
            . " LEFT JOIN product_variant pv ON pv.id = l.product_variant_id WHERE COALESCE(l.unit_cost_cents, p.purchase_price) > 0 AND COALESCE(pv.price, p.price) > 0 AND COALESCE(c.kind, '') <> 'reprise'"
            . ' AND c.date >= ? AND c.date < ?',
            [$today->modify('-' . AdviceRules::COEF_REFERENCE_DAYS . ' days')->format('Y-m-d'), $today->modify('+1 day')->format('Y-m-d')]
        );

        return (int) $r['n'] >= AdviceRules::COEF_MIN_LINES && (float) $r['cost'] > 0 ? round((float) $r['sale'] / (float) $r['cost'], 2) : self::DEFAULT_COEFFICIENT;
    }

    private function tripBudgetSite(array $trip): int
    {
        return (int) round($trip['budgetTotal'] * ($trip['currency'] !== null && $trip['currency'] !== ($this->currency?->code() ?? TenantCurrencyProvider::DEFAULT) ? (float) $trip['exchangeRate'] : 1.0));
    }

    private function periodOf(string $day): Collections
    {
        $p = $this->calendar->findByDate(new \DateTimeImmutable($day)) ?? $this->calendar->periodFor(new \DateTimeImmutable($day));
        if ($p === null) {
            throw new GestionConflictException('PERIOD_NOT_FOUND', 'Aucune période pour cette date.', 404, ['date' => $day]);
        }

        return $p;
    }

    /** @return array<string, int> prévu enregistré par poste */
    private function savedPlanned(int $periodId): array
    {
        $out = [];
        foreach ($this->emProvider->getEntityManager()->getConnection()->fetchAllAssociative('SELECT category, planned_cents FROM gestion_budget_line WHERE period_id = ?', [$periodId]) as $r) {
            $out[$r['category']] = (int) $r['planned_cents'];
        }
        ksort($out);

        return $out;
    }

    private static function periodRow(Collections $p): array
    {
        return ['id' => (int) $p->getId(), 'name' => $p->getNomCollection(), 'start' => $p->getStartDateCollection()->format('Y-m-d'), 'end' => $p->getEndDateCollection()->format('Y-m-d')];
    }
}
