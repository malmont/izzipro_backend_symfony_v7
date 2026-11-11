<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Lot RAP (11/11/2026, `docs/tresorerie-gestion.md` § rapprochement) : décision sur l'écart d'un solde (`gestion_cash_balance` : attendu à la
 * saisie, décision, note, auteur, date) et tolérance du rapprochement (`gestion_cash_setting.tolerance_cents`, 100 par défaut). Additive,
 * idempotente ; équivalent `scripts/migrate_all_v2_gestion_rapprochement.sh` pour toutes les bases.
 */
final class Version20261215100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Lot RAP : rapprochement bancaire des soldes de trésorerie';
    }

    public function up(Schema $schema): void
    {
        foreach (self::STATEMENTS as $sql) {
            $this->addSql($sql);
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE gestion_cash_balance DROP COLUMN IF EXISTS expected_at_entry, DROP COLUMN IF EXISTS gap_decision, DROP COLUMN IF EXISTS gap_note, DROP COLUMN IF EXISTS gap_decided_by, DROP COLUMN IF EXISTS gap_decided_at');
        $this->addSql('ALTER TABLE gestion_cash_setting DROP COLUMN IF EXISTS tolerance_cents');
    }

    public const STATEMENTS = [
        'ALTER TABLE gestion_cash_balance ADD COLUMN IF NOT EXISTS expected_at_entry BIGINT DEFAULT NULL, ADD COLUMN IF NOT EXISTS gap_decision VARCHAR(12) DEFAULT NULL, ADD COLUMN IF NOT EXISTS gap_note VARCHAR(500) DEFAULT NULL, ADD COLUMN IF NOT EXISTS gap_decided_by VARCHAR(255) DEFAULT NULL, ADD COLUMN IF NOT EXISTS gap_decided_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL',
        'ALTER TABLE gestion_cash_setting ADD COLUMN IF NOT EXISTS tolerance_cents BIGINT NOT NULL DEFAULT 100',
    ];
}
