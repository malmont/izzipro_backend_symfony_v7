<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Lot DOC-2 (15/11/2026, `docs/rapports-gestion.md`) : bilans de période et d'année en PDF. Deux colonnes de plus sur `gestion_report` :
 * `content` (les chiffres calculés à la demande, que le worker met en page tels quels) et `advice` (l'avis de la console demandé avec
 * le bilan : message de la console, puis son issue). Additive, idempotente ; aucune donnée existante modifiée. Équivalent
 * `scripts/migrate_all_v2_rapports_doc2.sh` (mêmes 2 instructions).
 */
final class Version20270111100000 extends AbstractMigration
{
    public const STATEMENTS = [
        'ALTER TABLE gestion_report ADD COLUMN IF NOT EXISTS content JSONB DEFAULT NULL',
        'ALTER TABLE gestion_report ADD COLUMN IF NOT EXISTS advice JSONB DEFAULT NULL',
    ];

    public function getDescription(): string
    {
        return 'Lot DOC-2 : bilans de période et d\'année en PDF (gestion_report.content, gestion_report.advice)';
    }

    public function up(Schema $schema): void
    {
        foreach (self::STATEMENTS as $sql) {
            $this->addSql($sql);
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE gestion_report DROP COLUMN IF EXISTS content');
        $this->addSql('ALTER TABLE gestion_report DROP COLUMN IF EXISTS advice');
    }
}
