<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Caisse, lot F3 (24/10/2026) : ticket numérique. Compteur de réimpressions et version du lien public sur pos_receipt,
 * réglages du reçu par courriel et par QR (entreprise). Strictement additif. Équivalent : scripts/migrate_all_v2_pos_receipt_digital.sh.
 */
final class Version20261024140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Caisse F3 : ticket numérique (additif)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pos_receipt ADD COLUMN IF NOT EXISTS reprint_count INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE pos_receipt ADD COLUMN IF NOT EXISTS link_version INT DEFAULT 1 NOT NULL');
        $this->addSql('ALTER TABLE pos_receipt ADD COLUMN IF NOT EXISTS link_revoked_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE entreprise ADD COLUMN IF NOT EXISTS pos_receipt_email_enabled BOOLEAN DEFAULT true NOT NULL');
        $this->addSql('ALTER TABLE entreprise ADD COLUMN IF NOT EXISTS pos_receipt_qr_enabled BOOLEAN DEFAULT true NOT NULL');
        $this->addSql('ALTER TABLE entreprise ADD COLUMN IF NOT EXISTS pos_receipt_link_days INT DEFAULT 90 NOT NULL');
        $this->addSql('ALTER TABLE entreprise ADD COLUMN IF NOT EXISTS pos_receipt_qr_print_default BOOLEAN DEFAULT true NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pos_receipt DROP COLUMN IF EXISTS reprint_count, DROP COLUMN IF EXISTS link_version, DROP COLUMN IF EXISTS link_revoked_at');
        $this->addSql('ALTER TABLE entreprise DROP COLUMN IF EXISTS pos_receipt_email_enabled, DROP COLUMN IF EXISTS pos_receipt_qr_enabled, DROP COLUMN IF EXISTS pos_receipt_link_days, DROP COLUMN IF EXISTS pos_receipt_qr_print_default');
    }
}
