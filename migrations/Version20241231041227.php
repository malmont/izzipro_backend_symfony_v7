<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20241231041227 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP SEQUENCE denomination_id_seq CASCADE');
        $this->addSql('CREATE TABLE cash_details (id INT NOT NULL, type_cash_id INT NOT NULL, transaction_caisse_id INT NOT NULL, nombre_items INT NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_2CCA19D6155B63DD ON cash_details (type_cash_id)');
        $this->addSql('CREATE INDEX IDX_2CCA19D6649365FC ON cash_details (transaction_caisse_id)');
        $this->addSql('ALTER TABLE cash_details ADD CONSTRAINT FK_2CCA19D6155B63DD FOREIGN KEY (type_cash_id) REFERENCES type_cash (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE cash_details ADD CONSTRAINT FK_2CCA19D6649365FC FOREIGN KEY (transaction_caisse_id) REFERENCES transaction_caisse (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('DROP INDEX uniq_c4de218813319bc3');
        $this->addSql('ALTER TABLE transaction_caisse DROP cashdetails_id');
        $this->addSql('ALTER TABLE type_cash ALTER value TYPE DOUBLE PRECISION');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('CREATE SEQUENCE denomination_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('ALTER TABLE cash_details DROP CONSTRAINT FK_2CCA19D6155B63DD');
        $this->addSql('ALTER TABLE cash_details DROP CONSTRAINT FK_2CCA19D6649365FC');
        $this->addSql('DROP TABLE cash_details');
        $this->addSql('ALTER TABLE type_cash ALTER value TYPE INT');
        $this->addSql('ALTER TABLE transaction_caisse ADD cashdetails_id INT DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_c4de218813319bc3 ON transaction_caisse (cashdetails_id)');
    }
}
