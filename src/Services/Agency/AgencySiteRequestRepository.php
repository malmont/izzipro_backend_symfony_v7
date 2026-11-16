<?php

namespace App\Services\Agency;

use App\Services\TenantConnectionManager;

/**
 * La file des demandes de création d'un site par une agence (lot AG-2, 15/11/2026, table `agency_site_requests` de la base maître,
 * `docs/agences-et-import.md` § 8). PDO, jamais l'ORM. La connexion maître est partagée par tout le processus : une transaction
 * ouverte ici est toujours refermée, même en cas d'erreur.
 *
 * Une demande passe de `pending` à `building` (un seul worker la prend : le pas est fait par la base), puis à `ready` ou à `failed`.
 * Tant qu'elle est en cours, elle retient une place de l'agence et le code du site (index unique partiel).
 */
class AgencySiteRequestRepository
{
    public const PENDING = 'pending';
    public const BUILDING = 'building';
    public const READY = 'ready';
    public const FAILED = 'failed';
    private const COLUMNS = 'id, agency_id, tenant_code, name, admin_email, options, status, error_code, result, requested_by, requested_at, started_at, finished_at';
    /** Verrou de la fabrication : une seule copie du site de référence à la fois sur toute la plateforme */
    private const BUILD_LOCK = 'agency-site-build';

    public function __construct(private readonly TenantConnectionManager $tenants, private readonly AgencyRepository $agencies)
    {
    }

    /**
     * Enregistre une demande, sous verrou de l'agence : le plafond de demandes par 24 heures et la limite de sites sont comptés et la
     * demande écrite dans la même transaction. Rend la demande, ou `daily` (plafond du jour atteint), `limit` (limite de sites
     * atteinte, demandes en cours comprises), `taken` (ce code est déjà demandé : index unique).
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>|string
     */
    public function open(int $agencyId, string $code, string $name, string $adminEmail, array $options, ?string $by, ?int $maxSites, int $dailyLimit): array|string
    {
        $pdo = $this->pdo();
        $pdo->beginTransaction();
        try {
            $lock = $pdo->prepare('SELECT id FROM agencies WHERE id = ? FOR UPDATE');
            $lock->execute([$agencyId]);
            $today = $pdo->prepare("SELECT COUNT(*) FROM agency_site_requests WHERE agency_id = ? AND requested_at > NOW() - INTERVAL '24 hours'");
            $today->execute([$agencyId]);
            if ((int) $today->fetchColumn() >= $dailyLimit) {
                $pdo->rollBack();

                return 'daily';
            }
            if ($maxSites !== null && $this->agencies->used($pdo, $agencyId) >= $maxSites) {
                $pdo->rollBack();

                return 'limit';
            }
            $pdo->exec('SAVEPOINT agency_site_request');
            try {
                $stmt = $pdo->prepare('INSERT INTO agency_site_requests (agency_id, tenant_code, name, admin_email, options, requested_by) VALUES (?, ?, ?, ?, ?, ?) RETURNING id');
                $stmt->execute([$agencyId, $code, $name, $adminEmail, json_encode($options, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES), $by !== null ? mb_substr($by, 0, 255) : null]);
                $id = (int) $stmt->fetchColumn();
            } catch (\PDOException $e) {
                if ($e->getCode() !== '23505') {
                    throw $e;
                }
                $pdo->exec('ROLLBACK TO SAVEPOINT agency_site_request');
                $pdo->rollBack();

                return 'taken';
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return $this->find($id) ?? throw new \RuntimeException('demande non relue');
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT ' . self::COLUMNS . ' FROM agency_site_requests WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row ? self::decode($row) : null;
    }

    /** @return list<array<string, mixed>> les demandes d'une agence, de la plus récente à la plus ancienne */
    public function ofAgency(int $agencyId, int $limit = 50): array
    {
        $stmt = $this->pdo()->prepare('SELECT ' . self::COLUMNS . ' FROM agency_site_requests WHERE agency_id = ? ORDER BY id DESC LIMIT ' . max(1, min(200, $limit)));
        $stmt->execute([$agencyId]);

        return array_map(self::decode(...), $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: []);
    }

    /** La dernière demande de ce site pour cette agence, qui a abouti (pour renvoyer l'invitation) */
    public function readyFor(int $agencyId, string $code): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT ' . self::COLUMNS . " FROM agency_site_requests WHERE agency_id = ? AND tenant_code = ? AND status = 'ready' ORDER BY id DESC LIMIT 1");
        $stmt->execute([$agencyId, $code]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row ? self::decode($row) : null;
    }

    /** Ce code est-il retenu par une demande en cours ? */
    public function codeRequested(string $code): bool
    {
        $stmt = $this->pdo()->prepare("SELECT 1 FROM agency_site_requests WHERE tenant_code = ? AND status IN ('pending', 'building')");
        $stmt->execute([$code]);

        return $stmt->fetchColumn() !== false;
    }

    /** Prend une demande en attente : vrai si c'est bien CET appel qui l'a prise (deux workers ne fabriquent jamais le même site) */
    public function claim(int $id): bool
    {
        $stmt = $this->pdo()->prepare("UPDATE agency_site_requests SET status = 'building', started_at = NOW() WHERE id = ? AND status = 'pending'");
        $stmt->execute([$id]);

        return $stmt->rowCount() === 1;
    }

    /** @return list<int> les demandes en attente (depuis au moins `$minutes` minutes), de la plus ancienne à la plus récente */
    public function pendingIds(int $minutes = 0): array
    {
        $stmt = $this->pdo()->prepare("SELECT id FROM agency_site_requests WHERE status = 'pending' AND requested_at <= NOW() - (? * INTERVAL '1 minute') ORDER BY id");
        $stmt->execute([max(0, $minutes)]);

        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN) ?: []);
    }

