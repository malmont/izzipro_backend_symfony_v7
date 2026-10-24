<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Caisse, lots F13 / F16 (31/10/2026) : kit Stripe Terminal dans l'application (Tap to Pay, lecteur Bluetooth WisePad 3).
 * Mode du terminal par poste (NULL = déduit : serveur si lecteur rattaché, sinon manuel), autorisations par site (défaut non),
 * délai d'expiration d'un remboursement Interac en attente sur le lecteur, canal du paiement (server | sdk). Additif.
 * Équivalent : scripts/migrate_all_v2_pos_terminal_sdk.sh.
 */
final class Version20261031100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Caisse F13/F16 : kit Stripe Terminal dans l\'application (additif)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cash_register ADD COLUMN IF NOT EXISTS terminal_mode VARCHAR(16) DEFAULT NULL');
        foreach (['pos_tap_to_pay_allowed BOOLEAN DEFAULT false NOT NULL', 'pos_bluetooth_reader_allowed BOOLEAN DEFAULT false NOT NULL', 'pos_terminal_refund_pending_minutes INT DEFAULT 15 NOT NULL'] as $column) {
            $this->addSql('ALTER TABLE entreprise ADD COLUMN IF NOT EXISTS ' . $column);
        }
        foreach (['channel VARCHAR(10) DEFAULT \'server\' NOT NULL', 'stripe_charge_id VARCHAR(64) DEFAULT NULL', 'reader_label VARCHAR(100) DEFAULT NULL', 'refund_requested_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL'] as $column) {
            $this->addSql('ALTER TABLE pos_terminal_payment ADD COLUMN IF NOT EXISTS ' . $column);
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cash_register DROP COLUMN IF EXISTS terminal_mode');
        $this->addSql('ALTER TABLE entreprise DROP COLUMN IF EXISTS pos_tap_to_pay_allowed, DROP COLUMN IF EXISTS pos_bluetooth_reader_allowed, DROP COLUMN IF EXISTS pos_terminal_refund_pending_minutes');
        $this->addSql('ALTER TABLE pos_terminal_payment DROP COLUMN IF EXISTS channel, DROP COLUMN IF EXISTS stripe_charge_id, DROP COLUMN IF EXISTS reader_label, DROP COLUMN IF EXISTS refund_requested_at');
    }
}
