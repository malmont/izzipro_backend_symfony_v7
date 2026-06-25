<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260625172338 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE vehicle ADD title VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE vehicle ADD description TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE vehicle ADD picture VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE vehicle ADD year INT DEFAULT NULL');
        $this->addSql('ALTER TABLE vehicle ADD color VARCHAR(100) DEFAULT NULL');
        $this->addSql('ALTER TABLE vehicle ADD transmission INT DEFAULT NULL');
        $this->addSql('ALTER TABLE vehicle ADD gas_type INT DEFAULT NULL');
        $this->addSql('ALTER TABLE vehicle ADD new_vehicle BOOLEAN DEFAULT NULL');
        $this->addSql('ALTER TABLE vehicle ADD featured_vehicle BOOLEAN DEFAULT NULL');
        $this->addSql('ALTER TABLE vehicle ADD web_display BOOLEAN DEFAULT NULL');
        $this->addSql('ALTER TABLE vehicle ADD slug VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('ALTER TABLE vehicle DROP title');
        $this->addSql('ALTER TABLE vehicle DROP description');
        $this->addSql('ALTER TABLE vehicle DROP picture');
        $this->addSql('ALTER TABLE vehicle DROP year');
        $this->addSql('ALTER TABLE vehicle DROP color');
        $this->addSql('ALTER TABLE vehicle DROP transmission');
        $this->addSql('ALTER TABLE vehicle DROP gas_type');
        $this->addSql('ALTER TABLE vehicle DROP new_vehicle');
        $this->addSql('ALTER TABLE vehicle DROP featured_vehicle');
        $this->addSql('ALTER TABLE vehicle DROP web_display');
        $this->addSql('ALTER TABLE vehicle DROP slug');
    }
}
