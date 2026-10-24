<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Caisse, lot A1/S2 (30/10/2026) : instantanés de libellés sur les lignes de commande (rétro-remplis depuis le catalogue actuel :
 * l'historique déjà perdu ne revient pas), durée de conservation et mode d'observation des modifications par site, limite
 * d'essais sur les codes de cartes-cadeaux. Additif. Équivalent : scripts/migrate_all_v2_retention.sh.
 */
final class Version20261030100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Caisse A1/S2 : instantanés de lignes, conservation, limites (additif)';
    }

    public function up(Schema $schema): void
    {
        foreach (['product_name VARCHAR(255) DEFAULT NULL', 'variant_label VARCHAR(255) DEFAULT NULL', 'product_code VARCHAR(64) DEFAULT NULL', 'barcode VARCHAR(64) DEFAULT NULL'] as $column) {
            $this->addSql('ALTER TABLE order_items ADD COLUMN IF NOT EXISTS ' . $column);
        }
        // Rétro-remplissage depuis le catalogue actuel (lignes avec variante, sans instantané)
        $this->addSql('UPDATE order_items i SET product_name = p.name, product_code = p.code, barcode = COALESCE(v.barcode, p.barcode), variant_label = NULLIF(TRIM(CONCAT_WS(\' \', c.name, s.name)), \'\') FROM product_variant v JOIN product p ON p.id = v.product_id LEFT JOIN color c ON c.id = v.color_id LEFT JOIN size s ON s.id = v.size_id WHERE i.product_variant_id = v.id AND i.product_name IS NULL');
        foreach (['data_retention_years INT DEFAULT NULL', 'retention_write_mode VARCHAR(10) DEFAULT \'observe\' NOT NULL', 'pos_gift_card_code_attempts INT DEFAULT 10 NOT NULL'] as $column) {
            $this->addSql('ALTER TABLE entreprise ADD COLUMN IF NOT EXISTS ' . $column);
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE order_items DROP COLUMN IF EXISTS product_name, DROP COLUMN IF EXISTS variant_label, DROP COLUMN IF EXISTS product_code, DROP COLUMN IF EXISTS barcode');
        $this->addSql('ALTER TABLE entreprise DROP COLUMN IF EXISTS data_retention_years, DROP COLUMN IF EXISTS retention_write_mode, DROP COLUMN IF EXISTS pos_gift_card_code_attempts');
    }
}
