<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250512151416 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SEQUENCE shipping_class_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE TABLE shipping_class (id INT NOT NULL, name VARCHAR(100) NOT NULL, description TEXT DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('ALTER TABLE product_shipping ADD shipping_class_entity_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE product_shipping ADD shipping_class VARCHAR(50) DEFAULT NULL');
        $this->addSql('ALTER TABLE product_shipping ADD CONSTRAINT FK_E6AC7DB3335924EB FOREIGN KEY (shipping_class_entity_id) REFERENCES shipping_class (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX IDX_E6AC7DB3335924EB ON product_shipping (shipping_class_entity_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('ALTER TABLE product_shipping DROP CONSTRAINT FK_E6AC7DB3335924EB');
        $this->addSql('DROP SEQUENCE shipping_class_id_seq CASCADE');
        $this->addSql('DROP TABLE shipping_class');
        $this->addSql('DROP INDEX IDX_E6AC7DB3335924EB');
        $this->addSql('ALTER TABLE product_shipping DROP shipping_class_entity_id');
        $this->addSql('ALTER TABLE product_shipping DROP shipping_class');
    }
}
