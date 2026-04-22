<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260422174158 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE booking ADD gemsuite_sale_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE booking ADD is_finalized BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE booking ADD created_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NOW() NOT NULL');
        $this->addSql('COMMENT ON COLUMN booking.created_at IS \'(DC2Type:datetime_immutable)\'');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('ALTER TABLE booking DROP gemsuite_sale_id');
        $this->addSql('ALTER TABLE booking DROP is_finalized');
        $this->addSql('ALTER TABLE booking DROP created_at');
    }
}
