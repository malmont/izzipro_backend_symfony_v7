<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Périodes de gestion, lot P2 (10/11/2026, `docs/periodes-gestion.md` § 3) : résultat de période figé (`period_result`) et
 * horodatage du rattachement d'un produit à une commande fournisseur (`product.commande_attached_at`, pour ignorer les
 * rattachements postérieurs à l'échéance d'une période fermée). Additive, idempotente ; équivalent
 * `scripts/migrate_all_v2_gestion_periode_resultat.sh` pour toutes les bases.
 */
final class Version20261129100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Gestion, lot P2 : table period_result, product.commande_attached_at';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE IF NOT EXISTS period_result (id SERIAL NOT NULL, collection_id INT NOT NULL, computed_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, payload JSON NOT NULL, PRIMARY KEY(id), CONSTRAINT fk_period_result_collection FOREIGN KEY (collection_id) REFERENCES collections (id) ON DELETE CASCADE)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_period_result_collection ON period_result (collection_id)');
        $this->addSql('ALTER TABLE product ADD COLUMN IF NOT EXISTS commande_attached_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS period_result');
        $this->addSql('ALTER TABLE product DROP COLUMN IF EXISTS commande_attached_at');
    }
}
