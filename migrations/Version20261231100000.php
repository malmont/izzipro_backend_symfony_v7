<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * APPS-MEMBRES, lot M2-b (14/11/2026, `docs/apps-membres.md` § 8) : une fiche Entreprise CRÉÉE à partir de maintenant naît « reprise
 * faite » (valeur par défaut de `app_membership_backfilled_at` : la date du jour). Un site neuf est une copie de `gmasuite`, sans
 * fiche Entreprise ni historique : sa reprise est faite par définition, dès que sa fiche existe. Les fiches existantes gardent leur
 * valeur. Additive, idempotente, aucune donnée modifiée. Équivalent `scripts/migrate_all_v2_apps_membres_reprise_defaut.sh`.
 */
final class Version20261231100000 extends AbstractMigration
{
    public const STATEMENTS = [
        'ALTER TABLE entreprise ALTER COLUMN app_membership_backfilled_at SET DEFAULT NOW()',
    ];

    public function getDescription(): string
    {
        return 'APPS-MEMBRES M2-b : une fiche Entreprise créée après la reprise naît « reprise faite »';
    }

    public function up(Schema $schema): void
    {
        foreach (self::STATEMENTS as $sql) {
            $this->addSql($sql);
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE entreprise ALTER COLUMN app_membership_backfilled_at SET DEFAULT NULL');
    }
}
