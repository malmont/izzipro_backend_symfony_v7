<?php

namespace App\Services\GestionService;

use App\Entity\MarketingCampaign;
use App\Services\BoutiqueSettingsService\TenantCurrencyProvider;
use App\Services\CaisseService\StoreClock;
use App\Services\TenantEntityManagerProvider;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Mesure après application d'un conseil du guide (lot G1b-1, 11/11/2026, `docs/guide-ia-gestion.md` § 8) : ventes et marge des articles du
 * conseil sur les 14 jours à partir de la date d'application (coupés à hier) contre autant de jours juste avant, comparées aux mêmes fenêtres
 * l'an dernier (saison) ; relance de publicité : rentabilité A1 de la campagne créée. Mêmes ventes que le résultat de période (hors taxes,
 * nettes des retours, coût inconnu hors marge). Mesurables : FAST_SELLER, SLOW_STOCK, END_CLEARANCE, AD_RELAUNCH (avec la campagne créée).
 */
class AdviceMeasure
{
    public const WINDOW_DAYS = 14;
    public const MIN_DAYS = 7;
    public const PRODUCT_KINDS = ['FAST_SELLER', 'SLOW_STOCK', 'END_CLEARANCE'];
    /** Lot LOC2 : conseils de location, mesurés sur le revenu de location de l'article par date de location (LOC1) */
    public const RENTAL_KINDS = ['RENTAL_PEAK_DAYS', 'RENTAL_LOW_WEEKDAYS', 'RENTAL_IDLE'];
    public const SEASONAL_TOLERANCE = 0.5;
    public const RESULT_TILE_DAYS = 30; // vignette advice-result : mesures complètes depuis moins de 30 jours

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly PeriodResultCalculator $results,
        private readonly CampaignProfitabilityCalculator $campaigns,
        private readonly PeriodCalendar $calendar,
        private readonly StoreClock $clock,
        private readonly TranslatorInterface $translator,
        private readonly ?TenantCurrencyProvider $currency = null,
        private readonly ?GestionRentalStatisticsService $rentals = null, // lot LOC2
    ) {
    }

    /**
     * Mesure d'un conseil fait (ligne de `gestion_advice`).
     *
     * @return array<string, mixed>
     */
    public function measure(array $row, string $locale, ?\DateTimeImmutable $today = null): array
    {
        $today ??= $this->calendar->today();
        $applied = json_decode((string) ($row['applied'] ?? 'null'), true) ?: [];
        $subject = json_decode((string) $row['subject'], true) ?: [];
        $appliedOn = $row['applied_on'] !== null ? substr((string) $row['applied_on'], 0, 10)
            : ($row['done_at'] !== null ? $this->clock->day(new \DateTimeImmutable((string) $row['done_at'], new \DateTimeZone('UTC'))) : $today->format('Y-m-d'));
        $kind = (string) $row['kind'];
        $base = ['adviceId' => (int) $row['id'], 'kind' => $kind, 'appliedOn' => $appliedOn, 'applied' => $applied === [] ? null : $applied, 'estimatedEffect' => (int) $row['effect_cents']];
        $campaignId = isset($applied['campaignId']) ? (int) $applied['campaignId'] : null;
        $rental = in_array($kind, self::RENTAL_KINDS, true) && $this->rentals !== null;
        $measurable = in_array($kind, self::PRODUCT_KINDS, true) || $rental || ($kind === 'AD_RELAUNCH' && $campaignId !== null);
        if (!$measurable) {
            return $base + ['measurable' => false];
        }
        if ($kind === 'AD_RELAUNCH') {
            return $base + $this->campaign($campaignId, $appliedOn, $locale);
        }

        $productIds = array_values(array_map('intval', $applied['productIds'] ?? ($subject['productIds'] ?? [])));
        $yesterday = $today->modify('-1 day')->format('Y-m-d');
        $afterFrom = $appliedOn;
        $afterTo = min((new \DateTimeImmutable($appliedOn))->modify('+' . (self::WINDOW_DAYS - 1) . ' days')->format('Y-m-d'), $yesterday);
        $days = $afterTo < $afterFrom ? 0 : AdviceRules::days($afterFrom, $afterTo);
        $beforeTo = (new \DateTimeImmutable($appliedOn))->modify('-1 day')->format('Y-m-d');
        $beforeFrom = (new \DateTimeImmutable($appliedOn))->modify('-' . max(1, $days) . ' days')->format('Y-m-d');
        $metrics = $rental ? fn (string $from, string $to) => $this->rentalMetrics($from, $to, $productIds) : fn (string $from, string $to) => $this->metrics($from, $to, $productIds);
        $before = $days > 0 ? $metrics($beforeFrom, $beforeTo) : self::zero();
        $after = $days > 0 ? $metrics($afterFrom, $afterTo) : self::zero();
        $change = self::diff($after, $before);
        $ly = null;
        $caveats = [];
        if ($days > 0) {
            $shift = fn (string $d) => (new \DateTimeImmutable($d))->modify('-1 year')->format('Y-m-d');
            $lyBefore = $metrics($shift($beforeFrom), $shift($beforeTo));
            $lyAfter = $metrics($shift($afterFrom), $shift($afterTo));
            $ly = ['before' => $lyBefore, 'after' => $lyAfter, 'change' => self::diff($lyAfter, $lyBefore)];
            if (self::seasonal($before['revenue'], $after['revenue'], $lyBefore['revenue'], $lyAfter['revenue'])) {
                $caveats[] = 'SEASONAL';
            }
        }
        $complete = $days >= self::WINDOW_DAYS;
        if ($days < self::MIN_DAYS) {
            $caveats[] = 'TOO_EARLY';
        }
        $confidence = $caveats === [] ? ($complete ? 'medium' : 'low') : 'low';
        $out = $base + [
            'measurable' => true,
            'window' => ['days' => $days, 'before' => ['from' => $beforeFrom, 'to' => $beforeTo], 'after' => ['from' => $afterFrom, 'to' => $afterTo], 'complete' => $complete],
            'scope' => ['kind' => 'products', 'productIds' => $productIds],
            'before' => $before, 'after' => $after, 'change' => $change, 'lastYear' => $ly,
            'confidence' => $confidence,
        ];
        $out['caveats'] = array_map(fn ($c) => ['code' => $c, 'text' => $this->translator->trans("measure.caveats.$c", ['%days%' => $days], 'gestion', $locale)], $caveats);
        $out['text'] = $this->text($kind, $applied, $row, $out, $locale);

        return $out;
    }

    /** Résumé pour la liste des conseils : null si non mesurable */
    public function summary(array $row, string $locale, ?\DateTimeImmutable $today = null): ?array
    {
        if ($row['status'] !== 'done') {
            return null;
        }
        $m = $this->measure($row, $locale, $today);

        return $m['measurable'] ? ['complete' => $m['window']['complete'], 'change' => ['grossMargin' => $m['change']['grossMargin'], 'revenue' => $m['change']['revenue']], 'text' => $m['text']] : null;
    }

    // ----- interne -----

    private function campaign(int $campaignId, string $appliedOn, string $locale): array
    {
        $campaign = $this->emProvider->getEntityManager()->find(MarketingCampaign::class, $campaignId);
        if ($campaign === null) {
            return ['measurable' => false];
        }
        $p = $this->campaigns->compute($campaign);
        $pick = fn (array $m) => ['revenue' => (int) $m['revenue'], 'grossMargin' => (int) $m['grossMargin'], 'units' => (int) ($m['items'] ?? 0)];
        $before = $pick($p['before']);
        $after = $pick($p['during']);
        $complete = (bool) $p['window']['complete'];
        $caveats = array_map(fn ($t) => ['code' => 'CAMPAIGN', 'text' => $t], $p['caveats']);
        if ((int) $p['window']['days'] < self::MIN_DAYS) {
            $caveats[] = ['code' => 'TOO_EARLY', 'text' => $this->translator->trans('measure.caveats.TOO_EARLY', ['%days%' => (int) $p['window']['days']], 'gestion', $locale)];
        }
        $change = self::diff($after, $before);
        $out = [
            'measurable' => true,
            'window' => ['days' => (int) $p['window']['days'], 'before' => ['from' => $p['reference']['from'], 'to' => $p['reference']['to']], 'after' => ['from' => $p['window']['from'], 'to' => $p['window']['to']], 'complete' => $complete],
            'scope' => ['kind' => 'campaign', 'campaignId' => $campaignId, 'productIds' => $p['scope']['productIds']],
            'before' => $before, 'after' => $after, 'change' => $change, 'lastYear' => null,
            'gainPerDollar' => $p['gainPerDollar'],
            'confidence' => $caveats === [] && $complete ? 'medium' : 'low', 'caveats' => $caveats,
        ];
        $out['text'] = $this->translator->trans('measure.texts.campaign', ['%date%' => HomeTilesService::day($appliedOn, $locale), '%change%' => $this->signed($change['grossMargin'], $locale), '%days%' => $out['window']['days']], 'gestion', $locale);

        return $out;
    }

    /** Texte de repli : prix, promotion (ventes obtenues quand il n'y avait aucune vente avant), trop tôt */
    private function text(string $kind, array $applied, array $row, array $m, string $locale): string
    {
        $params = json_decode((string) $row['params'], true) ?: [];
        $p = [
            '%date%' => HomeTilesService::day($m['appliedOn'], $locale), '%days%' => $m['window']['days'], '%change%' => $this->signed($m['change']['grossMargin'], $locale),
            '%estimated%' => $this->signed((int) $row['effect_cents'], $locale), '%units%' => $m['after']['units'], '%revenue%' => HomeTilesService::money($m['after']['revenue'], $locale, $this->code()),
            '%margin%' => HomeTilesService::money($m['after']['grossMargin'], $locale, $this->code()), '%name%' => (string) ($params['name'] ?? ''),
        ];
        if ($m['window']['days'] < self::MIN_DAYS) {
            return $this->translator->trans('measure.texts.too_early', $p, 'gestion', $locale);
        }
        if (in_array($kind, self::RENTAL_KINDS, true)) {
            $p['%change%'] = $this->signed($m['change']['revenue'], $locale);

            return $this->translator->trans('measure.texts.rental', $p, 'gestion', $locale);
        }
        if ($kind === 'FAST_SELLER') {
            $p['%percent%'] = $this->percent((float) ($applied['pricePercent'] ?? AdviceRules::FAST_PRICE_PERCENT), $locale);

            return $this->translator->trans('measure.texts.price', $p, 'gestion', $locale);
        }
        $p['%percent%'] = $this->percent((float) ($applied['promotionPercent'] ?? AdviceRules::PROMOTION_PERCENT), $locale);
        if ($m['before']['units'] <= 0) {
            return $this->translator->trans($p['%name%'] !== '' ? 'measure.texts.promotion_sales_named' : 'measure.texts.promotion_sales', $p, 'gestion', $locale);
        }

        return $this->translator->trans('measure.texts.promotion', $p, 'gestion', $locale);
    }

    /** @return array{revenue: int, grossMargin: int, units: int} */
    private function metrics(string $from, string $to, array $productIds): array
    {
        $m = $this->results->salesMetrics(new \DateTimeImmutable($from), new \DateTimeImmutable($to), $productIds);

        return ['revenue' => (int) $m['revenue'], 'grossMargin' => (int) $m['grossMargin'], 'units' => (int) $m['items']];
    }

    /** Lot LOC2 : revenu de location par date de location (sans coût : marge = revenu), locations commencées */
    private function rentalMetrics(string $from, string $to, array $productIds): array
    {
        $s = $this->rentals->compute($from, $to, $productIds === [] ? null : $productIds);
        $revenue = (int) array_sum(array_column($s['products'], 'revenueByRentalDate'));

        return ['revenue' => $revenue, 'grossMargin' => $revenue, 'units' => (int) array_sum(array_column($s['products'], 'rentals'))];
    }

    private static function zero(): array
    {
        return ['revenue' => 0, 'grossMargin' => 0, 'units' => 0];
    }

    private static function diff(array $a, array $b): array
    {
        return ['revenue' => $a['revenue'] - $b['revenue'], 'grossMargin' => $a['grossMargin'] - $b['grossMargin'], 'units' => $a['units'] - $b['units']];
    }

    /**
     * Saison : l'an dernier, sans aucun conseil, les ventes des mêmes fenêtres ont varié dans le même sens et d'une ampleur comparable
     * (± 50 % de la variation relative de cette année) : une bonne partie de l'écart tient sans doute à la saison.
     */
    public static function seasonal(int $before, int $after, int $lyBefore, int $lyAfter): bool
    {
        if ($before <= 0 || $lyBefore <= 0) {
            return false;
        }
        $now = $after / $before - 1;
        $last = $lyAfter / $lyBefore - 1;
        if ($now == 0.0 || $last == 0.0 || ($now > 0) !== ($last > 0)) {
            return false;
        }

        return abs($last) >= abs($now) * (1 - self::SEASONAL_TOLERANCE) && abs($last) <= abs($now) * (1 + self::SEASONAL_TOLERANCE);
    }

    private function signed(int $cents, string $locale): string
    {
        return ($cents > 0 ? '+' : ($cents < 0 ? '−' : '')) . HomeTilesService::money(abs($cents), $locale, $this->code());
    }

    private function percent(float $value, string $locale): string
    {
        $n = (new \NumberFormatter($locale === 'en' ? 'en_CA' : 'fr_CA', \NumberFormatter::DECIMAL))->format(round($value, 1)) ?: (string) $value;

        return $n . ($locale === 'en' ? '%' : "\u{00A0}%");
    }

    private function code(): string
    {
        return $this->currency?->code() ?? TenantCurrencyProvider::DEFAULT;
    }
}
