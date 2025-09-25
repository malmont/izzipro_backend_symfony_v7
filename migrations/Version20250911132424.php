<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250911132424 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            CREATE SEQUENCE home_slider_translation_id_seq INCREMENT BY 1 MINVALUE 1 START 1
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE home_slider_translation (id INT NOT NULL, home_slider_id INT DEFAULT NULL, language VARCHAR(10) NOT NULL, title VARCHAR(255) NOT NULL, description VARCHAR(255) DEFAULT NULL, button_message VARCHAR(255) DEFAULT NULL, button_url VARCHAR(255) DEFAULT NULL, PRIMARY KEY(id))
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX IDX_936290C35218EF7C ON home_slider_translation (home_slider_id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE home_slider_translation ADD CONSTRAINT FK_936290C35218EF7C FOREIGN KEY (home_slider_id) REFERENCES home_slider (id) NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            CREATE SCHEMA public
        SQL);
        $this->addSql(<<<'SQL'
            DROP SEQUENCE home_slider_translation_id_seq CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE home_slider_translation DROP CONSTRAINT FK_936290C35218EF7C
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE home_slider_translation
        SQL);
    }
}
