<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * APPS-MEMBRES, lot M1 (14/11/2026, `docs/apps-membres.md` § 8) : appartenance d'un compte client à une famille d'applications
 * (boutique, memoires, pos), version des conditions gardée sur une commande en invité, mode du site (« observe » par défaut ;
 * « require » viendra au lot M3). Additive, idempotente, aucune donnée existante modifiée ; la reprise de l'historique est une
 * commande à part (lot M2), jamais dans la migration. Équivalent `scripts/migrate_all_v2_apps_membres.sh`.
 */
final class Version20261229100000 extends AbstractMigration
{
    public const STATEMENTS = [
        'CREATE TABLE IF NOT EXISTS user_app_membership (id SERIAL NOT NULL, user_id INT NOT NULL, app VARCHAR(20) NOT NULL, source VARCHAR(20) NOT NULL, joined_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, terms_version VARCHAR(40) DEFAULT NULL, terms_accepted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id), CONSTRAINT fk_user_app_membership_user FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE CASCADE)',
        'CREATE UNIQUE INDEX IF NOT EXISTS uniq_user_app_membership ON user_app_membership (user_id, app)',
        'CREATE INDEX IF NOT EXISTS idx_user_app_membership_app ON user_app_membership (app)',
        'ALTER TABLE "order" ADD COLUMN IF NOT EXISTS terms_version VARCHAR(40) DEFAULT NULL',
        'ALTER TABLE entreprise ADD COLUMN IF NOT EXISTS app_membership_mode VARCHAR(10) DEFAULT \'observe\' NOT NULL',
    ];

    public function getDescription(): string
    {
        return 'APPS-MEMBRES M1 : appartenances des comptes aux familles d\'applications, version des conditions d\'une commande en invité, mode du site';
    }

    public function up(Schema $schema): void
    {
        foreach (self::STATEMENTS as $sql) {
            $this->addSql($sql);
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE entreprise DROP COLUMN IF EXISTS app_membership_mode');
        $this->addSql('ALTER TABLE "order" DROP COLUMN IF EXISTS terms_version');
        $this->addSql('DROP TABLE IF EXISTS user_app_membership');
    }
}
