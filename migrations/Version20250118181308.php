<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250118181308 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE payments ADD square_payment_id VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE payments ADD square_order_id VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE payments ADD square_receipt_url VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE payments ADD square_status VARCHAR(50) DEFAULT NULL');
        $this->addSql('ALTER TABLE payments ADD square_card_brand VARCHAR(50) DEFAULT NULL');
        $this->addSql('ALTER TABLE payments ADD square_last4 VARCHAR(4) DEFAULT NULL');
        $this->addSql('ALTER TABLE payments ADD square_risk_level VARCHAR(50) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('ALTER TABLE payments DROP square_payment_id');
        $this->addSql('ALTER TABLE payments DROP square_order_id');
        $this->addSql('ALTER TABLE payments DROP square_receipt_url');
        $this->addSql('ALTER TABLE payments DROP square_status');
        $this->addSql('ALTER TABLE payments DROP square_card_brand');
        $this->addSql('ALTER TABLE payments DROP square_last4');
        $this->addSql('ALTER TABLE payments DROP square_risk_level');
    }
}
