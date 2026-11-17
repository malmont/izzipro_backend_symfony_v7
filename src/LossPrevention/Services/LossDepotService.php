<?php

namespace App\LossPrevention\Services;

use App\LossPrevention\Entity\LossEvidence;
use App\LossPrevention\Entity\LossFinding;
use App\LossPrevention\Entity\LossJournalEntry;
use App\LossPrevention\Entity\LossPiece;
use App\Services\TenantEntityManagerProvider;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

/**
 * Les règles du dépôt et des heures d'ouverture (lot L4-c, `docs/prevention-pertes.md` § 17.15). Une étape de l'analyse à curseur
 * (§ 3), jouée à la fin de chaque passage, après la sortie ; elle lit les mouvements de zone des vraies pièces par rang.
 *
 * - **STOCKROOM_NOT_SHELVED** : une pièce lue à la porte de la réserve (`stockroom_door`), puis ni vue en rayon, au comptoir (antenne
 *   ou tapis) ni de nouveau dans la réserve, ni vendue, dans le délai. Une antenne ne dit pas le sens du passage : une pièce rentrée
 *   dans la réserve est revue à l'intérieur, et c'est ce qui l'explique. Muette tant qu'aucune antenne du rayon ou du comptoir (ni tapis)
 *   n'est en service : sans elles, toute sortie de la réserve serait un faux constat.
 * - **BACK_DOOR** : une pièce lue à la porte arrière de la réserve (`back_door`), pas retirée (une pièce retirée le jour même, avant le
 *   jugement, ne l'est plus : rien n'est jugé d'une pièce retirée). Jugée à la fin de la journée du magasin de la lecture : elle a son
 *   propre curseur (`back_door_cursor`), pour ne pas retenir les deux autres règles. Une antenne ne dit pas le sens : une pièce qui
 *   revient par cette porte ouvre aussi un constat, que le gérant classe sans suite.
 * - **OUT_OF_HOURS** : une arrivée ou un départ de zone hors des heures d'ouverture CONFIRMÉES par le gérant, marges comprises. Sans
 *   heures confirmées, la règle est muette.
 * - Les trois : **un constat par journée du magasin et par zone**, jamais par personne ; chaque pièce y est une preuve (un carton ne fait
 *   pas vingt constats) ; la gravité monte d'un cran à `minCount` pièces distinctes. Jamais une pièce d'exercice ni retirée, une lecture
 *   à l'heure douteuse ou par une puce retirée, une lecture d'avant le plancher de la notice aux salariés, d'avant la première fois.
 * - **Jamais trop tôt** : une sortie de la réserve n'est jugée qu'une fois le délai et la fenêtre du rattrapage passés, et la caisse
 *   lue jusque-là (`$horizon`) ; une lecture de la porte arrière, une fois sa journée finie. Le curseur s'arrête devant une lecture
 *   pas encore mûre.
 *
 * Aucun nom de personne n'est lu ni écrit ; rien n'est écrit dans la caisse, le stock, le catalogue ni les réservations.
 */
final class LossDepotService
{
    /** Mouvements jugés au plus par passage et par curseur ; au-delà, l'analyse dit qu'il en reste (`pending`) */
    public const BATCH = 500;
    /** Marge autour des heures d'ouverture (minutes ; réglable) */
    public const MARGIN_MINUTES = 60;
    public const MARGIN_BOUNDS = ['min' => 0, 'max' => 240];
    /** Pièces gardées au plus dans les faits d'un constat (le compte, lui, continue) */
    public const MAX_PIECES = 200;

    private const DEPOT = 'depot_cursor';
    private const BACK = 'back_door_cursor';
    private const MOVES = "e.type IN ('ZONE_SEEN', 'ZONE_LEFT') AND NOT e.exercise";
    private const BACK_READS = "e.type = 'ZONE_SEEN' AND NOT e.exercise AND ((e.data)::jsonb ->> 'zone') = 'back_door'";

    public function __construct(private readonly TenantEntityManagerProvider $emProvider, private readonly ?LoggerInterface $logger = null, private readonly ?LossExitService $exits = null)
    {
    }

