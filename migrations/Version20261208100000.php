<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Application de gestion, lot B1b-1 (11/11/2026, `docs/tresorerie-gestion.md`) : trésorerie saisie (soldes datés `gestion_cash_balance`,
 * mouvements prévus `gestion_cash_movement`, réglages `gestion_cash_setting`). Additive, idempotente ; équivalent
 * `scripts/migrate_all_v2_gestion_tresorerie.sh` pour toutes les bases.
 */
final class Version20261208100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Gestion, lot B1b-1 : trésorerie saisie (soldes, mouvements prévus, réglages)';
    }

    public function up(Schema $schema): void
    {
        foreach (self::STATEMENTS as $sql) {
            $this->addSql($sql);
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS gestion_cash_setting');
        $this->addSql('DROP TABLE IF EXISTS gestion_cash_movement');
        $this->addSql('DROP TABLE IF EXISTS gestion_cash_balance');
    }

    public const STATEMENTS = [
        'CREATE TABLE IF NOT EXISTS gestion_cash_balance (id SERIAL NOT NULL, balance_date DATE NOT NULL, amount_cents BIGINT NOT NULL, note VARCHAR(500) DEFAULT NULL, created_by VARCHAR(255) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))',
        'CREATE UNIQUE INDEX IF NOT EXISTS uniq_gestion_cash_balance_date ON gestion_cash_balance (balance_date)',
        'CREATE TABLE IF NOT EXISTS gestion_cash_movement (id SERIAL NOT NULL, label VARCHAR(255) NOT NULL, category VARCHAR(20) NOT NULL, direction VARCHAR(3) NOT NULL, amount_cents BIGINT NOT NULL, start_date DATE NOT NULL, recurrence VARCHAR(10) NOT NULL, end_date DATE DEFAULT NULL, notes TEXT DEFAULT NULL, created_by VARCHAR(255) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))',
        'CREATE INDEX IF NOT EXISTS idx_gestion_cash_movement_category ON gestion_cash_movement (category)',
        'CREATE TABLE IF NOT EXISTS gestion_cash_setting (id INT NOT NULL, threshold_cents BIGINT NOT NULL DEFAULT 0, include_estimated_sales BOOLEAN NOT NULL DEFAULT FALSE, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))',
    ];
}
