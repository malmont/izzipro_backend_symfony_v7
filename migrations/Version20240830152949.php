<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20240830152949 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SEQUENCE caisse_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE inventory_movements_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE payments_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE TABLE caisse (id INT NOT NULL, user_caisse_id INT DEFAULT NULL, order_caisse_id INT DEFAULT NULL, payment_id INT DEFAULT NULL, transactiontype_id INT DEFAULT NULL, transaction_date DATE NOT NULL, amount DOUBLE PRECISION NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_B2A353C82754735B ON caisse (user_caisse_id)');
        $this->addSql('CREATE INDEX IDX_B2A353C89B338547 ON caisse (order_caisse_id)');
        $this->addSql('CREATE INDEX IDX_B2A353C84C3A3BB ON caisse (payment_id)');
        $this->addSql('CREATE INDEX IDX_B2A353C84A021950 ON caisse (transactiontype_id)');
        $this->addSql('CREATE TABLE inventory_movements (id INT NOT NULL, product_variant_id INT DEFAULT NULL, movement_type_id INT DEFAULT NULL, quantity INT NOT NULL, movement_date DATE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_BF9F9C49A80EF684 ON inventory_movements (product_variant_id)');
        $this->addSql('CREATE INDEX IDX_BF9F9C49EA4ED04A ON inventory_movements (movement_type_id)');
        $this->addSql('CREATE TABLE payments (id INT NOT NULL, order_payment_id INT DEFAULT NULL, payment_method_id INT DEFAULT NULL, statut_payment_id INT DEFAULT NULL, amount DOUBLE PRECISION NOT NULL, payment_date DATE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_65D29B32B7195EEE ON payments (order_payment_id)');
        $this->addSql('CREATE INDEX IDX_65D29B325AA1164F ON payments (payment_method_id)');
        $this->addSql('CREATE INDEX IDX_65D29B327108AEB2 ON payments (statut_payment_id)');
        $this->addSql('ALTER TABLE caisse ADD CONSTRAINT FK_B2A353C82754735B FOREIGN KEY (user_caisse_id) REFERENCES "user" (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE caisse ADD CONSTRAINT FK_B2A353C89B338547 FOREIGN KEY (order_caisse_id) REFERENCES "order" (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE caisse ADD CONSTRAINT FK_B2A353C84C3A3BB FOREIGN KEY (payment_id) REFERENCES payments (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE caisse ADD CONSTRAINT FK_B2A353C84A021950 FOREIGN KEY (transactiontype_id) REFERENCES transaction_type (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE inventory_movements ADD CONSTRAINT FK_BF9F9C49A80EF684 FOREIGN KEY (product_variant_id) REFERENCES product_variant (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE inventory_movements ADD CONSTRAINT FK_BF9F9C49EA4ED04A FOREIGN KEY (movement_type_id) REFERENCES movement_type (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE payments ADD CONSTRAINT FK_65D29B32B7195EEE FOREIGN KEY (order_payment_id) REFERENCES "order" (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE payments ADD CONSTRAINT FK_65D29B325AA1164F FOREIGN KEY (payment_method_id) REFERENCES payment_method (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE payments ADD CONSTRAINT FK_65D29B327108AEB2 FOREIGN KEY (statut_payment_id) REFERENCES status_payment (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE "order" DROP CONSTRAINT fk_f52993981045cae0');
        $this->addSql('DROP INDEX idx_f52993981045cae0');
        $this->addSql('ALTER TABLE "order" DROP status_order_id');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('DROP SEQUENCE caisse_id_seq CASCADE');
        $this->addSql('DROP SEQUENCE inventory_movements_id_seq CASCADE');
        $this->addSql('DROP SEQUENCE payments_id_seq CASCADE');
        $this->addSql('ALTER TABLE caisse DROP CONSTRAINT FK_B2A353C82754735B');
        $this->addSql('ALTER TABLE caisse DROP CONSTRAINT FK_B2A353C89B338547');
        $this->addSql('ALTER TABLE caisse DROP CONSTRAINT FK_B2A353C84C3A3BB');
        $this->addSql('ALTER TABLE caisse DROP CONSTRAINT FK_B2A353C84A021950');
        $this->addSql('ALTER TABLE inventory_movements DROP CONSTRAINT FK_BF9F9C49A80EF684');
        $this->addSql('ALTER TABLE inventory_movements DROP CONSTRAINT FK_BF9F9C49EA4ED04A');
        $this->addSql('ALTER TABLE payments DROP CONSTRAINT FK_65D29B32B7195EEE');
        $this->addSql('ALTER TABLE payments DROP CONSTRAINT FK_65D29B325AA1164F');
        $this->addSql('ALTER TABLE payments DROP CONSTRAINT FK_65D29B327108AEB2');
        $this->addSql('DROP TABLE caisse');
        $this->addSql('DROP TABLE inventory_movements');
        $this->addSql('DROP TABLE payments');
        $this->addSql('ALTER TABLE "order" ADD status_order_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE "order" ADD CONSTRAINT fk_f52993981045cae0 FOREIGN KEY (status_order_id) REFERENCES status_payment (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX idx_f52993981045cae0 ON "order" (status_order_id)');
    }
}
