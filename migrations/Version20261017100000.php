<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261017100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Mémoires Vivantes : choix de couverture enregistrés sur le livre (mv_book.cover_style, bg_color, author_name) et suppression de compte (user.deleted_at) (équivalent de scripts/migrate_all_v2_book_cover_choices.sh, idempotent)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("DO \$\$ BEGIN IF EXISTS (SELECT FROM information_schema.tables WHERE table_name = 'mv_book') THEN ALTER TABLE mv_book ADD COLUMN IF NOT EXISTS cover_style VARCHAR(50) DEFAULT NULL, ADD COLUMN IF NOT EXISTS bg_color VARCHAR(7) DEFAULT NULL, ADD COLUMN IF NOT EXISTS author_name VARCHAR(255) DEFAULT NULL; END IF; END \$\$;");
        $this->addSql('ALTER TABLE "user" ADD COLUMN IF NOT EXISTS deleted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mv_book DROP COLUMN IF EXISTS cover_style, DROP COLUMN IF EXISTS bg_color, DROP COLUMN IF EXISTS author_name');
        $this->addSql('ALTER TABLE "user" DROP COLUMN IF EXISTS deleted_at');
    }
}
