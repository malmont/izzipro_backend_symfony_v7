<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250910202046 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            CREATE SEQUENCE explore_card_translation_id_seq INCREMENT BY 1 MINVALUE 1 START 1
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE explore_card_translation (id INT NOT NULL, explore_card_id INT DEFAULT NULL, language VARCHAR(10) NOT NULL, standard_title TEXT DEFAULT NULL, different_title TEXT DEFAULT NULL, description TEXT DEFAULT NULL, PRIMARY KEY(id))
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX IDX_454B6B4494325F75 ON explore_card_translation (explore_card_id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE explore_card_translation ADD CONSTRAINT FK_454B6B4494325F75 FOREIGN KEY (explore_card_id) REFERENCES explore_card (id) NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            CREATE SCHEMA public
        SQL);
        $this->addSql(<<<'SQL'
            DROP SEQUENCE explore_card_translation_id_seq CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE explore_card_translation DROP CONSTRAINT FK_454B6B4494325F75
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE explore_card_translation
        SQL);
    }
}