    /**
     * La fabrication a abouti : le site est rattaché à l'agence (origine « créé ») et la demande passe à « prêt », dans la même
     * transaction. La place était retenue par la demande : la limite n'est pas recomptée. Vrai si c'est fait ; faux si la demande
     * n'était plus « en création » ou si le site a déjà une agence (rien n'est alors écrit).
     *
     * @param array<string, mixed> $result
     */
    public function complete(int $id, int $agencyId, string $code, ?string $by, array $result): bool
    {
        $pdo = $this->pdo();
        $pdo->beginTransaction();
        try {
            $done = $pdo->prepare("UPDATE agency_site_requests SET status = 'ready', finished_at = NOW(), error_code = NULL, result = ? WHERE id = ? AND status = 'building'");
            $done->execute([json_encode($result, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES), $id]);
            if ($done->rowCount() !== 1) {
                $pdo->rollBack();

                return false;
            }
            $pdo->prepare("INSERT INTO agency_sites (agency_id, tenant_code, origin, attached_by) VALUES (?, ?, 'created', ?)")->execute([$agencyId, $code, $by !== null ? mb_substr($by, 0, 255) : null]);
            $pdo->commit();

            return true;
        } catch (\PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($e->getCode() === '23505') {
                return false;
            }
            throw $e;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** Complète ce que la fabrication a fait (l'invitation, envoyée après que le site est prêt) @param array<string, mixed> $result */
    public function setResult(int $id, array $result): void
    {
        $this->pdo()->prepare('UPDATE agency_site_requests SET result = ? WHERE id = ?')->execute([json_encode($result, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES), $id]);
    }

    /**
     * L'agence désigne une autre personne pour administrer le site : la nouvelle adresse remplace l'ancienne, gardée le temps que le
     * worker retire le compte jamais activé de la première (`replacedAdminEmail`). Le prénom et le nom donnés pour la première ne
     * valent plus.
     */
    public function changeAdmin(int $id, string $email, string $previous): void
    {
        $this->pdo()->prepare("UPDATE agency_site_requests SET admin_email = ?, options = (COALESCE(options, '{}'::jsonb) - 'adminFirstName' - 'adminLastName') || ?::jsonb WHERE id = ?")
            ->execute([$email, json_encode(['replacedAdminEmail' => $previous], \JSON_UNESCAPED_UNICODE), $id]);
    }

    /** Le worker a traité le changement de personne */
    public function clearReplacedAdmin(int $id): void
    {
        $this->pdo()->prepare("UPDATE agency_site_requests SET options = COALESCE(options, '{}'::jsonb) - 'replacedAdminEmail' WHERE id = ?")->execute([$id]);
    }

    /** Note l'instant d'une demande de renvoi d'invitation (un renvoi par site et par délai) */
    public function markInvitationAsked(int $id, \DateTimeImmutable $at): void
    {
        $this->pdo()->prepare("UPDATE agency_site_requests SET result = COALESCE(result, '{}'::jsonb) || ?::jsonb WHERE id = ?")
            ->execute([json_encode(['invitationAskedAt' => $at->setTimezone(new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM)]), $id]);
    }

    /** La demande passe « en échec », avec un code stable ; vrai si elle était en cours @param array<string, mixed>|null $result */
    public function fail(int $id, string $errorCode, ?array $result = null): bool
    {
        $stmt = $this->pdo()->prepare("UPDATE agency_site_requests SET status = 'failed', finished_at = NOW(), error_code = ?, result = ? WHERE id = ? AND status IN ('pending', 'building')");
        $stmt->execute([mb_substr($errorCode, 0, 40), $result !== null ? json_encode($result, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES) : null, $id]);

        return $stmt->rowCount() === 1;
    }

    /**
     * Les demandes « en création » depuis plus de `$minutes` minutes : leur worker a été interrompu. Elles passent « en échec »
     * (`INTERRUPTED`) ; rien n'est supprimé (une base créée à moitié reste, pour que la plateforme la regarde).
     *
     * @return list<array<string, mixed>> les demandes passées en échec
     */
    public function failStale(int $minutes): array
    {
        $stmt = $this->pdo()->prepare("UPDATE agency_site_requests SET status = 'failed', finished_at = NOW(), error_code = 'INTERRUPTED' WHERE status = 'building' AND started_at < NOW() - (? * INTERVAL '1 minute') RETURNING " . self::COLUMNS);
        $stmt->execute([max(1, $minutes)]);

        return array_map(self::decode(...), $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: []);
    }

    /** Prend le verrou de la fabrication, sans attendre ; vrai s'il est pris. Verrou de session de la connexion maître. */
    public function lockBuild(): bool
    {
        $stmt = $this->pdo()->prepare('SELECT pg_try_advisory_lock(hashtext(?))');
        $stmt->execute([self::BUILD_LOCK]);

        return in_array($stmt->fetchColumn(), [true, 't', 'true', 1, '1'], true);
    }

    public function unlockBuild(): void
    {
        try {
            $this->pdo()->prepare('SELECT pg_advisory_unlock(hashtext(?))')->execute([self::BUILD_LOCK]);
        } catch (\Throwable) {
            // connexion perdue : le verrou de session est tombé avec elle
        }
    }

    /** @param array<string, mixed> $row */
    private static function decode(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['agency_id'] = (int) $row['agency_id'];
        foreach (['options', 'result'] as $json) {
            $decoded = is_string($row[$json]) ? json_decode($row[$json], true) : null;
            $row[$json] = is_array($decoded) ? $decoded : [];
        }

        return $row;
    }

    private function pdo(): \PDO
    {
        return $this->tenants->getPdoMaster();
    }
}
