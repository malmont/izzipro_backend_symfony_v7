<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260423173916 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SEQUENCE rental_pack_translation_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE TABLE rental_pack_translation (id INT NOT NULL, rental_pack_id INT DEFAULT NULL, language VARCHAR(10) NOT NULL, name VARCHAR(255) NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_C61741F4CAFF68F8 ON rental_pack_translation (rental_pack_id)');
        $this->addSql('ALTER TABLE rental_pack_translation ADD CONSTRAINT FK_C61741F4CAFF68F8 FOREIGN KEY (rental_pack_id) REFERENCES rental_pack (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('DROP SEQUENCE rental_pack_translation_id_seq CASCADE');
        $this->addSql('ALTER TABLE rental_pack_translation DROP CONSTRAINT FK_C61741F4CAFF68F8');
        $this->addSql('DROP TABLE rental_pack_translation');
    }
}
