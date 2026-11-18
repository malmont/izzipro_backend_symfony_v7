<?php

namespace App\LossPrevention\Master;

use App\Services\TenantConnectionManager;

/**
 * Demandes de jumelage des boîtiers (lot L3-a2, `docs/prevention-pertes.md` § 16.16) : table `loss_box_pairing` de la BASE MAÎTRE,
 * la seule du module hors des bases de site (un boîtier qui attend son jumelage n'a pas encore de site). Schéma :
 * `ops/sql/loss_pairing_master.sql`. Comme les clés d'activation (`TenantAppKeyRepository`), ce dépôt parle à la base maître : il
 * est un service, hors du dossier `Repository/` du module (réservé aux dépôts Doctrine des bases de site, exclus de l'injection).
 */
class LossPairingRepository
{
    public const WAITING = 'waiting';
    public const PAIRED = 'paired';
    public const CLOSED = 'closed';
    private const COLUMNS = 'id, code_hash, machine_hash, version, arch, status, tenant_code, box_id, link, created_at, seen_at, expires_at, paired_at, delivered_at, closed_at, serial';

    /** La connexion de la transaction en cours : la même du début à la fin (jamais une connexion rouverte en route, qui écrirait hors transaction) */
    private ?\PDO $held = null;

    public function __construct(private readonly TenantConnectionManager $connectionManager)
    {
    }

    private function pdo(): \PDO
    {
        return $this->held ?? $this->connectionManager->getPdoMaster();
    }

    /**
     * Une transaction de la base maître.
     *
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    public function transaction(callable $work): mixed
    {
        if ($this->held !== null) {
            throw new \LogicException('Une transaction de la base maître est déjà ouverte.');
        }
        $pdo = $this->held = $this->connectionManager->getPdoMaster();
        try {
            $pdo->beginTransaction();
            $result = $work();
            $pdo->commit();

            return $result;
        } catch (\Throwable $e) {
            try {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
            } catch (\Throwable) {
                // la connexion est perdue : la base a déjà annulé la transaction
            }
            throw $e;
        } finally {
            $this->held = null;
        }
    }

    /** Verrou consultatif des demandes NOUVELLES (base maître) : leur nombre par heure se compte sans course */
    public const NEW_LOCK = 7461084;

