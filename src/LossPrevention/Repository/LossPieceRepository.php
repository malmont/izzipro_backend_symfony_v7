<?php

namespace App\LossPrevention\Repository;

use App\LossPrevention\Entity\LossPiece;
use App\LossPrevention\Entity\LossTag;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityRepository;

/**
 * Pièces, puces et leurs faits (lot L2-a, `docs/prevention-pertes.md` § 13), lus et écrits en SQL. Aucune règle ici : le service
 * décide, la base garde (index unique de l'identifiant, déclencheurs de la migration `Version20270109100000`). Les lectures du
 * catalogue (variante, article) ne font que lire.
 *
 * @extends EntityRepository<LossPiece>
 */
class LossPieceRepository extends EntityRepository
{
    /** Verrou consultatif des pièces d'exercice : création et effacement ne se croisent pas (comme les constats d'exercice) */
    public const EXERCISE_LOCK = 7461081;

    /** Lectures par un boîtier montrées dans la fiche d'une pièce, au plus (les dernières) ; les faits de sa vie y sont toujours tous */
    public const EVENTS_SHOWN = 200;

    /** Suite des numéros des vraies pièces, et celle des pièces d'exercice (un exercice ne creuse pas de trou dans la première) */
    private const NUMBERS = 'loss_piece_number_seq';
    private const EXERCISE_NUMBERS = 'loss_piece_exercise_number_seq';

    /**
     * Crée `$count` pièces d'une variante, chacune avec le numéro suivant de la suite de son genre (jamais redonné).
     *
     * @return list<int> identifiants des pièces créées, dans l'ordre des numéros
     */
    public function createPieces(int $variantId, int $productId, ?int $commandeId, bool $exercise, ?int $userId, int $count, string $now): array
    {
        return array_map('intval', $this->db()->fetchFirstColumn(
            "INSERT INTO loss_piece (number, variant_id, product_id, status, commande_id, exercise, created_by, created_at, updated_at)"
            . " SELECT nextval('" . ($exercise ? self::EXERCISE_NUMBERS : self::NUMBERS) . "'), CAST(:variant AS integer), CAST(:product AS integer), 'created', CAST(:commande AS integer), CAST(:exercise AS boolean),"
            . ' CAST(:user AS integer), CAST(:now AS timestamp), CAST(:now AS timestamp) FROM generate_series(1, CAST(:count AS integer)) RETURNING id',
            ['variant' => $variantId, 'product' => $productId, 'commande' => $commandeId, 'exercise' => $exercise ? 'true' : 'false', 'user' => $userId, 'now' => $now, 'count' => $count]
        ));
    }

    /** @return array<string, mixed>|null la ligne d'une pièce ; `$lock` : sous le verrou d'une mise à jour, dans la transaction de l'appelant */
    public function piece(int $id, bool $lock = false): ?array
    {
        return $this->db()->fetchAssociative('SELECT * FROM loss_piece WHERE id = ?' . ($lock ? ' FOR NO KEY UPDATE' : ''), [$id]) ?: null;
    }

    /**
     * @param list<int> $ids
     *
     * @return list<array<string, mixed>> dans l'ordre des numéros
     */
    public function pieces(array $ids): array
    {
        return $ids === [] ? [] : $this->db()->fetchAllAssociative('SELECT * FROM loss_piece WHERE id IN (?) ORDER BY number', [$ids], [ArrayParameterType::INTEGER]);
    }

    /**
     * Une page de pièces, les plus récentes d'abord.
     *
     * @param array{variantId?: int, productId?: int, status?: string, tagged?: bool} $filters
     *
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function page(array $filters, bool $exercise, int $page, int $limit): array
    {
        $where = ['p.exercise = CAST(:exercise AS boolean)'];
        $params = ['exercise' => $exercise ? 'true' : 'false'];
        foreach (['variantId' => 'p.variant_id', 'productId' => 'p.product_id', 'status' => 'p.status'] as $key => $column) {
            if (isset($filters[$key])) {
                $where[] = "$column = :$key";
                $params[$key] = $filters[$key];
            }
        }
        if (isset($filters['tagged'])) {
            $where[] = ($filters['tagged'] ? '' : 'NOT ') . "EXISTS (SELECT 1 FROM loss_tag t WHERE t.piece_id = p.id AND t.status = 'associated')";
        }
        $rows = $this->db()->fetchAllAssociative(
            'SELECT p.* FROM loss_piece p WHERE ' . implode(' AND ', $where) . sprintf(' ORDER BY p.number DESC LIMIT %d OFFSET %d', $limit, ($page - 1) * $limit),
            $params
        );

        // le total ne dépend pas de la page demandée (une page au-delà de la dernière rend une liste vide, pas un total à zéro)
        return ['rows' => $rows, 'total' => (int) $this->db()->fetchOne('SELECT COUNT(*) FROM loss_piece p WHERE ' . implode(' AND ', $where), $params)];
    }

    /** Pièces d'exercice présentes sur le site */
    public function exerciseCount(): int
    {
        return (int) $this->db()->fetchOne('SELECT COUNT(*) FROM loss_piece WHERE exercise');
    }

