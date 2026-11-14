<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Prévention des pertes, lot L1-a (14/11/2026, `docs/prevention-pertes.md`) : interrupteur du module (`entreprise.is_loss_prevention_active`,
 * droit `module.loss_prevention`, faux partout) et tables propres au module : réglages et curseur d'analyse (`loss_setting`, une ligne),
 * (`analysis_floor_sequence` : plancher sous lequel le journal de caisse n'est jamais lu, posé à la confirmation de la notice aux salariés là
 * où elle est exigée), réglages des règles (`loss_rule`), constats (`loss_finding`), preuves (`loss_evidence`), journal du module (`loss_journal`).
 *
 * Preuves et journal sont inaltérables, par la technique du journal de caisse (déclencheur qui refuse modification, suppression et vidage).
 * Seule porte : une suppression faite dans une transaction qui a posé `SET LOCAL loss.purge = 'on'`, réservée à la future commande de
 * conservation (aucune purge n'existe dans ce lot : décision du propriétaire, aucune purge automatique sans son accord).
 *
 * Tables et colonne nouvelles seulement ; additive, idempotente ; équivalent `scripts/migrate_all_v2_pertes.sh`.
 */
final class Version20270101100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Prévention des pertes L1-a : constats tirés de la caisse';
    }

    public function up(Schema $schema): void
    {
        foreach (self::STATEMENTS as $sql) {
            $this->addSql($sql);
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS loss_evidence');
        $this->addSql('DROP TABLE IF EXISTS loss_journal');
        $this->addSql('DROP TABLE IF EXISTS loss_finding');
        $this->addSql('DROP TABLE IF EXISTS loss_rule');
        $this->addSql('DROP TABLE IF EXISTS loss_setting');
        $this->addSql('DROP FUNCTION IF EXISTS loss_records_immutable()');
        $this->addSql('ALTER TABLE entreprise DROP COLUMN IF EXISTS is_loss_prevention_active');
    }

    public const STATEMENTS = [
        'ALTER TABLE entreprise ADD COLUMN IF NOT EXISTS is_loss_prevention_active BOOLEAN DEFAULT false NOT NULL',
        'CREATE TABLE IF NOT EXISTS loss_setting (id SERIAL NOT NULL, staff_notice_confirmed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, staff_notice_confirmed_by INT DEFAULT NULL, person_patterns_enabled BOOLEAN DEFAULT NULL, retention_findings_months SMALLINT DEFAULT 24 NOT NULL, retention_confirmed_months SMALLINT DEFAULT 60 NOT NULL, cursor_sequence INT DEFAULT NULL, backfill_until_sequence INT DEFAULT NULL, analysis_floor_sequence INT DEFAULT NULL, last_analysis_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, updated_by INT DEFAULT NULL, PRIMARY KEY(id))',
        'ALTER TABLE loss_setting ADD COLUMN IF NOT EXISTS analysis_floor_sequence INT DEFAULT NULL',
        'CREATE UNIQUE INDEX IF NOT EXISTS uniq_loss_setting_single ON loss_setting ((true))',
        "CREATE TABLE IF NOT EXISTS loss_rule (id SERIAL NOT NULL, code VARCHAR(40) NOT NULL, enabled BOOLEAN DEFAULT true NOT NULL, params JSON NOT NULL, person_enabled BOOLEAN DEFAULT NULL, person_params JSON DEFAULT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, updated_by INT DEFAULT NULL, PRIMARY KEY(id))",
        'CREATE UNIQUE INDEX IF NOT EXISTS uniq_loss_rule_code ON loss_rule (code)',
        "CREATE TABLE IF NOT EXISTS loss_finding (id SERIAL NOT NULL, number INT NOT NULL, rule_code VARCHAR(40) NOT NULL, variant VARCHAR(10) DEFAULT 'rule' NOT NULL, dedup_key VARCHAR(160) NOT NULL, status VARCHAR(12) DEFAULT 'open' NOT NULL, store_day DATE NOT NULL, occurred_from TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, occurred_to TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, cash_register_id INT DEFAULT NULL, session_id INT DEFAULT NULL, amount_at_stake INT DEFAULT NULL, currency VARCHAR(3) NOT NULL, subject_type VARCHAR(20) NOT NULL, subject_id INT NOT NULL, facts JSON NOT NULL, on_duty JSON NOT NULL, backfill BOOLEAN DEFAULT false NOT NULL, seen_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, seen_by INT DEFAULT NULL, decision_note TEXT DEFAULT NULL, decided_by INT DEFAULT NULL, decided_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, last_evidence_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))",
        'CREATE UNIQUE INDEX IF NOT EXISTS uniq_loss_finding_number ON loss_finding (number)',
        'CREATE UNIQUE INDEX IF NOT EXISTS uniq_loss_finding_dedup ON loss_finding (dedup_key)',
        'CREATE INDEX IF NOT EXISTS idx_loss_finding_status ON loss_finding (status, store_day)',
        'CREATE INDEX IF NOT EXISTS idx_loss_finding_subject ON loss_finding (rule_code, variant, subject_type, subject_id)',
        'CREATE TABLE IF NOT EXISTS loss_evidence (id SERIAL NOT NULL, finding_id INT NOT NULL, kind VARCHAR(20) NOT NULL, occurred_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, ref_type VARCHAR(20) NOT NULL, ref_id INT NOT NULL, audit_sequence INT DEFAULT NULL, audit_hash VARCHAR(64) DEFAULT NULL, snapshot TEXT NOT NULL, fingerprint VARCHAR(64) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))',
        'CREATE UNIQUE INDEX IF NOT EXISTS uniq_loss_evidence_ref ON loss_evidence (finding_id, ref_type, ref_id)',
        "CREATE TABLE IF NOT EXISTS loss_journal (id SERIAL NOT NULL, type VARCHAR(30) NOT NULL, occurred_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, user_id INT DEFAULT NULL, finding_id INT DEFAULT NULL, data TEXT NOT NULL, PRIMARY KEY(id))",
        'CREATE INDEX IF NOT EXISTS idx_loss_journal_finding ON loss_journal (finding_id, occurred_at)',
        "CREATE OR REPLACE FUNCTION loss_records_immutable() RETURNS trigger LANGUAGE plpgsql AS \$fn\$ BEGIN IF TG_OP = 'DELETE' AND current_setting('loss.purge', true) = 'on' THEN RETURN OLD; END IF; RAISE EXCEPTION 'prévention des pertes : % inaltérable (% refusé)', TG_TABLE_NAME, TG_OP; END \$fn\$",
        'DROP TRIGGER IF EXISTS loss_evidence_no_change ON loss_evidence',
        'CREATE TRIGGER loss_evidence_no_change BEFORE UPDATE OR DELETE ON loss_evidence FOR EACH ROW EXECUTE FUNCTION loss_records_immutable()',
        'DROP TRIGGER IF EXISTS loss_evidence_no_truncate ON loss_evidence',
        'CREATE TRIGGER loss_evidence_no_truncate BEFORE TRUNCATE ON loss_evidence FOR EACH STATEMENT EXECUTE FUNCTION loss_records_immutable()',
        'DROP TRIGGER IF EXISTS loss_journal_no_change ON loss_journal',
        'CREATE TRIGGER loss_journal_no_change BEFORE UPDATE OR DELETE ON loss_journal FOR EACH ROW EXECUTE FUNCTION loss_records_immutable()',
        'DROP TRIGGER IF EXISTS loss_journal_no_truncate ON loss_journal',
        'CREATE TRIGGER loss_journal_no_truncate BEFORE TRUNCATE ON loss_journal FOR EACH STATEMENT EXECUTE FUNCTION loss_records_immutable()',
    ];
}
