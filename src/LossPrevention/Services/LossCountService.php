<?php

namespace App\LossPrevention\Services;

use App\Gestion\Services\GestionConflictException;
use App\Gestion\Services\GestionValidationException;
use App\LossPrevention\Entity\LossCount;
use App\LossPrevention\Entity\LossDevice;
use App\LossPrevention\Entity\LossEvidence;
use App\LossPrevention\Entity\LossFinding;
use App\LossPrevention\Entity\LossPieceEvent;
use App\LossPrevention\Repository\LossCountRepository;
use App\Services\TenantEntityManagerProvider;
use Doctrine\DBAL\Connection;

/**
 * Le comptage et le bilan du soir (lot L4-d, `docs/prevention-pertes.md` § 17.16).
 *
 * - **Comptage** : le gérant l'ouvre, passe le lecteur à main, le ferme. Ses lectures sont les lectures du lecteur à main (`SPOT_CHECK`)
 *   entre l'ouverture et la fermeture : cela ne tient que parce qu'un seul comptage est ouvert à la fois (par genre), ce que la base
 *   garantit. Resté ouvert plus de `EXPIRE_HOURS` heures, il se ferme seul, sans conclusion : aucune absence n'est comptée.
 * - **Fermeture** : les pièces attendues (vraies pièces à puce, ni vendues ni retirées, posées avant l'ouverture ; pour une zone, celles vues
 *   en dernier dans cette zone) et lues sont écrites ; une pièce non lue qui ne l'avait pas été non plus au comptage fermé précédent qui la
 *   couvrait passe à l'état `missing` (son état d'avant est gardé), et un constat `PIECE_MISSING` s'ouvre pour ce comptage, chaque pièce
 *   en preuve, jamais par personne. Rien n'est écrit dans le stock : l'écart par article est seulement lu.
 * - **Retrouvée** : une pièce absente relue ensuite (n'importe quelle lecture à une heure sûre) reprend son état d'avant, et le constat de
 *   son comptage reçoit une preuve « retrouvée ». Joué à chaque passage de l'analyse.
 * - **Bilan du soir** : des nombres de la journée, calculés à la demande, sans nom ni classement.
 */