    /**
     * Juge les mouvements de zone arrivés depuis le passage précédent : réserve et heures d'ouverture, puis porte arrière.
     *
     * @param array<string, array{enabled: bool, params: array<string, int>}> $configs réglages en vigueur des trois règles
     * @param int                                                              $horizon heure (UTC) jusqu'à laquelle les ventes ont été lues
     *
     * @return array{judged: int, pending: bool}
     */
    public function sweep(LossAnalysisContext $ctx, array $configs, int $horizon, int $catchUpWindow, ?int $now = null): array
    {
        $now ??= time();
        $limit = min($horizon, $now);
        $conn = $ctx->conn;
        $hours = self::hours($conn);
        // une antenne du rayon ou du comptage EN ORDRE (pas en panne, pas retirée), ou un tapis : sans elles, la règle de la réserve se tait
        $shelves = (bool) $conn->fetchOne("SELECT EXISTS (SELECT 1 FROM loss_device WHERE NOT exercise AND status = 'ok'"
            . " AND ((kind = 'reader' AND zone IN ('floor', 'counter')) OR kind = 'counter_mat'))");
        $stockroom = ($configs[LossRuleCatalog::STOCKROOM_NOT_SHELVED]['enabled'] ?? false) && $shelves;
        $delay = (int) ($configs[LossRuleCatalog::STOCKROOM_NOT_SHELVED]['params']['minutes'] ?? 30) * 60;
        $depot = $this->pass($ctx, self::DEPOT, self::MOVES,
            // une sortie de la réserve attend le délai et la fenêtre du rattrapage, et que le boîtier ait déposé un fait plus récent que le
            // délai (un boîtier qui rattrape son retard n'a peut-être pas encore envoyé la lecture du rayon), ou 24 h ; le reste, rien
            function (int $at, array $read, array $data) use ($conn, $stockroom, $delay, $catchUpWindow): int {
                if (!$stockroom || $read['type'] !== 'ZONE_SEEN' || ($data['zone'] ?? null) !== 'stockroom_door') {
                    return $at;
                }
                $until = $at + $delay;
                $later = $read['box_id'] === null || $conn->fetchOne('SELECT 1 FROM loss_piece_event f WHERE f.box_id = ? AND f.occurred_at > ? LIMIT 1',
                    [(int) $read['box_id'], gmdate('Y-m-d H:i:s', $until)]) !== false;

                return $later ? $until + $catchUpWindow : max($until + $catchUpWindow, $until + 86400);
            }, $limit, true, function (array $piece, array $read, array $data, \DateTimeImmutable $readAt, string $day) use ($ctx, $configs, $hours, $stockroom, $delay): void {
                $zone = (string) ($data['zone'] ?? '');
                if (($configs[LossRuleCatalog::OUT_OF_HOURS]['enabled'] ?? false) && $hours !== null && !self::open($hours, $readAt, $ctx->clock->timezone())) {
                    $this->record($ctx, LossRuleCatalog::OUT_OF_HOURS, $zone, $day, $piece, $read, $readAt, (int) $configs[LossRuleCatalog::OUT_OF_HOURS]['params']['minCount'],
                        LossExitService::LEVEL_LOW, LossExitService::LEVEL_MEDIUM);
                }
                if ($stockroom && $read['type'] === 'ZONE_SEEN' && $zone === 'stockroom_door' && !$this->shelved($ctx->conn, $piece, $read, $readAt, $delay)) {
                    $this->record($ctx, LossRuleCatalog::STOCKROOM_NOT_SHELVED, $zone, $day, $piece, $read, $readAt,
                        (int) $configs[LossRuleCatalog::STOCKROOM_NOT_SHELVED]['params']['minCount'], LossExitService::LEVEL_LOW, LossExitService::LEVEL_MEDIUM);
                }
            });
        $back = $this->pass($ctx, self::BACK, self::BACK_READS,
            // mûre à la fin de la journée du magasin de la lecture : un retrait de la pièce ce jour-là l'explique
            static fn (int $at, array $read, array $data): int => $ctx->clock->range($ctx->clock->day(new \DateTimeImmutable('@' . $at)), null)[1]->getTimestamp(),
            $limit, false, function (array $piece, array $read, array $data, \DateTimeImmutable $readAt, string $day) use ($ctx, $configs): void {
                if (!($configs[LossRuleCatalog::BACK_DOOR]['enabled'] ?? false)) {
                    return;
                }
                // une pièce retirée LE JOUR MÊME (casse, retour au fournisseur) l'explique ; un retrait d'un autre jour, non
                if ($piece['status'] === LossPiece::RETIRED && $piece['retired_at'] !== null && $ctx->clock->day(LossAuditEntry::utc((string) $piece['retired_at'])) === $day) {
                    return;
                }
                // une vente de la pièce l'explique (un colis web remis au transporteur), avec le constat faible si l'article n'est pas
                // entièrement étiqueté, comme à la sortie
                if ($this->exits?->explainedBySale($ctx, $configs, $piece, $read, $readAt, $day) === true) {
                    return;
                }
                $this->record($ctx, LossRuleCatalog::BACK_DOOR, 'back_door', $day, $piece, $read, $readAt, (int) $configs[LossRuleCatalog::BACK_DOOR]['params']['minCount'],
                    LossExitService::LEVEL_MEDIUM, LossExitService::LEVEL_HIGH);
            });

        return ['judged' => $depot['judged'] + $back['judged'], 'pending' => $depot['pending'] || $back['pending']];
    }

