<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20240831224732 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SEQUENCE order_tax_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE tax_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE TABLE order_tax (id INT NOT NULL, order_tax_id INT DEFAULT NULL, tax_id INT DEFAULT NULL, amount DOUBLE PRECISION NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_CDDAF5167127340D ON order_tax (order_tax_id)');
        $this->addSql('CREATE INDEX IDX_CDDAF516B2A824D8 ON order_tax (tax_id)');
        $this->addSql('CREATE TABLE tax (id INT NOT NULL, name VARCHAR(255) NOT NULL, rate DOUBLE PRECISION NOT NULL, type VARCHAR(255) NOT NULL, province VARCHAR(255) DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('ALTER TABLE order_tax ADD CONSTRAINT FK_CDDAF5167127340D FOREIGN KEY (order_tax_id) REFERENCES "order" (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE order_tax ADD CONSTRAINT FK_CDDAF516B2A824D8 FOREIGN KEY (tax_id) REFERENCES tax (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE caisse ALTER is_open SET DEFAULT false');
        $this->addSql('ALTER TABLE "order" ADD sub_total DOUBLE PRECISION DEFAULT NULL');
        $this->addSql('ALTER TABLE "order" ADD total_tax DOUBLE PRECISION DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('DROP SEQUENCE order_tax_id_seq CASCADE');
        $this->addSql('DROP SEQUENCE tax_id_seq CASCADE');
        $this->addSql('ALTER TABLE order_tax DROP CONSTRAINT FK_CDDAF5167127340D');
        $this->addSql('ALTER TABLE order_tax DROP CONSTRAINT FK_CDDAF516B2A824D8');
        $this->addSql('DROP TABLE order_tax');
        $this->addSql('DROP TABLE tax');
        $this->addSql('ALTER TABLE "order" DROP sub_total');
        $this->addSql('ALTER TABLE "order" DROP total_tax');
        $this->addSql('ALTER TABLE caisse ALTER is_open DROP DEFAULT');
    }
}
