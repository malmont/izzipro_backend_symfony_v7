<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260605182458 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE admin_settings ADD COLUMN IF NOT EXISTS category_selector_component VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE categories ADD COLUMN IF NOT EXISTS sync_web BOOLEAN DEFAULT true NOT NULL');
        $this->addSql('ALTER TABLE categories ADD COLUMN IF NOT EXISTS is_visible BOOLEAN DEFAULT true NOT NULL');
        $this->addSql('ALTER TABLE product ADD COLUMN IF NOT EXISTS gemsuite_web_display BOOLEAN DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE admin_settings DROP COLUMN IF EXISTS category_selector_component');
        $this->addSql('ALTER TABLE product DROP COLUMN IF EXISTS gemsuite_web_display');
        $this->addSql('ALTER TABLE categories DROP COLUMN IF EXISTS sync_web');
        $this->addSql('ALTER TABLE categories DROP COLUMN IF EXISTS is_visible');
    }
}
