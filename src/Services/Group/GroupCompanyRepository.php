<?php

namespace App\Services\Group;

use App\Services\TenantConnectionManager;

/**
 * Lot G-1d : le lien « même entreprise » dans la base MAÎTRE (`ops/sql/group_company_master.sql`). L'état courant est sur le lien OUVERT de la
 * boutique (`shop_group_sites.same_company`) ; les demandes du directeur dans `shop_group_company_requests` (une seule en attente : index unique).
 */
class GroupCompanyRepository
{
    public function __construct(private readonly TenantConnectionManager $tenants)
    {
    }

    /** @return array<string, mixed>|null le lien OUVERT de ce site dans ce groupe (origine, état du lien) */
    public function link(int $groupId, string $code): ?array
    {
        return $this->one('SELECT id, origin, same_company, same_company_since, same_company_consent_version FROM shop_group_sites WHERE group_id = ? AND tenant_code = ? AND left_at IS NULL', [$groupId, $code]);
    }

    /**
     * Pose ou retire le lien sur le lien OUVERT d'une boutique ASSOCIÉE ; rend faux si rien n'a changé (lien fermé entre-temps, autre origine,
     * ou déjà dans cet état) : une seule requête conditionnelle, jamais deux gestes concurrents qui se croisent.
     */
    public function setSameCompany(int $groupId, string $code, bool $value, ?string $consentVersion): bool
    {
        $stmt = $this->pdo()->prepare("UPDATE shop_group_sites SET same_company = ?::boolean, same_company_since = CASE WHEN ?::boolean THEN NOW() ELSE NULL END, same_company_consent_version = ?
            WHERE group_id = ? AND tenant_code = ? AND left_at IS NULL AND origin = 'associated' AND same_company = ?::boolean");
        $stmt->execute([$value ? 't' : 'f', $value ? 't' : 'f', $value ? $consentVersion : null, $groupId, $code, $value ? 'f' : 't']);

        return $stmt->rowCount() > 0;
    }

    /**
     * Crée la demande ; null si une demande EN ATTENTE existe déjà (index unique partiel). Une demande échue encore « en attente » est d'abord
     * close (`expired`) : l'échéance se lit sans passage planifié.
     */
    public function request(int $groupId, string $code, ?string $by, \DateTimeImmutable $expires): ?int
    {
        $this->expire($groupId, $code);
        try {
            $stmt = $this->pdo()->prepare('INSERT INTO shop_group_company_requests (group_id, tenant_code, requested_by, expires_at) VALUES (?, ?, ?, ?) RETURNING id');
            $stmt->execute([$groupId, $code, $by, $expires->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s')]);
        } catch (\PDOException $e) {
            if ($e->getCode() === '23505') {
                return null;
            }

            throw $e;
        }

        return (int) $stmt->fetchColumn();
    }

    /** @return array<string, mixed>|null la demande EN ATTENTE et non échue de ce site dans ce groupe */
    public function pending(int $groupId, string $code): ?array
    {
        return $this->one("SELECT id, group_id, tenant_code, requested_by, requested_at, expires_at FROM shop_group_company_requests
            WHERE group_id = ? AND tenant_code = ? AND state = 'pending' AND expires_at > NOW() AND requested_at >= (SELECT joined_at FROM shop_group_sites WHERE group_id = ? AND tenant_code = ? AND left_at IS NULL)", [$groupId, $code, $groupId, $code]);
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->one('SELECT id, group_id, tenant_code, state, expires_at FROM shop_group_company_requests WHERE id = ?', [$id]);
    }

    /**
     * Répond à une demande EN ATTENTE et non échue : `confirmed` pose le lien dans la MÊME transaction (le lien ouvert doit être celui d'une
     * boutique associée de ce groupe) ; rend faux si la demande n'est plus en attente ou si le lien n'a pas pu être posé (rien n'est écrit).
     */
    public function answer(int $id, string $state, ?string $by, ?string $consentVersion, int $groupId, string $code): bool
    {
        $pdo = $this->pdo();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("UPDATE shop_group_company_requests SET state = ?, decided_by = ?, decided_at = NOW(), consent_version = ?
                WHERE id = ? AND group_id = ? AND tenant_code = ? AND state = 'pending' AND expires_at > NOW()
                  AND requested_at >= (SELECT joined_at FROM shop_group_sites WHERE group_id = ? AND tenant_code = ? AND left_at IS NULL)"); // relecture G-1d : jamais une demande d'avant le retour
            $stmt->execute([$state, $by, $consentVersion, $id, $groupId, $code, $groupId, $code]);
            if ($stmt->rowCount() === 0 || ($state === 'confirmed' && !$this->setSameCompany($groupId, $code, true, $consentVersion))) {
                $pdo->rollBack();

                return false;
            }
            $pdo->commit();

            return true;
        } catch (\Throwable $e) {
            $pdo->inTransaction() && $pdo->rollBack();

            throw $e;
        }
    }

    /** Ferme la demande en attente (retrait par le directeur, boutique sortie du groupe) ; rend vrai si une demande a été close */
    public function cancelPending(int $groupId, string $code, ?string $by): bool
    {
        $stmt = $this->pdo()->prepare("UPDATE shop_group_company_requests SET state = 'cancelled', decided_by = ?, decided_at = NOW() WHERE group_id = ? AND tenant_code = ? AND state = 'pending'");
        $stmt->execute([$by, $groupId, $code]);

        return $stmt->rowCount() > 0;
    }

    private function expire(int $groupId, string $code): void
    {
        $this->pdo()->prepare("UPDATE shop_group_company_requests SET state = 'expired', decided_by = 'échéance', decided_at = expires_at WHERE group_id = ? AND tenant_code = ? AND state = 'pending' AND expires_at <= NOW()")
            ->execute([$groupId, $code]);
        // relecture G-1d, passe 2 : une demande faite AVANT l'entrée actuelle de la boutique (course avec une sortie) est close, jamais confirmable
        $this->pdo()->prepare("UPDATE shop_group_company_requests SET state = 'cancelled', decided_by = 'sortie du groupe', decided_at = NOW() WHERE group_id = ? AND tenant_code = ? AND state = 'pending'
            AND NOT (requested_at >= (SELECT joined_at FROM shop_group_sites WHERE group_id = ? AND tenant_code = ? AND left_at IS NULL))")
            ->execute([$groupId, $code, $groupId, $code]);
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
