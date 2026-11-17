<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Prévention des pertes, lot L4-d « comptage » (16/11/2026, `docs/prevention-pertes.md` § 17.16) :
 * - `loss_count` : un comptage (périmètre, ouverture, fermeture ou fermeture sans conclusion, nombres) ; un seul ouvert à la fois par
 *   genre (vrai ou exercice), garanti par un index unique partiel ; un comptage fermé est figé ;
 * - `loss_count_piece` : les pièces attendues d'un comptage fermé, et si elles ont été lues ; écrites une fois, jamais changées ;
 * - `loss_piece.missing_from`, `missing_since`, `missing_count_id` : l'état d'avant d'une pièce absente à deux comptages de suite, depuis
 *   quand, et le comptage qui l'a dite absente (elle le reprend quand elle est relue) ;
 * - les deux tables ne se suppriment pas (fonction propre `loss_count_rows_kept`, qui laisse partir l'exercice seul).
 *
 * Additive, idempotente ; équivalent `scripts/migrate_all_v2_pertes_l4d.sh`.
 */
final class Version20270125100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Prévention des pertes L4-d : comptages, pièces d\'un comptage, état d\'avant d\'une pièce absente';
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
        // EN PREMIER : une pièce dite absente ne peut pas perdre son état d'avant
        "DO \$do\$ BEGIN IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'loss_piece' AND column_name = 'missing_from')"
        . " AND EXISTS (SELECT 1 FROM loss_piece WHERE status = 'missing') THEN"
        . " RAISE EXCEPTION 'prévention des pertes : des pièces sont dites absentes, le comptage ne se retire pas'; END IF; END \$do\$",
        'DROP TABLE IF EXISTS loss_count_piece',
        'DROP TABLE IF EXISTS loss_count',
        'DROP FUNCTION IF EXISTS loss_count_guard()',
        'DROP FUNCTION IF EXISTS loss_count_piece_guard()',
        'DROP FUNCTION IF EXISTS loss_count_rows_kept()',
        'ALTER TABLE loss_piece DROP COLUMN IF EXISTS missing_count_id',
        'ALTER TABLE loss_piece DROP COLUMN IF EXISTS missing_since',
        'ALTER TABLE loss_piece DROP COLUMN IF EXISTS missing_from',
    ];

    public const STATEMENTS = [
        'CREATE TABLE IF NOT EXISTS loss_count (id SERIAL NOT NULL, scope VARCHAR(20) NOT NULL, status VARCHAR(10) DEFAULT \'open\' NOT NULL, exercise BOOLEAN DEFAULT false NOT NULL,'
        . ' opened_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, opened_by INT DEFAULT NULL, closed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, closed_by INT DEFAULT NULL,'
        . ' expected INT DEFAULT NULL, found INT DEFAULT NULL, absent INT DEFAULT NULL, missing INT DEFAULT NULL, PRIMARY KEY(id))',
        "CREATE UNIQUE INDEX IF NOT EXISTS uniq_loss_count_open ON loss_count (exercise) WHERE status = 'open'",
        'CREATE TABLE IF NOT EXISTS loss_count_piece (count_id INT NOT NULL, piece_id INT NOT NULL, found BOOLEAN NOT NULL, exercise BOOLEAN DEFAULT false NOT NULL, PRIMARY KEY(count_id, piece_id))',
        'CREATE INDEX IF NOT EXISTS idx_loss_count_piece_piece ON loss_count_piece (piece_id, count_id)',
        'ALTER TABLE loss_piece ADD COLUMN IF NOT EXISTS missing_from VARCHAR(16) DEFAULT NULL',
        'ALTER TABLE loss_piece ADD COLUMN IF NOT EXISTS missing_since TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL',
        'ALTER TABLE loss_piece ADD COLUMN IF NOT EXISTS missing_count_id INT DEFAULT NULL',
        // Comptage : valeurs connues ; ouvert sans fin, fermé avec ; ce qui le décrit ne change pas ; fermé, il est figé
        "CREATE OR REPLACE FUNCTION loss_count_guard() RETURNS trigger LANGUAGE plpgsql AS \$fn\$ BEGIN"
        . " IF NEW.status NOT IN ('open', 'closed', 'expired') OR NEW.scope NOT IN ('all', 'stockroom', 'stockroom_door', 'floor', 'counter', 'exit', 'back_door')"
        . " THEN RAISE EXCEPTION 'prévention des pertes : état ou périmètre de comptage inconnu' USING ERRCODE = 'check_violation'; END IF;"
        . " IF (NEW.status = 'open') <> (NEW.closed_at IS NULL) OR (NEW.closed_at IS NOT NULL AND NEW.closed_at < NEW.opened_at)"
        . " THEN RAISE EXCEPTION 'prévention des pertes : un comptage ouvert n''a pas de fin, un comptage fermé en a une, après son ouverture' USING ERRCODE = 'check_violation'; END IF;"
        . " IF TG_OP = 'UPDATE' THEN"
        . " IF NEW.id <> OLD.id OR NEW.scope <> OLD.scope OR NEW.exercise <> OLD.exercise OR NEW.opened_at <> OLD.opened_at OR NEW.opened_by IS DISTINCT FROM OLD.opened_by"
        . " THEN RAISE EXCEPTION 'prévention des pertes : le périmètre, le genre et l''ouverture d''un comptage ne changent pas' USING ERRCODE = 'check_violation'; END IF;"
        . " IF OLD.status <> 'open' THEN RAISE EXCEPTION 'prévention des pertes : un comptage fermé est figé' USING ERRCODE = 'check_violation'; END IF;"
        . " END IF; RETURN NEW; END \$fn\$",
        'DROP TRIGGER IF EXISTS loss_count_guard ON loss_count',
        'CREATE TRIGGER loss_count_guard BEFORE INSERT OR UPDATE ON loss_count FOR EACH ROW EXECUTE FUNCTION loss_count_guard()',
        // Pièce d'un comptage : écrite pendant la fermeture (le comptage est encore ouvert), du même genre, jamais changée
        "CREATE OR REPLACE FUNCTION loss_count_piece_guard() RETURNS trigger LANGUAGE plpgsql AS \$fn\$ BEGIN"
        . " IF TG_OP = 'UPDATE' THEN RAISE EXCEPTION 'prévention des pertes : une pièce d''un comptage ne change pas' USING ERRCODE = 'check_violation'; END IF;"
        . " PERFORM 1 FROM loss_count c WHERE c.id = NEW.count_id AND c.exercise = NEW.exercise AND c.status = 'open' FOR SHARE;"
        . " IF NOT FOUND THEN RAISE EXCEPTION 'prévention des pertes : une pièce s''écrit dans un comptage ouvert, du même genre' USING ERRCODE = 'foreign_key_violation'; END IF;"
        . " PERFORM 1 FROM loss_piece p WHERE p.id = NEW.piece_id AND p.exercise = NEW.exercise;"
        . " IF NOT FOUND THEN RAISE EXCEPTION 'prévention des pertes : pièce inconnue ou d''un autre genre que le comptage' USING ERRCODE = 'foreign_key_violation'; END IF;"
        . " RETURN NEW; END \$fn\$",
        'DROP TRIGGER IF EXISTS loss_count_piece_guard ON loss_count_piece',
        'CREATE TRIGGER loss_count_piece_guard BEFORE INSERT OR UPDATE ON loss_count_piece FOR EACH ROW EXECUTE FUNCTION loss_count_piece_guard()',
        // Jamais supprimés, sauf l'exercice pendant son effacement (`loss.purge`) : une fonction PROPRE au comptage (celle des boîtiers lit
        // `OLD.id`, que `loss_count_piece` n'a pas, et le retour arrière d'un lot plus ancien la supprime) ; `OLD` n'est lu que pour une suppression
        "CREATE OR REPLACE FUNCTION loss_count_rows_kept() RETURNS trigger LANGUAGE plpgsql AS \$fn\$ BEGIN"
        . " IF TG_OP = 'DELETE' THEN IF current_setting('loss.purge', true) = 'exercise' AND OLD.exercise THEN RETURN OLD; END IF; END IF;"
        . " RAISE EXCEPTION 'prévention des pertes : % ne se supprime pas (% refusé)', TG_TABLE_NAME, TG_OP; END \$fn\$",
        'DROP TRIGGER IF EXISTS loss_count_kept ON loss_count',
        'CREATE TRIGGER loss_count_kept BEFORE DELETE ON loss_count FOR EACH ROW EXECUTE FUNCTION loss_count_rows_kept()',
        'DROP TRIGGER IF EXISTS loss_count_kept_all ON loss_count',
        'CREATE TRIGGER loss_count_kept_all BEFORE TRUNCATE ON loss_count FOR EACH STATEMENT EXECUTE FUNCTION loss_count_rows_kept()',
        'DROP TRIGGER IF EXISTS loss_count_piece_kept ON loss_count_piece',
        'CREATE TRIGGER loss_count_piece_kept BEFORE DELETE ON loss_count_piece FOR EACH ROW EXECUTE FUNCTION loss_count_rows_kept()',
        'DROP TRIGGER IF EXISTS loss_count_piece_kept_all ON loss_count_piece',
        'CREATE TRIGGER loss_count_piece_kept_all BEFORE TRUNCATE ON loss_count_piece FOR EACH STATEMENT EXECUTE FUNCTION loss_count_rows_kept()',
    ];
}
