<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Lot S1 (11/11/2026, `docs/statistiques-gestion.md`) : instantané quotidien de la valeur du stock (`gestion_stock_snapshot` : jour du magasin,
 * valeur de vente et valeur au coût connu), rempli par le passage quotidien de 10 h, pour comparer la valeur du stock dans le temps. Additive,
 * idempotente ; équivalent `scripts/migrate_all_v2_gestion_stock_snapshot.sh` pour toutes les bases.
 */
final class Version20261217100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Lot S1 : instantané quotidien de la valeur du stock';
    }

    public function up(Schema $schema): void
    {
        foreach (self::STATEMENTS as $sql) {
            $this->addSql($sql);
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS gestion_stock_snapshot');
    }

    public const STATEMENTS = [
        'CREATE TABLE IF NOT EXISTS gestion_stock_snapshot (snapshot_date DATE NOT NULL, sale_value_cents BIGINT NOT NULL, cost_value_cents BIGINT NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(snapshot_date))',
    ];
}
