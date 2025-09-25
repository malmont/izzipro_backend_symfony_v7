<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250910180114 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            CREATE SEQUENCE email_configuration_translation_id_seq INCREMENT BY 1 MINVALUE 1 START 1
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE email_configuration_translation (id INT NOT NULL, email_configuration_id INT DEFAULT NULL, language VARCHAR(10) NOT NULL, from_name VARCHAR(255) NOT NULL, signature TEXT DEFAULT NULL, PRIMARY KEY(id))
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX IDX_A43F35667DCAC56F ON email_configuration_translation (email_configuration_id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE email_configuration_translation ADD CONSTRAINT FK_A43F35667DCAC56F FOREIGN KEY (email_configuration_id) REFERENCES email_configuration (id) NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            CREATE SCHEMA public
        SQL);
        $this->addSql(<<<'SQL'
            DROP SEQUENCE email_configuration_translation_id_seq CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE email_configuration_translation DROP CONSTRAINT FK_A43F35667DCAC56F
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE email_configuration_translation
        SQL);
    }
}
