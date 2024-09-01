<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20240830142911 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP SEQUENCE order_details_id_seq CASCADE');
        $this->addSql('ALTER TABLE order_details DROP CONSTRAINT fk_845ca2c1cffe9ad6');
        $this->addSql('DROP TABLE order_details');
        $this->addSql('ALTER TABLE "order" DROP CONSTRAINT fk_f52993986d128938');
        $this->addSql('DROP INDEX idx_f52993986d128938');
        $this->addSql('ALTER TABLE "order" ADD status_id_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE "order" ADD order_source_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE "order" ADD order_date DATE DEFAULT NULL');
        $this->addSql('ALTER TABLE "order" ADD total_amount DOUBLE PRECISION NOT NULL');
        $this->addSql('ALTER TABLE "order" DROP user_order_id');
        $this->addSql('ALTER TABLE "order" DROP fullname');
        $this->addSql('ALTER TABLE "order" DROP carriername');
        $this->addSql('ALTER TABLE "order" DROP carrierprice');
        $this->addSql('ALTER TABLE "order" DROP deleveryaddress');
        $this->addSql('ALTER TABLE "order" DROP ispaid');
        $this->addSql('ALTER TABLE "order" DROP moreinformations');
        $this->addSql('ALTER TABLE "order" DROP created_at');
        $this->addSql('ALTER TABLE "order" DROP quantity');
        $this->addSql('ALTER TABLE "order" DROP sub_total_ht');
        $this->addSql('ALTER TABLE "order" DROP taxe');
        $this->addSql('ALTER TABLE "order" DROP sub_total_ttc');
        $this->addSql('ALTER TABLE "order" RENAME COLUMN stripe_checkout_session_id TO shipping_adress');
        $this->addSql('ALTER TABLE "order" ADD CONSTRAINT FK_F5299398881ECFA7 FOREIGN KEY (status_id_id) REFERENCES status_payment (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE "order" ADD CONSTRAINT FK_F529939829BB6799 FOREIGN KEY (order_source_id) REFERENCES order_source (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX IDX_F5299398881ECFA7 ON "order" (status_id_id)');
        $this->addSql('CREATE INDEX IDX_F529939829BB6799 ON "order" (order_source_id)');
        $this->addSql('ALTER TABLE "user" ADD user_order_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE "user" ADD CONSTRAINT FK_8D93D6496D128938 FOREIGN KEY (user_order_id) REFERENCES "order" (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX IDX_8D93D6496D128938 ON "user" (user_order_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('CREATE SEQUENCE order_details_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE TABLE order_details (id INT NOT NULL, orders_id INT NOT NULL, productname VARCHAR(255) NOT NULL, producprice DOUBLE PRECISION NOT NULL, quantity INT NOT NULL, sub_total_ht DOUBLE PRECISION NOT NULL, taxe DOUBLE PRECISION NOT NULL, sub_total_ttc DOUBLE PRECISION NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX idx_845ca2c1cffe9ad6 ON order_details (orders_id)');
        $this->addSql('ALTER TABLE order_details ADD CONSTRAINT fk_845ca2c1cffe9ad6 FOREIGN KEY (orders_id) REFERENCES "order" (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE "order" DROP CONSTRAINT FK_F5299398881ECFA7');
        $this->addSql('ALTER TABLE "order" DROP CONSTRAINT FK_F529939829BB6799');
        $this->addSql('DROP INDEX IDX_F5299398881ECFA7');
        $this->addSql('DROP INDEX IDX_F529939829BB6799');
        $this->addSql('ALTER TABLE "order" ADD user_order_id INT NOT NULL');
        $this->addSql('ALTER TABLE "order" ADD fullname VARCHAR(255) NOT NULL');
        $this->addSql('ALTER TABLE "order" ADD carriername VARCHAR(255) NOT NULL');
        $this->addSql('ALTER TABLE "order" ADD deleveryaddress TEXT NOT NULL');
        $this->addSql('ALTER TABLE "order" ADD ispaid BOOLEAN NOT NULL');
        $this->addSql('ALTER TABLE "order" ADD moreinformations TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE "order" ADD created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL');
        $this->addSql('ALTER TABLE "order" ADD quantity INT NOT NULL');
        $this->addSql('ALTER TABLE "order" ADD sub_total_ht DOUBLE PRECISION NOT NULL');
        $this->addSql('ALTER TABLE "order" ADD taxe DOUBLE PRECISION NOT NULL');
        $this->addSql('ALTER TABLE "order" ADD sub_total_ttc DOUBLE PRECISION NOT NULL');
        $this->addSql('ALTER TABLE "order" DROP status_id_id');
        $this->addSql('ALTER TABLE "order" DROP order_source_id');
        $this->addSql('ALTER TABLE "order" DROP order_date');
        $this->addSql('ALTER TABLE "order" RENAME COLUMN total_amount TO carrierprice');
        $this->addSql('ALTER TABLE "order" RENAME COLUMN shipping_adress TO stripe_checkout_session_id');
        $this->addSql('ALTER TABLE "order" ADD CONSTRAINT fk_f52993986d128938 FOREIGN KEY (user_order_id) REFERENCES "user" (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX idx_f52993986d128938 ON "order" (user_order_id)');
        $this->addSql('ALTER TABLE "user" DROP CONSTRAINT FK_8D93D6496D128938');
        $this->addSql('DROP INDEX IDX_8D93D6496D128938');
        $this->addSql('ALTER TABLE "user" DROP user_order_id');
    }
}
