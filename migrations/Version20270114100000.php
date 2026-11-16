<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Prévention des pertes, lot L3-b1 « tapis de comptoir » (16/11/2026, `docs/prevention-pertes.md` § 16.15) : la PRÉSENCE des
 * pièces sur un tapis (une projection des faits `COUNTER_SEEN` et `COUNTER_LEFT`, que le serveur tient à jour et qui se recalcule),
 * le lien d'un tapis avec UN poste de caisse, et l'état « retiré » d'un appareil remplacé. Tables du module seulement.
 *
 * Ce que la base garantit ELLE-MÊME :
 * - un poste de caisse a au plus un tapis en service (index unique ; un tapis retiré rend son poste) ; seul un tapis se relie à
 *   un poste ;
 * - une présence désigne un tapis (et lui seul) et une puce du même genre (réel ou exercice) qu'elle ; une puce n'est posée
 *   qu'une fois à la fois sur un tapis (index unique des présences ouvertes) ; une présence se ferme après son début ;
 * - un appareil ne part pas (purge d'exercice) tant qu'une présence le désigne ;
 * - deux genres de faits de boîtier de plus : appareil relié, appareil retiré.
 *
 * Trois fonctions de garde du lot L3-a sont remplacées par leur version élargie (mêmes noms, mêmes déclencheurs). Le retour
 * arrière retire la table et l'index, et remet la garde des suppressions dans sa version du lot L3-a (celle de ce lot lit la
 * table retirée) ; il laisse les deux autres élargies (état « retiré », deux genres de faits : rien qui puisse nuire sans ce lot).
 * Additive, idempotente ; équivalent `scripts/migrate_all_v2_pertes_l3b1.sh`.
 */
