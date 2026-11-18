<?php

namespace App\Services\Group;

use App\Services\TenantConnectionManager;

/** Les groupes de boutiques dans la base MAÎTRE (lot G-1a, `ops/sql/groups_master.sql`) */
class GroupRepository
{
    public function __construct(private readonly TenantConnectionManager $tenants)
    {
    }

    /** @return array<string, mixed>|null le groupe ACTIF où se trouve ce site (siège ou boutique) */
    public function ofSite(string $code): ?array
    {
        return $this->one("SELECT g.id, g.code, g.name, g.head_tenant_code, g.status, g.max_shops, s.origin FROM shop_groups g JOIN shop_group_sites s ON s.group_id = g.id AND s.left_at IS NULL
            WHERE s.tenant_code = ? AND g.status = 'active'", [$code]);
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->one('SELECT id, code, name, head_tenant_code, status, max_shops FROM shop_groups WHERE id = ?', [$id]);
    }

    /** Lot G-1b : ce site est-il une boutique ouverte de ce groupe ? */
    public function isOpenShop(int $groupId, string $code): bool
    {
        return $this->one('SELECT 1 AS ok FROM shop_group_sites WHERE group_id = ? AND tenant_code = ? AND left_at IS NULL', [$groupId, $code]) !== null;
    }

    /** @return list<array<string, mixed>> les boutiques du groupe, le siège d'abord */
    public function shops(int $groupId): array
    {
        $stmt = $this->pdo()->prepare("SELECT s.tenant_code, s.origin, s.joined_at, t.name FROM shop_group_sites s LEFT JOIN tenants t ON t.code = s.tenant_code
            WHERE s.group_id = ? AND s.left_at IS NULL ORDER BY (s.origin = 'head') DESC, s.id");
        $stmt->execute([$groupId]);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    public function create(string $code, string $name, string $head, int $maxShops, ?string $by): int
    {
        $pdo = $this->pdo();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('INSERT INTO shop_groups (code, name, head_tenant_code, max_shops, created_by) VALUES (?, ?, ?, ?, ?) RETURNING id');
            $stmt->execute([$code, mb_substr($name, 0, 160), $head, $maxShops, $by]);
            $id = (int) $stmt->fetchColumn();
            $pdo->prepare("INSERT INTO shop_group_sites (group_id, tenant_code, origin, joined_by) VALUES (?, ?, 'head', ?)")->execute([$id, $head, $by]);
            $pdo->commit();

            return $id;
        } catch (\Throwable $e) {
            $pdo->rollBack();

            throw $e;
        }
    }

    /** Ajoute une boutique sous verrou du groupe : `added`, `gone` (groupe dissous), `limit` (forfait plein) ou `taken` (déjà dans un groupe : index unique) */
    public function addShop(int $groupId, string $code, string $origin, ?string $by): string
    {
        $pdo = $this->pdo();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT max_shops, status FROM shop_groups WHERE id = ? FOR UPDATE');
            $stmt->execute([$groupId]);
            $group = $stmt->fetch(\PDO::FETCH_ASSOC) ?: ['max_shops' => 0, 'status' => 'dissolved'];
            if ($group['status'] !== 'active') {
                $pdo->rollBack();

                return 'gone';
            }
            $max = (int) $group['max_shops'];
            $count = $pdo->prepare('SELECT COUNT(*) FROM shop_group_sites WHERE group_id = ? AND left_at IS NULL');
            $count->execute([$groupId]);
            if ((int) $count->fetchColumn() >= $max) {
                $pdo->rollBack();

                return 'limit';
            }
            $pdo->prepare('INSERT INTO shop_group_sites (group_id, tenant_code, origin, joined_by) VALUES (?, ?, ?, ?)')->execute([$groupId, $code, $origin, $by]);
            $pdo->commit();

            return 'added';
        } catch (\PDOException $e) {
            $pdo->inTransaction() && $pdo->rollBack();
            if ($e->getCode() === '23505') {
                return 'taken';
            }

            throw $e;
        }
    }

    public function leave(int $groupId, string $code, ?string $by): bool
    {
        $stmt = $this->pdo()->prepare('UPDATE shop_group_sites SET left_at = NOW(), left_by = ? WHERE group_id = ? AND tenant_code = ? AND left_at IS NULL');
        $stmt->execute([$by, $groupId, $code]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Dissout le groupe SOUS VERROU (relecture neuve de G-1) : une acceptation qui arriverait pendant la dissolution attend (`addShop` prend le
     * même verrou) puis voit le groupe dissous. Rend faux si le groupe a encore une autre boutique que le siège au moment du verrou.
     */
    public function dissolve(int $groupId, string $head, ?string $by): bool
    {
        $pdo = $this->pdo();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('SELECT id FROM shop_groups WHERE id = ? FOR UPDATE')->execute([$groupId]);
            $others = $pdo->prepare('SELECT COUNT(*) FROM shop_group_sites WHERE group_id = ? AND left_at IS NULL AND tenant_code <> ?');
            $others->execute([$groupId, $head]);
            if ((int) $others->fetchColumn() > 0) {
                $pdo->rollBack();

                return false;
            }
            $pdo->prepare('UPDATE shop_group_sites SET left_at = NOW(), left_by = ? WHERE group_id = ? AND left_at IS NULL')->execute([$by, $groupId]);
            $pdo->prepare("UPDATE shop_groups SET status = 'dissolved', dissolved_at = NOW() WHERE id = ?")->execute([$groupId]);
            $pdo->commit();

            return true;
        } catch (\Throwable $e) {
            $pdo->inTransaction() && $pdo->rollBack();

            throw $e;
        }
    }

    /**
     * Compte ET note une demande d'invitation SOUS VERROU du groupe (relecture neuve : des demandes simultanées ne dépassent plus la limite).
     * Rend faux, sans rien noter, si la limite des 24 dernières heures est atteinte.
     */
    public function askInvitation(int $groupId, string $code, int $perDay, ?string $actor): bool
    {
        $pdo = $this->pdo();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('SELECT id FROM shop_groups WHERE id = ? FOR UPDATE')->execute([$groupId]);
            $count = $pdo->prepare("SELECT COUNT(*) FROM shop_group_events WHERE group_id = ? AND event = 'invitation_asked' AND created_at > NOW() - INTERVAL '1 day'");
            $count->execute([$groupId]);
            if ((int) $count->fetchColumn() >= $perDay) {
                $pdo->rollBack();

                return false;
            }
            $pdo->prepare("INSERT INTO shop_group_events (group_id, event, tenant_code, payload, actor) VALUES (?, 'invitation_asked', ?, '{}', ?)")->execute([$groupId, $code, $actor]);
            $pdo->commit();

            return true;
        } catch (\Throwable $e) {
            $pdo->inTransaction() && $pdo->rollBack();

            throw $e;
        }
    }

    /**
     * Crée l'invitation ; null si une invitation ouverte de ce groupe attend déjà ce site (index unique partiel : deux messages simultanés
     * ne font jamais deux invitations). Une invitation échue encore ouverte est d'abord close (refusée par l'échéance).
     */
    public function invite(int $groupId, string $code, string $tokenHash, ?string $by, \DateTimeImmutable $expires): ?int
    {
        $pdo = $this->pdo();
        $pdo->prepare("UPDATE shop_group_invitations SET refused_at = expires_at, answered_by = 'échéance' WHERE group_id = ? AND tenant_code = ? AND accepted_at IS NULL AND refused_at IS NULL AND expires_at <= NOW()")
            ->execute([$groupId, $code]);
        try {
            $stmt = $pdo->prepare('INSERT INTO shop_group_invitations (group_id, tenant_code, token_hash, invited_by, expires_at) VALUES (?, ?, ?, ?, ?) RETURNING id');
            $stmt->execute([$groupId, $code, $tokenHash, $by, $expires->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s')]);
        } catch (\PDOException $e) {
            if ($e->getCode() === '23505') {
                return null;
            }

            throw $e;
        }

        return (int) $stmt->fetchColumn();
    }

    /** @return array<string, mixed>|null */
    public function invitation(int $id): ?array
    {
        return $this->one('SELECT id, group_id, tenant_code, invited_by, created_at, expires_at, accepted_at, refused_at FROM shop_group_invitations WHERE id = ?', [$id]);
    }

    /** @return list<array<string, mixed>> les invitations en attente (non échues) adressées à ce site */
    public function openInvitationsFor(string $code): array
    {
        $stmt = $this->pdo()->prepare('SELECT i.id, i.group_id, i.expires_at, g.name AS group_name, g.head_tenant_code FROM shop_group_invitations i JOIN shop_groups g ON g.id = i.group_id
            WHERE i.tenant_code = ? AND i.accepted_at IS NULL AND i.refused_at IS NULL AND i.expires_at > NOW() AND g.status = \'active\' ORDER BY i.id DESC');
        $stmt->execute([$code]);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    public function answer(int $id, bool $accepted, ?string $by): void
    {
        $this->pdo()->prepare('UPDATE shop_group_invitations SET ' . ($accepted ? 'accepted_at' : 'refused_at') . ' = NOW(), answered_by = ? WHERE id = ?')->execute([$by, $id]);
    }


    /** @param array<string, mixed> $payload */
    public function event(int $groupId, string $event, ?string $code, array $payload, ?string $actor): void
    {
        $this->pdo()->prepare('INSERT INTO shop_group_events (group_id, event, tenant_code, payload, actor) VALUES (?, ?, ?, ?, ?)')
            ->execute([$groupId, $event, $code, json_encode($payload, \JSON_UNESCAPED_UNICODE), $actor]);
    }

    /** @return array<string, mixed>|null */
    private function one(string $sql, array $params): ?array
    {
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    private function pdo(): \PDO
    {
        return $this->tenants->getPdoMaster();
    }
}
