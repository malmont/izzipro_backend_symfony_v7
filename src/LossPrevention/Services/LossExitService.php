<?php

namespace App\LossPrevention\Services;

use App\LossPrevention\Entity\LossEvidence;
use App\LossPrevention\Entity\LossFinding;
use App\LossPrevention\Entity\LossJournalEntry;
use App\LossPrevention\Entity\LossPiece;
use App\LossPrevention\Entity\LossSaleUnit;
use App\LossPrevention\Entity\LossTag;
use App\Services\TenantEntityManagerProvider;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

/**
 * Les règles de la sortie (lot L4-b, `docs/prevention-pertes.md` § 17.13) : elles jugent les ARRIVÉES à la sortie (`ZONE_SEEN`,
 * zone `exit`) des vraies pièces, une fois que la caisse a pu les expliquer. Une étape de l'analyse à curseur (§ 3), jouée à la fin
 * de chaque passage, après le rattrapage ; elle lit les faits de pièces par rang (`loss_setting.exit_cursor`).
 *
 * - **EXIT_WITHOUT_SALE**, par pièce et par journée du magasin : une pièce ni vendue ni retirée (une pièce rendue compte) lue à la
 *   sortie, qu'aucune vente n'explique. Gravité haute. Les lectures suivantes du même jour s'ajoutent comme preuves : le constat ne
 *   se rouvre pas à chaque passage devant l'antenne.
 * - **EXIT_COVERED**, par article (variante) et par semaine : la lecture a été rattrapée sur une vente dont la puce n'avait pas été
 *   lue, alors que l'article n'est pas entièrement étiqueté (moins de pièces à puce non vendues que d'articles en stock) : on ne peut
 *   pas être sûr que la pièce sortie soit la pièce vendue. Gravité faible, moyenne à `minCount` dans la semaine. Un article
 *   entièrement étiqueté reste silencieux : la vente sans pièce ne peut être qu'une lecture manquée.
 * - **Jamais trop tôt** : une lecture n'est jugée que quand l'analyse a lu les ventes jusqu'à son heure plus la fenêtre du
 *   rattrapage (`$horizon`, donné par l'analyse). Sinon le curseur s'arrête devant elle, et elle attend le passage suivant.
 * - **Commandes web et locations** (lot L4-b2, § 17.14) : un article d'une commande web payée (retirée ou expédiée) explique une sortie
 *   de sa variante pendant la fenêtre des commandes web, comme une vente qui attend sa pièce ; une location en cours ce jour-là (de la veille de
 *   son début au lendemain de sa fin, en journées du magasin) explique la sortie d'une pièce de sa variante, au plus autant de pièces
 *   distinctes ce jour-là que d'unités louées (l'aller et le retour d'une même pièce le même jour comptent une fois). Une location
 *   n'ouvre jamais de constat faible : aucune pièce n'y est reliée.
 * - Jamais : une pièce d'exercice, une pièce retirée, une puce retirée, une lecture à l'heure douteuse, une lecture d'avant le
 *   plancher de la notice aux salariés, une lecture d'avant la première fois que ces règles ont tourné.
 *
 * Aucun nom de personne n'est lu ni écrit ; rien n'est écrit dans la caisse ni dans le catalogue (le stock y est lu).
 */
final class LossExitService
{
    /** Lectures jugées au plus par passage ; au-delà, l'analyse dit qu'il en reste (`pending`) */
    public const BATCH = 500;
    /**
     * Verrou consultatif des lectures de la sortie : un lot de faits du boîtier le prend en PARTAGE (`LossBoxChannelService::facts`),
     * le balayage en EXCLUSIF le temps de sa lecture. Le balayage voit donc tout lot commencé avant lui, et un lot commencé après lui
     * reçoit des rangs plus grands : le curseur par rang ne saute jamais une lecture validée en retard.
     */
    public const READS_LOCK = 7461086;
    public const LEVEL_HIGH = 'high';
    public const LEVEL_MEDIUM = 'medium';
    public const LEVEL_LOW = 'low';

    public function __construct(private readonly TenantEntityManagerProvider $emProvider, private readonly ?LoggerInterface $logger = null)
    {
    }

