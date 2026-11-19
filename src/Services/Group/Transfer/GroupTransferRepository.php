<?php

namespace App\Services\Group\Transfer;

use App\Services\TenantConnectionManager;

/**
 * Lot G-5 : les transferts entre boutiques d'un groupe dans la base MAÎTRE (`ops/sql/group_transfers_master.sql`). L'ÉTAT fait foi : chaque
 * passage est CONDITIONNEL (`WHERE state = <attendu>`), une seule requête concurrente le gagne (double clic, reprise, annulation).
 */
class GroupTransferRepository
{
    /** Espace des verrous consultatifs des transferts (premier entier de la paire) */
    public const LOCK_SPACE = 75050;

    public function __construct(private readonly TenantConnectionManager $tenants)
    {
    }

    /** @param list<array{line_no: int, source_product_id: int, source_variant_id: int, target_product_id: int, target_variant_id: ?int, quantity: int}> $lines */
    public function create(int $groupId, string $source, string $target, ?string $currency, array $lines, ?string $by): int
    {
        $pdo = $this->pdo();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('INSERT INTO shop_group_transfers (uid, group_id, source_tenant_code, target_tenant_code, currency, requested_by) VALUES (?, ?, ?, ?, ?, ?) RETURNING id');
            $stmt->execute(['gt-' . bin2hex(random_bytes(12)), $groupId, $source, $target, $currency, $by]);
            $id = (int) $stmt->fetchColumn();
            $line = $pdo->prepare('INSERT INTO shop_group_transfer_lines (transfer_id, line_no, source_product_id, source_variant_id, target_product_id, target_variant_id, quantity) VALUES (?, ?, ?, ?, ?, ?, ?)');
            foreach ($lines as $l) {
                $line->execute([$id, $l['line_no'], $l['source_product_id'], $l['source_variant_id'], $l['target_product_id'], $l['target_variant_id'], $l['quantity']]);
            }
            $pdo->commit();

            return $id;
        } catch (\Throwable $e) {
            $pdo->inTransaction() && $pdo->rollBack();

            throw $e;
        }
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM shop_group_transfers WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /** @return list<array<string, mixed>> */
    public function lines(int $id): array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM shop_group_transfer_lines WHERE transfer_id = ? ORDER BY line_no');
        $stmt->execute([$id]);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /** @return list<array<string, mixed>> les transferts du groupe, le plus récent d'abord ; `$source` : ceux qu'une boutique doit envoyer */
    public function list(int $groupId, ?string $source = null, int $limit = 100): array
    {
        $sql = 'SELECT * FROM shop_group_transfers WHERE group_id = ?' . ($source !== null ? ' AND source_tenant_code = ?' : '') . ' ORDER BY id DESC LIMIT ' . max(1, min(500, $limit));
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($source !== null ? [$groupId, $source] : [$groupId]);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Passage CONDITIONNEL d'un état à un autre ; rend vrai s'il a eu lieu. `$extra` : colonnes posées en même temps (nom => valeur ; `NOW()`
     * pour la valeur spéciale `now`).
     *
     * @param list<string> $from
     * @param array<string, mixed> $extra
     */
    public function move(int $id, array $from, string $to, array $extra = []): bool
    {
        $sets = ['state = ?'];
        $params = [$to];
        foreach ($extra as $column => $value) {
            if (!preg_match('/^[a-z_]+$/', $column)) {
                throw new \LogicException('colonne invalide');
            }
            if ($value === 'now') {
                $sets[] = "$column = NOW()";
            } elseif ($value === 'attempt+1') {
                $sets[] = "$column = $column + 1";
            } else {
                $sets[] = "$column = ?";
                $params[] = $value;
            }
        }
        $placeholders = implode(', ', array_fill(0, count($from), '?'));
        $stmt = $this->pdo()->prepare('UPDATE shop_group_transfers SET ' . implode(', ', $sets) . " WHERE id = ? AND state IN ($placeholders)");
        $stmt->execute([...$params, $id, ...$from]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Le coût unitaire figé à l'envoi, ligne par ligne (écrit après le COMMIT de la source). `$onlyMissing` : seulement les lignes encore sans
     * coût (une reprise après une panne entre la sortie et l'écriture du coût le pose, sans réécrire un coût déjà figé).
     *
     * @param array<int, ?int> $costs line_no => centimes
     */
    public function setCosts(int $id, array $costs, bool $onlyMissing = false): void
    {
        $stmt = $this->pdo()->prepare('UPDATE shop_group_transfer_lines SET unit_cost_cents = ? WHERE transfer_id = ? AND line_no = ?' . ($onlyMissing ? ' AND unit_cost_cents IS NULL' : ''));
        foreach ($costs as $lineNo => $cents) {
            $stmt->execute([$cents, $id, $lineNo]);
        }
    }

    /** Verrou consultatif d'un transfert (session de la connexion maître) : rend faux s'il est tenu ailleurs (envoi vivant, reprise, annulation) */
    public function tryLock(int $id): bool
    {
        $stmt = $this->pdo()->prepare('SELECT pg_try_advisory_lock(?, ?)');
        $stmt->execute([self::LOCK_SPACE, $id]);

        return (bool) $stmt->fetchColumn();
    }

    /** La connexion maître COURANTE tient-elle le verrou de ce transfert ? */
    public function holdsLock(int $id): bool
    {
        $stmt = $this->pdo()->prepare("SELECT EXISTS (SELECT 1 FROM pg_locks WHERE locktype = 'advisory' AND classid = ? AND objid = ? AND objsubid = 2 AND pid = pg_backend_pid() AND granted)");
        $stmt->execute([self::LOCK_SPACE, $id]);

        return (bool) $stmt->fetchColumn();
    }

    /** Un envoi bloqué dont la reprise a échoué passe derrière les autres (son `sending_since` repart de maintenant) */
    public function postpone(int $id): void
    {
        $this->pdo()->prepare("UPDATE shop_group_transfers SET sending_since = NOW() WHERE id = ? AND state = 'sending'")->execute([$id]);
    }

    public function unlock(int $id): void
    {
        $this->pdo()->prepare('SELECT pg_advisory_unlock(?, ?)')->execute([self::LOCK_SPACE, $id]);
    }

    /** @return list<int> les transferts bloqués en `sending` depuis plus de `$minutes` minutes */
    public function stuck(int $minutes): array
    {
        $stmt = $this->pdo()->prepare("SELECT id FROM shop_group_transfers WHERE state = 'sending' AND sending_since < NOW() - make_interval(mins => ?) ORDER BY sending_since, id LIMIT 50");
        $stmt->execute([$minutes]);

        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN) ?: []);
    }

    /** Les transferts seulement DEMANDÉS entre ces deux boutiques (n'importe quel sens), pour l'annulation qui suit un lien retiré */
    public function requestedFor(int $groupId, string $shop): array
    {
        $stmt = $this->pdo()->prepare("SELECT id FROM shop_group_transfers WHERE group_id = ? AND state = 'requested' AND (source_tenant_code = ? OR target_tenant_code = ?)");
        $stmt->execute([$groupId, $shop, $shop]);

        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN) ?: []);
    }

