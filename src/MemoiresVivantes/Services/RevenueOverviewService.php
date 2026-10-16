<?php

namespace App\MemoiresVivantes\Services;

use App\MemoiresVivantes\Repository\PaymentLedgerRepository;

/**
 * Vue d'ensemble par livre pour la console (16/10/2026), lecture seule : TOUS les livres, web et mobile, avec le
 * livre payé par lien Stripe, les achats de chapitre, les impressions, les remboursements et le net, par devise
 * (jamais additionnées). La période s'applique aux dates de paiement ; sans période : tout. Tri : net décroissant
 * (devise par défaut du site d'abord) puis création décroissante. Aucun lien « biographe » n'existe en base :
 * `biographer` est toujours null.
 */
class RevenueOverviewService
{
    public const DEFAULT_PER_PAGE = 50;
    public const MAX_PER_PAGE = 500;

    public function __construct(private readonly PaymentLedgerRepository $ledger, private readonly AiQuotaService $quotas, private readonly PricingService $pricing)
    {
    }

    /**
     * @param array<string, mixed> $query from, to, channel, search, page, perPage
     * @throws \InvalidArgumentException paramètre invalide
     */
    public function overview(array $query): array
    {
        $tz = $this->quotas->timezone();
        $from = $this->day($query['from'] ?? null, $tz, 'from');
        $to = $this->day($query['to'] ?? null, $tz, 'to');
        if ($from !== null && $to !== null && $from > $to) {
            throw new \InvalidArgumentException('from : doit précéder to');
        }
        $channels = [];
        foreach (array_filter(array_map('trim', explode(',', strtolower((string) ($query['channel'] ?? ''))))) as $c) {
            if (!in_array($c, ['web', 'mobile'], true)) {
                throw new \InvalidArgumentException(sprintf('channel : valeur inconnue « %s » (web, mobile)', $c));
            }
            $channels[] = $c;
        }
        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = min(self::MAX_PER_PAGE, max(1, (int) ($query['perPage'] ?? self::DEFAULT_PER_PAGE)));
        $utc = new \DateTimeZone('UTC');
        $fromUtc = $from?->setTimezone($utc);
        $toUtc = $to?->modify('+1 day')->setTimezone($utc);
        $defaultCurrency = $this->pricing->settings()->defaultCurrency();

        $chapterRows = [];
        foreach ($this->ledger->chapterRevenueByBook($fromUtc, $toUtc) as $r) {
            $chapterRows[(string) $r['book_id']][] = $r;
        }
        $printRows = [];
        foreach ($this->ledger->printRevenueByBook($fromUtc, $toUtc) as $r) {
            $printRows[(string) $r['book_id']][] = $r;
        }

        $books = [];
        $summary = ['books' => ['total' => 0, 'web' => 0, 'mobile' => 0], 'byChannel' => [], 'totals' => []];
        $channelTotals = ['web' => [], 'mobile' => []];
        $channelCounts = ['web' => $this->emptyCounts(), 'mobile' => $this->emptyCounts()];
        foreach ($this->ledger->books($channels, trim((string) ($query['search'] ?? ''))) as $b) {
            $id = (string) $b['id'];
            $channel = (string) $b['channel'];
            $summary['books']['total']++;
            $summary['books'][$channel] = ($summary['books'][$channel] ?? 0) + 1;
            $net = [];
            $add = function (string $currency, string $key, float $amount) use (&$net, &$channelTotals, $channel): void {
                if (abs($amount) < 0.005) {
                    return; // une commande web sans paiement, un achat sans montant : pas de devise à zéro
                }
                $net[$currency] ??= 0.0;
                $channelTotals[$channel][$currency] ??= ['currency' => $currency, 'book' => 0.0, 'chapters' => 0.0, 'prints' => 0.0, 'refunded' => 0.0, 'net' => 0.0];
                $channelTotals[$channel][$currency][$key] += $amount;
                $net[$currency] += $key === 'refunded' ? -$amount : $amount;
            };

            // Livre payé par lien Stripe : état courant de mv_book, compté dans la période par sa date de paiement
            $bookPayment = null;
            if (($b['payment_status'] ?? 'unpaid') !== 'unpaid') {
                $bookPayment = ['status' => (string) $b['payment_status'], 'amount' => $b['payment_amount'] === null ? null : round((float) $b['payment_amount'], 2), 'currency' => strtolower((string) ($b['payment_currency'] ?: 'eur')), 'paidAt' => $this->iso($b['paid_at'], $tz)];
                if ($b['payment_status'] === 'paid' && $b['payment_amount'] !== null && $this->inWindow($b['paid_at'], $fromUtc, $toUtc)) {
                    $add($bookPayment['currency'], 'book', (float) $b['payment_amount']);
                    $channelCounts[$channel]['booksPaid']++;
                }
            }
            $chaptersRevenue = [];
            foreach ($chapterRows[$id] ?? [] as $r) {
                $chaptersRevenue[] = ['currency' => (string) $r['currency'], 'paid' => round((float) $r['paid'], 2), 'refunded' => round((float) $r['refunded'], 2)];
                $add((string) $r['currency'], 'chapters', (float) $r['paid']);
                $add((string) $r['currency'], 'refunded', (float) $r['refunded']);
                $channelCounts[$channel]['chaptersPaid'] += (int) $r['paid_count'];
                $channelCounts[$channel]['refunds'] += (int) $r['refunded_count'];
            }
            $prints = ['orders' => 0, 'copies' => 0, 'revenue' => []];
            foreach ($printRows[$id] ?? [] as $r) {
                $prints['orders'] += (int) $r['orders'];
                $prints['copies'] += (int) $r['copies'];
                if ((float) $r['paid'] > 0 || (float) $r['pending'] > 0 || (float) $r['refunded'] > 0) {
                    $prints['revenue'][] = ['currency' => (string) $r['currency'], 'paid' => round((float) $r['paid'], 2), 'pending' => round((float) $r['pending'], 2), 'refunded' => round((float) $r['refunded'], 2)];
                }
                $add((string) $r['currency'], 'prints', (float) $r['paid']);
                $add((string) $r['currency'], 'refunded', (float) $r['refunded']);
                $channelCounts[$channel]['printsPaid'] += (int) $r['paid_count'];
                $channelCounts[$channel]['refunds'] += (int) $r['refunded_count'];
            }
            ksort($net);
            $books[] = [
                'id' => $id, 'title' => $b['title'], 'type' => $b['type'], 'channel' => $channel, 'createdAt' => $this->iso($b['created_at'], $tz),
                'owner' => ['userId' => (int) $b['user_id'], 'email' => (string) $b['email'], 'name' => trim((string) $b['firstname'] . ' ' . (string) $b['lastname'])],
                'biographer' => null,
                'chapters' => ['total' => (int) $b['chapters_total'], 'written' => (int) $b['chapters_written'], 'paid' => (int) $b['chapters_paid']],
                'bookPayment' => $bookPayment,
                'chaptersRevenue' => $chaptersRevenue,
                'prints' => $prints,
                'net' => array_map(fn ($currency, $amount) => ['currency' => $currency, 'amount' => round($amount, 2)], array_keys($net), $net),
                '_sort' => [round($net[$defaultCurrency] ?? (count($net) ? max($net) : 0.0), 2), (string) $b['created_at']],
            ];
        }
        usort($books, fn ($a, $b) => [$b['_sort'][0], $b['_sort'][1]] <=> [$a['_sort'][0], $a['_sort'][1]]);
        foreach ($books as &$book) {
            unset($book['_sort']);
        }
        unset($book);

        $overall = [];
        foreach (['web', 'mobile'] as $channel) {
            if ($channels !== [] && !in_array($channel, $channels, true)) {
                continue;
            }
            $totals = $this->roundTotals($channelTotals[$channel]);
            $summary['byChannel'][] = ['channel' => $channel, 'totals' => $totals, 'counts' => $channelCounts[$channel]];
            foreach ($totals as $t) {
                $overall[$t['currency']] ??= ['currency' => $t['currency'], 'book' => 0.0, 'chapters' => 0.0, 'prints' => 0.0, 'refunded' => 0.0, 'net' => 0.0];
                foreach (['book', 'chapters', 'prints', 'refunded'] as $k) {
                    $overall[$t['currency']][$k] += $t[$k];
                }
            }
        }
        $summary['totals'] = $this->roundTotals($overall);

        return [
            'from' => $from?->format('Y-m-d'), 'to' => $to?->format('Y-m-d'), 'timezone' => $tz->getName(),
            'summary' => $summary,
            'page' => $page, 'perPage' => $perPage, 'total' => count($books),
            'books' => array_slice($books, ($page - 1) * $perPage, $perPage),
        ];
    }