    /**
     * Juge les lectures de sortie arrivées depuis le passage précédent.
     *
     * @param array<string, array{enabled: bool, params: array<string, int>}> $configs réglages en vigueur des deux règles de la sortie
     * @param int                                                              $horizon heure (UTC) jusqu'à laquelle les ventes ont été lues
     *
     * @return array{judged: int, pending: bool} lectures jugées ; vrai s'il en reste de mûres derrière la limite du passage
     */
    public function sweep(LossAnalysisContext $ctx, array $configs, int $horizon, int $catchUpWindow, int $webWindow = LossSaleLinkService::WEB_DAYS * 86400): array
    {
        $conn = $ctx->conn;
        // Le verrou de SESSION se relâche toujours : la lecture se fait sous un point de reprise, et un échec y revient AVANT de relâcher
        // (dans une transaction en échec, `pg_advisory_unlock` échouerait lui aussi et le verrou resterait tenu, bloquant les lots)
        $conn->fetchOne('SELECT pg_advisory_lock(?)', [self::READS_LOCK]);
        $conn->executeStatement('SAVEPOINT loss_exit_lock');
        try {
            $cursor = $conn->fetchOne('SELECT exit_cursor FROM loss_setting ORDER BY id LIMIT 1');
            $first = $cursor === null || $cursor === false;
            if ($first) {
                // première fois : les lectures d'avant ne sont pas jugées (elles n'avaient aucune règle quand elles ont eu lieu)
                $this->moveCursor($conn, self::lastExitRead($conn));
                $reads = [];
            } else {
                $reads = $conn->fetchAllAssociative(
                    "SELECT e.id, e.occurred_at, e.piece_id, e.device_id, e.data FROM loss_piece_event e WHERE e.type = 'ZONE_SEEN' AND NOT e.exercise AND ((e.data)::jsonb ->> 'zone') = 'exit'"
                    . ' AND e.id > ? ORDER BY e.id LIMIT ' . self::BATCH, [(int) $cursor]);
            }
            $conn->executeStatement('RELEASE SAVEPOINT loss_exit_lock');
        } catch (\Throwable $e) {
            $conn->executeStatement('ROLLBACK TO SAVEPOINT loss_exit_lock');
            $conn->fetchOne('SELECT pg_advisory_unlock(?)', [self::READS_LOCK]);
            throw $e;
        }
        $conn->fetchOne('SELECT pg_advisory_unlock(?)', [self::READS_LOCK]);
        if ($first) {
            return ['judged' => 0, 'pending' => false];
        }
        $cursor = (int) $cursor;
        $judged = 0;
        $stopped = false;
        $skipped = [];
        foreach ($reads as $read) {
            $data = json_decode((string) $read['data'], true) ?: [];
            // Une lecture sans objet (heure douteuse, puce retirée, sans pièce, d'avant le plancher de la notice aux salariés) est passée
            // tout de suite : une heure douteuse dans le futur ne doit jamais arrêter le curseur jusqu'à ce qu'elle soit atteinte
            // lot L4-c : une arrivée redite (le boîtier a redémarré) n'est pas une sortie
            if (($data['clockDoubt'] ?? false) || ($data['tagRetired'] ?? false) || ($data['repeat'] ?? false) || $read['piece_id'] === null || $ctx->beforeFloor((string) $read['occurred_at'])) {
                $cursor = (int) $read['id'];
                ++$judged;
                continue;
            }
            $at = self::stamp((string) $read['occurred_at']);
            if ($at + $catchUpWindow > $horizon) {
                $stopped = true;
                break; // la caisse peut encore l'expliquer : le curseur s'arrête devant elle
            }
            $cursor = (int) $read['id'];
            ++$judged;
            // un point de reprise par lecture : une lecture qui échoue est sautée et dite, elle n'arrête pas la sortie pour toujours
            $conn->executeStatement('SAVEPOINT loss_exit_read');
            $counters = $ctx->writer->counters();
            try {
                $this->judge($ctx, $configs, $read, $catchUpWindow, $webWindow);
                $conn->executeStatement('RELEASE SAVEPOINT loss_exit_read');
            } catch (\Throwable $e) {
                $conn->executeStatement('ROLLBACK TO SAVEPOINT loss_exit_read');
                $ctx->writer->restoreCounters($counters);
                $skipped[] = (int) $read['id'];
                $this->logger?->error(sprintf('Prévention des pertes : lecture de sortie %d en échec, sautée : %s', (int) $read['id'], $e->getMessage()), ['exception' => $e]);
            }
        }
        $this->moveCursor($conn, $cursor);
        if ($skipped !== []) {
            $this->emProvider->getEntityManager()->getRepository(LossJournalEntry::class)->append(LossJournalEntry::ENTRIES_SKIPPED, null, null,
                // `count` reste à zéro : la synthèse le présente comme des opérations de CAISSE sautées ; les lectures sont dites à part
                ['count' => 0, 'sequences' => [], 'exitReads' => array_slice($skipped, 0, 50), 'exitReadsCount' => count($skipped)]);
        }

        return ['judged' => $judged, 'pending' => !$stopped && count($reads) >= self::BATCH];
    }

