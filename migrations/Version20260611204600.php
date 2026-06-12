<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260611204600 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SEQUENCE team_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE team_translation_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE TABLE team (id INT NOT NULL, name VARCHAR(255) NOT NULL, image VARCHAR(255) DEFAULT NULL, role VARCHAR(255) NOT NULL, description TEXT DEFAULT NULL, gemsuite_team_id INT DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE TABLE team_translation (id INT NOT NULL, team_id INT DEFAULT NULL, language VARCHAR(10) NOT NULL, role VARCHAR(255) NOT NULL, description TEXT DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_5F59AA22296CD8AE ON team_translation (team_id)');
        $this->addSql('ALTER TABLE team_translation ADD CONSTRAINT FK_5F59AA22296CD8AE FOREIGN KEY (team_id) REFERENCES team (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('DROP SEQUENCE team_id_seq CASCADE');
        $this->addSql('DROP SEQUENCE team_translation_id_seq CASCADE');
        $this->addSql('ALTER TABLE team_translation DROP CONSTRAINT FK_5F59AA22296CD8AE');
        $this->addSql('DROP TABLE team');
        $this->addSql('DROP TABLE team_translation');
    }
}
