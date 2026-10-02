<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261002150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Médiathèque : vidéo préparée pour une scène au défilement (shared_media.scroll_status, source_filename ; équivalent de scripts/migrate_all_v2_shared_media.sh, idempotent)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("DO \$\$ BEGIN IF EXISTS (SELECT FROM information_schema.tables WHERE table_name = 'shared_media') THEN
            ALTER TABLE shared_media ADD COLUMN IF NOT EXISTS scroll_status VARCHAR(12) DEFAULT NULL;
            ALTER TABLE shared_media ADD COLUMN IF NOT EXISTS source_filename VARCHAR(255) DEFAULT NULL;
        END IF; END \$\$;");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE shared_media DROP COLUMN IF EXISTS scroll_status');
        $this->addSql('ALTER TABLE shared_media DROP COLUMN IF EXISTS source_filename');
    }
}
