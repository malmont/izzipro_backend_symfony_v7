<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261002100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Mémoires Vivantes : nombre de séances d\'entretien prévu pour le livre (mv_book.session_count ; équivalent de scripts/migrate_all_v2_book_session_count.sh, idempotent)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("DO \$\$ BEGIN IF EXISTS (SELECT FROM information_schema.tables WHERE table_name = 'mv_book') THEN ALTER TABLE mv_book ADD COLUMN IF NOT EXISTS session_count SMALLINT DEFAULT NULL; END IF; END \$\$;");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mv_book DROP COLUMN IF EXISTS session_count');
    }
}
