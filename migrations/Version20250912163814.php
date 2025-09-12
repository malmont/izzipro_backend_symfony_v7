<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250912163814 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            CREATE SEQUENCE product_option_translation_id_seq INCREMENT BY 1 MINVALUE 1 START 1
        SQL);
        $this->addSql(<<<'SQL'
            CREATE SEQUENCE product_option_value_translation_id_seq INCREMENT BY 1 MINVALUE 1 START 1
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE product_option_translation (id INT NOT NULL, product_option_id INT DEFAULT NULL, language VARCHAR(10) NOT NULL, name VARCHAR(255) NOT NULL, PRIMARY KEY(id))
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX IDX_608B99A7C964ABE2 ON product_option_translation (product_option_id)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE product_option_value_translation (id INT NOT NULL, product_option_value_id INT DEFAULT NULL, language VARCHAR(10) NOT NULL, value VARCHAR(255) NOT NULL, PRIMARY KEY(id))
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX IDX_31D4E792EBDCCF9B ON product_option_value_translation (product_option_value_id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE product_option_translation ADD CONSTRAINT FK_608B99A7C964ABE2 FOREIGN KEY (product_option_id) REFERENCES product_option (id) NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE product_option_value_translation ADD CONSTRAINT FK_31D4E792EBDCCF9B FOREIGN KEY (product_option_value_id) REFERENCES product_option_value (id) NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE product_option ADD code VARCHAR(255) DEFAULT NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE product_option_value ADD code VARCHAR(255) DEFAULT NULL
        SQL);
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            CREATE SCHEMA public
        SQL);
        $this->addSql(<<<'SQL'
            DROP SEQUENCE product_option_translation_id_seq CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            DROP SEQUENCE product_option_value_translation_id_seq CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE product_option_translation DROP CONSTRAINT FK_608B99A7C964ABE2
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE product_option_value_translation DROP CONSTRAINT FK_31D4E792EBDCCF9B
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE product_option_translation
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE product_option_value_translation
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE product_option DROP code
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE product_option_value DROP code
        SQL);
    }
}
