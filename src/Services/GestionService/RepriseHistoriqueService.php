<?php

namespace App\Services\GestionService;

use App\Entity\Collections;
use App\Entity\Commande;
use App\Entity\Fournisseur;
use App\Entity\GestionReprise;
use App\Entity\PeriodResult;
use App\Entity\Product;
use App\Entity\TypeFournisseur;
use App\Services\CaisseService\StoreClock;
use App\Services\ContentAuditService\ContentAuditRecorder;
use App\Services\TenantEntityManagerProvider;
use App\UseCase\CommandeDashboardUseCase\FreezeCommandeMetricsUseCase;

/**
 * Reprise d'historique (lot P3, 10/11/2026, contrat validé par l'application : `docs/periodes-gestion.md` § 4) : un site qui
 * arrive avec des ventes mais sans commandes fournisseurs donne un coût aux articles déjà vendus, pour que les résultats des
 * périodes passées (P2) aient un coût des articles vendus.
 *
 * - Créneau : périodes **fermées**, du début de la période contenant (aujourd'hui − `months` mois) au début de la période
 *   courante ; `months` 1..24 (profondeur du calendrier).
 * - Articles vendus : lignes d'articles des commandes clients du créneau (mêmes règles que le résultat de période : ventes et
 *   retours, statuts incomplète / annulée et cartes-cadeaux exclus, locations et abonnements exclus), quantité nette > 0.
 * - Coût par article, dans l'ordre : `costs` du corps, puis `purchase_price` de la fiche, puis estimation `prix de vente /
 *   estimateCoefficient` (marquée estimée), sinon coût inconnu (hors reprise). Un article déjà rattaché à une commande
 *   fournisseur n'est jamais touché (`skipped`, `ALREADY_ATTACHED`).
 * - « Appliquer » : périodes manquantes créées (fermées par la date), une commande de reprise (`kind = reprise`, fournisseur
 *   « Reprise d'historique ») par période de première vente, article rattaché avec sa quantité vendue sur la ligne
 *   (`commande_quantity`, le stock n'est pas touché), rattachement horodaté au début de la période, commande figée ; résultats
 *   figés du créneau effacés. **Aucun effet sur la vente** : prix, prix d'achat, stock, variantes, visibilité intacts (testé).
 * - « Annuler » : n'efface que ce que la reprise a posé (commandes de reprise, rattachements, périodes créées restées vides,
 *   résultats figés du créneau).
 */
