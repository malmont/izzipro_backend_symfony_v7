<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Caisse, lot F2 (24/10/2026) : retours partiels et échanges. Lien d'une commande de retour et de ses lignes vers la vente
 * d'origine (order.returned_from_id, order_items.returned_from_item_id), crédit d'échange (order.credit_applied), table
 * pos_return, réglages des retours (entreprise), cumul remboursé et validation du remboursement d'un paiement par terminal.
 * Strictement additif, défauts neutres. Équivalent : scripts/migrate_all_v2_pos_returns.sh.
 */
final class Version20261024100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Caisse F2 : retours partiels et échanges (additif)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "order" ADD COLUMN IF NOT EXISTS returned_from_id INT DEFAULT NULL REFERENCES "order" (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE "order" ADD COLUMN IF NOT EXISTS credit_applied INT DEFAULT NULL');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_order_returned_from ON "order" (returned_from_id)');
        $this->addSql('ALTER TABLE order_items ADD COLUMN IF NOT EXISTS returned_from_item_id INT DEFAULT NULL REFERENCES order_items (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_order_items_returned_from ON order_items (returned_from_item_id)');
        $this->addSql('CREATE TABLE IF NOT EXISTS pos_return (id SERIAL PRIMARY KEY, order_id INT DEFAULT NULL REFERENCES "order" (id) ON DELETE SET NULL, return_order_id INT NOT NULL REFERENCES "order" (id) ON DELETE RESTRICT, exchange_order_id INT DEFAULT NULL REFERENCES "order" (id) ON DELETE SET NULL, cash_register_id INT NOT NULL REFERENCES cash_register (id) ON DELETE RESTRICT, caisse_id INT DEFAULT NULL, user_id INT NOT NULL, reason_code VARCHAR(40) DEFAULT NULL, reason_text VARCHAR(500) DEFAULT NULL, restock BOOLEAN DEFAULT true NOT NULL, refund_method VARCHAR(20) NOT NULL, amount INT NOT NULL, cash_refund INT DEFAULT 0 NOT NULL, card_refund INT DEFAULT 0 NOT NULL, cash_rounding INT DEFAULT 0 NOT NULL, credit_applied INT DEFAULT 0 NOT NULL, terminal_payment_id INT DEFAULT NULL, terminal_refund_id VARCHAR(64) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_pos_return_order ON pos_return (order_id)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_pos_return_session ON pos_return (caisse_id)');
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS uniq_pos_return_return_order ON pos_return (return_order_id)');
        foreach (['pos_return_max_days INT DEFAULT NULL', 'pos_return_without_receipt BOOLEAN DEFAULT false NOT NULL', 'pos_return_restock_default BOOLEAN DEFAULT true NOT NULL',
            'pos_return_cash_for_card_sale BOOLEAN DEFAULT false NOT NULL', 'pos_return_reasons TEXT DEFAULT NULL', "pos_approval_return_mode VARCHAR(10) DEFAULT 'refund' NOT NULL", 'pos_approval_return INT DEFAULT NULL'] as $column) {
            $this->addSql('ALTER TABLE entreprise ADD COLUMN IF NOT EXISTS ' . $column);
        }
        $this->addSql('ALTER TABLE pos_terminal_payment ADD COLUMN IF NOT EXISTS refunded_total INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE pos_terminal_payment ADD COLUMN IF NOT EXISTS refund_approved_by JSON DEFAULT NULL');
        $this->addSql("UPDATE pos_terminal_payment SET refunded_total = refund_amount WHERE refund_status = 'succeeded' AND refund_amount IS NOT NULL AND refunded_total = 0");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS pos_return');
        $this->addSql('ALTER TABLE order_items DROP COLUMN IF EXISTS returned_from_item_id');
        $this->addSql('ALTER TABLE "order" DROP COLUMN IF EXISTS returned_from_id, DROP COLUMN IF EXISTS credit_applied');
        $this->addSql('ALTER TABLE entreprise DROP COLUMN IF EXISTS pos_return_max_days, DROP COLUMN IF EXISTS pos_return_without_receipt, DROP COLUMN IF EXISTS pos_return_restock_default, DROP COLUMN IF EXISTS pos_return_cash_for_card_sale, DROP COLUMN IF EXISTS pos_return_reasons, DROP COLUMN IF EXISTS pos_approval_return_mode, DROP COLUMN IF EXISTS pos_approval_return');
        $this->addSql('ALTER TABLE pos_terminal_payment DROP COLUMN IF EXISTS refunded_total, DROP COLUMN IF EXISTS refund_approved_by');
    }
}
