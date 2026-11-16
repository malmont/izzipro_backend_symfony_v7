<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Prévention des pertes, lot L3-c « imprimante à puce » (16/11/2026, `docs/prevention-pertes.md` § 16.20) : les travaux d'impression
 * (`loss_print_job`, une ligne par pièce dans `loss_print_job_line`) que le boîtier vient chercher, et le mot de passe d'accès des
 * puces du site, rangé CHIFFRÉ (`loss_tag_secret`). Tables du module seulement : aucune table existante n'est modifiée.
 *
 * Ce que la base garantit ELLE-MÊME :
 * - un travail vise une imprimante de SON boîtier ; un travail d'exercice vit sur un boîtier simulé, un vrai travail sur un vrai ;
 * - un vrai travail dit le rang du mot de passe à employer, un travail d'exercice n'en porte JAMAIS (l'exercice ne touche pas au
 *   mot de passe des vraies puces) ;
 * - ce qui décrit un travail ou une ligne (boîtier, imprimante, pièce, identifiant à écrire, rang) ne change pas ; un travail fini
 *   ou annulé ne repart pas ;
 * - une ligne ne quitte l'attente qu'une fois : écrite (avec la puce posée sur SA pièce), en échec (avec son motif) ou annulée ;
 *   ensuite elle ne change plus ;
 * - une pièce n'attend que dans un travail à la fois ; un identifiant n'est donné qu'à une ligne ;
 * - le rang d'un mot de passe ne change pas et un mot de passe ne se supprime pas (sans lui, les puces écrites ne se réécrivent
 *   plus) ; son chiffre et le rang de sa clé peuvent changer (changement de clé de chiffrement).
 *
 * Aucune fonction d'un lot précédent n'est remplacée. Additive, idempotente ; équivalent `scripts/migrate_all_v2_pertes_l3c.sh`.
 * Retour arrière : les trois tables et leurs fonctions partent (rien d'autre ne les lit) ; LE MOT DE PASSE RANGÉ PART AVEC ELLES :
 * sur une base où des puces ont été écrites, exporter `loss_tag_secret` avant tout retour arrière (sans lui, elles ne se
 * réécrivent plus).
 */
final class Version20270118100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Prévention des pertes L3-c : travaux d\'impression des puces et mot de passe d\'accès rangé chiffré';
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
        'DROP TABLE IF EXISTS loss_print_job_line',
        'DROP TABLE IF EXISTS loss_print_job',
        'DROP TABLE IF EXISTS loss_tag_secret',
        'DROP FUNCTION IF EXISTS loss_print_job_guard()',
        'DROP FUNCTION IF EXISTS loss_print_line_guard()',
        'DROP FUNCTION IF EXISTS loss_tag_secret_guard()',
        'DROP FUNCTION IF EXISTS loss_tag_secret_kept()',
    ];

    public const STATEMENTS = [
        // Un travail d'impression : l'imprimante d'un boîtier, avec ou sans le prix, le rang du mot de passe à employer
        'CREATE TABLE IF NOT EXISTS loss_print_job (id SERIAL NOT NULL, box_id INT NOT NULL, device_id INT NOT NULL, status VARCHAR(10) DEFAULT \'waiting\' NOT NULL,'
        . ' with_price BOOLEAN DEFAULT false NOT NULL, exercise BOOLEAN DEFAULT false NOT NULL, secret_rank INT DEFAULT NULL, created_by INT DEFAULT NULL,'
        . ' created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, finished_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, cancelled_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id))',
        'CREATE INDEX IF NOT EXISTS idx_loss_print_job_box ON loss_print_job (box_id, status)',
        "CREATE OR REPLACE FUNCTION loss_print_job_guard() RETURNS trigger LANGUAGE plpgsql AS \$fn\$ BEGIN"
        . " IF NEW.status NOT IN ('waiting', 'running', 'done', 'cancelled') THEN RAISE EXCEPTION 'prévention des pertes : état de travail d''impression inconnu' USING ERRCODE = 'check_violation'; END IF;"
        . " IF NEW.exercise AND NEW.secret_rank IS NOT NULL THEN RAISE EXCEPTION 'prévention des pertes : un travail d''exercice ne porte jamais de mot de passe' USING ERRCODE = 'check_violation'; END IF;"
        . " IF NOT NEW.exercise AND (NEW.secret_rank IS NULL OR NOT EXISTS (SELECT 1 FROM loss_tag_secret s WHERE s.rank = NEW.secret_rank))"
        . " THEN RAISE EXCEPTION 'prévention des pertes : un vrai travail d''impression dit le rang d''un mot de passe rangé' USING ERRCODE = 'check_violation'; END IF;"
        . " IF TG_OP = 'INSERT' THEN"
        . " IF NOT EXISTS (SELECT 1 FROM loss_device d JOIN loss_box b ON b.id = d.box_id WHERE d.id = NEW.device_id AND d.box_id = NEW.box_id AND d.kind = 'printer' AND b.exercise = NEW.exercise)"
        . " THEN RAISE EXCEPTION 'prévention des pertes : un travail vise une imprimante de son boîtier (simulé pour un exercice, vrai sinon)' USING ERRCODE = 'check_violation'; END IF;"
        . " ELSE"
        . " IF NEW.id <> OLD.id OR NEW.box_id <> OLD.box_id OR NEW.device_id <> OLD.device_id OR NEW.with_price <> OLD.with_price OR NEW.exercise <> OLD.exercise"
        . " OR NEW.secret_rank IS DISTINCT FROM OLD.secret_rank OR NEW.created_by IS DISTINCT FROM OLD.created_by OR NEW.created_at <> OLD.created_at"
        . " THEN RAISE EXCEPTION 'prévention des pertes : ce qui décrit un travail d''impression ne change pas' USING ERRCODE = 'check_violation'; END IF;"
        . " IF OLD.status IN ('done', 'cancelled') AND NEW.status <> OLD.status THEN RAISE EXCEPTION 'prévention des pertes : un travail fini ou annulé ne repart pas' USING ERRCODE = 'check_violation'; END IF;"
        . " END IF; RETURN NEW; END \$fn\$",
        'DROP TRIGGER IF EXISTS loss_print_job_guard ON loss_print_job',
        'CREATE TRIGGER loss_print_job_guard BEFORE INSERT OR UPDATE ON loss_print_job FOR EACH ROW EXECUTE FUNCTION loss_print_job_guard()',
        // Une ligne par pièce : l'identifiant à écrire dans la puce, puis ce qu'il en est advenu
        'CREATE TABLE IF NOT EXISTS loss_print_job_line (id SERIAL NOT NULL, job_id INT NOT NULL, position INT NOT NULL, piece_id INT NOT NULL, identifier VARCHAR(64) NOT NULL,'
        . ' status VARCHAR(10) DEFAULT \'waiting\' NOT NULL, reason VARCHAR(24) DEFAULT NULL, tag_id INT DEFAULT NULL, done_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id),'
        . ' CONSTRAINT fk_loss_print_line_job FOREIGN KEY (job_id) REFERENCES loss_print_job (id) ON DELETE CASCADE)',
        'CREATE UNIQUE INDEX IF NOT EXISTS uniq_loss_print_line_position ON loss_print_job_line (job_id, position)',
        'CREATE UNIQUE INDEX IF NOT EXISTS uniq_loss_print_line_open_piece ON loss_print_job_line (piece_id) WHERE status = \'waiting\'',
        'CREATE UNIQUE INDEX IF NOT EXISTS uniq_loss_print_line_identifier ON loss_print_job_line (identifier)',
        'CREATE INDEX IF NOT EXISTS idx_loss_print_line_piece ON loss_print_job_line (piece_id)',
        "CREATE OR REPLACE FUNCTION loss_print_line_guard() RETURNS trigger LANGUAGE plpgsql AS \$fn\$ BEGIN"
        . " IF NEW.status NOT IN ('waiting', 'written', 'failed', 'cancelled') THEN RAISE EXCEPTION 'prévention des pertes : état de ligne d''impression inconnu' USING ERRCODE = 'check_violation'; END IF;"
        . " IF (NEW.status = 'written') <> (NEW.tag_id IS NOT NULL) OR (NEW.status = 'failed') <> (NEW.reason IS NOT NULL) OR (NEW.status = 'waiting') <> (NEW.done_at IS NULL)"
        . " THEN RAISE EXCEPTION 'prévention des pertes : une ligne écrite dit sa puce, une ligne en échec son motif, une ligne close son heure' USING ERRCODE = 'check_violation'; END IF;"
        . " IF TG_OP = 'INSERT' THEN"
        . " IF NEW.status <> 'waiting' OR NOT EXISTS (SELECT 1 FROM loss_piece p JOIN loss_print_job j ON j.id = NEW.job_id WHERE p.id = NEW.piece_id AND p.exercise = j.exercise)"
        . " THEN RAISE EXCEPTION 'prévention des pertes : une ligne naît en attente, sur une pièce du même monde que son travail (exercice ou non)' USING ERRCODE = 'check_violation'; END IF;"
        . " ELSE"
        . " IF NEW.id <> OLD.id OR NEW.job_id <> OLD.job_id OR NEW.position <> OLD.position OR NEW.piece_id <> OLD.piece_id OR NEW.identifier <> OLD.identifier"
        . " THEN RAISE EXCEPTION 'prévention des pertes : ce qui décrit une ligne d''impression ne change pas' USING ERRCODE = 'check_violation'; END IF;"
        . " IF OLD.status <> 'waiting' AND (NEW.status <> OLD.status OR NEW.reason IS DISTINCT FROM OLD.reason OR NEW.tag_id IS DISTINCT FROM OLD.tag_id OR NEW.done_at IS DISTINCT FROM OLD.done_at)"
        . " THEN RAISE EXCEPTION 'prévention des pertes : une ligne d''impression close ne change plus' USING ERRCODE = 'check_violation'; END IF;"
        . " IF NEW.status = 'written' AND OLD.status = 'waiting' AND NOT EXISTS (SELECT 1 FROM loss_tag t WHERE t.id = NEW.tag_id AND t.piece_id = NEW.piece_id AND t.identifier = NEW.identifier AND t.status = 'associated')"
        . " THEN RAISE EXCEPTION 'prévention des pertes : la puce d''une ligne écrite est posée sur sa pièce et porte son identifiant' USING ERRCODE = 'check_violation'; END IF;"
        . " END IF; RETURN NEW; END \$fn\$",
        'DROP TRIGGER IF EXISTS loss_print_line_guard ON loss_print_job_line',
        'CREATE TRIGGER loss_print_line_guard BEFORE INSERT OR UPDATE ON loss_print_job_line FOR EACH ROW EXECUTE FUNCTION loss_print_line_guard()',
        // Le mot de passe d'accès des puces du site, chiffré hors de la base (clé de l'environnement) : son rang, le rang de la clé
        'CREATE TABLE IF NOT EXISTS loss_tag_secret (id SERIAL NOT NULL, rank INT NOT NULL, key_rank INT NOT NULL, cipher TEXT NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))',
        'CREATE UNIQUE INDEX IF NOT EXISTS uniq_loss_tag_secret_rank ON loss_tag_secret (rank)',
        "CREATE OR REPLACE FUNCTION loss_tag_secret_guard() RETURNS trigger LANGUAGE plpgsql AS \$fn\$ BEGIN"
        . " IF NEW.rank < 1 OR NEW.key_rank < 1 OR NEW.cipher = '' THEN RAISE EXCEPTION 'prévention des pertes : un mot de passe rangé dit son rang, le rang de sa clé et son chiffre' USING ERRCODE = 'check_violation'; END IF;"
        . " IF TG_OP = 'UPDATE' AND (NEW.id <> OLD.id OR NEW.rank <> OLD.rank OR NEW.created_at <> OLD.created_at)"
        . " THEN RAISE EXCEPTION 'prévention des pertes : le rang d''un mot de passe rangé ne change pas' USING ERRCODE = 'check_violation'; END IF;"
        . " RETURN NEW; END \$fn\$",
        'DROP TRIGGER IF EXISTS loss_tag_secret_guard ON loss_tag_secret',
        'CREATE TRIGGER loss_tag_secret_guard BEFORE INSERT OR UPDATE ON loss_tag_secret FOR EACH ROW EXECUTE FUNCTION loss_tag_secret_guard()',
        "CREATE OR REPLACE FUNCTION loss_tag_secret_kept() RETURNS trigger LANGUAGE plpgsql AS \$fn\$ BEGIN"
        . " RAISE EXCEPTION 'prévention des pertes : % ne se supprime pas (% refusé)', TG_TABLE_NAME, TG_OP; END \$fn\$",
        'DROP TRIGGER IF EXISTS loss_tag_secret_kept ON loss_tag_secret',
        'CREATE TRIGGER loss_tag_secret_kept BEFORE DELETE ON loss_tag_secret FOR EACH ROW EXECUTE FUNCTION loss_tag_secret_kept()',
        'DROP TRIGGER IF EXISTS loss_tag_secret_kept_all ON loss_tag_secret',
        'CREATE TRIGGER loss_tag_secret_kept_all BEFORE TRUNCATE ON loss_tag_secret FOR EACH STATEMENT EXECUTE FUNCTION loss_tag_secret_kept()',
    ];
}
