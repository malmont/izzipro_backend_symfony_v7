<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Caisse, chantier C2 (19/10/2026) : journal d'audit inaltérable (pos_audit_log, déclencheur qui refuse modification,
 * suppression et vidage) et numéros de tickets et d'avoirs (pos_receipt). Strictement additif. Équivalent :
 * scripts/migrate_all_v2_pos_audit.sh. Idempotent.
 */
final class Version20261019140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Caisse C2 : journal d\'audit inaltérable et numérotation des tickets et avoirs (additif)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE IF NOT EXISTS pos_audit_log (id SERIAL PRIMARY KEY, sequence INT NOT NULL, type VARCHAR(40) NOT NULL, occurred_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, user_id INT DEFAULT NULL, cash_register_id INT DEFAULT NULL, session_id INT DEFAULT NULL, terminal_id INT DEFAULT NULL, payload TEXT NOT NULL, prev_hash VARCHAR(64) DEFAULT NULL, hash VARCHAR(64) NOT NULL)');
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS uniq_pos_audit_log_sequence ON pos_audit_log (sequence)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_pos_audit_log_register ON pos_audit_log (cash_register_id, occurred_at)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_pos_audit_log_type ON pos_audit_log (type)');
        $this->addSql('CREATE OR REPLACE FUNCTION pos_audit_log_immutable() RETURNS trigger LANGUAGE plpgsql AS $fn$ BEGIN RAISE EXCEPTION \'pos_audit_log : journal de caisse inaltérable (% refusé)\', TG_OP; END $fn$');
        $this->addSql('DROP TRIGGER IF EXISTS pos_audit_log_no_update ON pos_audit_log');
        $this->addSql('CREATE TRIGGER pos_audit_log_no_update BEFORE UPDATE OR DELETE ON pos_audit_log FOR EACH ROW EXECUTE FUNCTION pos_audit_log_immutable()');
        $this->addSql('DROP TRIGGER IF EXISTS pos_audit_log_no_truncate ON pos_audit_log');
        $this->addSql('CREATE TRIGGER pos_audit_log_no_truncate BEFORE TRUNCATE ON pos_audit_log FOR EACH STATEMENT EXECUTE FUNCTION pos_audit_log_immutable()');
        $this->addSql('CREATE TABLE IF NOT EXISTS pos_receipt (id SERIAL PRIMARY KEY, cash_register_id INT NOT NULL, kind VARCHAR(12) NOT NULL, number INT NOT NULL, order_id INT NOT NULL, caisse_id INT DEFAULT NULL, amount INT NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, CONSTRAINT fk_pos_receipt_register FOREIGN KEY (cash_register_id) REFERENCES cash_register (id), CONSTRAINT fk_pos_receipt_order FOREIGN KEY (order_id) REFERENCES "order" (id), CONSTRAINT fk_pos_receipt_session FOREIGN KEY (caisse_id) REFERENCES caisse (id))');
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS uniq_pos_receipt_number ON pos_receipt (cash_register_id, kind, number)');
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS uniq_pos_receipt_order ON pos_receipt (order_id, kind)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_pos_receipt_session ON pos_receipt (caisse_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS pos_receipt');
        $this->addSql('DROP TABLE IF EXISTS pos_audit_log');
        $this->addSql('DROP FUNCTION IF EXISTS pos_audit_log_immutable()');
    }
}
