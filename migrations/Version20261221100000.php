<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Lot VAR (11/11/2026, `docs/promotions-gestion.md` § 8) : promotions par variante (`variant_promotion` : pourcentage, prix promotionnel
 * figé, prix de départ, fenêtre de jours du magasin en UTC [starts_at, ends_at[, lot) et leur journal (`variant_promotion_batch`) ; ligne de
 * commande `order_items.variant_promotion_id` et prix barré figé `variant_catalog_unit_price` (reçu de caisse) ; interrupteur du site `entreprise.variant_promotions_enabled` (faux partout,
 * allumé par le super admin). Additive, idempotente ; équivalent `scripts/migrate_all_v2_gestion_promotions_variantes.sh`.
 */
final class Version20261221100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Lot VAR : promotions par variante';
    }

    public function up(Schema $schema): void
    {
        foreach (self::STATEMENTS as $sql) {
            $this->addSql($sql);
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS variant_promotion');
        $this->addSql('DROP TABLE IF EXISTS variant_promotion_batch');
        $this->addSql('ALTER TABLE order_items DROP COLUMN IF EXISTS variant_promotion_id');
        $this->addSql('ALTER TABLE order_items DROP COLUMN IF EXISTS variant_catalog_unit_price');
        $this->addSql('ALTER TABLE entreprise DROP COLUMN IF EXISTS variant_promotions_enabled');
    }

    public const STATEMENTS = [
        'CREATE TABLE IF NOT EXISTS variant_promotion_batch (id SERIAL NOT NULL, percent NUMERIC(5, 2) NOT NULL, rounding VARCHAR(16) NOT NULL, start_date DATE NOT NULL, end_date DATE NOT NULL, advice_id INT DEFAULT NULL, created_by VARCHAR(255) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, ended_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, ended_by VARCHAR(255) DEFAULT NULL, PRIMARY KEY(id))',
        'CREATE TABLE IF NOT EXISTS variant_promotion (id SERIAL NOT NULL, product_variant_id INT NOT NULL, batch_id INT DEFAULT NULL, percent NUMERIC(5, 2) NOT NULL, price INT NOT NULL, base_price INT NOT NULL, start_date DATE NOT NULL, end_date DATE NOT NULL, starts_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, ends_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, ended_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id))',
        'CREATE INDEX IF NOT EXISTS idx_variant_promotion_variant ON variant_promotion (product_variant_id, ends_at)',
        'CREATE INDEX IF NOT EXISTS idx_variant_promotion_batch ON variant_promotion (batch_id)',
        'ALTER TABLE order_items ADD COLUMN IF NOT EXISTS variant_promotion_id INT DEFAULT NULL',
        'ALTER TABLE order_items ADD COLUMN IF NOT EXISTS variant_catalog_unit_price INT DEFAULT NULL',
        'ALTER TABLE entreprise ADD COLUMN IF NOT EXISTS variant_promotions_enabled BOOLEAN DEFAULT false NOT NULL',
    ];
}
