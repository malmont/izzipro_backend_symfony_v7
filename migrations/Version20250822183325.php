<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250822183325 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            CREATE SEQUENCE product_type_id_seq INCREMENT BY 1 MINVALUE 1 START 1
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE product_type (id INT NOT NULL, specifications_template JSON DEFAULT NULL, PRIMARY KEY(id))
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE product ADD product_type_id INT DEFAULT NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE product ADD CONSTRAINT FK_D34A04AD14959723 FOREIGN KEY (product_type_id) REFERENCES product_type (id) NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX IDX_D34A04AD14959723 ON product (product_type_id)
        SQL);
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            CREATE SCHEMA public
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE product DROP CONSTRAINT FK_D34A04AD14959723
        SQL);
        $this->addSql(<<<'SQL'
            DROP SEQUENCE product_type_id_seq CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE product_type
        SQL);
        $this->addSql(<<<'SQL'
            DROP INDEX IDX_D34A04AD14959723
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE product DROP product_type_id
        SQL);
    }
}
