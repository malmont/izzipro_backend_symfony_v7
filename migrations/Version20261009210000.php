<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261009210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Grille de formules : subscription_plan.features, descriptions, badges (JSON par langue) et highlighted ; équivalent de scripts/migrate_all_v2_subscription_plan_grid.sh, idempotent';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE IF EXISTS subscription_plan ADD COLUMN IF NOT EXISTS features JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE IF EXISTS subscription_plan ADD COLUMN IF NOT EXISTS descriptions JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE IF EXISTS subscription_plan ADD COLUMN IF NOT EXISTS badges JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE IF EXISTS subscription_plan ADD COLUMN IF NOT EXISTS highlighted BOOLEAN NOT NULL DEFAULT FALSE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE IF EXISTS subscription_plan DROP COLUMN IF EXISTS highlighted');
        $this->addSql('ALTER TABLE IF EXISTS subscription_plan DROP COLUMN IF EXISTS badges');
        $this->addSql('ALTER TABLE IF EXISTS subscription_plan DROP COLUMN IF EXISTS descriptions');
        $this->addSql('ALTER TABLE IF EXISTS subscription_plan DROP COLUMN IF EXISTS features');
    }
}
