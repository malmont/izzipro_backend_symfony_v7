<?php

namespace App\Services\Platform\Sites;

use App\Services\TenantConnectionManager;

/**
 * Les demandes de création d'un site avec son type (lot TYPE-1, 16/11/2026, `docs/agences-et-import.md` § 9), dans la base MAÎTRE
 * (`platform_site_requests`, `ops/sql/platform_sites_master.sql`). Une demande est écrite AVANT sa première étape, avec ses étapes et
 * l'état de chacune ; chaque pas d'état est fait par une instruction qui vérifie l'état attendu : deux gestes simultanés ne font pas
 * deux fois le même pas.
 */
class PlatformSiteRequestRepository
{
    public const PENDING = 'pending';
    public const BUILDING = 'building';
    public const READY = 'ready';
    /** Une étape a échoué APRÈS la création du site : la demande est « à compléter » */
    public const INCOMPLETE = 'incomplete';
    /** Échec avant que le site existe : rien n'a été créé */
    public const FAILED = 'failed';
    public const ABANDONED = 'abandoned';
    /** États où une demande retient son code de site */
    public const LIVE = [self::PENDING, self::BUILDING, self::INCOMPLETE];
    /** Les étapes, dans l'ordre où elles se font (la base range les clés d'un JSON à sa façon : l'ordre est rendu à la lecture) */
    public const STEPS = ['site', 'invitation', 'agency', 'manager'];
    private const COLUMNS = 'id, type, tenant_code, name, admin_email, options, steps, status, failed_step, error_code, agency_id, result, requested_by, requested_at, started_at, finished_at';

    public function __construct(private readonly TenantConnectionManager $tenants)
    {
    }

