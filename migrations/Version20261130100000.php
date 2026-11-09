<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Périodes de gestion, lot P3 (10/11/2026, `docs/periodes-gestion.md` § 4) : reprise d'historique. `commande.kind`
 * (`standard` | `reprise`), `product.commande_quantity` (quantité de la ligne de commande fournisseur, distincte du stock),
 * `product.commande_cost_estimated` (coût estimé par coefficient), table `gestion_reprise` (une reprise active par site).
 * Additive, idempotente ; équivalent `scripts/migrate_all_v2_gestion_reprise.sh` pour toutes les bases.
 */
final class Version20261130100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Gestion, lot P3 : commande.kind, product.commande_quantity / commande_cost_estimated, table gestion_reprise';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE commande ADD COLUMN IF NOT EXISTS kind VARCHAR(16) DEFAULT 'standard' NOT NULL");
        $this->addSql('ALTER TABLE product ADD COLUMN IF NOT EXISTS commande_quantity INT DEFAULT NULL');
        $this->addSql('ALTER TABLE product ADD COLUMN IF NOT EXISTS commande_cost_estimated BOOLEAN DEFAULT FALSE NOT NULL');
        $this->addSql('CREATE TABLE IF NOT EXISTS gestion_reprise (id SERIAL NOT NULL, applied_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, months INT NOT NULL, start_date DATE NOT NULL, end_date DATE NOT NULL, estimate_coefficient NUMERIC(8, 3) DEFAULT NULL, created_period_ids JSON NOT NULL, summary JSON NOT NULL, PRIMARY KEY(id))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS gestion_reprise');
        $this->addSql('ALTER TABLE product DROP COLUMN IF EXISTS commande_cost_estimated');
        $this->addSql('ALTER TABLE product DROP COLUMN IF EXISTS commande_quantity');
        $this->addSql('ALTER TABLE commande DROP COLUMN IF EXISTS kind');
    }
}
