<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Prévention des pertes, lot L1-b (14/11/2026, `docs/prevention-pertes.md`) : jour du magasin du dernier courriel de résumé envoyé
 * (`loss_setting.last_digest_day`), pour n'en envoyer qu'un par jour. Colonne nouvelle sur une table du module ; additive, idempotente ;
 * équivalent `scripts/migrate_all_v2_pertes_l1b.sh`.
 */
final class Version20270102100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Prévention des pertes L1-b : jour du dernier courriel de résumé';
    }

    public function up(Schema $schema): void
    {
        foreach (self::STATEMENTS as $sql) {
            $this->addSql($sql);
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE loss_setting DROP COLUMN IF EXISTS last_digest_day');
    }

    public const STATEMENTS = [
        'ALTER TABLE loss_setting ADD COLUMN IF NOT EXISTS last_digest_day DATE DEFAULT NULL',
    ];
}
