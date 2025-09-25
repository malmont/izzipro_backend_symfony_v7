<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250912141339 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            CREATE SEQUENCE size_translation_id_seq INCREMENT BY 1 MINVALUE 1 START 1
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE size_translation (id INT NOT NULL, size_id INT DEFAULT NULL, language VARCHAR(10) NOT NULL, name VARCHAR(255) NOT NULL, PRIMARY KEY(id))
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX IDX_94DC2F5F498DA827 ON size_translation (size_id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE size_translation ADD CONSTRAINT FK_94DC2F5F498DA827 FOREIGN KEY (size_id) REFERENCES size (id) NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE size ADD code VARCHAR(255) DEFAULT NULL
        SQL);
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            CREATE SCHEMA public
        SQL);
        $this->addSql(<<<'SQL'
            DROP SEQUENCE size_translation_id_seq CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE size_translation DROP CONSTRAINT FK_94DC2F5F498DA827
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE size_translation
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE size DROP code
        SQL);
    }
}
