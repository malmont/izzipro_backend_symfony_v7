<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250911141216 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            CREATE SEQUENCE order_type_translation_id_seq INCREMENT BY 1 MINVALUE 1 START 1
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE order_type_translation (id INT NOT NULL, order_type_id INT DEFAULT NULL, language VARCHAR(10) NOT NULL, name VARCHAR(255) NOT NULL, description VARCHAR(255) DEFAULT NULL, PRIMARY KEY(id))
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX IDX_9D6686E2333625D8 ON order_type_translation (order_type_id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE order_type_translation ADD CONSTRAINT FK_9D6686E2333625D8 FOREIGN KEY (order_type_id) REFERENCES order_type (id) NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            CREATE SCHEMA public
        SQL);
        $this->addSql(<<<'SQL'
            DROP SEQUENCE order_type_translation_id_seq CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE order_type_translation DROP CONSTRAINT FK_9D6686E2333625D8
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE order_type_translation
        SQL);
    }
}
