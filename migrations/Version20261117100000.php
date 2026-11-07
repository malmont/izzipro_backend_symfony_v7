<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Console IA, lot 6 (07/11/2026) : paliers de l'assistant IA gouvernés par les droits d'usage, projetés sur la fiche Entreprise
 * (`is_ai_help_active` : Aide IA, `is_ai_full_active` : IA complète). Additif ; équivalent de scripts/migrate_all_v2_ai_tiers.sh.
 */
final class Version20261117100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'entreprise.is_ai_help_active / is_ai_full_active ; additif';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE entreprise ADD COLUMN IF NOT EXISTS is_ai_help_active BOOLEAN NOT NULL DEFAULT FALSE');
        $this->addSql('ALTER TABLE entreprise ADD COLUMN IF NOT EXISTS is_ai_full_active BOOLEAN NOT NULL DEFAULT FALSE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE entreprise DROP COLUMN IF EXISTS is_ai_help_active');
        $this->addSql('ALTER TABLE entreprise DROP COLUMN IF EXISTS is_ai_full_active');
    }
}