class LossCountService
{
    public const EXPIRE_HOURS = 12;
    /** Pièces retrouvées au plus par passage */
    public const FOUND_BATCH = 500;
    /** Pièces rendues au plus dans la fiche d'un comptage, articles au plus dans l'écart */
    public const DETAIL_LIMIT = 200;
    public const LIST_LIMIT = 50;
    /** Pièces non vues depuis N jours, dans le bilan */
    public const UNSEEN_DAYS = 30;
    public const UNSEEN_BOUNDS = ['min' => 1, 'max' => 365];

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly LossSite $site,
        private readonly LossTexts $texts,
        private readonly LossRuleService $rules,
        private readonly LossSettingsService $settings,
        private readonly LossFindingWriter $writer,
    ) {
    }

    /**
     * @param array<string, mixed> $body { scope: all | <zone> }
     *
     * @return array<string, mixed> le comptage ouvert
     */
    public function open(array $body, int $userId, bool $exercise, string $locale): array
    {
        $scope = $body['scope'] ?? LossCount::ALL;
        if (!is_string($scope) || ($scope !== LossCount::ALL && !in_array($scope, LossDevice::ZONES, true))) {
            throw new GestionValidationException(['scope' => 'INVALID_SCOPE']);
        }
        $this->expire();
        $id = $this->counts()->open($scope, $exercise, $userId, gmdate('Y-m-d H:i:s'));
        if ($id === null) {
            throw new GestionConflictException('COUNT_ALREADY_OPEN', $this->texts->t('counts.error.COUNT_ALREADY_OPEN', $locale), 409);
        }

        return $this->detail($id, $exercise, $locale);
    }

    /** @return array<string, mixed> le comptage fermé, ses absences et l'écart par article */
    public function close(int $id, int $userId, bool $exercise, string $locale): array
    {
        $this->expire();
        $conn = $this->conn();
        $conn->beginTransaction();
        try {
            $count = $this->counts()->findCount($id, $exercise, true);
            if ($count === null) {
                throw new GestionConflictException('COUNT_NOT_FOUND', $this->texts->t('counts.error.COUNT_NOT_FOUND', $locale), 404);
            }
            if ($count['status'] !== LossCount::OPEN) {
                throw new GestionConflictException('COUNT_NOT_OPEN', $this->texts->t('counts.error.COUNT_NOT_OPEN', $locale), 409);
            }
            $now = gmdate('Y-m-d H:i:s');
            $numbers = $this->counts()->writePieces($id, (string) $count['scope'], $exercise, (string) $count['opened_at'], $now);
            $missing = $this->counts()->markMissing($this->counts()->absentTwice($id, $exercise), $id, $now);
            $this->counts()->close($id, $userId, $now, $numbers + ['absent' => $numbers['expected'] - $numbers['found'], 'missing' => count($missing)]);
            // un constat pour les vraies pièces seulement, si la règle est allumée et l'analyse permise (droit du module, notice aux salariés)
            $config = $this->rules->effective(LossRuleCatalog::PIECE_MISSING);
            if (!$exercise && $missing !== [] && ($config['enabled'] ?? false) && $this->settings->blockedReason() === null) {
                $this->finding($id, (string) $count['scope'], (string) $count['opened_at'], $now, $missing, (int) $config['params']['minCount']);
            }
            $conn->commit();
        } catch (\Throwable $e) {
            if ($conn->isTransactionActive()) {
                $conn->rollBack();
            }
            throw $e;
        }
        $this->writer->flushCounters();

        return $this->detail($id, $exercise, $locale);
    }

    /** @return array{counts: list<array<string, mixed>>, open: ?array<string, mixed>} */
    public function list(bool $exercise, string $locale): array
    {
        $this->expire();
        $rows = array_map(fn (array $c): array => $this->view($c, $locale), $this->counts()->list($exercise, self::LIST_LIMIT));

        return ['counts' => $rows, 'open' => array_values(array_filter($rows, static fn (array $c): bool => $c['status'] === LossCount::OPEN))[0] ?? null,
            'expireHours' => self::EXPIRE_HOURS];
    }

    /** @return array<string, mixed> */
    public function detail(int $id, bool $exercise, string $locale): array
    {
        $count = $this->counts()->findCount($id, $exercise);
        if ($count === null) {
            throw new GestionConflictException('COUNT_NOT_FOUND', $this->texts->t('counts.error.COUNT_NOT_FOUND', $locale), 404);
        }
        $out = $this->view($count, $locale);
        if ($count['status'] === LossCount::CLOSED) {
            $conn = $this->conn();
            $out['absentPieces'] = array_map(fn (array $p): array => ['pieceId' => (int) $p['id'], 'number' => (int) $p['number'], 'article' => LossExitService::article($conn, (int) $p['variant_id']),
                'missing' => $p['status'] === 'missing', 'lastZone' => $p['last_zone'], 'lastZoneLabel' => $this->zone($p['last_zone'], $locale),
                'lastSeenAt' => $p['last_seen'] !== null ? LossAuditEntry::iso(LossAuditEntry::utc((string) $p['last_seen'])) : null], $this->counts()->absentPieces($id, self::DETAIL_LIMIT));
            // l'écart par article : le stock du catalogue (lu), les pièces attendues et lues ; la correction du stock reste un geste de la gestion
            $out['stockGap'] = array_map(fn (array $g): array => ['variantId' => (int) $g['variant_id'], 'article' => LossExitService::article($conn, (int) $g['variant_id']),
                'catalogStock' => $g['stock'] !== null ? (int) $g['stock'] : null, 'expectedPieces' => (int) $g['expected'], 'foundPieces' => (int) $g['found']], $this->counts()->stockGap($id, self::DETAIL_LIMIT));
            $out['stockNotice'] = $this->texts->t('counts.stockNotice', $locale);
        }

        return $out;
    }

    /**
     * À chaque passage de l'analyse : les comptages oubliés se ferment sans conclusion, et les pièces absentes relues reprennent leur état
     * (le constat de leur comptage le dit).
     *
     * @return array{expired: int, found: int}
     */
    public function sweep(LossFindingSink $writer): array
    {
        $expired = $this->expire();
        $found = 0;
        $now = gmdate('Y-m-d H:i:s');
        foreach ($this->counts()->foundAgain(LossPieceEvent::READS, self::FOUND_BATCH) as $piece) {
            if (!$this->counts()->restore((int) $piece['id'], $now)) {
                continue;
            }
            ++$found;
            $key = LossRuleCatalog::PIECE_MISSING . ':count:' . (int) $piece['missing_count_id'];
            $facts = $piece['missing_count_id'] !== null && !(bool) $piece['exercise'] ? $writer->factsOf($key) : null;
            if ($facts === null) {
                continue; // pas de constat pour ce comptage (exercice, règle éteinte) : la pièce reprend son état, rien d'autre
            }
            $at = LossAuditEntry::utc((string) $piece['event_at']);
            $facts['found'] = (int) ($facts['found'] ?? 0) + 1;
            $writer->record(['rule_code' => LossRuleCatalog::PIECE_MISSING, 'variant' => LossFinding::VARIANT_RULE, 'dedup_key' => $key, 'store_day' => $this->site->clock()->day($at),
                'occurred_from' => $at->format('Y-m-d H:i:s'), 'occurred_to' => $at->format('Y-m-d H:i:s'), 'cash_register_id' => null, 'session_id' => null, 'amount_at_stake' => null,
                'currency' => $this->site->currency(), 'subject_type' => 'count', 'subject_id' => (int) $piece['missing_count_id'], 'facts' => $facts, 'on_duty' => [], 'backfill' => false],
                [['kind' => LossEvidence::COUNT_FOUND, 'at' => $at, 'refType' => 'piece_event', 'refId' => (int) $piece['event_id'], 'sequence' => null, 'hash' => null,
                    'snapshot' => array_filter(['pieceId' => (int) $piece['id'], 'pieceNumber' => (int) $piece['number'], 'foundAt' => LossAuditEntry::iso($at),
                        'move' => (string) $piece['event_type'], 'zone' => $piece['zone']], static fn (mixed $v): bool => $v !== null)]]);
        }

        return ['expired' => $expired, 'found' => $found];
    }

    /**
     * Le bilan du soir : des nombres d'une journée du magasin, sans aucun nom.
     *
     * @param array<string, mixed> $query day (Y-m-d ; défaut : aujourd'hui), days (pièces non vues depuis N jours)
     *
     * @return array<string, mixed>
     */
    public function evening(array $query, string $locale, bool $exercise = false): array
    {
        $clock = $this->site->clock();
        $day = $query['day'] ?? null;
        $days = $query['days'] ?? null;
        $errors = [];
        if ($day !== null && $day !== '' && !(is_string($day) && LossFindingReader::isDay($day))) {
            $errors['day'] = 'INVALID_DATE';
        }
        if ($days !== null && $days !== '' && (!is_numeric($days) || (int) $days != $days || (int) $days < self::UNSEEN_BOUNDS['min'] || (int) $days > self::UNSEEN_BOUNDS['max'])) {
            $errors['days'] = 'PARAM_OUT_OF_RANGE';
        }
        if ($errors !== []) {
            throw new GestionValidationException($errors);
        }
        $day = is_string($day) && $day !== '' ? $day : $clock->day(new \DateTimeImmutable());
        $days = $days !== null && $days !== '' ? (int) $days : self::UNSEEN_DAYS;

        return $this->eveningOf($day, $days, $locale, $exercise);
    }

    /**
     * Le bilan d'une journée (aussi joint au résumé du jour). En exercice : les mêmes nombres sur les pièces, faits, constats, appareils et
     * comptages d'exercice (les ventes reliées n'existent qu'en vrai : leurs nombres valent 0).
     *
     * @return array<string, mixed>
     */
    public function eveningOf(string $day, int $days, string $locale, bool $exercise = false): array
    {
        $conn = $this->conn();
        [$start, $end] = $this->site->clock()->range($day, $day);
        $ex = $exercise ? 'true' : 'false';
        $range = ['from' => $start->format('Y-m-d H:i:s'), 'to' => $end->format('Y-m-d H:i:s'), 'ex' => $ex];
        $sold = $exercise ? ['tracked' => 0, 'unread' => 0]
            : $conn->fetchAssociative('SELECT COUNT(*) AS tracked, COUNT(*) FILTER (WHERE piece_id IS NULL) AS unread FROM loss_sale_unit WHERE sold_at >= :from AND sold_at < :to',
                ['from' => $range['from'], 'to' => $range['to']]);
        $unseenSince = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('-' . $days . ' days')->format('Y-m-d H:i:s');
        $unseen = (int) $conn->fetchOne(
            "SELECT COUNT(*) FROM loss_piece p WHERE p.exercise = CAST(:ex AS boolean) AND p.status NOT IN ('sold', 'retired') AND p.created_at < :since"
            . " AND EXISTS (SELECT 1 FROM loss_tag t WHERE t.piece_id = p.id AND t.status = 'associated' AND t.exercise = CAST(:ex AS boolean))"
            . ' AND NOT EXISTS (SELECT 1 FROM loss_piece_event e WHERE e.piece_id = p.id AND e.occurred_at >= :since)', ['since' => $unseenSince, 'ex' => $ex]);

        return [
            'day' => $day,
            'exercise' => $exercise,
            'trackedSold' => (int) $sold['tracked'],
            'soldWithoutRead' => (int) $sold['unread'],
            'exitsWithoutSale' => (int) $conn->fetchOne("SELECT COUNT(*) FROM loss_finding WHERE rule_code = 'EXIT_WITHOUT_SALE' AND exercise = CAST(? AS boolean) AND store_day = ?", [$ex, $day]),
            'leftStockroom' => (int) $conn->fetchOne("SELECT COUNT(DISTINCT e.piece_id) FROM loss_piece_event e WHERE e.type = 'ZONE_SEEN' AND e.exercise = CAST(:ex AS boolean)"
                . " AND ((e.data)::jsonb ->> 'zone') = 'stockroom_door' AND e.occurred_at >= :from AND e.occurred_at < :to", $range),
            'unseenDays' => $days,
            'unseenPieces' => $unseen,
            'missingPieces' => (int) $conn->fetchOne("SELECT COUNT(*) FROM loss_piece WHERE exercise = CAST(? AS boolean) AND status = 'missing'", [$ex]),
            // antennes et tapis tombés dans la journée (un NOMBRE d'appareils) : le boîtier a dit qu'un appareil ne répondait plus
            'devicesDown' => (int) $conn->fetchOne("SELECT COUNT(DISTINCT e.device_id) FROM loss_box_event e WHERE e.type = 'SOURCE_DOWN' AND e.exercise = CAST(:ex AS boolean) AND e.device_id IS NOT NULL"
                . ' AND e.occurred_at >= :from AND e.occurred_at < :to', $range),
            'countsClosed' => (int) $conn->fetchOne("SELECT COUNT(*) FROM loss_count WHERE exercise = CAST(:ex AS boolean) AND status = 'closed' AND closed_at >= :from AND closed_at < :to", $range),
            'notice' => $this->texts->t('counts.eveningNotice', $locale),
        ];
    }

    /** Ferme sans conclusion les comptages ouverts depuis plus de `EXPIRE_HOURS` heures */
    public function expire(): int
    {
        return $this->counts()->expire(gmdate('Y-m-d H:i:s', time() - self::EXPIRE_HOURS * 3600), self::EXPIRE_HOURS);
    }

    /** @param list<int> $missing */
    private function finding(int $countId, string $scope, string $openedAt, string $closedAt, array $missing, int $minCount): void
    {
        $conn = $this->conn();
        $closed = LossAuditEntry::utc($closedAt);
        $evidence = [];
        foreach ($conn->fetchAllAssociative('SELECT id, number, variant_id FROM loss_piece WHERE id IN (?) ORDER BY number', [$missing], [\Doctrine\DBAL\ArrayParameterType::INTEGER]) as $p) {
            $evidence[] = ['kind' => LossEvidence::COUNT_ABSENCE, 'at' => $closed, 'refType' => 'piece', 'refId' => (int) $p['id'], 'sequence' => null, 'hash' => null,
                'snapshot' => ['pieceId' => (int) $p['id'], 'pieceNumber' => (int) $p['number'], 'variantId' => (int) $p['variant_id'], 'article' => LossExitService::article($conn, (int) $p['variant_id']),
                    'countId' => $countId]];
        }
        $count = count($missing);
        $this->writer->record([
            'rule_code' => LossRuleCatalog::PIECE_MISSING, 'variant' => LossFinding::VARIANT_RULE, 'dedup_key' => LossRuleCatalog::PIECE_MISSING . ':count:' . $countId,
            'store_day' => $this->site->clock()->day($closed), 'occurred_from' => $openedAt, 'occurred_to' => $closedAt,
            'cash_register_id' => null, 'session_id' => null, 'amount_at_stake' => null, 'currency' => $this->site->currency(),
            'subject_type' => 'count', 'subject_id' => $countId,
            'facts' => ['countId' => $countId, 'scope' => $scope, 'count' => $count, 'minCount' => $minCount, 'pieces' => array_slice($missing, 0, LossDepotService::MAX_PIECES),
                'found' => 0, 'level' => $count >= $minCount ? LossExitService::LEVEL_HIGH : LossExitService::LEVEL_MEDIUM],
            'on_duty' => [], // jamais par personne
            'backfill' => false,
        ], $evidence);
    }

    /** @param array<string, mixed> $c */
    private function view(array $c, string $locale): array
    {
        $iso = static fn (mixed $v): ?string => $v !== null ? LossAuditEntry::iso(LossAuditEntry::utc((string) $v)) : null;

        return ['id' => (int) $c['id'], 'scope' => (string) $c['scope'], 'scopeLabel' => $c['scope'] === LossCount::ALL ? $this->texts->t('counts.scope.all', $locale) : $this->zone((string) $c['scope'], $locale),
            'status' => (string) $c['status'], 'statusLabel' => $this->texts->t('counts.status.' . $c['status'], $locale), 'exercise' => (bool) $c['exercise'],
            'openedAt' => $iso($c['opened_at']), 'closedAt' => $iso($c['closed_at']),
            'expected' => $c['expected'] !== null ? (int) $c['expected'] : null, 'found' => $c['found'] !== null ? (int) $c['found'] : null,
            'absent' => $c['absent'] !== null ? (int) $c['absent'] : null, 'missing' => $c['missing'] !== null ? (int) $c['missing'] : null,
            'expiresAt' => $c['status'] === LossCount::OPEN ? LossAuditEntry::iso(LossAuditEntry::utc((string) $c['opened_at'])->modify('+' . self::EXPIRE_HOURS . ' hours')) : null];
    }

    private function zone(?string $zone, string $locale): ?string
    {
        return $zone !== null && $this->texts->has('boxes.zone.' . $zone, $locale) ? $this->texts->t('boxes.zone.' . $zone, $locale) : null;
    }

    private function counts(): LossCountRepository
    {
        return $this->emProvider->getEntityManager()->getRepository(LossCount::class);
    }

    private function conn(): Connection
    {
        return $this->emProvider->getEntityManager()->getConnection();
    }
}