    /**
     * Le rang du dernier mouvement connu de chaque curseur (posés au premier passage, ou quand la notice aux salariés est confirmée).
     *
     * @return array{depot_cursor: int, back_door_cursor: int}
     */
    public static function lastReads(Connection $conn): array
    {
        return [
            self::DEPOT => (int) $conn->fetchOne('SELECT COALESCE(MAX(e.id), 0) FROM loss_piece_event e WHERE ' . self::MOVES),
            self::BACK => (int) $conn->fetchOne('SELECT COALESCE(MAX(e.id), 0) FROM loss_piece_event e WHERE ' . self::BACK_READS),
        ];
    }

    /**
     * Les heures d'ouverture confirmées, ou null (règle « hors des heures » muette).
     *
     * @return array{days: array<int, array{open: int, close: int}|null>, margin: int}|null minutes depuis minuit, par jour ISO (1 = lundi)
     */
    public static function hours(Connection $conn): ?array
    {
        $row = $conn->fetchAssociative('SELECT opening_hours, hours_margin_minutes FROM loss_setting ORDER BY id LIMIT 1');
        $days = $row !== false && $row['opening_hours'] !== null ? json_decode((string) $row['opening_hours'], true) : null;
        if (!is_array($days)) {
            return null;
        }
        $out = [];
        for ($d = 1; $d <= 7; ++$d) {
            $range = $days[(string) $d] ?? null;
            $out[$d] = is_array($range) && is_int($range['open'] ?? null) && is_int($range['close'] ?? null) ? ['open' => $range['open'], 'close' => $range['close']] : null;
        }

        return ['days' => $out, 'margin' => $row['hours_margin_minutes'] !== null ? (int) $row['hours_margin_minutes'] : self::MARGIN_MINUTES];
    }

    /**
     * L'instant est-il dans les heures d'ouverture (marges comprises), à l'heure du magasin ? Une plage qui déborde, avec sa marge, sur la
     * veille ou le lendemain compte aussi ce jour-là (fermeture à 23 h 30 : ouvert jusqu'à 0 h 30 le lendemain).
     *
     * @param array{days: array<int, array{open: int, close: int}|null>, margin: int} $hours
     */
    public static function open(array $hours, \DateTimeImmutable $at, \DateTimeZone $zone): bool
    {
        $local = $at->setTimezone($zone);
        $minute = (int) $local->format('G') * 60 + (int) $local->format('i');
        $today = (int) $local->format('N');
        $margin = $hours['margin'];
        foreach ([[$today, 0], [$today === 1 ? 7 : $today - 1, 1440], [$today === 7 ? 1 : $today + 1, -1440]] as [$day, $shift]) {
            $range = $hours['days'][$day];
            // la plage du jour voisin, ramenée sur l'axe d'aujourd'hui (la veille : 1 440 minutes plus tôt ; le lendemain : plus tard)
            if ($range !== null && $minute + $shift >= $range['open'] - $margin && $minute + $shift < $range['close'] + $margin) {
                return true;
            }
        }

        return false;
    }

