<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261016100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Mémoires Vivantes : origine du livre (mv_book.channel : web par défaut, mobile pour un livre créé par l\'application Living Memory) (équivalent de scripts/migrate_all_v2_book_channel.sh, idempotent)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("DO \$\$ BEGIN IF EXISTS (SELECT FROM information_schema.tables WHERE table_name = 'mv_book') THEN ALTER TABLE mv_book ADD COLUMN IF NOT EXISTS channel VARCHAR(10) NOT NULL DEFAULT 'web'; END IF; END \$\$;");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mv_book DROP COLUMN IF EXISTS channel');
    }
}
