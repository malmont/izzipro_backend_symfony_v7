<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Prévention des pertes, lot L3-a « boîtier » (15/11/2026, `docs/prevention-pertes.md` § 16) : le BOÎTIER de la boutique, les
 * APPAREILS qu'il déclare, et les FAITS qui le concernent. Tables du module seulement ; la table des faits de pièces gagne trois
 * colonnes facultatives (le boîtier et l'appareil qui ont vu la pièce, l'heure de réception). Aucune autre table n'est touchée.
 *
 * Ce que la base garantit ELLE-MÊME, quel que soit le chemin d'écriture :
 * - une clé de boîtier (son empreinte) désigne un seul boîtier ; un vrai boîtier a toujours une clé, un boîtier d'exercice n'en a
 *   jamais (aucune machine ne peut s'inscrire à sa place) ;
 * - un boîtier révoqué ne revient pas : ni son état, ni sa date de révocation, ni sa clé, ni sa génération ne changent plus ; la
 *   génération (ce qui périme les jetons) ne recule jamais ; la marque d'exercice, l'auteur et la date de création sont figés ;
 * - un boîtier actif qui n'est pas d'exercice est lié à une machine ;
 * - un appareil appartient à un boîtier qui existe, du même genre (réel ou exercice) que lui ; son boîtier, son numéro de série,
 *   son genre et sa marque d'exercice ne changent pas ; un numéro de série désigne un seul appareil par boîtier ;
 * - un fait de boîtier ne se modifie jamais ; il désigne un boîtier du même genre que lui, et un appareil de CE boîtier ;
 * - un fait de pièce venu d'un boîtier désigne un boîtier du même genre que lui, et un appareil de ce boîtier ;
 * - rien ne se supprime ni ne se vide, sauf les lignes d'EXERCICE, par la porte déjà en place (`SET LOCAL loss.purge = 'exercise'`),
 *   dans un ordre imposé : les faits, puis les appareils, puis les boîtiers.
 *
 * Tables, colonnes, index, fonctions et déclencheurs nouveaux seulement ; additive, idempotente ;
 * équivalent `scripts/migrate_all_v2_pertes_l3a.sh`.
 */
final class Version20270113100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Prévention des pertes L3-a : boîtiers, appareils et leurs faits';
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

    /** Retour arrière : les trois tables, les trois colonnes et ce qui les garde disparaissent, avec leurs lignes (propres au lot) */
    public const DOWN_STATEMENTS = [
        'DROP TRIGGER IF EXISTS loss_piece_event_box_guard ON loss_piece_event',
        'DROP INDEX IF EXISTS idx_loss_piece_event_box',
        'ALTER TABLE loss_piece_event DROP COLUMN IF EXISTS box_id',
        'ALTER TABLE loss_piece_event DROP COLUMN IF EXISTS device_id',
        'ALTER TABLE loss_piece_event DROP COLUMN IF EXISTS received_at',
        'DROP TABLE IF EXISTS loss_box_event',
        'DROP TABLE IF EXISTS loss_device',
        'DROP TABLE IF EXISTS loss_box',
        'DROP FUNCTION IF EXISTS loss_piece_event_box_guard()',
        'DROP FUNCTION IF EXISTS loss_box_event_frozen()',
        'DROP FUNCTION IF EXISTS loss_box_event_guard()',
        'DROP FUNCTION IF EXISTS loss_box_rows_kept()',
        'DROP FUNCTION IF EXISTS loss_device_guard()',
        'DROP FUNCTION IF EXISTS loss_box_guard()',
    ];

    public const STATEMENTS = [
        "CREATE TABLE IF NOT EXISTS loss_box (id SERIAL NOT NULL, name VARCHAR(80) NOT NULL, place VARCHAR(80) DEFAULT NULL, key_hash VARCHAR(64) DEFAULT NULL, key_hint VARCHAR(24) DEFAULT NULL, status VARCHAR(12) DEFAULT 'pending' NOT NULL, generation INT DEFAULT 1 NOT NULL, machine_hash VARCHAR(64) DEFAULT NULL, version VARCHAR(40) DEFAULT NULL, arch VARCHAR(16) DEFAULT NULL, enrolled_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, last_seen_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, clock_offset INT DEFAULT NULL, exercise BOOLEAN DEFAULT false NOT NULL, created_by INT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, revoked_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id))",
        'CREATE UNIQUE INDEX IF NOT EXISTS uniq_loss_box_key ON loss_box (key_hash) WHERE key_hash IS NOT NULL',
        'CREATE INDEX IF NOT EXISTS idx_loss_box_exercise ON loss_box (id) WHERE exercise',
        "CREATE TABLE IF NOT EXISTS loss_device (id SERIAL NOT NULL, box_id INT NOT NULL, serial VARCHAR(64) NOT NULL, kind VARCHAR(16) NOT NULL, model VARCHAR(60) DEFAULT NULL, status VARCHAR(8) DEFAULT 'ok' NOT NULL, zone VARCHAR(20) DEFAULT NULL, cash_register_id INT DEFAULT NULL, exercise BOOLEAN DEFAULT false NOT NULL, first_seen_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, last_seen_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))",
        'CREATE UNIQUE INDEX IF NOT EXISTS uniq_loss_device_box_serial ON loss_device (box_id, serial)',
        "CREATE TABLE IF NOT EXISTS loss_box_event (id SERIAL NOT NULL, box_id INT NOT NULL, device_id INT DEFAULT NULL, type VARCHAR(20) NOT NULL, occurred_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, user_id INT DEFAULT NULL, exercise BOOLEAN DEFAULT false NOT NULL, data TEXT NOT NULL, PRIMARY KEY(id))",
        'CREATE INDEX IF NOT EXISTS idx_loss_box_event_box ON loss_box_event (box_id, id)',
        'CREATE INDEX IF NOT EXISTS idx_loss_box_event_exercise ON loss_box_event (id) WHERE exercise',
        // Faits de pièces venus d'un boîtier : qui a vu, par quel appareil, et quand le serveur l'a reçu
        'ALTER TABLE loss_piece_event ADD COLUMN IF NOT EXISTS box_id INT DEFAULT NULL',
        'ALTER TABLE loss_piece_event ADD COLUMN IF NOT EXISTS device_id INT DEFAULT NULL',
        'ALTER TABLE loss_piece_event ADD COLUMN IF NOT EXISTS received_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL',
        'CREATE INDEX IF NOT EXISTS idx_loss_piece_event_box ON loss_piece_event (box_id, id) WHERE box_id IS NOT NULL',
        // Boîtier : états connus ; clé selon le genre ; identité figée ; un boîtier révoqué ne revient pas ; la génération ne recule pas
        "CREATE OR REPLACE FUNCTION loss_box_guard() RETURNS trigger LANGUAGE plpgsql AS \$fn\$ BEGIN"
        . " IF NEW.status NOT IN ('pending', 'active', 'revoked') THEN RAISE EXCEPTION 'prévention des pertes : état de boîtier inconnu' USING ERRCODE = 'check_violation'; END IF;"
        . " IF NEW.exercise <> (NEW.key_hash IS NULL) THEN RAISE EXCEPTION 'prévention des pertes : un vrai boîtier a une clé, un boîtier d''exercice n''en a pas' USING ERRCODE = 'check_violation'; END IF;"
        . " IF NEW.status = 'active' AND NOT NEW.exercise AND NEW.machine_hash IS NULL THEN RAISE EXCEPTION 'prévention des pertes : un boîtier actif est lié à une machine' USING ERRCODE = 'check_violation'; END IF;"
        . " IF TG_OP = 'UPDATE' THEN"
        . " IF NEW.id <> OLD.id OR NEW.exercise <> OLD.exercise OR NEW.created_by IS DISTINCT FROM OLD.created_by OR NEW.created_at <> OLD.created_at"
        . " THEN RAISE EXCEPTION 'prévention des pertes : la marque d''exercice et l''origine d''un boîtier ne changent pas' USING ERRCODE = 'check_violation'; END IF;"
        . " IF NEW.generation < OLD.generation THEN RAISE EXCEPTION 'prévention des pertes : la génération d''un boîtier ne recule pas' USING ERRCODE = 'check_violation'; END IF;"
        . " IF OLD.status = 'revoked' AND (NEW.status <> 'revoked' OR NEW.revoked_at IS DISTINCT FROM OLD.revoked_at OR NEW.key_hash IS DISTINCT FROM OLD.key_hash"
        . " OR NEW.generation <> OLD.generation OR NEW.machine_hash IS DISTINCT FROM OLD.machine_hash)"
        . " THEN RAISE EXCEPTION 'prévention des pertes : un boîtier révoqué ne revient pas' USING ERRCODE = 'check_violation'; END IF;"
        . " END IF; RETURN NEW; END \$fn\$",
        // Appareil : valeurs connues, identité figée, boîtier du même genre
        "CREATE OR REPLACE FUNCTION loss_device_guard() RETURNS trigger LANGUAGE plpgsql AS \$fn\$ BEGIN"
        . " IF NEW.kind NOT IN ('counter_mat', 'reader', 'printer', 'camera') OR NEW.status NOT IN ('ok', 'down') THEN RAISE EXCEPTION 'prévention des pertes : genre ou état d''appareil inconnu' USING ERRCODE = 'check_violation'; END IF;"
        . " IF NEW.zone IS NOT NULL AND NEW.zone NOT IN ('stockroom', 'stockroom_door', 'floor', 'counter', 'exit') THEN RAISE EXCEPTION 'prévention des pertes : zone inconnue' USING ERRCODE = 'check_violation'; END IF;"
        . " IF NEW.serial = '' THEN RAISE EXCEPTION 'prévention des pertes : numéro de série vide' USING ERRCODE = 'check_violation'; END IF;"
        . " IF TG_OP = 'UPDATE' THEN"
        . " IF NEW.id <> OLD.id OR NEW.box_id <> OLD.box_id OR NEW.serial <> OLD.serial OR NEW.kind <> OLD.kind OR NEW.exercise <> OLD.exercise OR NEW.first_seen_at <> OLD.first_seen_at"
        . " THEN RAISE EXCEPTION 'prévention des pertes : le boîtier, le numéro de série, le genre et la marque d''exercice d''un appareil ne changent pas' USING ERRCODE = 'check_violation'; END IF;"
        . " ELSE PERFORM 1 FROM loss_box b WHERE b.id = NEW.box_id AND b.exercise = NEW.exercise FOR SHARE;"
        . " IF NOT FOUND THEN RAISE EXCEPTION 'prévention des pertes : boîtier inconnu ou d''un autre genre que l''appareil' USING ERRCODE = 'foreign_key_violation'; END IF;"
        . " END IF; RETURN NEW; END \$fn\$",
        // Un fait de boîtier désigne un boîtier du même genre que lui, et un appareil de ce boîtier
        "CREATE OR REPLACE FUNCTION loss_box_event_guard() RETURNS trigger LANGUAGE plpgsql AS \$fn\$ BEGIN"
        . " PERFORM 1 FROM loss_box b WHERE b.id = NEW.box_id AND b.exercise = NEW.exercise;"
        . " IF NOT FOUND THEN RAISE EXCEPTION 'prévention des pertes : le fait désigne un boîtier inconnu ou d''un autre genre' USING ERRCODE = 'foreign_key_violation'; END IF;"
        . " IF NEW.device_id IS NOT NULL THEN PERFORM 1 FROM loss_device d WHERE d.id = NEW.device_id AND d.box_id = NEW.box_id;"
        . " IF NOT FOUND THEN RAISE EXCEPTION 'prévention des pertes : le fait désigne un appareil inconnu ou d''un autre boîtier' USING ERRCODE = 'foreign_key_violation'; END IF; END IF;"
        . " RETURN NEW; END \$fn\$",
        // Un fait de boîtier ne se modifie jamais
        "CREATE OR REPLACE FUNCTION loss_box_event_frozen() RETURNS trigger LANGUAGE plpgsql AS \$fn\$ BEGIN RAISE EXCEPTION 'prévention des pertes : un fait ne se modifie pas'; END \$fn\$",
        // Rien ne se supprime ni ne se vide, sauf une ligne d'exercice par la porte « exercise », dans l'ordre : faits, appareils, boîtiers
        "CREATE OR REPLACE FUNCTION loss_box_rows_kept() RETURNS trigger LANGUAGE plpgsql AS \$fn\$ BEGIN"
        . " IF TG_OP = 'DELETE' THEN IF current_setting('loss.purge', true) = 'exercise' AND OLD.exercise THEN"
        . " IF TG_TABLE_NAME = 'loss_box' AND (EXISTS (SELECT 1 FROM loss_device d WHERE d.box_id = OLD.id) OR EXISTS (SELECT 1 FROM loss_box_event e WHERE e.box_id = OLD.id)"
        . " OR EXISTS (SELECT 1 FROM loss_piece_event e WHERE e.box_id = OLD.id))"
        . " THEN RAISE EXCEPTION 'prévention des pertes : un boîtier ne part pas tant qu''un appareil ou un fait le désigne'; END IF;"
        . " IF TG_TABLE_NAME = 'loss_device' AND (EXISTS (SELECT 1 FROM loss_box_event e WHERE e.device_id = OLD.id) OR EXISTS (SELECT 1 FROM loss_piece_event e WHERE e.device_id = OLD.id))"
        . " THEN RAISE EXCEPTION 'prévention des pertes : un appareil ne part pas tant qu''un fait le désigne'; END IF;"
        . " RETURN OLD; END IF; END IF;"
        . " RAISE EXCEPTION 'prévention des pertes : % ne se supprime pas (% refusé)', TG_TABLE_NAME, TG_OP; END \$fn\$",
        // Un fait de pièce venu d'un boîtier : boîtier du même genre, appareil de ce boîtier ; pas d'appareil sans boîtier
        "CREATE OR REPLACE FUNCTION loss_piece_event_box_guard() RETURNS trigger LANGUAGE plpgsql AS \$fn\$ BEGIN"
        . " IF NEW.box_id IS NULL THEN IF NEW.device_id IS NOT NULL THEN RAISE EXCEPTION 'prévention des pertes : un appareil sans son boîtier' USING ERRCODE = 'check_violation'; END IF; RETURN NEW; END IF;"
        . " PERFORM 1 FROM loss_box b WHERE b.id = NEW.box_id AND b.exercise = NEW.exercise;"
        . " IF NOT FOUND THEN RAISE EXCEPTION 'prévention des pertes : le fait désigne un boîtier inconnu ou d''un autre genre' USING ERRCODE = 'foreign_key_violation'; END IF;"
        . " IF NEW.device_id IS NOT NULL THEN PERFORM 1 FROM loss_device d WHERE d.id = NEW.device_id AND d.box_id = NEW.box_id;"
        . " IF NOT FOUND THEN RAISE EXCEPTION 'prévention des pertes : le fait désigne un appareil inconnu ou d''un autre boîtier' USING ERRCODE = 'foreign_key_violation'; END IF; END IF;"
        . " RETURN NEW; END \$fn\$",
        'DROP TRIGGER IF EXISTS loss_box_guard ON loss_box',
        'CREATE TRIGGER loss_box_guard BEFORE INSERT OR UPDATE ON loss_box FOR EACH ROW EXECUTE FUNCTION loss_box_guard()',
        'DROP TRIGGER IF EXISTS loss_device_guard ON loss_device',
        'CREATE TRIGGER loss_device_guard BEFORE INSERT OR UPDATE ON loss_device FOR EACH ROW EXECUTE FUNCTION loss_device_guard()',
        'DROP TRIGGER IF EXISTS loss_box_event_guard ON loss_box_event',
        'CREATE TRIGGER loss_box_event_guard BEFORE INSERT ON loss_box_event FOR EACH ROW EXECUTE FUNCTION loss_box_event_guard()',
        'DROP TRIGGER IF EXISTS loss_box_event_frozen ON loss_box_event',
        'CREATE TRIGGER loss_box_event_frozen BEFORE UPDATE ON loss_box_event FOR EACH ROW EXECUTE FUNCTION loss_box_event_frozen()',
        'DROP TRIGGER IF EXISTS loss_piece_event_box_guard ON loss_piece_event',
        'CREATE TRIGGER loss_piece_event_box_guard BEFORE INSERT ON loss_piece_event FOR EACH ROW EXECUTE FUNCTION loss_piece_event_box_guard()',
        'DROP TRIGGER IF EXISTS loss_box_no_delete ON loss_box',
        'CREATE TRIGGER loss_box_no_delete BEFORE DELETE ON loss_box FOR EACH ROW EXECUTE FUNCTION loss_box_rows_kept()',
        'DROP TRIGGER IF EXISTS loss_device_no_delete ON loss_device',
        'CREATE TRIGGER loss_device_no_delete BEFORE DELETE ON loss_device FOR EACH ROW EXECUTE FUNCTION loss_box_rows_kept()',
        'DROP TRIGGER IF EXISTS loss_box_event_no_delete ON loss_box_event',
        'CREATE TRIGGER loss_box_event_no_delete BEFORE DELETE ON loss_box_event FOR EACH ROW EXECUTE FUNCTION loss_box_rows_kept()',
        'DROP TRIGGER IF EXISTS loss_box_no_truncate ON loss_box',
        'CREATE TRIGGER loss_box_no_truncate BEFORE TRUNCATE ON loss_box FOR EACH STATEMENT EXECUTE FUNCTION loss_box_rows_kept()',
        'DROP TRIGGER IF EXISTS loss_device_no_truncate ON loss_device',
        'CREATE TRIGGER loss_device_no_truncate BEFORE TRUNCATE ON loss_device FOR EACH STATEMENT EXECUTE FUNCTION loss_box_rows_kept()',
        'DROP TRIGGER IF EXISTS loss_box_event_no_truncate ON loss_box_event',
        'CREATE TRIGGER loss_box_event_no_truncate BEFORE TRUNCATE ON loss_box_event FOR EACH STATEMENT EXECUTE FUNCTION loss_box_rows_kept()',
    ];
}
