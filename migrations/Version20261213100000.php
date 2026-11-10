<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Application de gestion, lot P5 (11/11/2026, `docs/promotions-gestion.md`) : journal des promotions datées posées par la gestion
 * (`gestion_promotion_batch`, `gestion_promotion_change` : ancien et nouvel état du prix promotionnel de chaque article, pour terminer ou
 * remettre l'ancienne promotion). Additive, idempotente ; équivalent `scripts/migrate_all_v2_gestion_promotions.sh` pour toutes les bases.
 */
final class Version20261213100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Gestion, lot P5 : journal des promotions datées';
    }

    public function up(Schema $schema): void
    {
        foreach (self::STATEMENTS as $sql) {
            $this->addSql($sql);
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS gestion_promotion_change');
        $this->addSql('DROP TABLE IF EXISTS gestion_promotion_batch');
    }

    public const STATEMENTS = [
        'CREATE TABLE IF NOT EXISTS gestion_promotion_batch (id SERIAL NOT NULL, body JSON NOT NULL, percent NUMERIC(6, 2) NOT NULL, rounding VARCHAR(10) NOT NULL, start_date DATE NOT NULL, end_date DATE NOT NULL, starts_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, ends_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, advice_id INT DEFAULT NULL, advice_marked BOOLEAN NOT NULL DEFAULT FALSE, changed INT NOT NULL, created_by VARCHAR(255) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, ended_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, ended_by VARCHAR(255) DEFAULT NULL, end_skipped JSON DEFAULT NULL, PRIMARY KEY(id))',
        'CREATE TABLE IF NOT EXISTS gestion_promotion_change (id SERIAL NOT NULL, batch_id INT NOT NULL, product_id INT NOT NULL, old_special_price INT DEFAULT NULL, old_from TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, old_to TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, new_special_price INT NOT NULL, new_from TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, new_to TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id), CONSTRAINT fk_gestion_promotion_change_batch FOREIGN KEY (batch_id) REFERENCES gestion_promotion_batch (id) ON DELETE CASCADE)',
        'CREATE INDEX IF NOT EXISTS idx_gestion_promotion_change_batch ON gestion_promotion_change (batch_id)',
        'CREATE INDEX IF NOT EXISTS idx_gestion_promotion_change_product ON gestion_promotion_change (product_id)',
    ];
}
