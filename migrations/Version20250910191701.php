<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250910191701 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            CREATE SEQUENCE emploi_translation_id_seq INCREMENT BY 1 MINVALUE 1 START 1
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE emploi_translation (id INT NOT NULL, emploi_id INT DEFAULT NULL, language VARCHAR(10) NOT NULL, titre VARCHAR(255) NOT NULL, description TEXT DEFAULT NULL, PRIMARY KEY(id))
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX IDX_1FF2176FEC013E12 ON emploi_translation (emploi_id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE emploi_translation ADD CONSTRAINT FK_1FF2176FEC013E12 FOREIGN KEY (emploi_id) REFERENCES emploi (id) NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            CREATE SCHEMA public
        SQL);
        $this->addSql(<<<'SQL'
            DROP SEQUENCE emploi_translation_id_seq CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE emploi_translation DROP CONSTRAINT FK_1FF2176FEC013E12
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE emploi_translation
        SQL);
    }
}