    /**
     * Combien de demandes ont été créées depuis `$since`, compté sous verrou : deux dépôts de codes inconnus ne se croisent pas, le
     * plafond de la plateforme est exact (il ne dépend ni du cache ni du nombre d'appels simultanés). À appeler dans une transaction.
     */
    public function createdSince(string $since): int
    {
        $this->pdo()->exec('SELECT pg_advisory_xact_lock(' . self::NEW_LOCK . ')');
        $stmt = $this->pdo()->prepare('SELECT COUNT(*) FROM loss_box_pairing WHERE created_at > ?');
        $stmt->execute([$since]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Le site d'un jumelage, lu dans la base maître SANS cache : la décision de fermer un jumelage ne repose jamais sur une fiche
     * de site gardée en cache (une fiche fausse mènerait dans la base d'un autre site).
     *
     * @return array{code: string, dbname: string}|null null : site inconnu, ou sans base
     *
     * @throws \PDOException la base maître ne répond pas (l'appelant fait patienter le boîtier, et le dit au journal)
     */
    public function tenant(string $code): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT code, dbname FROM tenants WHERE code = ?');
        $stmt->execute([$code]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return is_array($row) && is_string($row['dbname'] ?? null) && $row['dbname'] !== '' ? ['code' => (string) $row['code'], 'dbname' => $row['dbname']] : null;
    }

    /** @return array<string, mixed>|null la demande de ce code ; `$lock` : tenue jusqu'à la fin de la transaction */
    public function byCodeHash(string $codeHash, bool $lock = false): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT ' . self::COLUMNS . ' FROM loss_box_pairing WHERE code_hash = ?' . ($lock ? ' FOR UPDATE' : ''));
        $stmt->execute([$codeHash]);

        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Dépose une demande. Deux dépôts du même code au même instant : un seul est gardé, le premier.
     *
     * @return bool vrai si la demande a été créée par cet appel
     */
    public function insert(string $codeHash, string $machineHash, string $version, string $arch, string $now, string $expiresAt, ?string $serial = null): bool
    {
        $stmt = $this->pdo()->prepare(
            'INSERT INTO loss_box_pairing (code_hash, machine_hash, version, arch, created_at, seen_at, expires_at, serial) VALUES (?, ?, ?, ?, ?, ?, ?, ?) ON CONFLICT (code_hash) DO NOTHING');
        $stmt->execute([$codeHash, $machineHash, $version, $arch, $now, $now, $expiresAt, $serial]);

        return $stmt->rowCount() === 1;
    }

    /** Le boîtier a donné signe de vie pour sa demande en attente : elle reste jumelable 30 jours de plus */
    public function renew(int $id, ?string $version, ?string $arch, string $now, string $expiresAt, ?string $serial = null): void
    {
        // lot L6-b : la série dite par la machine est gardée la première fois, jamais remplacée (comme le code)
        $this->pdo()->prepare(
            "UPDATE loss_box_pairing SET seen_at = ?, expires_at = ?, version = COALESCE(?, version), arch = COALESCE(?, arch), serial = COALESCE(serial, ?) WHERE id = ? AND status = 'waiting'")
            ->execute([$now, $expiresAt, $version, $arch, $serial, $id]);
    }

    /**
     * Un administrateur a jumelé cette demande à SON site. `$link` : l'aléa de ce jumelage (avec le code et le site, il désigne
     * le boîtier créé par ce jumelage-là).
     *
     * @throws \RuntimeException si la demande n'était plus en attente (rien n'est écrit)
     */
    public function pair(int $id, string $tenantCode, int $boxId, string $link, string $now): void
    {
        $stmt = $this->pdo()->prepare("UPDATE loss_box_pairing SET status = 'paired', tenant_code = ?, box_id = ?, link = ?, paired_at = ? WHERE id = ? AND status = 'waiting'");
        $stmt->execute([$tenantCode, $boxId, $link, $now, $id]);
        if ($stmt->rowCount() !== 1) {
            throw new \RuntimeException('Demande de jumelage : elle n\'était plus en attente.');
        }
    }

    /** Le boîtier a reçu son jeton (il peut le redemander avec sa preuve, si la réponse s'est perdue) */
    public function delivered(int $id, string $now): void
    {
        $this->pdo()->prepare("UPDATE loss_box_pairing SET delivered_at = ?, seen_at = ? WHERE id = ? AND status = 'paired'")->execute([$now, $now, $id]);
    }

    /** Le boîtier a été détaché de son site (révoqué, clé remise à zéro) : ce code ne vaut plus rien, pour toujours */
    public function close(int $id, string $now): void
    {
        $this->pdo()->prepare("UPDATE loss_box_pairing SET status = 'closed', closed_at = ? WHERE id = ? AND status <> 'closed'")->execute([$now, $id]);
    }

    // ----- Lot L6-b (17/11/2026, § 19.5) : les expéditions des boîtiers vendus, dans la même base et la même transaction que les demandes

    public const SHIPMENT_RESERVED = 'reserved';
    public const SHIPMENT_PLUGGED = 'plugged';
    public const SHIPMENT_CONFIRMED = 'confirmed';
    public const SHIPMENT_CANCELLED = 'cancelled';
    private const SHIPMENT_COLUMNS = 'id, serial, code_hash, tenant_code, order_ref, status, key_hash, ref, link, box_id, created_at, created_by, plugged_at, confirmed_at, confirmed_by, cancelled_at, cancelled_by';

    /** @return array<string, mixed>|null l'expédition vivante de ce code (jamais une annulée) ; `$lock` : tenue jusqu'à la fin de la transaction */
    public function shipmentByCodeHash(string $codeHash, bool $lock = false): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT ' . self::SHIPMENT_COLUMNS . " FROM loss_box_shipment WHERE code_hash = ? AND status <> 'cancelled'" . ($lock ? ' FOR UPDATE' : ''));
        $stmt->execute([$codeHash]);

        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    /** @return array{code: string, name: string, customDomain: ?string}|null la fiche d'un site, relue sans cache */
    public function tenantInfo(string $code): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT code, name, custom_domain FROM tenants WHERE code = ?');
        $stmt->execute([$code]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return is_array($row) ? ['code' => (string) $row['code'], 'name' => (string) $row['name'], 'customDomain' => $row['custom_domain'] !== null ? (string) $row['custom_domain'] : null] : null;
    }

    /** @return array<string, mixed>|null */
    public function shipment(int $id, bool $lock = false): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT ' . self::SHIPMENT_COLUMNS . ' FROM loss_box_shipment WHERE id = ?' . ($lock ? ' FOR UPDATE' : ''));
        $stmt->execute([$id]);

        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    /** @return list<array<string, mixed>> les expéditions, les plus récentes d'abord ; d'un seul site si `$tenantCode` */
    public function shipments(?string $tenantCode = null, ?string $status = null, int $limit = 200): array
    {
        $where = [];
        $params = [];
        if ($tenantCode !== null) {
            $where[] = 'tenant_code = ?';
            $params[] = $tenantCode;
        }
        if ($status !== null) {
            $where[] = 'status = ?';
            $params[] = $status;
        }
        $stmt = $this->pdo()->prepare('SELECT ' . self::SHIPMENT_COLUMNS . ' FROM loss_box_shipment' . ($where !== [] ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY id DESC LIMIT ' . max(1, min(500, $limit)));
        $stmt->execute($params);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /** @return int|null l'identifiant de l'expédition créée ; null si ce numéro de série ou ce code est déjà expédié */
    public function insertShipment(string $serial, string $codeHash, string $tenantCode, string $orderRef, string $by, string $now): ?int
    {
        $stmt = $this->pdo()->prepare('INSERT INTO loss_box_shipment (serial, code_hash, tenant_code, order_ref, created_at, created_by) VALUES (?, ?, ?, ?, ?, ?) ON CONFLICT DO NOTHING RETURNING id');
        // (ON CONFLICT sans cible : l'un ou l'autre des deux index uniques partiels, code ou numéro de série d'une expédition vivante)
        $stmt->execute([$serial, $codeHash, $tenantCode, $orderRef, $now, $by]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    /** Le boîtier est branché : les empreintes qui relieront son futur boîtier de site sont préparées (d'une expédition réservée) */
    public function plugShipment(int $id, string $keyHash, string $ref, string $link, string $now): bool
    {
        $stmt = $this->pdo()->prepare("UPDATE loss_box_shipment SET status = 'plugged', key_hash = ?, ref = ?, link = ?, plugged_at = ? WHERE id = ? AND status = 'reserved'");
        $stmt->execute([$keyHash, $ref, $link, $now, $id]);

        return $stmt->rowCount() === 1;
    }

    /** Non confirmée à temps : l'expédition revient à `reserved`, ses empreintes sont effacées (un nouveau branchement relance l'avis) */
    public function unplugShipment(int $id): bool
    {
        $stmt = $this->pdo()->prepare("UPDATE loss_box_shipment SET status = 'reserved', key_hash = NULL, ref = NULL, link = NULL, plugged_at = NULL WHERE id = ? AND status = 'plugged'");
        $stmt->execute([$id]);

        return $stmt->rowCount() === 1;
    }

    /** Confirmée par le site acheteur (ou jumelée par la saisie de son code) : le boîtier du site est créé, les empreintes s'effacent */
    public function confirmShipment(int $id, int $boxId, ?int $userId, string $now): bool
    {
        $stmt = $this->pdo()->prepare("UPDATE loss_box_shipment SET status = 'confirmed', box_id = ?, confirmed_at = ?, confirmed_by = ?, key_hash = NULL, ref = NULL, link = NULL WHERE id = ? AND status IN ('reserved', 'plugged')");
        $stmt->execute([$boxId, $now, $userId, $id]);

        return $stmt->rowCount() === 1;
    }

    /** Annulée par la plateforme : le code est libéré ; un boîtier déjà jumelé n'est pas touché (rien ici ne le désigne) */
    public function cancelShipment(int $id, string $by, string $now): bool
    {
        $stmt = $this->pdo()->prepare("UPDATE loss_box_shipment SET status = 'cancelled', cancelled_at = ?, cancelled_by = ?, key_hash = NULL, ref = NULL, link = NULL WHERE id = ? AND status IN ('reserved', 'plugged')");
        $stmt->execute([$now, $by, $id]);

        return $stmt->rowCount() === 1;
    }

    /** @param array<string, mixed> $data jamais le code ni une empreinte */
    public function shipmentEvent(int $id, string $type, ?string $actor, ?string $tenantCode, array $data, string $now): void
    {
        $this->pdo()->prepare('INSERT INTO loss_box_shipment_event (shipment_id, type, actor, tenant_code, data, created_at) VALUES (?, ?, ?, ?, ?::jsonb, ?)')
            ->execute([$id, $type, $actor, $tenantCode, json_encode($data ?: new \stdClass()), $now]);
    }

    /** @return list<array<string, mixed>> le journal d'une expédition, dans l'ordre */
    public function shipmentEvents(int $id): array
    {
        $stmt = $this->pdo()->prepare('SELECT type, actor, tenant_code, data, created_at FROM loss_box_shipment_event WHERE shipment_id = ? ORDER BY id');
        $stmt->execute([$id]);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }
}
