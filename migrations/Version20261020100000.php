<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Caisse, chantier C3 (20/10/2026) : PIN de caisse (pos_user_pin), validations d'un responsable (pos_approval), réglages
 * de validation et de verrouillage sur la fiche entreprise (valeurs par défaut). Strictement additif ; la table user
 * n'est pas touchée. Équivalent : scripts/migrate_all_v2_pos_pin.sh. Idempotent.
 */
final class Version20261020100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Caisse C3 : PIN, validations d\'un responsable, réglages de validation et de verrouillage (additif)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE IF NOT EXISTS pos_user_pin (id SERIAL PRIMARY KEY, user_id INT NOT NULL, pin_hash VARCHAR(255) NOT NULL, failed_attempts INT NOT NULL DEFAULT 0, locked_until TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, CONSTRAINT fk_pos_user_pin_user FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE CASCADE)');
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS uniq_pos_user_pin_user ON pos_user_pin (user_id)');
        $this->addSql('CREATE TABLE IF NOT EXISTS pos_approval (id SERIAL PRIMARY KEY, token_hash VARCHAR(64) NOT NULL, action VARCHAR(30) NOT NULL, manager_id INT NOT NULL, requested_by_id INT NOT NULL, terminal_id INT NOT NULL, context TEXT NOT NULL, expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, used_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL)');
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS uniq_pos_approval_token ON pos_approval (token_hash)');
        $this->addSql('ALTER TABLE entreprise ADD COLUMN IF NOT EXISTS pos_approval_refund INT DEFAULT 0');
        $this->addSql('ALTER TABLE entreprise ADD COLUMN IF NOT EXISTS pos_approval_withdrawal INT DEFAULT 0');
        $this->addSql('ALTER TABLE entreprise ADD COLUMN IF NOT EXISTS pos_approval_fund_withdrawal INT DEFAULT 0');
        $this->addSql('ALTER TABLE entreprise ADD COLUMN IF NOT EXISTS pos_approval_drawer_open BOOLEAN NOT NULL DEFAULT TRUE');
        $this->addSql('ALTER TABLE entreprise ADD COLUMN IF NOT EXISTS pos_approval_close_without_count BOOLEAN NOT NULL DEFAULT TRUE');
        $this->addSql('ALTER TABLE entreprise ADD COLUMN IF NOT EXISTS pos_lock_minutes INT NOT NULL DEFAULT 5');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS pos_approval');
        $this->addSql('DROP TABLE IF EXISTS pos_user_pin');
        foreach (['pos_approval_refund', 'pos_approval_withdrawal', 'pos_approval_fund_withdrawal', 'pos_approval_drawer_open', 'pos_approval_close_without_count', 'pos_lock_minutes'] as $column) {
            $this->addSql(sprintf('ALTER TABLE entreprise DROP COLUMN IF EXISTS %s', $column));
        }
    }
}
