<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Prévention des pertes, lot L5-a « alerte en caisse » (17/11/2026, `docs/prevention-pertes.md` § 18.2, « B ») :
 * - `loss_setting.cashier_alerts_enabled` : la caisse montre-t-elle les articles suivis non lus avant le paiement (éteint par défaut) ;
 * - `loss_counter_alert_day` : des COMPTEURS par poste de caisse et par jour du magasin (alertes montrées, « continuer quand même »).
 *   Ni personne, ni vente, ni article : deux nombres, que le bilan du soir lit.
 *
 * Additive, idempotente ; équivalent `scripts/migrate_all_v2_pertes_l5a.sh`.
 */
final class Version20270126100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Prévention des pertes L5-a : alerte en caisse (réglage, compteurs par poste et par jour)';
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

    public const DOWN_STATEMENTS = [
        'DROP TABLE IF EXISTS loss_counter_alert_day',
        'ALTER TABLE loss_setting DROP COLUMN IF EXISTS cashier_alerts_enabled',
    ];

    public const STATEMENTS = [
        'ALTER TABLE loss_setting ADD COLUMN IF NOT EXISTS cashier_alerts_enabled BOOLEAN DEFAULT false NOT NULL',
        'CREATE TABLE IF NOT EXISTS loss_counter_alert_day (cash_register_id INT NOT NULL, store_day DATE NOT NULL, shown INT DEFAULT 0 NOT NULL,'
        . ' proceeded INT DEFAULT 0 NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(cash_register_id, store_day),'
        . ' CONSTRAINT loss_counter_alert_day_counts CHECK (shown >= 0 AND proceeded >= 0 AND proceeded <= shown))',
        'CREATE INDEX IF NOT EXISTS idx_loss_counter_alert_day_day ON loss_counter_alert_day (store_day)',
    ];
}
