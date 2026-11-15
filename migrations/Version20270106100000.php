<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Lot NOTIF-1 (15/11/2026, `docs/notifications-courriels.md`) : niveaux des courriels de suivi.
 * - `entreprise.notification_level` : niveau du site (vide = automatique : « essential » pour un site d'essai, « normal » en activité) ;
 * - `"user".notification_level` : niveau choisi par un compte (vide = comme le site) ;
 * - `notification_log` : le journal des notifications du téléphone accueille aussi les courriels (`channel`, `push` pour tout l'existant),
 *   leur destinataire, leur sujet et la date où un courriel retenu est sorti dans un résumé.
 * Additive, idempotente, aucune donnée modifiée (toutes les lignes existantes prennent `channel = 'push'`, par défaut de colonne).
 * Équivalent `scripts/migrate_all_v2_notif.sh`.
 */
final class Version20270106100000 extends AbstractMigration
{
    public const STATEMENTS = [
        'ALTER TABLE entreprise ADD COLUMN IF NOT EXISTS notification_level VARCHAR(12) DEFAULT NULL',
        'ALTER TABLE "user" ADD COLUMN IF NOT EXISTS notification_level VARCHAR(12) DEFAULT NULL',
        "ALTER TABLE notification_log ADD COLUMN IF NOT EXISTS channel VARCHAR(8) DEFAULT 'push' NOT NULL",
        'ALTER TABLE notification_log ADD COLUMN IF NOT EXISTS recipient VARCHAR(180) DEFAULT NULL',
        'ALTER TABLE notification_log ADD COLUMN IF NOT EXISTS topic VARCHAR(20) DEFAULT NULL',
        'ALTER TABLE notification_log ADD COLUMN IF NOT EXISTS released_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL',
        "CREATE INDEX IF NOT EXISTS idx_notification_log_email ON notification_log (recipient, store_day) WHERE channel = 'email'",
    ];

    public function getDescription(): string
    {
        return 'Lot NOTIF-1 : niveau des courriels de suivi du site et du compte, courriels au journal des notifications';
    }

    public function up(Schema $schema): void
    {
        foreach (self::STATEMENTS as $sql) {
            $this->addSql($sql);
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_notification_log_email');
        foreach (['released_at', 'topic', 'recipient', 'channel'] as $column) {
            $this->addSql("ALTER TABLE notification_log DROP COLUMN IF EXISTS $column");
        }
        $this->addSql('ALTER TABLE "user" DROP COLUMN IF EXISTS notification_level');
        $this->addSql('ALTER TABLE entreprise DROP COLUMN IF EXISTS notification_level');
    }
}
