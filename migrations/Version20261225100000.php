<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * REPRISE-FIGE (12/11/2026, `docs/periodes-gestion.md` « Reprise d'historique ») : gestion_reprise.frozen_results, la copie des résultats
 * figés (lignes entières de period_result) que l'application d'une reprise efface, pour que son annulation les remette à l'identique.
 * Additive, idempotente ; équivalent `scripts/migrate_all_v2_reprise_fige.sh`.
 */
final class Version20261225100000 extends AbstractMigration
{
    public const STATEMENTS = [
        'ALTER TABLE gestion_reprise ADD COLUMN IF NOT EXISTS frozen_results JSON DEFAULT NULL',
    ];

    public function getDescription(): string
    {
        return 'REPRISE-FIGE : copie des résultats figés effacés par une reprise d\'historique';
    }

    public function up(Schema $schema): void
    {
        foreach (self::STATEMENTS as $sql) {
            $this->addSql($sql);
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE gestion_reprise DROP COLUMN IF EXISTS frozen_results');
    }
}
