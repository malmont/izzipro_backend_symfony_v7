<?php

namespace App\Services\Agency;

use App\Services\TenantConnectionManager;

/**
 * Agences, base maître (tables `agencies`, `agency_sites`, `agency_plans`, `agency_events`), PDO (lot AG-1, 15/11/2026,
 * `docs/agences-et-import.md` § 3). Jamais l'ORM ni une base de site. La connexion maître est partagée par tout le processus : une
 * transaction ouverte ici est toujours refermée, même en cas d'erreur.
 */
class AgencyRepository
{
    public const STATUSES = ['trial', 'active', 'suspended', 'closed'];
    private const COLUMNS = 'id, code, name, home_tenant_code, status, trial_ends_at, max_sites, plan_code, currency, contact_email, is_test, suspended_at, created_at, created_by, updated_at, daily_site_requests';

    public function __construct(private readonly TenantConnectionManager $tenants)
    {
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        return array_map(self::decode(...), $this->pdo()->query('SELECT ' . self::COLUMNS . ' FROM agencies ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC) ?: []);
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->one('SELECT ' . self::COLUMNS . ' FROM agencies WHERE id = ?', [$id]);
    }

    /** @return array<string, mixed>|null */
    public function findByCode(string $code): ?array
    {
        return $this->one('SELECT ' . self::COLUMNS . ' FROM agencies WHERE code = ?', [$code]);
    }

    /** L'agence dont ce site est le site d'attache, ou null */
    public function findByHome(string $tenantCode): ?array
    {
        return $this->one('SELECT ' . self::COLUMNS . ' FROM agencies WHERE home_tenant_code = ?', [$tenantCode]);
    }

    /** L'agence qui gère ce site aujourd'hui, ou null */
    public function managing(string $tenantCode): ?array
    {
        return $this->one('SELECT ' . self::COLUMNS . ' FROM agencies WHERE id = (SELECT agency_id FROM agency_sites WHERE tenant_code = ? AND detached_at IS NULL)', [$tenantCode]);
    }

    /**
     * Crée une agence. Sous un verrou du site (le même que le rattachement) : « un site d'attache n'est jamais un site géré » ne tient
     * à aucune contrainte de la base, il se vérifie donc ICI, à l'abri d'un rattachement fait au même instant. Rend l'agence créée, ou
     * null si le site est géré par une agence. Une violation d'unicité (code, site d'attache) remonte en `PDOException` 23505.
     *
     * @return array<string, mixed>|null
     */
    public function insert(string $code, string $name, string $homeTenantCode, \DateTimeImmutable $trialEndsAt, ?int $maxSites, ?string $planCode, ?string $contactEmail, bool $isTest, ?string $by): ?array
    {
        $pdo = $this->pdo();
        $pdo->beginTransaction();
        try {
            $this->lockSite($pdo, $homeTenantCode);
            $managed = $pdo->prepare('SELECT 1 FROM agency_sites WHERE tenant_code = ? AND detached_at IS NULL');
            $managed->execute([$homeTenantCode]);
            if ($managed->fetchColumn() !== false) {
                $pdo->rollBack();

                return null;
            }
            $stmt = $pdo->prepare('INSERT INTO agencies (code, name, home_tenant_code, status, trial_ends_at, max_sites, plan_code, contact_email, is_test, created_by) VALUES (?, ?, ?, \'trial\', ?, ?, ?, ?, ?, ?) RETURNING id');
            $stmt->execute([$code, $name, $homeTenantCode, self::utc($trialEndsAt), $maxSites, $planCode, $contactEmail, $isTest ? 'true' : 'false', $by !== null ? mb_substr($by, 0, 255) : null]);
            $id = (int) $stmt->fetchColumn();
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return $this->find($id) ?? throw new \RuntimeException('agence non relue');
    }

    /** Verrou d'un site, tenu jusqu'à la fin de la transaction : création d'une agence et rattachement d'un site ne se croisent pas */
    private function lockSite(\PDO $pdo, string $tenantCode): void
    {
        $lock = $pdo->prepare('SELECT pg_advisory_xact_lock(hashtext(?))');
        $lock->execute(['agency-site:' . $tenantCode]);
    }

    /** Passe une agence d'un état à un autre, seulement si elle est dans l'un des états attendus ; vrai si le pas a été fait */
    public function move(int $id, array $from, string $to): bool
    {
        $marks = implode(',', array_fill(0, count($from), '?'));
        $stmt = $this->pdo()->prepare("UPDATE agencies SET status = ?, suspended_at = CASE WHEN ? = 'suspended' THEN NOW() ELSE NULL END, updated_at = NOW() WHERE id = ? AND status IN ($marks)");
        $stmt->execute([$to, $to, $id, ...$from]);

        return $stmt->rowCount() === 1;
    }

    /** @return list<array<string, mixed>> les sites gérés aujourd'hui par l'agence, du plus ancien au plus récent */
    public function sites(int $agencyId): array
    {
        $stmt = $this->pdo()->prepare('SELECT id, tenant_code, origin, attached_at, attached_by FROM agency_sites WHERE agency_id = ? AND detached_at IS NULL ORDER BY id');
        $stmt->execute([$agencyId]);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Rattache un site à une agence, sous verrou de l'agence : la limite de sites est comptée et le site écrit dans la même
     * transaction. Rend `attached`, `limit` (la limite est atteinte) ou `taken` (le site a déjà une agence : index unique).
     * `$maxSites` vide : pas de limite. Les demandes de création en cours (lot AG-2) comptent : leur place est déjà retenue.
     */
    public function attach(int $agencyId, string $tenantCode, string $origin, ?int $maxSites, ?string $by): string
    {
        $pdo = $this->pdo();
        $pdo->beginTransaction();
        try {
            $lock = $pdo->prepare('SELECT id FROM agencies WHERE id = ? FOR UPDATE');
            $lock->execute([$agencyId]);
            // sous le verrou du site : il n'est pas devenu, au même instant, le site d'attache d'une agence
            $this->lockSite($pdo, $tenantCode);
            $home = $pdo->prepare('SELECT 1 FROM agencies WHERE home_tenant_code = ?');
            $home->execute([$tenantCode]);
            if ($home->fetchColumn() !== false) {
                $pdo->rollBack();

                return 'taken';
            }
            if ($maxSites !== null && $this->used($pdo, $agencyId) >= $maxSites) {
                $pdo->rollBack();

                return 'limit';
            }
            $pdo->exec('SAVEPOINT agency_attach');
            try {
                $stmt = $pdo->prepare('INSERT INTO agency_sites (agency_id, tenant_code, origin, attached_by) VALUES (?, ?, ?, ?)');
                $stmt->execute([$agencyId, $tenantCode, $origin, $by !== null ? mb_substr($by, 0, 255) : null]);
            } catch (\PDOException $e) {
                if ($e->getCode() !== '23505') {
                    throw $e;
                }
                $pdo->exec('ROLLBACK TO SAVEPOINT agency_attach');
                $pdo->rollBack();

                return 'taken';
            }
            $pdo->commit();

            return 'attached';
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** Places prises : les sites gérés aujourd'hui, plus les demandes de création en cours (lot AG-2) */
    public function used(\PDO $pdo, int $agencyId): int
    {
        $count = $pdo->prepare("SELECT (SELECT COUNT(*) FROM agency_sites WHERE agency_id = ? AND detached_at IS NULL) + (SELECT COUNT(*) FROM agency_site_requests WHERE agency_id = ? AND status IN ('pending', 'building'))");
        $count->execute([$agencyId, $agencyId]);

        return (int) $count->fetchColumn();
    }

    /** Le site géré aujourd'hui par cette agence, ou null : { tenant_code, origin, attached_at } */
    public function site(int $agencyId, string $tenantCode): ?array
    {
        return $this->one('SELECT tenant_code, origin, attached_at FROM agency_sites WHERE agency_id = ? AND tenant_code = ? AND detached_at IS NULL', [$agencyId, $tenantCode], false);
    }

    /** Limites d'une agence (lot AG-2) : nombre de sites (vide = illimité), demandes de création par 24 heures */
    public function setLimits(int $id, ?int $maxSites, int $dailySiteRequests): void
    {
        $this->pdo()->prepare('UPDATE agencies SET max_sites = ?, daily_site_requests = ?, updated_at = NOW() WHERE id = ?')->execute([$maxSites, $dailySiteRequests, $id]);
    }

    /** Détache un site de son agence ; vrai si c'est bien ce pas qui a été fait */
    public function detach(int $agencyId, string $tenantCode, ?string $by): bool
    {
        $stmt = $this->pdo()->prepare('UPDATE agency_sites SET detached_at = NOW(), detached_by = ? WHERE agency_id = ? AND tenant_code = ? AND detached_at IS NULL');
        $stmt->execute([$by !== null ? mb_substr($by, 0, 255) : null, $agencyId, $tenantCode]);

        return $stmt->rowCount() === 1;
    }

    /**
     * Un AUTRE code de site, sur la même base, est-il déjà le site d'attache d'une agence ouverte ou un site géré ? Deux codes peuvent
     * désigner une seule base (les mêmes comptes) : elle ne se confie pas deux fois.
     */
    public function sharesDatabase(string $tenantCode, string $dbname): bool
    {
        $stmt = $this->pdo()->prepare(
            "SELECT 1 FROM tenants t WHERE t.dbname = ? AND t.code <> ? AND (t.code IN (SELECT tenant_code FROM agency_sites WHERE detached_at IS NULL)
             OR t.code IN (SELECT home_tenant_code FROM agencies WHERE status <> 'closed')) LIMIT 1"
        );
        $stmt->execute([$dbname, $tenantCode]);

        return $stmt->fetchColumn() !== false;
    }

    /** Cette base est-elle celle d'un des sites nommés ? (un second code posé sur la base d'un site réservé) @param list<string> $codes */
    public function onDatabaseOf(string $dbname, array $codes): bool
    {
        if ($codes === []) {
            return false;
        }
        $stmt = $this->pdo()->prepare('SELECT 1 FROM tenants WHERE dbname = ? AND code IN (' . implode(',', array_fill(0, count($codes), '?')) . ') LIMIT 1');
        $stmt->execute([$dbname, ...$codes]);

        return $stmt->fetchColumn() !== false;
    }

    /** @return array{code: string, label: string, trial_days: int, max_sites: ?int}|null le forfait commun de ce code, s'il est actif (max_sites vide : illimité) */
    public function commonPlan(string $code): ?array
    {
        $row = $this->one('SELECT code, label, trial_days, max_sites FROM agency_plans WHERE code = ? AND agency_id IS NULL AND is_active', [$code], false);

        return $row === null ? null : ['code' => (string) $row['code'], 'label' => (string) $row['label'], 'trial_days' => (int) $row['trial_days'], 'max_sites' => $row['max_sites'] !== null ? (int) $row['max_sites'] : null];
    }

    /** @param array<string, mixed>|null $payload */
    public function recordEvent(int $agencyId, string $event, ?string $tenantCode, ?array $payload, ?string $actor): void
    {
        $stmt = $this->pdo()->prepare('INSERT INTO agency_events (agency_id, tenant_code, event, payload, actor) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$agencyId, $tenantCode, mb_substr($event, 0, 40), $payload !== null ? json_encode($payload, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES) : null, $actor !== null ? mb_substr($actor, 0, 255) : null]);
    }

    /** @return list<array<string, mixed>> le journal de l'agence, du plus récent au plus ancien */
    public function events(int $agencyId, int $limit = 100): array
    {
        $stmt = $this->pdo()->prepare('SELECT id, tenant_code, event, payload, actor, created_at FROM agency_events WHERE agency_id = ? ORDER BY id DESC LIMIT ' . max(1, min(500, $limit)));
        $stmt->execute([$agencyId]);

        return array_map(static function (array $row): array {
            $row['payload'] = is_string($row['payload']) ? json_decode($row['payload'], true) : null;

            return $row;
        }, $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: []);
    }

    /** @return array{code: string, name: string, dbname: string}|null la ligne du site dans la base maître, lue sans cache */
    public function tenant(string $code): ?array
    {
        $row = $this->one('SELECT code, name, dbname FROM tenants WHERE code = ?', [$code], false);

        return $row === null ? null : ['code' => (string) $row['code'], 'name' => (string) $row['name'], 'dbname' => (string) $row['dbname']];
    }

    /** Date telle que la base maître la garde : en UTC, sans décalage */
    private static function utc(\DateTimeInterface $date): string
    {
        return \DateTimeImmutable::createFromInterface($date)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /** @return array<string, mixed>|null */
    private function one(string $sql, array $params, bool $decode = true): ?array
    {
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row ? ($decode ? self::decode($row) : $row) : null;
    }

    /** @param array<string, mixed> $row */
    private static function decode(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['max_sites'] = $row['max_sites'] !== null ? (int) $row['max_sites'] : null; // vide : illimité (lot AG-2)
        $row['daily_site_requests'] = (int) ($row['daily_site_requests'] ?? 5);
        $row['is_test'] = in_array($row['is_test'], [true, 't', 'true', 1, '1'], true);

        return $row;
    }

    public function pdo(): \PDO
    {
        return $this->tenants->getPdoMaster();
    }
}
