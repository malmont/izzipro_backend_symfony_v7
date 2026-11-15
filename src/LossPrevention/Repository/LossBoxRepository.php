<?php

namespace App\LossPrevention\Repository;

use App\LossPrevention\Entity\LossBox;
use App\LossPrevention\Entity\LossPieceEvent;
use App\LossPrevention\Entity\LossTag;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityRepository;

/**
 * Boîtiers, appareils et leurs faits (lot L3-a, `docs/prevention-pertes.md` § 16), lus et écrits en SQL. Aucune règle ici : le
 * service décide, la base garde (index unique de la clé, déclencheurs de la migration `Version20270113100000`).
 *
 * @extends EntityRepository<LossBox>
 */
class LossBoxRepository extends EntityRepository
{
    /** Verrou consultatif des créations de boîtiers d'un site : le plafond se compte sans course */
    public const CREATE_LOCK = 7461082;

    private const BOX = 'id, name, place, key_hint, status, generation, machine_hash, version, arch, enrolled_at, last_seen_at, clock_offset, exercise, created_at, revoked_at';

    /** @return int identifiant du boîtier créé ; `$keyHash` null : boîtier d'exercice (aucune machine ne s'y inscrit) */
    public function create(string $name, ?string $place, ?string $keyHash, ?string $keyHint, bool $exercise, ?int $userId, string $now): int
    {
        return (int) $this->db()->fetchOne(
            'INSERT INTO loss_box (name, place, key_hash, key_hint, status, exercise, created_by, created_at, updated_at, enrolled_at, last_seen_at)'
            . ' VALUES (:name, :place, :hash, :hint, :status, CAST(:exercise AS boolean), :user, :now, :now, :seen, :seen) RETURNING id',
            ['name' => $name, 'place' => $place, 'hash' => $keyHash, 'hint' => $keyHint, 'status' => $exercise ? LossBox::ACTIVE : LossBox::PENDING,
                'exercise' => $exercise ? 'true' : 'false', 'user' => $userId, 'now' => $now, 'seen' => $exercise ? $now : null]
        );
    }

    /**
     * @param bool $lock  la ligne est verrouillée pour l'écrire (signe de vie, révocation, remise à zéro de la clé)
     * @param bool $share la ligne est seulement tenue : elle ne change pas tant que la transaction dure (lot de faits)
     *
     * @return array<string, mixed>|null
     */
    public function box(int $id, bool $lock = false, bool $share = false): ?array
    {
        return $this->db()->fetchAssociative('SELECT ' . self::BOX . ' FROM loss_box WHERE id = ?' . ($lock ? ' FOR UPDATE' : ($share ? ' FOR SHARE' : '')), [$id]) ?: null;
    }

    /** @return array<string, mixed>|null le boîtier de cette clé, verrouillé (deux inscriptions de la même clé ne se croisent pas) */
    public function boxByKeyHash(string $keyHash): ?array
    {
        return $this->db()->fetchAssociative('SELECT ' . self::BOX . ' FROM loss_box WHERE key_hash = ? FOR UPDATE', [$keyHash]) ?: null;
    }

    /** @return list<array<string, mixed>> */
    public function boxes(bool $exercise): array
    {
        return $this->db()->fetchAllAssociative('SELECT ' . self::BOX . ' FROM loss_box WHERE exercise = CAST(? AS boolean) ORDER BY id', [$exercise ? 'true' : 'false']);
    }

    /** @return list<array<string, mixed>> les boîtiers d'un genre qui ne sont pas révoqués : ce que le tableau de santé en lit */
    public function liveBoxes(bool $exercise): array
    {
        return $this->db()->fetchAllAssociative("SELECT id, name, status, last_seen_at FROM loss_box WHERE exercise = CAST(? AS boolean) AND status <> 'revoked' ORDER BY id", [$exercise ? 'true' : 'false']);
    }

    /** Boîtiers d'un genre qui ne sont pas révoqués */
    public function liveCount(bool $exercise): int
    {
        return (int) $this->db()->fetchOne("SELECT COUNT(*) FROM loss_box WHERE exercise = CAST(? AS boolean) AND status <> 'revoked'", [$exercise ? 'true' : 'false']);
    }

    /** Nombre d'appareils qu'un boîtier a déjà annoncés */
    public function deviceCount(int $boxId): int
    {
        return (int) $this->db()->fetchOne('SELECT COUNT(*) FROM loss_device WHERE box_id = ?', [$boxId]);
    }

