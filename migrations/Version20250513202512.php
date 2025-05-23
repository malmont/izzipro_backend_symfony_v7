<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250513202512 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SEQUENCE parcel_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE shipping_label_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE shipping_order_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE TABLE parcel (id INT NOT NULL, shipping_order_id INT DEFAULT NULL, index INT DEFAULT NULL, weight DOUBLE PRECISION DEFAULT NULL, length DOUBLE PRECISION DEFAULT NULL, width DOUBLE PRECISION DEFAULT NULL, height DOUBLE PRECISION DEFAULT NULL, price DOUBLE PRECISION DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_C99B5D6011702397 ON parcel (shipping_order_id)');
        $this->addSql('CREATE TABLE shipping_label (id INT NOT NULL, parcel_id INT DEFAULT NULL, label_url VARCHAR(255) DEFAULT NULL, tracking_code VARCHAR(100) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_E0388D52465E670C ON shipping_label (parcel_id)');
        $this->addSql('CREATE TABLE shipping_order (id INT NOT NULL, odershipping_id INT DEFAULT NULL, carrier_account_id VARCHAR(50) DEFAULT NULL, service VARCHAR(50) DEFAULT NULL, total_price DOUBLE PRECISION DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_1BB64E22ACB9B199 ON shipping_order (odershipping_id)');
        $this->addSql('ALTER TABLE parcel ADD CONSTRAINT FK_C99B5D6011702397 FOREIGN KEY (shipping_order_id) REFERENCES shipping_order (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE shipping_label ADD CONSTRAINT FK_E0388D52465E670C FOREIGN KEY (parcel_id) REFERENCES parcel (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE shipping_order ADD CONSTRAINT FK_1BB64E22ACB9B199 FOREIGN KEY (odershipping_id) REFERENCES "order" (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('DROP SEQUENCE parcel_id_seq CASCADE');
        $this->addSql('DROP SEQUENCE shipping_label_id_seq CASCADE');
        $this->addSql('DROP SEQUENCE shipping_order_id_seq CASCADE');
        $this->addSql('ALTER TABLE parcel DROP CONSTRAINT FK_C99B5D6011702397');
        $this->addSql('ALTER TABLE shipping_label DROP CONSTRAINT FK_E0388D52465E670C');
        $this->addSql('ALTER TABLE shipping_order DROP CONSTRAINT FK_1BB64E22ACB9B199');
        $this->addSql('DROP TABLE parcel');
        $this->addSql('DROP TABLE shipping_label');
        $this->addSql('DROP TABLE shipping_order');
    }
}
