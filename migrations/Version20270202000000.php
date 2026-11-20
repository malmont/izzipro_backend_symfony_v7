<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Lot G-5 (19/11/2026, `docs/ecrans-et-contextes.md` § 17) : transfert léger entre boutiques d'un groupe, côté SITE. Le type de mouvement
 * « Transfert » (une donnée) et les MARQUES d'idempotence (`group_transfer_marks`) : chaque écriture d'un transfert (sortie, retour, commande de
 * destination) porte sa marque, unique par transfert, sorte et ligne ; un rejeu trouve la marque et n'écrit rien de plus. Tables et ligne
 * neuves, rien d'existant modifié ; idempotente ; équivalent `scripts/migrate_all_v2_group_transfers.sh`.
 */
final class Version20270202000000 extends AbstractMigration
{
    public const STATEMENTS = [
        // même forme que le type « Import » (Version20270108100000) : l'identifiant vient de la séquence, ou du plus grand + 1
        "DO \$\$ DECLARE new_id INT; BEGIN IF NOT EXISTS (SELECT 1 FROM movement_type WHERE name = 'Transfert') THEN IF to_regclass('movement_type_id_seq') IS NOT NULL THEN PERFORM setval('movement_type_id_seq', GREATEST((SELECT COALESCE(MAX(id), 1) FROM movement_type), (SELECT last_value FROM movement_type_id_seq), 5)); new_id := nextval('movement_type_id_seq'); ELSE new_id := (SELECT GREATEST(COALESCE(MAX(id), 0) + 1, 6) FROM movement_type); END IF; INSERT INTO movement_type (id, name, description) VALUES (new_id, 'Transfert', 'Transfert entre boutiques d''un groupe (lot G-5)'); END IF; END \$\$",
        'CREATE TABLE IF NOT EXISTS group_transfer_marks (id SERIAL PRIMARY KEY, transfer_uid VARCHAR(40) NOT NULL, kind VARCHAR(10) NOT NULL, line_no INT NOT NULL, ref_id INT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT NOW())',
        'CREATE UNIQUE INDEX IF NOT EXISTS uniq_group_transfer_marks ON group_transfer_marks (transfer_uid, kind, line_no)',
    ];

    public const DOWN_STATEMENTS = [
        'DROP TABLE IF EXISTS group_transfer_marks',
    ];

    public function getDescription(): string
    {
        return 'G-5 : type de mouvement « Transfert » et marques d\'idempotence des transferts entre boutiques';
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
}