    public function rename(int $id, string $name, ?string $place, string $now): void
    {
        $this->db()->executeStatement('UPDATE loss_box SET name = :name, place = :place, updated_at = :now WHERE id = :id', ['name' => $name, 'place' => $place, 'now' => $now, 'id' => $id]);
    }

    /** Nouvelle clé : le boîtier attend une machine ; la génération avance (les jetons d'avant ne valent plus rien) */
    public function resetKey(int $id, string $keyHash, string $keyHint, string $now): void
    {
        $this->db()->executeStatement(
            "UPDATE loss_box SET key_hash = :hash, key_hint = :hint, status = 'pending', generation = generation + 1, machine_hash = NULL, updated_at = :now WHERE id = :id",
            ['hash' => $keyHash, 'hint' => $keyHint, 'now' => $now, 'id' => $id]
        );
    }

    /** Inscription d'une machine : le boîtier devient actif ; la génération avance. Rend la nouvelle génération */
    public function enrol(int $id, string $machineHash, string $version, string $arch, string $now): int
    {
        return (int) $this->db()->fetchOne(
            "UPDATE loss_box SET status = 'active', generation = generation + 1, machine_hash = :machine, version = :version, arch = :arch, enrolled_at = :now, last_seen_at = :now, clock_offset = NULL,"
            . ' updated_at = :now WHERE id = :id RETURNING generation',
            ['machine' => $machineHash, 'version' => $version, 'arch' => $arch, 'now' => $now, 'id' => $id]
        );
    }

    public function revoke(int $id, string $now): void
    {
        $this->db()->executeStatement("UPDATE loss_box SET status = 'revoked', revoked_at = :now, updated_at = :now WHERE id = :id AND status <> 'revoked'", ['now' => $now, 'id' => $id]);
    }

    /** Signe de vie : dernier contact, version annoncée, écart d'horloge mesuré (secondes, heure du boîtier moins heure du serveur) */
    public function seen(int $id, string $now, ?string $version, ?int $clockOffset): void
    {
        $this->db()->executeStatement(
            'UPDATE loss_box SET last_seen_at = :now, version = COALESCE(:version, version), clock_offset = :offset, updated_at = :now WHERE id = :id',
            ['now' => $now, 'version' => $version, 'offset' => $clockOffset, 'id' => $id]
        );
    }

    /**
     * @param list<int> $boxIds
     *
     * @return array<int, list<array<string, mixed>>> appareils par boîtier, dans l'ordre de leur première déclaration
     */
    public function devicesOf(array $boxIds): array
    {
        $out = array_fill_keys($boxIds, []);
        if ($boxIds === []) {
            return $out;
        }
        foreach ($this->db()->fetchAllAssociative('SELECT id, box_id, serial, kind, model, status, zone, cash_register_id, first_seen_at, last_seen_at FROM loss_device WHERE box_id IN (?) ORDER BY id',
            [$boxIds], [ArrayParameterType::INTEGER]) as $row) {
            $out[(int) $row['box_id']][] = $row;
        }

        return $out;
    }

    public function insertDevice(int $boxId, string $serial, string $kind, ?string $model, string $status, ?string $zone, bool $exercise, string $now): int
    {
        return (int) $this->db()->fetchOne(
            'INSERT INTO loss_device (box_id, serial, kind, model, status, zone, exercise, first_seen_at, last_seen_at) VALUES (:box, :serial, :kind, :model, :status, :zone, CAST(:exercise AS boolean), :now, :now) RETURNING id',
            ['box' => $boxId, 'serial' => $serial, 'kind' => $kind, 'model' => $model, 'status' => $status, 'zone' => $zone, 'exercise' => $exercise ? 'true' : 'false', 'now' => $now]
        );
    }

    /** État d'un appareil ; `$seen` : il vient d'être annoncé par son boîtier (sinon seule sa panne est notée) */
    public function deviceStatus(int $id, string $status, ?string $model, bool $seen, string $now): void
    {
        $this->db()->executeStatement(
            'UPDATE loss_device SET status = :status, model = COALESCE(:model, model)' . ($seen ? ', last_seen_at = :now' : '') . ' WHERE id = :id',
            ['status' => $status, 'model' => $model, 'id' => $id] + ($seen ? ['now' => $now] : [])
        );
    }

