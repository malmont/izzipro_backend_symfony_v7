<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Caisse, chantier C1 (19/10/2026) : coupures servies au POS (type_cash, lignes CAD ajoutées si absentes), seuil d'écart
 * et pas d'arrondi des espèces (entreprise), effet espèces et arrondi des transactions de caisse, arrondi des espèces
 * de la commande (défaut 0), rapports Z (cash_register_report). Strictement additif : aucune donnée existante modifiée.
 * Équivalent : scripts/migrate_all_v2_caisse_closing.sh. Idempotent.
 */
final class Version20261019130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Caisse C1 : coupures, seuil d\'écart, arrondi des espèces, effet espèces des transactions, rapports Z (additif)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE type_cash ADD COLUMN IF NOT EXISTS value_cents INT DEFAULT NULL');
        $this->addSql('ALTER TABLE type_cash ADD COLUMN IF NOT EXISTS kind VARCHAR(4) DEFAULT NULL');
        $this->addSql('ALTER TABLE type_cash ADD COLUMN IF NOT EXISTS active BOOLEAN NOT NULL DEFAULT TRUE');
        $this->addSql('ALTER TABLE type_cash ADD COLUMN IF NOT EXISTS currency VARCHAR(3) DEFAULT NULL');
        $this->addSql('ALTER TABLE type_cash ADD COLUMN IF NOT EXISTS position INT DEFAULT NULL');
        $this->addSql('INSERT INTO type_cash (id, name, value, value_cents, kind, active, currency, position) VALUES
 (1, \'100 $\', 100, 10000, \'bill\', TRUE, \'CAD\', 1),
 (2, \'50 $\', 50, 5000, \'bill\', TRUE, \'CAD\', 2),
 (11, \'20 $\', 20, 2000, \'bill\', TRUE, \'CAD\', 3),
 (3, \'10 $\', 10, 1000, \'bill\', TRUE, \'CAD\', 4),
 (4, \'5 $\', 5, 500, \'bill\', TRUE, \'CAD\', 5),
 (5, \'2 $\', 2, 200, \'coin\', TRUE, \'CAD\', 6),
 (6, \'1 $\', 1, 100, \'coin\', TRUE, \'CAD\', 7),
 (7, \'25 ¢\', 0.25, 25, \'coin\', TRUE, \'CAD\', 8),
 (8, \'10 ¢\', 0.10, 10, \'coin\', TRUE, \'CAD\', 9),
 (9, \'5 ¢\', 0.05, 5, \'coin\', TRUE, \'CAD\', 10),
 (10, \'1 ¢\', 0.01, 1, \'coin\', FALSE, \'CAD\', 11)
ON CONFLICT (id) DO NOTHING');
        $this->addSql('SELECT setval(pg_get_serial_sequence(\'type_cash\', \'id\'), GREATEST((SELECT MAX(id) FROM type_cash), 1))');
        $this->addSql('ALTER TABLE entreprise ADD COLUMN IF NOT EXISTS pos_discrepancy_threshold INT NOT NULL DEFAULT 0');
        $this->addSql('ALTER TABLE entreprise ADD COLUMN IF NOT EXISTS pos_cash_rounding_step INT DEFAULT NULL');
        $this->addSql('ALTER TABLE transaction_caisse ADD COLUMN IF NOT EXISTS cash_amount INT DEFAULT NULL');
        $this->addSql('ALTER TABLE transaction_caisse ADD COLUMN IF NOT EXISTS cash_rounding INT DEFAULT NULL');
        $this->addSql('ALTER TABLE "order" ADD COLUMN IF NOT EXISTS cash_rounding INT NOT NULL DEFAULT 0');
        $this->addSql('CREATE TABLE IF NOT EXISTS cash_register_report (
    id SERIAL PRIMARY KEY,
    cash_register_id INT NOT NULL,
    caisse_id INT NOT NULL,
    number INT NOT NULL,
    closed_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
    closed_by_id INT DEFAULT NULL,
    counted_cash INT DEFAULT NULL,
    expected_cash INT NOT NULL,
    discrepancy INT DEFAULT NULL,
    discrepancy_reason TEXT DEFAULT NULL,
    kept_fund INT DEFAULT NULL,
    bank_deposit INT DEFAULT NULL,
    data JSON NOT NULL,
    CONSTRAINT fk_cash_register_report_register FOREIGN KEY (cash_register_id) REFERENCES cash_register (id),
    CONSTRAINT fk_cash_register_report_session FOREIGN KEY (caisse_id) REFERENCES caisse (id),
    CONSTRAINT fk_cash_register_report_user FOREIGN KEY (closed_by_id) REFERENCES "user" (id) ON DELETE SET NULL
)');
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS uniq_cash_register_report_number ON cash_register_report (cash_register_id, number)');
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS uniq_cash_register_report_session ON cash_register_report (caisse_id)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_cash_register_report_closed_by ON cash_register_report (closed_by_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS cash_register_report');
        $this->addSql('ALTER TABLE "order" DROP COLUMN IF EXISTS cash_rounding');
        $this->addSql('ALTER TABLE transaction_caisse DROP COLUMN IF EXISTS cash_rounding');
        $this->addSql('ALTER TABLE transaction_caisse DROP COLUMN IF EXISTS cash_amount');
        $this->addSql('ALTER TABLE entreprise DROP COLUMN IF EXISTS pos_cash_rounding_step');
        $this->addSql('ALTER TABLE entreprise DROP COLUMN IF EXISTS pos_discrepancy_threshold');
    }
}
