<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261013100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Abonnements : dernière échéance impayée (subscription.payment_issue) ; équivalent de scripts/migrate_all_v2_subscription_payment_issue.sh, idempotent';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE IF EXISTS subscription ADD COLUMN IF NOT EXISTS payment_issue JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE IF EXISTS subscription DROP COLUMN IF EXISTS payment_issue');
    }
}