    /**
     * Lot L5-c (demande du chef de projet, 17/11/2026) : à quelle distance des heures d'ouverture CONFIRMÉES (sans la marge) tombe un instant,
     * pour l'avis de l'IA, qui ne reçoit jamais l'heure elle-même. Null quand l'instant est dans une plage ; sinon le côté le plus proche
     * (`before_opening` : avant une ouverture, `after_closing` : après une fermeture) et l'écart en minutes. Plages de la veille et du
     * lendemain comprises, comme `open`.
     *
     * @param array{days: array<int, array{open: int, close: int}|null>, margin: int} $hours
     *
     * @return array{side: string, minutes: int}|null
     */
    public static function outside(array $hours, \DateTimeImmutable $at, \DateTimeZone $zone): ?array
    {
        $local = $at->setTimezone($zone);
        $minute = (int) $local->format('G') * 60 + (int) $local->format('i');
        $today = (int) $local->format('N');
        $best = null;
        foreach ([[$today, 0], [$today === 1 ? 7 : $today - 1, -1440], [$today === 7 ? 1 : $today + 1, 1440]] as [$day, $shift]) {
            $range = $hours['days'][$day];
            if ($range === null) {
                continue;
            }
            [$open, $close] = [$range['open'] + $shift, $range['close'] + $shift];
            if ($minute >= $open && $minute < $close) {
                return null;
            }
            $candidate = $minute < $open ? ['side' => 'before_opening', 'minutes' => $open - $minute] : ['side' => 'after_closing', 'minutes' => $minute - $close];
            if ($best === null || $candidate['minutes'] < $best['minutes']) {
                $best = $candidate;
            }
        }

        return $best;
    }

    /**
     * Un passage d'un curseur : lit les mouvements après le curseur (sous le verrou des lectures, comme la sortie), passe ceux qui sont
     * sans objet, s'arrête devant le premier pas encore mûr, juge les autres chacun sous son point de reprise.
     *
     * @param callable(int, array<string, mixed>, array<string, mixed>): int                                            $ripe   heure de maturité d'une lecture
     * @param bool                                                                                                      $skipRetired une pièce retirée n'est pas jugée
     * @param callable(array<string, mixed>, array<string, mixed>, array<string, mixed>, \DateTimeImmutable, string): void $judge
     *
     * @return array{judged: int, pending: bool}
     */
    private function pass(LossAnalysisContext $ctx, string $column, string $filter, callable $ripe, int $limit, bool $skipRetired, callable $judge): array
    {
        $conn = $ctx->conn;
        $conn->fetchOne('SELECT pg_advisory_lock(?)', [LossExitService::READS_LOCK]);
        $conn->executeStatement('SAVEPOINT loss_depot_lock');
        try {
            $cursor = $conn->fetchOne('SELECT ' . $column . ' FROM loss_setting ORDER BY id LIMIT 1');
            $first = $cursor === null || $cursor === false;
            if ($first) {
                // première fois : les mouvements d'avant ne sont pas jugés (ils n'avaient aucune règle quand ils ont eu lieu)
                $this->moveCursor($conn, $column, self::lastReads($conn)[$column]);
                $reads = [];
            } else {
                $reads = $conn->fetchAllAssociative('SELECT e.id, e.type, e.occurred_at, e.piece_id, e.device_id, e.box_id, e.data FROM loss_piece_event e WHERE ' . $filter
                    . ' AND e.id > ? ORDER BY e.id LIMIT ' . self::BATCH, [(int) $cursor]);
            }
            $conn->executeStatement('RELEASE SAVEPOINT loss_depot_lock');
        } catch (\Throwable $e) {
            $conn->executeStatement('ROLLBACK TO SAVEPOINT loss_depot_lock');
            $conn->fetchOne('SELECT pg_advisory_unlock(?)', [LossExitService::READS_LOCK]);
            throw $e;
        }
        $conn->fetchOne('SELECT pg_advisory_unlock(?)', [LossExitService::READS_LOCK]);
        if ($first) {
            return ['judged' => 0, 'pending' => false];
        }
        $cursor = (int) $cursor;
        $judged = 0;
        $stopped = false;
        $skipped = [];
        foreach ($reads as $read) {
            $data = json_decode((string) $read['data'], true) ?: [];
            // une arrivée redite (le boîtier a redémarré et redit ce qui est à portée) n'est pas un mouvement
            if (($data['clockDoubt'] ?? false) || ($data['tagRetired'] ?? false) || ($data['repeat'] ?? false) || $read['piece_id'] === null || $ctx->beforeFloor((string) $read['occurred_at'])) {
                $cursor = (int) $read['id'];
                ++$judged;
                continue;
            }
            $at = (new \DateTimeImmutable((string) $read['occurred_at'], new \DateTimeZone('UTC')))->getTimestamp();
            if ($ripe($at, $read, $data) > $limit) {
                $stopped = true;
                break;
            }
            $cursor = (int) $read['id'];
            ++$judged;
            $conn->executeStatement('SAVEPOINT loss_depot_read');
            $counters = $ctx->writer->counters();
            try {
                $piece = $conn->fetchAssociative('SELECT p.id, p.number, p.status, p.exercise, p.variant_id, p.retired_at FROM loss_piece p WHERE p.id = ?', [(int) $read['piece_id']]);
                if ($piece !== false && !(bool) $piece['exercise'] && (!$skipRetired || $piece['status'] !== LossPiece::RETIRED)) {
                    $readAt = new \DateTimeImmutable((string) $read['occurred_at'], new \DateTimeZone('UTC'));
                    $judge($piece, $read, $data, $readAt, $ctx->clock->day($readAt));
                }
                $conn->executeStatement('RELEASE SAVEPOINT loss_depot_read');
            } catch (\Throwable $e) {
                $conn->executeStatement('ROLLBACK TO SAVEPOINT loss_depot_read');
                $ctx->writer->restoreCounters($counters);
                $skipped[] = (int) $read['id'];
                $this->logger?->error(sprintf('Prévention des pertes : mouvement de zone %d en échec, sauté : %s', (int) $read['id'], $e->getMessage()), ['exception' => $e]);
            }
        }
        $this->moveCursor($conn, $column, $cursor);
        if ($skipped !== []) {
            $this->emProvider->getEntityManager()->getRepository(LossJournalEntry::class)->append(LossJournalEntry::ENTRIES_SKIPPED, null, null,
                ['count' => 0, 'sequences' => [], 'zoneMoves' => array_slice($skipped, 0, 50), 'zoneMovesCount' => count($skipped)]);
        }

        return ['judged' => $judged, 'pending' => !$stopped && count($reads) >= self::BATCH];
    }

