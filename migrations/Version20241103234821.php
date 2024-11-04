<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20241103234821 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SEQUENCE collection_statistiques_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE TABLE collection_statistiques (id INT NOT NULL, collection_id INT DEFAULT NULL, general_budget DOUBLE PRECISION NOT NULL, used_budget DOUBLE PRECISION NOT NULL, remaining_budget DOUBLE PRECISION NOT NULL, total_item_cost DOUBLE PRECISION NOT NULL, total_shipping_cost DOUBLE PRECISION NOT NULL, total_expense_cost DOUBLE PRECISION NOT NULL, order_count INT NOT NULL, item_count INT NOT NULL, model_count INT NOT NULL, stock_value DOUBLE PRECISION NOT NULL, margin DOUBLE PRECISION NOT NULL, taux_marge DOUBLE PRECISION NOT NULL, taux_marque DOUBLE PRECISION NOT NULL, average_multiplier DOUBLE PRECISION NOT NULL, start_date DATE NOT NULL, end_date DATE NOT NULL, duration_days INT NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_2939A94C514956FD ON collection_statistiques (collection_id)');
        $this->addSql('ALTER TABLE collection_statistiques ADD CONSTRAINT FK_2939A94C514956FD FOREIGN KEY (collection_id) REFERENCES collections (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('DROP SEQUENCE collection_statistiques_id_seq CASCADE');
        $this->addSql('ALTER TABLE collection_statistiques DROP CONSTRAINT FK_2939A94C514956FD');
        $this->addSql('DROP TABLE collection_statistiques');
    }
}
