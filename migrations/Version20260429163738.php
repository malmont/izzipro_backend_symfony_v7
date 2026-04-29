<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260429163738 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE "user" ADD COLUMN IF NOT EXISTS license_number VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE "user" ADD COLUMN IF NOT EXISTS license_expiration_date DATE DEFAULT NULL');
        $this->addSql('ALTER TABLE "order" ADD COLUMN IF NOT EXISTS guest_license_number VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE "order" ADD COLUMN IF NOT EXISTS guest_license_expiration_date DATE DEFAULT NULL');
        $this->addSql('ALTER TABLE order_items ADD COLUMN IF NOT EXISTS license_number VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE order_items ADD COLUMN IF NOT EXISTS license_expiration_date DATE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('ALTER TABLE order_items DROP COLUMN IF EXISTS license_number');
        $this->addSql('ALTER TABLE order_items DROP COLUMN IF EXISTS license_expiration_date');
        $this->addSql('ALTER TABLE "order" DROP COLUMN IF EXISTS guest_license_number');
        $this->addSql('ALTER TABLE "order" DROP COLUMN IF EXISTS guest_license_expiration_date');
        $this->addSql('ALTER TABLE "user" DROP COLUMN IF EXISTS license_number');
        $this->addSql('ALTER TABLE "user" DROP COLUMN IF EXISTS license_expiration_date');
    }
}
