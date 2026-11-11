<?php

namespace App\Gestion\Services;

use App\Entity\Collections;
use App\Entity\Commande;
use App\Gestion\Entity\GestionTrip;
use App\Entity\NoteDeFrais;
use App\Services\BoutiqueSettingsService\SiteCurrencyService;
use App\Services\BoutiqueSettingsService\TenantCurrencyProvider;
use App\Services\CaisseService\StoreClock;
use App\Services\ContentAuditService\ContentAuditRecorder;
use App\Services\TenantEntityManagerProvider;

/**
 * Voyages d'achat de la gestion (lot B1a, 11/11/2026, `docs/budgets-gestion.md` §§ 2-3) : validation (devise et taux comme G6 / A1, pas de
 * chevauchement), rattachement automatique des notes de frais et commandes par leur date quand le voyage est créé ou ses dates changent
 * (éléments libres ou rattachés automatiquement seulement ; jamais un choix du gérant ; périodes fermées ignorées), rattachement manuel
 * (tout ou rien, 409 PERIOD_CLOSED sur une période fermée), suivi jour par jour (achats et frais séparés, `budgetScope`).
 */
class GestionTripService
{
    public const WARNING_RATIO = 0.8;

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly PeriodCalendar $calendar,
        private readonly StoreClock $clock,
        private readonly ContentAuditRecorder $audit,
        private readonly ?TenantCurrencyProvider $currency = null,
    ) {
    }

    /** @return array{0: list<array<string, mixed>>, 1: int} */
    public function list(?string $from, ?string $to, int $page, int $limit): array
    {
        $conn = $this->emProvider->getEntityManager()->getConnection();
        $where = ['TRUE'];
        $params = [];
        if ($from !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
            $where[] = 'end_date >= :from';
            $params['from'] = $from;
        }
        if ($to !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            $where[] = 'start_date <= :to';
            $params['to'] = $to;
        }
        $sql = ' FROM gestion_trip WHERE ' . implode(' AND ', $where);
        $total = (int) $conn->fetchOne('SELECT COUNT(*)' . $sql, $params);
        $ids = $conn->fetchFirstColumn('SELECT id' . $sql . ' ORDER BY start_date DESC, id DESC LIMIT ' . $limit . ' OFFSET ' . (($page - 1) * $limit), $params);
        $em = $this->emProvider->getEntityManager();

        return [array_map(fn ($id) => $this->toArray($em->find(GestionTrip::class, (int) $id)), $ids), $total];
    }

    public function find(int $id): GestionTrip
    {
        $trip = $this->emProvider->getEntityManager()->find(GestionTrip::class, $id);
        if ($trip === null) {
            throw new GestionConflictException('TRIP_NOT_FOUND', 'Voyage introuvable.', 404, ['tripId' => $id]);
        }

        return $trip;
    }

    /** @param array<string, mixed> $data */
    public function create(array $data, ?string $by): GestionTrip
    {
        $f = $this->validate($data, null);
        $now = new \DateTimeImmutable();
        $trip = (new GestionTrip())->setCreatedBy($by)->setCreatedAt($now);
        $this->apply($trip, $f);
        $em = $this->emProvider->getEntityManager();
        $em->persist($trip);
        $em->flush();
        $this->syncAuto($trip);
        $this->audit->record('gestion-voyages', $trip->getId(), 'create', null, $this->toArray($trip));

        return $trip;
    }

    /** @param array<string, mixed> $data */
    public function update(GestionTrip $trip, array $data): GestionTrip
    {
        $before = $this->toArray($trip);
        $merged = [
            'name' => $trip->getName(), 'place' => $trip->getPlace(), 'startDate' => $trip->getStartDate()->format('Y-m-d'), 'endDate' => $trip->getEndDate()->format('Y-m-d'),
            'budgetTotal' => $trip->getBudgetTotalCents(), 'budgetDaily' => $trip->getBudgetDailyCents(), 'budgetScope' => $trip->getBudgetScope(),
            'currency' => $trip->getCurrency(), 'exchangeRate' => $trip->getExchangeRate(), 'notes' => $trip->getNotes(),
        ];
        $f = $this->validate(array_replace($merged, $data), $trip->getId());
        $datesChanged = $f['startDate']->format('Y-m-d') !== $trip->getStartDate()->format('Y-m-d') || $f['endDate']->format('Y-m-d') !== $trip->getEndDate()->format('Y-m-d');
        $this->apply($trip, $f);
        $this->emProvider->getEntityManager()->flush();
        if ($datesChanged) {
            $this->syncAuto($trip);
        }
        $this->audit->record('gestion-voyages', $trip->getId(), 'update', $before, $this->toArray($trip));

        return $trip;
    }

    /**
     * Suppression : les notes et commandes rattachées redeviennent libres, sauf celles des périodes fermées, qui gardent leur rattachement
     * (le poste « voyage » d'une période fermée ne bouge pas).
     */
    public function delete(GestionTrip $trip): void
    {
        $em = $this->emProvider->getEntityManager();
        $id = (int) $trip->getId();
        $before = $this->toArray($trip);
        foreach ([NoteDeFrais::class, Commande::class] as $class) {
            foreach ($em->getRepository($class)->findBy(['tripId' => $id]) as $item) {
                if (!$this->inClosedPeriod($item->getDate())) {
                    $item->setTrip(null, null);
                }
            }
        }
        $em->remove($trip);
        $em->flush();
        $this->audit->record('gestion-voyages', $id, 'delete', $before, null);
    }

    /**
     * Choix du gérant : rattache (ou détache, `$tripId` null) des notes et commandes, en `manual` (jamais déplacées ensuite par la date).
     * Tout ou rien : un élément inconnu → 422, un élément d'une période fermée → 409 PERIOD_CLOSED avec les identifiants concernés.
     *
     * @return array{tripId: ?int, notes: list<array{id: int, tripId: ?int}>, commandes: list<array{id: int, tripId: ?int}>}
     */
    public function attach(?int $tripId, array $noteIds, array $commandeIds): array
    {
        $em = $this->emProvider->getEntityManager();
        if ($tripId !== null) {
            $this->find($tripId);
        }
        $errors = [];
        $items = ['notes' => [], 'commandes' => []];
        foreach (['noteIds' => [$noteIds, NoteDeFrais::class, 'notes', 'NOTE_NOT_FOUND'], 'commandeIds' => [$commandeIds, Commande::class, 'commandes', 'COMMANDE_NOT_FOUND']] as $field => [$ids, $class, $key, $code]) {
            foreach (array_values($ids) as $i => $id) {
                $item = is_int($id) || (is_string($id) && ctype_digit($id)) ? $em->find($class, (int) $id) : null;
                $item === null ? $errors["{$field}[$i]"] = $code : $items[$key][$item->getId()] = $item;
            }
        }
        if ($errors !== []) {
            throw new GestionValidationException($errors, 'Rattachement invalide : ' . implode(', ', array_keys($errors)));
        }
        if ($items['notes'] === [] && $items['commandes'] === []) {
            throw new GestionValidationException(['noteIds' => 'REQUIRED'], 'Aucune note ni commande à rattacher');
        }
        $closed = ['noteIds' => [], 'commandeIds' => []];
        foreach ($items['notes'] as $id => $n) {
            if ($this->inClosedPeriod($n->getDate())) {
                $closed['noteIds'][] = $id;
            }
        }
        foreach ($items['commandes'] as $id => $c) {
            if ($this->inClosedPeriod($c->getDate())) {
                $closed['commandeIds'][] = $id;
            }
        }
        if ($closed['noteIds'] !== [] || $closed['commandeIds'] !== []) {
            throw new GestionConflictException('PERIOD_CLOSED', 'Ces éléments sont dans une période fermée : leur rattachement ne change plus.', 409, $closed);
        }
        foreach ([...array_values($items['notes']), ...array_values($items['commandes'])] as $item) {
            $item->setTrip($tripId, 'manual');
        }
        $em->flush();

        return [
            'tripId' => $tripId,
            'notes' => array_values(array_map(fn (NoteDeFrais $n) => ['id' => (int) $n->getId(), 'tripId' => $n->getTripId()], $items['notes'])),
            'commandes' => array_values(array_map(fn (Commande $c) => ['id' => (int) $c->getId(), 'tripId' => $c->getTripId()], $items['commandes'])),
        ];
    }

    /** Forme rendue par l'API */
    public function toArray(GestionTrip $t): array
    {
        $site = $this->currency?->code() ?? TenantCurrencyProvider::DEFAULT;

        return [
            'id' => $t->getId(), 'name' => $t->getName(), 'place' => $t->getPlace(),
            'startDate' => $t->getStartDate()->format('Y-m-d'), 'endDate' => $t->getEndDate()->format('Y-m-d'), 'days' => $t->days(),
            'budgetTotal' => $t->getBudgetTotalCents(), 'budgetDaily' => $t->dailyCents(), 'budgetDailyEntered' => $t->getBudgetDailyCents() !== null, 'budgetScope' => $t->getBudgetScope(),
            'currency' => $t->getCurrency() ?? $site, 'exchangeRate' => $t->getCurrency() === null ? null : $t->getExchangeRate(), 'siteCurrency' => $site,
            'budgetTotalSite' => $t->budgetTotalSite(), 'budgetDailySite' => $t->budgetDailySite(),
            'notes' => $t->getNotes(), 'createdAt' => $t->getCreatedAt()?->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * Suivi du voyage, dans la devise du site : chaque jour, achats (lignes des commandes rattachées × taux de la commande) et frais (notes
     * rattachées) ; `spent` selon `budgetScope` ; allocation à date = budget journalier × jours écoulés (au plus le total). Un élément rattaché
     * à la main hors des dates compte dans les totaux.
     *
     * @return array<string, mixed>
     */
    public function follow(GestionTrip $trip, ?\DateTimeImmutable $today = null): array
    {
        $conn = $this->emProvider->getEntityManager()->getConnection();
        $today ??= $this->calendar->today();
        $id = (int) $trip->getId();
        $purchases = [];
        foreach ($conn->fetchAllAssociative(
            'SELECT CAST(c.date AS date) AS d, COALESCE(SUM(l.quantity_ordered * COALESCE(l.unit_cost_cents, p.purchase_price, p.price, 0) * COALESCE(c.exchange_rate, 1)), 0) AS amount'
            . ' FROM commande c LEFT JOIN commande_line l ON l.commande_id = c.id LEFT JOIN product p ON p.id = l.product_id WHERE c.trip_id = ? GROUP BY CAST(c.date AS date)', [$id]
        ) as $r) {
            $purchases[(string) $r['d']] = (int) round((float) $r['amount']);
        }
        $expenses = [];
        foreach ($conn->fetchAllAssociative('SELECT n.date AS d, COALESCE(SUM(n.montant), 0) AS amount FROM note_de_frais n WHERE n.trip_id = ? GROUP BY n.date', [$id]) as $r) {
            $expenses[substr((string) $r['d'], 0, 10)] = (int) $r['amount'];
        }
        $all = $trip->getBudgetScope() === 'all';
        $daily = $trip->budgetDailySite();
        $days = [];
        for ($d = $trip->getStartDate(); $d <= $trip->getEndDate(); $d = $d->modify('+1 day')) {
            $k = $d->format('Y-m-d');
            $p = $purchases[$k] ?? 0;
            $e = $expenses[$k] ?? 0;
            $days[] = ['date' => $k, 'purchases' => $p, 'expenses' => $e, 'spent' => $all ? $p + $e : $e, 'budget' => $daily];
        }
        $totalPurchases = array_sum($purchases);
        $totalExpenses = array_sum($expenses);
        $spent = $all ? $totalPurchases + $totalExpenses : $totalExpenses;
        $budgetTotal = $trip->budgetTotalSite();
        $todayKey = $today->format('Y-m-d');
        $elapsed = $todayKey < $trip->getStartDate()->format('Y-m-d') ? 0 : min($trip->days(), (int) $trip->getStartDate()->diff($today->setTime(0, 0))->days + 1);
        $allowance = min($daily * $elapsed, $budgetTotal);
        $status = 'ok';
        if ($spent > $budgetTotal || ($elapsed > 0 && $spent > $allowance)) {
            $status = 'over';
        } elseif (($elapsed > 0 && $spent >= self::WARNING_RATIO * $allowance && $allowance > 0) || ($budgetTotal > 0 && $spent >= self::WARNING_RATIO * $budgetTotal)) {
            $status = 'warning';
        }
        $average = $elapsed > 0 ? (int) round($spent / $elapsed) : 0;
        $inProgress = $trip->covers($today);
        $todaySpent = $inProgress ? ($all ? ($purchases[$todayKey] ?? 0) : 0) + ($expenses[$todayKey] ?? 0) : null;

        return [
            'trip' => ['id' => $id, 'name' => $trip->getName(), 'place' => $trip->getPlace(), 'startDate' => $trip->getStartDate()->format('Y-m-d'), 'endDate' => $trip->getEndDate()->format('Y-m-d')],
            'budgetScope' => $trip->getBudgetScope(), 'inProgress' => $inProgress, 'elapsedDays' => $elapsed,
            'days' => $days,
            'totals' => ['purchases' => $totalPurchases, 'expenses' => $totalExpenses, 'spent' => $spent, 'budget' => $budgetTotal, 'remaining' => $budgetTotal - $spent, 'allowanceToDate' => $allowance],
            'today' => $inProgress ? ['date' => $todayKey, 'spent' => $todaySpent, 'remaining' => $daily - $todaySpent] : null,
            'averageDaily' => $average, 'projectedTotal' => $average * $trip->days(), 'status' => $status,
            'overBy' => $status === 'over' ? max($spent - $allowance, $spent - $budgetTotal) : 0,
        ];
    }

    /** Voyage en cours aujourd'hui, ou null */
    public function current(?\DateTimeImmutable $today = null): ?GestionTrip
    {
        $today ??= $this->calendar->today();
        $conn = $this->emProvider->getEntityManager()->getConnection();
        $id = $conn->fetchOne('SELECT id FROM gestion_trip WHERE start_date <= :d AND end_date >= :d ORDER BY id LIMIT 1', ['d' => $today->format('Y-m-d')]);

        return $id === false ? null : $this->emProvider->getEntityManager()->find(GestionTrip::class, (int) $id);
    }

    // ----- interne -----

    /**
     * Rattachement automatique après création ou changement de dates : les éléments libres (trip_id et trip_source nuls) datés dans le voyage
     * y entrent ; ceux rattachés automatiquement à ce voyage et sortis de ses dates en sortent. Un choix du gérant (manual, y compris détaché)
     * n'est jamais touché ; les éléments des périodes fermées sont ignorés, sans erreur ; les commandes de reprise restent hors voyage.
     */
    private function syncAuto(GestionTrip $trip): void
    {
        $em = $this->emProvider->getEntityManager();
        $id = (int) $trip->getId();
        $start = $trip->getStartDate()->format('Y-m-d');
        $end = $trip->getEndDate()->format('Y-m-d');
        $conn = $em->getConnection();
        $noteIds = $conn->fetchFirstColumn(
            "SELECT id FROM note_de_frais WHERE (trip_id IS NULL AND trip_source IS NULL AND date BETWEEN :s AND :e) OR (trip_id = :id AND trip_source = 'auto' AND date NOT BETWEEN :s AND :e)",
            ['s' => $start, 'e' => $end, 'id' => $id]
        );
        $commandeIds = $conn->fetchFirstColumn(
            "SELECT id FROM commande WHERE kind <> 'reprise' AND ((trip_id IS NULL AND trip_source IS NULL AND CAST(date AS date) BETWEEN :s AND :e) OR (trip_id = :id AND trip_source = 'auto' AND CAST(date AS date) NOT BETWEEN :s AND :e))",
            ['s' => $start, 'e' => $end, 'id' => $id]
        );
        $changed = false;
        foreach ([[NoteDeFrais::class, $noteIds], [Commande::class, $commandeIds]] as [$class, $ids]) {
            foreach ($ids as $itemId) {
                $item = $em->find($class, (int) $itemId);
                if ($item === null || $this->inClosedPeriod($item->getDate())) {
                    continue;
                }
                $item->getTripId() === $id ? $item->setTrip(null, null) : $item->setTrip($id, 'auto');
                $changed = true;
            }
        }
        if ($changed) {
            $em->flush();
        }
    }

    private function inClosedPeriod(?\DateTimeInterface $date): bool
    {
        if ($date === null) {
            return false;
        }
        $period = $this->calendar->findByDate($date);

        return $period instanceof Collections && $this->calendar->isClosed($period);
    }

    /** @return array<string, mixed> */
    private function validate(array $data, ?int $selfId): array
    {
        $input = new GestionInput($data);
        $name = $input->text('name', true);
        $place = $input->text('place', false);
        $start = $input->date('startDate', true);
        $end = $input->date('endDate', true);
        $total = $input->cents('budgetTotal', true);
        $daily = ($data['budgetDaily'] ?? null) === null ? null : $input->cents('budgetDaily', false);
        $notes = $input->text('notes', false, 2000);
        $errors = $input->errors();
        $scope = isset($data['budgetScope']) && is_string($data['budgetScope']) ? strtolower(trim($data['budgetScope'])) : GestionTrip::DEFAULT_SCOPE;
        if (!in_array($scope, GestionTrip::SCOPES, true)) {
            $errors['budgetScope'] = 'INVALID_SCOPE';
        }
        if ($start !== null && $end !== null && $end < $start) {
            $errors['endDate'] = 'BEFORE_START';
        }
        if ($total !== null && $total < 0) {
            $errors['budgetTotal'] = 'MUST_BE_POSITIVE';
        }
        if ($daily !== null && $daily < 0) {
            $errors['budgetDaily'] = 'MUST_BE_POSITIVE';
        }
        $site = strtoupper($this->currency?->code() ?? TenantCurrencyProvider::DEFAULT);
        $currency = isset($data['currency']) && is_string($data['currency']) && trim($data['currency']) !== '' ? strtoupper(trim($data['currency'])) : null;
        if ($currency !== null && !in_array($currency, SiteCurrencyService::SUPPORTED, true)) {
            $errors['currency'] = 'INVALID_CURRENCY';
        }
        if ($currency === $site) {
            $currency = null;
        }
        $rate = null;
        if ($currency !== null) {
            $raw = $data['exchangeRate'] ?? null;
            if ($raw === null || $raw === '') {
                $errors['exchangeRate'] = 'EXCHANGE_RATE_REQUIRED';
            } elseif (!is_numeric($raw) || (float) $raw <= 0) {
                $errors['exchangeRate'] = 'MUST_BE_POSITIVE';
            } else {
                $rate = (float) $raw;
            }
        }
        if ($errors !== []) {
            throw new GestionValidationException($errors, 'Voyage invalide : ' . implode(', ', array_keys($errors)));
        }
        $overlap = $this->emProvider->getEntityManager()->getConnection()->fetchOne(
            'SELECT id FROM gestion_trip WHERE start_date <= :e AND end_date >= :s AND id <> :id ORDER BY start_date LIMIT 1',
            ['s' => $start->format('Y-m-d'), 'e' => $end->format('Y-m-d'), 'id' => $selfId ?? 0]
        );
        if ($overlap !== false) {
            throw new GestionConflictException('TRIP_OVERLAP', 'Ces dates chevauchent un autre voyage.', 409, ['tripId' => (int) $overlap]);
        }

        return ['name' => $name, 'place' => $place, 'startDate' => \DateTimeImmutable::createFromInterface($start)->setTime(0, 0), 'endDate' => \DateTimeImmutable::createFromInterface($end)->setTime(0, 0),
            'budgetTotal' => (int) $total, 'budgetDaily' => $daily, 'budgetScope' => $scope, 'currency' => $currency, 'exchangeRate' => $rate, 'notes' => $notes];
    }

    private function apply(GestionTrip $t, array $f): void
    {
        $t->setName((string) $f['name'])->setPlace($f['place'])->setStartDate($f['startDate'])->setEndDate($f['endDate'])
            ->setBudgetTotalCents($f['budgetTotal'])->setBudgetDailyCents($f['budgetDaily'])->setBudgetScope($f['budgetScope'])
            ->setCurrency($f['currency'])->setExchangeRate($f['exchangeRate'])->setNotes($f['notes'])->setUpdatedAt(new \DateTimeImmutable());
    }
}
