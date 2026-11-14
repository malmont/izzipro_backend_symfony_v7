<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Prévention des pertes, suite du lot L1-b (14/11/2026, `docs/prevention-pertes.md`) : dernier constat existant au moment du dernier
 * courriel de résumé (`loss_setting.last_digest_finding_id`). Décision du propriétaire : le courriel n'annonce que les constats nés depuis
 * le courriel précédent (identifiant plus grand que cette borne) ; NULL tant qu'aucun courriel n'est parti. La borne est écrite avec le jour,
 * dans la même instruction (la réservation), et rendue avec lui si aucun envoi n'aboutit. Colonne nouvelle sur une table du module ;
 * additive, idempotente ; équivalent `scripts/migrate_all_v2_pertes_l1b_suite.sh`.
 */
final class Version20270103100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Prévention des pertes : borne du dernier courriel de résumé';
    }

    public function up(Schema $schema): void
    {
        foreach (self::STATEMENTS as $sql) {
            $this->addSql($sql);
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE loss_setting DROP COLUMN IF EXISTS last_digest_finding_id');
    }

    public const STATEMENTS = [
        'ALTER TABLE loss_setting ADD COLUMN IF NOT EXISTS last_digest_finding_id INT DEFAULT NULL',
    ];
}
