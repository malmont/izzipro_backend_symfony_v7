<?php

namespace App\Services\SiteContentService;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * Écriture de lignes brutes dans une base de site (copie et construction du contenu d'exemple) : seules les colonnes
 * que la table possède sont écrites, l'identifiant vient de la séquence de la table (<table>_id_seq). Beaucoup de bases
 * de site n'ont pas de valeur par défaut sur id (Doctrine appelle la séquence lui-même) : la séquence est alors appelée
 * explicitement.
 */
final class SqlRowWriter
{
    /** @var array<string, array<string, true>> table => colonnes */
    private array $columns = [];
    /** @var array<string, ?string> table => séquence à appeler pour id (null : valeur par défaut de la colonne) */
    private array $sequences = [];

    public function __construct(private readonly Connection $db)
    {
    }

    /** Insère une ligne (sans son identifiant) ; retourne le nouvel identifiant */
    public function insert(string $table, array $row): int
    {
        unset($row['id']);
        [$sql, $values, $types] = $this->statement($table, $row, $this->sequence($table));

        return (int) $this->db->fetchOne($sql . ' RETURNING id', $values, $types);
    }

    /** Ligne d'une table de liaison (sans identifiant) */
    public function link(string $table, array $row): void
    {
        [$sql, $values, $types] = $this->statement($table, $row);
        $this->db->executeStatement($sql, $values, $types);
    }

    public function hasColumn(string $table, string $column): bool
    {
        return isset($this->columns($table)[$column]);
    }

    /** @return array<string, true> */
    public function columns(string $table): array
    {
        return $this->columns[$table] ??= array_fill_keys($this->db->fetchFirstColumn("SELECT column_name FROM information_schema.columns WHERE table_schema = 'public' AND table_name = ?", [$table]), true);
    }

    private function sequence(string $table): ?string
    {
        if (!array_key_exists($table, $this->sequences)) {
            $default = $this->db->fetchOne("SELECT column_default FROM information_schema.columns WHERE table_schema = 'public' AND table_name = ? AND column_name = 'id'", [$table]);
            $this->sequences[$table] = $default === null && $this->db->fetchOne('SELECT to_regclass(?)', [$table . '_id_seq']) !== null ? $table . '_id_seq' : null;
        }

        return $this->sequences[$table];
    }

    /** $sequence : identifiant tiré de cette séquence */
    private function statement(string $table, array $row, ?string $sequence = null): array
    {
        $row = array_intersect_key($row, $this->columns($table));
        $types = array_map(fn ($v) => match (true) {
            is_bool($v) => ParameterType::BOOLEAN,
            $v === null => ParameterType::NULL,
            is_int($v) => ParameterType::INTEGER,
            default => ParameterType::STRING,
        }, array_values($row));
        $columns = array_map(fn ($c) => $this->db->quoteIdentifier((string) $c), array_keys($row));
        $marks = array_fill(0, count($row), '?');
        if ($sequence !== null) {
            $columns[] = 'id';
            $marks[] = sprintf("nextval('%s')", $sequence);
        }

        return [sprintf('INSERT INTO %s (%s) VALUES (%s)', $table, implode(', ', $columns), implode(', ', $marks)), array_values($row), $types];
    }
}
