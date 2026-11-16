<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Prévention des pertes, lot L4-b2 « commandes web et locations » (16/11/2026, `docs/prevention-pertes.md` § 17.14) :
 * - `loss_sale_unit.origin` : d'où vient l'article à relier. `sale` (vente au comptoir lue dans le journal de caisse, comme avant) ou
 *   `web` (article d'une commande web payée, retirée en boutique ou expédiée, lu dans les commandes : aucune entrée du journal de caisse, donc
 *   `audit_sequence` = 0, aucun poste) ;
 * - une garde NOUVELLE (`loss_sale_unit_guard_v2`) : celle du lot L3-b2a, plus l'origine (valeurs permises ; une commande web n'a ni poste
 *   ni entrée du journal ; l'origine ne change pas). Le déclencheur est reposé sur elle ; l'ancienne fonction reste, pour le retour
 *   arrière ;
 * - `loss_setting.web_window_days` : la fenêtre des commandes web (jours ; nul = 14) ;
 * - deux index partiels sur les articles des commandes web : ceux qui attendent une pièce, ceux qui ne sont pas revenus (commandes guettées).
 *
 * Rejouer la migration du lot L3-b2a (`Version20270116100000`) repose le déclencheur sur l'ANCIENNE fonction : rejouer celle-ci ensuite.
 *
 * Additive, idempotente ; équivalent `scripts/migrate_all_v2_pertes_l4b2.sh`.
 */
