<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Gestion, lot G6 (10/11/2026, `docs/gestion-mise-a-niveau.md`) : devise d'achat par fournisseur. `fournisseur.currency`
 * (ISO 4217, null = devise du site), `commande.currency` (devise figée à la création de la commande, null = devise du site)
 * et `commande.exchange_rate` (taux vers la devise du site, null = 1). Additif, idempotent ; équivalent de
 * `scripts/migrate_all_v2_gestion_devise_achat.sh`.
 */
final class Version20261127100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Gestion G6 : fournisseur.currency, commande.currency et commande.exchange_rate (devise d\'achat, taux vers la devise du site) ; additif';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE fournisseur ADD COLUMN IF NOT EXISTS currency VARCHAR(3) DEFAULT NULL');
        $this->addSql('ALTER TABLE commande ADD COLUMN IF NOT EXISTS currency VARCHAR(3) DEFAULT NULL');
        $this->addSql('ALTER TABLE commande ADD COLUMN IF NOT EXISTS exchange_rate NUMERIC(12, 6) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE commande DROP COLUMN IF EXISTS exchange_rate');
        $this->addSql('ALTER TABLE commande DROP COLUMN IF EXISTS currency');
        $this->addSql('ALTER TABLE fournisseur DROP COLUMN IF EXISTS currency');
    }
}
