<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Notifications sur téléphone, lot N1 (11/11/2026, `docs/notifications-gestion.md`) : appareils inscrits (`push_device`), types coupés
 * par utilisateur (`notification_preference`), journal (`notification_log`). Additive, idempotente ; équivalent
 * `scripts/migrate_all_v2_gestion_notifications.sh` pour toutes les bases.
 */
final class Version20261206100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Notifications sur téléphone : appareils, préférences, journal';
    }

    public function up(Schema $schema): void
    {
        foreach (self::STATEMENTS as $sql) {
            $this->addSql($sql);
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS notification_log');
        $this->addSql('DROP TABLE IF EXISTS notification_preference');
        $this->addSql('DROP TABLE IF EXISTS push_device');
    }

    public const STATEMENTS = [
        'CREATE TABLE IF NOT EXISTS push_device (id SERIAL NOT NULL, user_id INT NOT NULL, platform VARCHAR(20) NOT NULL, token VARCHAR(512) NOT NULL, device_name VARCHAR(120) DEFAULT NULL, locale VARCHAR(5) DEFAULT NULL, app_version VARCHAR(40) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, last_seen_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, disabled_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, disabled_reason VARCHAR(40) DEFAULT NULL, PRIMARY KEY(id))',
        'CREATE UNIQUE INDEX IF NOT EXISTS uniq_push_device_token ON push_device (token)',
        'CREATE INDEX IF NOT EXISTS idx_push_device_user ON push_device (user_id)',
        'CREATE TABLE IF NOT EXISTS notification_preference (id SERIAL NOT NULL, user_id INT NOT NULL, kind VARCHAR(30) NOT NULL, enabled BOOLEAN NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))',
        'CREATE UNIQUE INDEX IF NOT EXISTS uniq_notification_preference_user_kind ON notification_preference (user_id, kind)',
        'CREATE TABLE IF NOT EXISTS notification_log (id SERIAL NOT NULL, user_id INT NOT NULL, kind VARCHAR(30) NOT NULL, title VARCHAR(255) NOT NULL, body TEXT NOT NULL, action JSON NOT NULL, subject JSON NOT NULL, status VARCHAR(20) NOT NULL, devices INT NOT NULL, error VARCHAR(255) DEFAULT NULL, store_day DATE NOT NULL, sent_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))',
        'CREATE INDEX IF NOT EXISTS idx_notification_log_user_day ON notification_log (user_id, store_day)',
    ];
}
