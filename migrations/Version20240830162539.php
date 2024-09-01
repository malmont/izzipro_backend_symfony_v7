<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20240830162539 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE caisse DROP CONSTRAINT fk_b2a353c84a021950');
        $this->addSql('DROP INDEX idx_b2a353c84a021950');
        $this->addSql('ALTER TABLE caisse RENAME COLUMN transactiontype_id TO transaction_type_id');
        $this->addSql('ALTER TABLE caisse ADD CONSTRAINT FK_B2A353C8B3E6B071 FOREIGN KEY (transaction_type_id) REFERENCES transaction_type (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX IDX_B2A353C8B3E6B071 ON caisse (transaction_type_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('ALTER TABLE caisse DROP CONSTRAINT FK_B2A353C8B3E6B071');
        $this->addSql('DROP INDEX IDX_B2A353C8B3E6B071');
        $this->addSql('ALTER TABLE caisse RENAME COLUMN transaction_type_id TO transactiontype_id');
        $this->addSql('ALTER TABLE caisse ADD CONSTRAINT fk_b2a353c84a021950 FOREIGN KEY (transactiontype_id) REFERENCES transaction_type (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX idx_b2a353c84a021950 ON caisse (transactiontype_id)');
    }
}
