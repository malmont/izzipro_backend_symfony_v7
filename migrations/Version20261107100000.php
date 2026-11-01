<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261107100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Usage des codes à usage unique : otp_code.purpose (login, email_change, account_deletion ; « login » pour les codes existants ; additif ; équivalent de scripts/migrate_all_v2_otp_purpose.sh)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE otp_code ADD COLUMN IF NOT EXISTS purpose VARCHAR(20) NOT NULL DEFAULT 'login'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE otp_code DROP COLUMN IF EXISTS purpose');
    }
}
