<?php

namespace App\LossPrevention\Repository;

use App\LossPrevention\Entity\LossSaleUnit;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityRepository;

/**
 * Articles suivis vendus et pièces reliées (lot L3-b2a, `docs/prevention-pertes.md` § 16.18), en SQL. Ce dépôt LIT les tables de la
 * caisse (`"order"`, `order_items`) et n'y écrit jamais ; il n'écrit que dans les tables du module.
 *
 * @extends EntityRepository<LossSaleUnit>
 */
class LossSaleUnitRepository extends EntityRepository
{
    /** @return array{id: int, sold_at: string}|null la commande d'une vente, avec son heure RÉELLE (une vente hors ligne garde la sienne) */
    public function order(int $id): ?array
    {
        $row = $this->db()->fetchAssociative('SELECT id, order_date FROM "order" WHERE id = ?', [$id]);

        return $row && $row['order_date'] !== null ? ['id' => (int) $row['id'], 'sold_at' => (string) $row['order_date']] : null;
    }

    /** @return list<array{id: int, variant: int, quantity: int}> les lignes VENDUES d'une commande qui portent une variante (un article libre n'en a pas ; une location n'est pas une vente) */
    public function itemsOf(int $orderId): array
    {
        return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'variant' => (int) $r['product_variant_id'], 'quantity' => (int) $r['quantity']],
            $this->db()->fetchAllAssociative('SELECT i.id, i.product_variant_id, i.quantity FROM order_items i WHERE i.order_associated_id = ? AND i.product_variant_id IS NOT NULL AND i.quantity > 0'
                // une ligne de LOCATION (elle porte une réservation) n'est pas une vente : sa pièce revient, et son retour n'est pas un REFUND
                . ' AND NOT EXISTS (SELECT 1 FROM booking b WHERE b.order_item_id = i.id) ORDER BY i.id', [$orderId]));
    }

    /**
     * Celles de ces variantes qui étaient SUIVIES à l'heure de la vente : une vraie pièce existait, portait une puce, ni l'une ni
     * l'autre n'était retirée, et cette pièce n'était pas déjà vendue (comme à la caisse, où un article dont toutes les pièces à puce
     * sont vendues n'est plus suivi). Une vente d'avant le suivi d'un article n'est donc pas comptée « vendue sans lecture ».
     *
     * @param list<int> $variantIds
     *
     * @return list<int>
     */
    public function trackedAt(array $variantIds, string $at): array
    {
        if ($variantIds === []) {
            return [];
        }

        return array_map('intval', $this->db()->fetchFirstColumn(
            'SELECT DISTINCT p.variant_id FROM loss_piece p JOIN loss_tag t ON t.piece_id = p.id WHERE p.variant_id IN (:ids) AND NOT p.exercise AND NOT t.exercise'
            . ' AND p.created_at <= :at AND (p.retired_at IS NULL OR p.retired_at > :at) AND t.associated_at IS NOT NULL AND t.associated_at <= :at AND (t.retired_at IS NULL OR t.retired_at > :at)'
            . ' AND NOT EXISTS (SELECT 1 FROM loss_sale_unit u WHERE u.piece_id = p.id AND u.sold_at <= :at AND (u.returned_at IS NULL OR u.returned_at > :at))',
            ['ids' => $variantIds, 'at' => $at], ['ids' => ArrayParameterType::INTEGER]));
    }

    /** Un article suivi vendu ; rend son identifiant, ou null s'il était déjà écrit (la même vente relue) */
    public function insert(int $orderId, int $orderItemId, int $unitNo, int $variantId, ?int $cashRegisterId, string $soldAt, int $sequence, string $now): ?int
    {
        $id = $this->db()->fetchOne(
            'INSERT INTO loss_sale_unit (order_id, order_item_id, unit_no, variant_id, cash_register_id, sold_at, audit_sequence, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            . ' ON CONFLICT (order_item_id, unit_no) DO NOTHING RETURNING id', [$orderId, $orderItemId, $unitNo, $variantId, $cashRegisterId, $soldAt, $sequence, $now]);

        return $id === false ? null : (int) $id;
    }

    /**
     * Les pièces de cette variante LUES sur le tapis de ce poste autour d'un instant : posées avant `$until`, et encore là ou parties
     * après `$from`, par une puce en service. `$sold` : faux pour une vente (pièces pas encore vendues, reliées à aucun article en
     * cours, et POSÉES APRÈS leur dernier retour : une pièce rendue restée sur le tapis n'est pas prise pour la vente suivante),
     * vrai pour un retour (pièces vendues). La plus anciennement posée d'abord.
     *
     * @return list<int> identifiants de pièces
     */
    public function piecesReadOnCounter(int $cashRegisterId, int $variantId, string $from, string $until, bool $sold): array
    {
        return array_map('intval', $this->db()->fetchFirstColumn(
            'SELECT p.id FROM loss_counter_presence c JOIN loss_device d ON d.id = c.device_id JOIN loss_tag t ON t.id = c.tag_id JOIN loss_piece p ON p.id = t.piece_id'
            . " WHERE d.cash_register_id = :register AND d.kind = 'counter_mat' AND NOT d.exercise AND NOT c.exercise AND NOT p.exercise AND p.variant_id = :variant AND t.status = 'associated'"
            . ' AND c.since <= :until AND (c.until IS NULL OR c.until >= :from) AND ' . ($sold
                ? "p.status = 'sold'"
                : "p.status NOT IN ('sold', 'retired') AND NOT EXISTS (SELECT 1 FROM loss_sale_unit u WHERE u.piece_id = p.id AND u.returned_at IS NULL)"
                    // posée APRÈS son dernier retour d'articles (une vente annulée n'a pas fait sortir la pièce : ressaisie, elle la retrouve),
                    // par une puce qui la portait déjà à l'heure de la vente
                    . " AND NOT EXISTS (SELECT 1 FROM loss_sale_unit r WHERE r.piece_id = p.id AND r.returned_by = 'return' AND r.returned_at >= c.since)"
                    . ' AND t.associated_at <= :until')
            . ' GROUP BY p.id ORDER BY MIN(c.since), p.id',
            ['register' => $cashRegisterId, 'variant' => $variantId, 'from' => $from, 'until' => $until]));
    }

    /** Relie une pièce à un article vendu : une fois, si l'article n'en a pas et n'est pas revenu. Rend vrai si c'est fait */
    public function link(int $unitId, int $pieceId, string $by, string $now): bool
    {
        return $this->db()->executeStatement('UPDATE loss_sale_unit SET piece_id = ?, matched_by = ?, matched_at = ? WHERE id = ? AND piece_id IS NULL AND returned_at IS NULL', [$pieceId, $by, $now, $unitId]) === 1;
    }

    /** L'état d'une pièce : `sold` quand elle est reliée à une vente, `returned` quand elle revient (de nouveau vendable) */
    public function pieceStatus(int $pieceId, string $status, string $now): void
    {
        $this->db()->executeStatement("UPDATE loss_piece SET status = ?, updated_at = ? WHERE id = ? AND status <> 'retired'", [$status, $now, $pieceId]);
    }

    /** @return list<array{id: int, piece_id: ?int, order_item_id: int, variant_id: int}> les articles d'une commande pas encore revenus */
    public function openUnitsOfOrder(int $orderId): array
    {
        return $this->db()->fetchAllAssociative('SELECT id, piece_id, order_item_id, variant_id FROM loss_sale_unit WHERE order_id = ? AND returned_at IS NULL ORDER BY id', [$orderId]);
    }

    /** @return list<array{id: int, piece_id: ?int, order_item_id: int, variant_id: int}> les articles d'une ligne de vente pas encore revenus, dans l'ordre de leur rang */
    public function openUnitsOfItem(int $orderItemId): array
    {
        return $this->db()->fetchAllAssociative('SELECT id, piece_id, order_item_id, variant_id FROM loss_sale_unit WHERE order_item_id = ? AND returned_at IS NULL ORDER BY unit_no', [$orderItemId]);
    }

    /** Un article revient, par un retour d'articles (`return`) ou parce que sa vente est annulée (`cancellation`) : dit une fois */
    public function returned(int $unitId, string $at, int $sequence, string $by): bool
    {
        return $this->db()->executeStatement('UPDATE loss_sale_unit SET returned_at = ?, return_sequence = ?, returned_by = ? WHERE id = ? AND returned_at IS NULL', [$at, $sequence, $by, $unitId]) === 1;
    }

    /**
     * Les articles suivis vendus depuis `$since` qui n'ont pas de pièce et ne sont pas revenus : ce que le rattrapage regarde, la
     * vente la plus ancienne d'abord. Tous les postes du site (la sortie est commune) ; la base est celle du site, et de lui seul.
     *
     * @return list<array{id: int, variant_id: int, sold_at: string, order_id: int, order_item_id: int, cash_register_id: ?int, created_at: string}>
     */
    public function unitsToCatchUp(string $since, int $limit): array
    {
        return $this->db()->fetchAllAssociative(
            'SELECT id, variant_id, sold_at, order_id, order_item_id, cash_register_id, created_at FROM loss_sale_unit WHERE piece_id IS NULL AND returned_at IS NULL AND sold_at > ? ORDER BY sold_at, id LIMIT '
            . max(1, $limit), [$since]);
    }

    /**
     * Les articles suivis écrits depuis `$createdSince`, qui ont un poste, n'ont pas de pièce et ne sont pas revenus : ceux pour qui
     * le lien par le tapis est retenté (les faits du boîtier ont pu arriver après la vente). Leur propre limite : ils ne passent pas
     * derrière les articles plus anciens qui attendent la sortie.
     *
     * @return list<array{id: int, variant_id: int, sold_at: string, order_id: int, order_item_id: int, cash_register_id: int, created_at: string}>
     */
    public function unitsToRetryOnCounter(string $createdSince, int $limit): array
    {
        return $this->db()->fetchAllAssociative(
            'SELECT id, variant_id, sold_at, order_id, order_item_id, cash_register_id, created_at FROM loss_sale_unit WHERE piece_id IS NULL AND returned_at IS NULL AND cash_register_id IS NOT NULL'
            . ' AND created_at > ? ORDER BY sold_at, id LIMIT ' . max(1, $limit), [$createdSince]);
    }

    /** Le site a-t-il au moins une vraie puce posée sur une pièce ? Sans cela, aucune vente n'a d'article suivi */
    public function hasTaggedPiece(): bool
    {
        return (bool) $this->db()->fetchOne('SELECT EXISTS (SELECT 1 FROM loss_tag WHERE NOT exercise AND piece_id IS NOT NULL)');
    }

    /**
     * Les lectures à la SORTIE, entre deux instants, des vraies pièces de ces variantes qui ne sont ni vendues, ni retirées, ni déjà
     * reliées à un article en cours, dans l'ordre du temps. UNE requête pour toutes les variantes d'un passage. Ne comptent pas :
     * une lecture à l'heure douteuse, une lecture par une puce retirée, et une lecture d'AVANT le dernier retour de la pièce (la
     * sortie du client qui l'a rapportée depuis ne vaut pas pour une autre vente).
     *
     * @param list<int> $variantIds
     * @param string    $horizon   les articles en attente vendus depuis cet instant
     * @param int       $before    secondes avant la vente d'un article où une lecture compte encore
     * @param int       $window    secondes après la vente d'un article où une lecture compte encore
     *
     * @return array<int, list<array{event: int, piece: int, at: string}>> par variante
     */
    public function exitReads(array $variantIds, string $from, string $until, string $horizon, int $before, int $window, int $limit): array
    {
        if ($variantIds === []) {
            return [];
        }
        $out = [];
        foreach ($this->db()->fetchAllAssociative(
            "SELECT e.id, e.piece_id, e.occurred_at, p.variant_id FROM loss_piece_event e JOIN loss_piece p ON p.id = e.piece_id WHERE e.type = 'ZONE_SEEN' AND NOT e.exercise AND NOT p.exercise"
            . " AND p.variant_id IN (:variants) AND p.status NOT IN ('sold', 'retired') AND e.occurred_at >= :from AND e.occurred_at <= :until"
            . " AND (e.data)::jsonb ->> 'zone' = 'exit' AND COALESCE((e.data)::jsonb ->> 'clockDoubt', 'false') <> 'true' AND COALESCE((e.data)::jsonb ->> 'tagRetired', 'false') <> 'true'"
            . ' AND NOT EXISTS (SELECT 1 FROM loss_sale_unit u WHERE u.piece_id = p.id AND u.returned_at IS NULL)'
            . " AND NOT EXISTS (SELECT 1 FROM loss_sale_unit r WHERE r.piece_id = p.id AND r.returned_by = 'return' AND r.returned_at >= e.occurred_at)"
            // seulement les lectures qui tombent dans la fenêtre d'au moins UN article en attente : un portant près de la porte, relu
            // sans cesse, ne remplit pas la limite avec des lectures qui ne servent à aucune vente
            . ' AND EXISTS (SELECT 1 FROM loss_sale_unit w WHERE w.variant_id = p.variant_id AND w.piece_id IS NULL AND w.returned_at IS NULL AND w.sold_at > :horizon'
            . ' AND e.occurred_at >= w.sold_at - make_interval(secs => :before) AND e.occurred_at <= w.sold_at + make_interval(secs => :window))'
            . ' ORDER BY e.occurred_at, e.id LIMIT ' . max(1, $limit),
            ['variants' => $variantIds, 'from' => $from, 'until' => $until, 'horizon' => $horizon, 'before' => $before, 'window' => $window], ['variants' => ArrayParameterType::INTEGER]) as $row) {
            $out[(int) $row['variant_id']][] = ['event' => (int) $row['id'], 'piece' => (int) $row['piece_id'], 'at' => (string) $row['occurred_at']];
        }

        return $out;
    }

    /** Efface les présences FERMÉES avant `$before` (jamais une présence ouverte, jamais un fait), par paquets ; rend leur nombre */
    public function purgePresences(string $before, int $limit): int
    {
        // Lot L4-a : les présences par zone suivent la même conservation, sauf la DERNIÈRE de chaque pièce (elle dit où la pièce a été
        // vue en dernier, même il y a longtemps)
        $zones = (int) $this->db()->executeStatement(
            'DELETE FROM loss_zone_presence WHERE id IN (SELECT z.id FROM loss_zone_presence z WHERE z.until IS NOT NULL AND z.until < ? AND NOT z.exercise'
            . ' AND (z.piece_id IS NULL OR EXISTS (SELECT 1 FROM loss_zone_presence n WHERE n.piece_id = z.piece_id AND n.id <> z.id AND (n.since > z.since OR (n.since = z.since AND n.id > z.id))))'
            . ' ORDER BY z.id LIMIT ' . max(1, $limit) . ')', [$before]);

        return $zones + (int) $this->db()->executeStatement(
            'DELETE FROM loss_counter_presence WHERE id IN (SELECT id FROM loss_counter_presence WHERE until IS NOT NULL AND until < ? AND NOT exercise ORDER BY id LIMIT ' . max(1, $limit) . ')', [$before]);
    }

    /**
     * @param list<int> $pieceIds
     *
     * @return array<int, array<string, mixed>> la vente en cours de chaque pièce vendue (référence du ticket, poste, heure, par quoi)
     */
    public function salesOfPieces(array $pieceIds): array
    {
        if ($pieceIds === []) {
            return [];
        }
        $out = [];
        foreach ($this->db()->fetchAllAssociative(
            'SELECT u.piece_id, u.order_id, u.sold_at, u.matched_by, u.matched_at, u.cash_register_id, o.reference FROM loss_sale_unit u LEFT JOIN "order" o ON o.id = u.order_id'
            . ' WHERE u.piece_id IN (?) AND u.returned_at IS NULL', [$pieceIds], [ArrayParameterType::INTEGER]) as $row) {
            $out[(int) $row['piece_id']] = $row;
        }

        return $out;
    }

    private function db(): Connection
    {
        return $this->getEntityManager()->getConnection();
    }
}
