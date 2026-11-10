<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Application de gestion, lot B1a (11/11/2026, `docs/budgets-gestion.md`) : prévu par poste et par période (`gestion_budget_line`), voyages
 * d'achat (`gestion_trip`), rattachement des notes de frais et commandes à un voyage (`trip_id`, `trip_source`). Additive, idempotente ;
 * équivalent `scripts/migrate_all_v2_gestion_budgets.sh` pour toutes les bases.
 */
final class Version20261207100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Gestion, lot B1a : budgets par poste, voyages d\'achat, rattachement trip_id';
    }

    public function up(Schema $schema): void
    {
        foreach (self::STATEMENTS as $sql) {
            $this->addSql($sql);
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE commande DROP COLUMN IF EXISTS trip_source');
        $this->addSql('ALTER TABLE commande DROP COLUMN IF EXISTS trip_id');
        $this->addSql('ALTER TABLE note_de_frais DROP COLUMN IF EXISTS trip_source');
        $this->addSql('ALTER TABLE note_de_frais DROP COLUMN IF EXISTS trip_id');
        $this->addSql('DROP TABLE IF EXISTS gestion_trip');
        $this->addSql('DROP TABLE IF EXISTS gestion_budget_line');
    }

    public const STATEMENTS = [
        'CREATE TABLE IF NOT EXISTS gestion_budget_line (id SERIAL NOT NULL, period_id INT NOT NULL, category VARCHAR(20) NOT NULL, planned_cents INT NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id), CONSTRAINT fk_gestion_budget_line_period FOREIGN KEY (period_id) REFERENCES collections (id) ON DELETE CASCADE)',
        'CREATE UNIQUE INDEX IF NOT EXISTS uniq_gestion_budget_line_period_category ON gestion_budget_line (period_id, category)',
        'CREATE TABLE IF NOT EXISTS gestion_trip (id SERIAL NOT NULL, name VARCHAR(255) NOT NULL, place VARCHAR(255) DEFAULT NULL, start_date DATE NOT NULL, end_date DATE NOT NULL, budget_total_cents INT NOT NULL, budget_daily_cents INT DEFAULT NULL, budget_scope VARCHAR(10) NOT NULL, currency VARCHAR(3) DEFAULT NULL, exchange_rate NUMERIC(12, 6) DEFAULT NULL, notes TEXT DEFAULT NULL, created_by VARCHAR(255) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))',
        'CREATE INDEX IF NOT EXISTS idx_gestion_trip_dates ON gestion_trip (start_date, end_date)',
        'ALTER TABLE note_de_frais ADD COLUMN IF NOT EXISTS trip_id INT DEFAULT NULL',
        'ALTER TABLE note_de_frais ADD COLUMN IF NOT EXISTS trip_source VARCHAR(10) DEFAULT NULL',
        'ALTER TABLE commande ADD COLUMN IF NOT EXISTS trip_id INT DEFAULT NULL',
        'ALTER TABLE commande ADD COLUMN IF NOT EXISTS trip_source VARCHAR(10) DEFAULT NULL',
        'CREATE INDEX IF NOT EXISTS idx_note_de_frais_trip ON note_de_frais (trip_id)',
        'CREATE INDEX IF NOT EXISTS idx_commande_trip ON commande (trip_id)',
    ];
}