    /** Le rang de la dernière arrivée à la sortie connue (le curseur s'y pose au premier passage, ou quand la notice est confirmée) */
    public static function lastExitRead(Connection $conn): int
    {
        return (int) $conn->fetchOne("SELECT COALESCE(MAX(id), 0) FROM loss_piece_event WHERE type = 'ZONE_SEEN' AND NOT exercise AND ((data)::jsonb ->> 'zone') = 'exit'");
    }

    /** @param array<string, mixed> $read */
    private function judge(LossAnalysisContext $ctx, array $configs, array $read, int $catchUpWindow, int $webWindow): void
    {
        $conn = $ctx->conn;
        $piece = $conn->fetchAssociative('SELECT p.id, p.number, p.status, p.exercise, p.variant_id FROM loss_piece p WHERE p.id = ?', [(int) $read['piece_id']]);
        if ($piece === false || (bool) $piece['exercise'] || $piece['status'] === LossPiece::RETIRED) {
            return; // jamais une pièce d'exercice ni une pièce retirée
        }
        $readAt = new \DateTimeImmutable((string) $read['occurred_at'], new \DateTimeZone('UTC'));
        $day = $ctx->clock->day($readAt);
        $when = $readAt->format('Y-m-d H:i:s');
        $before = LossSaleLinkService::CATCH_UP_BEFORE;
        // 1. La vente EN COURS à l'heure de la lecture l'explique : vendue avant (ou juste après, la fenêtre d'avant du rattrapage), pas
        // encore rendue à cette heure. Un RETOUR lu plus tard (la cliente qui rapporte l'article passe le portique en entrant) ne fait pas
        // de cette lecture une sortie sans vente ; une ANNULATION lue plus tard, elle, ne prouve pas que la pièce est revenue : la vente
        // annulée n'explique plus rien (`VOID_AFTER_SALE` juge l'annulation elle-même)
        if ($this->explainedBySale($ctx, $configs, $piece, $read, $readAt, $day)) {
            return;
        }
        // 2. Des ventes de cet article encore SANS pièce, autour de la lecture, peuvent l'expliquer (le rattrapage ne les a pas encore vues :
        // trop d'articles en attente, analyse en retard, lot du boîtier arrivé après le rattrapage). Mais une vente n'explique qu'UNE sortie,
        // comme le rattrapage relie une lecture à une seule vente : la lecture n'est expliquée que s'il y a plus de ventes en attente dans
        // sa fenêtre que de pièces de l'article sorties AVANT elle (dans la même fenêtre) sans vente qui les explique. Le compte ne dépend
        // pas du passage où chaque lecture est jugée. Expliquée, et l'article pas entièrement étiqueté : le doute est dit. Un article de
        // commande WEB (lot L4-b2) attend sur la fenêtre des commandes web : les sorties d'avant comptent alors depuis le plus ancien qui attend
        $from = $readAt->modify('-' . $catchUpWindow . ' seconds')->format('Y-m-d H:i:s');
        $waiting = $conn->fetchAllAssociative(
            'SELECT s.id, s.order_id, s.order_item_id, s.sold_at, s.cash_register_id, s.matched_by, s.origin FROM loss_sale_unit s'
            . " WHERE s.variant_id = :variant AND s.piece_id IS NULL AND s.returned_at IS NULL AND s.sold_at <= :to AND (s.sold_at >= :from OR (s.origin = 'web' AND s.sold_at >= :webFrom))"
            . ' ORDER BY s.sold_at, s.id',
            ['variant' => (int) $piece['variant_id'], 'from' => $from, 'to' => $readAt->modify('+' . $before . ' seconds')->format('Y-m-d H:i:s'),
                'webFrom' => $readAt->modify('-' . $webWindow . ' seconds')->format('Y-m-d H:i:s')]);
        if ($waiting !== []) {
            $since = $readAt->modify('-' . ($catchUpWindow + $before) . ' seconds');
            $oldest = (new \DateTimeImmutable((string) $waiting[0]['sold_at'], new \DateTimeZone('UTC')))->modify('-' . $before . ' seconds');
            $earlier = (int) $conn->fetchOne(
                'SELECT COUNT(DISTINCT e.piece_id) FROM loss_piece_event e JOIN loss_piece p ON p.id = e.piece_id'
                . " WHERE e.type = 'ZONE_SEEN' AND NOT e.exercise AND ((e.data)::jsonb ->> 'zone') = 'exit' AND COALESCE((e.data)::jsonb ->> 'clockDoubt', 'false') <> 'true' AND COALESCE((e.data)::jsonb ->> 'repeat', 'false') <> 'true'"
                . ' AND p.variant_id = :variant AND NOT p.exercise AND e.piece_id <> :piece AND e.occurred_at >= :from AND (e.occurred_at < :at OR (e.occurred_at = :at AND e.id < :id))'
                . " AND NOT EXISTS (SELECT 1 FROM loss_sale_unit u WHERE u.piece_id = e.piece_id AND u.sold_at <= e.occurred_at + make_interval(secs => :before)"
                . " AND (u.returned_at IS NULL OR (u.returned_by = 'return' AND u.returned_at >= e.occurred_at)))",
                ['variant' => (int) $piece['variant_id'], 'piece' => (int) $piece['id'], 'from' => min($since, $oldest)->format('Y-m-d H:i:s'),
                    'at' => $when, 'id' => (int) $read['id'], 'before' => $before]);
            if ($earlier < count($waiting)) {
                $this->doubt($ctx, $configs, $piece, $read, $readAt, $day, $waiting[$earlier], true);

                return;
            }
        }
        // 3. Une LOCATION en cours ce jour-là (lot L4-b2) : au plus autant de pièces distinctes de l'article sorties ce jour-là que d'unités
        // louées. Les autres pièces de l'article lues avant elle ce jour-là, sans vente en cours, prennent leur place d'abord ; celles
        // qu'une vente en attente explique (cas 2) n'en prennent pas : seules les ventes en attente que des sorties d'AVANT ce jour n'ont
        // pas déjà prises sont retirées du compte. Silencieuse : aucune pièce n'est reliée à une location
        $rented = $this->rented($ctx, (int) $piece['variant_id'], $day);
        if ($rented > 0) {
            [$dayStart] = $ctx->clock->range($day, $day);
            $others = (int) $conn->fetchOne(
                'SELECT COUNT(DISTINCT e.piece_id) FROM loss_piece_event e JOIN loss_piece p ON p.id = e.piece_id'
                . " WHERE e.type = 'ZONE_SEEN' AND NOT e.exercise AND ((e.data)::jsonb ->> 'zone') = 'exit' AND COALESCE((e.data)::jsonb ->> 'clockDoubt', 'false') <> 'true' AND COALESCE((e.data)::jsonb ->> 'repeat', 'false') <> 'true'"
                . " AND COALESCE((e.data)::jsonb ->> 'tagRetired', 'false') <> 'true' AND p.status <> 'retired'"
                . ' AND p.variant_id = :variant AND NOT p.exercise AND e.piece_id <> :piece AND e.occurred_at >= :from AND (e.occurred_at < :at OR (e.occurred_at = :at AND e.id < :id))'
                . " AND NOT EXISTS (SELECT 1 FROM loss_sale_unit u WHERE u.piece_id = e.piece_id AND u.sold_at <= e.occurred_at + make_interval(secs => :before)"
                . " AND (u.returned_at IS NULL OR (u.returned_by = 'return' AND u.returned_at >= e.occurred_at)))",
                ['variant' => (int) $piece['variant_id'], 'piece' => (int) $piece['id'], 'from' => $dayStart->format('Y-m-d H:i:s'), 'at' => $when, 'id' => (int) $read['id'], 'before' => $before]);
            $free = 0;
            if ($waiting !== []) {
                // les ventes en attente déjà prises par des sorties d'avant ce jour (cas 2, même fenêtre) ne libèrent aucune sortie de ce jour
                $earlierBefore = (int) $conn->fetchOne(
                    'SELECT COUNT(DISTINCT e.piece_id) FROM loss_piece_event e JOIN loss_piece p ON p.id = e.piece_id'
                    . " WHERE e.type = 'ZONE_SEEN' AND NOT e.exercise AND ((e.data)::jsonb ->> 'zone') = 'exit' AND COALESCE((e.data)::jsonb ->> 'clockDoubt', 'false') <> 'true' AND COALESCE((e.data)::jsonb ->> 'repeat', 'false') <> 'true'"
                    . ' AND p.variant_id = :variant AND NOT p.exercise AND e.piece_id <> :piece AND e.occurred_at >= :from AND e.occurred_at < :dayStart'
                    . " AND NOT EXISTS (SELECT 1 FROM loss_sale_unit u WHERE u.piece_id = e.piece_id AND u.sold_at <= e.occurred_at + make_interval(secs => :before)"
                    . " AND (u.returned_at IS NULL OR (u.returned_by = 'return' AND u.returned_at >= e.occurred_at)))",
                    ['variant' => (int) $piece['variant_id'], 'piece' => (int) $piece['id'], 'from' => min($since, $oldest)->format('Y-m-d H:i:s'),
                        'dayStart' => $dayStart->format('Y-m-d H:i:s'), 'before' => $before]);
                $free = max(0, count($waiting) - $earlierBefore);
            }
            if (max(0, $others - $free) < $rented) {
                return;
            }
        }
        // 4. Aucune vente ni location ne l'explique
        if ($configs[LossRuleCatalog::EXIT_WITHOUT_SALE]['enabled'] ?? false) {
            $this->withoutSale($ctx, $piece, $read, $readAt, $day);
        }
    }

