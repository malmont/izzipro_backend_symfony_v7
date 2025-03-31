<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250330223551 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SEQUENCE type_fournisseur_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE TABLE type_fournisseur (id INT NOT NULL, name VARCHAR(255) NOT NULL, photo VARCHAR(255) DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('ALTER TABLE fournisseur ADD type_fournisseur_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE fournisseur ADD CONSTRAINT FK_369ECA3231CF5CEB FOREIGN KEY (type_fournisseur_id) REFERENCES type_fournisseur (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX IDX_369ECA3231CF5CEB ON fournisseur (type_fournisseur_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('ALTER TABLE fournisseur DROP CONSTRAINT FK_369ECA3231CF5CEB');
        $this->addSql('DROP SEQUENCE type_fournisseur_id_seq CASCADE');
        $this->addSql('DROP TABLE type_fournisseur');
        $this->addSql('DROP INDEX IDX_369ECA3231CF5CEB');
        $this->addSql('ALTER TABLE fournisseur DROP type_fournisseur_id');
    }
}
