<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260930210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Mémoires Vivantes : adresse du client sur le livre (mv_book.client_address), pour l\'itinéraire du biographe (équivalent de scripts/migrate_all_v2_book_client_address.sh, idempotent)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("DO \$\$ BEGIN IF EXISTS (SELECT FROM information_schema.tables WHERE table_name = 'mv_book') THEN ALTER TABLE mv_book ADD COLUMN IF NOT EXISTS client_address TEXT DEFAULT NULL; END IF; END \$\$;");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mv_book DROP COLUMN IF EXISTS client_address');
    }
}