    /**
     * La pièce sortie de la réserve a-t-elle été revue en rayon, au comptoir (antenne ou tapis), dans la réserve, ou vendue, dans le délai ?
     *
     * @param array<string, mixed> $piece
     * @param array<string, mixed> $read
     */
    private function shelved(Connection $conn, array $piece, array $read, \DateTimeImmutable $readAt, int $delay): bool
    {
        $params = ['piece' => (int) $piece['id'], 'variant' => (int) $piece['variant_id'], 'id' => (int) $read['id'], 'at' => $readAt->format('Y-m-d H:i:s'),
            'until' => $readAt->modify('+' . $delay . ' seconds')->format('Y-m-d H:i:s'), 'before' => LossSaleLinkService::CATCH_UP_BEFORE];

        return $conn->fetchOne(
            "SELECT 1 FROM loss_piece_event f WHERE f.piece_id = :piece AND f.type = 'ZONE_SEEN' AND NOT f.exercise AND f.id <> :id"
            . " AND ((f.data)::jsonb ->> 'zone') IN ('floor', 'counter', 'stockroom') AND f.occurred_at >= :at AND f.occurred_at <= :until"
            . ' UNION ALL SELECT 1 FROM loss_counter_presence c WHERE c.piece_id = :piece AND NOT c.exercise AND c.since >= :at AND c.since <= :until'
            . ' UNION ALL SELECT 1 FROM loss_sale_unit u WHERE u.piece_id = :piece AND u.sold_at >= CAST(:at AS timestamp) - make_interval(secs => :before) AND u.sold_at <= :until'
            // la pièce était encore PRÉSENTE en rayon, au comptoir ou dans la réserve autour de la lecture (une antenne ne redit pas l'arrivée
            // d'une pièce qu'elle voit déjà : un portant près de la porte, une étagère contre elle)
            . " UNION ALL SELECT 1 FROM loss_zone_presence z WHERE z.piece_id = :piece AND NOT z.exercise AND z.zone IN ('floor', 'counter', 'stockroom')"
            . ' AND z.since <= :until AND (z.until IS NULL OR z.until >= CAST(:at AS timestamp) - make_interval(secs => :before))'
            // une vente de l'article qui attend encore sa pièce (vendue au comptoir sans tapis, sans rattrapage) : une vente par sortie, au plus
            . ' UNION ALL SELECT 1 FROM loss_sale_unit w WHERE w.variant_id = :variant AND w.piece_id IS NULL AND w.returned_at IS NULL'
            . ' AND w.sold_at >= CAST(:at AS timestamp) - make_interval(secs => :before) AND w.sold_at <= :until'
            . ' AND (SELECT COUNT(*) FROM loss_sale_unit w2 WHERE w2.variant_id = :variant AND w2.piece_id IS NULL AND w2.returned_at IS NULL'
            . ' AND w2.sold_at >= CAST(:at AS timestamp) - make_interval(secs => :before) AND w2.sold_at <= :until) > (SELECT COUNT(DISTINCT o.piece_id) FROM loss_piece_event o'
            . " JOIN loss_piece op ON op.id = o.piece_id WHERE o.type = 'ZONE_SEEN' AND NOT o.exercise AND ((o.data)::jsonb ->> 'zone') = 'stockroom_door' AND op.variant_id = :variant"
            . ' AND o.piece_id <> :piece AND o.occurred_at >= CAST(:at AS timestamp) - make_interval(secs => :before) AND o.id < :id)'
            . ' LIMIT 1', $params) !== false;
    }

