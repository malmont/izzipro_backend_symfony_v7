<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Lot CONSTRUCTION (16/11/2026, `docs/boutique-en-construction.md`) : l'état public d'un site, « en construction » ou « ouvert », et
 * son lien d'aperçu (état : open | construction, site neuf entier | boutique, boutique seule). Quatre colonnes sur la fiche du site, lues et écrites en SQL direct (`StorefrontService`), hors de l'entité.
 * Tout site EXISTANT reste « ouvert » (valeur par défaut de la colonne) : rien ne change pour lui. Un site NEUF naît « en
 * construction » : à sa création, `TenantConnectionManager::createTenant` règle la valeur par défaut de SA base sur « construction ».
 * Idempotent. Script équivalent pour toutes les bases : scripts/migrate_all_v2_construction.sh.
 */
final class Version20270115100000 extends AbstractMigration
{
    public const STATEMENTS = [
        "ALTER TABLE entreprise ADD COLUMN IF NOT EXISTS storefront_state VARCHAR(12) NOT NULL DEFAULT 'open'",
        'ALTER TABLE entreprise ADD COLUMN IF NOT EXISTS storefront_opened_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL',
        'ALTER TABLE entreprise ADD COLUMN IF NOT EXISTS storefront_preview_hash VARCHAR(64) DEFAULT NULL',
        'ALTER TABLE entreprise ADD COLUMN IF NOT EXISTS storefront_preview_expires_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL',
        // une boutique déjà active sur un site existant est tenue pour « déjà ouverte » : son module éteint puis rallumé ne la referme pas
        "UPDATE entreprise SET storefront_opened_at = (NOW() AT TIME ZONE 'UTC') WHERE storefront_opened_at IS NULL AND storefront_state = 'open' AND is_boutique_active",
    ];

    public function getDescription(): string
    {
        return 'Lot CONSTRUCTION : état public du site (en construction | ouvert) et lien d\'aperçu, sur la fiche du site';
    }

    public function up(Schema $schema): void
    {
        foreach (self::STATEMENTS as $statement) {
            $this->addSql($statement);
        }
    }

    public function down(Schema $schema): void
    {
        foreach (['storefront_preview_expires_at', 'storefront_preview_hash', 'storefront_opened_at', 'storefront_state'] as $column) {
            $this->addSql("ALTER TABLE entreprise DROP COLUMN IF EXISTS $column");
        }
    }
}