final class Version20270123100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Prévention des pertes L4-b2 : origine des articles à relier (commandes web), garde, fenêtre des commandes web';
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
        // EN PREMIER : un article d'une commande web n'a pas d'entrée du journal de caisse, il ne peut pas rester sans son origine ; rien n'est
        // défait tant qu'il en existe (même hors d'une transaction)
        "DO \$do\$ BEGIN IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'loss_sale_unit' AND column_name = 'origin')"
        . " AND EXISTS (SELECT 1 FROM loss_sale_unit WHERE origin = 'web') THEN"
        . " RAISE EXCEPTION 'prévention des pertes : des articles de commandes web existent, l''origine ne se retire pas'; END IF; END \$do\$",
        'DROP TRIGGER IF EXISTS loss_sale_unit_guard ON loss_sale_unit',
        'CREATE TRIGGER loss_sale_unit_guard BEFORE INSERT OR UPDATE ON loss_sale_unit FOR EACH ROW EXECUTE FUNCTION loss_sale_unit_guard()',
        'DROP FUNCTION IF EXISTS loss_sale_unit_guard_v2()',
        'DROP INDEX IF EXISTS idx_loss_sale_unit_web_open',
        'DROP INDEX IF EXISTS idx_loss_sale_unit_web_watch',
        'ALTER TABLE loss_setting DROP COLUMN IF EXISTS web_window_days',
        'ALTER TABLE loss_sale_unit DROP COLUMN IF EXISTS origin',
    ];

    public const STATEMENTS = [
        "ALTER TABLE loss_sale_unit ADD COLUMN IF NOT EXISTS origin VARCHAR(8) DEFAULT 'sale' NOT NULL",
        'ALTER TABLE loss_setting ADD COLUMN IF NOT EXISTS web_window_days INT DEFAULT NULL',
        "CREATE OR REPLACE FUNCTION loss_sale_unit_guard_v2() RETURNS trigger LANGUAGE plpgsql AS \$fn\$ BEGIN"
        . " IF NEW.unit_no < 1 THEN RAISE EXCEPTION 'prévention des pertes : le rang d''un article vendu commence à 1' USING ERRCODE = 'check_violation'; END IF;"
        . " IF NEW.origin NOT IN ('sale', 'web') THEN RAISE EXCEPTION 'prévention des pertes : un article à relier vient d''une vente au comptoir ou d''une commande web' USING ERRCODE = 'check_violation'; END IF;"
        . " IF NEW.origin = 'web' AND (NEW.cash_register_id IS NOT NULL OR NEW.audit_sequence <> 0)"
        . " THEN RAISE EXCEPTION 'prévention des pertes : un article de commande web n''a ni poste ni entrée du journal de caisse' USING ERRCODE = 'check_violation'; END IF;"
        . " IF (NEW.piece_id IS NULL) <> (NEW.matched_by IS NULL) OR (NEW.piece_id IS NULL) <> (NEW.matched_at IS NULL) OR (NEW.matched_by IS NOT NULL AND NEW.matched_by NOT IN ('counter', 'catch_up'))"
        . " THEN RAISE EXCEPTION 'prévention des pertes : une pièce reliée dit par quoi (tapis ou rattrapage) et quand' USING ERRCODE = 'check_violation'; END IF;"
        . " IF (NEW.returned_at IS NULL) <> (NEW.return_sequence IS NULL) OR (NEW.returned_at IS NULL) <> (NEW.returned_by IS NULL) OR (NEW.returned_by IS NOT NULL AND NEW.returned_by NOT IN ('return', 'cancellation'))"
        . " THEN RAISE EXCEPTION 'prévention des pertes : un article revenu dit son entrée du journal de caisse et par quoi (retour ou vente annulée)' USING ERRCODE = 'check_violation'; END IF;"
        . " IF NEW.piece_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM loss_piece p WHERE p.id = NEW.piece_id AND NOT p.exercise AND p.variant_id = NEW.variant_id)"
        . " THEN RAISE EXCEPTION 'prévention des pertes : la pièce reliée est une vraie pièce de la variante vendue' USING ERRCODE = 'check_violation'; END IF;"
        . " IF TG_OP = 'UPDATE' THEN"
        . " IF NEW.id <> OLD.id OR NEW.order_id <> OLD.order_id OR NEW.order_item_id <> OLD.order_item_id OR NEW.unit_no <> OLD.unit_no OR NEW.variant_id <> OLD.variant_id"
        . " OR NEW.cash_register_id IS DISTINCT FROM OLD.cash_register_id OR NEW.sold_at <> OLD.sold_at OR NEW.audit_sequence <> OLD.audit_sequence OR NEW.created_at <> OLD.created_at"
        . " OR NEW.origin <> OLD.origin"
        . " THEN RAISE EXCEPTION 'prévention des pertes : ce qui décrit un article vendu ne change pas' USING ERRCODE = 'check_violation'; END IF;"
        . " IF OLD.piece_id IS NOT NULL AND (NEW.piece_id IS DISTINCT FROM OLD.piece_id OR NEW.matched_by IS DISTINCT FROM OLD.matched_by OR NEW.matched_at IS DISTINCT FROM OLD.matched_at)"
        . " THEN RAISE EXCEPTION 'prévention des pertes : la pièce reliée à un article vendu ne change pas' USING ERRCODE = 'check_violation'; END IF;"
        . " IF OLD.returned_at IS NOT NULL AND (NEW.returned_at IS DISTINCT FROM OLD.returned_at OR NEW.return_sequence IS DISTINCT FROM OLD.return_sequence OR NEW.returned_by IS DISTINCT FROM OLD.returned_by"
        . " OR NEW.piece_id IS DISTINCT FROM OLD.piece_id)"
        . " THEN RAISE EXCEPTION 'prévention des pertes : un article revenu ne change plus' USING ERRCODE = 'check_violation'; END IF;"
        . " END IF; RETURN NEW; END \$fn\$",
        'DROP TRIGGER IF EXISTS loss_sale_unit_guard ON loss_sale_unit',
        'CREATE TRIGGER loss_sale_unit_guard BEFORE INSERT OR UPDATE ON loss_sale_unit FOR EACH ROW EXECUTE FUNCTION loss_sale_unit_guard_v2()',
        // les articles des commandes web qui attendent une pièce : le rattrapage les lit par origine, sur leur propre fenêtre
        "CREATE INDEX IF NOT EXISTS idx_loss_sale_unit_web_open ON loss_sale_unit (sold_at, id) WHERE origin = 'web' AND piece_id IS NULL AND returned_at IS NULL",
        // les commandes web guettées (annulation, retour) : leurs articles pas encore revenus, retirés ou non
        "CREATE INDEX IF NOT EXISTS idx_loss_sale_unit_web_watch ON loss_sale_unit (sold_at, order_id) WHERE origin = 'web' AND returned_at IS NULL",
    ];
}
