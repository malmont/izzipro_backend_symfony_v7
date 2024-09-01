<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20240830144517 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE "order" DROP CONSTRAINT fk_f5299398881ecfa7');
        $this->addSql('DROP INDEX idx_f5299398881ecfa7');
        $this->addSql('ALTER TABLE "order" ADD status_order_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE "order" ADD shipping_adress_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE "order" DROP shipping_adress');
        $this->addSql('ALTER TABLE "order" ALTER order_date SET NOT NULL');
        $this->addSql('ALTER TABLE "order" RENAME COLUMN status_id_id TO user_id_id');
        $this->addSql('ALTER TABLE "order" ADD CONSTRAINT FK_F52993989D86650F FOREIGN KEY (user_id_id) REFERENCES "user" (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE "order" ADD CONSTRAINT FK_F52993981045CAE0 FOREIGN KEY (status_order_id) REFERENCES status_payment (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE "order" ADD CONSTRAINT FK_F5299398C273A89B FOREIGN KEY (shipping_adress_id) REFERENCES adress (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX IDX_F52993989D86650F ON "order" (user_id_id)');
        $this->addSql('CREATE INDEX IDX_F52993981045CAE0 ON "order" (status_order_id)');
        $this->addSql('CREATE INDEX IDX_F5299398C273A89B ON "order" (shipping_adress_id)');
        $this->addSql('ALTER TABLE "user" DROP CONSTRAINT fk_8d93d6496d128938');
        $this->addSql('DROP INDEX idx_8d93d6496d128938');
        $this->addSql('ALTER TABLE "user" DROP user_order_id');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('ALTER TABLE "user" ADD user_order_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE "user" ADD CONSTRAINT fk_8d93d6496d128938 FOREIGN KEY (user_order_id) REFERENCES "order" (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX idx_8d93d6496d128938 ON "user" (user_order_id)');
        $this->addSql('ALTER TABLE "order" DROP CONSTRAINT FK_F52993989D86650F');
        $this->addSql('ALTER TABLE "order" DROP CONSTRAINT FK_F52993981045CAE0');
        $this->addSql('ALTER TABLE "order" DROP CONSTRAINT FK_F5299398C273A89B');
        $this->addSql('DROP INDEX IDX_F52993989D86650F');
        $this->addSql('DROP INDEX IDX_F52993981045CAE0');
        $this->addSql('DROP INDEX IDX_F5299398C273A89B');
        $this->addSql('ALTER TABLE "order" ADD status_id_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE "order" ADD shipping_adress VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE "order" DROP user_id_id');
        $this->addSql('ALTER TABLE "order" DROP status_order_id');
        $this->addSql('ALTER TABLE "order" DROP shipping_adress_id');
        $this->addSql('ALTER TABLE "order" ALTER order_date DROP NOT NULL');
        $this->addSql('ALTER TABLE "order" ADD CONSTRAINT fk_f5299398881ecfa7 FOREIGN KEY (status_id_id) REFERENCES status_payment (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX idx_f5299398881ecfa7 ON "order" (status_id_id)');
    }
}
