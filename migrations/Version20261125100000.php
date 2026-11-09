<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Gestion, lot G1 (09/11/2026, `docs/gestion-mise-a-niveau.md`) : montants des achats en cents entiers, comme la boutique et
 * la caisse. Colonnes converties en place (double precision → integer, valeur × 100 arrondie) ; idempotent : une colonne
 * déjà entière n'est pas retouchée. Les colonnes déjà en cents (`product.purchase_price`, `product.price`,
 * `*_statistiques.total_item_cost`, `stock_value`) ne changent pas d'échelle mais passent aussi en entier.
 * Équivalent de `scripts/migrate_all_v2_gestion_cents.sh`.
 */
final class Version20261125100000 extends AbstractMigration
{
    /** table => [colonne => facteur] (100 : unités monétaires → cents ; 1 : déjà en cents) */
    private const COLUMNS = [
        'commande' => ['budget' => 100],
        'collections' => ['budget_collection' => 100],
        'frais_de_port' => ['price' => 100],
        'note_de_frais' => ['montant' => 100],
        'commande_statistiques' => ['general_budget' => 100, 'used_budget' => 100, 'remaining_budget' => 100, 'total_item_cost' => 1, 'total_frais_de_port' => 100, 'stock_value' => 1, 'marge' => 100],
        'collection_statistiques' => ['general_budget' => 100, 'used_budget' => 100, 'remaining_budget' => 100, 'total_item_cost' => 1, 'total_shipping_cost' => 100, 'total_expense_cost' => 100, 'stock_value' => 1, 'margin' => 100],
    ];

    public function getDescription(): string
    {
        return 'Gestion G1 : budgets, frais de port, notes de frais et statistiques figées en cents entiers (conversion en place, idempotente)';
    }

    public function up(Schema $schema): void
    {
        foreach (self::COLUMNS as $table => $columns) {
            foreach ($columns as $column => $factor) {
                $this->addSql(sprintf(
                    "DO $$ BEGIN IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = '%s' AND column_name = '%s' AND data_type = 'double precision') THEN " .
                    'ALTER TABLE "%s" ALTER COLUMN "%s" TYPE INTEGER USING ROUND("%s" * %d)::INTEGER; END IF; END $$;',
                    $table, $column, $table, $column, $column, $factor
                ));
            }
        }
    }

    public function down(Schema $schema): void
    {
        foreach (self::COLUMNS as $table => $columns) {
            foreach ($columns as $column => $factor) {
                $this->addSql(sprintf(
                    "DO $$ BEGIN IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = '%s' AND column_name = '%s' AND data_type = 'integer') THEN " .
                    'ALTER TABLE "%s" ALTER COLUMN "%s" TYPE DOUBLE PRECISION USING "%s"::DOUBLE PRECISION / %d; END IF; END $$;',
                    $table, $column, $table, $column, $column, $factor
                ));
            }
        }
    }
}
