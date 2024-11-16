<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20241116170154 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE admin_settings ADD section2_component VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE admin_settings ADD type_component_section2 VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE admin_settings ADD section3_component VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE admin_settings ADD type_component_section3 VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE admin_settings ADD section4_component VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE admin_settings ADD type_component_section4 VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('ALTER TABLE admin_settings DROP section2_component');
        $this->addSql('ALTER TABLE admin_settings DROP type_component_section2');
        $this->addSql('ALTER TABLE admin_settings DROP section3_component');
        $this->addSql('ALTER TABLE admin_settings DROP type_component_section3');
        $this->addSql('ALTER TABLE admin_settings DROP section4_component');
        $this->addSql('ALTER TABLE admin_settings DROP type_component_section4');
    }
}
