<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250910194735 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            CREATE SEQUENCE entreprise_translation_id_seq INCREMENT BY 1 MINVALUE 1 START 1
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE entreprise_translation (id INT NOT NULL, entreprise_id INT DEFAULT NULL, language VARCHAR(10) NOT NULL, condition_of_use TEXT DEFAULT NULL, legal_notice TEXT DEFAULT NULL, privacy_policy TEXT DEFAULT NULL, apropos TEXT DEFAULT NULL, PRIMARY KEY(id))
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX IDX_831EE904A4AEAFEA ON entreprise_translation (entreprise_id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE entreprise_translation ADD CONSTRAINT FK_831EE904A4AEAFEA FOREIGN KEY (entreprise_id) REFERENCES entreprise (id) NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            CREATE SCHEMA public
        SQL);
        $this->addSql(<<<'SQL'
            DROP SEQUENCE entreprise_translation_id_seq CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE entreprise_translation DROP CONSTRAINT FK_831EE904A4AEAFEA
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE entreprise_translation
        SQL);
    }
}
