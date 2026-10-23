<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Caisse, lot F14 (29/10/2026) : fuseau horaire du magasin et plan de comptes par site. Additif. Équivalent : scripts/migrate_all_v2_pos_accounting.sh. */
final class Version20261029100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Caisse F14 : fuseau horaire du magasin, plan de comptes (additif)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE entreprise ADD COLUMN IF NOT EXISTS pos_timezone VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE entreprise ADD COLUMN IF NOT EXISTS accounting_accounts TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE entreprise DROP COLUMN IF EXISTS pos_timezone, DROP COLUMN IF EXISTS accounting_accounts');
    }
}