    /**
     * Un constat par règle, journée du magasin et zone ; la pièce s'y ajoute (une fois) et le mouvement en est une preuve.
     *
     * @param array<string, mixed> $piece
     * @param array<string, mixed> $read
     */
    private function record(LossAnalysisContext $ctx, string $rule, string $zone, string $day, array $piece, array $read, \DateTimeImmutable $readAt, int $minCount, string $base, string $raised): void
    {
        $key = $rule . ':zone:' . $zone . ':day:' . $day;
        $known = $ctx->writer->factsOf($key);
        $pieces = array_map('intval', is_array($known['pieces'] ?? null) ? $known['pieces'] : []);
        $count = (int) ($known['count'] ?? 0);
        if (!in_array((int) $piece['id'], $pieces, true)) {
            ++$count;
            if (count($pieces) < self::MAX_PIECES) {
                $pieces[] = (int) $piece['id'];
            }
        }
        $serial = $read['device_id'] !== null ? $ctx->conn->fetchOne('SELECT serial FROM loss_device WHERE id = ?', [(int) $read['device_id']]) : null;
        $ctx->writer->record([
            'rule_code' => $rule, 'variant' => LossFinding::VARIANT_RULE, 'dedup_key' => $key,
            'store_day' => $day, 'occurred_from' => $readAt->format('Y-m-d H:i:s'), 'occurred_to' => $readAt->format('Y-m-d H:i:s'),
            'cash_register_id' => null, 'session_id' => null, 'amount_at_stake' => null, 'currency' => $ctx->currency,
            'subject_type' => 'zone', 'subject_id' => 0,
            'facts' => ['zone' => $zone, 'count' => $count, 'minCount' => $minCount, 'pieces' => $pieces, 'level' => $count >= $minCount ? $raised : $base],
            'on_duty' => [], // jamais par personne
            'backfill' => false,
        ], [['kind' => LossEvidence::ZONE_MOVE, 'at' => $readAt, 'refType' => 'piece_event', 'refId' => (int) $read['id'], 'sequence' => null, 'hash' => null,
            'snapshot' => array_filter([
                'pieceId' => (int) $piece['id'], 'pieceNumber' => (int) $piece['number'], 'variantId' => (int) $piece['variant_id'],
                'article' => LossExitService::article($ctx->conn, (int) $piece['variant_id']), 'move' => (string) $read['type'], 'zone' => $zone,
                'at' => LossAuditEntry::iso($readAt), 'device' => is_string($serial) ? $serial : null,
            ], static fn (mixed $v): bool => $v !== null && $v !== '')]]);
    }

    private function moveCursor(Connection $conn, string $column, int $cursor): void
    {
        $conn->executeStatement('UPDATE loss_setting SET ' . $column . ' = ?', [$cursor]);
    }
}
