<?php

namespace App\LossPrevention\Repository;

use App\LossPrevention\Entity\LossCount;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityRepository;

/**
 * Comptages et leurs pièces (lot L4-d, `docs/prevention-pertes.md` § 17.16), en SQL. Lit les pièces, les puces, les faits et les présences
 * par zone ; n'écrit que dans `loss_count`, `loss_count_piece` et l'état des pièces dites absentes.
 *
 * @extends EntityRepository<LossCount>
 */
class LossCountRepository extends EntityRepository
{
    private const COLUMNS = 'id, scope, status, exercise, opened_at, opened_by, closed_at, closed_by, expected, found, absent, missing';

    /** Un comptage ouvert ; rend son identifiant, ou null si un autre est déjà ouvert (l'index unique de la base le garantit) */
    public function open(string $scope, bool $exercise, ?int $userId, string $now): ?int
    {
        $id = $this->db()->fetchOne('INSERT INTO loss_count (scope, status, exercise, opened_at, opened_by) VALUES (?, ?, CAST(? AS boolean), ?, ?)'
            . " ON CONFLICT (exercise) WHERE status = 'open' DO NOTHING RETURNING id", [$scope, LossCount::OPEN, $exercise ? 'true' : 'false', $now, $userId]);

        return $id === false ? null : (int) $id;
    }

    /** @return array<string, mixed>|null */
    public function findCount(int $id, bool $exercise, bool $lock = false): ?array
    {
        $row = $this->db()->fetchAssociative('SELECT ' . self::COLUMNS . ' FROM loss_count WHERE id = ? AND exercise = CAST(? AS boolean)' . ($lock ? ' FOR UPDATE' : ''),
            [$id, $exercise ? 'true' : 'false']);

        return $row === false ? null : $row;
    }

    /** @return list<array<string, mixed>> les comptages, les plus récents d'abord */
    public function list(bool $exercise, int $limit): array
    {
        return $this->db()->fetchAllAssociative('SELECT ' . self::COLUMNS . ' FROM loss_count WHERE exercise = CAST(? AS boolean) ORDER BY opened_at DESC, id DESC LIMIT ' . max(1, $limit),
            [$exercise ? 'true' : 'false']);
    }

    /** Ferme sans conclusion les comptages ouverts avant `$before` (fin : leur ouverture plus la durée) ; rend leur nombre */
    public function expire(string $before, int $hours): int
    {
        return (int) $this->db()->executeStatement(
            "UPDATE loss_count SET status = 'expired', closed_at = opened_at + make_interval(hours => ?) WHERE status = 'open' AND opened_at < ?", [$hours, $before]);
    }

    /**
     * Écrit les pièces attendues du comptage, et si elles ont été lues par le lecteur à main pendant le comptage : vraies pièces (du genre du
     * comptage) à puce posée, ni vendues ni retirées, créées avant l'ouverture ; pour une zone, celles vues en dernier dans cette zone.
     *
     * @return array{expected: int, found: int}
     */
    public function writePieces(int $countId, string $scope, bool $exercise, string $openedAt, string $closedAt): array
    {
        $params = ['count' => $countId, 'ex' => $exercise ? 'true' : 'false', 'opened' => $openedAt, 'closed' => $closedAt];
        $zone = '';
        if ($scope !== LossCount::ALL) {
            $zone = ' AND (SELECT z.zone FROM loss_zone_presence z WHERE z.piece_id = p.id ORDER BY z.since DESC, z.id DESC LIMIT 1) = :zone';
            $params['zone'] = $scope;
        }
        $this->db()->executeStatement(
            'INSERT INTO loss_count_piece (count_id, piece_id, found, exercise) SELECT :count, p.id,'
            . " EXISTS (SELECT 1 FROM loss_piece_event e WHERE e.piece_id = p.id AND e.type = 'SPOT_CHECK' AND e.exercise = CAST(:ex AS boolean)"
            . " AND e.occurred_at >= :opened AND e.occurred_at <= :closed AND COALESCE((e.data)::jsonb ->> 'clockDoubt', 'false') <> 'true'), CAST(:ex AS boolean)"
            . " FROM loss_piece p WHERE p.exercise = CAST(:ex AS boolean) AND p.status NOT IN ('sold', 'retired') AND p.created_at <= :opened"
            . " AND EXISTS (SELECT 1 FROM loss_tag t WHERE t.piece_id = p.id AND t.status = 'associated' AND t.exercise = CAST(:ex AS boolean))" . $zone, $params);
        $row = $this->db()->fetchAssociative('SELECT COUNT(*) AS expected, COUNT(*) FILTER (WHERE found) AS found FROM loss_count_piece WHERE count_id = ?', [$countId]);

        return ['expected' => (int) $row['expected'], 'found' => (int) $row['found']];
    }

    /**
     * Les pièces non lues de ce comptage qui ne l'avaient pas été non plus au comptage FERMÉ précédent qui les couvrait (même genre).
     *
     * @return list<int>
     */
    public function absentTwice(int $countId, bool $exercise): array
    {
        return array_map('intval', $this->db()->fetchFirstColumn(
            'SELECT cp.piece_id FROM loss_count_piece cp WHERE cp.count_id = :count AND NOT cp.found AND (SELECT prev.found FROM loss_count_piece prev JOIN loss_count c ON c.id = prev.count_id'
            . " WHERE prev.piece_id = cp.piece_id AND c.id <> :count AND c.status = 'closed' AND c.exercise = CAST(:ex AS boolean) ORDER BY c.closed_at DESC, c.id DESC LIMIT 1) = false"
            . ' ORDER BY cp.piece_id', ['count' => $countId, 'ex' => $exercise ? 'true' : 'false']));
    }

