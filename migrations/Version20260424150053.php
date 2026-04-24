<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260424150053 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE INDEX idx_product_slug ON product (slug)');
        $this->addSql('CREATE INDEX idx_product_barcode ON product (barcode)');
        $this->addSql('CREATE INDEX idx_product_is_web ON product (is_web)');
        $this->addSql('CREATE INDEX idx_product_is_pos ON product (is_pos)');
        $this->addSql('CREATE INDEX idx_product_is_accessory ON product (is_accessory)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('DROP INDEX idx_product_slug');
        $this->addSql('DROP INDEX idx_product_barcode');
        $this->addSql('DROP INDEX idx_product_is_web');
        $this->addSql('DROP INDEX idx_product_is_pos');
        $this->addSql('DROP INDEX idx_product_is_accessory');
    }
}
