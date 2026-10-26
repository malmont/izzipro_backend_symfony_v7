<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Caisse, lot M1 (mise en production des paiements de caisse) : mode Stripe de la caisse par site (test par défaut), compte Connect réel
 * distinct (le compte existant reste celui du mode test ; un magasin peut n'avoir qu'un compte réel : account_id devient facultatif),
 * mode Stripe de chaque paiement par terminal, alertes de surveillance (réglages par site, état des alertes envoyées). Additif.
 * Équivalent : scripts/migrate_all_v2_pos_stripe_live.sh.
 */
final class Version20261103100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Caisse M1 : mode Stripe par site, compte Connect réel, surveillance (additif)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE entreprise ADD COLUMN IF NOT EXISTS pos_stripe_mode VARCHAR(8) DEFAULT \'test\' NOT NULL');
        $this->addSql('ALTER TABLE entreprise ADD COLUMN IF NOT EXISTS pos_alerts_enabled BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE entreprise ADD COLUMN IF NOT EXISTS pos_alerts_email VARCHAR(180) DEFAULT NULL');
        $this->addSql('ALTER TABLE stripe_config ADD COLUMN IF NOT EXISTS live_account_id VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE stripe_config ADD COLUMN IF NOT EXISTS live_is_active BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE stripe_config ADD COLUMN IF NOT EXISTS live_terminal_location_id VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE stripe_config ALTER COLUMN account_id DROP NOT NULL');
        $this->addSql('ALTER TABLE pos_terminal_payment ADD COLUMN IF NOT EXISTS stripe_mode VARCHAR(8) DEFAULT \'test\' NOT NULL');
        $this->addSql('CREATE TABLE IF NOT EXISTS pos_health_alert (id SERIAL PRIMARY KEY, code VARCHAR(40) NOT NULL, level VARCHAR(10) NOT NULL, fingerprint VARCHAR(64) NOT NULL,
            first_seen_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, last_sent_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, resolved_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL)');
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS uniq_pos_health_alert_code ON pos_health_alert (code)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS pos_health_alert');
        $this->addSql('ALTER TABLE pos_terminal_payment DROP COLUMN IF EXISTS stripe_mode');
        $this->addSql('ALTER TABLE stripe_config DROP COLUMN IF EXISTS live_account_id, DROP COLUMN IF EXISTS live_is_active, DROP COLUMN IF EXISTS live_terminal_location_id');
        $this->addSql('ALTER TABLE entreprise DROP COLUMN IF EXISTS pos_stripe_mode, DROP COLUMN IF EXISTS pos_alerts_enabled, DROP COLUMN IF EXISTS pos_alerts_email');
    }
}
