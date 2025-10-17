<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251017160107 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            ALTER TABLE payments ADD stripe_payment_id VARCHAR(255) DEFAULT NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE payments ADD stripe_receipt_url VARCHAR(255) DEFAULT NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE payments ADD stripe_status VARCHAR(50) DEFAULT NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE payments ADD stripe_card_brand VARCHAR(50) DEFAULT NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE payments ADD stripe_last4 VARCHAR(4) DEFAULT NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE payments ADD stripe_risk_level VARCHAR(50) DEFAULT NULL
        SQL);
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            CREATE SCHEMA public
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE payments DROP stripe_payment_id
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE payments DROP stripe_receipt_url
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE payments DROP stripe_status
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE payments DROP stripe_card_brand
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE payments DROP stripe_last4
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE payments DROP stripe_risk_level
        SQL);
    }
}
