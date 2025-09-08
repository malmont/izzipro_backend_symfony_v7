<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250908204609 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            CREATE SEQUENCE banniere_translation_id_seq INCREMENT BY 1 MINVALUE 1 START 1
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE banniere_translation (id INT NOT NULL, banniere_id INT DEFAULT NULL, language VARCHAR(10) NOT NULL, titre VARCHAR(255) NOT NULL, texte VARCHAR(255) DEFAULT NULL, PRIMARY KEY(id))
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX IDX_860925535C272687 ON banniere_translation (banniere_id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE banniere_translation ADD CONSTRAINT FK_860925535C272687 FOREIGN KEY (banniere_id) REFERENCES banniere (id) NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            CREATE SCHEMA public
        SQL);
        $this->addSql(<<<'SQL'
            DROP SEQUENCE banniere_translation_id_seq CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE banniere_translation DROP CONSTRAINT FK_860925535C272687
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE banniere_translation
        SQL);
    }
}
