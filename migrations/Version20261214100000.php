<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Lot P5b (11/11/2026, `docs/promotions-gestion.md` § 7) : journal de la remise en UTC des dates de promotion saisies avant la correction
 * (`gestion_promotion_tz_fix` : anciennes et nouvelles valeurs, fuseau du magasin), rempli par `app:gestion:promotions:fuseau`. Additive,
 * idempotente ; équivalent `scripts/migrate_all_v2_gestion_promotions_fuseau.sh` pour toutes les bases.
 */
final class Version20261214100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Lot P5b : journal de la remise en UTC des dates de promotion';
    }

    public function up(Schema $schema): void
    {
        foreach (self::STATEMENTS as $sql) {
            $this->addSql($sql);
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS gestion_promotion_tz_fix');
    }

    public const STATEMENTS = [
        'CREATE TABLE IF NOT EXISTS gestion_promotion_tz_fix (id SERIAL NOT NULL, product_id INT NOT NULL, old_from TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, old_to TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, new_from TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, new_to TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, timezone VARCHAR(64) NOT NULL, applied_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))',
        'CREATE INDEX IF NOT EXISTS idx_gestion_promotion_tz_fix_product ON gestion_promotion_tz_fix (product_id)',
    ];
}