    /** @param array<string, mixed> $payload */
    public function event(int $id, string $event, array $payload, ?string $actor): void
    {
        $this->pdo()->prepare('INSERT INTO shop_group_transfer_events (transfer_id, event, payload, actor) VALUES (?, ?, ?, ?)')
            ->execute([$id, $event, json_encode($payload, \JSON_UNESCAPED_UNICODE), $actor]);
    }

    /**
     * La correspondance d'une variante de `$source` dans `$target` (G-4), dans les deux sens d'une copie.
     *
     * @return array{target_product_id: int, target_variant_id: ?int, source_product_id: int}|null
     */
    public function link(int $groupId, string $source, int $sourceVariantId, string $target): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT target_product_id, target_variant_id, source_product_id FROM shop_group_item_links WHERE group_id = ? AND source_tenant_code = ? AND source_variant_id = ? AND target_tenant_code = ?
            UNION ALL SELECT source_product_id, source_variant_id, target_product_id FROM shop_group_item_links WHERE group_id = ? AND target_tenant_code = ? AND target_variant_id = ? AND source_tenant_code = ? LIMIT 1');
        $stmt->execute([$groupId, $source, $sourceVariantId, $target, $groupId, $source, $sourceVariantId, $target]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row ? ['target_product_id' => (int) $row['target_product_id'], 'target_variant_id' => $row['target_variant_id'] === null ? null : (int) $row['target_variant_id'], 'source_product_id' => (int) $row['source_product_id']] : null;
    }

    private function pdo(): \PDO
    {
        return $this->tenants->getPdoMaster();
    }
}
