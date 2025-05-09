<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250508194132 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE explore_card ALTER standard_title TYPE TEXT');
        $this->addSql('ALTER TABLE explore_card ALTER different_title TYPE TEXT');
        $this->addSql('ALTER TABLE explore_card ALTER different_title TYPE TEXT');
        $this->addSql('ALTER TABLE explore_card ALTER description TYPE TEXT');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('ALTER TABLE explore_card ALTER standard_title TYPE VARCHAR(255)');
        $this->addSql('ALTER TABLE explore_card ALTER different_title TYPE VARCHAR(100)');
        $this->addSql('ALTER TABLE explore_card ALTER description TYPE VARCHAR(255)');
    }
}