    /** Puces simulées présentes sur le site, retirées comprises */
    public function exerciseTagCount(): int
    {
        return (int) $this->db()->fetchOne('SELECT COUNT(*) FROM loss_tag WHERE exercise');
    }

    public function retirePiece(int $id, string $reason, string $now): void
    {
        $this->db()->executeStatement("UPDATE loss_piece SET status = 'retired', retired_reason = ?, retired_at = ?, updated_at = ? WHERE id = ?", [$reason, $now, $now, $id]);
    }

    /** @return array<string, mixed>|null la puce qui porte cet identifiant (forme canonique) */
    public function tagByIdentifier(string $identifier, bool $lock = false): ?array
    {
        return $this->db()->fetchAssociative('SELECT * FROM loss_tag WHERE identifier = ?' . ($lock ? ' FOR NO KEY UPDATE' : ''), [$identifier]) ?: null;
    }

    /** @return array<string, mixed>|null */
    public function tagById(int $id, bool $lock = false): ?array
    {
        return $this->db()->fetchAssociative('SELECT * FROM loss_tag WHERE id = ?' . ($lock ? ' FOR NO KEY UPDATE' : ''), [$id]) ?: null;
    }

    /**
     * @param list<int> $pieceIds
     *
     * @return array<int, list<array<string, mixed>>> les puces de chaque pièce, dans l'ordre où elles ont été posées
     */
    public function tagsOf(array $pieceIds): array
    {
        $out = [];
        if ($pieceIds === []) {
            return $out;
        }
        foreach ($this->db()->fetchAllAssociative('SELECT * FROM loss_tag WHERE piece_id IN (?) ORDER BY id', [$pieceIds], [ArrayParameterType::INTEGER]) as $row) {
            $out[(int) $row['piece_id']][] = $row;
        }

        return $out;
    }

    /** Puces actives d'une pièce */
    public function activeTagCount(int $pieceId): int
    {
        return (int) $this->db()->fetchOne("SELECT COUNT(*) FROM loss_tag WHERE piece_id = ? AND status = 'associated'", [$pieceId]);
    }

    /** Pose une puce nouvelle sur une pièce ; rend son identifiant de ligne. L'index unique refuse un identifiant déjà connu. */
    public function insertAssociatedTag(string $identifier, string $raw, string $technology, int $pieceId, bool $exercise, ?int $userId, string $now): int
    {
        return (int) $this->db()->fetchOne(
            'INSERT INTO loss_tag (identifier, raw, technology, status, piece_id, exercise, associated_at, created_by, created_at, updated_at)'
            . " VALUES (:identifier, :raw, :technology, 'associated', :piece, CAST(:exercise AS boolean), :now, :user, :now, :now) RETURNING id",
            ['identifier' => $identifier, 'raw' => $raw, 'technology' => $technology, 'piece' => $pieceId, 'exercise' => $exercise ? 'true' : 'false', 'user' => $userId, 'now' => $now]
        );
    }

    /** Une pièce en service porte-t-elle une puce posée, dans ce monde (réel ou exercice) ? Une puce retirée ne compte plus */
    public function associatedTagExists(bool $exercise): bool
    {
        return $this->db()->fetchOne(
            "SELECT 1 FROM loss_tag t JOIN loss_piece p ON p.id = t.piece_id WHERE t.status = 'associated' AND p.status <> 'retired' AND t.exercise = CAST(:exercise AS boolean) LIMIT 1",
            ['exercise' => $exercise ? 'true' : 'false']
        ) !== false;
    }

