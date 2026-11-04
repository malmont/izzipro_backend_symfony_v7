<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Console de pilotage, lot 3 bis (04/11/2026) : branchement d'une formule d'abonnement sur un service (offre de la plateforme, plus tard webhook) et site cible d'un abonnement. Additif ; équivalent de scripts/migrate_all_v2_subscription_fulfillment.sh. */
final class Version20261114100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'subscription_plan.fulfillment_type / fulfillment_ref, subscription.fulfillment_target ; additif';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE subscription_plan ADD COLUMN IF NOT EXISTS fulfillment_type VARCHAR(20) NOT NULL DEFAULT 'none'");
        $this->addSql('ALTER TABLE subscription_plan ADD COLUMN IF NOT EXISTS fulfillment_ref VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE subscription ADD COLUMN IF NOT EXISTS fulfillment_target VARCHAR(50) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE subscription_plan DROP COLUMN IF EXISTS fulfillment_type');
        $this->addSql('ALTER TABLE subscription_plan DROP COLUMN IF EXISTS fulfillment_ref');
        $this->addSql('ALTER TABLE subscription DROP COLUMN IF EXISTS fulfillment_target');
    }
}
