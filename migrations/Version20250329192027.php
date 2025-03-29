<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250329192027 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SEQUENCE type_note_de_frais_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE TABLE type_note_de_frais (id INT NOT NULL, name VARCHAR(255) NOT NULL, image VARCHAR(255) DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('ALTER TABLE note_de_frais ADD type_note_de_frais_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE note_de_frais ADD CONSTRAINT FK_E6ECCF53BAFAB15C FOREIGN KEY (type_note_de_frais_id) REFERENCES type_note_de_frais (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX IDX_E6ECCF53BAFAB15C ON note_de_frais (type_note_de_frais_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('ALTER TABLE note_de_frais DROP CONSTRAINT FK_E6ECCF53BAFAB15C');
        $this->addSql('DROP SEQUENCE type_note_de_frais_id_seq CASCADE');
        $this->addSql('DROP TABLE type_note_de_frais');
        $this->addSql('DROP INDEX IDX_E6ECCF53BAFAB15C');
        $this->addSql('ALTER TABLE note_de_frais DROP type_note_de_frais_id');
    }
}
