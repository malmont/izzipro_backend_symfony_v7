<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20240830145816 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SEQUENCE order_items_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE TABLE order_items (id INT NOT NULL, order_associated_id INT DEFAULT NULL, product_variant_id INT DEFAULT NULL, quantity INT NOT NULL, unit_price DOUBLE PRECISION NOT NULL, total_price DOUBLE PRECISION NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_62809DB0E24BFBC ON order_items (order_associated_id)');
        $this->addSql('CREATE INDEX IDX_62809DB0A80EF684 ON order_items (product_variant_id)');
        $this->addSql('ALTER TABLE order_items ADD CONSTRAINT FK_62809DB0E24BFBC FOREIGN KEY (order_associated_id) REFERENCES "order" (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE order_items ADD CONSTRAINT FK_62809DB0A80EF684 FOREIGN KEY (product_variant_id) REFERENCES product_variant (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('DROP SEQUENCE order_items_id_seq CASCADE');
        $this->addSql('ALTER TABLE order_items DROP CONSTRAINT FK_62809DB0E24BFBC');
        $this->addSql('ALTER TABLE order_items DROP CONSTRAINT FK_62809DB0A80EF684');
        $this->addSql('DROP TABLE order_items');
    }
}
