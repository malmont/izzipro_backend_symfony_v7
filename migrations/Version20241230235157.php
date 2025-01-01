<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20241230235157 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SEQUENCE cash_details_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE denomination_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE type_cash_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE TABLE cash_details (id INT NOT NULL, transaction_caisse_id INT DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_2CCA19D6649365FC ON cash_details (transaction_caisse_id)');
        $this->addSql('CREATE TABLE denomination (id INT NOT NULL, type_cash_id INT DEFAULT NULL, cash_details_id INT DEFAULT NULL, cash_detail_id INT DEFAULT NULL, nombre_items INT NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_15AEA10C155B63DD ON denomination (type_cash_id)');
        $this->addSql('CREATE INDEX IDX_15AEA10CFF40E28 ON denomination (cash_details_id)');
        $this->addSql('CREATE INDEX IDX_15AEA10C3D673E47 ON denomination (cash_detail_id)');
        $this->addSql('CREATE TABLE type_cash (id INT NOT NULL, name VARCHAR(255) DEFAULT NULL, value INT DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('ALTER TABLE cash_details ADD CONSTRAINT FK_2CCA19D6649365FC FOREIGN KEY (transaction_caisse_id) REFERENCES transaction_caisse (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE denomination ADD CONSTRAINT FK_15AEA10C155B63DD FOREIGN KEY (type_cash_id) REFERENCES type_cash (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE denomination ADD CONSTRAINT FK_15AEA10CFF40E28 FOREIGN KEY (cash_details_id) REFERENCES cash_details (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE denomination ADD CONSTRAINT FK_15AEA10C3D673E47 FOREIGN KEY (cash_detail_id) REFERENCES cash_details (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE transaction_caisse ADD cashdetails_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE transaction_caisse ADD CONSTRAINT FK_C4DE218813319BC3 FOREIGN KEY (cashdetails_id) REFERENCES cash_details (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_C4DE218813319BC3 ON transaction_caisse (cashdetails_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('ALTER TABLE transaction_caisse DROP CONSTRAINT FK_C4DE218813319BC3');
        $this->addSql('DROP SEQUENCE cash_details_id_seq CASCADE');
        $this->addSql('DROP SEQUENCE denomination_id_seq CASCADE');
        $this->addSql('DROP SEQUENCE type_cash_id_seq CASCADE');
        $this->addSql('ALTER TABLE cash_details DROP CONSTRAINT FK_2CCA19D6649365FC');
        $this->addSql('ALTER TABLE denomination DROP CONSTRAINT FK_15AEA10C155B63DD');
        $this->addSql('ALTER TABLE denomination DROP CONSTRAINT FK_15AEA10CFF40E28');
        $this->addSql('ALTER TABLE denomination DROP CONSTRAINT FK_15AEA10C3D673E47');
        $this->addSql('DROP TABLE cash_details');
        $this->addSql('DROP TABLE denomination');
        $this->addSql('DROP TABLE type_cash');
        $this->addSql('DROP INDEX UNIQ_C4DE218813319BC3');
        $this->addSql('ALTER TABLE transaction_caisse DROP cashdetails_id');
    }
}
