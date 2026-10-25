<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261025110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Abonnements : carte enregistrée chez Stripe (subscription.has_payment_method, null = inconnu) (équivalent de scripts/migrate_all_v2_subscription_payment_method.sh, idempotent)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE IF EXISTS subscription ADD COLUMN IF NOT EXISTS has_payment_method BOOLEAN DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE IF EXISTS subscription DROP COLUMN IF EXISTS has_payment_method');
    }
}