    /**
     * Le cas 1 du jugement, aussi pour la porte arrière (lot L4-c) : une vente EN COURS de la pièce à l'heure de la lecture l'explique ; si
     * c'est CETTE lecture qui l'a reliée (rattrapage) et que l'article n'est pas entièrement étiqueté, le doute est dit (constat faible).
     *
     * @param array<string, array{enabled: bool, params: array<string, int>}> $configs
     * @param array<string, mixed>                                             $piece
     * @param array<string, mixed>                                             $read
     */
    public function explainedBySale(LossAnalysisContext $ctx, array $configs, array $piece, array $read, \DateTimeImmutable $readAt, string $day): bool
    {
        $conn = $ctx->conn;
        $before = LossSaleLinkService::CATCH_UP_BEFORE;
        $when = $readAt->format('Y-m-d H:i:s');
        $sale = $conn->fetchAssociative(
            'SELECT s.id, s.order_id, s.order_item_id, s.sold_at, s.cash_register_id, s.matched_by FROM loss_sale_unit s'
            . " WHERE s.piece_id = :piece AND s.sold_at <= :limit AND (s.returned_at IS NULL OR (s.returned_by = 'return' AND s.returned_at >= :at)) ORDER BY s.sold_at DESC, s.id DESC LIMIT 1",
            ['piece' => (int) $piece['id'], 'limit' => $readAt->modify('+' . $before . ' seconds')->format('Y-m-d H:i:s'), 'at' => $when]);
        if ($sale === false) {
            return false;
        }
        // C'est CETTE lecture qui a relié la pièce à cette vente (rattrapage) ? Si l'article n'est pas entièrement étiqueté, le
        // doute est dit (constat faible) ; sinon, rien
        $byThisRead = $sale['matched_by'] === 'catch_up' && $conn->fetchOne(
            "SELECT 1 FROM loss_piece_event f WHERE f.piece_id = :piece AND f.type = 'PIECE_SOLD' AND ((f.data)::jsonb ->> 'orderItemId') = :item"
            . " AND ((f.data)::jsonb ->> 'readEventId') = :read LIMIT 1",
            ['piece' => (int) $piece['id'], 'item' => (string) $sale['order_item_id'], 'read' => (string) $read['id']]) !== false;
        if ($byThisRead) {
            $this->doubt($ctx, $configs, $piece, $read, $readAt, $day, $sale, false);
        }

        return true;
    }

