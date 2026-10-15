<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261015120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Caisse : postes de caisse (cash_register), caisse.cash_register_id et caisse.closed_at, une session ouverte par poste, types de transaction ; équivalent de scripts/migrate_all_v2_cash_registers.sh, idempotent';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE IF NOT EXISTS cash_register (id SERIAL NOT NULL, name VARCHAR(100) NOT NULL, is_active BOOLEAN NOT NULL DEFAULT TRUE, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT NOW(), PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS uniq_cash_register_name ON cash_register (name)');
        // Un poste « Caisse principale » s'il n'en existe aucun ; toutes les sessions existantes lui sont rattachées
        $this->addSql("INSERT INTO cash_register (name) SELECT 'Caisse principale' WHERE NOT EXISTS (SELECT 1 FROM cash_register)");
        $this->addSql('ALTER TABLE caisse ADD COLUMN IF NOT EXISTS cash_register_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE caisse ADD COLUMN IF NOT EXISTS closed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('UPDATE caisse SET cash_register_id = (SELECT MIN(id) FROM cash_register) WHERE cash_register_id IS NULL');
        $this->addSql('ALTER TABLE caisse ALTER COLUMN cash_register_id SET NOT NULL');
        $this->addSql("DO \$\$ BEGIN IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'fk_caisse_cash_register') THEN ALTER TABLE caisse ADD CONSTRAINT fk_caisse_cash_register FOREIGN KEY (cash_register_id) REFERENCES cash_register (id) NOT DEFERRABLE INITIALLY IMMEDIATE; END IF; END \$\$");
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_caisse_cash_register ON caisse (cash_register_id)');
        // Une seule session ouverte par poste ; non créé (avertissement) si une base en a déjà plusieurs
        $this->addSql("DO \$\$ BEGIN IF EXISTS (SELECT 1 FROM caisse WHERE is_open = true GROUP BY cash_register_id HAVING COUNT(*) > 1) THEN RAISE WARNING 'caisse : plusieurs sessions ouvertes sur un même poste, index uniq_caisse_open_register non créé'; ELSE CREATE UNIQUE INDEX IF NOT EXISTS uniq_caisse_open_register ON caisse (cash_register_id) WHERE is_open = true; END IF; END \$\$");
        // Types de transaction de caisse (HandleCaisseTransactionUseCase) : aucune base n'en avait au 15/10/2026
        $this->addSql("INSERT INTO transaction_type (id, name) VALUES (1, 'Vente'), (2, 'Remboursement'), (3, 'Dépôt'), (4, 'Ouverture'), (5, 'Fermeture'), (6, 'Retrait'), (7, 'Retrait fond de caisse'), (8, 'Ajout fond de caisse') ON CONFLICT (id) DO NOTHING");
        $this->addSql("SELECT setval(pg_get_serial_sequence('transaction_type', 'id'), GREATEST((SELECT MAX(id) FROM transaction_type), 1))");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS uniq_caisse_open_register');
        $this->addSql('ALTER TABLE caisse DROP CONSTRAINT IF EXISTS fk_caisse_cash_register');
        $this->addSql('ALTER TABLE caisse DROP COLUMN IF EXISTS cash_register_id');
        $this->addSql('ALTER TABLE caisse DROP COLUMN IF EXISTS closed_at');
        $this->addSql('DROP TABLE IF EXISTS cash_register');
    }
}
