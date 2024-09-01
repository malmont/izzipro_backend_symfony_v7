<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20240830175551 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP SEQUENCE caisse_id_seq CASCADE');
        $this->addSql('ALTER TABLE caisse DROP CONSTRAINT fk_b2a353c82754735b');
        $this->addSql('ALTER TABLE caisse DROP CONSTRAINT fk_b2a353c89b338547');
        $this->addSql('ALTER TABLE caisse DROP CONSTRAINT fk_b2a353c84c3a3bb');
        $this->addSql('ALTER TABLE caisse_transaction_type DROP CONSTRAINT fk_39e34a8f27b4febf');
        $this->addSql('ALTER TABLE caisse_transaction_type DROP CONSTRAINT fk_39e34a8fb3e6b071');
        $this->addSql('DROP TABLE caisse');
        $this->addSql('DROP TABLE caisse_transaction_type');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('CREATE SEQUENCE caisse_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE TABLE caisse (id INT NOT NULL, user_caisse_id INT DEFAULT NULL, order_caisse_id INT DEFAULT NULL, payment_id INT DEFAULT NULL, transaction_date DATE NOT NULL, amount DOUBLE PRECISION NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX idx_b2a353c84c3a3bb ON caisse (payment_id)');
        $this->addSql('CREATE INDEX idx_b2a353c89b338547 ON caisse (order_caisse_id)');
        $this->addSql('CREATE INDEX idx_b2a353c82754735b ON caisse (user_caisse_id)');
        $this->addSql('CREATE TABLE caisse_transaction_type (caisse_id INT NOT NULL, transaction_type_id INT NOT NULL, PRIMARY KEY(caisse_id, transaction_type_id))');
        $this->addSql('CREATE INDEX idx_39e34a8fb3e6b071 ON caisse_transaction_type (transaction_type_id)');
        $this->addSql('CREATE INDEX idx_39e34a8f27b4febf ON caisse_transaction_type (caisse_id)');
        $this->addSql('ALTER TABLE caisse ADD CONSTRAINT fk_b2a353c82754735b FOREIGN KEY (user_caisse_id) REFERENCES "user" (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE caisse ADD CONSTRAINT fk_b2a353c89b338547 FOREIGN KEY (order_caisse_id) REFERENCES "order" (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE caisse ADD CONSTRAINT fk_b2a353c84c3a3bb FOREIGN KEY (payment_id) REFERENCES payments (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE caisse_transaction_type ADD CONSTRAINT fk_39e34a8f27b4febf FOREIGN KEY (caisse_id) REFERENCES caisse (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE caisse_transaction_type ADD CONSTRAINT fk_39e34a8fb3e6b071 FOREIGN KEY (transaction_type_id) REFERENCES transaction_type (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }
}
