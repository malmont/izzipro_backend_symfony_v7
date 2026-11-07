<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Livraison, lot 1 (07/11/2026) : nature d'un transporteur (prix fixe, retrait en magasin, EasyPost), produit à expédier ou non,
 * expédition manuelle d'une commande (date, transporteur, numéro et lien de suivi). Additif ; équivalent de
 * scripts/migrate_all_v2_shipping_lot1.sh.
 */
final class Version20261115100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'carrier.kind, product.requires_shipping, order.shipped_at / tracking_carrier / tracking_number / tracking_url ; additif';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE carrier ADD COLUMN IF NOT EXISTS kind VARCHAR(20) NOT NULL DEFAULT 'fixed'");
        $this->addSql("UPDATE carrier SET kind = 'easypost' WHERE kind = 'fixed' AND carrier_account_id IS NOT NULL AND carrier_account_id <> ''");
        $this->addSql('ALTER TABLE product ADD COLUMN IF NOT EXISTS requires_shipping BOOLEAN NOT NULL DEFAULT TRUE');
        $this->addSql('ALTER TABLE "order" ADD COLUMN IF NOT EXISTS shipped_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE "order" ADD COLUMN IF NOT EXISTS tracking_carrier VARCHAR(100) DEFAULT NULL');
        $this->addSql('ALTER TABLE "order" ADD COLUMN IF NOT EXISTS tracking_number VARCHAR(100) DEFAULT NULL');
        $this->addSql('ALTER TABLE "order" ADD COLUMN IF NOT EXISTS tracking_url VARCHAR(500) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE carrier DROP COLUMN IF EXISTS kind');
        $this->addSql('ALTER TABLE product DROP COLUMN IF EXISTS requires_shipping');
        $this->addSql('ALTER TABLE "order" DROP COLUMN IF EXISTS shipped_at');
        $this->addSql('ALTER TABLE "order" DROP COLUMN IF EXISTS tracking_carrier');
        $this->addSql('ALTER TABLE "order" DROP COLUMN IF EXISTS tracking_number');
        $this->addSql('ALTER TABLE "order" DROP COLUMN IF EXISTS tracking_url');
    }
}
