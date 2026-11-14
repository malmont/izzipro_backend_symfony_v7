<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Prévention des pertes, lot EX-1 « mode exercice » (14/11/2026, `docs/prevention-pertes.md`) : un constat d'exercice est un constat fictif,
 * marqué (`loss_finding.exercise`), que le gérant lance et efface lui-même. La base garantit ELLE-MÊME qu'un vrai constat ne peut pas être
 * effacé par ce chemin :
 *
 * - la marque d'exercice est figée à la création (un déclencheur refuse de la changer : on ne marque pas un vrai constat pour le supprimer) ;
 * - la porte de purge de L1-a (`loss.purge = 'on'`), dont rien ne se servait, DISPARAÎT ; la seule porte est `loss.purge = 'exercise'`, et
 *   elle ne laisse supprimer une preuve ou une ligne du journal du module que si son constat est marqué exercice (la suppression se fait
 *   donc dans l'ordre preuves, journal, constat : le constat doit encore exister) ; une ligne du journal sans constat ne se supprime jamais ;
 * - un constat ne se supprime que s'il est d'exercice, avec la porte, et seulement quand il n'a plus ni preuve ni ligne de journal (l'ordre
 *   preuves, journal, constat est donc imposé par la base elle-même) ; le vidage des trois tables est refusé.
 * Le futur lot de conservation ouvrira sa propre porte, avec ses conditions.
 *
 * Index `loss_journal (type, id)` : le numéro d'un exercice est lu dans la dernière ligne « exercice lancé » du journal (elle ne se supprime
 * pas, le numéro ne redescend donc jamais) ; l'index borne cette lecture.
 *
 * Colonne, index et déclencheurs nouveaux, fonction remplacée ; idempotente ; équivalent `scripts/migrate_all_v2_pertes_ex1.sh`.
 */
final class Version20270105100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Prévention des pertes EX-1 : constats d\'exercice, effaçables par la seule porte « exercise »';
    }

    public function up(Schema $schema): void
    {
        foreach (self::STATEMENTS as $sql) {
            $this->addSql($sql);
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TRIGGER IF EXISTS loss_finding_no_truncate ON loss_finding');
        $this->addSql('DROP TRIGGER IF EXISTS loss_finding_no_delete ON loss_finding');
        $this->addSql('DROP TRIGGER IF EXISTS loss_finding_exercise_frozen ON loss_finding');
        $this->addSql('DROP FUNCTION IF EXISTS loss_finding_no_delete()');
        $this->addSql('DROP FUNCTION IF EXISTS loss_exercise_flag_frozen()');
        // la fonction de L1-a, telle qu'elle était
        $this->addSql("CREATE OR REPLACE FUNCTION loss_records_immutable() RETURNS trigger LANGUAGE plpgsql AS \$fn\$ BEGIN IF TG_OP = 'DELETE' AND current_setting('loss.purge', true) = 'on' THEN RETURN OLD; END IF; RAISE EXCEPTION 'prévention des pertes : % inaltérable (% refusé)', TG_TABLE_NAME, TG_OP; END \$fn\$");
        $this->addSql('DROP INDEX IF EXISTS idx_loss_journal_type');
        $this->addSql('DROP INDEX IF EXISTS idx_loss_finding_exercise');
        $this->addSql('ALTER TABLE loss_finding DROP COLUMN IF EXISTS exercise');
    }

    public const STATEMENTS = [
        'ALTER TABLE loss_finding ADD COLUMN IF NOT EXISTS exercise BOOLEAN DEFAULT false NOT NULL',
        'CREATE INDEX IF NOT EXISTS idx_loss_finding_exercise ON loss_finding (id) WHERE exercise',
        'CREATE INDEX IF NOT EXISTS idx_loss_journal_type ON loss_journal (type, id)',
        // La marque d'exercice ne change jamais après la création
        "CREATE OR REPLACE FUNCTION loss_exercise_flag_frozen() RETURNS trigger LANGUAGE plpgsql AS \$fn\$ BEGIN RAISE EXCEPTION 'prévention des pertes : la marque d''exercice d''un constat ne change pas'; END \$fn\$",
        'DROP TRIGGER IF EXISTS loss_finding_exercise_frozen ON loss_finding',
        'CREATE TRIGGER loss_finding_exercise_frozen BEFORE UPDATE OF exercise ON loss_finding FOR EACH ROW WHEN (OLD.exercise IS DISTINCT FROM NEW.exercise) EXECUTE FUNCTION loss_exercise_flag_frozen()',
        // Preuves et journal : la seule porte est « exercise », et seulement pour ce qui appartient à un constat d'exercice
        "CREATE OR REPLACE FUNCTION loss_records_immutable() RETURNS trigger LANGUAGE plpgsql AS \$fn\$ BEGIN IF TG_OP = 'DELETE' THEN IF current_setting('loss.purge', true) = 'exercise' AND OLD.finding_id IS NOT NULL AND EXISTS (SELECT 1 FROM loss_finding f WHERE f.id = OLD.finding_id AND f.exercise) THEN RETURN OLD; END IF; END IF; RAISE EXCEPTION 'prévention des pertes : % inaltérable (% refusé)', TG_TABLE_NAME, TG_OP; END \$fn\$",
        // Un constat ne se supprime que s'il est d'exercice, par la même porte, et APRÈS ses preuves et ses lignes de journal (sinon elles
        // resteraient orphelines et ne pourraient plus jamais être supprimées : l'ordre est imposé par la base) ; jamais de vidage
        "CREATE OR REPLACE FUNCTION loss_finding_no_delete() RETURNS trigger LANGUAGE plpgsql AS \$fn\$ BEGIN IF TG_OP = 'DELETE' THEN IF OLD.exercise AND current_setting('loss.purge', true) = 'exercise' AND NOT EXISTS (SELECT 1 FROM loss_evidence e WHERE e.finding_id = OLD.id) AND NOT EXISTS (SELECT 1 FROM loss_journal j WHERE j.finding_id = OLD.id) THEN RETURN OLD; END IF; END IF; RAISE EXCEPTION 'prévention des pertes : un constat ne se supprime pas (% refusé)', TG_OP; END \$fn\$",
        'DROP TRIGGER IF EXISTS loss_finding_no_delete ON loss_finding',
        'CREATE TRIGGER loss_finding_no_delete BEFORE DELETE ON loss_finding FOR EACH ROW EXECUTE FUNCTION loss_finding_no_delete()',
        'DROP TRIGGER IF EXISTS loss_finding_no_truncate ON loss_finding',
        'CREATE TRIGGER loss_finding_no_truncate BEFORE TRUNCATE ON loss_finding FOR EACH STATEMENT EXECUTE FUNCTION loss_finding_no_delete()',
    ];
}
