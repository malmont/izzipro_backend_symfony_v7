<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260504172235 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE "order" ADD COLUMN IF NOT EXISTS guest_license_number VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE "order" ADD COLUMN IF NOT EXISTS guest_license_expiration_date DATE DEFAULT NULL');
        $this->addSql('ALTER TABLE order_items ADD COLUMN IF NOT EXISTS license_number VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE order_items ADD COLUMN IF NOT EXISTS license_expiration_date DATE DEFAULT NULL');
        $this->addSql('ALTER TABLE order_items ADD COLUMN IF NOT EXISTS sale_unit VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE order_items DROP COLUMN IF EXISTS license_number');
        $this->addSql('ALTER TABLE order_items DROP COLUMN IF EXISTS license_expiration_date');
        $this->addSql('ALTER TABLE order_items DROP COLUMN IF EXISTS sale_unit');
        $this->addSql('ALTER TABLE "order" DROP COLUMN IF EXISTS guest_license_number');
        $this->addSql('ALTER TABLE "order" DROP COLUMN IF EXISTS guest_license_expiration_date');
    }
}