    /**
     * Nombre de faits de chaque type, vrais ou d'exercice : de quoi calculer l'avancement de l'installation sans rien stocker.
     *
     * @param list<string> $types
     *
     * @return array<string, int>
     */
    public function eventCounts(array $types, bool $exercise): array
    {
        $out = array_fill_keys($types, 0);
        foreach ($this->db()->fetchAllKeyValue(
            'SELECT type, COUNT(*) FROM loss_piece_event WHERE exercise = CAST(:exercise AS boolean) AND type IN (:types) GROUP BY type',
            ['exercise' => $exercise ? 'true' : 'false', 'types' => $types], ['types' => ArrayParameterType::STRING]
        ) as $type => $count) {
            $out[(string) $type] = (int) $count;
        }

        return $out;
    }

    /** Pose sur une pièce une puce déjà connue (lue à l'essai), pas encore posée */
    public function associateKnownTag(int $tagId, int $pieceId, string $now): void
    {
        $this->db()->executeStatement("UPDATE loss_tag SET status = 'associated', piece_id = ?, associated_at = ?, updated_at = ? WHERE id = ? AND status = ?", [$pieceId, $now, $now, $tagId, LossTag::KNOWN]);
    }

    public function retireTag(int $id, string $reason, string $now): void
    {
        $this->db()->executeStatement("UPDATE loss_tag SET status = 'retired', retired_reason = ?, retired_at = ?, updated_at = ? WHERE id = ?", [$reason, $now, $now, $id]);
    }

    /** @return list<int> identifiants des puces actives d'une pièce, verrouillées dans la transaction de l'appelant */
    public function lockActiveTags(int $pieceId): array
    {
        return array_map('intval', $this->db()->fetchFirstColumn("SELECT id FROM loss_tag WHERE piece_id = ? AND status = 'associated' ORDER BY id FOR NO KEY UPDATE", [$pieceId]));
    }

