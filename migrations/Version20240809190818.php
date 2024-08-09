<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20240809190818 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SEQUENCE color_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE product_variant_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE size_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE style_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE TABLE color (id INT NOT NULL, name VARCHAR(255) NOT NULL, code_hexa VARCHAR(255) DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE TABLE product_variant (id INT NOT NULL, color_id INT DEFAULT NULL, size_id INT DEFAULT NULL, product_id INT DEFAULT NULL, stock_quantity INT NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_209AA41D7ADA1FB5 ON product_variant (color_id)');
        $this->addSql('CREATE INDEX IDX_209AA41D498DA827 ON product_variant (size_id)');
        $this->addSql('CREATE INDEX IDX_209AA41D4584665A ON product_variant (product_id)');
        $this->addSql('CREATE TABLE size (id INT NOT NULL, name VARCHAR(255) NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE TABLE style (id INT NOT NULL, name VARCHAR(255) NOT NULL, PRIMARY KEY(id))');
        $this->addSql('ALTER TABLE product_variant ADD CONSTRAINT FK_209AA41D7ADA1FB5 FOREIGN KEY (color_id) REFERENCES color (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE product_variant ADD CONSTRAINT FK_209AA41D498DA827 FOREIGN KEY (size_id) REFERENCES size (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE product_variant ADD CONSTRAINT FK_209AA41D4584665A FOREIGN KEY (product_id) REFERENCES product (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE product ADD style_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE product ADD commande_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE product ADD purchase_price DOUBLE PRECISION DEFAULT NULL');
        $this->addSql('ALTER TABLE product ADD coefficient_multiplier DOUBLE PRECISION DEFAULT NULL');
        $this->addSql('ALTER TABLE product ADD barcode VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE product ADD CONSTRAINT FK_D34A04ADBACD6074 FOREIGN KEY (style_id) REFERENCES style (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE product ADD CONSTRAINT FK_D34A04AD82EA2E54 FOREIGN KEY (commande_id) REFERENCES commande (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX IDX_D34A04ADBACD6074 ON product (style_id)');
        $this->addSql('CREATE INDEX IDX_D34A04AD82EA2E54 ON product (commande_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('ALTER TABLE product DROP CONSTRAINT FK_D34A04ADBACD6074');
        $this->addSql('DROP SEQUENCE color_id_seq CASCADE');
        $this->addSql('DROP SEQUENCE product_variant_id_seq CASCADE');
        $this->addSql('DROP SEQUENCE size_id_seq CASCADE');
        $this->addSql('DROP SEQUENCE style_id_seq CASCADE');
        $this->addSql('ALTER TABLE product_variant DROP CONSTRAINT FK_209AA41D7ADA1FB5');
        $this->addSql('ALTER TABLE product_variant DROP CONSTRAINT FK_209AA41D498DA827');
        $this->addSql('ALTER TABLE product_variant DROP CONSTRAINT FK_209AA41D4584665A');
        $this->addSql('DROP TABLE color');
        $this->addSql('DROP TABLE product_variant');
        $this->addSql('DROP TABLE size');
        $this->addSql('DROP TABLE style');
        $this->addSql('ALTER TABLE product DROP CONSTRAINT FK_D34A04AD82EA2E54');
        $this->addSql('DROP INDEX IDX_D34A04ADBACD6074');
        $this->addSql('DROP INDEX IDX_D34A04AD82EA2E54');
        $this->addSql('ALTER TABLE product DROP style_id');
        $this->addSql('ALTER TABLE product DROP commande_id');
        $this->addSql('ALTER TABLE product DROP purchase_price');
        $this->addSql('ALTER TABLE product DROP coefficient_multiplier');
        $this->addSql('ALTER TABLE product DROP barcode');
    }
}
