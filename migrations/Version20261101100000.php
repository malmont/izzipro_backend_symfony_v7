<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Caisse, lot F17 (01/11/2026) : coupons de fabricant (bons remboursables par un tiers, LTA art. 181(2) et TVQ : taxes sur le prix
 * plein, le coupon vaut paiement). Table des coupons acceptés, part coupon de la commande, réglages par site. Additif.
 * Équivalent : scripts/migrate_all_v2_pos_coupons.sh.
 */
final class Version20261101100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Caisse F17 : coupons de fabricant (additif)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE IF NOT EXISTS pos_manufacturer_coupon (id SERIAL PRIMARY KEY, order_id INT NOT NULL, order_item_id INT DEFAULT NULL, cash_register_id INT DEFAULT NULL, caisse_id INT DEFAULT NULL, user_id INT DEFAULT NULL,
            manufacturer VARCHAR(100) NOT NULL, company_prefix VARCHAR(12) DEFAULT NULL, offer_code VARCHAR(12) DEFAULT NULL, serial VARCHAR(32) DEFAULT NULL, barcode VARCHAR(80) DEFAULT NULL, format VARCHAR(20) DEFAULT NULL,
            amount INT NOT NULL, source VARCHAR(8) NOT NULL DEFAULT \'manual\', status VARCHAR(24) NOT NULL DEFAULT \'redeemed\', expires_at DATE DEFAULT NULL,
            redeemed_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, claimed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, claim_batch VARCHAR(40) DEFAULT NULL,
            voided_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, voided_caisse_id INT DEFAULT NULL, return_order_id INT DEFAULT NULL)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_pos_mfr_coupon_order ON pos_manufacturer_coupon (order_id)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_pos_mfr_coupon_claims ON pos_manufacturer_coupon (manufacturer, status, redeemed_at)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_pos_mfr_coupon_serial ON pos_manufacturer_coupon (company_prefix, offer_code, serial)');
        $this->addSql('ALTER TABLE "order" ADD COLUMN IF NOT EXISTS manufacturer_coupon_applied INT DEFAULT NULL');
        $this->addSql('ALTER TABLE entreprise ADD COLUMN IF NOT EXISTS pos_coupon_enabled BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE entreprise ADD COLUMN IF NOT EXISTS pos_coupon_max_without_approval INT DEFAULT 2000 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS pos_manufacturer_coupon');
        $this->addSql('ALTER TABLE "order" DROP COLUMN IF EXISTS manufacturer_coupon_applied');
        $this->addSql('ALTER TABLE entreprise DROP COLUMN IF EXISTS pos_coupon_enabled, DROP COLUMN IF EXISTS pos_coupon_max_without_approval');
    }
}
