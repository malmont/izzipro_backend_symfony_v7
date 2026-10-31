<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261106100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Profils des applications : apparence de l\'application e-commerce mobile (accent_color, theme_mode, theme_font, theme_radius, button_style, product_card, home_hero sur app_profile ; additif, aucune donnée touchée ; équivalent de scripts/migrate_all_v2_app_theme.sh)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_profile ADD COLUMN IF NOT EXISTS accent_color VARCHAR(7) DEFAULT NULL, ADD COLUMN IF NOT EXISTS theme_mode VARCHAR(10) DEFAULT NULL, ADD COLUMN IF NOT EXISTS theme_font VARCHAR(30) DEFAULT NULL, ADD COLUMN IF NOT EXISTS theme_radius SMALLINT DEFAULT NULL, ADD COLUMN IF NOT EXISTS button_style VARCHAR(10) DEFAULT NULL, ADD COLUMN IF NOT EXISTS product_card VARCHAR(10) DEFAULT NULL, ADD COLUMN IF NOT EXISTS home_hero VARCHAR(10) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_profile DROP COLUMN IF EXISTS accent_color, DROP COLUMN IF EXISTS theme_mode, DROP COLUMN IF EXISTS theme_font, DROP COLUMN IF EXISTS theme_radius, DROP COLUMN IF EXISTS button_style, DROP COLUMN IF EXISTS product_card, DROP COLUMN IF EXISTS home_hero');
    }
}