    /** @return array{booksPaid: int, chaptersPaid: int, printsPaid: int, refunds: int} */
    private function emptyCounts(): array
    {
        return ['booksPaid' => 0, 'chaptersPaid' => 0, 'printsPaid' => 0, 'refunds' => 0];
    }

    /** @param array<string, array> $totals */
    private function roundTotals(array $totals): array
    {
        ksort($totals);
        $out = [];
        foreach ($totals as $t) {
            foreach (['book', 'chapters', 'prints', 'refunded'] as $k) {
                $t[$k] = round($t[$k], 2);
            }
            $t['net'] = round($t['book'] + $t['chapters'] + $t['prints'] - $t['refunded'], 2);
            $out[] = $t;
        }

        return $out;
    }

    private function inWindow(?string $date, ?\DateTimeImmutable $from, ?\DateTimeImmutable $to): bool
    {
        if ($date === null) {
            return false;
        }
        $at = new \DateTimeImmutable($date, new \DateTimeZone('UTC'));

        return ($from === null || $at >= $from) && ($to === null || $at < $to);
    }

    private function iso(?string $value, \DateTimeZone $tz): ?string
    {
        return $value === null ? null : (new \DateTimeImmutable($value, new \DateTimeZone('UTC')))->setTimezone($tz)->format(\DateTimeInterface::ATOM);
    }

    private function day(mixed $value, \DateTimeZone $tz, string $name): ?\DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            throw new \InvalidArgumentException($name . ' : format AAAA-MM-JJ attendu (jour existant)');
        }

        return \DateTimeImmutable::createFromFormat('!Y-m-d', $value, $tz);
    }
}
