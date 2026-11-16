<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Prévention des pertes, lot L4-c « heures d'ouverture et dépôt » (16/11/2026, `docs/prevention-pertes.md` § 17.15) :
 * - `loss_setting.opening_hours` (JSON : une plage par jour de la semaine, à l'heure du magasin, ou fermé ; nul = pas d'heures
 *   confirmées, la règle « hors des heures » est éteinte) et `loss_setting.hours_margin_minutes` (marge avant et après ; nul = 60) ;
 * - `loss_setting.depot_cursor` et `loss_setting.back_door_cursor` : le rang du dernier fait de pièce jugé par les règles du dépôt
 *   (la porte arrière a son propre curseur : elle attend la fin de la journée du magasin, les autres non) ;
 * - deux index partiels : les arrivées et départs de zone des vraies pièces, et les lectures de la porte arrière, lus par rang ;
 * - une garde NOUVELLE des appareils (`loss_device_guard_v2`) : celle du lot L3-b1, plus la zone `back_door` ; le déclencheur est
 *   reposé sur elle, l'ancienne fonction reste pour le retour arrière.
 *
 * Additive, idempotente ; équivalent `scripts/migrate_all_v2_pertes_l4c.sh`. Rejouer la migration du lot L3-a
 * (`Version20270113100000`) repose le déclencheur sur l'ANCIENNE fonction : rejouer celle-ci ensuite (celle du lot L3-b1 ne
 * remplace que l'ancienne fonction, sans toucher au déclencheur).
 */
final class Version20270124100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Prévention des pertes L4-c : heures d\'ouverture, curseurs du dépôt, index des mouvements de zone, zone back_door';
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
        // EN PREMIER : une antenne réglée sur la porte arrière ne peut pas rester sous l'ancienne garde
        "DO \$do\$ BEGIN IF EXISTS (SELECT 1 FROM loss_device WHERE zone = 'back_door') THEN"
        . " RAISE EXCEPTION 'prévention des pertes : une antenne est réglée sur la porte arrière, la zone ne se retire pas'; END IF; END \$do\$",
        'DROP TRIGGER IF EXISTS loss_device_guard ON loss_device',
        'CREATE TRIGGER loss_device_guard BEFORE INSERT OR UPDATE ON loss_device FOR EACH ROW EXECUTE FUNCTION loss_device_guard()',
        'DROP FUNCTION IF EXISTS loss_device_guard_v2()',
        'DROP INDEX IF EXISTS idx_loss_piece_event_moves',
        'DROP INDEX IF EXISTS idx_loss_piece_event_back_door',
        'ALTER TABLE loss_setting DROP COLUMN IF EXISTS back_door_cursor',
        'ALTER TABLE loss_setting DROP COLUMN IF EXISTS depot_cursor',
        'ALTER TABLE loss_setting DROP COLUMN IF EXISTS hours_margin_minutes',
        'ALTER TABLE loss_setting DROP COLUMN IF EXISTS opening_hours',
    ];

    public const STATEMENTS = [
        'ALTER TABLE loss_setting ADD COLUMN IF NOT EXISTS opening_hours TEXT DEFAULT NULL',
        'ALTER TABLE loss_setting ADD COLUMN IF NOT EXISTS hours_margin_minutes INT DEFAULT NULL',
        'ALTER TABLE loss_setting ADD COLUMN IF NOT EXISTS depot_cursor INT DEFAULT NULL',
        'ALTER TABLE loss_setting ADD COLUMN IF NOT EXISTS back_door_cursor INT DEFAULT NULL',
        "CREATE INDEX IF NOT EXISTS idx_loss_piece_event_moves ON loss_piece_event (id) WHERE type IN ('ZONE_SEEN', 'ZONE_LEFT') AND NOT exercise",
        // la porte arrière a son curseur : ses lectures, par rang, sans parcourir les autres mouvements
        "CREATE INDEX IF NOT EXISTS idx_loss_piece_event_back_door ON loss_piece_event (id) WHERE type = 'ZONE_SEEN' AND NOT exercise AND ((data)::jsonb ->> 'zone') = 'back_door'",
        "CREATE OR REPLACE FUNCTION loss_device_guard_v2() RETURNS trigger LANGUAGE plpgsql AS \$fn\$ BEGIN"
        . " IF NEW.kind NOT IN ('counter_mat', 'reader', 'printer', 'camera') OR NEW.status NOT IN ('ok', 'down', 'retired') THEN RAISE EXCEPTION 'prévention des pertes : genre ou état d''appareil inconnu' USING ERRCODE = 'check_violation'; END IF;"
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
        'CREATE TRIGGER loss_device_guard BEFORE INSERT OR UPDATE ON loss_device FOR EACH ROW EXECUTE FUNCTION loss_device_guard_v2()',
    ];
}
