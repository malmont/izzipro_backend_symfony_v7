<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20240905150525 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE inventory_movements DROP CONSTRAINT FK_BF9F9C49A80EF684');
        $this->addSql('ALTER TABLE inventory_movements ALTER product_variant_id DROP NOT NULL');
        $this->addSql('ALTER TABLE inventory_movements ADD CONSTRAINT FK_BF9F9C49A80EF684 FOREIGN KEY (product_variant_id) REFERENCES product_variant (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('ALTER TABLE inventory_movements DROP CONSTRAINT fk_bf9f9c49a80ef684');
        $this->addSql('ALTER TABLE inventory_movements ALTER product_variant_id SET NOT NULL');
        $this->addSql('ALTER TABLE inventory_movements ADD CONSTRAINT fk_bf9f9c49a80ef684 FOREIGN KEY (product_variant_id) REFERENCES product_variant (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }
}
