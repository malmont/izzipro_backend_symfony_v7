<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250509174929 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SEQUENCE packaging_type_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE product_shipping_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE TABLE packaging_type (id INT NOT NULL, name VARCHAR(100) NOT NULL, inner_length DOUBLE PRECISION NOT NULL, inner_width DOUBLE PRECISION NOT NULL, inner_height DOUBLE PRECISION NOT NULL, max_weight DOUBLE PRECISION NOT NULL, volumetric_divisor INT NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE TABLE product_shipping (id INT NOT NULL, product_id INT DEFAULT NULL, weight DOUBLE PRECISION NOT NULL, length DOUBLE PRECISION NOT NULL, width DOUBLE PRECISION DEFAULT NULL, height DOUBLE PRECISION DEFAULT NULL, shipping_class VARCHAR(50) DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_E6AC7DB34584665A ON product_shipping (product_id)');
        $this->addSql('ALTER TABLE product_shipping ADD CONSTRAINT FK_E6AC7DB34584665A FOREIGN KEY (product_id) REFERENCES product (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('DROP SEQUENCE packaging_type_id_seq CASCADE');
        $this->addSql('DROP SEQUENCE product_shipping_id_seq CASCADE');
        $this->addSql('ALTER TABLE product_shipping DROP CONSTRAINT FK_E6AC7DB34584665A');
        $this->addSql('DROP TABLE packaging_type');
        $this->addSql('DROP TABLE product_shipping');
    }
}
