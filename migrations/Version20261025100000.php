<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Caisse, chantier C5 partie 2 (25/10/2026) : mode hors ligne. Instantanés des packs, ventes hors ligne reçues (idempotence
 * par terminal), lots de synchronisation, numéro provisoire sur les tickets, date de synchronisation sur les commandes,
 * code-barres de variante, réglages par site. Strictement additif. Équivalent : scripts/migrate_all_v2_pos_offline.sh.
 */
final class Version20261025100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Caisse C5 partie 2 : mode hors ligne (additif)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE product_variant ADD COLUMN IF NOT EXISTS barcode VARCHAR(32) DEFAULT NULL');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_product_variant_barcode ON product_variant (barcode)');
        $this->addSql('ALTER TABLE pos_receipt ADD COLUMN IF NOT EXISTS provisional_number VARCHAR(40) DEFAULT NULL');
        $this->addSql('ALTER TABLE "order" ADD COLUMN IF NOT EXISTS synced_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('CREATE TABLE IF NOT EXISTS pos_offline_pack (id SERIAL PRIMARY KEY, version VARCHAR(64) NOT NULL, etag VARCHAR(64) NOT NULL, generated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, valid_until TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, snapshot JSON NOT NULL)');
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS uniq_pos_offline_pack_version ON pos_offline_pack (version)');
        $this->addSql('CREATE TABLE IF NOT EXISTS pos_offline_sale (id SERIAL PRIMARY KEY, terminal_id INT NOT NULL, idempotency_key VARCHAR(36) NOT NULL, provisional_number VARCHAR(40) NOT NULL, cash_register_id INT DEFAULT NULL, order_id INT DEFAULT NULL, status VARCHAR(12) NOT NULL, result JSON NOT NULL, pack_version VARCHAR(64) DEFAULT NULL, chain_seq INT DEFAULT NULL, chain_hash VARCHAR(64) DEFAULT NULL, price_drift INT DEFAULT 0 NOT NULL, stock_shortfall INT DEFAULT 0 NOT NULL, late_entry BOOLEAN DEFAULT false NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL)');
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS uniq_pos_offline_sale_key ON pos_offline_sale (terminal_id, idempotency_key)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_pos_offline_sale_order ON pos_offline_sale (order_id)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_pos_offline_sale_terminal ON pos_offline_sale (terminal_id, created_at)');
        $this->addSql('CREATE TABLE IF NOT EXISTS pos_offline_sync (id SERIAL PRIMARY KEY, terminal_id INT NOT NULL, synced_by_id INT NOT NULL, count INT DEFAULT 0 NOT NULL, accepted INT DEFAULT 0 NOT NULL, duplicates INT DEFAULT 0 NOT NULL, rejected INT DEFAULT 0 NOT NULL, drift_total INT DEFAULT 0 NOT NULL, chain_broken BOOLEAN DEFAULT false NOT NULL, last_hash VARCHAR(64) DEFAULT NULL, clock_offset_seconds INT DEFAULT NULL, pack_version VARCHAR(64) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_pos_offline_sync_terminal ON pos_offline_sync (terminal_id, created_at)');
        foreach (['pos_offline_enabled BOOLEAN DEFAULT false NOT NULL', 'pos_offline_max_hours INT DEFAULT 24 NOT NULL', 'pos_offline_max_queued_sales INT DEFAULT 100 NOT NULL', 'pos_offline_max_queued_amount INT DEFAULT 500000 NOT NULL', 'pos_offline_price_tolerance INT DEFAULT 5 NOT NULL', 'pos_offline_pack_validity_hours INT DEFAULT 24 NOT NULL'] as $column) {
            $this->addSql('ALTER TABLE entreprise ADD COLUMN IF NOT EXISTS ' . $column);
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS pos_offline_sync');
        $this->addSql('DROP TABLE IF EXISTS pos_offline_sale');
        $this->addSql('DROP TABLE IF EXISTS pos_offline_pack');
        $this->addSql('ALTER TABLE "order" DROP COLUMN IF EXISTS synced_at');
        $this->addSql('ALTER TABLE pos_receipt DROP COLUMN IF EXISTS provisional_number');
        $this->addSql('ALTER TABLE product_variant DROP COLUMN IF EXISTS barcode');
        $this->addSql('ALTER TABLE entreprise DROP COLUMN IF EXISTS pos_offline_enabled, DROP COLUMN IF EXISTS pos_offline_max_hours, DROP COLUMN IF EXISTS pos_offline_max_queued_sales, DROP COLUMN IF EXISTS pos_offline_max_queued_amount, DROP COLUMN IF EXISTS pos_offline_price_tolerance, DROP COLUMN IF EXISTS pos_offline_pack_validity_hours');
    }
}
