<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261009230000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Atouts de l\'accueil : ordre d\'affichage (feature.position) ; équivalent de scripts/migrate_all_v2_feature_position.sh, idempotent';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE IF EXISTS feature ADD COLUMN IF NOT EXISTS position INT NOT NULL DEFAULT 0');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE IF EXISTS feature DROP COLUMN IF EXISTS position');
    }
}