    /**
     * Dit absentes ces pièces (leur état d'avant est gardé) ; une pièce déjà absente, vendue ou retirée ne change pas.
     *
     * @param list<int> $pieceIds
     *
     * @return list<int> celles qui viennent de l'être
     */
    public function markMissing(array $pieceIds, int $countId, string $at): array
    {
        if ($pieceIds === []) {
            return [];
        }

        return array_map('intval', $this->db()->fetchFirstColumn(
            "UPDATE loss_piece SET missing_from = status, status = 'missing', missing_since = :at, missing_count_id = :count, updated_at = :at"
            . " WHERE id IN (:ids) AND status NOT IN ('missing', 'sold', 'retired') RETURNING id",
            ['at' => $at, 'count' => $countId, 'ids' => $pieceIds], ['ids' => ArrayParameterType::INTEGER]));
    }

    /** @param array{expected: int, found: int, absent: int, missing: int} $numbers */
    public function close(int $countId, int $userId, string $at, array $numbers): void
    {
        $this->db()->executeStatement("UPDATE loss_count SET status = 'closed', closed_at = ?, closed_by = ?, expected = ?, found = ?, absent = ?, missing = ? WHERE id = ? AND status = 'open'",
            [$at, $userId, $numbers['expected'], $numbers['found'], $numbers['absent'], $numbers['missing'], $countId]);
    }

    /**
     * Les pièces absentes relues depuis (n'importe quelle lecture à une heure sûre, après leur absence), avec la première lecture qui les a revues.
     *
     * @return list<array{id: int, number: int, variant_id: int, missing_from: ?string, missing_count_id: ?int, exercise: bool, event_id: int, event_type: string, event_at: string, zone: ?string}>
     */
    public function foundAgain(array $types, int $limit): array
    {
        return $this->db()->fetchAllAssociative(
            'SELECT p.id, p.number, p.variant_id, p.missing_from, p.missing_count_id, p.exercise, e.id AS event_id, e.type AS event_type, e.occurred_at AS event_at, (e.data)::jsonb ->> \'zone\' AS zone'
            . " FROM loss_piece p JOIN LATERAL (SELECT f.id, f.type, f.occurred_at, f.data FROM loss_piece_event f WHERE f.piece_id = p.id AND f.type IN (:types) AND f.occurred_at > p.missing_since"
            . " AND COALESCE((f.data)::jsonb ->> 'clockDoubt', 'false') <> 'true' ORDER BY f.occurred_at, f.id LIMIT 1) e ON true WHERE p.status = 'missing' ORDER BY p.id LIMIT " . max(1, $limit),
            ['types' => $types], ['types' => ArrayParameterType::STRING]);
    }

    /** Une pièce relue reprend son état d'avant (« créée » s'il n'est pas connu) */
    public function restore(int $pieceId, string $now): bool
    {
        return $this->db()->executeStatement(
            "UPDATE loss_piece SET status = COALESCE(missing_from, 'created'), missing_from = NULL, missing_since = NULL, missing_count_id = NULL, updated_at = ? WHERE id = ? AND status = 'missing'",
            [$now, $pieceId]) === 1;
    }

    /**
     * Les pièces non lues d'un comptage, avec leur article et où elles ont été vues en dernier.
     *
     * @return list<array{id: int, number: int, variant_id: int, status: string, last_zone: ?string, last_seen: ?string}>
     */
    public function absentPieces(int $countId, int $limit): array
    {
        return $this->db()->fetchAllAssociative(
            'SELECT p.id, p.number, p.variant_id, p.status, (SELECT z.zone FROM loss_zone_presence z WHERE z.piece_id = p.id ORDER BY z.since DESC, z.id DESC LIMIT 1) AS last_zone,'
            . ' (SELECT MAX(e.occurred_at) FROM loss_piece_event e WHERE e.piece_id = p.id) AS last_seen'
            . ' FROM loss_count_piece cp JOIN loss_piece p ON p.id = cp.piece_id WHERE cp.count_id = ? AND NOT cp.found ORDER BY p.number LIMIT ' . max(1, $limit), [$countId]);
    }

    /**
     * Écart par article entre le stock du catalogue (lu) et les pièces lues au comptage : pour chaque variante des pièces attendues.
     *
     * @return list<array{variant_id: int, stock: ?int, expected: int, found: int}>
     */
    public function stockGap(int $countId, int $limit): array
    {
        return $this->db()->fetchAllAssociative(
            'SELECT p.variant_id, v.stock_quantity AS stock, COUNT(*) AS expected, COUNT(*) FILTER (WHERE cp.found) AS found FROM loss_count_piece cp JOIN loss_piece p ON p.id = cp.piece_id'
            . ' LEFT JOIN product_variant v ON v.id = p.variant_id WHERE cp.count_id = ? GROUP BY p.variant_id, v.stock_quantity ORDER BY COUNT(*) FILTER (WHERE NOT cp.found) DESC, p.variant_id LIMIT ' . max(1, $limit),
            [$countId]);
    }

    private function db(): Connection
    {
        return $this->getEntityManager()->getConnection();
    }
}
