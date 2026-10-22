<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Caisse, lot F1 (24/10/2026) : remises de caisse. Remises par ligne (order_items.discounts, discount_amount), remise de
 * ticket (order.discount_*), réglages par site (entreprise). Strictement additif, défauts neutres (0 / null).
 * Équivalent : scripts/migrate_all_v2_pos_discounts.sh.
 */
final class Version20261024120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Caisse F1 : remises de caisse (additif)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE order_items ADD COLUMN IF NOT EXISTS discounts JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE order_items ADD COLUMN IF NOT EXISTS discount_amount INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE "order" ADD COLUMN IF NOT EXISTS discount_amount INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE "order" ADD COLUMN IF NOT EXISTS discount_percent_bp INT DEFAULT NULL');
        $this->addSql('ALTER TABLE "order" ADD COLUMN IF NOT EXISTS discount_reason_code VARCHAR(40) DEFAULT NULL');
        $this->addSql('ALTER TABLE "order" ADD COLUMN IF NOT EXISTS discount_reason_text VARCHAR(500) DEFAULT NULL');
        foreach (['pos_discount_enabled BOOLEAN DEFAULT true NOT NULL', 'pos_discount_ticket_enabled BOOLEAN DEFAULT true NOT NULL', 'pos_discount_cashier_max_percent_bp INT DEFAULT 1000 NOT NULL', 'pos_discount_cashier_max_amount INT DEFAULT 2000 NOT NULL',
            'pos_discount_manager_max_percent_bp INT DEFAULT 5000 NOT NULL', 'pos_discount_manager_max_amount INT DEFAULT 20000 NOT NULL', 'pos_discount_reason_required BOOLEAN DEFAULT true NOT NULL', 'pos_discount_reasons TEXT DEFAULT NULL'] as $column) {
            $this->addSql('ALTER TABLE entreprise ADD COLUMN IF NOT EXISTS ' . $column);
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE order_items DROP COLUMN IF EXISTS discounts, DROP COLUMN IF EXISTS discount_amount');
        $this->addSql('ALTER TABLE "order" DROP COLUMN IF EXISTS discount_amount, DROP COLUMN IF EXISTS discount_percent_bp, DROP COLUMN IF EXISTS discount_reason_code, DROP COLUMN IF EXISTS discount_reason_text');
        $this->addSql('ALTER TABLE entreprise DROP COLUMN IF EXISTS pos_discount_enabled, DROP COLUMN IF EXISTS pos_discount_ticket_enabled, DROP COLUMN IF EXISTS pos_discount_cashier_max_percent_bp, DROP COLUMN IF EXISTS pos_discount_cashier_max_amount, DROP COLUMN IF EXISTS pos_discount_manager_max_percent_bp, DROP COLUMN IF EXISTS pos_discount_manager_max_amount, DROP COLUMN IF EXISTS pos_discount_reason_required, DROP COLUMN IF EXISTS pos_discount_reasons');
    }
}
