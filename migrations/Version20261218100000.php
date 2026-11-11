<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Lot AN, partie EX (11/11/2026, `docs/annee-gestion.md` § 1) : mois de début de l'exercice du site (`entreprise.fiscal_year_start_month`,
 * 1 à 12, null = 1, année civile). Additive, idempotente ; équivalent `scripts/migrate_all_v2_gestion_exercice.sh` pour toutes les bases.
 */
final class Version20261218100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Lot AN (EX) : mois de début de l\'exercice du site';
    }

    public function up(Schema $schema): void
    {
        foreach (self::STATEMENTS as $sql) {
            $this->addSql($sql);
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE entreprise DROP COLUMN IF EXISTS fiscal_year_start_month');
    }

    public const STATEMENTS = [
        'ALTER TABLE entreprise ADD COLUMN IF NOT EXISTS fiscal_year_start_month SMALLINT DEFAULT NULL',
    ];
}