    /**
     * Une sortie qu'une vente sans lecture explique (rattrapée, ou en attente de l'être) : constat faible si l'article n'est pas entièrement
     * étiqueté, sinon rien.
     *
     * @param array<string, mixed> $piece
     * @param array<string, mixed> $read
     * @param array<string, mixed> $sale
     */
    private function doubt(LossAnalysisContext $ctx, array $configs, array $piece, array $read, \DateTimeImmutable $readAt, string $day, array $sale, bool $waiting): void
    {
        if (($configs[LossRuleCatalog::EXIT_COVERED]['enabled'] ?? false) && !$this->fullyTagged($ctx->conn, (int) $piece['variant_id'])) {
            $this->covered($ctx, (int) $configs[LossRuleCatalog::EXIT_COVERED]['params']['minCount'], $piece, $read, $readAt, $day, $sale, $waiting);
        }
    }

    /**
     * @param array<string, mixed> $piece
     * @param array<string, mixed> $read
     */
    private function withoutSale(LossAnalysisContext $ctx, array $piece, array $read, \DateTimeImmutable $readAt, string $day): void
    {
        $key = LossRuleCatalog::EXIT_WITHOUT_SALE . ':piece:' . $piece['id'] . ':day:' . $day;
        $known = $ctx->writer->factsOf($key);
        $reads = (int) ($known['reads'] ?? 0) + 1;
        $ctx->writer->record([
            'rule_code' => LossRuleCatalog::EXIT_WITHOUT_SALE, 'variant' => LossFinding::VARIANT_RULE, 'dedup_key' => $key,
            'store_day' => $day, 'occurred_from' => $readAt->format('Y-m-d H:i:s'), 'occurred_to' => $readAt->format('Y-m-d H:i:s'),
            'cash_register_id' => null, 'session_id' => null, 'amount_at_stake' => $this->price($ctx->conn, (int) $piece['variant_id']), 'currency' => $ctx->currency,
            'subject_type' => 'piece', 'subject_id' => (int) $piece['id'],
            'facts' => ['pieceId' => (int) $piece['id'], 'pieceNumber' => (int) $piece['number'], 'variantId' => (int) $piece['variant_id'], 'article' => $this->article($ctx->conn, (int) $piece['variant_id']),
                'reads' => $reads, 'level' => self::LEVEL_HIGH],
            'on_duty' => [], // jamais par personne
            'backfill' => false,
        ], [$this->evidence($ctx->conn, $piece, $read, $readAt, null)]);
    }

