<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20240830181236 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SEQUENCE caisse_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE transaction_caisse_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE TABLE caisse (id INT NOT NULL, amount_total DOUBLE PRECISION NOT NULL, created_at DATE DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE TABLE transaction_caisse (id INT NOT NULL, caisse_id INT NOT NULL, user_caisse_id INT NOT NULL, order_caisse_id INT DEFAULT NULL, payment_id INT DEFAULT NULL, transaction_type_id INT NOT NULL, transaction_date DATE NOT NULL, amount DOUBLE PRECISION NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_C4DE218827B4FEBF ON transaction_caisse (caisse_id)');
        $this->addSql('CREATE INDEX IDX_C4DE21882754735B ON transaction_caisse (user_caisse_id)');
        $this->addSql('CREATE INDEX IDX_C4DE21889B338547 ON transaction_caisse (order_caisse_id)');
        $this->addSql('CREATE INDEX IDX_C4DE21884C3A3BB ON transaction_caisse (payment_id)');
        $this->addSql('CREATE INDEX IDX_C4DE2188B3E6B071 ON transaction_caisse (transaction_type_id)');
        $this->addSql('ALTER TABLE transaction_caisse ADD CONSTRAINT FK_C4DE218827B4FEBF FOREIGN KEY (caisse_id) REFERENCES caisse (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE transaction_caisse ADD CONSTRAINT FK_C4DE21882754735B FOREIGN KEY (user_caisse_id) REFERENCES "user" (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE transaction_caisse ADD CONSTRAINT FK_C4DE21889B338547 FOREIGN KEY (order_caisse_id) REFERENCES "order" (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE transaction_caisse ADD CONSTRAINT FK_C4DE21884C3A3BB FOREIGN KEY (payment_id) REFERENCES payments (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE transaction_caisse ADD CONSTRAINT FK_C4DE2188B3E6B071 FOREIGN KEY (transaction_type_id) REFERENCES transaction_type (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('DROP SEQUENCE caisse_id_seq CASCADE');
        $this->addSql('DROP SEQUENCE transaction_caisse_id_seq CASCADE');
        $this->addSql('ALTER TABLE transaction_caisse DROP CONSTRAINT FK_C4DE218827B4FEBF');
        $this->addSql('ALTER TABLE transaction_caisse DROP CONSTRAINT FK_C4DE21882754735B');
        $this->addSql('ALTER TABLE transaction_caisse DROP CONSTRAINT FK_C4DE21889B338547');
        $this->addSql('ALTER TABLE transaction_caisse DROP CONSTRAINT FK_C4DE21884C3A3BB');
        $this->addSql('ALTER TABLE transaction_caisse DROP CONSTRAINT FK_C4DE2188B3E6B071');
        $this->addSql('DROP TABLE caisse');
        $this->addSql('DROP TABLE transaction_caisse');
    }
}
