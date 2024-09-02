<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20240902195146 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SEQUENCE order_type_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE payment_type_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE TABLE order_type (id INT NOT NULL, name VARCHAR(255) NOT NULL, description VARCHAR(255) DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE TABLE payment_type (id INT NOT NULL, name VARCHAR(255) NOT NULL, description VARCHAR(255) DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('ALTER TABLE "order" ADD order_type_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE "order" ADD CONSTRAINT FK_F5299398333625D8 FOREIGN KEY (order_type_id) REFERENCES order_type (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX IDX_F5299398333625D8 ON "order" (order_type_id)');
        $this->addSql('ALTER TABLE payments ADD payment_type_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE payments ADD CONSTRAINT FK_65D29B32DC058279 FOREIGN KEY (payment_type_id) REFERENCES payment_type (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX IDX_65D29B32DC058279 ON payments (payment_type_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('ALTER TABLE "order" DROP CONSTRAINT FK_F5299398333625D8');
        $this->addSql('ALTER TABLE payments DROP CONSTRAINT FK_65D29B32DC058279');
        $this->addSql('DROP SEQUENCE order_type_id_seq CASCADE');
        $this->addSql('DROP SEQUENCE payment_type_id_seq CASCADE');
        $this->addSql('DROP TABLE order_type');
        $this->addSql('DROP TABLE payment_type');
        $this->addSql('DROP INDEX IDX_65D29B32DC058279');
        $this->addSql('ALTER TABLE payments DROP payment_type_id');
        $this->addSql('DROP INDEX IDX_F5299398333625D8');
        $this->addSql('ALTER TABLE "order" DROP order_type_id');
    }
}
