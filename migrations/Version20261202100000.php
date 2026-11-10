<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Application de gestion, lot L0 (10/11/2026, `docs/lignes-achat-gestion.md`) : lignes d'achat `commande_line` (un article, ou une
 * variante, acheté dans une commande fournisseur ; plusieurs commandes par article pour les réassorts) et reprise des rattachements
 * existants (`product.commande_id`) en lignes `source = migration`, réputées reçues ; coût inconnu → NULL (jamais le prix de vente).
 * Additive, idempotente ; équivalent `scripts/migrate_all_v2_gestion_lignes.sh` pour toutes les bases. SQL de reprise identique à
 * `App\Services\CommandeService\CommandeLineService::BACKFILL_SQL`.
 */
final class Version20261202100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Gestion, lot L0 : table commande_line et reprise des rattachements existants';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE IF NOT EXISTS commande_line (id SERIAL NOT NULL, commande_id INT NOT NULL, product_id INT NOT NULL, product_variant_id INT DEFAULT NULL, quantity_ordered INT DEFAULT 0 NOT NULL, quantity_received INT DEFAULT 0 NOT NULL, unit_cost_cents INT DEFAULT NULL, estimated BOOLEAN DEFAULT FALSE NOT NULL, attached_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, source VARCHAR(16) DEFAULT 'manual' NOT NULL, created_by VARCHAR(255) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id), CONSTRAINT fk_commande_line_commande FOREIGN KEY (commande_id) REFERENCES commande (id) ON DELETE CASCADE, CONSTRAINT fk_commande_line_product FOREIGN KEY (product_id) REFERENCES product (id) ON DELETE CASCADE, CONSTRAINT fk_commande_line_variant FOREIGN KEY (product_variant_id) REFERENCES product_variant (id) ON DELETE SET NULL)");
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_commande_line_product ON commande_line (product_id)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_commande_line_commande ON commande_line (commande_id)');
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS uniq_commande_line_product ON commande_line (commande_id, product_id) WHERE product_variant_id IS NULL');
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS uniq_commande_line_variant ON commande_line (commande_id, product_id, product_variant_id) WHERE product_variant_id IS NOT NULL');
        $this->addSql(self::BACKFILL_SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS commande_line');
    }

    /** Reprise : une ligne par produit rattaché qui n'en a pas encore dans sa commande (idempotent) */
    public const BACKFILL_SQL = "INSERT INTO commande_line (commande_id, product_id, product_variant_id, quantity_ordered, quantity_received, unit_cost_cents, estimated, attached_at, source, created_by, created_at, updated_at) SELECT p.commande_id, p.id, NULL, COALESCE(p.commande_quantity, p.quantity, 0), COALESCE(p.commande_quantity, p.quantity, 0), CASE WHEN p.commande_cost_cents IS NULL THEN CASE WHEN p.purchase_price IS NOT NULL THEN ROUND(p.purchase_price)::int END WHEN p.purchase_price IS NULL AND p.commande_cost_cents = ROUND(p.price) THEN NULL ELSE p.commande_cost_cents END, COALESCE(p.commande_cost_estimated, FALSE), COALESCE(p.commande_attached_at, c.date, NOW()), 'migration', 'migration L0', NOW(), NOW() FROM product p JOIN commande c ON c.id = p.commande_id WHERE NOT EXISTS (SELECT 1 FROM commande_line l WHERE l.product_id = p.id AND l.commande_id = p.commande_id)";
}
