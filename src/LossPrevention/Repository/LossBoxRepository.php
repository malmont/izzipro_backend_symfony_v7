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
    /** Verrou consultatif de la liaison d'un tapis à un poste de caisse (première des deux clés ; la seconde est le poste) */
    public const REGISTER_LOCK = 7461083;

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
     * @return array<int, list<array<string, mixed>>> appareils par boîtier, dans l'ordre de leur première déclaration ; `$withRetired`
     *                                                faux : sans les appareils retirés (remplacés)
     */
    public function devicesOf(array $boxIds, bool $withRetired = true): array
    {
        $out = array_fill_keys($boxIds, []);
        if ($boxIds === []) {
            return $out;
        }
        foreach ($this->db()->fetchAllAssociative('SELECT id, box_id, serial, kind, model, status, zone, cash_register_id, first_seen_at, last_seen_at FROM loss_device WHERE box_id IN (?)'
            . ($withRetired ? '' : " AND status <> 'retired'") . ' ORDER BY id', [$boxIds], [ArrayParameterType::INTEGER]) as $row) {
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

    /** @return array<string, mixed>|null un appareil de CE boîtier ; `$lock` : pour l'écrire */
    public function device(int $boxId, int $deviceId, bool $lock = false): ?array
    {
        return $this->db()->fetchAssociative('SELECT id, box_id, serial, kind, model, status, zone, cash_register_id, exercise, last_seen_at FROM loss_device WHERE id = ? AND box_id = ?'
            . ($lock ? ' FOR UPDATE' : ''), [$deviceId, $boxId]) ?: null;
    }

    /** Relie un tapis à un poste de caisse, ou donne sa zone à un lecteur (une seule des deux colonnes change) */
    public function assignDevice(int $id, string $column, int|string|null $value): void
    {
        $this->db()->executeStatement('UPDATE loss_device SET ' . ($column === 'cash_register_id' ? 'cash_register_id' : 'zone') . ' = ? WHERE id = ?', [$value, $id]);
    }

    /** Tient le poste de caisse jusqu'à la fin de la transaction (verrou consultatif : rien n'est verrouillé dans les tables de la caisse) */
    public function lockRegister(int $cashRegisterId): void
    {
        $this->db()->executeStatement('SELECT pg_advisory_xact_lock(?, ?)', [self::REGISTER_LOCK, $cashRegisterId]);
    }

    /** Le tapis en service déjà relié à ce poste, s'il y en a un (hors `$exceptDeviceId`) */
    public function matOfRegisterId(int $cashRegisterId, int $exceptDeviceId = 0): ?int
    {
        $id = $this->db()->fetchOne("SELECT id FROM loss_device WHERE cash_register_id = ? AND status <> 'retired' AND NOT exercise AND id <> ? LIMIT 1", [$cashRegisterId, $exceptDeviceId]);

        return $id === false ? null : (int) $id;
    }

    /**
     * Le tapis en service d'un poste de caisse, avec ce qu'il faut pour savoir s'il répond : son état, son boîtier et le dernier
     * signe de vie de celui-ci. Vrais tapis seulement.
     *
     * @return array<string, mixed>|null
     */
    public function matOfRegister(int $cashRegisterId): ?array
    {
        return $this->db()->fetchAssociative(
            'SELECT d.id, d.status, b.id AS box_id, b.status AS box_status, b.last_seen_at AS box_seen, b.clock_offset AS box_clock FROM loss_device d JOIN loss_box b ON b.id = d.box_id'
            . " WHERE d.cash_register_id = ? AND d.kind = 'counter_mat' AND d.status <> 'retired' AND NOT d.exercise AND b.status <> 'revoked' ORDER BY d.id LIMIT 1", [$cashRegisterId]) ?: null;
    }

    /**
     * Les tapis en service d'un genre, pour le tableau de santé : état, poste, boîtier, et s'ils ont déjà lu une puce.
     *
     * @return list<array<string, mixed>>
     */
    public function liveMats(bool $exercise): array
    {
        return $this->db()->fetchAllAssociative(
            'SELECT d.id, d.serial, d.model, d.status, d.cash_register_id, d.last_seen_at, b.status AS box_status, b.last_seen_at AS box_seen, b.clock_offset AS box_clock,'
            . ' EXISTS (SELECT 1 FROM loss_counter_presence c WHERE c.device_id = d.id) AS has_read, r.name AS register_name'
            . ' FROM loss_device d JOIN loss_box b ON b.id = d.box_id LEFT JOIN cash_register r ON r.id = d.cash_register_id'
            . " WHERE d.kind = 'counter_mat' AND d.status <> 'retired' AND d.exercise = CAST(? AS boolean) AND b.status <> 'revoked' ORDER BY d.id", [$exercise ? 'true' : 'false']);
    }

    /**
     * Les imprimantes à puce en service (lot L3-c), avec l'état de leur boîtier et si elles ont déjà écrit une puce relue conforme.
     *
     * @return list<array<string, mixed>>
     */
    public function livePrinters(bool $exercise): array
    {
        return $this->db()->fetchAllAssociative(
            'SELECT d.id, d.serial, d.model, d.status, d.last_seen_at, b.status AS box_status, b.last_seen_at AS box_seen,'
            . " EXISTS (SELECT 1 FROM loss_print_job_line l JOIN loss_print_job j ON j.id = l.job_id WHERE j.device_id = d.id AND l.status = 'written') AS has_written"
            . ' FROM loss_device d JOIN loss_box b ON b.id = d.box_id'
            . " WHERE d.kind = 'printer' AND d.status <> 'retired' AND d.exercise = CAST(? AS boolean) AND b.status <> 'revoked' ORDER BY d.id", [$exercise ? 'true' : 'false']);
    }

    /** @return array{id: int, name: string}|null un poste de caisse actif du site (lecture du catalogue de la caisse, jamais d'écriture) */
    public function cashRegister(int $id): ?array
    {
        $row = $this->db()->fetchAssociative('SELECT id, name FROM cash_register WHERE id = ? AND is_active', [$id]);

        return $row ? ['id' => (int) $row['id'], 'name' => (string) $row['name']] : null;
    }

    /**
     * @param list<int> $ids
     *
     * @return array<int, string> noms des postes de caisse (actifs ou non : un tapis garde son poste affiché)
     */
    public function cashRegisterNames(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return array_map('strval', $this->db()->fetchAllKeyValue('SELECT id, name FROM cash_register WHERE id IN (?)', [$ids], [ArrayParameterType::INTEGER]));
    }

    /** Un appareil retiré rend son poste de caisse pour de bon : s'il revient un jour, il revient sans poste */
    public function retireDevice(int $id): void
    {
        $this->db()->executeStatement("UPDATE loss_device SET status = 'retired', cash_register_id = NULL WHERE id = ?", [$id]);
    }

    // ----- Présence sur un tapis de comptoir (projection des faits COUNTER_SEEN et COUNTER_LEFT)

    /**
     * Une puce arrive sur un tapis : une présence s'ouvre, sauf si elle y est déjà (index unique des présences ouvertes), et sauf si
     * l'on connaît déjà un départ PLUS RÉCENT que cette arrivée (un lot arrivé en retard ne pose pas une pièce fantôme). Une puce
     * n'est que sur un tapis à la fois : si un autre la croyait encore posée, sa présence se ferme. La pièce est celle que porte la
     * puce à cet instant.
     */
    public function openPresence(int $deviceId, int $tagId, string $since, bool $exercise): void
    {
        $this->db()->executeStatement('UPDATE loss_counter_presence SET until = CAST(? AS timestamp) WHERE tag_id = ? AND device_id <> ? AND until IS NULL AND since <= CAST(? AS timestamp)',
            [$since, $tagId, $deviceId, $since]);
        $this->db()->executeStatement(
            'INSERT INTO loss_counter_presence (device_id, tag_id, piece_id, since, exercise) SELECT :device, t.id, t.piece_id, CAST(:since AS timestamp), CAST(:exercise AS boolean) FROM loss_tag t WHERE t.id = :tag'
            . ' AND NOT EXISTS (SELECT 1 FROM loss_counter_presence c WHERE c.device_id = :device AND c.tag_id = :tag AND c.until > CAST(:since AS timestamp))'
            . ' ON CONFLICT (device_id, tag_id) WHERE until IS NULL DO NOTHING',
            ['device' => $deviceId, 'tag' => $tagId, 'since' => $since, 'exercise' => $exercise ? 'true' : 'false']);
    }

    /**
     * Une puce quitte un tapis : sa présence ouverte se ferme, si ce départ n'est pas plus ancien que l'arrivée qu'on connaît. Sans
     * présence ouverte, le départ est retenu quand même (une présence d'un instant) : une arrivée plus ancienne, reçue ensuite,
     * n'ouvrira rien.
     */
    public function closePresence(int $deviceId, int $tagId, string $until, bool $exercise): void
    {
        $closed = (int) $this->db()->executeStatement('UPDATE loss_counter_presence SET until = CAST(? AS timestamp) WHERE device_id = ? AND tag_id = ? AND until IS NULL AND since <= CAST(? AS timestamp)',
            [$until, $deviceId, $tagId, $until]);
        if ($closed === 0) {
            $this->db()->executeStatement(
                'INSERT INTO loss_counter_presence (device_id, tag_id, piece_id, since, until, exercise) SELECT :device, t.id, t.piece_id, CAST(:at AS timestamp), CAST(:at AS timestamp), CAST(:exercise AS boolean) FROM loss_tag t'
                . ' WHERE t.id = :tag AND NOT EXISTS (SELECT 1 FROM loss_counter_presence c WHERE c.device_id = :device AND c.tag_id = :tag AND (c.until IS NULL OR c.until >= CAST(:at AS timestamp)))',
                ['device' => $deviceId, 'tag' => $tagId, 'at' => $until, 'exercise' => $exercise ? 'true' : 'false']);
        }
    }

    /** Un départ à l'heure douteuse : la puce n'est plus posée pour nous, à l'heure du serveur (jamais avant son arrivée) */
    public function closePresencesOfTag(int $deviceId, int $tagId, string $until): void
    {
        $this->db()->executeStatement('UPDATE loss_counter_presence SET until = GREATEST(CAST(? AS timestamp), since) WHERE device_id = ? AND tag_id = ? AND until IS NULL', [$until, $deviceId, $tagId]);
    }

    /**
     * Un tapis en panne, retiré, ou dont le boîtier s'est tu : ce qu'on croyait posé dessus ne l'est plus pour nous. Le boîtier
     * redit ce qui est sur le tapis à son retour.
     *
     * @param list<int> $deviceIds
     */
    public function closePresencesOf(array $deviceIds, string $until): void
    {
        if ($deviceIds !== []) {
            $this->db()->executeStatement('UPDATE loss_counter_presence SET until = GREATEST(CAST(? AS timestamp), since) WHERE device_id IN (?) AND until IS NULL', [$until, $deviceIds],
                [1 => ArrayParameterType::INTEGER]);
        }
    }

    /** @return list<int> les tapis d'un boîtier (retirés compris : leurs présences sont déjà fermées) */
    public function matIdsOf(int $boxId): array
    {
        return array_map('intval', $this->db()->fetchFirstColumn("SELECT id FROM loss_device WHERE box_id = ? AND kind = 'counter_mat'", [$boxId]));
    }

    /** Un boîtier révoqué rend les postes de caisse de ses tapis : un autre tapis peut les prendre */
    public function releaseRegistersOf(int $boxId): void
    {
        $this->db()->executeStatement('UPDATE loss_device SET cash_register_id = NULL WHERE box_id = ? AND cash_register_id IS NOT NULL', [$boxId]);
    }

    /** @return list<array{tag_id: int, piece_id: ?int}> les puces posées en ce moment sur un tapis, la plus ancienne d'abord */
    public function openPresencesOf(int $deviceId): array
    {
        return $this->db()->fetchAllAssociative('SELECT c.tag_id, t.piece_id FROM loss_counter_presence c JOIN loss_tag t ON t.id = c.tag_id WHERE c.device_id = ? AND c.until IS NULL ORDER BY c.id', [$deviceId]);
    }

    /** @return list<array{id: int, name: string, is_active: bool}> tous les postes de caisse du site, actifs ou non (lecture du catalogue de la caisse, jamais d'écriture) */
    public function cashRegisters(): array
    {
        return $this->db()->fetchAllAssociative('SELECT id, name, is_active FROM cash_register ORDER BY id');
    }

    /** @return array<int, int> le tapis en service de chaque poste de caisse qui en a un : poste => appareil */
    public function matsByRegister(): array
    {
        return array_map('intval', $this->db()->fetchAllKeyValue("SELECT d.cash_register_id, MIN(d.id) FROM loss_device d JOIN loss_box b ON b.id = d.box_id WHERE d.kind = 'counter_mat'"
            . " AND d.status <> 'retired' AND NOT d.exercise AND b.status <> 'revoked' AND d.cash_register_id IS NOT NULL GROUP BY d.cash_register_id"));
    }

    /** Nombre de postes de caisse actifs du site */
    public function activeRegisterCount(): int
    {
        return (int) $this->db()->fetchOne('SELECT COUNT(*) FROM cash_register WHERE is_active');
    }

    /**
     * @param list<int> $deviceIds
     *
     * @return array<int, int> nombre de puces posées en ce moment, par tapis
     */
    public function presentCounts(array $deviceIds): array
    {
        if ($deviceIds === []) {
            return [];
        }

        return array_map('intval', $this->db()->fetchAllKeyValue('SELECT device_id, COUNT(*) FROM loss_counter_presence WHERE device_id IN (?) AND until IS NULL GROUP BY device_id', [$deviceIds],
            [ArrayParameterType::INTEGER]));
    }

    /**
     * Les pièces « lues » sur un tapis : posées dessus en ce moment, ou parties depuis `$since`. Une pièce vendue ou retirée ne
     * compte pas ; une puce retirée non plus.
     *
     * @return list<array{variant: int, present: bool}> une ligne par pièce lue : sa variante, et si elle est encore sur le tapis
     */
    public function readOnMat(int $deviceId, string $since): array
    {
        return array_map(static fn (array $row): array => ['variant' => (int) $row['variant_id'], 'present' => (bool) $row['present']], $this->db()->fetchAllAssociative(
            // la pièce est celle que la puce porte AUJOURD'HUI : une puce posée sur le tapis puis associée à sa pièce est lue
            'SELECT p.id, p.variant_id, BOOL_OR(c.until IS NULL) AS present FROM loss_counter_presence c JOIN loss_tag t ON t.id = c.tag_id JOIN loss_piece p ON p.id = t.piece_id'
            . " WHERE c.device_id = ? AND (c.until IS NULL OR c.until >= ?) AND t.status = 'associated' AND p.status NOT IN ('sold', 'retired') GROUP BY p.id, p.variant_id", [$deviceId, $since]));
    }

    /**
     * @param list<int> $variantIds
     *
     * @return list<int> celles de ces variantes qui sont SUIVIES : au moins une vraie pièce en service porte une puce
     */
    public function trackedVariants(array $variantIds): array
    {
        if ($variantIds === []) {
            return [];
        }

        return array_map('intval', $this->db()->fetchFirstColumn(
            "SELECT DISTINCT p.variant_id FROM loss_piece p JOIN loss_tag t ON t.piece_id = p.id AND t.status = 'associated' WHERE p.variant_id IN (?) AND NOT p.exercise AND p.status NOT IN ('sold', 'retired')",
            [$variantIds], [ArrayParameterType::INTEGER]));
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

    /** Efface les boîtiers d'exercice, dans l'ordre que la base impose : faits et présences, appareils, boîtiers. Rend le nombre de boîtiers effacés */
    public function deleteExercise(): int
    {
        $conn = $this->db();
        $conn->executeStatement("SET LOCAL loss.purge = 'exercise'");
        $conn->executeStatement('DELETE FROM loss_print_job WHERE exercise'); // lot L3-c : les travaux de l'imprimante simulée (leurs lignes partent avec eux)
        $conn->executeStatement('DELETE FROM loss_piece_event WHERE exercise AND box_id IS NOT NULL');
        $conn->executeStatement('DELETE FROM loss_counter_presence WHERE exercise');
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
