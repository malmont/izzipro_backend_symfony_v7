<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Lot ABO-CONF (19/11/2026) : historique des prix d'une formule d'abonnement (`subscription_plan_price` : prix, devise, rythme, du / au,
 * prix Stripe de l'époque). Un prix ou un rythme modifié clôt la ligne en cours et en ouvre une : les nouveaux abonnés paient le nouveau
 * prix, les abonnés en cours gardent le leur. Table neuve : aucune donnée existante modifiée. Idempotente ; équivalent
 * `scripts/migrate_all_v2_abo_catalog.sh`.
 */
final class Version20270201800000 extends AbstractMigration
{
    public const STATEMENTS = [
        'CREATE TABLE IF NOT EXISTS subscription_plan_price (id SERIAL PRIMARY KEY, plan_id INT NOT NULL REFERENCES subscription_plan (id) ON DELETE CASCADE, price_cents INT NOT NULL, currency VARCHAR(3) NOT NULL, interval VARCHAR(10) NOT NULL, interval_count INT NOT NULL, stripe_price_id VARCHAR(255) DEFAULT NULL, valid_from TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, valid_until TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, changed_by VARCHAR(180) DEFAULT NULL)',
        'CREATE INDEX IF NOT EXISTS idx_subscription_plan_price_plan ON subscription_plan_price (plan_id, valid_from)',
        // décision C du propriétaire : commission des abonnements par site (vide = celle de la plateforme) et commission appliquée à chaque abonnement
        'ALTER TABLE stripe_config ADD COLUMN IF NOT EXISTS subscription_fee_bp INT DEFAULT NULL',
        'ALTER TABLE subscription ADD COLUMN IF NOT EXISTS fee_bp_applied INT DEFAULT NULL',
    ];

    public const DOWN_STATEMENTS = [
        'ALTER TABLE subscription DROP COLUMN IF EXISTS fee_bp_applied',
        'ALTER TABLE stripe_config DROP COLUMN IF EXISTS subscription_fee_bp',
        'DROP TABLE IF EXISTS subscription_plan_price',
    ];

    public function getDescription(): string
    {
        return 'ABO-CONF : historique des prix des formules d\'abonnement';
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
