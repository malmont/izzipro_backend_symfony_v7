<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Console de pilotage, lot 2 (04/11/2026) : langue du site choisie explicitement (null = déduite de l'adresse). Additif ; équivalent de scripts/migrate_all_v2_site_locale.sh. */
final class Version20261113100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'entreprise.locale (langue du site choisie, null = déduite) ; additif';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE entreprise ADD COLUMN IF NOT EXISTS locale VARCHAR(5) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE entreprise DROP COLUMN IF EXISTS locale');
    }
}
