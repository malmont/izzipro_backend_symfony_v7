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
    private const COLUMNS = 'id, code_hash, machine_hash, version, arch, status, tenant_code, box_id, link, created_at, seen_at, expires_at, paired_at, delivered_at, closed_at';

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
    public function insert(string $codeHash, string $machineHash, string $version, string $arch, string $now, string $expiresAt): bool
    {
        $stmt = $this->pdo()->prepare(
            'INSERT INTO loss_box_pairing (code_hash, machine_hash, version, arch, created_at, seen_at, expires_at) VALUES (?, ?, ?, ?, ?, ?, ?) ON CONFLICT (code_hash) DO NOTHING');
        $stmt->execute([$codeHash, $machineHash, $version, $arch, $now, $now, $expiresAt]);

        return $stmt->rowCount() === 1;
    }

    /** Le boîtier a donné signe de vie pour sa demande en attente : elle reste jumelable 30 jours de plus */
    public function renew(int $id, ?string $version, ?string $arch, string $now, string $expiresAt): void
    {
        $this->pdo()->prepare(
            "UPDATE loss_box_pairing SET seen_at = ?, expires_at = ?, version = COALESCE(?, version), arch = COALESCE(?, arch) WHERE id = ? AND status = 'waiting'")
            ->execute([$now, $expiresAt, $version, $arch, $id]);
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
}
