<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Caisse, lot F9 (27/10/2026) : cartes-cadeaux et avoirs. Tables pos_gift_card et pos_gift_card_transaction, part réglée par
 * carte-cadeau sur la commande, part recréditée et avoir émis sur le retour, type de commande 3 « CarteCadeau », types de
 * transaction de caisse 9 et 10, réglages par site. Strictement additif. Équivalent : scripts/migrate_all_v2_pos_gift_cards.sh.
 */
final class Version20261027100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Caisse F9 : cartes-cadeaux et avoirs (additif)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE IF NOT EXISTS pos_gift_card (id SERIAL PRIMARY KEY, kind VARCHAR(12) NOT NULL, code_hash VARCHAR(64) NOT NULL, last4 VARCHAR(4) NOT NULL, initial_amount INT NOT NULL, balance INT NOT NULL, status VARCHAR(12) DEFAULT \'active\' NOT NULL, expires_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, issued_order_id INT DEFAULT NULL, issued_return_id INT DEFAULT NULL, issued_by_id INT DEFAULT NULL, cash_register_id INT DEFAULT NULL, replaces_id INT DEFAULT NULL, replaced_by_id INT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, canceled_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL)');
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS uniq_pos_gift_card_code ON pos_gift_card (code_hash)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_pos_gift_card_order ON pos_gift_card (issued_order_id)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_pos_gift_card_status ON pos_gift_card (status)');
        $this->addSql('CREATE TABLE IF NOT EXISTS pos_gift_card_transaction (id SERIAL PRIMARY KEY, gift_card_id INT NOT NULL REFERENCES pos_gift_card (id) ON DELETE CASCADE, kind VARCHAR(12) NOT NULL, amount INT NOT NULL, balance_after INT NOT NULL, order_id INT DEFAULT NULL, return_id INT DEFAULT NULL, caisse_id INT DEFAULT NULL, user_id INT DEFAULT NULL, terminal_id INT DEFAULT NULL, approved_by JSON DEFAULT NULL, reason VARCHAR(255) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_pos_gift_card_tx_card ON pos_gift_card_transaction (gift_card_id)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_pos_gift_card_tx_order ON pos_gift_card_transaction (order_id)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_pos_gift_card_tx_session ON pos_gift_card_transaction (caisse_id)');
        $this->addSql('ALTER TABLE "order" ADD COLUMN IF NOT EXISTS gift_card_applied INT DEFAULT NULL');
        $this->addSql('ALTER TABLE pos_return ADD COLUMN IF NOT EXISTS gift_card_refund INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE pos_return ADD COLUMN IF NOT EXISTS store_credit_id INT DEFAULT NULL');
        $this->addSql("INSERT INTO order_type (id, name) VALUES (3, 'CarteCadeau') ON CONFLICT (id) DO NOTHING");
        $this->addSql("SELECT setval(pg_get_serial_sequence('order_type', 'id'), GREATEST((SELECT MAX(id) FROM order_type), 1))");
        $this->addSql("INSERT INTO order_type_translation (id, order_type_id, language, name) SELECT (SELECT COALESCE(MAX(id), 0) + 1 FROM order_type_translation), 3, 'fr', 'CarteCadeau' WHERE NOT EXISTS (SELECT 1 FROM order_type_translation WHERE order_type_id = 3 AND language = 'fr')");
        $this->addSql("INSERT INTO order_type_translation (id, order_type_id, language, name) SELECT (SELECT COALESCE(MAX(id), 0) + 1 FROM order_type_translation), 3, 'en', 'GiftCard' WHERE NOT EXISTS (SELECT 1 FROM order_type_translation WHERE order_type_id = 3 AND language = 'en')");
        $this->addSql("INSERT INTO transaction_type (id, name) VALUES (9, 'Vente carte-cadeau'), (10, 'Remboursement carte-cadeau') ON CONFLICT (id) DO NOTHING");
        $this->addSql("SELECT setval(pg_get_serial_sequence('transaction_type', 'id'), GREATEST((SELECT MAX(id) FROM transaction_type), 1))");
        foreach (['pos_gift_card_enabled BOOLEAN DEFAULT false NOT NULL', 'pos_gift_card_amounts VARCHAR(255) DEFAULT NULL', 'pos_gift_card_expiry_months INT DEFAULT NULL', 'pos_store_credit_enabled BOOLEAN DEFAULT false NOT NULL',
            'pos_store_credit_expiry_months INT DEFAULT NULL', 'pos_gift_card_cash_refund_max INT DEFAULT 500 NOT NULL', 'pos_gift_card_cash_refund_beyond BOOLEAN DEFAULT false NOT NULL'] as $column) {
            $this->addSql('ALTER TABLE entreprise ADD COLUMN IF NOT EXISTS ' . $column);
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS pos_gift_card_transaction');
        $this->addSql('DROP TABLE IF EXISTS pos_gift_card');
        $this->addSql('ALTER TABLE "order" DROP COLUMN IF EXISTS gift_card_applied');
        $this->addSql('ALTER TABLE pos_return DROP COLUMN IF EXISTS gift_card_refund, DROP COLUMN IF EXISTS store_credit_id');
        $this->addSql('ALTER TABLE entreprise DROP COLUMN IF EXISTS pos_gift_card_enabled, DROP COLUMN IF EXISTS pos_gift_card_amounts, DROP COLUMN IF EXISTS pos_gift_card_expiry_months, DROP COLUMN IF EXISTS pos_store_credit_enabled, DROP COLUMN IF EXISTS pos_store_credit_expiry_months, DROP COLUMN IF EXISTS pos_gift_card_cash_refund_max, DROP COLUMN IF EXISTS pos_gift_card_cash_refund_beyond');
    }
}
