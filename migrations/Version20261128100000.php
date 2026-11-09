<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Périodes de gestion, lot P1 (10/11/2026, `docs/periodes-gestion.md`) : `collections.kind` (manual | period), `period_key`
 * (unique parmi les périodes), `reopened_at`, `closed_at` ; `entreprise.purchase_period_frequency` (quarterly | semester |
 * monthly, null = quarterly). Additif, idempotent ; équivalent de `scripts/migrate_all_v2_gestion_periodes.sh`.
 */
final class Version20261128100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Périodes de gestion P1 : collections.kind, period_key (unique), reopened_at, closed_at ; entreprise.purchase_period_frequency ; additif';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE collections ADD COLUMN IF NOT EXISTS kind VARCHAR(16) DEFAULT 'manual' NOT NULL");
        $this->addSql('ALTER TABLE collections ADD COLUMN IF NOT EXISTS period_key VARCHAR(16) DEFAULT NULL');
        $this->addSql('ALTER TABLE collections ADD COLUMN IF NOT EXISTS reopened_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE collections ADD COLUMN IF NOT EXISTS closed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql("CREATE UNIQUE INDEX IF NOT EXISTS uniq_collections_period_key ON collections (period_key) WHERE kind = 'period'");
        $this->addSql('ALTER TABLE entreprise ADD COLUMN IF NOT EXISTS purchase_period_frequency VARCHAR(16) DEFAULT NULL');
        // taux de marge / taux de marque figés avant le lot P1 avec les définitions inversées : échangés une seule fois (condition sur le signe de la marge, idempotent)
        $this->addSql('UPDATE collection_statistiques SET taux_marge = taux_marque, taux_marque = taux_marge WHERE (margin > 0 AND taux_marge < taux_marque) OR (margin < 0 AND taux_marge > taux_marque)');
        $this->addSql('UPDATE commande_statistiques SET taux_marge = taux_marque, taux_marque = taux_marge WHERE taux_marge IS NOT NULL AND taux_marque IS NOT NULL AND ((marge > 0 AND taux_marge < taux_marque) OR (marge < 0 AND taux_marge > taux_marque))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS uniq_collections_period_key');
        $this->addSql('ALTER TABLE collections DROP COLUMN IF EXISTS closed_at');
        $this->addSql('ALTER TABLE collections DROP COLUMN IF EXISTS reopened_at');
        $this->addSql('ALTER TABLE collections DROP COLUMN IF EXISTS period_key');
        $this->addSql('ALTER TABLE collections DROP COLUMN IF EXISTS kind');
        $this->addSql('ALTER TABLE entreprise DROP COLUMN IF EXISTS purchase_period_frequency');
    }
}
