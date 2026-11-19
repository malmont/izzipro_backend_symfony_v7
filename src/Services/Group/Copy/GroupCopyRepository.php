<?php

namespace App\Services\Group\Copy;

use App\Services\TenantConnectionManager;

/** Lot G-4 : copies du catalogue et correspondance des articles, dans la base MAÎTRE (`ops/sql/group_copies_master.sql`) */
class GroupCopyRepository
{
    public function __construct(private readonly TenantConnectionManager $tenants)
    {
    }

    public function create(int $groupId, string $source, string $target, int $jobId, int $articles, bool $sourceInclusive, ?string $by): int
    {
        $stmt = $this->pdo()->prepare('INSERT INTO shop_group_copies (group_id, source_tenant_code, target_tenant_code, target_job_id, articles, source_prices_include_tax, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?) RETURNING id');
        $stmt->execute([$groupId, $source, $target, $jobId, $articles, $sourceInclusive ? 't' : 'f', $by]);

        return (int) $stmt->fetchColumn();
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM shop_group_copies WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : self::normalize($row);
    }

    /** @return array<string, mixed>|null la copie qui porte cette tâche d'import de la cible */
    public function byJob(string $target, int $jobId): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM shop_group_copies WHERE target_tenant_code = ? AND target_job_id = ?');
        $stmt->execute([$target, $jobId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : self::normalize($row);
    }

    /** @return list<array<string, mixed>> les copies du groupe, les plus récentes d'abord */
    public function ofGroup(int $groupId, int $limit = 20): array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM shop_group_copies WHERE group_id = ? ORDER BY created_at DESC, id DESC LIMIT ' . max(1, min(100, $limit)));
        $stmt->execute([$groupId]);

        return array_map(self::normalize(...), $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: []);
    }

    /** Confirmée une seule fois : faux si elle l'était déjà (deux clics) */
    public function confirm(int $id, bool $withCost): bool
    {
        $stmt = $this->pdo()->prepare('UPDATE shop_group_copies SET confirmed_at = NOW(), with_cost = ? WHERE id = ? AND confirmed_at IS NULL AND cancelled_at IS NULL');
        $stmt->execute([$withCost ? 't' : 'f', $id]);

        return $stmt->rowCount() === 1;
    }

    /** Une confirmation qui n'a pas pu partir (file indisponible) : la copie redevient confirmable */
    public function unconfirm(int $id): void
    {
        $this->pdo()->prepare('UPDATE shop_group_copies SET confirmed_at = NULL WHERE id = ?')->execute([$id]);
    }

    public function cancel(int $id): void
    {
        $this->pdo()->prepare('UPDATE shop_group_copies SET cancelled_at = COALESCE(cancelled_at, NOW()) WHERE id = ?')->execute([$id]);
    }

    /** @param list<array{sourceProduct: int, sourceVariant: ?int, targetProduct: int, targetVariant: ?int}> $links */
    public function addLinks(array $copy, array $links): int
    {
        $stmt = $this->pdo()->prepare('INSERT INTO shop_group_item_links (group_id, copy_id, source_tenant_code, source_product_id, source_variant_id, target_tenant_code, target_product_id, target_variant_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?) ON CONFLICT DO NOTHING');
        $added = 0;
        foreach ($links as $l) {
            $stmt->execute([$copy['group_id'], $copy['id'], $copy['source_tenant_code'], $l['sourceProduct'], $l['sourceVariant'], $copy['target_tenant_code'], $l['targetProduct'], $l['targetVariant']]);
            $added += $stmt->rowCount();
        }

        return $added;
    }

    /** @return list<int> les articles de la cible qui ont un lien */
    public function linkedTargetProducts(string $target): array
    {
        $stmt = $this->pdo()->prepare('SELECT DISTINCT target_product_id FROM shop_group_item_links WHERE target_tenant_code = ?');
        $stmt->execute([$target]);

        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN) ?: []);
    }

    /** @param list<int> $products */
    public function forgetTargetProducts(string $target, array $products): int
    {
        if ($products === []) {
            return 0;
        }
        $stmt = $this->pdo()->prepare('DELETE FROM shop_group_item_links WHERE target_tenant_code = ? AND target_product_id = ANY(?::int[])');
        $stmt->execute([$target, '{' . implode(',', array_map('intval', $products)) . '}']);

        return $stmt->rowCount();
    }

    /** @return list<array<string, mixed>> */
    public function linksOfCopy(int $copyId): array
    {
        $stmt = $this->pdo()->prepare('SELECT source_product_id, source_variant_id, target_product_id, target_variant_id FROM shop_group_item_links WHERE copy_id = ? ORDER BY id');
        $stmt->execute([$copyId]);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /** @param array<string, mixed> $row */
    private static function normalize(array $row): array
    {
        foreach (['with_cost', 'source_prices_include_tax'] as $flag) {
            $row[$flag] = in_array($row[$flag], [true, 't', 1, '1'], true);
        }
        foreach (['id', 'group_id', 'target_job_id', 'articles'] as $int) {
            $row[$int] = (int) $row[$int];
        }

        return $row;
    }

    private function pdo(): \PDO
    {
        return $this->tenants->getPdoMaster();
    }
}