final class Version20270114100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Prévention des pertes L3-b1 : présence sur le tapis de comptoir, tapis relié à un poste, appareil retiré';
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
        'DROP TABLE IF EXISTS loss_counter_presence',
        'DROP FUNCTION IF EXISTS loss_counter_presence_guard()',
        'DROP INDEX IF EXISTS uniq_loss_device_register',
        // la garde des suppressions revient à sa version du lot L3-a : celle de ce lot lit la table qui vient de disparaître
        "CREATE OR REPLACE FUNCTION loss_box_rows_kept() RETURNS trigger LANGUAGE plpgsql AS \$fn\$ BEGIN"
        . " IF TG_OP = 'DELETE' THEN IF current_setting('loss.purge', true) = 'exercise' AND OLD.exercise THEN"
        . " IF TG_TABLE_NAME = 'loss_box' AND (EXISTS (SELECT 1 FROM loss_device d WHERE d.box_id = OLD.id) OR EXISTS (SELECT 1 FROM loss_box_event e WHERE e.box_id = OLD.id)"
        . " OR EXISTS (SELECT 1 FROM loss_piece_event e WHERE e.box_id = OLD.id))"
        . " THEN RAISE EXCEPTION 'prévention des pertes : un boîtier ne part pas tant qu''un appareil ou un fait le désigne'; END IF;"
        . " IF TG_TABLE_NAME = 'loss_device' AND (EXISTS (SELECT 1 FROM loss_box_event e WHERE e.device_id = OLD.id) OR EXISTS (SELECT 1 FROM loss_piece_event e WHERE e.device_id = OLD.id))"
        . " THEN RAISE EXCEPTION 'prévention des pertes : un appareil ne part pas tant qu''un fait le désigne'; END IF;"
        . " RETURN OLD; END IF; END IF;"
        . " RAISE EXCEPTION 'prévention des pertes : % ne se supprime pas (% refusé)', TG_TABLE_NAME, TG_OP; END \$fn\$",
    ];

    public const STATEMENTS = [
        "CREATE TABLE IF NOT EXISTS loss_counter_presence (id SERIAL NOT NULL, device_id INT NOT NULL, tag_id INT NOT NULL, piece_id INT DEFAULT NULL, since TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, until TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, exercise BOOLEAN DEFAULT false NOT NULL, PRIMARY KEY(id))",
        'CREATE UNIQUE INDEX IF NOT EXISTS uniq_loss_counter_presence_open ON loss_counter_presence (device_id, tag_id) WHERE until IS NULL',
        'CREATE INDEX IF NOT EXISTS idx_loss_counter_presence_device ON loss_counter_presence (device_id, until)',
        'CREATE INDEX IF NOT EXISTS idx_loss_counter_presence_exercise ON loss_counter_presence (id) WHERE exercise',
        // un poste de caisse a au plus un tapis en service
        "CREATE UNIQUE INDEX IF NOT EXISTS uniq_loss_device_register ON loss_device (cash_register_id) WHERE cash_register_id IS NOT NULL AND status <> 'retired' AND NOT exercise",
        // Présence : un tapis, une puce du même genre, une fin après le début
        "CREATE OR REPLACE FUNCTION loss_counter_presence_guard() RETURNS trigger LANGUAGE plpgsql AS \$fn\$ BEGIN"
        . " IF NEW.until IS NOT NULL AND NEW.until < NEW.since THEN RAISE EXCEPTION 'prévention des pertes : une présence se ferme après son début' USING ERRCODE = 'check_violation'; END IF;"
        . " IF TG_OP = 'UPDATE' THEN"
        . " IF NEW.id <> OLD.id OR NEW.device_id <> OLD.device_id OR NEW.tag_id <> OLD.tag_id OR NEW.exercise <> OLD.exercise OR NEW.since <> OLD.since OR NEW.piece_id IS DISTINCT FROM OLD.piece_id"
        . " THEN RAISE EXCEPTION 'prévention des pertes : le tapis, la puce et le début d''une présence ne changent pas' USING ERRCODE = 'check_violation'; END IF;"
        . " ELSE"
        . " PERFORM 1 FROM loss_device d WHERE d.id = NEW.device_id AND d.kind = 'counter_mat' AND d.exercise = NEW.exercise FOR KEY SHARE;"
        . " IF NOT FOUND THEN RAISE EXCEPTION 'prévention des pertes : la présence désigne un appareil qui n''est pas un tapis, ou d''un autre genre' USING ERRCODE = 'foreign_key_violation'; END IF;"
        . " PERFORM 1 FROM loss_tag t WHERE t.id = NEW.tag_id AND t.exercise = NEW.exercise AND t.piece_id IS NOT DISTINCT FROM NEW.piece_id FOR KEY SHARE;"
        . " IF NOT FOUND THEN RAISE EXCEPTION 'prévention des pertes : la présence désigne une puce inconnue, d''un autre genre, ou une autre pièce que la sienne' USING ERRCODE = 'foreign_key_violation'; END IF;"
        . " END IF; RETURN NEW; END \$fn\$",
        'DROP TRIGGER IF EXISTS loss_counter_presence_guard ON loss_counter_presence',
        'CREATE TRIGGER loss_counter_presence_guard BEFORE INSERT OR UPDATE ON loss_counter_presence FOR EACH ROW EXECUTE FUNCTION loss_counter_presence_guard()',
        // Appareil (remplace la garde du lot L3-a) : état « retiré » ; un poste de caisse seulement pour un tapis
        "CREATE OR REPLACE FUNCTION loss_device_guard() RETURNS trigger LANGUAGE plpgsql AS \$fn\$ BEGIN"
        . " IF NEW.kind NOT IN ('counter_mat', 'reader', 'printer', 'camera') OR NEW.status NOT IN ('ok', 'down', 'retired') THEN RAISE EXCEPTION 'prévention des pertes : genre ou état d''appareil inconnu' USING ERRCODE = 'check_violation'; END IF;"
        . " IF NEW.zone IS NOT NULL AND NEW.zone NOT IN ('stockroom', 'stockroom_door', 'floor', 'counter', 'exit') THEN RAISE EXCEPTION 'prévention des pertes : zone inconnue' USING ERRCODE = 'check_violation'; END IF;"
        . " IF NEW.cash_register_id IS NOT NULL AND NEW.kind <> 'counter_mat' THEN RAISE EXCEPTION 'prévention des pertes : seul un tapis de comptoir se relie à un poste de caisse' USING ERRCODE = 'check_violation'; END IF;"
        . " IF NEW.serial = '' THEN RAISE EXCEPTION 'prévention des pertes : numéro de série vide' USING ERRCODE = 'check_violation'; END IF;"
        . " IF TG_OP = 'UPDATE' THEN"
        . " IF NEW.id <> OLD.id OR NEW.box_id <> OLD.box_id OR NEW.serial <> OLD.serial OR NEW.kind <> OLD.kind OR NEW.exercise <> OLD.exercise OR NEW.first_seen_at <> OLD.first_seen_at"
        . " THEN RAISE EXCEPTION 'prévention des pertes : le boîtier, le numéro de série, le genre et la marque d''exercice d''un appareil ne changent pas' USING ERRCODE = 'check_violation'; END IF;"
        . " ELSE PERFORM 1 FROM loss_box b WHERE b.id = NEW.box_id AND b.exercise = NEW.exercise FOR SHARE;"
        . " IF NOT FOUND THEN RAISE EXCEPTION 'prévention des pertes : boîtier inconnu ou d''un autre genre que l''appareil' USING ERRCODE = 'foreign_key_violation'; END IF;"
        . " END IF; RETURN NEW; END \$fn\$",
        // Fait de boîtier (remplace la garde du lot L3-a) : appareil relié, appareil retiré
        "CREATE OR REPLACE FUNCTION loss_box_event_guard() RETURNS trigger LANGUAGE plpgsql AS \$fn\$ BEGIN"
        . " IF NEW.type NOT IN ('BOX_CREATED', 'BOX_ENROLLED', 'BOX_KEY_RESET', 'BOX_REVOKED', 'BOX_BACK', 'DEVICE_SEEN', 'SOURCE_DOWN', 'SOURCE_UP', 'DEVICE_ASSIGNED', 'DEVICE_RETIRED') THEN RAISE EXCEPTION 'prévention des pertes : genre de fait de boîtier inconnu' USING ERRCODE = 'check_violation'; END IF;"
        . " PERFORM 1 FROM loss_box b WHERE b.id = NEW.box_id AND b.exercise = NEW.exercise FOR KEY SHARE;"
        . " IF NOT FOUND THEN RAISE EXCEPTION 'prévention des pertes : le fait désigne un boîtier inconnu ou d''un autre genre' USING ERRCODE = 'foreign_key_violation'; END IF;"
        . " IF NEW.device_id IS NOT NULL THEN PERFORM 1 FROM loss_device d WHERE d.id = NEW.device_id AND d.box_id = NEW.box_id FOR KEY SHARE;"
        . " IF NOT FOUND THEN RAISE EXCEPTION 'prévention des pertes : le fait désigne un appareil inconnu ou d''un autre boîtier' USING ERRCODE = 'foreign_key_violation'; END IF; END IF;"
        . " RETURN NEW; END \$fn\$",
        // Suppressions (remplace la garde du lot L3-a) : un appareil ne part pas tant qu'une présence le désigne
        "CREATE OR REPLACE FUNCTION loss_box_rows_kept() RETURNS trigger LANGUAGE plpgsql AS \$fn\$ BEGIN"
        . " IF TG_OP = 'DELETE' THEN IF current_setting('loss.purge', true) = 'exercise' AND OLD.exercise THEN"
        . " IF TG_TABLE_NAME = 'loss_box' AND (EXISTS (SELECT 1 FROM loss_device d WHERE d.box_id = OLD.id) OR EXISTS (SELECT 1 FROM loss_box_event e WHERE e.box_id = OLD.id)"
        . " OR EXISTS (SELECT 1 FROM loss_piece_event e WHERE e.box_id = OLD.id))"
        . " THEN RAISE EXCEPTION 'prévention des pertes : un boîtier ne part pas tant qu''un appareil ou un fait le désigne'; END IF;"
        . " IF TG_TABLE_NAME = 'loss_device' AND (EXISTS (SELECT 1 FROM loss_box_event e WHERE e.device_id = OLD.id) OR EXISTS (SELECT 1 FROM loss_piece_event e WHERE e.device_id = OLD.id)"
        . " OR EXISTS (SELECT 1 FROM loss_counter_presence c WHERE c.device_id = OLD.id))"
        . " THEN RAISE EXCEPTION 'prévention des pertes : un appareil ne part pas tant qu''un fait ou une présence le désigne'; END IF;"
        . " RETURN OLD; END IF; END IF;"
        . " RAISE EXCEPTION 'prévention des pertes : % ne se supprime pas (% refusé)', TG_TABLE_NAME, TG_OP; END \$fn\$",
    ];
}
