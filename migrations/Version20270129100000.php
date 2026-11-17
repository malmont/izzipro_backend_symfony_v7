<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Prévention des pertes, lot L5-d « alerte à la sortie et signal du portique » (17/11/2026, `docs/prevention-pertes.md` § 18.2 et § 18.11) :
 * - `loss_setting.exit_alerts_night` (allumé par défaut : l'alerte part aussi hors des heures d'ouverture) et `exit_signal_enabled` (le
 *   signal sonore du portique, éteint par défaut) ;
 * - `loss_piece.sold_changed_at` : quand une pièce est passée à « vendue » ou en est sortie (retour) ; il sert au boîtier les DELTAS de la
 *   liste des pièces qui peuvent sortir sans sonner. Rempli, pour les pièces déjà vendues, par leur dernière mise à jour ;
 * - `loss_alert` : les alertes de sortie du jour, une par zone et par minute (les pièces d'une même minute et d'une même zone sont
 *   regroupées) ; rien d'une personne ;
 * - une garde NOUVELLE des appareils (`loss_device_guard_v3`) : celle du lot L4-c, plus le genre `signal` ; le déclencheur est reposé sur
 *   elle, l'ancienne fonction reste pour le retour arrière (rejouer L4-c repose le déclencheur sur la v2 : rejouer celle-ci ensuite).
 *
 * Additive, idempotente ; équivalent `scripts/migrate_all_v2_pertes_l5d.sh`.
 */
