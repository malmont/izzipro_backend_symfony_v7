<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Caisse, lot P2-a (26/10/2026) : article libre et prix manuel (F7). Lignes de commande : libellé libre, taxable, prix
 * catalogue et motif du prix manuel ; validations groupées (jeton couvrant plusieurs actions) ; réglages par site.
 * Strictement additif. Équivalent : scripts/migrate_all_v2_pos_custom_items.sh.
 */
final class Version20261026100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Caisse P2-a : article libre, prix manuel, validations groupées (additif)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE order_items ADD COLUMN IF NOT EXISTS custom_label VARCHAR(120) DEFAULT NULL');
        $this->addSql('ALTER TABLE order_items ADD COLUMN IF NOT EXISTS taxable BOOLEAN DEFAULT true NOT NULL');
        $this->addSql('ALTER TABLE order_items ADD COLUMN IF NOT EXISTS catalog_unit_price INT DEFAULT NULL');
        $this->addSql('ALTER TABLE order_items ADD COLUMN IF NOT EXISTS price_reason_code VARCHAR(40) DEFAULT NULL');
        $this->addSql('ALTER TABLE order_items ADD COLUMN IF NOT EXISTS price_reason_text VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE pos_approval ADD COLUMN IF NOT EXISTS actions JSON DEFAULT NULL');
        foreach (['pos_custom_item_enabled BOOLEAN DEFAULT false NOT NULL', 'pos_custom_item_max_amount INT DEFAULT NULL', 'pos_price_override_enabled BOOLEAN DEFAULT false NOT NULL',
            'pos_price_override_max_amount INT DEFAULT NULL', 'pos_price_override_reason_required BOOLEAN DEFAULT true NOT NULL', 'pos_price_override_reasons TEXT DEFAULT NULL'] as $column) {
            $this->addSql('ALTER TABLE entreprise ADD COLUMN IF NOT EXISTS ' . $column);
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE order_items DROP COLUMN IF EXISTS custom_label, DROP COLUMN IF EXISTS taxable, DROP COLUMN IF EXISTS catalog_unit_price, DROP COLUMN IF EXISTS price_reason_code, DROP COLUMN IF EXISTS price_reason_text');
        $this->addSql('ALTER TABLE pos_approval DROP COLUMN IF EXISTS actions');
        $this->addSql('ALTER TABLE entreprise DROP COLUMN IF EXISTS pos_custom_item_enabled, DROP COLUMN IF EXISTS pos_custom_item_max_amount, DROP COLUMN IF EXISTS pos_price_override_enabled, DROP COLUMN IF EXISTS pos_price_override_max_amount, DROP COLUMN IF EXISTS pos_price_override_reason_required, DROP COLUMN IF EXISTS pos_price_override_reasons');
    }
}
