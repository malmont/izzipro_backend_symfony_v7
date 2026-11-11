<?php

namespace App\Gestion\Services;

use App\Services\CaisseService\StoreClock;
use App\Services\LandingContentService\LandingContentException;
use App\Services\TenantEntityManagerProvider;

/**
 * Promotions datées posées par la gestion (lot P5, 11/11/2026, `docs/promotions-gestion.md`) : le mécanisme existant de l'article
 * (`special_price`, `special_price_from`, `special_price_to`, appliqué partout par `Product::getEffectivePrice` : boutique, panier, caisse en ligne,
 * favoris, pack hors ligne ; fin automatique par les dates, prix normal jamais touché), écrit par le chemin d'un article
 * (`GestionPriceService::writeFields`). Bornes = début et fin des jours du magasin, converties en UTC (les instants sont comparés en UTC).
 * Une seule promotion par article ; les variantes à prix propre n'en profitent pas (alerte VARIANT_OWN_PRICE).
 */
class GestionPromotionService
{
    public const ROUNDINGS = ['none', 'ends_99', 'down_50', 'down_100'];
    public const PERCENT_RANGE = [5, 90];
    public const MAX_DAYS = 90;
    public const BIG_CHANGE = 50;

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly GestionPriceService $prices,
        private readonly GestionAdviceService $advices,
        private readonly PeriodCalendar $calendar,
        private readonly StoreClock $clock,
    ) {
    }

    /** @return array<string, mixed> { rows, summary, period, pricesIncludeTax, previewHash, caveats } */
    public function preview(array $body): array
    {
        $in = $this->input($body);
        [$products] = $this->prices->scope($in);
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $rows = array_map(fn ($p) => $this->row($p, $in, $now), $products);
        $changed = count(array_filter($rows, fn ($r) => $r['changed']));
        $includesTax = $this->conn()->fetchOne('SELECT prices_include_tax FROM entreprise ORDER BY id LIMIT 1');
        $includesTax = $includesTax !== false && in_array($includesTax, [true, 't', 1, '1'], true);

        return [
            'rows' => $rows,
            'summary' => ['products' => count($products), 'changed' => $changed, 'skipped' => count($rows) - $changed],
            'period' => ['startDate' => $in['startDate'], 'endDate' => $in['endDate'], 'startsAt' => $in['startsAt'], 'endsAt' => $in['endsAt'], 'days' => $in['days']],
            'pricesIncludeTax' => $includesTax,
            'previewHash' => hash('sha256', (string) json_encode([$in, array_map(fn ($r) => [$r['productId'], $r['regularPrice'], $r['current'], $r['promoPrice'], $r['alerts']], $rows)])),
            'caveats' => $includesTax ? [['code' => 'PRICES_INCLUDE_TAX', 'text' => 'Les prix du site sont taxes comprises : la marge affichée comprend la taxe.']] : [],
        ];
    }

    /** @return array<string, mixed> { batchId, changed, rows, advice } */
    public function apply(array $body, ?string $user): array
    {
        $preview = $this->preview($body);
        $hash = $body['previewHash'] ?? null;
        if (!is_string($hash) || $hash === '') {
            throw new GestionValidationException(['previewHash' => 'REQUIRED']);
        }
        if (!hash_equals($preview['previewHash'], $hash)) {
            throw new GestionConflictException('PRICES_CHANGED', 'Des prix ou des promotions ont changé depuis l\'aperçu : voici l\'aperçu à jour.', 409, ['preview' => $preview]);
        }
        $allow = ['BELOW_COST' => ($body['allowBelowCost'] ?? false) === true, 'BIG_CHANGE' => ($body['allowBigChange'] ?? false) === true, 'PROMOTION_EXISTS' => ($body['allowReplace'] ?? false) === true];
        $write = array_values(array_filter($preview['rows'], function ($r) use ($allow): bool {
            if (!$r['changed']) {
                return false;
            }
            foreach ($allow as $alert => $ok) {
                if (!$ok && in_array($alert, $r['alerts'], true)) {
                    return false;
                }
            }

            return true;
        }));
        if ($write === []) {
            throw new GestionConflictException('NOTHING_TO_APPLY', 'Aucune promotion à poser (inchangées, sous le coût, grande remise ou promotion existante sans accord).', 422, ['preview' => $preview]);
        }
        $in = $this->input($body);
        $conn = $this->conn();
        $conn->beginTransaction();
        try {
            $batchId = (int) $conn->fetchOne(
                'INSERT INTO gestion_promotion_batch (body, percent, rounding, start_date, end_date, starts_at, ends_at, advice_id, changed, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW()) RETURNING id',
                [json_encode(array_diff_key($body, ['previewHash' => 1])), $in['percent'], $in['rounding'], $in['startDate'], $in['endDate'], self::sql($in['startsAt']), self::sql($in['endsAt']), $in['adviceId'], count($write), $user]
            );
            foreach ($write as $r) {
                $old = $r['current'];
                $this->prices->writeFields('products', $r['productId'], ['specialPrice' => $r['promoPrice'], 'specialPriceFrom' => $in['startsAt'], 'specialPriceTo' => $in['endsAt']]);
                $conn->executeStatement(
                    'INSERT INTO gestion_promotion_change (batch_id, product_id, old_special_price, old_from, old_to, new_special_price, new_from, new_to) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                    [$batchId, $r['productId'], $old['specialPrice'] ?? null, isset($old['from']) ? self::sql($old['from']) : null, isset($old['to']) ? self::sql($old['to']) : null,
                        $r['promoPrice'], self::sql($in['startsAt']), self::sql($in['endsAt'])]
                );
            }
            $advice = null;
            if ($in['adviceId'] !== null && ($body['markAdviceDone'] ?? true) !== false) {
                $advice = $this->advices->markDoneByApplication($in['adviceId'], $user, ['promotionPercent' => $in['percent'], 'productIds' => array_column($write, 'productId')], $in['startDate'], $this->advices->siteLanguage());
                if ($advice !== null) {
                    $conn->executeStatement('UPDATE gestion_promotion_batch SET advice_marked = TRUE WHERE id = ?', [$batchId]);
                }
            }
            $conn->commit();
        } catch (\Throwable $e) {
            if ($conn->isTransactionActive()) {
                $conn->rollBack();
            }
            $this->emProvider->getEntityManager()->clear();
            throw $e instanceof LandingContentException ? new GestionConflictException('PRICE_REFUSED', $e->getMessage(), 422) : $e;
        }

        return ['batchId' => $batchId, 'changed' => count($write), 'rows' => $write, 'advice' => $advice];
    }

    /**
     * Terminer maintenant : promotion pas encore commencée → l'ancien état revient ; commencée → fin à maintenant ; déjà finie → rien.
     * Tout ou rien (409 PROMOTION_EDITED_SINCE si un article a été retouché depuis), ou seulement les articles intacts (`onlyUntouched`).
     *
     * @return array<string, mixed>
     */
    public function end(mixed $batchId, bool $onlyUntouched, ?string $user): array
    {
        if (!is_int($batchId)) {
            throw new GestionValidationException(['batchId' => 'REQUIRED']);
        }
        $conn = $this->conn();
        $batch = $conn->fetchAssociative('SELECT * FROM gestion_promotion_batch WHERE id = ?', [$batchId]);
        if ($batch === false) {
            throw new GestionConflictException('BATCH_NOT_FOUND', 'Lot de promotions introuvable.', 404, ['batchId' => $batchId]);
        }
        if ($batch['ended_at'] !== null) {
            throw new GestionConflictException('ALREADY_ENDED', 'Ce lot de promotions est déjà terminé.', 409, ['batchId' => $batchId]);
        }
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $nowSql = $now->format('Y-m-d H:i:s');
        $restore = [];
        $stop = [];
        $over = [];
        $touched = [];
        foreach ($conn->fetchAllAssociative('SELECT c.*, p.special_price, p.special_price_from, p.special_price_to FROM gestion_promotion_change c JOIN product p ON p.id = c.product_id WHERE c.batch_id = ? ORDER BY c.id', [$batchId]) as $c) {
            $row = ['productId' => (int) $c['product_id'], 'promoPrice' => (int) $c['new_special_price'], 'startsAt' => self::iso($c['new_from']), 'endsAt' => self::iso($c['new_to'])];
            $intact = $c['special_price'] !== null && (int) round((float) $c['special_price']) === (int) $c['new_special_price']
                && self::sameInstant($c['special_price_from'], $c['new_from']) && self::sameInstant($c['special_price_to'], $c['new_to']);
            if (!$intact) {
                $touched[] = $row + ['current' => ['specialPrice' => $c['special_price'] === null ? null : (int) round((float) $c['special_price']), 'from' => self::iso($c['special_price_from']), 'to' => self::iso($c['special_price_to'])]];
            } elseif ($nowSql < (string) $c['new_from']) {
                $restore[] = $row + ['old' => ['specialPrice' => $c['old_special_price'] === null ? null : (int) $c['old_special_price'], 'from' => self::iso($c['old_from']), 'to' => self::iso($c['old_to'])]];
            } elseif ($nowSql <= (string) $c['new_to']) {
                $stop[] = $row;
            } else {
                $over[] = $row;
            }
        }
        if ($touched !== [] && !$onlyUntouched) {
            throw new GestionConflictException('PROMOTION_EDITED_SINCE', 'Des promotions ont été retouchées depuis : rien n\'est terminé (onlyUntouched pour terminer le reste).', 409, ['rows' => $touched]);
        }
        $conn->beginTransaction();
        try {
            foreach ($restore as $r) {
                $this->prices->writeFields('products', $r['productId'], ['specialPrice' => $r['old']['specialPrice'], 'specialPriceFrom' => $r['old']['from'], 'specialPriceTo' => $r['old']['to']]);
            }
            foreach ($stop as $r) {
                $this->prices->writeFields('products', $r['productId'], ['specialPriceTo' => $now->format(\DateTimeInterface::ATOM)]);
            }
            $conn->executeStatement('UPDATE gestion_promotion_batch SET ended_at = NOW(), ended_by = ?, end_skipped = ? WHERE id = ?', [$user, $touched === [] ? null : json_encode($touched), $batchId]);
            $adviceRestored = false;
            if ($batch['advice_marked'] && $batch['advice_id'] !== null && $stop === [] && $over === []) { // jamais commencée : le conseil n'a pas été appliqué
                $adviceRestored = $conn->executeStatement("UPDATE gestion_advice SET status = 'new', status_by = NULL, status_note = NULL, done_at = NULL, applied = NULL, applied_on = NULL, updated_at = NOW() WHERE id = ? AND status = 'done'", [$batch['advice_id']]) > 0;
            }
            $conn->commit();
        } catch (\Throwable $e) {
            if ($conn->isTransactionActive()) {
                $conn->rollBack();
            }
            $this->emProvider->getEntityManager()->clear();
            throw $e;
        }

        return ['batchId' => $batchId, 'restored' => $restore, 'stopped' => $stop, 'over' => $over, 'skipped' => $touched, 'adviceRestored' => $adviceRestored];
    }

    /**
     * Articles en promotion en cours ou à venir, lus sur l'article (quelle que soit l'origine, EasyAdmin compris), avec le lot P5 qui l'a posée.
     *
     * @return list<array<string, mixed>>
     */
    public function current(string $status): array
    {
        if (!in_array($status, ['active', 'planned', 'all'], true)) {
            throw new GestionValidationException(['status' => 'INVALID_STATUS']);
        }
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $rows = $this->conn()->fetchAllAssociative(
            'SELECT p.id, p.name, p.price, p.special_price, p.special_price_from, p.special_price_to,'
            . ' (SELECT c.batch_id FROM gestion_promotion_change c JOIN gestion_promotion_batch b ON b.id = c.batch_id WHERE c.product_id = p.id AND b.ended_at IS NULL'
            . '  AND c.new_special_price = ROUND(p.special_price) AND c.new_from = p.special_price_from AND c.new_to = p.special_price_to ORDER BY c.id DESC LIMIT 1) AS batch_id'
            . ' FROM product p WHERE p.special_price > 0 AND (p.special_price_to IS NULL OR p.special_price_to >= :now) ORDER BY p.special_price_from NULLS FIRST, p.id',
            ['now' => $now]
        );
        $out = [];
        foreach ($rows as $r) {
            $active = $r['special_price_from'] === null || (string) $r['special_price_from'] <= $now;
            if (($status === 'active' && !$active) || ($status === 'planned' && $active)) {
                continue;
            }
            $out[] = ['productId' => (int) $r['id'], 'name' => (string) $r['name'], 'regularPrice' => (int) round((float) $r['price']), 'specialPrice' => (int) round((float) $r['special_price']),
                'startsAt' => self::iso($r['special_price_from']), 'endsAt' => self::iso($r['special_price_to']), 'status' => $active ? 'active' : 'planned', 'batchId' => $r['batch_id'] === null ? null : (int) $r['batch_id']];
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    public function batches(int $limit): array
    {
        return array_map(fn ($b) => $this->batchRow($b), $this->conn()->fetchAllAssociative('SELECT * FROM gestion_promotion_batch ORDER BY id DESC LIMIT ' . max(1, min(100, $limit))));
    }

    public function batch(int $id): array
    {
        $b = $this->conn()->fetchAssociative('SELECT * FROM gestion_promotion_batch WHERE id = ?', [$id]);
        if ($b === false) {
            throw new GestionConflictException('BATCH_NOT_FOUND', 'Lot de promotions introuvable.', 404, ['batchId' => $id]);
        }
        $changes = $this->conn()->fetchAllAssociative('SELECT c.*, p.name, p.price FROM gestion_promotion_change c LEFT JOIN product p ON p.id = c.product_id WHERE c.batch_id = ? ORDER BY c.id', [$id]);

        return $this->batchRow($b) + ['rows' => array_map(fn ($c) => ['productId' => (int) $c['product_id'], 'name' => $c['name'], 'regularPrice' => $c['price'] === null ? null : (int) round((float) $c['price']),
            'promoPrice' => (int) $c['new_special_price'], 'replaced' => $c['old_special_price'] === null ? null : ['specialPrice' => (int) $c['old_special_price'], 'from' => self::iso($c['old_from']), 'to' => self::iso($c['old_to'])]], $changes)];
    }

    /** Arrondi vers le bas (une promotion n'annonce jamais moins de remise) : ends_99 = le plus grand X,99 ≤ prix ; down_50 ; down_100 ; none = au cent */
    public static function round(float $cents, string $rounding): int
    {
        $c = (int) floor($cents + 1e-9);
        $r = match ($rounding) {
            'ends_99' => intdiv($c, 100) * 100 + 99 <= $c ? intdiv($c, 100) * 100 + 99 : intdiv($c, 100) * 100 - 1,
            'down_50' => intdiv($c, 50) * 50,
            'down_100' => intdiv($c, 100) * 100,
            default => (int) round($cents),
        };

        return $r > 0 ? $r : (int) round($cents);
    }

    // ----- interne -----

    private function row(array $p, array $in, \DateTimeImmutable $now): array
    {
        $regular = (int) round((float) ($p['price'] ?? 0));
        $cost = $p['cost'] === null ? null : (int) round((float) $p['cost']);
        $saleItem = $p['product_type'] === 'standard' && !in_array($p['rental'], [true, 't', 1, '1'], true);
        $alerts = [];
        $promo = $regular;
        if (!$saleItem) {
            $alerts[] = 'NOT_A_SALE_ITEM';
        } elseif ($regular <= 0) {
            $alerts[] = 'PRICE_MISSING';
        } else {
            $promo = self::round($regular * (1 - $in['percent'] / 100), $in['rounding']);
        }
        $blocked = $alerts !== [];
        $nowSql = $now->format('Y-m-d H:i:s');
        $current = null;
        if ($p['special_price'] !== null && (float) $p['special_price'] > 0) {
            $from = $p['special_price_from'];
            $to = $p['special_price_to'];
            $current = ['specialPrice' => (int) round((float) $p['special_price']), 'from' => self::iso($from), 'to' => self::iso($to),
                'active' => ($from === null || (string) $from <= $nowSql) && ($to === null || (string) $to >= $nowSql)];
            $same = $current['specialPrice'] === $promo && self::sameInstant($from, $in['startsAt']) && self::sameInstant($to, $in['endsAt']);
            if (!$blocked && $same) {
                $alerts[] = 'NO_CHANGE';
            } elseif ($to === null || (string) $to >= $nowSql) {
                $alerts[] = 'PROMOTION_EXISTS';
            }
        }
        if (!$blocked && $cost === null) {
            $alerts[] = 'COST_UNKNOWN';
        } elseif (!$blocked && $promo < $cost) {
            $alerts[] = 'BELOW_COST';
        }
        if (!$blocked && $in['percent'] > self::BIG_CHANGE) {
            $alerts[] = 'BIG_CHANGE';
        }
        $own = array_values(array_filter($p['variants'], fn ($v) => $v['price'] !== null));
        if ($own !== []) {
            $alerts[] = 'VARIANT_OWN_PRICE';
        }
        $inheriting = array_values(array_filter($p['variants'], fn ($v) => $v['price'] === null));
        $rate = fn (int $price) => $cost === null || $price <= 0 ? null : round(($price - $cost) / $price * 100, 2);

        return [
            'productId' => (int) $p['id'], 'name' => (string) $p['name'], 'regularPrice' => $regular, 'promoPrice' => $promo, 'discount' => $regular - $promo, 'cost' => $cost,
            'marginRegular' => $cost === null ? null : $regular - $cost, 'marginPromo' => $cost === null ? null : $promo - $cost,
            'marginRateRegular' => $rate($regular), 'marginRatePromo' => $rate($promo),
            'stock' => $p['variants'] === [] ? (int) $p['quantity'] : array_sum(array_map(fn ($v) => (int) $v['stock'], $inheriting)),
            'startsAt' => $in['startsAt'], 'endsAt' => $in['endsAt'], 'current' => $current,
            'variantsOwnPrice' => ['count' => count($own), 'variants' => array_map(fn ($v) => ['variantId' => (int) $v['id'], 'variantLabel' => GestionPriceService::variantLabel($v['size'] ?? null, $v['color'] ?? null)], $own)],
            'changed' => !$blocked && !in_array('NO_CHANGE', $alerts, true), 'alerts' => $alerts,
        ];
    }

    /** @return array<string, mixed> */
    private function input(array $body): array
    {
        $errors = [];
        $percent = $body['percent'] ?? null;
        if (!is_int($percent) && !(is_float($percent) && is_finite($percent))) {
            $errors['percent'] = 'MUST_BE_NUMBER';
        } elseif ($percent < self::PERCENT_RANGE[0] || $percent > self::PERCENT_RANGE[1]) {
            $errors['percent'] = 'OUT_OF_RANGE';
        }
        $rounding = $body['rounding'] ?? 'none';
        if (!in_array($rounding, self::ROUNDINGS, true)) {
            $errors['rounding'] = 'INVALID_ROUNDING';
        }
        $today = $this->calendar->today()->format('Y-m-d');
        $date = fn (mixed $v) => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && \DateTimeImmutable::createFromFormat('!Y-m-d', $v) !== false ? $v : null;
        $start = ($body['startDate'] ?? null) === null ? $today : $date($body['startDate']);
        $end = ($body['endDate'] ?? null) === null ? null : $date($body['endDate']);
        if ($start === null) {
            $errors['startDate'] = 'INVALID_DATE';
        } elseif ($start < $today) {
            $errors['startDate'] = 'DATE_IN_PAST';
        }
        if (($body['endDate'] ?? null) === null) {
            $errors['endDate'] = 'REQUIRED';
        } elseif ($end === null) {
            $errors['endDate'] = 'INVALID_DATE';
        } elseif ($start !== null && $end < $start) {
            $errors['endDate'] = 'BEFORE_START';
        } elseif ($start !== null && AdviceRules::days($start, $end) > self::MAX_DAYS) {
            $errors['endDate'] = 'TOO_LONG';
        }
        $scopes = array_filter(['productIds', 'commandeId', 'adviceId'], fn ($k) => ($body[$k] ?? null) !== null);
        if ($scopes === []) {
            $errors['productIds'] = 'SCOPE_REQUIRED';
        } elseif (count($scopes) > 1) {
            $errors['productIds'] = 'SCOPE_AMBIGUOUS';
        }
        $productIds = null;
        if (isset($body['productIds'])) {
            $list = $body['productIds'];
            if (!is_array($list) || !array_is_list($list) || $list === []) {
                $errors['productIds'] = 'REQUIRED';
            } elseif (count($list) > GestionPriceService::MAX_PRODUCTS) {
                $errors['productIds'] = 'TOO_MANY';
            } else {
                foreach ($list as $i => $id) {
                    if (!is_int($id) || $id <= 0) {
                        $errors["productIds[$i]"] = 'INVALID_ID';
                    }
                }
                $productIds = array_values(array_unique(array_filter($list, 'is_int')));
            }
        }
        foreach (['commandeId', 'adviceId'] as $k) {
            if (isset($body[$k]) && (!is_int($body[$k]) || $body[$k] <= 0)) {
                $errors[$k] = 'INVALID_ID';
            }
        }
        if ($errors !== []) {
            throw new GestionValidationException($errors, 'Promotion invalide');
        }
        [$startsAt, $endExclusive] = $this->clock->range($start, $end);

        return ['percent' => (float) $percent, 'rounding' => $rounding, 'startDate' => $start, 'endDate' => $end, 'days' => AdviceRules::days($start, $end),
            'startsAt' => $startsAt->format(\DateTimeInterface::ATOM), 'endsAt' => $endExclusive->modify('-1 second')->format(\DateTimeInterface::ATOM),
            'productIds' => $productIds, 'commandeId' => $body['commandeId'] ?? null, 'adviceId' => $body['adviceId'] ?? null];
    }

    private function batchRow(array $b): array
    {
        return [
            'id' => (int) $b['id'], 'percent' => (float) $b['percent'], 'rounding' => $b['rounding'], 'startDate' => substr((string) $b['start_date'], 0, 10), 'endDate' => substr((string) $b['end_date'], 0, 10),
            'startsAt' => self::iso($b['starts_at']), 'endsAt' => self::iso($b['ends_at']), 'adviceId' => $b['advice_id'] === null ? null : (int) $b['advice_id'], 'changed' => (int) $b['changed'],
            'createdBy' => $b['created_by'], 'createdAt' => self::iso($b['created_at']), 'endedAt' => self::iso($b['ended_at']), 'endedBy' => $b['ended_by'],
            'endable' => $b['ended_at'] === null && (string) $b['ends_at'] >= (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
            'endSkipped' => $b['end_skipped'] === null ? null : json_decode((string) $b['end_skipped'], true),
        ];
    }

    /** Instant UTC lu en base (sans fuseau) → ISO 8601 +00:00 */
    private static function iso(mixed $sql): ?string
    {
        return $sql === null ? null : (new \DateTimeImmutable((string) $sql, new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM);
    }

    /** ISO 8601 → valeur SQL en UTC */
    private static function sql(string $iso): string
    {
        return (new \DateTimeImmutable($iso))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private static function sameInstant(mixed $sql, mixed $other): bool
    {
        if ($sql === null || $other === null) {
            return $sql === $other;
        }
        $o = str_contains((string) $other, 'T') ? self::sql((string) $other) : substr((string) $other, 0, 19);

        return substr((string) $sql, 0, 19) === $o;
    }

    private function conn(): \Doctrine\DBAL\Connection
    {
        return $this->emProvider->getEntityManager()->getConnection();
    }
}
