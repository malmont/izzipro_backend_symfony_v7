<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261001090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Mémoires Vivantes : police du livre (mv_book.font, code du catalogue BookFontCatalog ; équivalent de scripts/migrate_all_v2_book_font.sh, idempotent)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("DO \$\$ BEGIN IF EXISTS (SELECT FROM information_schema.tables WHERE table_name = 'mv_book') THEN ALTER TABLE mv_book ADD COLUMN IF NOT EXISTS font VARCHAR(40) DEFAULT NULL; END IF; END \$\$;");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mv_book DROP COLUMN IF EXISTS font');
    }
}
