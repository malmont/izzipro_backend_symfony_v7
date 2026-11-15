<?php

namespace App\Import;

use App\Services\TenantEntityManagerProvider;
use Doctrine\DBAL\Connection;

/**
 * Les imports du site courant (lot IMP-1, `docs/import-boutique.md`) : tables `import_job` et `import_item`, lues et écrites en SQL
 * sur la connexion du site. `import_item` garde, pour chaque chose créée par un import, sa référence d'origine : c'est elle qui
 * empêche un doublon quand un fichier est rejoué, et qui dit quoi retirer à l'annulation.
 */
class ImportJobStore
{
    public const SOURCE_SHOPIFY = 'shopify';
    public const KIND_CATALOG = 'catalog';
    /** Lot IMP-2 : le fichier des clients (comptes invités, `CustomerImportService`) */
    public const KIND_CUSTOMERS = 'customers';
    public const KINDS = [self::KIND_CATALOG, self::KIND_CUSTOMERS];
    public const UPLOADED = 'uploaded';
    public const ANALYSED = 'analysed';
    public const RUNNING = 'running';
    public const DONE = 'done';
    public const FAILED = 'failed';
    public const CANCELLED = 'cancelled';
    /** États où un import occupe le site : un seul à la fois */
    public const ACTIVE = [self::UPLOADED, self::ANALYSED, self::RUNNING];
    public const ITEM_PRODUCT = 'product';
    public const ITEM_VARIANT = 'variant';
    public const ITEM_CATEGORY = 'category';
    public const ITEM_PICTURE = 'picture';
    public const ITEM_CUSTOMER = 'customer';

    public function __construct(private readonly TenantEntityManagerProvider $emProvider)
    {
    }

    public function create(string $source, string $kind, string $fileName, string $storedName, string $hash, int $rows, ?string $by): int
    {
        return (int) $this->conn()->fetchOne(
            'INSERT INTO import_job (source, kind, status, file_name, stored_name, file_hash, row_count, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?) RETURNING id',
            [$source, $kind, self::UPLOADED, mb_substr($fileName, 0, 255), $storedName, $hash, $rows, $by !== null ? mb_substr($by, 0, 180) : null]
        );
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $row = $this->conn()->fetchAssociative('SELECT * FROM import_job WHERE id = ?', [$id]);

        return $row === false ? null : $this->decode($row);
    }

    /** @return list<array<string, mixed>> */
    public function latest(int $limit = 20): array
    {
        return array_map($this->decode(...), $this->conn()->fetchAllAssociative('SELECT * FROM import_job ORDER BY id DESC LIMIT ' . max(1, min(100, $limit))));
    }

    /** Un import occupe-t-il déjà le site ? Rend son identifiant, ou null */
    public function activeId(): ?int
    {
        $id = $this->conn()->fetchOne("SELECT id FROM import_job WHERE status IN ('uploaded', 'analysed', 'running') ORDER BY id LIMIT 1");

        return $id === false ? null : (int) $id;
    }

    /**
     * Passe un import d'un état à un autre, seulement s'il est bien dans l'état attendu : deux demandes simultanées ne font pas
     * deux fois le même pas. Rend vrai si le pas a été fait.
     *
     * @param array<string, mixed> $set colonnes à écrire en plus (les tableaux sont encodés en JSON)
     */
    public function move(int $id, string $from, string $to, array $set = []): bool
    {
        $sql = 'UPDATE import_job SET status = :to';
        $params = ['to' => $to, 'id' => $id, 'from' => $from];
        foreach ($set as $column => $value) {
            if (!in_array($column, ['options', 'report', 'result', 'error', 'analysed_at', 'started_at', 'finished_at', 'cancelled_at'], true)) {
                throw new \InvalidArgumentException("Colonne d'import inconnue : $column");
            }
            if (str_ends_with($column, '_at')) {
                $sql .= ", $column = NOW()";
                continue;
            }
            $sql .= ", $column = :$column";
            $params[$column] = is_array($value) ? json_encode($value, \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_THROW_ON_ERROR) : $value;
        }

        return $this->conn()->executeStatement($sql . ' WHERE id = :id AND status = :from', $params) === 1;
    }

    public function saveResult(int $id, array $result): void
    {
        $this->conn()->executeStatement('UPDATE import_job SET result = ? WHERE id = ?', [json_encode($result, \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_THROW_ON_ERROR), $id]);
    }

    public function addItem(int $jobId, string $source, string $kind, int $entityId, string $ref): void
    {
        $this->conn()->executeStatement('INSERT INTO import_item (job_id, source, kind, entity_id, ref) VALUES (?, ?, ?, ?, ?)', [$jobId, $source, $kind, $entityId, mb_substr($ref, 0, 255)]);
    }

    /**
     * L'identifiant de l'ARTICLE qu'un import précédent a créé pour cette référence et qui existe encore, ou null. Un article retiré
     * (par l'annulation ou à la main) redevient importable ; un article gardé par une annulation reste « déjà importé ».
     */
    public function productOf(string $source, string $ref): ?int
    {
        $id = $this->conn()->fetchOne(
            "SELECT i.entity_id FROM import_item i WHERE i.source = ? AND i.kind = 'product' AND i.ref = ? AND EXISTS (SELECT 1 FROM product p WHERE p.id = i.entity_id) ORDER BY i.id DESC LIMIT 1",
            [$source, mb_substr($ref, 0, 255)]
        );

        return $id === false ? null : (int) $id;
    }

    /** @return list<array{id: int, kind: string, entity_id: int, ref: string}> */
    public function itemsOf(int $jobId, ?string $kind = null): array
    {
        return $this->conn()->fetchAllAssociative(
            'SELECT id, kind, entity_id, ref FROM import_item WHERE job_id = ?' . ($kind !== null ? ' AND kind = ?' : '') . ' ORDER BY id',
            $kind !== null ? [$jobId, $kind] : [$jobId]
        );
    }

    /** Oublie ce qu'un import avait créé pour cet article (article, variantes, images) : il a été retiré */
    public function forgetProduct(int $jobId, int $productId, array $variantIds): void
    {
        $this->conn()->executeStatement("DELETE FROM import_item WHERE job_id = ? AND kind IN ('product', 'picture') AND entity_id = ?", [$jobId, $productId]);
        if ($variantIds !== []) {
            $this->conn()->executeStatement("DELETE FROM import_item WHERE job_id = ? AND kind = 'variant' AND entity_id IN (" . implode(',', array_map('intval', $variantIds)) . ')', [$jobId]);
        }
    }

    public function forgetCategory(int $jobId, int $categoryId): void
    {
        $this->conn()->executeStatement("DELETE FROM import_item WHERE job_id = ? AND kind = 'category' AND entity_id = ?", [$jobId, $categoryId]);
    }

    /** @param array<string, mixed> $row */
    private function decode(array $row): array
    {
        foreach (['options', 'report', 'result'] as $column) {
            $row[$column] = is_string($row[$column] ?? null) ? (json_decode($row[$column], true) ?: []) : null;
        }
        $row['id'] = (int) $row['id'];
        $row['row_count'] = (int) $row['row_count'];

        return $row;
    }

    private function conn(): Connection
    {
        return $this->emProvider->getEntityManager()->getConnection();
    }
}
