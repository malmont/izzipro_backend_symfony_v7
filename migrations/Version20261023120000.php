<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Caisse, lot R1 (23/10/2026) : réglages de caisse modifiables par site dans EasyAdmin (PIN, fond suggéré, moyens de
 * paiement du POS, ticket et numéros de taxes, paniers en attente, alerte espèces, terminal exigé), préfixe de ticket par
 * poste, commission plateforme en ligne. Strictement additif, défauts = comportement actuel.
 * Équivalent : scripts/migrate_all_v2_pos_settings.sh.
 */
final class Version20261023120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Caisse R1 : réglages de caisse par site (additif)';
    }

    public function up(Schema $schema): void
    {
        foreach ([
            'pos_pin_min_length INT DEFAULT 4 NOT NULL', 'pos_pin_max_length INT DEFAULT 6 NOT NULL', 'pos_pin_max_attempts INT DEFAULT 5 NOT NULL', 'pos_pin_lock_minutes INT DEFAULT 15 NOT NULL',
            'pos_opening_fund_suggested INT DEFAULT NULL', 'pos_closing_kept_fund_suggested INT DEFAULT NULL',
            'pos_pay_cash BOOLEAN DEFAULT true NOT NULL', 'pos_pay_declared_card BOOLEAN DEFAULT true NOT NULL', 'pos_pay_paypal BOOLEAN DEFAULT true NOT NULL', 'pos_pay_multi BOOLEAN DEFAULT true NOT NULL',
            'pos_declared_card_blocked_with_terminal BOOLEAN DEFAULT false NOT NULL',
            'pos_receipt_header TEXT DEFAULT NULL', 'pos_receipt_footer TEXT DEFAULT NULL', 'pos_receipt_show_tax_numbers BOOLEAN DEFAULT true NOT NULL',
            'gst_number VARCHAR(30) DEFAULT NULL', 'qst_number VARCHAR(30) DEFAULT NULL',
            'pos_held_carts_max INT DEFAULT 10 NOT NULL', 'pos_held_carts_alert_hours INT DEFAULT 24 NOT NULL',
            'pos_cash_alert_threshold INT DEFAULT NULL', 'pos_terminal_header_required BOOLEAN DEFAULT false NOT NULL',
        ] as $column) {
            $this->addSql('ALTER TABLE entreprise ADD COLUMN IF NOT EXISTS ' . $column);
        }
        $this->addSql('ALTER TABLE cash_register ADD COLUMN IF NOT EXISTS receipt_prefix VARCHAR(10) DEFAULT NULL');
        $this->addSql('ALTER TABLE stripe_config ADD COLUMN IF NOT EXISTS online_fee_bp INT DEFAULT 100 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE entreprise DROP COLUMN IF EXISTS pos_pin_min_length, DROP COLUMN IF EXISTS pos_pin_max_length, DROP COLUMN IF EXISTS pos_pin_max_attempts, DROP COLUMN IF EXISTS pos_pin_lock_minutes, DROP COLUMN IF EXISTS pos_opening_fund_suggested, DROP COLUMN IF EXISTS pos_closing_kept_fund_suggested, DROP COLUMN IF EXISTS pos_pay_cash, DROP COLUMN IF EXISTS pos_pay_declared_card, DROP COLUMN IF EXISTS pos_pay_paypal, DROP COLUMN IF EXISTS pos_pay_multi, DROP COLUMN IF EXISTS pos_declared_card_blocked_with_terminal, DROP COLUMN IF EXISTS pos_receipt_header, DROP COLUMN IF EXISTS pos_receipt_footer, DROP COLUMN IF EXISTS pos_receipt_show_tax_numbers, DROP COLUMN IF EXISTS gst_number, DROP COLUMN IF EXISTS qst_number, DROP COLUMN IF EXISTS pos_held_carts_max, DROP COLUMN IF EXISTS pos_held_carts_alert_hours, DROP COLUMN IF EXISTS pos_cash_alert_threshold, DROP COLUMN IF EXISTS pos_terminal_header_required');
        $this->addSql('ALTER TABLE cash_register DROP COLUMN IF EXISTS receipt_prefix');
        $this->addSql('ALTER TABLE stripe_config DROP COLUMN IF EXISTS online_fee_bp');
    }
}
