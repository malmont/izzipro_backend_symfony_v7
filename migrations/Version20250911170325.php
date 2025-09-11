<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250911170325 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            CREATE SEQUENCE recherche_translation_id_seq INCREMENT BY 1 MINVALUE 1 START 1
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE recherche_translation (id INT NOT NULL, recherche_id INT DEFAULT NULL, language VARCHAR(10) NOT NULL, titre VARCHAR(255) NOT NULL, texte1 VARCHAR(255) DEFAULT NULL, texte2 VARCHAR(255) DEFAULT NULL, PRIMARY KEY(id))
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX IDX_542F361E6A4A07 ON recherche_translation (recherche_id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE recherche_translation ADD CONSTRAINT FK_542F361E6A4A07 FOREIGN KEY (recherche_id) REFERENCES recherche (id) NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            CREATE SCHEMA public
        SQL);
        $this->addSql(<<<'SQL'
            DROP SEQUENCE recherche_translation_id_seq CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE recherche_translation DROP CONSTRAINT FK_542F361E6A4A07
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE recherche_translation
        SQL);
    }
}
