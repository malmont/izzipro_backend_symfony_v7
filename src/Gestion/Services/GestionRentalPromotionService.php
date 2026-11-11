<?php

namespace App\Gestion\Services;

use App\Entity\Product;
use App\Entity\RentalPromotion;
use App\Services\Booking\BookingClock;
use App\Services\TenantEntityManagerProvider;

/**
 * Promotions de location par article depuis la gestion (lot LOC3, 11/11/2026, `docs/locations-gestion.md` § 3, plan validé par l'application).
 * Aperçu sans écriture (empreinte), application tout ou rien (lot et promotions, écrits par Doctrine pour vider le cache des articles), fin,
 * liste et lots. Fenêtre en jours du magasin (fuseau de la boutique, `BookingClock`) écrite en UTC [début, fin[ ; la remise s'applique à une
 * location dont le jour de début est dans la fenêtre et sur un jour coché (`RentalPromotionService`, calcul commun boutique et caisse).
 */
class GestionRentalPromotionService
{
    public const PERCENT_RANGE = [5, 90];
    public const MAX_DAYS = 90;
    public const MAX_PRODUCTS = 50;
    public const BIG_DISCOUNT = 50;
    public const RENTAL_KINDS = ['RENTAL_PEAK_DAYS', 'RENTAL_LOW_WEEKDAYS', 'RENTAL_IDLE'];

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly BookingClock $clock,
        private readonly PeriodCalendar $calendar,
        private readonly GestionAdviceService $advices,
    ) {
    }

    /** POST /api/gestion/locations/promotions/apercu : aucune écriture */
    public function preview(array $body): array
    {
        $in = $this->input($body);
        $items = [];
        foreach ($in['productIds'] as $pid) {
            $items[] = $this->item($pid, $in);
        }
        $count = fn (string $s) => count(array_filter($items, fn ($i) => $i['status'] === $s));

        return [
            'percent' => $in['percent'], 'startDate' => $in['startDate'], 'endDate' => $in['endDate'], 'days' => $in['days'], 'weekdays' => $in['weekdays'],
            'adviceId' => $in['adviceId'], 'items' => $items,
            'counts' => ['ok' => $count('ok'), 'notRentable' => $count('NOT_RENTABLE'), 'overlap' => $count('PROMOTION_OVERLAP')],
            'warnings' => $in['percent'] > self::BIG_DISCOUNT ? ['BIG_DISCOUNT'] : [],
            'previewHash' => hash('sha256', (string) json_encode([array_diff_key($in, ['startsAtUtc' => 1, 'endsAtUtc' => 1]), array_map(fn ($i) => [$i['productId'], $i['status'], $i['conflicts']], $items)])),
        ];
    }

    /** POST /api/gestion/locations/promotions/appliquer : empreinte de l'aperçu, tout ou rien */
    public function apply(array $body, ?string $user): array
    {
        $hash = $body['previewHash'] ?? null;
        if (!is_string($hash) || $hash === '') {
            throw new GestionValidationException(['previewHash' => 'REQUIRED']);
        }
        $preview = $this->preview($body);
        if (!hash_equals($preview['previewHash'], $hash)) {
            throw new GestionConflictException('PRICES_CHANGED', 'Des promotions ont changé depuis l\'aperçu : voici l\'aperçu à jour.', 409, ['preview' => $preview]);
        }
        if ($preview['counts']['overlap'] > 0) {
            throw new GestionConflictException('PROMOTION_OVERLAP', 'Un article a déjà une promotion de location sur ces jours : rien n\'est appliqué.', 409, ['preview' => $preview]);
        }
        if ($preview['counts']['notRentable'] > 0) {
            throw new GestionConflictException('NOT_RENTABLE', 'Un article ne se loue pas : rien n\'est appliqué.', 422, ['preview' => $preview]);
        }
        $in = $this->input($body);
        $em = $this->emProvider->getEntityManager();
        $conn = $em->getConnection();
        $conn->beginTransaction();
        try {
            $batchId = (int) $conn->fetchOne(
                'INSERT INTO rental_promotion_batch (percent, start_date, end_date, weekdays, advice_id, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW()) RETURNING id',
                [$in['percent'], $in['startDate'], $in['endDate'], json_encode($in['weekdays']), $in['adviceId'], $user]
            );
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            foreach ($in['productIds'] as $pid) {
                $p = (new RentalPromotion())->setProduct($em->find(Product::class, $pid))->setBatchId($batchId)->setPercent($in['percent'])
                    ->setStartsAt($in['startsAtUtc'])->setEndsAt($in['endsAtUtc'])->setWeekdays($in['weekdays'])->setCreatedAt($now)
                    ->setDays(new \DateTimeImmutable($in['startDate']), new \DateTimeImmutable($in['endDate']));
                $em->persist($p);
            }
            $em->flush();
            $conn->commit();
        } catch (\Throwable $e) {
            if ($conn->isTransactionActive()) {
                $conn->rollBack();
            }
            throw $e;
        }
        $advice = $in['adviceId'] === null ? null
            : $this->advices->markDoneByApplication($in['adviceId'], $user, ['rentalPromotionPercent' => $in['percent'], 'productIds' => $in['productIds'], 'batchId' => $batchId], $in['startDate'], $this->advices->siteLanguage());

        return ['batch' => $this->batch($batchId), 'advice' => $advice];
    }

    /**
     * POST /api/gestion/locations/promotions/terminer { batchId } : une promotion pas encore commencée ne s'appliquera jamais ; une promotion en
     * cours s'arrête à la fin de la journée du magasin (une location qui commence aujourd'hui la garde, comme son devis).
     */
    public function end(mixed $batchId, ?string $user): array
    {
        if (!is_int($batchId) || $batchId <= 0) {
            throw new GestionValidationException(['batchId' => $batchId === null ? 'REQUIRED' : 'INVALID_ID']);
        }
        $batch = $this->conn()->fetchAssociative('SELECT * FROM rental_promotion_batch WHERE id = ?', [$batchId]);
        if ($batch === false) {
            throw new GestionConflictException('BATCH_NOT_FOUND', 'Lot de promotions de location introuvable.', 404, ['batchId' => $batchId]);
        }
        if ($batch['ended_at'] !== null) {
            throw new GestionConflictException('ALREADY_ENDED', 'Ce lot de promotions de location est déjà terminé.', 409, ['batchId' => $batchId]);
        }
        $tz = $this->clock->timezone();
        $utc = new \DateTimeZone('UTC');
        $now = new \DateTimeImmutable('now', $utc);
        $tomorrow = (new \DateTimeImmutable('tomorrow', $tz))->setTimezone($utc);
        $em = $this->emProvider->getEntityManager();
        foreach ($em->getRepository(RentalPromotion::class)->findBy(['batchId' => $batchId]) as $p) {
            $start = new \DateTimeImmutable($p->getStartsAt()->format('Y-m-d H:i:s'), $utc);
            $end = new \DateTimeImmutable($p->getEndsAt()->format('Y-m-d H:i:s'), $utc);
            if ($end <= $now) {
                continue; // déjà finie par ses dates
            }
            $endsAt = $start > $now ? $start : min($end, $tomorrow);
            // Correction LOC3 (11/11/2026) : end_date suit ends_at (invariant endsAt = minuit du magasin le lendemain de endDate) ;
            // annulée avant son début : plage vide, endDate = startDate − 1 jour
            $lastDay = new \DateTimeImmutable($endsAt->setTimezone($tz)->modify('-1 day')->format('Y-m-d'));
            $p->setEndsAt($endsAt)->setEndedAt($now)->setDays($p->getStartDate(), $lastDay);
        }
        $em->flush();
        $this->conn()->executeStatement('UPDATE rental_promotion_batch SET ended_at = NOW(), ended_by = ? WHERE id = ?', [$user, $batchId]);

        return $this->batch($batchId);
    }

    /** GET /api/gestion/locations/promotions : promotions en cours et à venir */
    public function current(): array
    {
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $rows = $this->conn()->fetchAllAssociative(
            'SELECT rp.*, p.name FROM rental_promotion rp JOIN product p ON p.id = rp.product_id WHERE rp.ends_at > ? AND rp.ends_at > rp.starts_at ORDER BY rp.starts_at, rp.id', [$now]
        );

        return array_map(fn ($r) => $this->promotionRow($r, $now), $rows);
    }

    /** GET /api/gestion/locations/promotions/lots */
    public function batches(int $limit): array
    {
        $ids = $this->conn()->fetchFirstColumn('SELECT id FROM rental_promotion_batch ORDER BY id DESC LIMIT ' . max(1, min(100, $limit)));

        return array_map(fn ($id) => $this->batch((int) $id), $ids);
    }

    public function batch(int $id): array
    {
        $b = $this->conn()->fetchAssociative('SELECT * FROM rental_promotion_batch WHERE id = ?', [$id]);
        if ($b === false) {
            throw new GestionConflictException('BATCH_NOT_FOUND', 'Lot de promotions de location introuvable.', 404, ['batchId' => $id]);
        }
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $rows = $this->conn()->fetchAllAssociative('SELECT rp.*, p.name FROM rental_promotion rp JOIN product p ON p.id = rp.product_id WHERE rp.batch_id = ? ORDER BY rp.id', [$id]);
        $atom = fn (?string $v) => $v === null ? null : (new \DateTimeImmutable($v, new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM);

        return [
            'id' => (int) $b['id'], 'percent' => (int) $b['percent'], 'startDate' => substr((string) $b['start_date'], 0, 10), 'endDate' => substr((string) $b['end_date'], 0, 10),
            'weekdays' => array_map('intval', json_decode((string) $b['weekdays'], true) ?: []), 'adviceId' => $b['advice_id'] === null ? null : (int) $b['advice_id'],
            'createdBy' => $b['created_by'], 'createdAt' => $atom($b['created_at']), 'endedAt' => $atom($b['ended_at']), 'endedBy' => $b['ended_by'],
            'promotions' => array_map(fn ($r) => $this->promotionRow($r, $now), $rows),
        ];
    }

    // ----- interne -----

    /** @return array<string, mixed> un article de l'aperçu : louable, promotions qui chevauchent, exemple de tarif */
    private function item(int $pid, array $in): array
    {
        $em = $this->emProvider->getEntityManager();
        $product = $em->find(Product::class, $pid);
        if ($product === null) {
            throw new GestionValidationException(['productIds' => 'NOT_FOUND'], 'Article introuvable : ' . $pid);
        }
        $rentable = $product->isRentalEnabled() && $product->getBookingConfiguration() !== null;
        $conflicts = [];
        foreach ($this->conn()->fetchAllAssociative('SELECT id, weekdays FROM rental_promotion WHERE product_id = ? AND starts_at < ? AND ends_at > ? AND ends_at > starts_at ORDER BY id',
            [$pid, $in['endsAtUtc']->format('Y-m-d H:i:s'), $in['startsAtUtc']->format('Y-m-d H:i:s')]) as $r) {
            if (array_intersect(array_map('intval', json_decode((string) $r['weekdays'], true) ?: []), $in['weekdays']) !== []) {
                $conflicts[] = (int) $r['id'];
            }
        }
        $pack = $product->getRentalPacks()[0] ?? null;
        $unit = $product->getBookingConfiguration()?->getGranularity() === 'days' ? 'day' : 'hour';
        $rate = $pack === null ? null : (int) round((float) ($unit === 'day' ? $pack->getDayRate() : $pack->getHourRate()));

        return [
            'productId' => $pid, 'name' => $product->getName(), 'status' => !$rentable ? 'NOT_RENTABLE' : ($conflicts !== [] ? 'PROMOTION_OVERLAP' : 'ok'), 'conflicts' => $conflicts,
            'example' => $rate === null || $rate <= 0 ? null : ['durationType' => $unit, 'rate' => $rate, 'discounted' => $rate - (int) round($rate * $in['percent'] / 100)],
        ];
    }

    private function promotionRow(array $r, string $now): array
    {
        $utc = new \DateTimeZone('UTC');

        return [
            'id' => (int) $r['id'], 'batchId' => $r['batch_id'] === null ? null : (int) $r['batch_id'], 'productId' => (int) $r['product_id'], 'name' => $r['name'] ?? null,
            'percent' => (int) $r['percent'], 'startDate' => substr((string) $r['start_date'], 0, 10), 'endDate' => substr((string) $r['end_date'], 0, 10),
            'startsAt' => (new \DateTimeImmutable((string) $r['starts_at'], $utc))->format(\DateTimeInterface::ATOM), 'endsAt' => (new \DateTimeImmutable((string) $r['ends_at'], $utc))->format(\DateTimeInterface::ATOM),
            'weekdays' => array_map('intval', json_decode((string) $r['weekdays'], true) ?: []),
            'status' => (string) $r['ends_at'] <= (string) $r['starts_at'] ? 'cancelled' : ((string) $r['starts_at'] > $now ? 'upcoming' : ((string) $r['ends_at'] > $now ? 'active' : 'ended')),
            'endedAt' => $r['ended_at'] === null ? null : (new \DateTimeImmutable((string) $r['ended_at'], $utc))->format(\DateTimeInterface::ATOM),
        ];
    }

    /** @return array<string, mixed> entrée validée : articles (ou conseil du guide), pourcentage, jours du magasin, jours de la semaine, bornes UTC */
    private function input(array $body): array
    {
        $errors = [];
        $advice = null;
        if (isset($body['adviceId'])) {
            if (!is_int($body['adviceId']) || $body['adviceId'] <= 0) {
                $errors['adviceId'] = 'INVALID_ID';
            } else {
                $advice = $this->conn()->fetchAssociative('SELECT kind, subject, action FROM gestion_advice WHERE id = ?', [$body['adviceId']]);
                if ($advice === false || !in_array($advice['kind'], self::RENTAL_KINDS, true)) {
                    $errors['adviceId'] = 'NOT_A_RENTAL_ADVICE';
                    $advice = null;
                }
            }
        }
        $action = $advice === null ? [] : ((json_decode((string) $advice['action'], true) ?: [])['params'] ?? []);
        $percent = $body['percent'] ?? ($action['percent'] ?? null);
        if (!is_int($percent)) {
            $errors['percent'] = $percent === null ? 'REQUIRED' : 'MUST_BE_INTEGER';
        } elseif ($percent < self::PERCENT_RANGE[0] || $percent > self::PERCENT_RANGE[1]) {
            $errors['percent'] = 'OUT_OF_RANGE';
        }
        $weekdays = $body['weekdays'] ?? ($action['weekdays'] ?? [1, 2, 3, 4, 5, 6, 7]);
        if (!is_array($weekdays) || !array_is_list($weekdays) || $weekdays === [] || array_filter($weekdays, fn ($w) => !is_int($w) || $w < 1 || $w > 7) !== []) {
            $errors['weekdays'] = 'INVALID_WEEKDAYS';
            $weekdays = [];
        }
        $weekdays = array_values(array_unique($weekdays));
        sort($weekdays);
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
        $productIds = null;
        if (isset($body['productIds'])) {
            $list = $body['productIds'];
            if (!is_array($list) || !array_is_list($list) || $list === []) {
                $errors['productIds'] = 'REQUIRED';
            } elseif (count($list) > self::MAX_PRODUCTS) {
                $errors['productIds'] = 'TOO_MANY';
            } elseif (array_filter($list, fn ($id) => !is_int($id) || $id <= 0) !== []) {
                $errors['productIds'] = 'INVALID_ID';
            } else {
                $productIds = array_values(array_unique($list));
            }
        } elseif ($advice !== null) {
            $productIds = array_values(array_map('intval', (json_decode((string) $advice['subject'], true) ?: [])['productIds'] ?? []));
        } elseif (!isset($errors['adviceId'])) {
            $errors['productIds'] = 'SCOPE_REQUIRED';
        }
        if ($errors !== []) {
            throw new GestionValidationException($errors, 'Promotion de location invalide');
        }
        $tz = $this->clock->timezone();

        return [
            'productIds' => $productIds, 'adviceId' => $advice === null ? null : (int) $body['adviceId'], 'percent' => $percent, 'weekdays' => $weekdays,
            'startDate' => $start, 'endDate' => $end, 'days' => AdviceRules::days($start, $end),
            'startsAtUtc' => BookingClock::utc(new \DateTimeImmutable("$start 00:00:00", $tz)),
            'endsAtUtc' => BookingClock::utc((new \DateTimeImmutable("$end 00:00:00", $tz))->modify('+1 day')),
        ];
    }

    private function conn(): \Doctrine\DBAL\Connection
    {
        return $this->emProvider->getEntityManager()->getConnection();
    }
}
