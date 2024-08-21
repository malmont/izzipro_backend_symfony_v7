<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20240820222836 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SEQUENCE frais_de_port_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE transporteur_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE TABLE frais_de_port (id INT NOT NULL, commande_id INT DEFAULT NULL, transporteur_id INT DEFAULT NULL, name VARCHAR(255) NOT NULL, facture VARCHAR(255) DEFAULT NULL, image VARCHAR(255) DEFAULT NULL, trachnumber VARCHAR(255) DEFAULT NULL, price DOUBLE PRECISION DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_6FC5088782EA2E54 ON frais_de_port (commande_id)');
        $this->addSql('CREATE INDEX IDX_6FC5088797C86FA4 ON frais_de_port (transporteur_id)');
        $this->addSql('CREATE TABLE transporteur (id INT NOT NULL, name VARCHAR(255) NOT NULL, logo VARCHAR(255) DEFAULT NULL, contact VARCHAR(255) DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('ALTER TABLE frais_de_port ADD CONSTRAINT FK_6FC5088782EA2E54 FOREIGN KEY (commande_id) REFERENCES commande (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE frais_de_port ADD CONSTRAINT FK_6FC5088797C86FA4 FOREIGN KEY (transporteur_id) REFERENCES transporteur (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('DROP SEQUENCE frais_de_port_id_seq CASCADE');
        $this->addSql('DROP SEQUENCE transporteur_id_seq CASCADE');
        $this->addSql('ALTER TABLE frais_de_port DROP CONSTRAINT FK_6FC5088782EA2E54');
        $this->addSql('ALTER TABLE frais_de_port DROP CONSTRAINT FK_6FC5088797C86FA4');
        $this->addSql('DROP TABLE frais_de_port');
        $this->addSql('DROP TABLE transporteur');
    }
}
