<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Caisse, lot F10 : grille de favoris (produits épinglés par le responsable, par site ou par poste) et réglages par site
 * (désactivés par défaut). Additif. Équivalent : scripts/migrate_all_v2_pos_favorites.sh.
 */
final class Version20261102100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Caisse F10 : grille de favoris (additif)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE IF NOT EXISTS pos_favorite (id SERIAL PRIMARY KEY, product_id INT NOT NULL REFERENCES product (id) ON DELETE CASCADE,
            product_variant_id INT DEFAULT NULL REFERENCES product_variant (id) ON DELETE CASCADE, cash_register_id INT DEFAULT NULL REFERENCES cash_register (id) ON DELETE CASCADE,
            position INT NOT NULL DEFAULT 0, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT NOW(), created_by_id INT DEFAULT NULL)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_pos_favorite_scope ON pos_favorite (cash_register_id, position)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_pos_favorite_product ON pos_favorite (product_id)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_pos_favorite_variant ON pos_favorite (product_variant_id)');
        $this->addSql('ALTER TABLE entreprise ADD COLUMN IF NOT EXISTS pos_favorites_enabled BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE entreprise ADD COLUMN IF NOT EXISTS pos_favorites_mode VARCHAR(16) DEFAULT \'both\' NOT NULL');
        $this->addSql('ALTER TABLE entreprise ADD COLUMN IF NOT EXISTS pos_favorites_days INT DEFAULT 30 NOT NULL');
        $this->addSql('ALTER TABLE entreprise ADD COLUMN IF NOT EXISTS pos_favorites_grid_size INT DEFAULT 24 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS pos_favorite');
        $this->addSql('ALTER TABLE entreprise DROP COLUMN IF EXISTS pos_favorites_enabled, DROP COLUMN IF EXISTS pos_favorites_mode, DROP COLUMN IF EXISTS pos_favorites_days, DROP COLUMN IF EXISTS pos_favorites_grid_size');
    }
}