    /**
     * @param array<string, mixed> $piece
     * @param array<string, mixed> $read
     * @param array<string, mixed> $sale
     */
    private function covered(LossAnalysisContext $ctx, int $minCount, array $piece, array $read, \DateTimeImmutable $readAt, string $day, array $sale, bool $waiting): void
    {
        $variant = (int) $piece['variant_id'];
        $week = (new \DateTimeImmutable($day))->format('o-\WW'); // semaine ISO de la journée du magasin
        $key = LossRuleCatalog::EXIT_COVERED . ':variant:' . $variant . ':week:' . $week;
        $known = $ctx->writer->factsOf($key);
        $count = (int) ($known['count'] ?? 0) + 1;
        $price = $this->price($ctx->conn, $variant);
        $ctx->writer->record([
            'rule_code' => LossRuleCatalog::EXIT_COVERED, 'variant' => LossFinding::VARIANT_RULE, 'dedup_key' => $key,
            'store_day' => $day, 'occurred_from' => $readAt->format('Y-m-d H:i:s'), 'occurred_to' => $readAt->format('Y-m-d H:i:s'),
            'cash_register_id' => null, 'session_id' => null, 'amount_at_stake' => $price !== null ? $price * $count : null, 'currency' => $ctx->currency,
            'subject_type' => 'product_variant', 'subject_id' => $variant,
            'facts' => ['variantId' => $variant, 'article' => $this->article($ctx->conn, $variant), 'week' => $week, 'count' => $count, 'minCount' => $minCount,
                'level' => $count >= $minCount ? self::LEVEL_MEDIUM : self::LEVEL_LOW],
            'on_duty' => [],
            'backfill' => false,
        ], [$this->evidence($ctx->conn, $piece, $read, $readAt, $sale, $waiting)]);
    }

