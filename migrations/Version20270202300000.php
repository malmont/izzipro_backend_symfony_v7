<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Lot ABO-LANG (21/11/2026, décision du chef) : `user.locale`, la langue du compte (`fr`, `en` ou vide). Les courriels des abonnements
 * (avis d'abonnement, fil, invitation d'un intervenant) suivent la langue du DESTINATAIRE, sinon celle du site. Colonne vide : aucune
 * donnée existante modifiée. Idempotente ; équivalent `scripts/migrate_all_v2_user_locale.sh`.
 */
final class Version20270202300000 extends AbstractMigration
{
    public const STATEMENTS = [
        'ALTER TABLE "user" ADD COLUMN IF NOT EXISTS locale VARCHAR(5) DEFAULT NULL',
    ];

    public const DOWN_STATEMENTS = [
        'ALTER TABLE "user" DROP COLUMN IF EXISTS locale',
    ];

    public function getDescription(): string
    {
        return 'ABO-LANG : langue du compte (user.locale)';
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
