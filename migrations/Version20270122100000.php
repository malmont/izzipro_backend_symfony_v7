<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Prévention des pertes, lot L4-b « sortie sans vente » (16/11/2026, `docs/prevention-pertes.md` § 17.13) :
 * - `loss_finding.evidence_after_decision` : une preuve s'est ajoutée APRÈS la décision du gérant. Posé par l'écriture qui ajoute la
 *   preuve, remis à faux par une décision : « du nouveau depuis la décision » ne dépend plus de deux dates à la seconde (une preuve
 *   écrite dans la seconde d'une décision n'était pas vue) ;
 * - `loss_setting.exit_cursor` : le rang du dernier fait de pièce jugé par les règles de la sortie (lot L4-b) ;
 * - un index partiel sur les arrivées à la SORTIE (`ZONE_SEEN`, zone `exit`) des vraies pièces : les règles de la sortie les lisent par
 *   rang, sans parcourir les arrivées des autres zones ;
 * - un index sur la pièce des articles vendus (rendus compris) : le jugement d'une lecture cherche la vente en cours à son heure.
 *
 * Aucune fonction ni table d'un lot précédent n'est remplacée. Additive, idempotente ; équivalent
 * `scripts/migrate_all_v2_pertes_l4b.sh`.
 */
final class Version20270122100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Prévention des pertes L4-b : du nouveau depuis la décision, curseur des lectures de sortie, index des arrivées à la sortie';
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
        'DROP INDEX IF EXISTS idx_loss_piece_event_exit',
        'DROP INDEX IF EXISTS idx_loss_sale_unit_piece',
        'ALTER TABLE loss_setting DROP COLUMN IF EXISTS exit_cursor',
        'ALTER TABLE loss_finding DROP COLUMN IF EXISTS evidence_after_decision',
    ];

    public const STATEMENTS = [
        'ALTER TABLE loss_finding ADD COLUMN IF NOT EXISTS evidence_after_decision BOOLEAN DEFAULT false NOT NULL',
        'ALTER TABLE loss_setting ADD COLUMN IF NOT EXISTS exit_cursor INT DEFAULT NULL',
        "CREATE INDEX IF NOT EXISTS idx_loss_piece_event_exit ON loss_piece_event (id) WHERE type = 'ZONE_SEEN' AND NOT exercise AND ((data)::jsonb ->> 'zone') = 'exit'",
        'CREATE INDEX IF NOT EXISTS idx_loss_sale_unit_piece ON loss_sale_unit (piece_id) WHERE piece_id IS NOT NULL',
    ];
}