final class Version20270129100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Prévention des pertes L5-d : alerte à la sortie et signal du portique';
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
        // EN PREMIER : un avertisseur ne peut pas rester sous l'ancienne garde
        "DO \$do\$ BEGIN IF EXISTS (SELECT 1 FROM loss_device WHERE kind = 'signal') THEN"
        . " RAISE EXCEPTION 'prévention des pertes : un avertisseur est inscrit, le genre ne se retire pas'; END IF; END \$do\$",
        'DROP TRIGGER IF EXISTS loss_device_guard ON loss_device',
        'CREATE TRIGGER loss_device_guard BEFORE INSERT OR UPDATE ON loss_device FOR EACH ROW EXECUTE FUNCTION loss_device_guard_v2()',
        'DROP FUNCTION IF EXISTS loss_device_guard_v3()',
        'DROP TABLE IF EXISTS loss_alert',
        'DROP INDEX IF EXISTS idx_loss_piece_sold_changed',
        'ALTER TABLE loss_piece DROP COLUMN IF EXISTS sold_changed_at',
        'ALTER TABLE loss_setting DROP COLUMN IF EXISTS exit_signal_enabled',
        'ALTER TABLE loss_setting DROP COLUMN IF EXISTS exit_alerts_night',
    ];

    public const STATEMENTS = [
        'ALTER TABLE loss_setting ADD COLUMN IF NOT EXISTS exit_alerts_night BOOLEAN DEFAULT true NOT NULL',
        'ALTER TABLE loss_setting ADD COLUMN IF NOT EXISTS exit_signal_enabled BOOLEAN DEFAULT false NOT NULL',
        'ALTER TABLE loss_piece ADD COLUMN IF NOT EXISTS sold_changed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL',
        "UPDATE loss_piece SET sold_changed_at = updated_at WHERE status = 'sold' AND sold_changed_at IS NULL",
        'CREATE INDEX IF NOT EXISTS idx_loss_piece_sold_changed ON loss_piece (sold_changed_at) WHERE sold_changed_at IS NOT NULL',
        'CREATE TABLE IF NOT EXISTS loss_alert (id SERIAL NOT NULL, exercise BOOLEAN DEFAULT false NOT NULL, zone VARCHAR(20) NOT NULL,'
        . ' minute TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, store_day DATE NOT NULL, out_of_hours BOOLEAN DEFAULT false NOT NULL, articles JSON NOT NULL,'
        . ' pieces JSON NOT NULL, pushed SMALLINT DEFAULT 0 NOT NULL, requested_by INT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,'
        . ' updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))',
        // une alerte par zone et par minute ; en exercice, par gérant aussi (deux exercices de la même minute ne se mêlent pas). Relecture 2 :
        // un index de l'ancienne forme (sans COALESCE, première version du lot) serait gardé par IF NOT EXISTS, et l'ON CONFLICT échouerait ;
        // il est retiré s'il n'a pas la bonne forme (la table ne garde que des alertes du jour)
        "DO \$do\$ BEGIN IF EXISTS (SELECT 1 FROM pg_indexes WHERE schemaname = current_schema() AND indexname = 'uniq_loss_alert_minute' AND indexdef NOT ILIKE '%coalesce%')"
        . " THEN DROP INDEX uniq_loss_alert_minute; END IF; END \$do\$",
        'CREATE UNIQUE INDEX IF NOT EXISTS uniq_loss_alert_minute ON loss_alert (exercise, zone, minute, (COALESCE(requested_by, 0)))',
        'CREATE INDEX IF NOT EXISTS idx_loss_alert_day ON loss_alert (store_day, exercise)',
        // une garde NOUVELLE des appareils : celle du lot L4-c, plus le genre `signal` (l'avertisseur du portique) ; l'ancienne reste pour le retour arrière
        "CREATE OR REPLACE FUNCTION loss_device_guard_v3() RETURNS trigger LANGUAGE plpgsql AS \$fn\$ BEGIN"
        . " IF NEW.kind NOT IN ('counter_mat', 'reader', 'printer', 'camera', 'signal') OR NEW.status NOT IN ('ok', 'down', 'retired') THEN RAISE EXCEPTION 'prévention des pertes : genre ou état d''appareil inconnu' USING ERRCODE = 'check_violation'; END IF;"
        . " IF NEW.zone IS NOT NULL AND NEW.zone NOT IN ('stockroom', 'stockroom_door', 'floor', 'counter', 'exit', 'back_door') THEN RAISE EXCEPTION 'prévention des pertes : zone inconnue' USING ERRCODE = 'check_violation'; END IF;"
        . " IF NEW.cash_register_id IS NOT NULL AND NEW.kind <> 'counter_mat' THEN RAISE EXCEPTION 'prévention des pertes : seul un tapis de comptoir se relie à un poste de caisse' USING ERRCODE = 'check_violation'; END IF;"
        . " IF NEW.serial = '' THEN RAISE EXCEPTION 'prévention des pertes : numéro de série vide' USING ERRCODE = 'check_violation'; END IF;"
        . " IF TG_OP = 'UPDATE' THEN"
        . " IF NEW.id <> OLD.id OR NEW.box_id <> OLD.box_id OR NEW.serial <> OLD.serial OR NEW.kind <> OLD.kind OR NEW.exercise <> OLD.exercise OR NEW.first_seen_at <> OLD.first_seen_at"
        . " THEN RAISE EXCEPTION 'prévention des pertes : le boîtier, le numéro de série, le genre et la marque d''exercice d''un appareil ne changent pas' USING ERRCODE = 'check_violation'; END IF;"
        . " ELSE PERFORM 1 FROM loss_box b WHERE b.id = NEW.box_id AND b.exercise = NEW.exercise FOR SHARE;"
        . " IF NOT FOUND THEN RAISE EXCEPTION 'prévention des pertes : boîtier inconnu ou d''un autre genre que l''appareil' USING ERRCODE = 'foreign_key_violation'; END IF;"
        . " END IF; RETURN NEW; END \$fn\$",
        'DROP TRIGGER IF EXISTS loss_device_guard ON loss_device',
        'CREATE TRIGGER loss_device_guard BEFORE INSERT OR UPDATE ON loss_device FOR EACH ROW EXECUTE FUNCTION loss_device_guard_v3()',
    ];
}
