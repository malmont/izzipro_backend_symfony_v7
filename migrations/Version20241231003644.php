<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20241231003644 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE denomination DROP CONSTRAINT fk_15aea10c3d673e47');
        $this->addSql('DROP INDEX idx_15aea10c3d673e47');
        $this->addSql('ALTER TABLE denomination DROP cash_detail_id');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('ALTER TABLE denomination ADD cash_detail_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE denomination ADD CONSTRAINT fk_15aea10c3d673e47 FOREIGN KEY (cash_detail_id) REFERENCES cash_details (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX idx_15aea10c3d673e47 ON denomination (cash_detail_id)');
    }
}
