<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Caisse, chantier C4 (23/10/2026) : terminal de paiement Stripe. Lecteur par poste (cash_register), Location par site
 * (stripe_config), réglages par site (entreprise, valeurs par défaut = comportement actuel), table pos_terminal_payment.
 * Strictement additif (colonnes nullables ou avec défaut neutre). Équivalent : scripts/migrate_all_v2_pos_terminal.sh.
 */
final class Version20261023100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Caisse C4 : terminal de paiement Stripe (additif)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cash_register ADD COLUMN IF NOT EXISTS terminal_enabled BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE cash_register ADD COLUMN IF NOT EXISTS stripe_reader_id VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE cash_register ADD COLUMN IF NOT EXISTS stripe_reader_label VARCHAR(100) DEFAULT NULL');
        $this->addSql('ALTER TABLE cash_register ADD COLUMN IF NOT EXISTS stripe_reader_simulated BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE stripe_config ADD COLUMN IF NOT EXISTS terminal_location_id VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE entreprise ADD COLUMN IF NOT EXISTS pos_terminal_fee_card_bp INT DEFAULT 100 NOT NULL');
        $this->addSql('ALTER TABLE entreprise ADD COLUMN IF NOT EXISTS pos_terminal_fee_interac_bp INT DEFAULT 100 NOT NULL');
        $this->addSql('ALTER TABLE entreprise ADD COLUMN IF NOT EXISTS pos_terminal_interac BOOLEAN DEFAULT NULL');
        $this->addSql('ALTER TABLE entreprise ADD COLUMN IF NOT EXISTS pos_terminal_card_wait_seconds INT DEFAULT 120 NOT NULL');
        $this->addSql('ALTER TABLE entreprise ADD COLUMN IF NOT EXISTS pos_terminal_auth_cancel_minutes INT DEFAULT 30 NOT NULL');
        $this->addSql('ALTER TABLE entreprise ADD COLUMN IF NOT EXISTS pos_terminal_orphan_minutes INT DEFAULT 5 NOT NULL');
        $this->addSql('ALTER TABLE entreprise ADD COLUMN IF NOT EXISTS pos_terminal_customer_cancel BOOLEAN DEFAULT true NOT NULL');
        $this->addSql('ALTER TABLE entreprise ADD COLUMN IF NOT EXISTS pos_terminal_cart_display BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('CREATE TABLE IF NOT EXISTS pos_terminal_payment (id SERIAL PRIMARY KEY, stripe_payment_intent_id VARCHAR(64) NOT NULL, cash_register_id INT NOT NULL REFERENCES cash_register (id) ON DELETE RESTRICT, caisse_id INT DEFAULT NULL, user_id INT NOT NULL, terminal_id INT DEFAULT NULL, reader_id VARCHAR(64) NOT NULL, amount INT NOT NULL, currency VARCHAR(3) NOT NULL, status VARCHAR(20) NOT NULL, payment_method_type VARCHAR(20) DEFAULT NULL, card_brand VARCHAR(20) DEFAULT NULL, last4 VARCHAR(4) DEFAULT NULL, failure_code VARCHAR(40) DEFAULT NULL, failure_message VARCHAR(255) DEFAULT NULL, quote_total INT NOT NULL, other_payments INT NOT NULL, quote_hash VARCHAR(64) NOT NULL, application_fee INT NOT NULL, idempotency_key VARCHAR(36) NOT NULL, order_id INT DEFAULT NULL, order_attached_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, refund_status VARCHAR(20) DEFAULT NULL, refund_id VARCHAR(64) DEFAULT NULL, refund_amount INT DEFAULT NULL, refund_failure_message VARCHAR(255) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, finished_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL)');
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS uniq_pos_terminal_payment_intent ON pos_terminal_payment (stripe_payment_intent_id)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_pos_terminal_payment_status ON pos_terminal_payment (status, created_at)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_pos_terminal_payment_order ON pos_terminal_payment (order_id)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_pos_terminal_payment_register ON pos_terminal_payment (cash_register_id, created_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS pos_terminal_payment');
        $this->addSql('ALTER TABLE cash_register DROP COLUMN IF EXISTS terminal_enabled, DROP COLUMN IF EXISTS stripe_reader_id, DROP COLUMN IF EXISTS stripe_reader_label, DROP COLUMN IF EXISTS stripe_reader_simulated');
        $this->addSql('ALTER TABLE stripe_config DROP COLUMN IF EXISTS terminal_location_id');
        $this->addSql('ALTER TABLE entreprise DROP COLUMN IF EXISTS pos_terminal_fee_card_bp, DROP COLUMN IF EXISTS pos_terminal_fee_interac_bp, DROP COLUMN IF EXISTS pos_terminal_interac, DROP COLUMN IF EXISTS pos_terminal_card_wait_seconds, DROP COLUMN IF EXISTS pos_terminal_auth_cancel_minutes, DROP COLUMN IF EXISTS pos_terminal_orphan_minutes, DROP COLUMN IF EXISTS pos_terminal_customer_cancel, DROP COLUMN IF EXISTS pos_terminal_cart_display');
    }
}
