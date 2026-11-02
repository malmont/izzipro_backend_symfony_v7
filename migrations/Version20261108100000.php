<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * F18 partie 2a (01/11/2026) : catégories fiscales et détail par taux. Strictement additif, aucune donnée touchée ; équivalent de
 * scripts/migrate_all_v2_tax_categories.sh (toutes les bases, gmasuite et le modèle des tests compris).
 */
final class Version20261108100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Catégories fiscales : tax.category, product.tax_category, order_items.tax_category (figée), order_tax.base (HT par taux) ; entreprise.siret et entreprise.prices_include_tax (faux) ; additif';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tax ADD COLUMN IF NOT EXISTS category VARCHAR(16) DEFAULT NULL');
        $this->addSql('ALTER TABLE product ADD COLUMN IF NOT EXISTS tax_category VARCHAR(16) DEFAULT NULL');
        $this->addSql('ALTER TABLE order_items ADD COLUMN IF NOT EXISTS tax_category VARCHAR(16) DEFAULT NULL');
        $this->addSql('ALTER TABLE order_tax ADD COLUMN IF NOT EXISTS base INT DEFAULT NULL');
        $this->addSql('ALTER TABLE entreprise ADD COLUMN IF NOT EXISTS siret VARCHAR(30) DEFAULT NULL');
        $this->addSql('ALTER TABLE entreprise ADD COLUMN IF NOT EXISTS prices_include_tax BOOLEAN DEFAULT false NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tax DROP COLUMN IF EXISTS category');
        $this->addSql('ALTER TABLE product DROP COLUMN IF EXISTS tax_category');
        $this->addSql('ALTER TABLE order_items DROP COLUMN IF EXISTS tax_category');
        $this->addSql('ALTER TABLE order_tax DROP COLUMN IF EXISTS base');
        $this->addSql('ALTER TABLE entreprise DROP COLUMN IF EXISTS siret');
        $this->addSql('ALTER TABLE entreprise DROP COLUMN IF EXISTS prices_include_tax');
    }
}