    /**
     * Enregistre une demande, toutes ses étapes « à faire ». Rend la demande, ou null si ce code est déjà celui d'une demande vivante
     * (index unique : deux demandes au même instant, la base n'en garde qu'une).
     *
     * @param array<string, mixed> $options
     * @param array<string, string> $steps étape => état
     * @return array<string, mixed>|null
     */
    public function open(string $type, string $code, string $name, string $adminEmail, array $options, array $steps, ?string $by): ?array
    {
        $stmt = $this->pdo()->prepare('INSERT INTO platform_site_requests (type, tenant_code, name, admin_email, options, steps, requested_by) VALUES (?, ?, ?, ?, ?, ?, ?)'
            . ' ON CONFLICT (tenant_code) WHERE status IN (\'pending\', \'building\', \'incomplete\') DO NOTHING RETURNING id');
        $stmt->execute([$type, $code, mb_substr($name, 0, 160), mb_substr($adminEmail, 0, 180), self::json($options), self::json($steps), $by !== null ? mb_substr($by, 0, 255) : null]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : $this->find((int) $id);
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT ' . self::COLUMNS . ' FROM platform_site_requests WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row ? self::decode($row) : null;
    }

    /** @return list<array<string, mixed>> les demandes, de la plus récente à la plus ancienne */
    public function latest(int $limit = 100): array
    {
        $rows = $this->pdo()->query('SELECT ' . self::COLUMNS . ' FROM platform_site_requests ORDER BY id DESC LIMIT ' . max(1, min(500, $limit)))->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        return array_map(self::decode(...), $rows);
    }

    /** Ce code est-il celui d'une demande vivante ? */
    public function codeRequested(string $code): bool
    {
        $stmt = $this->pdo()->prepare("SELECT 1 FROM platform_site_requests WHERE tenant_code = ? AND status IN ('pending', 'building', 'incomplete') LIMIT 1");
        $stmt->execute([$code]);

        return $stmt->fetchColumn() !== false;
    }

    /** @return array<string, int> code du site => identifiant de sa demande « à compléter » */
    public function incompleteByCode(): array
    {
        $out = [];
        foreach ($this->pdo()->query("SELECT tenant_code, id FROM platform_site_requests WHERE status = 'incomplete'")->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $row) {
            $out[(string) $row['tenant_code']] = (int) $row['id'];
        }

        return $out;
    }

    /** @return list<array<string, mixed>> les demandes faites dont l'acceptation est NOTÉE et dont le rôle de responsable n'est pas encore donné */
    public function acceptedWaiting(): array
    {
        $rows = $this->pdo()->query('SELECT ' . self::COLUMNS . " FROM platform_site_requests WHERE status = 'ready' AND steps->>'manager' = 'waiting' AND result->>'acceptedUserId' IS NOT NULL ORDER BY id")->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        return array_map(self::decode(...), $rows);
    }

    /** @return list<int> les demandes en attente (depuis au moins `$minutes` minutes), de la plus ancienne à la plus récente */
    public function pendingIds(int $minutes = 0): array
    {
        $stmt = $this->pdo()->prepare("SELECT id FROM platform_site_requests WHERE status = 'pending' AND requested_at <= NOW() - (? * INTERVAL '1 minute') ORDER BY id");
        $stmt->execute([max(0, $minutes)]);

        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN) ?: []);
    }

    /** @return list<array<string, mixed>> les demandes restées « en cours » depuis plus de `$minutes` minutes : leur worker est tombé */
    public function staleBuilding(int $minutes): array
    {
        $stmt = $this->pdo()->prepare('SELECT ' . self::COLUMNS . " FROM platform_site_requests WHERE status = 'building' AND started_at <= NOW() - (? * INTERVAL '1 minute') ORDER BY id");
        $stmt->execute([max(1, $minutes)]);

        return array_map(self::decode(...), $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * L'administrateur désigné d'une demande de type agence vient d'accepter son invitation : le compte qui a accepté est NOTÉ sur la
     * demande (son identifiant, jamais son adresse). Seule une acceptation notée ici donne le rôle de responsable. Rend les demandes
     * touchées (une demande abandonnée ou en échec n'est pas relue).
     *
     * @return list<array<string, mixed>>
     */
    public function noteAcceptance(string $code, string $email, int $userId): array
    {
        $stmt = $this->pdo()->prepare("UPDATE platform_site_requests SET result = jsonb_set(COALESCE(result, '{}'::jsonb), '{acceptedUserId}', to_jsonb(?::int))"
            . " WHERE tenant_code = ? AND lower(admin_email) = lower(?) AND type = 'agency' AND status IN ('pending', 'building', 'ready', 'incomplete')"
            . " AND steps->>'manager' IN ('todo', 'waiting', 'failed') RETURNING id");
        $stmt->execute([$userId, $code, $email]);

        return array_values(array_filter(array_map(fn (mixed $id): ?array => $this->find((int) $id), $stmt->fetchAll(\PDO::FETCH_COLUMN) ?: [])));
    }

    /** « Ne plus attendre » : le rôle de responsable d'une demande faite n'attend plus l'acceptation. Rend vrai si quelque chose a changé. */
    public function stopWaiting(int $id): bool
    {
        $stmt = $this->pdo()->prepare("UPDATE platform_site_requests SET steps = jsonb_set(steps, '{manager}', '\"skipped\"') WHERE id = ? AND status = 'ready' AND steps->>'manager' = 'waiting'");
        $stmt->execute([$id]);

        return $stmt->rowCount() === 1;
    }

    /** L'ancienne adresse d'un administrateur remplacé n'a plus à être gardée : son invitation restée sans suite a été retirée */
    public function clearReplacedAdmin(int $id): void
    {
        $this->pdo()->prepare("UPDATE platform_site_requests SET options = options - 'replacedAdminEmail' WHERE id = ?")->execute([$id]);
    }

    /**
     * Passe une demande d'un état à un autre, seulement si elle est dans un des états attendus. Rend vrai si le pas a été fait.
     *
     * @param list<string> $from
     * @param array<string, mixed> $set colonnes à écrire en plus : steps, failed_step, error_code, agency_id, result, started (maintenant), finished (maintenant)
     */
    public function move(int $id, array $from, string $to, array $set = []): bool
    {
        $sql = 'UPDATE platform_site_requests SET status = ?';
        $params = [$to];
        foreach ($set as $column => $value) {
            if (in_array($column, ['started', 'finished'], true)) {
                $sql .= ", {$column}_at = " . ($value ? 'NOW()' : 'NULL');
                continue;
            }
            if (!in_array($column, ['steps', 'failed_step', 'error_code', 'agency_id', 'result'], true)) {
                throw new \InvalidArgumentException("Colonne de demande inconnue : $column");
            }
            // le résultat est FONDU dans celui de la base, jamais remplacé : une acceptation notée entre-temps n'est pas effacée
            $sql .= $column === 'result' ? ", result = COALESCE(result, '{}'::jsonb) || ?::jsonb" : ", $column = ?";
            $params[] = is_array($value) ? self::json($value) : $value;
        }
        $stmt = $this->pdo()->prepare($sql . ' WHERE id = ? AND status IN (' . implode(',', array_fill(0, count($from), '?')) . ')');
        $stmt->execute([...$params, $id, ...$from]);

        return $stmt->rowCount() === 1;
    }

    /**
     * Désigne une autre adresse pour l'administrateur d'une demande À COMPLÉTER dont l'invitation n'est pas partie, en une instruction
     * (deux reprises simultanées n'en laissent passer qu'une). L'ancienne est gardée dans les options, le temps de retirer son
     * invitation. Rend vrai si l'adresse a été changée.
     */
    public function changeAdmin(int $id, string $email, string $previous): bool
    {
        // l'acceptation notée était celle de l'ancienne adresse : elle ne vaut pas pour la nouvelle
        $stmt = $this->pdo()->prepare("UPDATE platform_site_requests SET admin_email = ?, result = COALESCE(result, '{}'::jsonb) - 'acceptedUserId', options = jsonb_set(COALESCE(options, '{}'::jsonb), '{replacedAdminEmail}', to_jsonb(?::text))"
            . " WHERE id = ? AND status = 'incomplete' AND admin_email = ? AND steps->>'invitation' <> 'done'");
        $stmt->execute([mb_substr($email, 0, 180), $previous, $id, $previous]);

        return $stmt->rowCount() === 1;
    }

    /**
     * Écrit l'état des étapes (et ce que la fabrication a fait) d'une demande, sans changer son état. Le résultat est FONDU dans celui
     * de la base, jamais remplacé (une acceptation notée entre-temps n'est pas effacée). @param array<string, string> $steps
     */
    public function saveSteps(int $id, array $steps, ?array $result = null, ?int $agencyId = null): void
    {
        $this->pdo()->prepare("UPDATE platform_site_requests SET steps = ?, result = COALESCE(result, '{}'::jsonb) || COALESCE(?::jsonb, '{}'::jsonb), agency_id = COALESCE(?, agency_id) WHERE id = ?")
            ->execute([self::json($steps), $result !== null && $result !== [] ? self::json($result) : null, $agencyId, $id]);
    }

    /** @param array<string, mixed> $value */
    private static function json(array $value): string
    {
        return json_encode($value, \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_THROW_ON_ERROR);
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private static function decode(array $row): array
    {
        foreach (['options', 'steps', 'result'] as $column) {
            $row[$column] = is_string($row[$column] ?? null) ? (json_decode($row[$column], true) ?: []) : [];
        }
        $row['steps'] = array_replace(array_intersect_key(array_flip(self::STEPS), $row['steps']), $row['steps']);
        $row['id'] = (int) $row['id'];
        $row['agency_id'] = $row['agency_id'] !== null ? (int) $row['agency_id'] : null;

        return $row;
    }

    private function pdo(): \PDO
    {
        return $this->tenants->getPdoMaster();
    }
}