    /**
     * Écrit un fait (ajout seul).
     *
     * @param array<string, mixed> $data nombres, codes et identifiants seulement : jamais un nom
     */
    public function event(string $type, ?int $userId, ?int $pieceId, ?int $tagId, bool $exercise, array $data, string $now): void
    {
        $this->db()->executeStatement(
            'INSERT INTO loss_piece_event (type, occurred_at, user_id, piece_id, tag_id, source, exercise, data) VALUES (:type, :now, :user, :piece, :tag, :source, CAST(:exercise AS boolean), :data)',
            ['type' => $type, 'now' => $now, 'user' => $userId, 'piece' => $pieceId, 'tag' => $tagId, 'source' => $exercise ? 'exercise' : 'app',
                'exercise' => $exercise ? 'true' : 'false', 'data' => json_encode((object) $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]
        );
    }

    /** @return list<array<string, mixed>> les faits d'une pièce (les siens et ceux de ses puces), du plus ancien au plus récent */
    public function eventsOf(int $pieceId): array
    {
        // Toute la vie de la pièce (créée, puce posée, retirée…), et ses 200 dernières LECTURES seulement : depuis le lot L3-a un
        // lecteur de boîtier peut relire une puce des milliers de fois, la création et la pose ne doivent pas sortir de la fiche
        $columns = 'id, type, occurred_at, user_id, piece_id, tag_id, source, data';

        return $this->db()->fetchAllAssociative(
            "SELECT * FROM ((SELECT $columns FROM loss_piece_event WHERE piece_id = :piece AND type NOT IN (:reads))"
            . " UNION ALL (SELECT $columns FROM loss_piece_event WHERE piece_id = :piece AND type IN (:reads) ORDER BY id DESC LIMIT " . self::EVENTS_SHOWN . ')) e ORDER BY id',
            ['piece' => $pieceId, 'reads' => \App\LossPrevention\Entity\LossPieceEvent::READS], ['reads' => ArrayParameterType::STRING]
        );
    }

    /**
     * Synthèse des vraies pièces par variante : pièces en service, retirées, avec puce. Les totaux portent sur toutes les variantes
     * retenues par le filtre, pas sur la page.
     *
     * @param array{variantId?: int, productId?: int} $filters
     *
     * @return array{rows: list<array<string, mixed>>, total: int, totals: array{active: int, retired: int, tagged: int}}
     */
    public function synthesis(array $filters, int $page, int $limit): array
    {
        $where = ['NOT p.exercise'];
        $params = [];
        foreach (['variantId' => 'p.variant_id', 'productId' => 'p.product_id'] as $key => $column) {
            if (isset($filters[$key])) {
                $where[] = "$column = :$key";
                $params[$key] = $filters[$key];
            }
        }
        $from = 'FROM loss_piece p WHERE ' . implode(' AND ', $where);
        $tagged = "EXISTS (SELECT 1 FROM loss_tag t WHERE t.piece_id = p.id AND t.status = 'associated')";
        // en service : ni retirée ni vendue (lot L3-b2b : une pièce vendue n'est plus dans la boutique ; revenue, elle l'est de nouveau)
        $counts = "COUNT(*) FILTER (WHERE p.status NOT IN ('retired', 'sold')) AS active, COUNT(*) FILTER (WHERE p.status = 'sold') AS sold, COUNT(*) FILTER (WHERE p.status = 'retired') AS retired,"
            . " COUNT(*) FILTER (WHERE p.status NOT IN ('retired', 'sold') AND $tagged) AS tagged";
        $rows = $this->db()->fetchAllAssociative(
            "SELECT p.variant_id, MIN(p.product_id) AS product_id, $counts $from"
            . sprintf(' GROUP BY p.variant_id ORDER BY MIN(p.product_id), p.variant_id LIMIT %d OFFSET %d', $limit, ($page - 1) * $limit),
            $params
        );
        $totals = $this->db()->fetchAssociative("SELECT COUNT(DISTINCT p.variant_id) AS variants, $counts $from", $params) ?: [];

        return ['rows' => $rows, 'total' => (int) ($totals['variants'] ?? 0),
            'totals' => ['active' => (int) ($totals['active'] ?? 0), 'sold' => (int) ($totals['sold'] ?? 0), 'retired' => (int) ($totals['retired'] ?? 0), 'tagged' => (int) ($totals['tagged'] ?? 0)]];
    }

    /**
     * Efface toutes les pièces d'exercice du site, avec leurs puces et leurs faits, par la porte d'exercice de la base (les
     * déclencheurs ne laissent supprimer qu'une ligne marquée exercice). Dans la transaction de l'appelant.
     *
     * @return int nombre de pièces effacées
     */
    public function deleteExercise(): int
    {
        $conn = $this->db();
        $conn->executeStatement("SET LOCAL loss.purge = 'exercise'");
        $conn->executeStatement('DELETE FROM loss_counter_presence WHERE exercise'); // lot L3-b1 : une pièce d'exercice posée sur un tapis simulé
        $conn->executeStatement('DELETE FROM loss_piece_event WHERE exercise');
        $conn->executeStatement('DELETE FROM loss_tag WHERE exercise');
        $deleted = (int) $conn->executeStatement('DELETE FROM loss_piece WHERE exercise');
        $conn->executeStatement("SET LOCAL loss.purge = 'off'"); // la porte se referme avant la fin de la transaction

        return $deleted;
    }

    /**
     * Ce que le catalogue dit de ces variantes (lecture seule) : article, nom, couleur, taille, code-barres, stock, nombre de variantes
     * de l'article. Une variante supprimée du catalogue est absente du résultat.
     *
     * @param list<int> $variantIds
     *
     * @return array<int, array<string, mixed>> par identifiant de variante
     */
    public function variants(array $variantIds): array
    {
        $out = [];
        if ($variantIds === []) {
            return $out;
        }
        foreach ($this->db()->fetchAllAssociative(
            'SELECT v.id, v.product_id, v.barcode, v.stock_quantity, v.price, p.price AS product_price, p.name, p.barcode AS product_barcode, c.name AS color, s.name AS size,'
            . ' (SELECT COUNT(*) FROM product_variant x WHERE x.product_id = v.product_id) AS siblings'
            . ' FROM product_variant v JOIN product p ON p.id = v.product_id LEFT JOIN color c ON c.id = v.color_id LEFT JOIN size s ON s.id = v.size_id WHERE v.id IN (?)',
            [array_values(array_unique($variantIds))], [ArrayParameterType::INTEGER]
        ) as $row) {
            $out[(int) $row['id']] = $row;
        }

        return $out;
    }

    /** @return list<int> identifiants des variantes d'un article, dans l'ordre */
    public function variantIdsOfProduct(int $productId): array
    {
        return array_map('intval', $this->db()->fetchFirstColumn('SELECT id FROM product_variant WHERE product_id = ? ORDER BY id', [$productId]));
    }

    public function commandeExists(int $id): bool
    {
        return $this->db()->fetchOne('SELECT 1 FROM commande WHERE id = ?', [$id]) !== false;
    }

    private function db(): Connection
    {
        return $this->getEntityManager()->getConnection();
    }
}
