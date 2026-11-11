<?php

namespace App\Gestion\Services;

use App\Entity\ProductVariant;
use App\Entity\VariantPromotion;
use App\Services\Booking\BookingClock;
use App\Services\OrderService\VariantPriceResolver;
use App\Services\TenantEntityManagerProvider;

/**
 * Promotions par variante depuis la gestion (lot VAR, 11/11/2026, `docs/promotions-gestion.md` § 8, contrat validé par l'application) : aperçu
 * sans écriture (empreinte), application (lot et promotions écrits par Doctrine pour vider le cache des articles), fin, liste et lots.
 *
 * Prix de départ d'une variante : son prix propre, sinon le prix normal de l'article ; prix promotionnel = départ × (1 − pourcentage), arrondi
 * vers le bas comme P5. Fenêtre en jours du magasin (fuseau de la boutique) écrite en UTC [début, fin[, invariant de la correction LOC3 :
 * ends_at = minuit du magasin le lendemain de end_date. La promotion de variante l'emporte sur celle de l'article (sans cumul) ; lue par
 * `VariantPriceResolver` seulement quand l'interrupteur du site (super admin) est allumé : éteint, l'aperçu le signale et l'écriture est refusée.
 */
class GestionVariantPromotionService
{
    public const PERCENT_RANGE = [5, 90];
    public const MAX_DAYS = 90;
    public const MAX_VARIANTS = 500;
    public const BIG_CHANGE = 50;
    public const STATUSES = ['active', 'planned', 'all'];
    /** Alertes qui bloquent une ligne sauf accord explicite du gérant (champ du corps) */
    public const ALLOW = ['BELOW_COST' => 'allowBelowCost', 'BIG_CHANGE' => 'allowBigChange', 'PROMOTION_EXISTS' => 'allowReplace', 'VARIANT_PRICE_ABOVE_PRODUCT_PROMOTION' => 'allowAboveProductPromotion'];

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly GestionPriceService $prices,
        private readonly GestionAdviceService $advices,
        private readonly PeriodCalendar $calendar,
        private readonly BookingClock $clock,
        private readonly VariantPriceResolver $resolver,
    ) {
    }

    /** POST /api/gestion/promotions/variantes/apercu : aucune écriture */
    public function preview(array $body): array
    {
        $in = $this->input($body);
        [$products, $lineCosts] = $this->prices->scope(['productIds' => $in['productIds'], 'commandeId' => $in['commandeId'], 'adviceId' => $in['adviceId']]);
        $existing = $this->overlapping($in);
        $rows = [];
        foreach ($products as $p) {
            foreach ($p['variants'] as $v) {
                if ($in['variantIds'] === null || in_array((int) $v['id'], $in['variantIds'], true)) {
                    $rows[] = $this->row($p, $v, $in, $lineCosts[(int) $p['id']] ?? null, $existing[(int) $v['id']] ?? []);
                }
            }
        }
        if (count($rows) > self::MAX_VARIANTS) {
            throw new GestionValidationException(['variantIds' => 'TOO_MANY']);
        }
        $includesTax = in_array($this->conn()->fetchOne('SELECT prices_include_tax FROM entreprise ORDER BY id LIMIT 1'), [true, 't', 1, '1'], true);
        $enabled = $this->resolver->enabled();
        $changed = count(array_filter($rows, fn ($r) => $r['changed']));

        return [
            'rows' => $rows,
            'summary' => ['variants' => count($rows), 'changed' => $changed, 'skipped' => count($rows) - $changed],
            'period' => ['startDate' => $in['startDate'], 'endDate' => $in['endDate'], 'startsAt' => $in['startsAt']->format(\DateTimeInterface::ATOM), 'endsAt' => $in['endsAt']->format(\DateTimeInterface::ATOM), 'days' => $in['days']],
            'enabled' => $enabled,
            'warnings' => $enabled ? [] : ['VARIANT_PROMOTIONS_OFF'],
            'pricesIncludeTax' => $includesTax,
            'previewHash' => hash('sha256', (string) json_encode([array_diff_key($in, ['startsAt' => 1, 'endsAt' => 1]), array_map(fn ($r) => [$r['variantId'], $r['basePrice'], $r['promoPrice'], $r['alerts'], array_column($r['current'], 'id'), $r['productPromotion']], $rows)])),
            'caveats' => $includesTax ? [['code' => 'PRICES_INCLUDE_TAX', 'text' => 'Les prix du site sont taxes comprises : la marge affichée comprend la taxe.']] : [],
        ];
    }

    /** POST /api/gestion/promotions/variantes/appliquer : empreinte de l'aperçu ; lignes bloquées écrites seulement avec leur accord explicite */
    public function apply(array $body, ?string $user): array
    {
        $hash = $body['previewHash'] ?? null;
        if (!is_string($hash) || $hash === '') {
            throw new GestionValidationException(['previewHash' => 'REQUIRED']);
        }
        $preview = $this->preview($body);
        if (!$preview['enabled']) {
            throw new GestionConflictException('VARIANT_PROMOTIONS_OFF', 'Les promotions par variante ne sont pas activées sur ce site (réglage du super administrateur).', 409);
        }
        if (!hash_equals($preview['previewHash'], $hash)) {
            throw new GestionConflictException('PRICES_CHANGED', 'Des prix ou des promotions ont changé depuis l\'aperçu : voici l\'aperçu à jour.', 409, ['preview' => $preview]);
        }
        $write = [];
        $skipped = [];
        foreach ($preview['rows'] as $r) {
            $refused = array_values(array_filter($r['alerts'], fn ($a) => isset(self::ALLOW[$a]) && ($body[self::ALLOW[$a]] ?? false) !== true));
            if (!$r['changed'] || $refused !== []) {
                $skipped[] = ['variantId' => $r['variantId'], 'alerts' => $r['changed'] ? $refused : $r['alerts']];
            } else {
                $write[] = $r;
            }
        }
        if ($write === []) {
            throw new GestionConflictException('NOTHING_TO_APPLY', 'Aucune variante à mettre en promotion (alertes à accepter ou variantes non vendables).', 422, ['preview' => $preview, 'skipped' => $skipped]);
        }
        $in = $this->input($body);
        $em = $this->emProvider->getEntityManager();
        $conn = $em->getConnection();
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $conn->beginTransaction();
        try {
            $batchId = (int) $conn->fetchOne(
                'INSERT INTO variant_promotion_batch (percent, rounding, start_date, end_date, advice_id, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW()) RETURNING id',
                [$in['percent'], $in['rounding'], $in['startDate'], $in['endDate'], $in['adviceId'], $user]
            );
            foreach ($write as $r) {
                foreach ($r['current'] as $old) { // remplacement accepté (allowReplace) : l'ancienne s'arrête au début de la nouvelle
                    $this->stop($em->find(VariantPromotion::class, $old['id']), $in['startsAt'], $now);
                }
                $em->persist((new VariantPromotion())->setProductVariant($em->find(ProductVariant::class, $r['variantId']))->setBatchId($batchId)->setPercent($in['percent'])
                    ->setPrice($r['promoPrice'])->setBasePrice($r['basePrice'])->setStartsAt($in['startsAt'])->setEndsAt($in['endsAt'])->setCreatedAt($now)
                    ->setDays(new \DateTimeImmutable($in['startDate']), new \DateTimeImmutable($in['endDate'])));
            }
            $em->flush();
            $conn->commit();
        } catch (\Throwable $e) {
            if ($conn->isTransactionActive()) {
                $conn->rollBack();
            }
            throw $e;
        }
        $advice = $in['adviceId'] === null ? null : $this->advices->markDoneByApplication($in['adviceId'], $user,
            ['promotionPercent' => $in['percent'], 'productIds' => array_values(array_unique(array_column($write, 'productId'))), 'variantIds' => array_column($write, 'variantId'), 'variantPromotionBatchId' => $batchId],
            $in['startDate'], $this->advices->siteLanguage());

        return ['batch' => $this->batch($batchId), 'skipped' => $skipped, 'advice' => $advice];
    }

    /**
     * POST /api/gestion/promotions/variantes/terminer { batchId } : pas encore commencée → plage vide (endDate = startDate − 1) ; en cours → jusqu'à
     * la fin de la journée du magasin (endDate = aujourd'hui) ; les commandes déjà passées ne changent jamais.
     */
    public function end(mixed $batchId, ?string $user): array
    {
        if (!is_int($batchId) || $batchId <= 0) {
            throw new GestionValidationException(['batchId' => $batchId === null ? 'REQUIRED' : 'INVALID_ID']);
        }
        $batch = $this->conn()->fetchAssociative('SELECT * FROM variant_promotion_batch WHERE id = ?', [$batchId]);
        if ($batch === false) {
            throw new GestionConflictException('BATCH_NOT_FOUND', 'Lot de promotions de variantes introuvable.', 404, ['batchId' => $batchId]);
        }
        if ($batch['ended_at'] !== null) {
            throw new GestionConflictException('ALREADY_ENDED', 'Ce lot de promotions de variantes est déjà terminé.', 409, ['batchId' => $batchId]);
        }
        $utc = new \DateTimeZone('UTC');
        $now = new \DateTimeImmutable('now', $utc);
        $tomorrow = (new \DateTimeImmutable('tomorrow', $this->clock->timezone()))->setTimezone($utc);
        $em = $this->emProvider->getEntityManager();
        foreach ($em->getRepository(VariantPromotion::class)->findBy(['batchId' => $batchId]) as $p) {
            $this->stop($p, $tomorrow, $now);
        }
        $em->flush();
        $this->conn()->executeStatement('UPDATE variant_promotion_batch SET ended_at = NOW(), ended_by = ? WHERE id = ?', [$user, $batchId]);

        return $this->batch($batchId);
    }

    /** GET /api/gestion/promotions/variantes?status=active|planned|all (défaut all : en cours et à venir) */
    public function current(string $status): array
    {
        if (!in_array($status, self::STATUSES, true)) {
            throw new GestionValidationException(['status' => 'INVALID_STATUS']);
        }
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $where = match ($status) {
            'active' => 'vp.starts_at <= :now AND vp.ends_at > :now',
            'planned' => 'vp.starts_at > :now',
            default => 'vp.ends_at > :now',
        };

        return array_map(fn ($r) => $this->promotionRow($r, $now), $this->conn()->fetchAllAssociative(
            self::ROWS_SQL . " WHERE $where AND vp.ends_at > vp.starts_at ORDER BY vp.starts_at, vp.id", ['now' => $now]
        ));
    }

    /** GET /api/gestion/promotions/variantes/lots?limit= */
    public function batches(int $limit): array
    {
        $ids = $this->conn()->fetchFirstColumn('SELECT id FROM variant_promotion_batch ORDER BY id DESC LIMIT ' . max(1, min(100, $limit)));

        return array_map(fn ($id) => $this->batch((int) $id), $ids);
    }

    /** GET /api/gestion/promotions/variantes/lots/{id} */
    public function batch(int $id): array
    {
        $b = $this->conn()->fetchAssociative('SELECT * FROM variant_promotion_batch WHERE id = ?', [$id]);
        if ($b === false) {
            throw new GestionConflictException('BATCH_NOT_FOUND', 'Lot de promotions de variantes introuvable.', 404, ['batchId' => $id]);
        }
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $rows = $this->conn()->fetchAllAssociative(self::ROWS_SQL . ' WHERE vp.batch_id = ? ORDER BY vp.id', [$id]);
        $iso = fn ($v) => $v === null ? null : (new \DateTimeImmutable((string) $v, new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM);

        return [
            'id' => (int) $b['id'], 'percent' => self::number((float) $b['percent']), 'rounding' => (string) $b['rounding'],
            'startDate' => substr((string) $b['start_date'], 0, 10), 'endDate' => substr((string) $b['end_date'], 0, 10),
            'adviceId' => $b['advice_id'] === null ? null : (int) $b['advice_id'], 'createdBy' => $b['created_by'], 'createdAt' => $iso($b['created_at']),
            'endedAt' => $iso($b['ended_at']), 'endedBy' => $b['ended_by'],
            'promotions' => array_map(fn ($r) => $this->promotionRow($r, $now), $rows),
        ];
    }

    // ----- interne -----

    private const ROWS_SQL = 'SELECT vp.*, pv.product_id, p.name, sz.name AS size, co.name AS color FROM variant_promotion vp JOIN product_variant pv ON pv.id = vp.product_variant_id'
        . ' JOIN product p ON p.id = pv.product_id LEFT JOIN size sz ON sz.id = pv.size_id LEFT JOIN color co ON co.id = pv.color_id';

    /** Arrête une promotion à l'instant donné (minuit du magasin) : pas commencée → plage vide ; end_date = jour du magasin de ends_at − 1 (invariant) */
    private function stop(?VariantPromotion $p, \DateTimeImmutable $at, \DateTimeImmutable $now): void
    {
        if ($p === null) {
            return;
        }
        [$start, $end] = $p->window();
        if ($end <= $now || $end <= $start) {
            return; // déjà finie par ses dates, ou déjà annulée
        }
        $endsAt = $start >= $at ? $start : min($end, $at);
        $lastDay = new \DateTimeImmutable($endsAt->setTimezone($this->clock->timezone())->modify('-1 day')->format('Y-m-d'));
        $p->setEndsAt($endsAt)->setEndedAt($now)->setDays($p->getStartDate(), $lastDay);
    }

    /** @return array<int, list<array<string, mixed>>> promotions de variante qui recoupent la fenêtre demandée, par variante */
    private function overlapping(array $in): array
    {
        $out = [];
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        foreach ($this->conn()->fetchAllAssociative(self::ROWS_SQL . ' WHERE vp.ends_at > vp.starts_at AND vp.ends_at > ? AND vp.starts_at < ? AND vp.ends_at > ? ORDER BY vp.starts_at, vp.id',
            [$in['startsAt']->format('Y-m-d H:i:s'), $in['endsAt']->format('Y-m-d H:i:s'), $now]) as $r) {
            $out[(int) $r['product_variant_id']][] = $this->promotionRow($r, $now);
        }

        return $out;
    }

    private function row(array $p, array $v, array $in, ?float $lineCost, array $current): array
    {
        $own = $v['price'] !== null;
        $base = (int) round((float) ($own ? $v['price'] : ($p['price'] ?? 0)));
        $costRaw = $lineCost ?? ($p['cost'] === null ? null : (float) $p['cost']);
        $cost = $costRaw === null ? null : (int) round($costRaw);
        $saleItem = $p['product_type'] === 'standard' && !in_array($p['rental'], [true, 't', 1, '1'], true);
        $alerts = [];
        $promo = $base;
        if (!$saleItem) {
            $alerts[] = 'NOT_A_SALE_ITEM';
        } elseif ($base <= 0) {
            $alerts[] = 'PRICE_MISSING';
        } else {
            $promo = GestionPromotionService::round($base * (1 - $in['percent'] / 100), $in['rounding']);
        }
        $blocked = $alerts !== [];
        // Promotion de l'article dont les dates recoupent la fenêtre : elle ne s'applique qu'aux variantes sans prix propre ; la promotion de variante
        // la remplace pendant ses dates (la plus précise gagne) ; alerte si le prix de variante serait plus haut (le gérant renchérirait sans le vouloir)
        $productPromotion = null;
        if (!$own && $p['special_price'] !== null && (float) $p['special_price'] > 0) {
            $from = $p['special_price_from'] === null ? null : new \DateTimeImmutable((string) $p['special_price_from'], new \DateTimeZone('UTC'));
            $to = $p['special_price_to'] === null ? null : new \DateTimeImmutable((string) $p['special_price_to'], new \DateTimeZone('UTC'));
            if (($from === null || $from < $in['endsAt']) && ($to === null || $to >= $in['startsAt'])) {
                $productPromotion = ['specialPrice' => (int) round((float) $p['special_price']), 'from' => $from?->format(\DateTimeInterface::ATOM), 'to' => $to?->format(\DateTimeInterface::ATOM)];
            }
        }
        if (!$blocked) {
            if ($productPromotion !== null) {
                $alerts[] = 'PRODUCT_PROMOTION_ACTIVE';
                if ($promo > $productPromotion['specialPrice']) {
                    $alerts[] = 'VARIANT_PRICE_ABOVE_PRODUCT_PROMOTION';
                }
            }
            if ($current !== []) {
                $alerts[] = 'PROMOTION_EXISTS';
            }
            if ($cost === null) {
                $alerts[] = 'COST_UNKNOWN';
            } elseif ($promo < $cost) {
                $alerts[] = 'BELOW_COST';
            }
            if ($in['percent'] > self::BIG_CHANGE) {
                $alerts[] = 'BIG_CHANGE';
            }
            if ($promo >= $base) {
                $alerts[] = 'NO_CHANGE';
            }
        }
        $rate = fn (int $price) => $cost === null || $price <= 0 ? null : round(($price - $cost) / $price * 100, 2);

        return [
            'variantId' => (int) $v['id'], 'productId' => (int) $p['id'], 'name' => (string) $p['name'], 'variantLabel' => GestionPriceService::variantLabel($v['size'] ?? null, $v['color'] ?? null),
            'ownPrice' => $own, 'basePrice' => $base, 'promoPrice' => $promo, 'discount' => $base - $promo, 'cost' => $cost,
            'marginBase' => $cost === null ? null : $base - $cost, 'marginPromo' => $cost === null ? null : $promo - $cost, 'marginRateBase' => $rate($base), 'marginRatePromo' => $rate($promo),
            'stock' => (int) $v['stock'], 'current' => $current, 'productPromotion' => $productPromotion,
            'changed' => !$blocked && !in_array('NO_CHANGE', $alerts, true), 'alerts' => $alerts,
        ];
    }

    private function promotionRow(array $r, string $now): array
    {
        $utc = new \DateTimeZone('UTC');

        return [
            'id' => (int) $r['id'], 'batchId' => $r['batch_id'] === null ? null : (int) $r['batch_id'], 'variantId' => (int) $r['product_variant_id'], 'productId' => (int) $r['product_id'],
            'name' => $r['name'] ?? null, 'variantLabel' => GestionPriceService::variantLabel($r['size'] ?? null, $r['color'] ?? null),
            'percent' => self::number((float) $r['percent']), 'price' => (int) $r['price'], 'basePrice' => (int) $r['base_price'],
            'startDate' => substr((string) $r['start_date'], 0, 10), 'endDate' => substr((string) $r['end_date'], 0, 10),
            'startsAt' => (new \DateTimeImmutable((string) $r['starts_at'], $utc))->format(\DateTimeInterface::ATOM), 'endsAt' => (new \DateTimeImmutable((string) $r['ends_at'], $utc))->format(\DateTimeInterface::ATOM),
            'status' => (string) $r['ends_at'] <= (string) $r['starts_at'] ? 'cancelled' : ((string) $r['starts_at'] > $now ? 'upcoming' : ((string) $r['ends_at'] > $now ? 'active' : 'ended')),
            'endedAt' => $r['ended_at'] === null ? null : (new \DateTimeImmutable((string) $r['ended_at'], $utc))->format(\DateTimeInterface::ATOM),
        ];
    }

    /** @return array<string, mixed> entrée validée : périmètre (une seule forme), pourcentage, arrondi, jours du magasin, bornes UTC */
    private function input(array $body): array
    {
        $errors = [];
        $percent = $body['percent'] ?? null;
        if (!is_int($percent) && !(is_float($percent) && is_finite($percent))) {
            $errors['percent'] = $percent === null ? 'REQUIRED' : 'MUST_BE_NUMBER';
        } elseif ($percent < self::PERCENT_RANGE[0] || $percent > self::PERCENT_RANGE[1]) {
            $errors['percent'] = 'OUT_OF_RANGE';
        }
        $rounding = $body['rounding'] ?? 'none';
        if (!in_array($rounding, GestionPromotionService::ROUNDINGS, true)) {
            $errors['rounding'] = 'INVALID_ROUNDING';
        }
        $today = $this->calendar->today()->format('Y-m-d');
        $date = fn (mixed $v) => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && \DateTimeImmutable::createFromFormat('!Y-m-d', $v)?->format('Y-m-d') === $v ? $v : null;
        $start = ($body['startDate'] ?? null) === null ? $today : $date($body['startDate']);
        $end = $date($body['endDate'] ?? null);
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
        $scopes = array_values(array_filter(['variantIds', 'productIds', 'commandeId', 'adviceId'], fn ($k) => ($body[$k] ?? null) !== null));
        if ($scopes === []) {
            $errors['variantIds'] = 'SCOPE_REQUIRED';
        } elseif (count($scopes) > 1) {
            $errors[$scopes[0]] = 'SCOPE_AMBIGUOUS';
        }
        $lists = ['variantIds' => null, 'productIds' => null];
        foreach (['variantIds' => self::MAX_VARIANTS, 'productIds' => GestionPriceService::MAX_PRODUCTS] as $key => $max) {
            if (!isset($body[$key])) {
                continue;
            }
            $list = $body[$key];
            if (!is_array($list) || !array_is_list($list) || $list === []) {
                $errors[$key] = 'REQUIRED';
            } elseif (count($list) > $max) {
                $errors[$key] = 'TOO_MANY';
            } elseif (array_filter($list, fn ($id) => !is_int($id) || $id <= 0) !== []) {
                $errors[$key] = 'INVALID_ID';
            } else {
                $lists[$key] = array_values(array_unique($list));
            }
        }
        foreach (['commandeId', 'adviceId'] as $k) {
            if (isset($body[$k]) && (!is_int($body[$k]) || $body[$k] <= 0)) {
                $errors[$k] = 'INVALID_ID';
            }
        }
        // variantIds : articles des variantes, filtrés ensuite ; variante inconnue → VARIANT_NOT_FOUND
        $productIds = $lists['productIds'];
        if ($lists['variantIds'] !== null && !isset($errors['variantIds'])) {
            $found = $this->conn()->fetchAllKeyValue('SELECT id, product_id FROM product_variant WHERE id IN (?)', [$lists['variantIds']], [\Doctrine\DBAL\ArrayParameterType::INTEGER]);
            foreach ($lists['variantIds'] as $i => $id) {
                if (!isset($found[$id])) {
                    $errors["variantIds[$i]"] = 'VARIANT_NOT_FOUND';
                }
            }
            $productIds = array_values(array_unique(array_map('intval', $found)));
        }
        if ($errors !== []) {
            throw new GestionValidationException($errors, 'Promotion de variante invalide');
        }
        $tz = $this->clock->timezone();

        return [
            'variantIds' => $lists['variantIds'], 'productIds' => $productIds, 'commandeId' => $body['commandeId'] ?? null, 'adviceId' => $body['adviceId'] ?? null,
            'percent' => (float) $percent, 'rounding' => $rounding, 'startDate' => $start, 'endDate' => $end, 'days' => AdviceRules::days($start, $end),
            'startsAt' => BookingClock::utc(new \DateTimeImmutable("$start 00:00:00", $tz)),
            'endsAt' => BookingClock::utc((new \DateTimeImmutable("$end 00:00:00", $tz))->modify('+1 day')),
        ];
    }

    private static function number(float $value): int|float
    {
        return floor($value) === $value ? (int) $value : $value;
    }

    private function conn(): \Doctrine\DBAL\Connection
    {
        return $this->emProvider->getEntityManager()->getConnection();
    }
}
