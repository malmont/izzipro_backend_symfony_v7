<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261009150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Boutique réglable : livraison des échéances d\'abonnement (subscription.shipping_amount) et combinaison de personnalisation sur les lignes de commande (order_items.customization_id) ; équivalent de scripts/migrate_all_v2_subscription_shipping_customization.sh, idempotent';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE IF EXISTS subscription ADD COLUMN IF NOT EXISTS shipping_amount INT NOT NULL DEFAULT 0');
        $this->addSql('ALTER TABLE IF EXISTS order_items ADD COLUMN IF NOT EXISTS customization_id INT DEFAULT NULL REFERENCES product_customization_image (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_order_items_customization ON order_items (customization_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE IF EXISTS order_items DROP COLUMN IF EXISTS customization_id');
        $this->addSql('ALTER TABLE IF EXISTS subscription DROP COLUMN IF EXISTS shipping_amount');
    }
}
