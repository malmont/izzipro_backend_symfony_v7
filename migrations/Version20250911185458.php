<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250911185458 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            CREATE SEQUENCE status_commande_translation_id_seq INCREMENT BY 1 MINVALUE 1 START 1
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE status_commande_translation (id INT NOT NULL, status_commande_id INT DEFAULT NULL, language VARCHAR(10) NOT NULL, name VARCHAR(255) NOT NULL, description VARCHAR(255) DEFAULT NULL, PRIMARY KEY(id))
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX IDX_C87963E86305BA5 ON status_commande_translation (status_commande_id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE status_commande_translation ADD CONSTRAINT FK_C87963E86305BA5 FOREIGN KEY (status_commande_id) REFERENCES status_commande (id) NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            CREATE SCHEMA public
        SQL);
        $this->addSql(<<<'SQL'
            DROP SEQUENCE status_commande_translation_id_seq CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE status_commande_translation DROP CONSTRAINT FK_C87963E86305BA5
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE status_commande_translation
        SQL);
    }
}
