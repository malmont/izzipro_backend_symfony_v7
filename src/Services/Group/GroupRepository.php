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
        $stmt = $this->pdo()->prepare("SELECT s.tenant_code, s.origin, s.joined_at, s.same_company, s.same_company_since, t.name FROM shop_group_sites s LEFT JOIN tenants t ON t.code = s.tenant_code
            WHERE s.group_id = ? AND s.left_at IS NULL ORDER BY (s.origin = 'head') DESC, s.id");
        $stmt->execute([$groupId]);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Lot G-2 : les boutiques ouvertes du groupe avec leur base, le siège d'abord, pour « Toutes les boutiques ». Une boutique sans ligne
     * dans `tenants` garde un `dbname` nul : elle sera listée en erreur, jamais lue ailleurs.
     *
     * @return list<array{tenant_code: string, name: ?string, dbname: ?string, origin: string, same_company: bool}>
     */
    public function shopDatabases(int $groupId): array
    {
        $stmt = $this->pdo()->prepare("SELECT s.tenant_code, t.name, t.dbname, s.origin, s.same_company FROM shop_group_sites s LEFT JOIN tenants t ON t.code = s.tenant_code
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

    /**
     * Ajoute une boutique sous verrou du groupe : `added`, `gone` (groupe dissous, ou invitation close entre-temps), `limit` (forfait plein) ou
     * `taken` (déjà dans un groupe : index unique). Lot G-INVITES : avec `$invitationId`, l'invitation est relue OUVERTE sous ce même verrou
     * et marquée acceptée dans la même transaction ; l'annulation par le siège prend le même verrou : jamais une boutique entrée par une
     * invitation que le siège vient d'annuler.
     */
    public function addShop(int $groupId, string $code, string $origin, ?string $by, ?string $sameCompanyConsent = null, ?int $invitationId = null): string
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
            if ($invitationId !== null) {
                $open = $pdo->prepare('SELECT 1 FROM shop_group_invitations WHERE id = ? AND group_id = ? AND accepted_at IS NULL AND refused_at IS NULL AND expires_at > NOW()');
                $open->execute([$invitationId, $groupId]);
                if ($open->fetchColumn() === false) {
                    $pdo->rollBack();

                    return 'gone';
                }
            }
            $max = (int) $group['max_shops'];
            $count = $pdo->prepare('SELECT COUNT(*) FROM shop_group_sites WHERE group_id = ? AND left_at IS NULL');
            $count->execute([$groupId]);
            if ((int) $count->fetchColumn() >= $max) {
                $pdo->rollBack();

                return 'limit';
            }
            // lot G-1d : la case « même entreprise » cochée à l'acceptation naît AVEC le lien (jamais une boutique entrée sans le lien qu'elle a coché)
            $pdo->prepare('INSERT INTO shop_group_sites (group_id, tenant_code, origin, joined_by, same_company, same_company_since, same_company_consent_version)
                VALUES (?, ?, ?, ?, ?::boolean, CASE WHEN ?::boolean THEN NOW() ELSE NULL END, ?)')
                ->execute([$groupId, $code, $origin, $by, $sameCompanyConsent !== null ? 't' : 'f', $sameCompanyConsent !== null ? 't' : 'f', $sameCompanyConsent]);
            if ($invitationId !== null) {
                $pdo->prepare('UPDATE shop_group_invitations SET accepted_at = NOW(), answered_by = ? WHERE id = ?')->execute([$by, $invitationId]);
            }
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
     * Rend null, sans rien noter, si la limite des 24 dernières heures est atteinte. Lot G-INVITES : la demande est l'événement
     * `invitation_requested` (le code tapé, l'heure) ; son identifiant est le NUMÉRO DE LA DEMANDE, rendu à l'appelant pour la file. Avant ce
     * lot, l'événement s'appelait `invitation_asked` : la limite compte les deux.
     */
    public function askInvitation(int $groupId, string $code, int $perDay, ?string $actor): ?int
    {
        $pdo = $this->pdo();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('SELECT id FROM shop_groups WHERE id = ? FOR UPDATE')->execute([$groupId]);
            // relecture neuve, passe 2 : compté par SIÈGE (tous ses groupes, dissous compris) : dissoudre et recréer ne remet pas la limite à zéro
            $count = $pdo->prepare("SELECT COUNT(*) FROM shop_group_events e JOIN shop_groups g ON g.id = e.group_id WHERE g.head_tenant_code = (SELECT head_tenant_code FROM shop_groups WHERE id = ?)
                AND e.event IN ('invitation_asked', 'invitation_requested') AND e.created_at > NOW() - INTERVAL '1 day'");
            $count->execute([$groupId]);
            if ((int) $count->fetchColumn() >= $perDay) {
                $pdo->rollBack();

                return null;
            }
            $stmt = $pdo->prepare("INSERT INTO shop_group_events (group_id, event, tenant_code, payload, actor) VALUES (?, 'invitation_requested', ?, '{}', ?) RETURNING id");
            $stmt->execute([$groupId, $code, $actor]);
            $id = (int) $stmt->fetchColumn();
            $pdo->commit();

            return $id;
        } catch (\Throwable $e) {
            $pdo->inTransaction() && $pdo->rollBack();

            throw $e;
        }
    }

    /**
     * Lot G-INVITES : les demandes d'invitation du groupe, lues dans le SEUL journal de la base maître (jamais une base de site : le temps de
     * réponse ne dit rien de l'existence du site). Sans les demandes annulées ni celles dont l'invitation a été acceptée ; une demande échue
     * reste lisible `$keepDays` jours après son échéance.
     *
     * @return list<array{id: int, code: string, created_at: string}>
     */
    public function invitationRequests(int $groupId, int $validDays, int $keepDays): array
    {
        $stmt = $this->pdo()->prepare("SELECT e.id, COALESCE(e.tenant_code, '') AS code, e.created_at FROM shop_group_events e
            WHERE e.group_id = ? AND e.event = 'invitation_requested' AND e.created_at > NOW() - make_interval(days => ?)
              AND NOT EXISTS (SELECT 1 FROM shop_group_events c WHERE c.group_id = e.group_id AND c.event = 'invitation_cancelled' AND c.payload::json->>'request' = e.id::text)
              AND NOT EXISTS (SELECT 1 FROM shop_group_invitations i WHERE i.request_id = e.id AND i.accepted_at IS NOT NULL)
            ORDER BY e.id DESC");
        $stmt->execute([$groupId, $validDays + $keepDays]);

        return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'code' => (string) $r['code'], 'created_at' => (string) $r['created_at']], $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * Lot G-INVITES : annule une demande SOUS VERROU du groupe (celui de `addShop`) : l'événement `invitation_cancelled` (qui, quand), puis la
     * vraie invitation née de cette demande, s'il y en a une, est close (elle ne s'accepte plus). Rend faux si la demande n'est pas une
     * demande listée de ce groupe (inconnue, d'un autre groupe, déjà annulée, acceptée, ou plus gardée).
     */
    public function cancelInvitationRequest(int $groupId, int $requestId, int $validDays, int $keepDays, ?string $actor): bool
    {
        $pdo = $this->pdo();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('SELECT id FROM shop_groups WHERE id = ? FOR UPDATE')->execute([$groupId]);
            $listed = false;
            foreach ($this->invitationRequests($groupId, $validDays, $keepDays) as $request) {
                $listed = $listed || $request['id'] === $requestId;
            }
            if (!$listed) {
                $pdo->rollBack();

                return false;
            }
            $pdo->prepare("INSERT INTO shop_group_events (group_id, event, tenant_code, payload, actor) VALUES (?, 'invitation_cancelled', NULL, ?, ?)")
                ->execute([$groupId, json_encode(['request' => $requestId]), $actor]);
            $this->closeRequestInvitation($requestId, $pdo);
            $pdo->commit();

            return true;
        } catch (\Throwable $e) {
            $pdo->inTransaction() && $pdo->rollBack();

            throw $e;
        }
    }

    /** Lot G-INVITES : la demande a-t-elle été annulée par le siège ? (relu par la file avant et après la création de l'invitation) */
    public function isRequestCancelled(int $groupId, int $requestId): bool
    {
        return $this->one("SELECT 1 AS ok FROM shop_group_events WHERE group_id = ? AND event = 'invitation_cancelled' AND payload::json->>'request' = ?", [$groupId, (string) $requestId]) !== null;
    }

    /** Lot G-INVITES : close l'invitation encore ouverte née de cette demande (rend vrai si une invitation a été close) */
    public function closeRequestInvitation(int $requestId, ?\PDO $pdo = null): bool
    {
        $stmt = ($pdo ?? $this->pdo())->prepare("UPDATE shop_group_invitations SET refused_at = NOW(), answered_by = 'annulée par le siège' WHERE request_id = ? AND accepted_at IS NULL AND refused_at IS NULL");
        $stmt->execute([$requestId]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Crée l'invitation ; null si une invitation ouverte de ce groupe attend déjà ce site (index unique partiel : deux messages simultanés
     * ne font jamais deux invitations). Une invitation échue encore ouverte est d'abord close (refusée par l'échéance).
     */
    public function invite(int $groupId, string $code, string $tokenHash, ?string $by, \DateTimeImmutable $expires, ?int $requestId = null): ?int
    {
        $pdo = $this->pdo();
        $pdo->prepare("UPDATE shop_group_invitations SET refused_at = expires_at, answered_by = 'échéance' WHERE group_id = ? AND tenant_code = ? AND accepted_at IS NULL AND refused_at IS NULL AND expires_at <= NOW()")
            ->execute([$groupId, $code]);
        try {
            $stmt = $pdo->prepare('INSERT INTO shop_group_invitations (group_id, tenant_code, token_hash, invited_by, expires_at, request_id) VALUES (?, ?, ?, ?, ?, ?) RETURNING id');
            $stmt->execute([$groupId, $code, $tokenHash, $by, $expires->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'), $requestId]);
        } catch (\PDOException $e) {
            if ($e->getCode() === '23505') {
                return null;
            }

            throw $e;
        }

        return (int) $stmt->fetchColumn();
    }

    /**
     * Lot G-INVITES (relecture neuve) : crée l'invitation d'une DEMANDE numérotée SOUS LE VERROU DU GROUPE, celui que prend l'annulation
     * (`cancelInvitationRequest`) : l'annulation est relue sous ce verrou, puis l'invitation est créée dans la même transaction. Aucun ordre
     * ne laisse donc une invitation ouverte pour une demande annulée (l'annulation la voit et la ferme, ou la file voit l'annulation).
     * L'échéance part de l'heure de la DEMANDE (+ `$days`), la même que la liste du siège, et non du passage de la file.
     *
     * @return int|'cancelled'|'pending' l'id de l'invitation créée ; `cancelled` si la demande est annulée (ou inconnue de ce groupe) ;
     *                                   `pending` si une invitation ouverte de ce groupe attend déjà ce site
     */
    public function inviteForRequest(int $groupId, int $requestId, string $code, string $tokenHash, ?string $by, int $days): int|string
    {
        $pdo = $this->pdo();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('SELECT id FROM shop_groups WHERE id = ? FOR UPDATE')->execute([$groupId]);
            $request = $pdo->prepare("SELECT created_at FROM shop_group_events WHERE id = ? AND group_id = ? AND event = 'invitation_requested'");
            $request->execute([$requestId, $groupId]);
            $requestedAt = $request->fetchColumn();
            if ($requestedAt === false || $this->isRequestCancelled($groupId, $requestId)) {
                $pdo->rollBack();

                return 'cancelled';
            }
            // une invitation échue encore ouverte est d'abord close ; une invitation ouverte attend déjà ce site : rien de neuf (sous le verrou,
            // aucune autre création pour ce groupe ne peut se glisser entre cette lecture et l'insertion)
            $pdo->prepare("UPDATE shop_group_invitations SET refused_at = expires_at, answered_by = 'échéance' WHERE group_id = ? AND tenant_code = ? AND accepted_at IS NULL AND refused_at IS NULL AND expires_at <= NOW()")
                ->execute([$groupId, $code]);
            $open = $pdo->prepare('SELECT 1 FROM shop_group_invitations WHERE group_id = ? AND tenant_code = ? AND accepted_at IS NULL AND refused_at IS NULL');
            $open->execute([$groupId, $code]);
            if ($open->fetchColumn() !== false) {
                $pdo->rollBack();

                return 'pending';
            }
            $stmt = $pdo->prepare('INSERT INTO shop_group_invitations (group_id, tenant_code, token_hash, invited_by, expires_at, request_id) VALUES (?, ?, ?, ?, ?::timestamp + make_interval(days => ?), ?) RETURNING id');
            $stmt->execute([$groupId, $code, $tokenHash, $by, (string) $requestedAt, $days, $requestId]);
            $id = (int) $stmt->fetchColumn();
            $pdo->commit();

            return $id;
        } catch (\Throwable $e) {
            $pdo->inTransaction() && $pdo->rollBack();

            throw $e;
        }
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
