<?php

namespace App\Services\Agency\Assistance;

use App\Services\TenantConnectionManager;

/**
 * Les demandes d'assistance, dans la base MAÎTRE (`assistance_grants`, lot AG-ASSIST). Dates en UTC, sans fuseau.
 */
class AssistanceGrantRepository
{
    private const COLUMNS = 'id, tenant_code, agency_id, section, mode, requested_by_id, requested_by, requested_at, request_expires_at, started_at, ends_at, closed_at, closed_by, closed_reason';

    public function __construct(private readonly TenantConnectionManager $tenants)
    {
    }

    public function pdo(): \PDO
    {
        return $this->tenants->getPdoMaster();
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT ' . self::COLUMNS . ' FROM assistance_grants WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /** @return list<array<string, mixed>> les demandes d'un site, les plus récentes d'abord */
    public function forTenant(string $tenantCode, int $limit = 50): array
    {
        $stmt = $this->pdo()->prepare('SELECT ' . self::COLUMNS . ' FROM assistance_grants WHERE tenant_code = ? ORDER BY id DESC LIMIT ' . max(1, min(200, $limit)));
        $stmt->execute([$tenantCode]);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /** @return list<array<string, mixed>> les demandes NON FERMÉES d'une agence (l'état réel se calcule à la lecture) */
    public function openForAgency(int $agencyId): array
    {
        // seulement les sites que l'agence gère ENCORE
        $stmt = $this->pdo()->prepare('SELECT g.' . str_replace(', ', ', g.', self::COLUMNS) . ' FROM assistance_grants g WHERE g.agency_id = ? AND g.closed_at IS NULL
            AND EXISTS (SELECT 1 FROM agency_sites s WHERE s.agency_id = g.agency_id AND s.tenant_code = g.tenant_code AND s.detached_at IS NULL) ORDER BY g.id DESC LIMIT 200');
        $stmt->execute([$agencyId]);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /** @return list<array<string, mixed>> les demandes non fermées d'une agence sur un site */
    public function openFor(string $tenantCode, int $agencyId): array
    {
        $stmt = $this->pdo()->prepare('SELECT ' . self::COLUMNS . ' FROM assistance_grants WHERE tenant_code = ? AND agency_id = ? AND closed_at IS NULL ORDER BY id');
        $stmt->execute([$tenantCode, $agencyId]);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Ferme d'abord la demande ÉCHUE de cette section (fenêtre finie, ou 24 h sans entrée), puis insère la nouvelle, dans une transaction :
     * l'index unique partiel refuse une seconde demande vivante (PDOException 23505), même simultanée.
     *
     * @return array<string, mixed>
     */
    public function insert(string $tenantCode, int $agencyId, string $section, string $mode, int $byId, string $by, \DateTimeImmutable $now, \DateTimeImmutable $expires): array
    {
        $pdo = $this->pdo();
        $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE assistance_grants SET closed_at = ?, closed_reason = CASE WHEN started_at IS NULL THEN 'expired' ELSE 'ended' END
                WHERE tenant_code = ? AND section = ? AND closed_at IS NULL AND ((started_at IS NULL AND request_expires_at <= ?) OR (ends_at IS NOT NULL AND ends_at <= ?))")
                ->execute([self::ts($now), $tenantCode, $section, self::ts($now), self::ts($now)]);
            // une demande encore ouverte d'une AUTRE agence (le site a changé d'agence) est fermée : elle ne revivra pas
            $pdo->prepare("UPDATE assistance_grants SET closed_at = ?, closed_reason = 'agency' WHERE tenant_code = ? AND section = ? AND closed_at IS NULL AND agency_id <> ?")
                ->execute([self::ts($now), $tenantCode, $section, $agencyId]);
            $stmt = $pdo->prepare('INSERT INTO assistance_grants (tenant_code, agency_id, section, mode, requested_by_id, requested_by, requested_at, request_expires_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?) RETURNING ' . self::COLUMNS);
            $stmt->execute([$tenantCode, $agencyId, $section, $mode, $byId, mb_substr($by, 0, 180), self::ts($now), self::ts($expires)]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();

            throw $e;
        }

        return $row;
    }

    /** La fenêtre part de la PREMIÈRE requête de l'opérateur : écrite une seule fois (rend faux si une autre requête l'a déjà posée, ou si la demande a échu) */
    public function start(int $id, \DateTimeImmutable $now, \DateTimeImmutable $ends): bool
    {
        $stmt = $this->pdo()->prepare('UPDATE assistance_grants SET started_at = ?, ends_at = ? WHERE id = ? AND started_at IS NULL AND closed_at IS NULL AND request_expires_at > ?');
        $stmt->execute([self::ts($now), self::ts($ends), $id, self::ts($now)]);

        return $stmt->rowCount() === 1;
    }

    public function close(int $id, string $tenantCode, string $reason, ?string $by, \DateTimeImmutable $now): bool
    {
        $stmt = $this->pdo()->prepare('UPDATE assistance_grants SET closed_at = ?, closed_reason = ?, closed_by = ? WHERE id = ? AND tenant_code = ? AND closed_at IS NULL');
        $stmt->execute([self::ts($now), $reason, $by !== null ? mb_substr($by, 0, 180) : null, $id, $tenantCode]);

        return $stmt->rowCount() === 1;
    }

    private static function ts(\DateTimeImmutable $at): string
    {
        return $at->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
