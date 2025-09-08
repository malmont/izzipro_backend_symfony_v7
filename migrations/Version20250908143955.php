<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250908143955 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            CREATE SEQUENCE baniere_statique_translation_id_seq INCREMENT BY 1 MINVALUE 1 START 1
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE baniere_statique_translation (id INT NOT NULL, baniere_statique_id INT DEFAULT NULL, titre VARCHAR(255) NOT NULL, texte TEXT DEFAULT NULL, texte_bouton VARCHAR(255) DEFAULT NULL, PRIMARY KEY(id))
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX IDX_73A87D2EBD239F01 ON baniere_statique_translation (baniere_statique_id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE baniere_statique_translation ADD CONSTRAINT FK_73A87D2EBD239F01 FOREIGN KEY (baniere_statique_id) REFERENCES baniere_statique (id) NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            CREATE SCHEMA public
        SQL);
        $this->addSql(<<<'SQL'
            DROP SEQUENCE baniere_statique_translation_id_seq CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE baniere_statique_translation DROP CONSTRAINT FK_73A87D2EBD239F01
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE baniere_statique_translation
        SQL);
    }
}
