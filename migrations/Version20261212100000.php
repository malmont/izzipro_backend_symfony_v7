<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Application de gestion, lot P4 (11/11/2026, `docs/prix-gestion.md`) : journal des prix appliqués (`gestion_price_batch` : un lot par
 * application, annulable 24 h ; `gestion_price_change` : ancien et nouveau prix par article ou variante). Additive, idempotente ; équivalent
 * `scripts/migrate_all_v2_gestion_prix.sh` pour toutes les bases.
 */
final class Version20261212100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Gestion, lot P4 : journal des prix appliqués';
    }

    public function up(Schema $schema): void
    {
        foreach (self::STATEMENTS as $sql) {
            $this->addSql($sql);
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS gestion_price_change');
        $this->addSql('DROP TABLE IF EXISTS gestion_price_batch');
    }

    public const STATEMENTS = [
        'CREATE TABLE IF NOT EXISTS gestion_price_batch (id SERIAL NOT NULL, body JSON NOT NULL, mode VARCHAR(12) NOT NULL, value NUMERIC(10, 4) NOT NULL, rounding VARCHAR(10) NOT NULL, advice_id INT DEFAULT NULL, advice_marked BOOLEAN NOT NULL DEFAULT FALSE, changed INT NOT NULL, created_by VARCHAR(255) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, undone_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, undone_by VARCHAR(255) DEFAULT NULL, undo_skipped JSON DEFAULT NULL, PRIMARY KEY(id))',
        'CREATE TABLE IF NOT EXISTS gestion_price_change (id SERIAL NOT NULL, batch_id INT NOT NULL, product_id INT NOT NULL, variant_id INT DEFAULT NULL, level VARCHAR(8) NOT NULL, old_price INT NOT NULL, new_price INT NOT NULL, PRIMARY KEY(id), CONSTRAINT fk_gestion_price_change_batch FOREIGN KEY (batch_id) REFERENCES gestion_price_batch (id) ON DELETE CASCADE)',
        'CREATE INDEX IF NOT EXISTS idx_gestion_price_change_batch ON gestion_price_change (batch_id)',
        'CREATE INDEX IF NOT EXISTS idx_gestion_price_change_product ON gestion_price_change (product_id)',
    ];
}
