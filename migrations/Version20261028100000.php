<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Caisse, lot F8 (28/10/2026) : fiche client et fidélité. Tables pos_customer, pos_customer_consent, pos_loyalty_transaction ;
 * client rattaché sur la commande et la carte-cadeau ; réglages par site. Strictement additif.
 * Équivalent : scripts/migrate_all_v2_pos_customers.sh.
 */
final class Version20261028100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Caisse F8 : fiche client et fidélité (additif)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE IF NOT EXISTS pos_customer (id SERIAL PRIMARY KEY, first_name VARCHAR(80) DEFAULT NULL, last_name VARCHAR(80) DEFAULT NULL, phone VARCHAR(20) DEFAULT NULL, email VARCHAR(180) DEFAULT NULL, user_id INT DEFAULT NULL, loyalty_code VARCHAR(20) DEFAULT NULL, loyalty_points INT DEFAULT 0 NOT NULL, loyalty_last_activity_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_by_id INT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, erased_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, merged_into_id INT DEFAULT NULL)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_pos_customer_phone ON pos_customer (phone)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_pos_customer_email ON pos_customer (email)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_pos_customer_user ON pos_customer (user_id)');
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS uniq_pos_customer_loyalty_code ON pos_customer (loyalty_code)');
        $this->addSql('CREATE TABLE IF NOT EXISTS pos_customer_consent (id SERIAL PRIMARY KEY, customer_id INT NOT NULL REFERENCES pos_customer (id) ON DELETE CASCADE, purpose VARCHAR(20) NOT NULL, granted BOOLEAN NOT NULL, at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, by_user_id INT DEFAULT NULL, text_version VARCHAR(40) DEFAULT NULL, source VARCHAR(12) DEFAULT \'pos\' NOT NULL)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_pos_customer_consent_customer ON pos_customer_consent (customer_id, purpose)');
        $this->addSql('CREATE TABLE IF NOT EXISTS pos_loyalty_transaction (id SERIAL PRIMARY KEY, customer_id INT NOT NULL REFERENCES pos_customer (id) ON DELETE CASCADE, kind VARCHAR(12) NOT NULL, points INT NOT NULL, balance_after INT NOT NULL, amount INT DEFAULT NULL, order_id INT DEFAULT NULL, return_id INT DEFAULT NULL, caisse_id INT DEFAULT NULL, user_id INT DEFAULT NULL, approved_by JSON DEFAULT NULL, reason VARCHAR(255) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_pos_loyalty_tx_customer ON pos_loyalty_transaction (customer_id)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_pos_loyalty_tx_order ON pos_loyalty_transaction (order_id)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_pos_loyalty_tx_session ON pos_loyalty_transaction (caisse_id)');
        $this->addSql('ALTER TABLE "order" ADD COLUMN IF NOT EXISTS customer_id INT DEFAULT NULL');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_order_customer ON "order" (customer_id)');
        $this->addSql('ALTER TABLE pos_gift_card ADD COLUMN IF NOT EXISTS customer_id INT DEFAULT NULL');
        foreach (['pos_customers_enabled BOOLEAN DEFAULT true NOT NULL', 'pos_customer_link_enabled BOOLEAN DEFAULT true NOT NULL', 'pos_loyalty_enabled BOOLEAN DEFAULT false NOT NULL', 'pos_loyalty_points_per_dollar INT DEFAULT 1 NOT NULL',
            'pos_loyalty_value_per100 INT DEFAULT 100 NOT NULL', 'pos_loyalty_min_redeem INT DEFAULT 500 NOT NULL', 'pos_loyalty_redeem_step INT DEFAULT 100 NOT NULL', 'pos_loyalty_expiry_months INT DEFAULT NULL', 'pos_consent_text_version VARCHAR(40) DEFAULT NULL', 'pos_customer_search_per_minute INT DEFAULT 30 NOT NULL'] as $column) {
            $this->addSql('ALTER TABLE entreprise ADD COLUMN IF NOT EXISTS ' . $column);
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS pos_loyalty_transaction');
        $this->addSql('DROP TABLE IF EXISTS pos_customer_consent');
        $this->addSql('DROP TABLE IF EXISTS pos_customer');
        $this->addSql('ALTER TABLE "order" DROP COLUMN IF EXISTS customer_id');
        $this->addSql('ALTER TABLE pos_gift_card DROP COLUMN IF EXISTS customer_id');
        $this->addSql('ALTER TABLE entreprise DROP COLUMN IF EXISTS pos_customers_enabled, DROP COLUMN IF EXISTS pos_customer_link_enabled, DROP COLUMN IF EXISTS pos_loyalty_enabled, DROP COLUMN IF EXISTS pos_loyalty_points_per_dollar, DROP COLUMN IF EXISTS pos_loyalty_value_per100, DROP COLUMN IF EXISTS pos_loyalty_min_redeem, DROP COLUMN IF EXISTS pos_loyalty_redeem_step, DROP COLUMN IF EXISTS pos_loyalty_expiry_months, DROP COLUMN IF EXISTS pos_consent_text_version, DROP COLUMN IF EXISTS pos_customer_search_per_minute');
    }
}
