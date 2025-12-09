<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251202155229 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            CREATE SEQUENCE booking_id_seq INCREMENT BY 1 MINVALUE 1 START 1
        SQL);
        $this->addSql(<<<'SQL'
            CREATE SEQUENCE booking_configuration_id_seq INCREMENT BY 1 MINVALUE 1 START 1
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE booking (id INT NOT NULL, product_id INT DEFAULT NULL, start_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, end_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, quantity INT NOT NULL, status VARCHAR(40) NOT NULL, PRIMARY KEY(id))
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX IDX_E00CEDDE4584665A ON booking (product_id)
        SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN booking.start_at IS '(DC2Type:datetime_immutable)'
        SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN booking.end_at IS '(DC2Type:datetime_immutable)'
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE booking_configuration (id INT NOT NULL, product_id INT DEFAULT NULL, granularity VARCHAR(40) NOT NULL, stock_quantity INT NOT NULL, min_duration INT NOT NULL, buffer_time INT NOT NULL, PRIMARY KEY(id))
        SQL);
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX UNIQ_AEDBE5174584665A ON booking_configuration (product_id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE booking ADD CONSTRAINT FK_E00CEDDE4584665A FOREIGN KEY (product_id) REFERENCES product (id) NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE booking_configuration ADD CONSTRAINT FK_AEDBE5174584665A FOREIGN KEY (product_id) REFERENCES product (id) NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE product ADD mode VARCHAR(20) DEFAULT 'retail' NOT NULL
        SQL);
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            CREATE SCHEMA public
        SQL);
        $this->addSql(<<<'SQL'
            DROP SEQUENCE booking_id_seq CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            DROP SEQUENCE booking_configuration_id_seq CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE booking DROP CONSTRAINT FK_E00CEDDE4584665A
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE booking_configuration DROP CONSTRAINT FK_AEDBE5174584665A
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE booking
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE booking_configuration
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE product DROP mode
        SQL);
    }
}
