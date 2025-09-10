<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250910141311 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            CREATE SEQUENCE categorie_marque_translation_id_seq INCREMENT BY 1 MINVALUE 1 START 1
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE categorie_marque_translation (id INT NOT NULL, categorie_marque_id INT DEFAULT NULL, language VARCHAR(10) NOT NULL, nom VARCHAR(255) NOT NULL, PRIMARY KEY(id))
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX IDX_4116A215E578B6B5 ON categorie_marque_translation (categorie_marque_id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE categorie_marque_translation ADD CONSTRAINT FK_4116A215E578B6B5 FOREIGN KEY (categorie_marque_id) REFERENCES categorie_marque (id) NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            CREATE SCHEMA public
        SQL);
        $this->addSql(<<<'SQL'
            DROP SEQUENCE categorie_marque_translation_id_seq CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE categorie_marque_translation DROP CONSTRAINT FK_4116A215E578B6B5
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE categorie_marque_translation
        SQL);
    }
}