    /**
     * La preuve : la lecture, l'antenne, la dernière zone où la pièce avait été vue avant, et la vente qui l'a rattrapée s'il y en a
     * une (son heure et son poste). Aucune personne.
     *
     * @param array<string, mixed>      $piece
     * @param array<string, mixed>      $read
     * @param array<string, mixed>|null $sale
     *
     * @return array<string, mixed>
     */
    private function evidence(Connection $conn, array $piece, array $read, \DateTimeImmutable $readAt, ?array $sale, bool $waiting = false): array
    {
        $serial = $read['device_id'] !== null ? $conn->fetchOne('SELECT serial FROM loss_device WHERE id = ?', [(int) $read['device_id']]) : null;
        $before = $conn->fetchAssociative(
            "SELECT zone, since FROM loss_zone_presence WHERE piece_id = ? AND zone <> 'exit' AND since <= ? ORDER BY since DESC, id DESC LIMIT 1",
            [(int) $piece['id'], $readAt->format('Y-m-d H:i:s')]);
        // lot L5-d : le portique a-t-il sonné pour cette pièce (fait d'un avertisseur, ± 2 minutes), une alerte est-elle partie ? Dits, jamais une preuve de plus
        $window = [$readAt->modify('-2 minutes')->format('Y-m-d H:i:s'), $readAt->modify('+2 minutes')->format('Y-m-d H:i:s')];
        $signal = $conn->fetchOne("SELECT MIN(occurred_at) FROM loss_piece_event WHERE piece_id = ? AND type = 'EXIT_SIGNAL' AND occurred_at BETWEEN ? AND ?", [(int) $piece['id'], ...$window]);
        $alert = $conn->fetchOne('SELECT MIN(minute) FROM loss_alert WHERE NOT exercise AND minute BETWEEN ? AND ? AND (pieces)::jsonb @> to_jsonb(CAST(? AS INT))',
            [$readAt->modify('-1 minute')->format('Y-m-d H:i:s'), $window[1], (int) $piece['id']]);

        return ['kind' => LossEvidence::EXIT_READ, 'at' => $readAt, 'refType' => 'piece_event', 'refId' => (int) $read['id'], 'sequence' => null, 'hash' => null,
            'snapshot' => array_filter([
                'pieceId' => (int) $piece['id'], 'pieceNumber' => (int) $piece['number'], 'variantId' => (int) $piece['variant_id'],
                'readAt' => LossAuditEntry::iso($readAt), 'device' => is_string($serial) ? $serial : null,
                'lastZone' => $before !== false ? (string) $before['zone'] : null,
                'lastZoneAt' => $before !== false ? LossAuditEntry::iso(new \DateTimeImmutable((string) $before['since'], new \DateTimeZone('UTC'))) : null,
                'soldAt' => $sale !== null ? LossAuditEntry::iso(new \DateTimeImmutable((string) $sale['sold_at'], new \DateTimeZone('UTC'))) : null,
                'cashRegisterId' => $sale !== null && $sale['cash_register_id'] !== null ? (int) $sale['cash_register_id'] : null,
                'saleWaiting' => $waiting ? true : null, // la vente attendait encore sa pièce : elle peut expliquer la sortie
                'signalAt' => is_string($signal) ? LossAuditEntry::iso(new \DateTimeImmutable($signal, new \DateTimeZone('UTC'))) : null,
                'alertAt' => is_string($alert) ? LossAuditEntry::iso(new \DateTimeImmutable($alert, new \DateTimeZone('UTC'))) : null,
            ], static fn (mixed $v): bool => $v !== null)];
    }

