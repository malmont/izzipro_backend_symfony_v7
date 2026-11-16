<?php

namespace App\Services\Entry;

use App\Services\TenantConnectionManager;

/**
 * Le JOURNAL DES ENTRÉES dans le site d'un autre (lot AG-3b, 16/11/2026, `docs/ecrans-et-contextes.md` § 7), dans la base maître
 * (table `site_entries`, `ops/sql/agency_master.sql`). Une ligne par jeton d'entrée remis ; la garde relit la ligne de l'entrée à
 * chaque requête (par `EntryService`, avec un cache d'une minute au plus).
 */
class EntryRepository
{
    private const COLUMNS = 'id, mode, home_tenant_code, target_tenant_code, account_id, account_email, first_name, agency_id, agency_name, created_at, expires_at, ended_at, ended_reason';

    public function __construct(private readonly TenantConnectionManager $tenants)
    {
    }

    /** Ouvre une entrée ; rend son numéro */
    public function open(string $mode, string $home, string $target, int $accountId, string $accountEmail, string $firstName, ?int $agencyId, ?string $agencyName, \DateTimeImmutable $expiresAt): int
    {
        $stmt = $this->pdo()->prepare('INSERT INTO site_entries (mode, home_tenant_code, target_tenant_code, account_id, account_email, first_name, agency_id, agency_name, created_at, expires_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?) RETURNING id');
        $stmt->execute([$mode, $home, $target, $accountId, mb_substr($accountEmail, 0, 180), mb_substr($firstName, 0, 100), $agencyId, $agencyName !== null ? mb_substr($agencyName, 0, 160) : null,
            self::utc(new \DateTimeImmutable()), self::utc($expiresAt)]);

        return (int) $stmt->fetchColumn();
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT ' . self::COLUMNS . ' FROM site_entries WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row ? self::decode($row) : null;
    }

    /** Ferme une entrée encore ouverte ; rend vrai si elle l'était */
    public function end(int $id, string $reason): bool
    {
        $stmt = $this->pdo()->prepare('UPDATE site_entries SET ended_at = ?, ended_reason = ? WHERE id = ? AND ended_at IS NULL');
        $stmt->execute([self::utc(new \DateTimeImmutable()), mb_substr($reason, 0, 30), $id]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Ferme toutes les entrées encore ouvertes d'un compte de ce site d'attache (déconnexion) ; rend leurs numéros.
     *
     * @return list<int>
     */
    public function endFrom(string $home, int $accountId, string $reason): array
    {
        $stmt = $this->pdo()->prepare('UPDATE site_entries SET ended_at = ?, ended_reason = ? WHERE home_tenant_code = ? AND account_id = ? AND ended_at IS NULL RETURNING id');
        $stmt->execute([self::utc(new \DateTimeImmutable()), mb_substr($reason, 0, 30), $home, $accountId]);

        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN) ?: []);
    }

    /**
     * Les dernières entrées dans ce site, de la plus récente à la plus ancienne.
     *
     * @return list<array<string, mixed>>
     */
    public function recentInto(string $target, int $limit = 100): array
    {
        $stmt = $this->pdo()->prepare('SELECT ' . self::COLUMNS . ' FROM site_entries WHERE target_tenant_code = ? ORDER BY id DESC LIMIT ' . max(1, min(500, $limit)));
        $stmt->execute([$target]);

        return array_map(self::decode(...), $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: []);
    }

    /** @param array<string, mixed> $row */
    private static function decode(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['account_id'] = (int) $row['account_id'];
        $row['agency_id'] = $row['agency_id'] !== null ? (int) $row['agency_id'] : null;

        return $row;
    }

    private static function utc(\DateTimeInterface $date): string
    {
        return \DateTimeImmutable::createFromInterface($date)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private function pdo(): \PDO
    {
        return $this->tenants->getPdoMaster();
    }
}
