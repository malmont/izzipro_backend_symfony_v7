<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Prévention des pertes, lot L4-a « zones et antennes » (16/11/2026, `docs/prevention-pertes.md` § 17.12) : la PRÉSENCE des pièces
 * dans une zone (une projection des faits `ZONE_SEEN` et `ZONE_LEFT` d'une antenne, que le serveur tient à jour : elle dit où
 * une pièce a été vue en dernier, elle n'est la preuve de rien, les faits le sont), et deux réglages servis au boîtier (la durée
 * de lecture qui fait « arriver » une pièce dans une zone, le silence qui la fait « partir »). Tables du module seulement.
 *
 * Ce que la base garantit ELLE-MÊME :
 * - une présence désigne un lecteur (et lui seul) et une puce du même genre (réel ou exercice) qu'elle, et la pièce que porte
 *   cette puce ; une puce n'est vue qu'une fois à la fois par une antenne (index unique des présences ouvertes) ;
 * - une présence se ferme après son début ; son antenne, sa puce, sa pièce, sa zone et son début ne changent pas ;
 * - les deux durées sont positives.
 *
 * AUCUNE fonction, AUCUNE table d'un lot précédent n'est remplacée ni indexée ; `loss_setting` reçoit deux colonnes facultatives.
 * Additive, idempotente ; équivalent `scripts/migrate_all_v2_pertes_l4a.sh`.
 */
final class Version20270120100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Prévention des pertes L4-a : présence des pièces par zone, durées d\'arrivée et de départ';
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

    public const DOWN_STATEMENTS = [
        'DROP TABLE IF EXISTS loss_zone_presence',
        'DROP FUNCTION IF EXISTS loss_zone_presence_guard()',
        'ALTER TABLE loss_setting DROP CONSTRAINT IF EXISTS loss_setting_zone_seconds',
        'ALTER TABLE loss_setting DROP COLUMN IF EXISTS zone_arrive_seconds',
        'ALTER TABLE loss_setting DROP COLUMN IF EXISTS zone_leave_seconds',
    ];

    public const STATEMENTS = [
        'CREATE TABLE IF NOT EXISTS loss_zone_presence (id SERIAL NOT NULL, device_id INT NOT NULL, tag_id INT NOT NULL, piece_id INT DEFAULT NULL, zone VARCHAR(20) NOT NULL, since TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, until TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, exercise BOOLEAN DEFAULT false NOT NULL, PRIMARY KEY(id))',
        'CREATE UNIQUE INDEX IF NOT EXISTS uniq_loss_zone_presence_open ON loss_zone_presence (device_id, tag_id) WHERE until IS NULL',
        'CREATE INDEX IF NOT EXISTS idx_loss_zone_presence_piece ON loss_zone_presence (piece_id, since)',
        'CREATE INDEX IF NOT EXISTS idx_loss_zone_presence_tag ON loss_zone_presence (tag_id) WHERE until IS NULL',
        'CREATE INDEX IF NOT EXISTS idx_loss_zone_presence_device ON loss_zone_presence (device_id, until)',
        'CREATE INDEX IF NOT EXISTS idx_loss_zone_presence_exercise ON loss_zone_presence (id) WHERE exercise',
        // Présence par zone : un lecteur, une puce du même genre et sa pièce, une zone, une fin après le début ; rien de son identité ne change
        "CREATE OR REPLACE FUNCTION loss_zone_presence_guard() RETURNS trigger LANGUAGE plpgsql AS \$fn\$ BEGIN"
        . " IF NEW.zone = '' THEN RAISE EXCEPTION 'prévention des pertes : une présence dit sa zone' USING ERRCODE = 'check_violation'; END IF;"
        . " IF NEW.until IS NOT NULL AND NEW.until < NEW.since THEN RAISE EXCEPTION 'prévention des pertes : une présence se ferme après son début' USING ERRCODE = 'check_violation'; END IF;"
        . " IF TG_OP = 'UPDATE' THEN"
        . " IF NEW.id <> OLD.id OR NEW.device_id <> OLD.device_id OR NEW.tag_id <> OLD.tag_id OR NEW.exercise <> OLD.exercise OR NEW.since <> OLD.since OR NEW.zone <> OLD.zone OR NEW.piece_id IS DISTINCT FROM OLD.piece_id"
        . " THEN RAISE EXCEPTION 'prévention des pertes : l''antenne, la puce, la zone et le début d''une présence ne changent pas' USING ERRCODE = 'check_violation'; END IF;"
        . " ELSE"
        . " PERFORM 1 FROM loss_device d WHERE d.id = NEW.device_id AND d.kind = 'reader' AND d.exercise = NEW.exercise FOR KEY SHARE;"
        . " IF NOT FOUND THEN RAISE EXCEPTION 'prévention des pertes : la présence désigne un appareil qui n''est pas un lecteur, ou d''un autre genre' USING ERRCODE = 'foreign_key_violation'; END IF;"
        . " PERFORM 1 FROM loss_tag t WHERE t.id = NEW.tag_id AND t.exercise = NEW.exercise AND t.piece_id IS NOT DISTINCT FROM NEW.piece_id FOR KEY SHARE;"
        . " IF NOT FOUND THEN RAISE EXCEPTION 'prévention des pertes : la présence désigne une puce inconnue, d''un autre genre, ou une autre pièce que la sienne' USING ERRCODE = 'foreign_key_violation'; END IF;"
        . " END IF; RETURN NEW; END \$fn\$",
        'DROP TRIGGER IF EXISTS loss_zone_presence_guard ON loss_zone_presence',
        'CREATE TRIGGER loss_zone_presence_guard BEFORE INSERT OR UPDATE ON loss_zone_presence FOR EACH ROW EXECUTE FUNCTION loss_zone_presence_guard()',
        // Deux réglages servis au boîtier (null : la valeur par défaut du module)
        'ALTER TABLE loss_setting ADD COLUMN IF NOT EXISTS zone_arrive_seconds INT DEFAULT NULL',
        'ALTER TABLE loss_setting ADD COLUMN IF NOT EXISTS zone_leave_seconds INT DEFAULT NULL',
        'ALTER TABLE loss_setting DROP CONSTRAINT IF EXISTS loss_setting_zone_seconds',
        'ALTER TABLE loss_setting ADD CONSTRAINT loss_setting_zone_seconds CHECK ((zone_arrive_seconds IS NULL OR zone_arrive_seconds BETWEEN 1 AND 60) AND (zone_leave_seconds IS NULL OR zone_leave_seconds BETWEEN 5 AND 600))',
    ];
}
