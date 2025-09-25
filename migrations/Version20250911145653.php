<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250911145653 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            CREATE SEQUENCE payment_method_translation_id_seq INCREMENT BY 1 MINVALUE 1 START 1
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE payment_method_translation (id INT NOT NULL, payment_method_id INT DEFAULT NULL, language VARCHAR(10) NOT NULL, name VARCHAR(255) NOT NULL, description VARCHAR(255) DEFAULT NULL, PRIMARY KEY(id))
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX IDX_3D44EBAB5AA1164F ON payment_method_translation (payment_method_id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE payment_method_translation ADD CONSTRAINT FK_3D44EBAB5AA1164F FOREIGN KEY (payment_method_id) REFERENCES payment_method (id) NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            CREATE SCHEMA public
        SQL);
        $this->addSql(<<<'SQL'
            DROP SEQUENCE payment_method_translation_id_seq CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE payment_method_translation DROP CONSTRAINT FK_3D44EBAB5AA1164F
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE payment_method_translation
        SQL);
    }
}
