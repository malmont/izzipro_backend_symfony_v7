<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Lot ABO-1 (19/11/2026, `docs/plan-abo-universel.md`) : droits par période des abonnements. `benefit_occurrence` (un droit à profiter,
 * né d'une facture payée ou offert), `subscription_abo_event` (historique : né, utilisé, offert, reporté, perdu, pause) ; réglages de la
 * formule (`single_period` : carte de séances ; `max_pause_days`, `pauses_per_year`) ; pauses datées de l'abonnement (`pauses`) ;
 * résiliation programmée à une date (`cancel_at`, fin d'engagement : décision du chef du 19/11/2026).
 * Tables neuves et colonnes ajoutées avec valeur par défaut : aucune donnée existante modifiée. Idempotente ; équivalent
 * `scripts/migrate_all_v2_abo_rights.sh`.
 */
final class Version20270201500000 extends AbstractMigration
{
    public const STATEMENTS = [
        'CREATE TABLE IF NOT EXISTS benefit_occurrence (id SERIAL PRIMARY KEY, subscription_id INT NOT NULL REFERENCES subscription (id) ON DELETE CASCADE, plan_benefit_id INT DEFAULT NULL REFERENCES plan_benefit (id) ON DELETE SET NULL, benefit_id INT NOT NULL REFERENCES benefit (id) ON DELETE RESTRICT, invoice_id VARCHAR(255) DEFAULT NULL, rank INT NOT NULL DEFAULT 1, origin VARCHAR(10) NOT NULL, status VARCHAR(20) NOT NULL, period_start TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, period_end TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, valid_until TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, carried BOOLEAN NOT NULL DEFAULT false, trial BOOLEAN NOT NULL DEFAULT false, done_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, done_by VARCHAR(180) DEFAULT NULL, note VARCHAR(300) DEFAULT NULL, reason VARCHAR(300) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL)',
        'CREATE UNIQUE INDEX IF NOT EXISTS uniq_benefit_occurrence_birth ON benefit_occurrence (subscription_id, plan_benefit_id, invoice_id, rank) WHERE invoice_id IS NOT NULL',
        'CREATE INDEX IF NOT EXISTS idx_benefit_occurrence_sub ON benefit_occurrence (subscription_id, status, valid_until)',
        'CREATE INDEX IF NOT EXISTS idx_benefit_occurrence_due ON benefit_occurrence (status, valid_until)',
        'CREATE TABLE IF NOT EXISTS subscription_abo_event (id SERIAL PRIMARY KEY, subscription_id INT NOT NULL REFERENCES subscription (id) ON DELETE CASCADE, type VARCHAR(30) NOT NULL, benefit_id INT DEFAULT NULL, quantity INT NOT NULL DEFAULT 0, note VARCHAR(300) DEFAULT NULL, by_user VARCHAR(180) DEFAULT NULL, at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL)',
        'CREATE INDEX IF NOT EXISTS idx_subscription_abo_event_sub ON subscription_abo_event (subscription_id, at)',
        'ALTER TABLE subscription_plan ADD COLUMN IF NOT EXISTS single_period BOOLEAN NOT NULL DEFAULT false',
        'ALTER TABLE subscription_plan ADD COLUMN IF NOT EXISTS max_pause_days INT NOT NULL DEFAULT 90',
        'ALTER TABLE subscription_plan ADD COLUMN IF NOT EXISTS pauses_per_year INT NOT NULL DEFAULT 1',
        'ALTER TABLE subscription ADD COLUMN IF NOT EXISTS pauses JSON DEFAULT NULL',
        'ALTER TABLE subscription ADD COLUMN IF NOT EXISTS cancel_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL',
    ];

    public const DOWN_STATEMENTS = [
        'DROP TABLE IF EXISTS subscription_abo_event',
        'DROP TABLE IF EXISTS benefit_occurrence',
        'ALTER TABLE subscription DROP COLUMN IF EXISTS cancel_at',
        'ALTER TABLE subscription DROP COLUMN IF EXISTS pauses',
        'ALTER TABLE subscription_plan DROP COLUMN IF EXISTS pauses_per_year',
        'ALTER TABLE subscription_plan DROP COLUMN IF EXISTS max_pause_days',
        'ALTER TABLE subscription_plan DROP COLUMN IF EXISTS single_period',
    ];

    public function getDescription(): string
    {
        return 'ABO-1 : droits par période, historique, réglages de pause et carte de séances';
    }

    public function up(Schema $schema): void
    {
        foreach (self::STATEMENTS as $sql) {
            $this->addSql($sql);
        }
    }

    public function down(Schema $schema): void
    {
        foreach (self::DOWN_STATEMENTS as $sql) {
            $this->addSql($sql);
        }
    }
}
