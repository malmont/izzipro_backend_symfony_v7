<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Lot ABO-2 (20/11/2026, `docs/plan-abo-universel.md` § 3) : agenda des prestations. `benefit_slot_rule` (créneaux récurrents à l'heure
 * LOCALE du magasin), `benefit_slot` (un créneau daté, en UTC ; unicité règle + début), `benefit_slot_hold` (place retenue 30 minutes pendant
 * le paiement d'une souscription, Q1) ; `benefit_occurrence` gagne la date planifiée (`slot_id`, `scheduled_at`, `scheduled_end`,
 * `scheduled_by`, `scheduled_source`). Tables neuves et colonnes vides : aucune donnée existante modifiée. Idempotente ; équivalent
 * `scripts/migrate_all_v2_abo_agenda.sh`.
 */
final class Version20270201900000 extends AbstractMigration
{
    public const STATEMENTS = [
        'CREATE TABLE IF NOT EXISTS benefit_slot_rule (id SERIAL PRIMARY KEY, benefit_id INT NOT NULL REFERENCES benefit (id) ON DELETE CASCADE, weekdays JSON NOT NULL, local_time VARCHAR(5) NOT NULL, duration_minutes INT NOT NULL, capacity INT NOT NULL, location VARCHAR(160) DEFAULT NULL, staff_user_id INT DEFAULT NULL, valid_from DATE DEFAULT NULL, valid_until DATE DEFAULT NULL, active BOOLEAN NOT NULL DEFAULT true, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL)',
        'CREATE INDEX IF NOT EXISTS idx_benefit_slot_rule_benefit ON benefit_slot_rule (benefit_id)',
        'CREATE TABLE IF NOT EXISTS benefit_slot (id SERIAL PRIMARY KEY, benefit_id INT NOT NULL REFERENCES benefit (id) ON DELETE CASCADE, rule_id INT DEFAULT NULL REFERENCES benefit_slot_rule (id) ON DELETE SET NULL, starts_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, ends_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, capacity INT NOT NULL, location VARCHAR(160) DEFAULT NULL, staff_user_id INT DEFAULT NULL, status VARCHAR(12) NOT NULL DEFAULT \'open\', cancelled_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, cancel_reason VARCHAR(300) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL)',
        'CREATE UNIQUE INDEX IF NOT EXISTS uniq_benefit_slot_rule_start ON benefit_slot (rule_id, starts_at) WHERE rule_id IS NOT NULL',
        'CREATE INDEX IF NOT EXISTS idx_benefit_slot_benefit_start ON benefit_slot (benefit_id, starts_at)',
        'CREATE TABLE IF NOT EXISTS benefit_slot_hold (id SERIAL PRIMARY KEY, slot_id INT NOT NULL REFERENCES benefit_slot (id) ON DELETE CASCADE, benefit_id INT NOT NULL, user_id INT NOT NULL, plan_id INT NOT NULL, subscription_id INT DEFAULT NULL REFERENCES subscription (id) ON DELETE CASCADE, expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL)',
        'CREATE INDEX IF NOT EXISTS idx_benefit_slot_hold_slot ON benefit_slot_hold (slot_id, expires_at)',
        'CREATE INDEX IF NOT EXISTS idx_benefit_slot_hold_sub ON benefit_slot_hold (subscription_id)',
        'ALTER TABLE benefit_occurrence ADD COLUMN IF NOT EXISTS slot_id INT DEFAULT NULL REFERENCES benefit_slot (id) ON DELETE SET NULL',
        'ALTER TABLE benefit_occurrence ADD COLUMN IF NOT EXISTS scheduled_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL',
        'ALTER TABLE benefit_occurrence ADD COLUMN IF NOT EXISTS scheduled_end TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL',
        'ALTER TABLE benefit_occurrence ADD COLUMN IF NOT EXISTS scheduled_by VARCHAR(180) DEFAULT NULL',
        'ALTER TABLE benefit_occurrence ADD COLUMN IF NOT EXISTS scheduled_source VARCHAR(10) DEFAULT NULL',
        'CREATE INDEX IF NOT EXISTS idx_benefit_occurrence_slot ON benefit_occurrence (slot_id, status)',
        'CREATE INDEX IF NOT EXISTS idx_benefit_occurrence_scheduled ON benefit_occurrence (status, scheduled_at)',
    ];

    public const DOWN_STATEMENTS = [
        'DROP INDEX IF EXISTS idx_benefit_occurrence_scheduled',
        'DROP INDEX IF EXISTS idx_benefit_occurrence_slot',
        'ALTER TABLE benefit_occurrence DROP COLUMN IF EXISTS scheduled_source',
        'ALTER TABLE benefit_occurrence DROP COLUMN IF EXISTS scheduled_by',
        'ALTER TABLE benefit_occurrence DROP COLUMN IF EXISTS scheduled_end',
        'ALTER TABLE benefit_occurrence DROP COLUMN IF EXISTS scheduled_at',
        'ALTER TABLE benefit_occurrence DROP COLUMN IF EXISTS slot_id',
        'DROP TABLE IF EXISTS benefit_slot_hold',
        'DROP TABLE IF EXISTS benefit_slot',
        'DROP TABLE IF EXISTS benefit_slot_rule',
    ];

    public function getDescription(): string
    {
        return 'ABO-2 : agenda des prestations (règles de créneaux, créneaux, places retenues, date planifiée des droits)';
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
