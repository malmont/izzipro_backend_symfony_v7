<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Prévention des pertes, lot L2-a « étiquettes à puce » (15/11/2026, `docs/prevention-pertes.md` § 13) : la PIÈCE (un exemplaire
 * physique d'une variante, numéro unique sur le site), la PUCE qu'elle porte, et les FAITS qui les font changer. Tables du module
 * seulement : aucune table existante n'est touchée ; la pièce ne remplace pas le stock et n'a aucune clé étrangère vers le catalogue.
 *
 * Ce que la base garantit ELLE-MÊME, quel que soit le chemin d'écriture :
 * - un identifiant de puce désigne une seule puce sur le site (index unique), en forme canonique (majuscules, sans espace autour) ;
 * - une puce associée ne change jamais de pièce ; une puce retirée ne revient pas ; identifiant, technologie et marque d'exercice
 *   d'une puce sont figés ; une puce simulée est une puce d'exercice, et elle seule ; une puce ne se pose que sur une pièce qui
 *   existe, qui n'est pas retirée, et du même genre qu'elle (réelle ou d'exercice) ;
 * - numéro, variante, article et marque d'exercice d'une pièce sont figés ; une pièce retirée ne revient pas ;
 * - un fait ne se modifie jamais ;
 * - rien ne se supprime ni ne se vide, sauf les lignes d'EXERCICE, par la porte déjà en place (`SET LOCAL loss.purge = 'exercise'`).
 *
 * Tables, séquence, index, fonctions et déclencheurs nouveaux seulement ; additive, idempotente ;
 * équivalent `scripts/migrate_all_v2_pertes_l2a.sh`.
 */
final class Version20270109100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Prévention des pertes L2-a : pièces, puces et leurs faits';
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

    /** Retour arrière : les trois tables et ce qui les garde disparaissent, avec leurs lignes (tables propres au lot) */
    public const DOWN_STATEMENTS = [
        'DROP TABLE IF EXISTS loss_piece_event',
        'DROP TABLE IF EXISTS loss_tag',
        'DROP TABLE IF EXISTS loss_piece',
        'DROP FUNCTION IF EXISTS loss_piece_event_frozen()',
        'DROP FUNCTION IF EXISTS loss_piece_rows_kept()',
        'DROP FUNCTION IF EXISTS loss_tag_guard()',
        'DROP FUNCTION IF EXISTS loss_piece_guard()',
        'DROP SEQUENCE IF EXISTS loss_piece_number_seq',
    ];

    public const STATEMENTS = [
        'CREATE SEQUENCE IF NOT EXISTS loss_piece_number_seq',
        "CREATE TABLE IF NOT EXISTS loss_piece (id SERIAL NOT NULL, number INT NOT NULL, variant_id INT NOT NULL, product_id INT NOT NULL, status VARCHAR(16) DEFAULT 'created' NOT NULL, zone VARCHAR(20) DEFAULT NULL, commande_id INT DEFAULT NULL, exercise BOOLEAN DEFAULT false NOT NULL, retired_reason VARCHAR(20) DEFAULT NULL, retired_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_by INT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))",
        'CREATE UNIQUE INDEX IF NOT EXISTS uniq_loss_piece_number ON loss_piece (number)',
        'CREATE INDEX IF NOT EXISTS idx_loss_piece_variant ON loss_piece (variant_id, status)',
        'CREATE INDEX IF NOT EXISTS idx_loss_piece_exercise ON loss_piece (id) WHERE exercise',
        'CREATE TABLE IF NOT EXISTS loss_tag (id SERIAL NOT NULL, identifier VARCHAR(64) NOT NULL, raw VARCHAR(128) NOT NULL, technology VARCHAR(12) NOT NULL, status VARCHAR(12) NOT NULL, piece_id INT DEFAULT NULL, exercise BOOLEAN DEFAULT false NOT NULL, retired_reason VARCHAR(20) DEFAULT NULL, associated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, retired_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_by INT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))',
        'CREATE UNIQUE INDEX IF NOT EXISTS uniq_loss_tag_identifier ON loss_tag (identifier)',
        'CREATE INDEX IF NOT EXISTS idx_loss_tag_piece ON loss_tag (piece_id)',
        'CREATE INDEX IF NOT EXISTS idx_loss_tag_exercise ON loss_tag (id) WHERE exercise',
        "CREATE TABLE IF NOT EXISTS loss_piece_event (id SERIAL NOT NULL, type VARCHAR(20) NOT NULL, occurred_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, user_id INT DEFAULT NULL, piece_id INT DEFAULT NULL, tag_id INT DEFAULT NULL, source VARCHAR(12) DEFAULT 'app' NOT NULL, event_key VARCHAR(64) DEFAULT NULL, exercise BOOLEAN DEFAULT false NOT NULL, data TEXT NOT NULL, PRIMARY KEY(id))",
        'CREATE INDEX IF NOT EXISTS idx_loss_piece_event_piece ON loss_piece_event (piece_id, id)',
        'CREATE INDEX IF NOT EXISTS idx_loss_piece_event_tag ON loss_piece_event (tag_id, id)',
        'CREATE UNIQUE INDEX IF NOT EXISTS uniq_loss_piece_event_key ON loss_piece_event (event_key) WHERE event_key IS NOT NULL',
        'CREATE INDEX IF NOT EXISTS idx_loss_piece_event_exercise ON loss_piece_event (id) WHERE exercise',
        // Pièce : états connus ; identité figée ; une pièce retirée ne revient pas
        "CREATE OR REPLACE FUNCTION loss_piece_guard() RETURNS trigger LANGUAGE plpgsql AS \$fn\$ BEGIN"
        . " IF NEW.status NOT IN ('created', 'in_stockroom', 'on_floor', 'at_counter', 'sold', 'returned', 'missing', 'retired') THEN RAISE EXCEPTION 'prévention des pertes : état de pièce inconnu' USING ERRCODE = 'check_violation'; END IF;"
        . " IF TG_OP = 'UPDATE' THEN"
        . " IF NEW.id <> OLD.id OR NEW.number <> OLD.number OR NEW.variant_id <> OLD.variant_id OR NEW.product_id <> OLD.product_id OR NEW.exercise <> OLD.exercise THEN RAISE EXCEPTION 'prévention des pertes : le numéro, la variante et la marque d''exercice d''une pièce ne changent pas' USING ERRCODE = 'check_violation'; END IF;"
        . " IF OLD.status = 'retired' AND NEW.status <> 'retired' THEN RAISE EXCEPTION 'prévention des pertes : une pièce retirée ne revient pas' USING ERRCODE = 'check_violation'; END IF;"
        . " END IF; RETURN NEW; END \$fn\$",
        // Puce : forme canonique, valeurs connues, identité figée, jamais deux pièces, jamais de retour, pièce du même genre
        "CREATE OR REPLACE FUNCTION loss_tag_guard() RETURNS trigger LANGUAGE plpgsql AS \$fn\$ DECLARE newly_set BOOLEAN := false; BEGIN"
        . " IF NEW.identifier = '' OR NEW.identifier <> UPPER(BTRIM(NEW.identifier)) THEN RAISE EXCEPTION 'prévention des pertes : identifiant de puce hors de sa forme canonique' USING ERRCODE = 'check_violation'; END IF;"
        . " IF NEW.technology NOT IN ('uhf', 'nfc', 'simulated') OR NEW.status NOT IN ('known', 'associated', 'retired') THEN RAISE EXCEPTION 'prévention des pertes : technologie ou état de puce inconnu' USING ERRCODE = 'check_violation'; END IF;"
        . " IF NEW.exercise <> (NEW.technology = 'simulated') THEN RAISE EXCEPTION 'prévention des pertes : une puce simulée est une puce d''exercice, et elle seule' USING ERRCODE = 'check_violation'; END IF;"
        . " IF NEW.status = 'associated' AND NEW.piece_id IS NULL THEN RAISE EXCEPTION 'prévention des pertes : une puce associée porte sa pièce' USING ERRCODE = 'check_violation'; END IF;"
        . " IF TG_OP = 'UPDATE' THEN"
        . " IF NEW.id <> OLD.id OR NEW.identifier <> OLD.identifier OR NEW.technology <> OLD.technology OR NEW.exercise <> OLD.exercise THEN RAISE EXCEPTION 'prévention des pertes : l''identifiant, la technologie et la marque d''exercice d''une puce ne changent pas' USING ERRCODE = 'check_violation'; END IF;"
        . " IF OLD.piece_id IS NOT NULL AND NEW.piece_id IS DISTINCT FROM OLD.piece_id THEN RAISE EXCEPTION 'prévention des pertes : une puce associée ne change jamais de pièce' USING ERRCODE = 'check_violation'; END IF;"
        . " IF OLD.status = 'retired' AND NEW.status <> 'retired' THEN RAISE EXCEPTION 'prévention des pertes : une puce retirée ne revient pas' USING ERRCODE = 'check_violation'; END IF;"
        . " newly_set := NEW.piece_id IS NOT NULL AND OLD.piece_id IS NULL;"
        . " ELSE newly_set := NEW.piece_id IS NOT NULL;"
        . " END IF;"
        . " IF newly_set THEN"
        . " PERFORM 1 FROM loss_piece p WHERE p.id = NEW.piece_id AND p.exercise = NEW.exercise AND p.status <> 'retired' FOR SHARE;"
        . " IF NOT FOUND THEN RAISE EXCEPTION 'prévention des pertes : pièce inconnue, retirée ou d''un autre genre que la puce' USING ERRCODE = 'foreign_key_violation'; END IF;"
        . " END IF; RETURN NEW; END \$fn\$",
        // Rien ne se supprime ni ne se vide, sauf une ligne d'exercice par la porte « exercise » (pièces, puces, faits : même colonne)
        "CREATE OR REPLACE FUNCTION loss_piece_rows_kept() RETURNS trigger LANGUAGE plpgsql AS \$fn\$ BEGIN"
        . " IF TG_OP = 'DELETE' THEN IF current_setting('loss.purge', true) = 'exercise' AND OLD.exercise THEN RETURN OLD; END IF; END IF;"
        . " RAISE EXCEPTION 'prévention des pertes : % ne se supprime pas (% refusé)', TG_TABLE_NAME, TG_OP; END \$fn\$",
        // Un fait ne se modifie jamais
        "CREATE OR REPLACE FUNCTION loss_piece_event_frozen() RETURNS trigger LANGUAGE plpgsql AS \$fn\$ BEGIN RAISE EXCEPTION 'prévention des pertes : un fait ne se modifie pas'; END \$fn\$",
        'DROP TRIGGER IF EXISTS loss_piece_guard ON loss_piece',
        'CREATE TRIGGER loss_piece_guard BEFORE INSERT OR UPDATE ON loss_piece FOR EACH ROW EXECUTE FUNCTION loss_piece_guard()',
        'DROP TRIGGER IF EXISTS loss_tag_guard ON loss_tag',
        'CREATE TRIGGER loss_tag_guard BEFORE INSERT OR UPDATE ON loss_tag FOR EACH ROW EXECUTE FUNCTION loss_tag_guard()',
        'DROP TRIGGER IF EXISTS loss_piece_event_frozen ON loss_piece_event',
        'CREATE TRIGGER loss_piece_event_frozen BEFORE UPDATE ON loss_piece_event FOR EACH ROW EXECUTE FUNCTION loss_piece_event_frozen()',
        'DROP TRIGGER IF EXISTS loss_piece_no_delete ON loss_piece',
        'CREATE TRIGGER loss_piece_no_delete BEFORE DELETE ON loss_piece FOR EACH ROW EXECUTE FUNCTION loss_piece_rows_kept()',
        'DROP TRIGGER IF EXISTS loss_tag_no_delete ON loss_tag',
        'CREATE TRIGGER loss_tag_no_delete BEFORE DELETE ON loss_tag FOR EACH ROW EXECUTE FUNCTION loss_piece_rows_kept()',
        'DROP TRIGGER IF EXISTS loss_piece_event_no_delete ON loss_piece_event',
        'CREATE TRIGGER loss_piece_event_no_delete BEFORE DELETE ON loss_piece_event FOR EACH ROW EXECUTE FUNCTION loss_piece_rows_kept()',
        'DROP TRIGGER IF EXISTS loss_piece_no_truncate ON loss_piece',
        'CREATE TRIGGER loss_piece_no_truncate BEFORE TRUNCATE ON loss_piece FOR EACH STATEMENT EXECUTE FUNCTION loss_piece_rows_kept()',
        'DROP TRIGGER IF EXISTS loss_tag_no_truncate ON loss_tag',
        'CREATE TRIGGER loss_tag_no_truncate BEFORE TRUNCATE ON loss_tag FOR EACH STATEMENT EXECUTE FUNCTION loss_piece_rows_kept()',
        'DROP TRIGGER IF EXISTS loss_piece_event_no_truncate ON loss_piece_event',
        'CREATE TRIGGER loss_piece_event_no_truncate BEFORE TRUNCATE ON loss_piece_event FOR EACH STATEMENT EXECUTE FUNCTION loss_piece_rows_kept()',
    ];
}
