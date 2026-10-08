<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261008140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Boutique réglable : délai de livraison affiché d\'un transporteur (carrier.estimated_days ; équivalent de scripts/migrate_all_v2_carrier_estimated_days.sh, idempotent)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE IF EXISTS carrier ADD COLUMN IF NOT EXISTS estimated_days VARCHAR(30) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE IF EXISTS carrier DROP COLUMN IF EXISTS estimated_days');
    }
}
