<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20240817135625 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SEQUENCE note_de_frais_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE TABLE note_de_frais (id INT NOT NULL, collection_id INT DEFAULT NULL, name VARCHAR(255) NOT NULL, description VARCHAR(255) DEFAULT NULL, image_ndf VARCHAR(255) NOT NULL, montant DOUBLE PRECISION NOT NULL, date DATE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_E6ECCF53514956FD ON note_de_frais (collection_id)');
        $this->addSql('ALTER TABLE note_de_frais ADD CONSTRAINT FK_E6ECCF53514956FD FOREIGN KEY (collection_id) REFERENCES collections (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('DROP SEQUENCE note_de_frais_id_seq CASCADE');
        $this->addSql('ALTER TABLE note_de_frais DROP CONSTRAINT FK_E6ECCF53514956FD');
        $this->addSql('DROP TABLE note_de_frais');
    }
}
