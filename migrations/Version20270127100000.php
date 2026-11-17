<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Prévention des pertes, lot L5-b « constat classé qui grossit » (17/11/2026, `docs/prevention-pertes.md` § 18.4, réserve 1) :
 * - `loss_finding.decided_count` : le nombre suivi du constat (celui que la règle compare à son seuil) au moment de la décision ;
 * - `loss_finding.grew_at` : quand le constat décidé a grossi (au moins doublé, et au moins 3 de plus) ; une fois par décision.
 *
 * Additive, idempotente ; équivalent `scripts/migrate_all_v2_pertes_l5b.sh`.
 */
final class Version20270127100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Prévention des pertes L5-b : nombre suivi à la décision, constat qui grossit';
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
        'ALTER TABLE loss_finding DROP COLUMN IF EXISTS grew_at',
        'ALTER TABLE loss_finding DROP COLUMN IF EXISTS decided_count',
    ];

    public const STATEMENTS = [
        'ALTER TABLE loss_finding ADD COLUMN IF NOT EXISTS decided_count INT DEFAULT NULL',
        'ALTER TABLE loss_finding ADD COLUMN IF NOT EXISTS grew_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL',
    ];
}
