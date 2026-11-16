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
        // l'heure d'ouverture de la boutique, posée avec la colonne et UNE FOIS : une boutique déjà active sur un site existant est
        // tenue pour « déjà ouverte ». Rejouée plus tard, cette instruction ne touche plus rien : sur un site en service, une
        // boutique active sans heure d'ouverture est une boutique EN CONSTRUCTION (`StorefrontService::closureOf`), à ne pas ouvrir.
        "DO \$\$ BEGIN IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = 'entreprise' AND column_name = 'storefront_opened_at') THEN"
        . ' ALTER TABLE entreprise ADD COLUMN storefront_opened_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL;'
        . " UPDATE entreprise SET storefront_opened_at = (NOW() AT TIME ZONE 'UTC') WHERE is_boutique_active; END IF; END \$\$",
        'ALTER TABLE entreprise ADD COLUMN IF NOT EXISTS storefront_preview_hash VARCHAR(64) DEFAULT NULL',
        'ALTER TABLE entreprise ADD COLUMN IF NOT EXISTS storefront_preview_expires_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL',
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
