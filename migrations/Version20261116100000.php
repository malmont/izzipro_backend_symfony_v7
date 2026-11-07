<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Livraison, lot 1 bis (07/11/2026) : poids et dimensions par défaut d'une classe d'expédition, hérités par les produits sans fiche.
 * Additif ; équivalent de scripts/migrate_all_v2_shipping_class_defaults.sh (qui pose aussi les valeurs des classes fournies d'office).
 */
final class Version20261116100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'shipping_class.default_weight_kg / default_length_cm / default_width_cm / default_height_cm ; additif';
    }

    public function up(Schema $schema): void
    {
        foreach (['default_weight_kg', 'default_length_cm', 'default_width_cm', 'default_height_cm'] as $column) {
            $this->addSql(sprintf('ALTER TABLE shipping_class ADD COLUMN IF NOT EXISTS %s DOUBLE PRECISION DEFAULT NULL', $column));
        }
    }

    public function down(Schema $schema): void
    {
        foreach (['default_weight_kg', 'default_length_cm', 'default_width_cm', 'default_height_cm'] as $column) {
            $this->addSql(sprintf('ALTER TABLE shipping_class DROP COLUMN IF EXISTS %s', $column));
        }
    }
}
