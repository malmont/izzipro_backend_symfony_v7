<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Lot ABO-0 (18/11/2026, `docs/plan-abo-universel.md`) : prestations du site (`benefit`) et prestations livrées par une formule
 * d'abonnement (`plan_benefit`). Tables neuves, aucune table existante modifiée ; idempotente ; équivalent
 * `scripts/migrate_all_v2_abo_benefits.sh`.
 */
final class Version20270201400000 extends AbstractMigration
{
    public const STATEMENTS = [
        'CREATE TABLE IF NOT EXISTS benefit (id SERIAL PRIMARY KEY, kind VARCHAR(20) NOT NULL, names JSON NOT NULL, descriptions JSON DEFAULT NULL, duration_minutes INT DEFAULT NULL, location VARCHAR(160) DEFAULT NULL, default_staff_user_id INT DEFAULT NULL, active BOOLEAN NOT NULL DEFAULT true, position INT NOT NULL DEFAULT 0, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL)',
        "COMMENT ON COLUMN benefit.created_at IS '(DC2Type:datetime_immutable)'",
        "COMMENT ON COLUMN benefit.updated_at IS '(DC2Type:datetime_immutable)'",
        'CREATE TABLE IF NOT EXISTS plan_benefit (id SERIAL PRIMARY KEY, plan_id INT NOT NULL REFERENCES subscription_plan (id) ON DELETE CASCADE, benefit_id INT NOT NULL REFERENCES benefit (id) ON DELETE RESTRICT, quantity INT DEFAULT NULL, scheduling VARCHAR(30) NOT NULL, slot_choice VARCHAR(10) DEFAULT NULL, cancel_deadline_hours INT NOT NULL DEFAULT 24, carry_over_max INT DEFAULT NULL, proof VARCHAR(10) NOT NULL, position INT NOT NULL DEFAULT 0, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL)',
        "COMMENT ON COLUMN plan_benefit.created_at IS '(DC2Type:datetime_immutable)'",
        'CREATE UNIQUE INDEX IF NOT EXISTS uniq_plan_benefit ON plan_benefit (plan_id, benefit_id)',
        'CREATE INDEX IF NOT EXISTS idx_plan_benefit_benefit ON plan_benefit (benefit_id)',
    ];

    public const DOWN_STATEMENTS = [
        'DROP TABLE IF EXISTS plan_benefit',
        'DROP TABLE IF EXISTS benefit',
    ];

    public function getDescription(): string
    {
        return 'ABO-0 : prestations du site et prestations des formules d\'abonnement';
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
