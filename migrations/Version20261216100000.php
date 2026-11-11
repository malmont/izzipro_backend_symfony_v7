<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Lot FF1 (11/11/2026, `docs/frais-fixes-gestion.md`) : drapeau « frais fixe » des mouvements prévus (`gestion_cash_movement.fixed_cost`,
 * null = règle par défaut de la catégorie). Additive, idempotente ; équivalent `scripts/migrate_all_v2_gestion_frais_fixes.sh` pour toutes les bases.
 */
final class Version20261216100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Lot FF1 : frais fixes des mouvements prévus';
    }

    public function up(Schema $schema): void
    {
        foreach (self::STATEMENTS as $sql) {
            $this->addSql($sql);
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE gestion_cash_movement DROP COLUMN IF EXISTS fixed_cost');
    }

    public const STATEMENTS = [
        'ALTER TABLE gestion_cash_movement ADD COLUMN IF NOT EXISTS fixed_cost BOOLEAN DEFAULT NULL',
    ];
}
