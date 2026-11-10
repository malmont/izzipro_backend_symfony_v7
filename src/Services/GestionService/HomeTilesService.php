<?php

namespace App\Services\GestionService;

use App\Entity\GestionDocument;
use App\Entity\GestionTileDismissal;
use App\Repository\GestionTileDismissalRepository;
use App\Services\TenantEntityManagerProvider;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Vignettes d'accueil de l'application de gestion, première version (11/11/2026, `docs/vignettes-gestion.md`) : premiers pas par
 * règles (sans IA) et alerte de stock bas calculée ici ; 3 au plus, les plus urgentes d'abord ; écartées par utilisateur (premier pas :
 * pour toujours ; alerte : 7 jours, ou plus tôt si la liste des articles concernés change). Les conseils formulés par l'IA (`advice`)
 * viendront avec le lot G1 sans changer la forme.
 */
class HomeTilesService
{
    public const FIRST_INVOICE = 'first-invoice';
    public const FIRST_NOTE = 'first-note';
    public const FIRST_CAMPAIGN = 'first-campaign';
    public const LOW_STOCK = 'low-stock';
    public const BUDGET_OVER = 'budget-over';   // lot B1a : poste du budget de la période en cours au-delà du prévu
    public const TRIP_BUDGET = 'trip-budget';   // lot B1a : voyage en cours au-delà de son budget à date
    public const CASH_LOW = 'cash-low';         // lot B1b-1 : point bas de trésorerie des 14 prochains jours sous le seuil
    public const CASH_FIRST_BALANCE = 'cash-first-balance'; // lot B1b-1 : aucun solde saisi, mais un mouvement prévu ou une période en cours
    public const KNOWN = [self::FIRST_INVOICE, self::FIRST_NOTE, self::FIRST_CAMPAIGN, self::LOW_STOCK, self::BUDGET_OVER, self::TRIP_BUDGET, self::CASH_LOW, self::CASH_FIRST_BALANCE];
    public const ALERTS = [self::LOW_STOCK, self::BUDGET_OVER, self::TRIP_BUDGET, self::CASH_LOW];
    public const MAX_TILES = 3;
    public const LOW_STOCK_WINDOW_DAYS = 30;  // ventes prises en compte
    public const LOW_STOCK_MIN_SOLD = 2;      // une variante vendue au moins 2 fois sur la fenêtre « se vend »
    public const LOW_STOCK_DAYS = 14;         // couverture du stock au rythme des ventes en dessous de laquelle elle « va manquer »
    public const LOW_STOCK_MAX_FIGURES = 20;
    public const ALERT_SNOOZE_DAYS = 7;

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly TranslatorInterface $translator,
        private readonly ?GestionBudgetService $budgets = null,
        private readonly ?GestionTripService $trips = null,
        private readonly ?\App\Services\BoutiqueSettingsService\TenantCurrencyProvider $currency = null,
        private readonly ?GestionCashService $cash = null,
        private readonly ?PeriodCalendar $calendar = null,
    ) {
    }

    /** @return list<array<string, mixed>> vignettes visibles pour cet utilisateur, 3 au plus */
    public function visible(int $userId, string $locale): array
    {
        /** @var GestionTileDismissalRepository $repo */
        $repo = $this->emProvider->getEntityManager()->getRepository(GestionTileDismissal::class);
        $dismissed = $repo->forUser($userId);
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $tiles = array_values(array_filter($this->candidates($locale), function (array $t) use ($dismissed, $now): bool {
            $d = $dismissed[$t['id']] ?? null;
            if ($d === null) {
                return true;
            }
            $expired = $d['until'] !== null && new \DateTimeImmutable($d['until'], new \DateTimeZone('UTC')) <= $now;
            $changed = $d['signature'] !== null && $d['signature'] !== $t['signature'];

            return $expired || $changed;
        }));
        usort($tiles, fn (array $a, array $b) => $b['priority'] <=> $a['priority']);

        return array_map(function (array $t): array {
            unset($t['signature']);

            return $t;
        }, array_slice($tiles, 0, self::MAX_TILES));
    }

    public function dismiss(int $userId, string $tileId): void
    {
        $signature = null;
        $until = null;
        if (in_array($tileId, self::ALERTS, true)) {
            foreach ($this->candidates('fr') as $t) {
                if ($t['id'] === $tileId) {
                    $signature = $t['signature'];
                }
            }
            $until = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('+' . self::ALERT_SNOOZE_DAYS . ' days');
        }
        /** @var GestionTileDismissalRepository $repo */
        $repo = $this->emProvider->getEntityManager()->getRepository(GestionTileDismissal::class);
        $repo->dismiss($userId, $tileId, $signature, $until);
    }

    /** @return list<array<string, mixed>> toutes les vignettes qui s'appliquent au site, avant les écarts */
    private function candidates(string $locale): array
    {
        $conn = $this->emProvider->getEntityManager()->getConnection();
        $tiles = [];
        $cash = $this->cash?->alert();
        if ($cash !== null && $cash['status'] !== 'ok') {
            $tiles[] = $this->tile(self::CASH_LOW, 'alert', 85, 'cash_low', $locale,
                ['%amount%' => self::money($cash['low14']['amount'], $locale, $this->siteCurrency()), '%date%' => self::day($cash['low14']['date'], $locale)],
                ['screen' => 'tresorerie', 'params' => new \stdClass()],
                [['label' => $this->t('tiles.sources.cash_balance', $locale, ['%date%' => self::day($cash['balance']['date'], $locale)]), 'value' => $cash['balance']['amount']], ['label' => $this->t('tiles.sources.cash_threshold', $locale), 'value' => $cash['threshold']]],
                [['date' => $cash['low14']['date'], 'amount' => $cash['low14']['amount'], 'threshold' => $cash['threshold'], 'status' => $cash['status'], 'series' => $cash['series']]],
                $cash['low14']['date'] . ':' . $cash['status']);
        }
        if ($this->cash !== null && !$this->cash->hasBalance() && ($this->cash->hasMovement() || $this->calendar?->findByDate($this->calendar->today()) !== null)) {
            $tiles[] = $this->tile(self::CASH_FIRST_BALANCE, 'first_steps', 20, 'cash_first_balance', $locale, [], ['screen' => 'tresorerie', 'params' => new \stdClass()]);
        }
        $low = $this->lowStock();
        if ($low['items'] !== []) {
            $count = count($low['all']);
            $tiles[] = $this->tile(self::LOW_STOCK, 'alert', 80, 'low_stock', $locale, ['%count%' => $count], ['screen' => 'stock.low', 'params' => ['variantIds' => $low['all']]], [
                ['label' => $this->t('tiles.sources.sold_last_30_days', $locale), 'value' => $low['sold']],
                ['label' => $this->t('tiles.sources.current_stock', $locale), 'value' => $low['stock']],
            ], $low['items'], $low['signature']);
        }
        $trip = $this->trips?->current();
        if ($trip !== null) {
            $follow = $this->trips->follow($trip);
            if ($follow['status'] === 'over') {
                $tiles[] = $this->tile(self::TRIP_BUDGET, 'alert', 75, 'trip_budget', $locale, ['%place%' => $trip->getPlace() ?? $trip->getName(), '%amount%' => self::money($follow['overBy'], $locale, $this->siteCurrency())],
                    ['screen' => 'voyages.suivi', 'params' => ['tripId' => (int) $trip->getId()]],
                    [['label' => $this->t('tiles.sources.trip_spent', $locale), 'value' => $follow['totals']['spent']], ['label' => $this->t('tiles.sources.trip_allowance', $locale), 'value' => $follow['totals']['allowanceToDate']]],
                    [['tripId' => (int) $trip->getId(), 'spent' => $follow['totals']['spent'], 'allowanceToDate' => $follow['totals']['allowanceToDate'], 'budget' => $follow['totals']['budget'], 'overBy' => $follow['overBy']]],
                    'trip:' . $trip->getId());
            }
        }
        $over = $this->budgets?->overCurrent($locale) ?? [];
        if (($over['lines'] ?? []) !== []) {
            $tiles[] = $this->tile(self::BUDGET_OVER, 'alert', 70, 'budget_over', $locale, ['%count%' => count($over['lines'])],
                ['screen' => 'periode.budget', 'params' => ['periodId' => (int) $over['period']['id']]], [],
                array_map(fn ($l) => ['category' => $l['category'], 'label' => $l['label'], 'planned' => $l['planned'], 'actual' => $l['actual']], $over['lines']),
                sha1($over['period']['id'] . ':' . implode(',', array_column($over['lines'], 'category'))));
        }
        $noInvoice = $conn->fetchOne('SELECT 1 FROM commande_line LIMIT 1') === false
            && $conn->fetchOne('SELECT 1 FROM gestion_document WHERE type = ? AND status = ? LIMIT 1', [GestionDocument::TYPE_FACTURE, GestionDocument::STATUS_CONFIRMED]) === false;
        if ($noInvoice) {
            $tiles[] = $this->tile(self::FIRST_INVOICE, 'first_steps', 40, 'first_invoice', $locale, [], ['screen' => 'documents.scan', 'params' => ['type' => 'facture']]);
        }
        if ($conn->fetchOne('SELECT 1 FROM note_de_frais LIMIT 1') === false) {
            $tiles[] = $this->tile(self::FIRST_NOTE, 'first_steps', 30, 'first_note', $locale, [], ['screen' => 'documents.scan', 'params' => ['type' => 'note']]);
        }
        if ($conn->fetchOne('SELECT 1 FROM marketing_campaign LIMIT 1') === false) {
            $tiles[] = $this->tile(self::FIRST_CAMPAIGN, 'first_steps', 10, 'first_campaign', $locale, [], ['screen' => 'campagnes.new', 'params' => new \stdClass()]);
        }

        return $tiles;
    }

    /**
     * Variantes qui se vendent et vont manquer : vendues (nettes des retours) au moins `LOW_STOCK_MIN_SOLD` fois sur les
     * `LOW_STOCK_WINDOW_DAYS` derniers jours, stock < `LOW_STOCK_DAYS` jours de ventes à ce rythme (stock nul ou négatif compris).
     * Seuls les articles en vente comptent : type standard, vente active, pas de location, visibles en boutique ou en caisse
     * (un article créé par une facture reste invisible jusqu'à son activation). Locations, abonnements et lignes libres
     * (cartes-cadeaux, articles divers) exclus : ils n'ont pas de variante ou passent par une réservation.
     *
     * Aussi lue par les notifications (lot N1) avec un seuil plus strict (`$coverageDays` = 7).
     *
     * @return array{items: list<array<string, mixed>>, all: list<int>, sold: int, stock: int, signature: ?string}
     */
    public function lowStock(int $coverageDays = self::LOW_STOCK_DAYS): array
    {
        $conn = $this->emProvider->getEntityManager()->getConnection();
        $from = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('-' . self::LOW_STOCK_WINDOW_DAYS . ' days')->format('Y-m-d H:i:s');
        $rows = $conn->fetchAllAssociative(
            'WITH sold AS ('
            . ' SELECT oi.product_variant_id AS vid, SUM(CASE WHEN o.order_type_id = 2 THEN -oi.quantity ELSE oi.quantity END) AS qty'
            . ' FROM order_items oi JOIN "order" o ON o.id = oi.order_associated_id LEFT JOIN booking b ON b.order_item_id = oi.id'
            . ' WHERE o.order_date >= :from AND o.order_type_id IN (1, 2) AND o.status_id NOT IN (1, 7) AND b.id IS NULL AND o.subscription_id IS NULL AND oi.product_variant_id IS NOT NULL'
            . ' GROUP BY oi.product_variant_id)'
            . ' SELECT p.id AS product_id, pv.id AS variant_id, p.name, sz.name AS size, co.name AS color, COALESCE(pv.stock_quantity, 0) AS stock, s.qty AS sold'
            . ' FROM sold s JOIN product_variant pv ON pv.id = s.vid JOIN product p ON p.id = pv.product_id'
            . ' LEFT JOIN size sz ON sz.id = pv.size_id LEFT JOIN color co ON co.id = pv.color_id'
            . " WHERE s.qty >= :minSold AND p.product_type = 'standard' AND p.sale_enabled AND NOT p.rental_enabled AND (p.is_web OR p.is_pos)"
            . ' AND COALESCE(pv.stock_quantity, 0) * :window < :days * s.qty'
            . ' ORDER BY COALESCE(pv.stock_quantity, 0)::numeric / s.qty, s.qty DESC, pv.id',
            ['from' => $from, 'minSold' => self::LOW_STOCK_MIN_SOLD, 'window' => self::LOW_STOCK_WINDOW_DAYS, 'days' => $coverageDays]
        );
        $items = [];
        $all = [];
        $soldTotal = 0;
        $stockTotal = 0;
        foreach ($rows as $r) {
            $sold = (int) $r['sold'];
            $stock = (int) $r['stock'];
            $all[] = (int) $r['variant_id'];
            $soldTotal += $sold;
            $stockTotal += $stock;
            if (count($items) < self::LOW_STOCK_MAX_FIGURES) {
                $label = trim((string) $r['name'] . ($r['size'] !== null ? ' ' . $r['size'] : '') . ($r['color'] !== null ? ' ' . $r['color'] : ''));
                $items[] = ['productId' => (int) $r['product_id'], 'variantId' => (int) $r['variant_id'], 'name' => $label, 'stock' => $stock, 'soldLast30Days' => $sold, 'daysLeft' => $stock <= 0 ? 0 : (int) floor($stock * self::LOW_STOCK_WINDOW_DAYS / $sold)];
            }
        }
        $sorted = $all;
        sort($sorted);

        return ['items' => $items, 'all' => $all, 'sold' => $soldTotal, 'stock' => $stockTotal, 'signature' => $all === [] ? null : sha1(implode(',', $sorted))];
    }

    /** @param array<string, mixed> $action */
    private function tile(string $id, string $kind, int $priority, string $key, string $locale, array $params, array $action, array $sources = [], array $figures = [], ?string $signature = null): array
    {
        return [
            'id' => $id, 'kind' => $kind, 'priority' => $priority,
            'title' => $this->t("tiles.$key.title", $locale, $params), 'text' => $this->t("tiles.$key.text", $locale, $params),
            'action' => $action, 'sources' => $sources, 'dismissible' => true, 'figures' => $figures, 'signature' => $signature,
        ];
    }

    /** Montant en cents écrit pour un texte (format monétaire de la langue) */
    public static function money(int $cents, string $locale, string $currency): string
    {
        $formatted = (new \NumberFormatter($locale === 'en' ? 'en_CA' : 'fr_CA', \NumberFormatter::CURRENCY))->formatCurrency($cents / 100, $currency);

        return $formatted === false ? number_format($cents / 100, 2) . ' ' . $currency : $formatted;
    }

    /** Date (Y-m-d) écrite pour un texte : « 14 novembre » / « November 14 » */
    public static function day(string $date, string $locale): string
    {
        $formatted = (new \IntlDateFormatter($locale === 'en' ? 'en_CA' : 'fr_CA', \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, 'UTC', null, $locale === 'en' ? 'MMMM d' : 'd MMMM'))->format(new \DateTimeImmutable($date . ' 12:00:00', new \DateTimeZone('UTC')));

        return $formatted === false ? $date : $formatted;
    }

    private function siteCurrency(): string
    {
        return $this->currency?->code() ?? \App\Services\BoutiqueSettingsService\TenantCurrencyProvider::DEFAULT;
    }

    private function t(string $key, string $locale, array $params = []): string
    {
        return $this->translator->trans($key, $params, 'gestion', $locale);
    }
}
