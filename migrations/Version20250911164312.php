<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250911164312 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            CREATE SEQUENCE presentation_group_translation_id_seq INCREMENT BY 1 MINVALUE 1 START 1
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE presentation_group_translation (id INT NOT NULL, presentation_group_id INT DEFAULT NULL, language VARCHAR(10) NOT NULL, titre VARCHAR(255) NOT NULL, PRIMARY KEY(id))
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX IDX_A68275A4EC7E4357 ON presentation_group_translation (presentation_group_id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE presentation_group_translation ADD CONSTRAINT FK_A68275A4EC7E4357 FOREIGN KEY (presentation_group_id) REFERENCES presentation_group (id) NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            CREATE SCHEMA public
        SQL);
        $this->addSql(<<<'SQL'
            DROP SEQUENCE presentation_group_translation_id_seq CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE presentation_group_translation DROP CONSTRAINT FK_A68275A4EC7E4357
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE presentation_group_translation
        SQL);
    }
}