    /**
     * Unités louées de cette variante en cours une journée du magasin : réservations non annulées dont la journée tombe entre la veille de
     * leur début et le lendemain de leur fin (en journées du magasin).
     */
    private function rented(LossAnalysisContext $ctx, int $variant, string $day): int
    {
        // bornes larges (deux jours de chaque côté, changements d'heure compris), la journée exacte est jugée ensuite
        [$start, $end] = $ctx->clock->range($day, $day);
        $rows = $this->emProvider->getEntityManager()->getRepository(LossSaleUnit::class)->rentalsOfVariant($variant,
            $start->modify('-2 days')->format('Y-m-d H:i:s'), $end->modify('+2 days')->format('Y-m-d H:i:s'));
        $units = 0;
        foreach ($rows as $row) {
            $first = (new \DateTimeImmutable($ctx->clock->day(new \DateTimeImmutable((string) $row['start_at'], new \DateTimeZone('UTC')))))->modify('-1 day')->format('Y-m-d');
            $last = (new \DateTimeImmutable($ctx->clock->day(new \DateTimeImmutable((string) $row['end_at'], new \DateTimeZone('UTC')))))->modify('+1 day')->format('Y-m-d');
            if ($day >= $first && $day <= $last) {
                $units += max(1, (int) $row['quantity']);
            }
        }

        return $units;
    }

    /**
     * Un article est entièrement étiqueté quand il a au moins autant de vraies pièces à puce non vendues que d'articles en stock (le
     * stock est LU dans le catalogue, jamais écrit). Un stock inconnu ou négatif ne dit pas « entièrement étiqueté ».
     */
    private function fullyTagged(Connection $conn, int $variant): bool
    {
        $stock = $conn->fetchOne('SELECT stock_quantity FROM product_variant WHERE id = ?', [$variant]);
        if ($stock === false || $stock === null) {
            return false;
        }
        $tagged = (int) $conn->fetchOne(
            'SELECT COUNT(DISTINCT p.id) FROM loss_piece p JOIN loss_tag t ON t.piece_id = p.id AND t.status = :associated'
            . ' WHERE p.variant_id = :variant AND NOT p.exercise AND p.status NOT IN (:sold, :retired)',
            ['associated' => LossTag::ASSOCIATED, 'variant' => $variant, 'sold' => LossPiece::SOLD, 'retired' => LossPiece::RETIRED]);

        return (int) $stock >= 0 && $tagged >= (int) $stock;
    }

    /** Le nom de l'article et de sa variante, tels que le catalogue les dit au moment du constat (un nom de produit, jamais de personne) */
    /** Le nom d'un article et de sa variante (couleur, taille), lu dans le catalogue ; aussi pour les règles du dépôt (lot L4-c) */
    public static function article(Connection $conn, int $variant): string
    {
        $row = $conn->fetchAssociative('SELECT a.name, c.name AS color, z.name AS size FROM product_variant v JOIN product a ON a.id = v.product_id'
            . ' LEFT JOIN color c ON c.id = v.color_id LEFT JOIN size z ON z.id = v.size_id WHERE v.id = ?', [$variant]);
        if ($row === false) {
            return '';
        }
        $label = implode(' / ', array_filter([trim((string) $row['color']), trim((string) $row['size'])], static fn (string $part): bool => $part !== ''));

        return mb_substr(trim((string) $row['name']) . ($label !== '' ? ' (' . $label . ')' : ''), 0, 120);
    }

    /** Le prix de vente de la variante (celui de l'article à défaut), en centimes ; lu dans le catalogue */
    private function price(Connection $conn, int $variant): ?int
    {
        $price = $conn->fetchOne('SELECT COALESCE(v.price, a.price) FROM product_variant v JOIN product a ON a.id = v.product_id WHERE v.id = ?', [$variant]);

        return $price === false || $price === null ? null : (int) round((float) $price);
    }

    private function moveCursor(Connection $conn, int $cursor): void
    {
        $conn->executeStatement('UPDATE loss_setting SET exit_cursor = ?', [$cursor]);
    }

    private static function stamp(string $stored): int
    {
        return (new \DateTimeImmutable($stored, new \DateTimeZone('UTC')))->getTimestamp();
    }
}
