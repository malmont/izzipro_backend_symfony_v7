<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250515184914 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SEQUENCE address_entreprise_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE TABLE address_entreprise (id INT NOT NULL, entreprise_id INT DEFAULT NULL, street1 VARCHAR(255) NOT NULL, street2 VARCHAR(255) NOT NULL, city VARCHAR(255) NOT NULL, state VARCHAR(255) NOT NULL, zip VARCHAR(255) NOT NULL, country VARCHAR(255) NOT NULL, phone VARCHAR(255) NOT NULL, email VARCHAR(255) NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_758472FEA4AEAFEA ON address_entreprise (entreprise_id)');
        $this->addSql('ALTER TABLE address_entreprise ADD CONSTRAINT FK_758472FEA4AEAFEA FOREIGN KEY (entreprise_id) REFERENCES entreprise (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('DROP SEQUENCE address_entreprise_id_seq CASCADE');
        $this->addSql('ALTER TABLE address_entreprise DROP CONSTRAINT FK_758472FEA4AEAFEA');
        $this->addSql('DROP TABLE address_entreprise');
    }
}
