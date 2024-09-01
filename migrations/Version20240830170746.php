<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20240830170746 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE caisse_transaction_type (caisse_id INT NOT NULL, transaction_type_id INT NOT NULL, PRIMARY KEY(caisse_id, transaction_type_id))');
        $this->addSql('CREATE INDEX IDX_39E34A8F27B4FEBF ON caisse_transaction_type (caisse_id)');
        $this->addSql('CREATE INDEX IDX_39E34A8FB3E6B071 ON caisse_transaction_type (transaction_type_id)');
        $this->addSql('ALTER TABLE caisse_transaction_type ADD CONSTRAINT FK_39E34A8F27B4FEBF FOREIGN KEY (caisse_id) REFERENCES caisse (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE caisse_transaction_type ADD CONSTRAINT FK_39E34A8FB3E6B071 FOREIGN KEY (transaction_type_id) REFERENCES transaction_type (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE caisse DROP CONSTRAINT fk_b2a353c8b3e6b071');
        $this->addSql('DROP INDEX idx_b2a353c8b3e6b071');
        $this->addSql('ALTER TABLE caisse DROP transaction_type_id');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('ALTER TABLE caisse_transaction_type DROP CONSTRAINT FK_39E34A8F27B4FEBF');
        $this->addSql('ALTER TABLE caisse_transaction_type DROP CONSTRAINT FK_39E34A8FB3E6B071');
        $this->addSql('DROP TABLE caisse_transaction_type');
        $this->addSql('ALTER TABLE caisse ADD transaction_type_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE caisse ADD CONSTRAINT fk_b2a353c8b3e6b071 FOREIGN KEY (transaction_type_id) REFERENCES transaction_type (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX idx_b2a353c8b3e6b071 ON caisse (transaction_type_id)');
    }
}