    /** @param array<string, mixed> $data nombres, codes et identifiants seulement */
    public function event(int $boxId, ?int $deviceId, string $type, ?int $userId, bool $exercise, array $data, string $now): void
    {
        $this->db()->executeStatement(
            'INSERT INTO loss_box_event (box_id, device_id, type, occurred_at, user_id, exercise, data) VALUES (:box, :device, :type, :now, :user, CAST(:exercise AS boolean), :data)',
            ['box' => $boxId, 'device' => $deviceId, 'type' => $type, 'now' => $now, 'user' => $userId, 'exercise' => $exercise ? 'true' : 'false',
                'data' => json_encode((object) $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]
        );
    }

    /** @return list<array<string, mixed>> les derniers faits d'un boîtier, le plus récent d'abord */
    public function eventsOf(int $boxId, int $limit): array
    {
        return $this->db()->fetchAllAssociative('SELECT id, device_id, type, occurred_at, user_id, data FROM loss_box_event WHERE box_id = ? ORDER BY id DESC LIMIT ' . max(1, $limit), [$boxId]);
    }

    /** Nombre de faits de pièces reçus d'un boîtier */
    public function pieceFactCount(int $boxId): int
    {
        return (int) $this->db()->fetchOne('SELECT COUNT(*) FROM loss_piece_event WHERE box_id = ?', [$boxId]);
    }

    /**
     * Écrit un fait de pièce venu d'un boîtier. La clé d'idempotence décide : un fait déjà reçu n'est pas écrit une seconde fois,
     * même si deux envois du même lot se croisent (index unique, `ON CONFLICT`).
     *
     * @param array<string, mixed> $data
     *
     * @return bool vrai si le fait vient d'être écrit, faux s'il était déjà connu
     */
    public function pieceFact(string $type, string $at, ?int $pieceId, ?int $tagId, int $boxId, ?int $deviceId, ?string $eventKey, bool $exercise, array $data, string $now): bool
    {
        return (bool) $this->db()->fetchOne(
            'INSERT INTO loss_piece_event (type, occurred_at, user_id, piece_id, tag_id, source, event_key, exercise, data, box_id, device_id, received_at)'
            . ' VALUES (:type, :at, NULL, :piece, :tag, :source, :key, CAST(:exercise AS boolean), :data, :box, :device, :now)'
            . ' ON CONFLICT (event_key) WHERE event_key IS NOT NULL DO NOTHING RETURNING id',
            ['type' => $type, 'at' => $at, 'piece' => $pieceId, 'tag' => $tagId, 'source' => $exercise ? LossPieceEvent::SOURCE_EXERCISE : LossPieceEvent::SOURCE_BOX, 'key' => $eventKey,
                'exercise' => $exercise ? 'true' : 'false', 'data' => json_encode((object) $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'box' => $boxId, 'device' => $deviceId, 'now' => $now]
        );
    }

    /**
     * @param list<string> $identifiers formes canoniques
     *
     * @return array<string, array<string, mixed>> puces connues, par identifiant
     */
    public function tagsByIdentifier(array $identifiers): array
    {
        if ($identifiers === []) {
            return [];
        }
        $out = [];
        foreach ($this->db()->fetchAllAssociative('SELECT id, identifier, piece_id, status, exercise FROM loss_tag WHERE identifier IN (?)', [$identifiers], [ArrayParameterType::STRING]) as $row) {
            $out[(string) $row['identifier']] = $row;
        }

        return $out;
    }

    /** @return list<array{piece_id: int, tag_id: int}> pièces d'exercice qui portent une puce, les plus récentes d'abord */
    public function exercisePiecesWithTag(int $limit): array
    {
        return $this->db()->fetchAllAssociative(
            "SELECT t.piece_id, t.id AS tag_id FROM loss_tag t JOIN loss_piece p ON p.id = t.piece_id WHERE t.exercise AND t.status = :status AND p.status <> 'retired' ORDER BY t.id DESC LIMIT " . max(1, $limit),
            ['status' => LossTag::ASSOCIATED]
        );
    }

    /** Efface les boîtiers d'exercice, dans l'ordre que la base impose : faits, appareils, boîtiers. Rend le nombre de boîtiers effacés */
    public function deleteExercise(): int
    {
        $conn = $this->db();
        $conn->executeStatement("SET LOCAL loss.purge = 'exercise'");
        $conn->executeStatement('DELETE FROM loss_piece_event WHERE exercise AND box_id IS NOT NULL');
        $conn->executeStatement('DELETE FROM loss_box_event WHERE exercise');
        $conn->executeStatement('DELETE FROM loss_device WHERE exercise');
        $deleted = (int) $conn->executeStatement('DELETE FROM loss_box WHERE exercise');
        $conn->executeStatement("SET LOCAL loss.purge = 'off'"); // la porte se referme avant la fin de la transaction

        return $deleted;
    }

    private function db(): Connection
    {
        return $this->getEntityManager()->getConnection();
    }
}
