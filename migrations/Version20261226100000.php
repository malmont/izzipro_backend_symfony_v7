<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * REPRISE-TDB (13/11/2026, `docs/periodes-gestion.md` « Reprise d'historique ») : gestion_reprise.frozen_dashboards, la copie des
 * tableaux de bord figés (lignes entières de collection_statistiques) que l'application d'une reprise efface, pour que son annulation
 * les remette à l'identique. Même mécanisme que frozen_results (REPRISE-FIGE). Additive, idempotente ; équivalent
 * `scripts/migrate_all_v2_reprise_tdb.sh`.
 */
final class Version20261226100000 extends AbstractMigration
{
    public const STATEMENTS = [
        'ALTER TABLE gestion_reprise ADD COLUMN IF NOT EXISTS frozen_dashboards JSON DEFAULT NULL',
    ];

    public function getDescription(): string
    {
        return 'REPRISE-TDB : copie des tableaux de bord figés effacés par une reprise d\'historique';
    }

    public function up(Schema $schema): void
    {
        foreach (self::STATEMENTS as $sql) {
            $this->addSql($sql);
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE gestion_reprise DROP COLUMN IF EXISTS frozen_dashboards');
    }
}