class RepriseHistoriqueService
{
    public const SUPPLIER_NAME = 'Reprise d\'historique';
    public const DEFAULT_MONTHS = 12;
    public const MAX_MONTHS = 24;
    public const DEFAULT_LIMIT = 100;
    public const MAX_LIMIT = 200;

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly PeriodCalendar $calendar,
        private readonly StoreClock $clock,
        private readonly FreezeCommandeMetricsUseCase $freezeCommande,
        private readonly ContentAuditRecorder $audit,
    ) {
    }

    /** Reprise active du site, ou null */
    public function active(): ?GestionReprise
    {
        return $this->emProvider->getEntityManager()->getRepository(GestionReprise::class)->findOneBy([], ['id' => 'DESC']);
    }

    /** @return array{active: bool, appliedAt: ?string, months: ?int, from: ?string, to: ?string, estimateCoefficient: ?float, periods: ?array, commandes: ?array, skipped: ?array} */
    public function state(): array
    {
        $reprise = $this->active();
        if ($reprise === null) {
            return ['active' => false, 'appliedAt' => null, 'months' => null, 'from' => null, 'to' => null, 'estimateCoefficient' => null, 'periods' => null, 'commandes' => null, 'skipped' => null];
        }
        $summary = $reprise->getSummary();

        return ['active' => true, 'appliedAt' => $reprise->getAppliedAt()?->format(\DateTimeInterface::ATOM), 'months' => $reprise->getMonths(),
            'from' => $reprise->getStartDate()?->format('Y-m-d'), 'to' => $reprise->getEndDate()?->format('Y-m-d'), 'estimateCoefficient' => $reprise->getEstimateCoefficient(),
            'periods' => $summary['periods'] ?? [], 'commandes' => $summary['commandes'] ?? [], 'skipped' => $summary['skipped'] ?? []];
    }

    /**
     * Créneau de la reprise : [début de la période contenant (aujourd'hui − months mois), début de la période courante[
     *
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}
     */
    public function window(int $months): array
    {
        $today = $this->calendar->today();
        $frequency = $this->calendar->frequency();
        $from = PeriodCalendar::definition($today->modify("-$months months"), $frequency)['start'];
        $to = PeriodCalendar::definition($today, $frequency)['start'];

        return [$from, $to];
    }

    /** @return array<string, mixed> */
    public function simulate(int $months, ?float $coefficient, int $page, int $limit): array
    {
        [$from, $to] = $this->window($months);
        $products = $this->soldProducts($from, $to, $coefficient);
        $sales = ['revenue' => 0, 'orders' => $this->orderCount($from, $to), 'items' => 0, 'unknownCostItems' => 0];
        $investment = ['known' => 0, 'estimated' => 0];
        foreach ($products as $p) {
            $sales['revenue'] += $p['revenue'];
            $sales['items'] += $p['quantitySold'];
            if ($p['costSource'] === null) {
                $sales['unknownCostItems'] += $p['quantitySold'];
            } elseif ($p['costSource'] === 'estimated') {
                $investment['estimated'] += $p['quantitySold'] * $p['unitCost'];
            } else {
                $investment['known'] += $p['quantitySold'] * $p['unitCost'];
            }
        }
        $total = count($products);
        $page = max(1, $page);
        $limit = max(1, min(self::MAX_LIMIT, $limit));

        return [
            'from' => $from->format('Y-m-d'), 'to' => $to->modify('-1 day')->format('Y-m-d'), 'months' => $months, 'estimateCoefficient' => $coefficient,
            'periods' => $this->periodsOf($from, $to),
            'sales' => $sales,
            'products' => array_values(array_slice($products, ($page - 1) * $limit, $limit)),
            'productsTotal' => $total, 'page' => $page, 'limit' => $limit,
            'investment' => $investment,
        ];
    }

    /**
     * @param array<int, int> $costs productId → coût unitaire (cents, devise du site)
     * @return array<string, mixed> état de la reprise (forme de `state()`)
     */
    public function apply(int $months, array $costs, ?float $coefficient): array
    {
        if ($this->active() !== null) {
            throw new GestionConflictException('REPRISE_ACTIVE', 'Une reprise d\'historique est déjà appliquée : annulez-la d\'abord.');
        }
        $em = $this->emProvider->getEntityManager();
        $conn = $em->getConnection();
        [$from, $to] = $this->window($months);
        $before = array_map('intval', $conn->fetchFirstColumn("SELECT id FROM collections WHERE kind = 'period'"));
        $this->calendar->ensurePeriods($from);
        $periodIds = array_map('intval', $conn->fetchFirstColumn("SELECT id FROM collections WHERE kind = 'period' AND start_date_collection >= :from AND start_date_collection < :to", ['from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')]));
        $created = array_values(array_diff($periodIds, $before));

        $products = $this->soldProducts($from, $to, $coefficient);
        $skipped = [];
        $byPeriod = [];
        foreach ($products as $p) {
            $id = (int) $p['productId'];
            if ($p['costSource'] === 'commande') {
                if (array_key_exists($id, $costs)) {
                    $skipped[] = ['productId' => $id, 'code' => 'ALREADY_ATTACHED'];
                }
                continue;
            }
            [$cost, $estimated] = match (true) {
                array_key_exists($id, $costs) => [$costs[$id], false],
                $p['costSource'] === 'purchasePrice' => [$p['unitCost'], false],
                $p['costSource'] === 'estimated' => [$p['unitCost'], true],
                default => [null, false],
            };
            if ($cost === null) {
                continue; // coût inconnu : hors reprise
            }
            $byPeriod[$p['firstPeriodKey']][] = ['productId' => $id, 'cost' => (int) $cost, 'quantity' => (int) $p['quantitySold'], 'estimated' => $estimated];
        }

        $supplier = $this->supplier();
        $commandes = [];
        foreach ($byPeriod as $periodKey => $lines) {
            $period = $em->getRepository(Collections::class)->findOneBy(['periodKey' => $periodKey, 'kind' => Collections::KIND_PERIOD]);
            if ($period === null) {
                continue;
            }
            $start = \DateTimeImmutable::createFromInterface($period->getStartDateCollection())->setTime(0, 0);
            $budget = array_sum(array_map(fn (array $l) => $l['cost'] * $l['quantity'], $lines));
            $commande = (new Commande())->setName(self::SUPPLIER_NAME . ' — ' . $period->getNomCollection())->setBudget($budget)->setDate(\DateTime::createFromImmutable($start))
                ->setCollections($period)->setFournisseur($supplier)->setKind(Commande::KIND_REPRISE)->setIsClosed(false);
            $em->persist($commande);
            $em->flush();
            foreach ($lines as $line) {
                $product = $em->find(Product::class, $line['productId']);
                if ($product === null || $product->getCommande() !== null) {
                    continue;
                }
                $product->setCommande($commande)->setCommandeCostCents($line['cost'])->setCommandeQuantity($line['quantity'])->setCommandeAttachedAt($start)->setCommandeCostEstimated($line['estimated']);
            }
            $em->flush();
            $this->freezeCommande->execute($commande); // figée : chiffres de la période conservés
            $commandes[] = ['id' => $commande->getId(), 'periodKey' => $periodKey, 'periodName' => $period->getNomCollection(), 'productCount' => count($lines),
                'estimatedCount' => count(array_filter($lines, fn (array $l) => $l['estimated'])), 'investment' => $budget];
        }
        $this->forgetFrozenResults($from, $to);

        $periods = [];
        foreach ($em->getRepository(Collections::class)->findBy(['id' => $periodIds], ['startDateCollection' => 'ASC']) as $period) {
            $periods[] = ['id' => $period->getId(), 'periodKey' => $period->getPeriodKey(), 'name' => $period->getNomCollection(), 'created' => in_array($period->getId(), $created, true)];
        }
        $reprise = (new GestionReprise())->setAppliedAt(new \DateTimeImmutable())->setMonths($months)->setStartDate($from)->setEndDate($to->modify('-1 day'))
            ->setEstimateCoefficient($coefficient)->setCreatedPeriodIds($created)->setSummary(['periods' => $periods, 'commandes' => $commandes, 'skipped' => $skipped]);
        $em->persist($reprise);
        $em->flush();
        $this->audit->record('gestion-reprise', $reprise->getId(), 'apply', null, $reprise->getSummary() + ['months' => $months, 'estimateCoefficient' => $coefficient]);

        return $this->state();
    }

    /** @return array<string, mixed> ce qui a été retiré */
    public function cancel(): array
    {
        $reprise = $this->active();
        if ($reprise === null) {
            throw new GestionConflictException('REPRISE_NOT_FOUND', 'Aucune reprise d\'historique à annuler.', 404);
        }
        $em = $this->emProvider->getEntityManager();
        $detached = 0;
        $commandes = $em->getRepository(Commande::class)->findBy(['kind' => Commande::KIND_REPRISE]);
        foreach ($commandes as $commande) {
            foreach ($em->getRepository(Product::class)->findBy(['commande' => $commande]) as $product) {
                $product->setCommande(null)->setCommandeCostCents(null)->setCommandeQuantity(null)->setCommandeAttachedAt(null)->setCommandeCostEstimated(false);
                $detached++;
            }
            $em->flush();
            $em->createQuery('DELETE FROM App\Entity\CommandeStatistiques s WHERE s.commande = :c')->setParameter('c', $commande)->execute();
            $em->remove($commande);
        }
        $em->flush();
        $removedPeriods = [];
        foreach ($reprise->getCreatedPeriodIds() as $periodId) {
            $period = $em->find(Collections::class, $periodId);
            if ($period === null || !$period->isPeriod()) {
                continue;
            }
            $used = (int) $em->getConnection()->fetchOne('SELECT (SELECT COUNT(*) FROM commande WHERE collections_id = :id) + (SELECT COUNT(*) FROM note_de_frais WHERE collection_id = :id)', ['id' => $periodId]);
            if ($used === 0 && (int) $period->getBudgetCollection() === 0) {
                $removedPeriods[] = $period->getPeriodKey();
                $em->remove($period); // period_result et collection_statistiques suivent (ON DELETE CASCADE)
            }
        }
        $em->flush();
        $this->forgetFrozenResults($reprise->getStartDate(), $reprise->getEndDate()->modify('+1 day'));
        $summary = ['commandesRemoved' => count($commandes), 'productsDetached' => $detached, 'periodsRemoved' => $removedPeriods];
        $this->audit->record('gestion-reprise', $reprise->getId(), 'cancel', $reprise->getSummary(), $summary);
        $em->remove($reprise);
        $em->flush();

        return ['cancelled' => true] + $summary;
    }

    // ----- lecture -----

    /**
     * Articles vendus dans le créneau (quantité nette > 0), triés par chiffre d'affaires décroissant.
     *
     * @return list<array{productId: int, name: ?string, code: ?string, quantitySold: int, revenue: int, firstSoldAt: string, firstPeriodKey: string, costKnown: bool, costSource: ?string, unitCost: ?int}>
     */
    public function soldProducts(\DateTimeImmutable $from, \DateTimeImmutable $to, ?float $coefficient): array
    {
        [$fromUtc, $toUtc] = $this->bounds($from, $to);
        $factor = 'CASE WHEN o.prices_include_tax AND COALESCE(o.sub_total, 0) <> 0 THEN (o.sub_total - COALESCE(o.total_tax, 0)) / o.sub_total ELSE 1 END';
        $rows = $this->emProvider->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT p.id, p.name, p.code, p.price, p.purchase_price, p.commande_id, p.commande_cost_cents, p.commande_cost_estimated, c.exchange_rate,'
            . ' SUM(CASE WHEN o.order_type_id = 2 THEN -oi.quantity ELSE oi.quantity END) AS qty,'
            . " SUM((CASE WHEN o.order_type_id = 2 THEN -ABS(oi.total_price) ELSE oi.total_price END) * ($factor)) AS revenue,"
            . ' MIN(o.order_date) FILTER (WHERE o.order_type_id = 1) AS first_sold'
            . ' FROM order_items oi JOIN "order" o ON o.id = oi.order_associated_id'
            . ' JOIN product_variant pv ON pv.id = oi.product_variant_id JOIN product p ON p.id = pv.product_id'
            . ' LEFT JOIN booking b ON b.order_item_id = oi.id LEFT JOIN commande c ON c.id = p.commande_id'
            . ' WHERE o.order_date >= :from AND o.order_date < :to AND o.order_type_id IN (1, 2) AND o.status_id NOT IN (1, 7) AND b.id IS NULL AND o.subscription_id IS NULL'
            . ' GROUP BY p.id, c.exchange_rate HAVING SUM(CASE WHEN o.order_type_id = 2 THEN -oi.quantity ELSE oi.quantity END) > 0 AND MIN(o.order_date) FILTER (WHERE o.order_type_id = 1) IS NOT NULL'
            . ' ORDER BY revenue DESC, p.id',
            ['from' => $fromUtc, 'to' => $toUtc]
        );
        $frequency = $this->calendar->frequency();
        $tz = $this->clock->timezone();
        $out = [];
        foreach ($rows as $r) {
            $firstSold = (new \DateTimeImmutable((string) $r['first_sold'], new \DateTimeZone('UTC')))->setTimezone($tz);
            [$source, $unit] = match (true) {
                $r['commande_id'] !== null && $r['commande_cost_cents'] !== null => [$r['commande_cost_estimated'] ? 'estimated' : 'commande', (int) round((float) $r['commande_cost_cents'] * (float) ($r['exchange_rate'] ?? 1))],
                $r['commande_id'] !== null => ['commande', $r['purchase_price'] !== null ? (int) round((float) $r['purchase_price']) : (int) round((float) $r['price'])],
                $r['purchase_price'] !== null => ['purchasePrice', (int) round((float) $r['purchase_price'])],
                $coefficient !== null && $coefficient > 0 && $r['price'] !== null => ['estimated', (int) round((float) $r['price'] / $coefficient)],
                default => [null, null],
            };
            if ($r['commande_id'] !== null && $source === 'estimated') {
                $source = 'commande'; // déjà rattaché (par une reprise précédente) : jamais touché
            }
            $out[] = ['productId' => (int) $r['id'], 'name' => $r['name'], 'code' => $r['code'], 'quantitySold' => (int) $r['qty'], 'revenue' => (int) round((float) $r['revenue']),
                'firstSoldAt' => $firstSold->format('Y-m-d'), 'firstPeriodKey' => PeriodCalendar::definition($firstSold, $frequency)['key'],
                'costKnown' => $source !== null && $source !== 'estimated', 'costSource' => $source, 'unitCost' => $unit];
        }

        return $out;
    }

    private function orderCount(\DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        [$fromUtc, $toUtc] = $this->bounds($from, $to);

        return (int) $this->emProvider->getEntityManager()->getConnection()->fetchOne(
            'SELECT COUNT(DISTINCT o.id) FROM "order" o JOIN order_items oi ON oi.order_associated_id = o.id LEFT JOIN booking b ON b.order_item_id = oi.id'
            . ' WHERE o.order_date >= :from AND o.order_date < :to AND o.order_type_id = 1 AND o.status_id NOT IN (1, 7) AND b.id IS NULL AND o.subscription_id IS NULL AND oi.product_variant_id IS NOT NULL',
            ['from' => $fromUtc, 'to' => $toUtc]
        );
    }

    /** @return list<array{periodKey: string, name: string, start: string, end: string, exists: bool, status: ?string}> */
    private function periodsOf(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $frequency = $this->calendar->frequency();
        $repo = $this->emProvider->getEntityManager()->getRepository(Collections::class);
        $out = [];
        $cursor = $from;
        $guard = 0;
        while ($cursor < $to && $guard++ < 60) {
            $def = PeriodCalendar::definition($cursor, $frequency);
            $existing = $repo->findOneBy(['periodKey' => $def['key'], 'kind' => Collections::KIND_PERIOD]);
            $out[] = ['periodKey' => $def['key'], 'name' => $def['name'], 'start' => $def['start']->format('Y-m-d'), 'end' => $def['end']->format('Y-m-d'),
                'exists' => $existing !== null, 'status' => $existing !== null ? $this->calendar->status($existing) : null];
            $cursor = $def['end']->modify('+1 day');
        }

        return $out;
    }

    /** Bornes UTC (chaînes SQL) du créneau de jours du magasin [from, to[ */
    private function bounds(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        [$start, $end] = $this->clock->range($from->format('Y-m-d'), $to->modify('-1 day')->format('Y-m-d'));

        return [$start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s')];
    }

    private function supplier(): Fournisseur
    {
        $em = $this->emProvider->getEntityManager();
        $supplier = $em->getRepository(Fournisseur::class)->findOneBy(['name' => self::SUPPLIER_NAME]);
        if ($supplier !== null) {
            return $supplier;
        }
        $type = $em->getRepository(TypeFournisseur::class)->findOneBy(['name' => 'Autre']) ?? $em->getRepository(TypeFournisseur::class)->findOneBy([], ['id' => 'ASC']);
        $supplier = (new Fournisseur())->setName(self::SUPPLIER_NAME)->setTypeFournisseur($type);
        $em->persist($supplier);
        $em->flush();

        return $supplier;
    }

    /** Résultats figés des périodes du créneau effacés : recalculés à la lecture suivante */
    private function forgetFrozenResults(\DateTimeImmutable $from, \DateTimeImmutable $to): void
    {
        $em = $this->emProvider->getEntityManager();
        $em->createQuery('DELETE FROM App\Entity\PeriodResult r WHERE r.collection IN (SELECT c.id FROM App\Entity\Collections c WHERE c.kind = :k AND c.startDateCollection >= :from AND c.startDateCollection < :to)')
            ->setParameter('k', Collections::KIND_PERIOD)->setParameter('from', $from->format('Y-m-d'))->setParameter('to', $to->format('Y-m-d'))->execute();
    }
}
