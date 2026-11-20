<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Lot ABO-3a (20/11/2026, `docs/plan-abo-universel.md` § 4 et § 7) : « Fait » et suivi des droits. `benefit_occurrence` gagne la preuve
 * (photo réencodée sans métadonnées, rangée en stockage privé : chemin, empreinte, type, date), la date du courriel « fait » (un seul par
 * droit), l'avis de l'abonné (confirmé ou contesté, commentaire, date, demande d'Assistance ouverte) et la décision de l'administrateur
 * sur une contestation ; `subscription.done_emails` : l'abonné reçoit-il le courriel « fait » (oui par défaut, il peut le couper).
 * Colonnes vides ou à leur valeur par défaut : aucune donnée existante modifiée. Idempotente ; équivalent `scripts/migrate_all_v2_abo_fait.sh`.
 */
final class Version20270202100000 extends AbstractMigration
{
    public const STATEMENTS = [
        'ALTER TABLE benefit_occurrence ADD COLUMN IF NOT EXISTS proof_path VARCHAR(80) DEFAULT NULL',
        'ALTER TABLE benefit_occurrence ADD COLUMN IF NOT EXISTS proof_sha256 VARCHAR(64) DEFAULT NULL',
        'ALTER TABLE benefit_occurrence ADD COLUMN IF NOT EXISTS proof_mime VARCHAR(20) DEFAULT NULL',
        'ALTER TABLE benefit_occurrence ADD COLUMN IF NOT EXISTS proof_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL',
        'ALTER TABLE benefit_occurrence ADD COLUMN IF NOT EXISTS done_notified_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL',
        'ALTER TABLE benefit_occurrence ADD COLUMN IF NOT EXISTS customer_ack VARCHAR(12) DEFAULT NULL',
        'ALTER TABLE benefit_occurrence ADD COLUMN IF NOT EXISTS customer_ack_comment VARCHAR(1000) DEFAULT NULL',
        'ALTER TABLE benefit_occurrence ADD COLUMN IF NOT EXISTS customer_ack_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL',
        'ALTER TABLE benefit_occurrence ADD COLUMN IF NOT EXISTS ack_ticket_ref VARCHAR(20) DEFAULT NULL',
        'ALTER TABLE benefit_occurrence ADD COLUMN IF NOT EXISTS ack_resolution VARCHAR(12) DEFAULT NULL',
        'ALTER TABLE benefit_occurrence ADD COLUMN IF NOT EXISTS ack_resolved_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL',
        'ALTER TABLE benefit_occurrence ADD COLUMN IF NOT EXISTS ack_resolved_by VARCHAR(180) DEFAULT NULL',
        'CREATE INDEX IF NOT EXISTS idx_benefit_occurrence_ack ON benefit_occurrence (customer_ack, ack_resolution)',
        'CREATE INDEX IF NOT EXISTS idx_benefit_occurrence_proof ON benefit_occurrence (proof_at) WHERE proof_path IS NOT NULL',
        'ALTER TABLE subscription ADD COLUMN IF NOT EXISTS done_emails BOOLEAN NOT NULL DEFAULT true',
    ];

    public const DOWN_STATEMENTS = [
        'ALTER TABLE subscription DROP COLUMN IF EXISTS done_emails',
        'DROP INDEX IF EXISTS idx_benefit_occurrence_proof',
        'DROP INDEX IF EXISTS idx_benefit_occurrence_ack',
        'ALTER TABLE benefit_occurrence DROP COLUMN IF EXISTS ack_resolved_by',
        'ALTER TABLE benefit_occurrence DROP COLUMN IF EXISTS ack_resolved_at',
        'ALTER TABLE benefit_occurrence DROP COLUMN IF EXISTS ack_resolution',
        'ALTER TABLE benefit_occurrence DROP COLUMN IF EXISTS ack_ticket_ref',
        'ALTER TABLE benefit_occurrence DROP COLUMN IF EXISTS customer_ack_at',
        'ALTER TABLE benefit_occurrence DROP COLUMN IF EXISTS customer_ack_comment',
        'ALTER TABLE benefit_occurrence DROP COLUMN IF EXISTS customer_ack',
        'ALTER TABLE benefit_occurrence DROP COLUMN IF EXISTS done_notified_at',
        'ALTER TABLE benefit_occurrence DROP COLUMN IF EXISTS proof_at',
        'ALTER TABLE benefit_occurrence DROP COLUMN IF EXISTS proof_mime',
        'ALTER TABLE benefit_occurrence DROP COLUMN IF EXISTS proof_sha256',
        'ALTER TABLE benefit_occurrence DROP COLUMN IF EXISTS proof_path',
    ];

    public function getDescription(): string
    {
        return 'ABO-3a : « Fait » et suivi des droits (preuve, courriel, avis de l\'abonné, contestation, courriel coupé par l\'abonné)';
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
