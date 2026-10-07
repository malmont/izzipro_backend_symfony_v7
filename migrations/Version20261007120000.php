<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261007120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ordre des présentations d\'un groupe (presentation_group.presentation_order ; équivalent de scripts/migrate_all_v2_presentation_order.sh, idempotent)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("DO \$\$ BEGIN IF EXISTS (SELECT FROM information_schema.tables WHERE table_name = 'presentation_group') THEN ALTER TABLE presentation_group ADD COLUMN IF NOT EXISTS presentation_order TEXT DEFAULT NULL; END IF; END \$\$;");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE presentation_group DROP COLUMN IF EXISTS presentation_order');
    }
}
