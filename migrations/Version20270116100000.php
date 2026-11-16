<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Prévention des pertes, lot L3-b2a « la vente reliée aux pièces » (16/11/2026, `docs/prevention-pertes.md` § 16.18) : une ligne par
 * article SUIVI vendu à la caisse (`loss_sale_unit`), que l'analyse du module écrit en lisant le journal de caisse, et la pièce
 * qui lui est reliée quand elle a été lue (sur le tapis du poste, ou à la sortie par rattrapage). Trois réglages de plus. Tables
 * du module seulement : aucune table de la caisse n'est touchée.
 *
 * Ce que la base garantit ELLE-MÊME :
 * - un article vendu n'a qu'une ligne (index unique : ligne de la vente et rang), et donc qu'une pièce ;
 * - une pièce n'est vendue qu'une fois à la fois (index unique des pièces reliées à un article non revenu) ;
 * - la pièce reliée est une vraie pièce de la MÊME variante que l'article ; une pièce ne se relie qu'une fois à une ligne, ne se
 *   change pas et ne se retire pas ; un article revenu ne reçoit plus de pièce ; le retour ne se dit qu'une fois, et dit par quoi
 *   (un retour d'articles, ou une vente annulée) ;
 * - ce qui décrit la vente (commande, ligne, rang, variante, poste, heure, entrée du journal de caisse) ne change jamais ;
 * - aucune ligne ne se supprime.
 *
 * Aucune fonction d'un lot précédent n'est remplacée. Additive, idempotente ; équivalent `scripts/migrate_all_v2_pertes_l3b2a.sh`.
 * Retour arrière : la table, ses deux fonctions et les trois colonnes de réglage partent (rien d'autre ne les lit).
 */
final class Version20270116100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Prévention des pertes L3-b2a : articles suivis vendus et pièces reliées, fenêtres et conservation réglables';
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
        'DROP TABLE IF EXISTS loss_sale_unit',
        'DROP FUNCTION IF EXISTS loss_sale_unit_guard()',
        'DROP FUNCTION IF EXISTS loss_sale_unit_kept()',
        'ALTER TABLE loss_setting DROP COLUMN IF EXISTS counter_window_seconds',
        'ALTER TABLE loss_setting DROP COLUMN IF EXISTS catch_up_seconds',
        'ALTER TABLE loss_setting DROP COLUMN IF EXISTS presence_retention_days',
    ];

    public const STATEMENTS = [
        // Réglages : fenêtre du tapis, fenêtre du rattrapage (secondes), conservation des présences fermées (jours) ; nul = la
        // valeur par défaut du code (10 minutes, 30 minutes, 90 jours)
        'ALTER TABLE loss_setting ADD COLUMN IF NOT EXISTS counter_window_seconds INT DEFAULT NULL',
        'ALTER TABLE loss_setting ADD COLUMN IF NOT EXISTS catch_up_seconds INT DEFAULT NULL',
        'ALTER TABLE loss_setting ADD COLUMN IF NOT EXISTS presence_retention_days INT DEFAULT NULL',
        // Un article suivi vendu : la vente (commande, ligne, rang dans la ligne), sa variante, son poste, son heure RÉELLE, l'entrée
        // du journal de caisse qui l'a dite ; la pièce reliée (nulle tant qu'aucune n'a été lue), par quoi et quand ; son retour
        'CREATE TABLE IF NOT EXISTS loss_sale_unit (id BIGSERIAL NOT NULL, order_id INT NOT NULL, order_item_id INT NOT NULL, unit_no INT NOT NULL, variant_id INT NOT NULL,'
        . ' cash_register_id INT DEFAULT NULL, sold_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, audit_sequence BIGINT NOT NULL, piece_id INT DEFAULT NULL, matched_by VARCHAR(10) DEFAULT NULL,'
        . ' matched_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, returned_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, return_sequence BIGINT DEFAULT NULL,'
        . ' returned_by VARCHAR(12) DEFAULT NULL,'
        . ' created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))',
        'CREATE UNIQUE INDEX IF NOT EXISTS uniq_loss_sale_unit_item ON loss_sale_unit (order_item_id, unit_no)',
        'CREATE UNIQUE INDEX IF NOT EXISTS uniq_loss_sale_unit_piece ON loss_sale_unit (piece_id) WHERE piece_id IS NOT NULL AND returned_at IS NULL',
        'CREATE INDEX IF NOT EXISTS idx_loss_sale_unit_open ON loss_sale_unit (sold_at, id) WHERE piece_id IS NULL AND returned_at IS NULL',
        'CREATE INDEX IF NOT EXISTS idx_loss_sale_unit_order ON loss_sale_unit (order_id)',
        'CREATE INDEX IF NOT EXISTS idx_loss_sale_unit_register ON loss_sale_unit (cash_register_id, sold_at)',
        "CREATE OR REPLACE FUNCTION loss_sale_unit_guard() RETURNS trigger LANGUAGE plpgsql AS \$fn\$ BEGIN"
        . " IF NEW.unit_no < 1 THEN RAISE EXCEPTION 'prévention des pertes : le rang d''un article vendu commence à 1' USING ERRCODE = 'check_violation'; END IF;"
        . " IF (NEW.piece_id IS NULL) <> (NEW.matched_by IS NULL) OR (NEW.piece_id IS NULL) <> (NEW.matched_at IS NULL) OR (NEW.matched_by IS NOT NULL AND NEW.matched_by NOT IN ('counter', 'catch_up'))"
        . " THEN RAISE EXCEPTION 'prévention des pertes : une pièce reliée dit par quoi (tapis ou rattrapage) et quand' USING ERRCODE = 'check_violation'; END IF;"
        . " IF (NEW.returned_at IS NULL) <> (NEW.return_sequence IS NULL) OR (NEW.returned_at IS NULL) <> (NEW.returned_by IS NULL) OR (NEW.returned_by IS NOT NULL AND NEW.returned_by NOT IN ('return', 'cancellation'))"
        . " THEN RAISE EXCEPTION 'prévention des pertes : un article revenu dit son entrée du journal de caisse et par quoi (retour ou vente annulée)' USING ERRCODE = 'check_violation'; END IF;"
        . " IF NEW.piece_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM loss_piece p WHERE p.id = NEW.piece_id AND NOT p.exercise AND p.variant_id = NEW.variant_id)"
        . " THEN RAISE EXCEPTION 'prévention des pertes : la pièce reliée est une vraie pièce de la variante vendue' USING ERRCODE = 'check_violation'; END IF;"
        . " IF TG_OP = 'UPDATE' THEN"
        . " IF NEW.id <> OLD.id OR NEW.order_id <> OLD.order_id OR NEW.order_item_id <> OLD.order_item_id OR NEW.unit_no <> OLD.unit_no OR NEW.variant_id <> OLD.variant_id"
        . " OR NEW.cash_register_id IS DISTINCT FROM OLD.cash_register_id OR NEW.sold_at <> OLD.sold_at OR NEW.audit_sequence <> OLD.audit_sequence OR NEW.created_at <> OLD.created_at"
        . " THEN RAISE EXCEPTION 'prévention des pertes : ce qui décrit un article vendu ne change pas' USING ERRCODE = 'check_violation'; END IF;"
        . " IF OLD.piece_id IS NOT NULL AND (NEW.piece_id IS DISTINCT FROM OLD.piece_id OR NEW.matched_by IS DISTINCT FROM OLD.matched_by OR NEW.matched_at IS DISTINCT FROM OLD.matched_at)"
        . " THEN RAISE EXCEPTION 'prévention des pertes : la pièce reliée à un article vendu ne change pas' USING ERRCODE = 'check_violation'; END IF;"
        . " IF OLD.returned_at IS NOT NULL AND (NEW.returned_at IS DISTINCT FROM OLD.returned_at OR NEW.return_sequence IS DISTINCT FROM OLD.return_sequence OR NEW.returned_by IS DISTINCT FROM OLD.returned_by"
        . " OR NEW.piece_id IS DISTINCT FROM OLD.piece_id)"
        . " THEN RAISE EXCEPTION 'prévention des pertes : un article revenu ne change plus' USING ERRCODE = 'check_violation'; END IF;"
        . " END IF; RETURN NEW; END \$fn\$",
        'DROP TRIGGER IF EXISTS loss_sale_unit_guard ON loss_sale_unit',
        'CREATE TRIGGER loss_sale_unit_guard BEFORE INSERT OR UPDATE ON loss_sale_unit FOR EACH ROW EXECUTE FUNCTION loss_sale_unit_guard()',
        "CREATE OR REPLACE FUNCTION loss_sale_unit_kept() RETURNS trigger LANGUAGE plpgsql AS \$fn\$ BEGIN"
        . " RAISE EXCEPTION 'prévention des pertes : % ne se supprime pas (% refusé)', TG_TABLE_NAME, TG_OP; END \$fn\$",
        'DROP TRIGGER IF EXISTS loss_sale_unit_kept ON loss_sale_unit',
        'CREATE TRIGGER loss_sale_unit_kept BEFORE DELETE ON loss_sale_unit FOR EACH ROW EXECUTE FUNCTION loss_sale_unit_kept()',
        'DROP TRIGGER IF EXISTS loss_sale_unit_kept_all ON loss_sale_unit',
        'CREATE TRIGGER loss_sale_unit_kept_all BEFORE TRUNCATE ON loss_sale_unit FOR EACH STATEMENT EXECUTE FUNCTION loss_sale_unit_kept()',
    ];
}
