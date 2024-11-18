<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20241117171701 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE admin_settings ADD section5_component VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE admin_settings ADD type_component_section5 VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE admin_settings ADD section6_component VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE admin_settings ADD type_component_section6 VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE admin_settings ADD section7_component VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE admin_settings ADD type_component_section7 VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE admin_settings ADD select_type_product_fetch_section5 VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE admin_settings ADD select_type_product_fetch_section6 VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE admin_settings ADD select_type_product_fetch_section7 VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('ALTER TABLE admin_settings DROP section5_component');
        $this->addSql('ALTER TABLE admin_settings DROP type_component_section5');
        $this->addSql('ALTER TABLE admin_settings DROP section6_component');
        $this->addSql('ALTER TABLE admin_settings DROP type_component_section6');
        $this->addSql('ALTER TABLE admin_settings DROP section7_component');
        $this->addSql('ALTER TABLE admin_settings DROP type_component_section7');
        $this->addSql('ALTER TABLE admin_settings DROP select_type_product_fetch_section5');
        $this->addSql('ALTER TABLE admin_settings DROP select_type_product_fetch_section6');
        $this->addSql('ALTER TABLE admin_settings DROP select_type_product_fetch_section7');
    }
}
