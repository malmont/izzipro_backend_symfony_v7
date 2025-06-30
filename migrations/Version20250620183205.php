<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250620183205 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            CREATE SEQUENCE product_option_id_seq INCREMENT BY 1 MINVALUE 1 START 1
        SQL);
        $this->addSql(<<<'SQL'
            CREATE SEQUENCE product_option_value_id_seq INCREMENT BY 1 MINVALUE 1 START 1
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE product_option (id INT NOT NULL, name VARCHAR(255) NOT NULL, PRIMARY KEY(id))
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE product_option_value (id INT NOT NULL, product_option_id INT DEFAULT NULL, value VARCHAR(255) NOT NULL, PRIMARY KEY(id))
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX IDX_A938C737C964ABE2 ON product_option_value (product_option_id)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE product_variant_product_option_value (product_variant_id INT NOT NULL, product_option_value_id INT NOT NULL, PRIMARY KEY(product_variant_id, product_option_value_id))
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX IDX_612C6EDDA80EF684 ON product_variant_product_option_value (product_variant_id)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX IDX_612C6EDDEBDCCF9B ON product_variant_product_option_value (product_option_value_id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE product_option_value ADD CONSTRAINT FK_A938C737C964ABE2 FOREIGN KEY (product_option_id) REFERENCES product_option (id) NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE product_variant_product_option_value ADD CONSTRAINT FK_612C6EDDA80EF684 FOREIGN KEY (product_variant_id) REFERENCES product_variant (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE product_variant_product_option_value ADD CONSTRAINT FK_612C6EDDEBDCCF9B FOREIGN KEY (product_option_value_id) REFERENCES product_option_value (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE "user" ALTER id DROP DEFAULT
        SQL);
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            CREATE SCHEMA public
        SQL);
        $this->addSql(<<<'SQL'
            DROP SEQUENCE product_option_id_seq CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            DROP SEQUENCE product_option_value_id_seq CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE product_option_value DROP CONSTRAINT FK_A938C737C964ABE2
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE product_variant_product_option_value DROP CONSTRAINT FK_612C6EDDA80EF684
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE product_variant_product_option_value DROP CONSTRAINT FK_612C6EDDEBDCCF9B
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE product_option
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE product_option_value
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE product_variant_product_option_value
        SQL);
        $this->addSql(<<<'SQL'
            CREATE SEQUENCE user_id_seq
        SQL);
        $this->addSql(<<<'SQL'
            SELECT setval('user_id_seq', (SELECT MAX(id) FROM "user"))
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE "user" ALTER id SET DEFAULT nextval('user_id_seq')
        SQL);
    }
}
