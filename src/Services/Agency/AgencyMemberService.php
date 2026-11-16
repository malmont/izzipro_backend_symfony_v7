<?php

namespace App\Services\Agency;

use App\Security\AgencyRoles;
use App\Services\Platform\TenantDatabaseConnector;
use App\UseCase\PlatformUseCase\PlatformValidationException;

/**
 * Rôles d'agence (lot AG-1, `docs/agences-et-import.md` § 3.3) : « responsable » et « collaborateur », portés par des comptes du SITE
 * D'ATTACHE de l'agence. Donnés et retirés par le super administrateur seulement (routes de la plateforme et page d'administration) ;
 * chaque changement est écrit au journal de l'agence (`agency_events`).
 *
 * Le super administrateur n'est pas forcément connecté sur le site d'attache : les comptes sont lus et écrits par une connexion
 * directe à la base de ce site (`TenantDatabaseConnector`), sans jamais changer le site de la requête en cours.
 */
class AgencyMemberService
{
    public const EVENT_GRANTED = 'role_granted';
    public const EVENT_REVOKED = 'role_revoked';
    /** Rôles qui donnent un accès : un compte qui n'en porte plus aucun n'a plus de session à garder */
    private const ACCESS_ROLES = ['ROLE_ADMIN', 'ROLE_SUPER_ADMIN', 'ROLE_USER_INTERNET', 'ROLE_USER_POS', 'ROLE_POS_MANAGER', 'ROLE_SUPPORT', 'ROLE_COMPANY', 'ROLE_CONSULTANT', ...AgencyRoles::ALL];

    public function __construct(private readonly AgencyRepository $repository, private readonly TenantDatabaseConnector $connector)
    {
    }

    /**
     * @param array<string, mixed> $agency
     * @return list<array{userId: int, email: string, name: string, role: string}> les comptes du site d'attache qui portent un rôle d'agence
     */
    public function members(array $agency): array
    {
        $home = $this->repository->tenant((string) $agency['home_tenant_code']);
        if ($home === null) {
            return [];
        }
        $stmt = $this->connector->pdo($home['dbname'])->query('SELECT id, email, firstname, lastname, roles FROM "user" WHERE roles::text LIKE \'%"ROLE_AGENCY%\' AND deleted_at IS NULL ORDER BY id');
        $out = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $row) {
            $role = AgencyRoles::nameOf((array) json_decode((string) $row['roles'], true));
            if ($role !== null) {
                $out[] = ['userId' => (int) $row['id'], 'email' => (string) $row['email'], 'name' => trim($row['firstname'] . ' ' . $row['lastname']), 'role' => $role];
            }
        }

        return $out;
    }

    /**
     * Donne (`manager`, `member`) ou retire (`none`) le rôle d'agence d'un compte du site d'attache, désigné par son adresse. Les
     * autres rôles du compte ne bougent pas. Sans changement, rien n'est écrit. Avec `$userId`, seul CE compte peut le recevoir.
     *
     * @param array<string, mixed> $agency
     * @return array{userId: int, email: string, role: ?string, changed: bool}
     */
    public function set(array $agency, mixed $email, mixed $role, ?string $by, ?int $userId = null): array
    {
        $errors = [];
        if (!is_string($email) || filter_var(trim($email), \FILTER_VALIDATE_EMAIL) === false) {
            $errors[] = ['path' => 'email', 'message' => 'adresse courriel d\'un compte du site d\'attache attendue'];
        }
        if (!is_string($role) || ($role !== 'none' && !isset(AgencyRoles::NAMES[$role]))) {
            $errors[] = ['path' => 'role', 'message' => 'manager, member ou none attendu'];
        }
        if ($errors !== []) {
            throw new PlatformValidationException($errors);
        }
        if ($agency['status'] === 'closed') {
            throw new AgencyException('AGENCY_NOT_OPEN', 409, ['status' => $agency['status']]);
        }
        $home = $this->repository->tenant((string) $agency['home_tenant_code']) ?? throw new AgencyException('AGENCY_NOT_FOUND', 404);
        $pdo = $this->connector->pdo($home['dbname']);
        $pdo->beginTransaction();
        try {
            // `$userId` (lot TYPE-1) : le compte est désigné par son identifiant ET son adresse. Deux comptes d'un site peuvent porter la
            // même adresse à la casse près : sans l'identifiant, le rôle irait au plus ancien, pas forcément à celui qu'on veut.
            $find = $pdo->prepare('SELECT id, email, roles FROM "user" WHERE lower(email) = lower(?) AND deleted_at IS NULL' . ($userId !== null ? ' AND id = ?' : '') . ' ORDER BY id LIMIT 1 FOR UPDATE');
            $find->execute($userId !== null ? [trim($email), $userId] : [trim($email)]);
            $user = $find->fetch(\PDO::FETCH_ASSOC);
            if (!$user) {
                $pdo->rollBack();
                throw new AgencyException('AGENCY_MEMBER_NOT_FOUND', 404);
            }
            $before = array_values((array) json_decode((string) $user['roles'], true));
            $after = array_values(array_diff($before, AgencyRoles::ALL)); // un compte ne porte qu'un rôle d'agence à la fois
            if ($role !== 'none') {
                $after[] = AgencyRoles::NAMES[$role];
            }
            [$sortedBefore, $sortedAfter] = [$before, $after];
            sort($sortedBefore);
            sort($sortedAfter);
            $changed = $sortedBefore !== $sortedAfter; // les mêmes rôles dans un autre ordre : aucun changement
            if ($changed) {
                $update = $pdo->prepare('UPDATE "user" SET roles = ? WHERE id = ?');
                $update->execute([json_encode($after), (int) $user['id']]);
                // Le compte ne porte plus aucun rôle d'accès : ses sessions sont closes (jetons de renouvellement retirés), comme pour un
                // agent d'assistance révoqué. Sans cela, la session du web se renouvellerait sans fin et, n'étant plus « agence seul »,
                // le compte atteindrait les routes qui ne demandent qu'un compte connecté. Le jeton d'accès restant vit 15 minutes au plus.
                if (array_intersect($after, self::ACCESS_ROLES) === []) {
                    $close = $pdo->prepare('DELETE FROM refresh_tokens WHERE username = ?');
                    $close->execute([(string) $user['email']]);
                }
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        if ($changed) {
            $was = AgencyRoles::nameOf($before);
            // l'identifiant du compte, pas son adresse : la base maître ne garde pas de donnée personnelle des sites
            $this->repository->recordEvent($agency['id'], $role === 'none' ? self::EVENT_REVOKED : self::EVENT_GRANTED, $home['code'], ['userId' => (int) $user['id'], 'role' => $role === 'none' ? null : $role, 'was' => $was], $by);
            $this->connector->invalidateSiteCache($home['code'], $home['dbname']);
        }

        return ['userId' => (int) $user['id'], 'email' => (string) $user['email'], 'role' => $role === 'none' ? null : $role, 'changed' => $changed];
    }
}
