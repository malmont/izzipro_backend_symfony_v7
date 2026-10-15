<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261015130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Fiche entreprise : activation des applications mobiles (is_pos_active, is_mobile_shop_active, is_management_app_active, is_living_memory_active) ; équivalent de scripts/migrate_all_v2_app_activation.sh, idempotent';
    }

    public function up(Schema $schema): void
    {
        foreach (['is_pos_active', 'is_mobile_shop_active', 'is_management_app_active', 'is_living_memory_active'] as $column) {
            $this->addSql(sprintf('ALTER TABLE entreprise ADD COLUMN IF NOT EXISTS %s BOOLEAN NOT NULL DEFAULT FALSE', $column));
        }
    }

    public function down(Schema $schema): void
    {
        foreach (['is_pos_active', 'is_mobile_shop_active', 'is_management_app_active', 'is_living_memory_active'] as $column) {
            $this->addSql(sprintf('ALTER TABLE entreprise DROP COLUMN IF EXISTS %s', $column));
        }
    }
}
