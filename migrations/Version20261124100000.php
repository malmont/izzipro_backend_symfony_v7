<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Rattachement de produits existants à une commande fournisseur (09/11/2026, application de gestion) : product.commande_cost_cents, colonne additive nullable. Idempotente ; script scripts/migrate_all_v2_commande_products.sh. */
final class Version20261124100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'product.commande_cost_cents : coût d\'achat retenu pour la commande fournisseur (produits rattachés seulement)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE product ADD COLUMN IF NOT EXISTS commande_cost_cents INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE product DROP COLUMN IF EXISTS commande_cost_cents');
    }
}
