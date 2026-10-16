<?php

namespace App\MemoiresVivantes\Services;

use App\MemoiresVivantes\Repository\PaymentLedgerRepository;

/**
 * Écran « Paiements » de la console d'administration (16/10/2026), lecture seule : tout ce qui a été payé depuis
 * l'application mobile (chapitres par achat intégré, impressions par Stripe) avec les remboursements tels qu'ils
 * arrivent ; les livres payés par lien Stripe seulement si kind=book est demandé. Totaux par devise, jamais
 * additionnés entre devises. Fenêtre : dates du site (minuit à minuit dans son fuseau), 30 derniers jours par défaut.
 */
class PaymentLedgerService
{
    public const DEFAULT_DAYS = 30;
    public const DEFAULT_PER_PAGE = 50;
    public const MAX_PER_PAGE = 500;
    public const STATUSES = ['paid', 'pending', 'refunded'];
    public const STORES = ['app_store', 'play_store', 'stripe'];

    public function __construct(private readonly PaymentLedgerRepository $ledger, private readonly AiQuotaService $quotas)
    {
    }

    /**
     * @param array<string, mixed> $query paramètres de la requête (from, to, kind, status, store, search, page, perPage)
     * @throws \InvalidArgumentException paramètre invalide (message pour le 422)
     */
    public function list(array $query): array
    {
        $tz = $this->quotas->timezone();
        $to = $this->day($query['to'] ?? null, $tz, 'to') ?? (new \DateTimeImmutable('now', $tz))->setTime(0, 0);
        $from = $this->day($query['from'] ?? null, $tz, 'from') ?? $to->modify(sprintf('-%d days', self::DEFAULT_DAYS - 1));
        if ($from > $to) {
            throw new \InvalidArgumentException('from : doit précéder to');
        }
        $kinds = $this->listParam($query['kind'] ?? null, PaymentLedgerRepository::KINDS, 'kind') ?: ['chapter', 'print'];
        $statuses = $this->listParam($query['status'] ?? null, self::STATUSES, 'status');
        $stores = array_map(fn ($s) => $s === 'google_play' ? 'play_store' : $s, $this->listParam($query['store'] ?? null, array_merge(self::STORES, ['google_play']), 'store'));
        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = min(self::MAX_PER_PAGE, max(1, (int) ($query['perPage'] ?? self::DEFAULT_PER_PAGE)));
        $utc = new \DateTimeZone('UTC');
        $rows = $this->ledger->find($from->setTimezone($utc), $to->modify('+1 day')->setTimezone($utc), $kinds, $statuses, $stores, trim((string) ($query['search'] ?? '')));

        $totals = [];
        $counts = ['paid' => 0, 'pending' => 0, 'refunded' => 0];
        foreach ($rows as $r) {
            $currency = (string) $r['currency'];
            $totals[$currency] ??= ['currency' => $currency, 'paid' => 0.0, 'refunded' => 0.0, 'pending' => 0.0, 'net' => 0.0];
            $status = (string) $r['status'];
            $counts[$status] = ($counts[$status] ?? 0) + 1;
            if ($status === 'paid') {
                $totals[$currency]['paid'] += (float) $r['amount'];
            } elseif ($status === 'pending') {
                $totals[$currency]['pending'] += (float) $r['amount'];
            } elseif ($status === 'refunded') {
                $totals[$currency]['paid'] += (float) $r['amount']; // encaissé puis rendu : le net le reflète
                $totals[$currency]['refunded'] += (float) ($r['refunded_amount'] ?? $r['amount']);
            }
        }
        ksort($totals);
        foreach ($totals as &$t) {
            foreach (['paid', 'refunded', 'pending'] as $k) {
                $t[$k] = round($t[$k], 2);
            }
            $t['net'] = round($t['paid'] - $t['refunded'], 2);
        }
        unset($t);

        return [
            'from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d'), 'timezone' => $tz->getName(),
            'page' => $page, 'perPage' => $perPage, 'total' => count($rows),
            'totals' => array_values($totals), 'counts' => $counts,
            'payments' => array_map(fn ($r) => $this->row($r, $tz), array_slice($rows, ($page - 1) * $perPage, $perPage)),
        ];
    }

    /** Même liste en CSV (séparateur « ; », UTF-8 avec BOM, montants en unités avec un point) ; toutes les lignes de la fenêtre */
    public function csv(array $query): string
    {
        $data = $this->list(['perPage' => self::MAX_PER_PAGE, 'page' => 1] + $query);
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, ['kind', 'status', 'amount', 'currency', 'paidAt', 'refundedAt', 'refundedAmount', 'store', 'environment', 'paymentRef', 'userId', 'email', 'name', 'bookId', 'bookTitle', 'bookChannel', 'chapterId', 'chapterTitle', 'printQuantity', 'printOrderId', 'luluStatus'], ';');
        foreach ($data['payments'] as $p) {
            fputcsv($handle, [$p['kind'], $p['status'], $p['amount'], $p['currency'], $p['paidAt'], $p['refundedAt'], $p['refundedAmount'], $p['store'], $p['environment'], $p['paymentRef'],
                $p['user']['userId'], $p['user']['email'], $p['user']['name'], $p['book']['id'] ?? '', $p['book']['title'] ?? '', $p['book']['channel'] ?? '',
                $p['chapter']['id'] ?? '', $p['chapter']['title'] ?? '', $p['print']['quantity'] ?? '', $p['print']['orderId'] ?? '', $p['print']['luluStatus'] ?? ''], ';');
        }
        rewind($handle);

        return (string) stream_get_contents($handle);
    }

    private function row(array $r, \DateTimeZone $tz): array
    {
        $iso = fn ($v) => $v === null ? null : (new \DateTimeImmutable((string) $v, new \DateTimeZone('UTC')))->setTimezone($tz)->format(\DateTimeInterface::ATOM);

        return [
            'id' => (string) $r['id'], 'kind' => (string) $r['kind'], 'status' => (string) $r['status'],
            'amount' => $r['amount'] === null ? null : round((float) $r['amount'], 2), 'currency' => (string) $r['currency'],
            'paidAt' => $iso($r['paid_at']), 'refundedAt' => $iso($r['refunded_at']), 'refundedAmount' => $r['refunded_amount'] === null ? null : round((float) $r['refunded_amount'], 2),
            'store' => $r['store'], 'paymentRef' => $r['payment_ref'], 'environment' => $r['environment'],
            'user' => ['userId' => (int) $r['user_id'], 'email' => (string) $r['email'], 'name' => trim((string) $r['firstname'] . ' ' . (string) $r['lastname'])],
            'book' => $r['book_id'] === null ? null : ['id' => (string) $r['book_id'], 'title' => $r['book_title'], 'channel' => $r['book_channel']],
            'chapter' => $r['kind'] === 'chapter' && $r['chapter_id'] !== null ? ['id' => (string) $r['chapter_id'], 'title' => $r['chapter_title']] : null,
            'print' => $r['kind'] === 'print' ? ['quantity' => (int) $r['print_quantity'], 'orderId' => (string) $r['print_order_id'], 'luluStatus' => $r['lulu_status']] : null,
        ];
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

    /** @return list<string> valeurs séparées par des virgules, chacune dans la liste permise */
    private function listParam(mixed $value, array $allowed, string $name): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        $values = array_values(array_unique(array_filter(array_map('trim', explode(',', strtolower((string) $value))))));
        foreach ($values as $v) {
            if (!in_array($v, $allowed, true)) {
                throw new \InvalidArgumentException(sprintf('%s : valeur inconnue « %s » (%s)', $name, $v, implode(', ', $allowed)));
            }
        }

        return $values;
    }
}
