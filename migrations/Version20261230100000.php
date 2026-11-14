<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * APPS-MEMBRES, lot M2 (14/11/2026, `docs/apps-membres.md` § 8) : date de la reprise de l'historique des appartenances d'un site
 * (vide : pas faite), posée par `app:members:backfill <site> --apply`. Additive, idempotente, aucune donnée modifiée ; la reprise
 * elle-même n'est jamais dans une migration. Équivalent `scripts/migrate_all_v2_apps_membres_reprise.sh`.
 */
final class Version20261230100000 extends AbstractMigration
{
    public const STATEMENTS = [
        'ALTER TABLE entreprise ADD COLUMN IF NOT EXISTS app_membership_backfilled_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL',
    ];

    public function getDescription(): string
    {
        return 'APPS-MEMBRES M2 : date de la reprise de l\'historique des appartenances du site';
    }

    public function up(Schema $schema): void
    {
        foreach (self::STATEMENTS as $sql) {
            $this->addSql($sql);
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE entreprise DROP COLUMN IF EXISTS app_membership_backfilled_at');
    }
}
