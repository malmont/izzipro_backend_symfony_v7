<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Application de gestion, lot G1b-1 (11/11/2026, `docs/guide-ia-gestion.md` § 8) : ce que le gérant a appliqué en marquant un conseil fait
 * (`gestion_advice.applied`, `applied_on`), base de la mesure après application. Additive, idempotente ; équivalent
 * `scripts/migrate_all_v2_gestion_conseils_mesure.sh` pour toutes les bases.
 */
final class Version20261210100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Gestion, lot G1b-1 : conseils appliqués (applied, applied_on)';
    }

    public function up(Schema $schema): void
    {
        foreach (self::STATEMENTS as $sql) {
            $this->addSql($sql);
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE gestion_advice DROP COLUMN IF EXISTS applied_on');
        $this->addSql('ALTER TABLE gestion_advice DROP COLUMN IF EXISTS applied');
    }

    public const STATEMENTS = [
        'ALTER TABLE gestion_advice ADD COLUMN IF NOT EXISTS applied JSON DEFAULT NULL',
        'ALTER TABLE gestion_advice ADD COLUMN IF NOT EXISTS applied_on DATE DEFAULT NULL',
    ];
}
