<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Gestion, lot G4 (09/11/2026, `docs/gestion-mise-a-niveau.md`) : règles de suppression des clés étrangères des achats.
 * Supprimer une commande fournisseur détache ses produits (`product.commande_id` → SET NULL : les produits de la boutique
 * ne sont plus supprimés) et efface ses statistiques figées et son frais de port (CASCADE) ; supprimer une collection
 * efface ses statistiques figées (CASCADE ; les collections sont par ailleurs supprimées logiquement, `del`).
 * Idempotent : la règle courante est lue dans information_schema avant toute modification. Noms de contraintes Doctrine
 * (empreinte table + colonne, identiques sur toutes les bases). Équivalent de `scripts/migrate_all_v2_gestion_fk.sh`.
 */
final class Version20261126100000 extends AbstractMigration
{
    /** table => [colonne, contrainte, table référencée, règle] */
    private const RULES = [
        ['product', 'commande_id', 'fk_d34a04ad82ea2e54', 'commande', 'SET NULL'],
        ['commande_statistiques', 'commande_id', 'fk_89e6102d82ea2e54', 'commande', 'CASCADE'],
        ['frais_de_port', 'commande_id', 'fk_6fc5088782ea2e54', 'commande', 'CASCADE'],
        ['collection_statistiques', 'collection_id', 'fk_2939a94c514956fd', 'collections', 'CASCADE'],
    ];

    public function getDescription(): string
    {
        return 'Gestion G4 : product.commande_id ON DELETE SET NULL ; statistiques figées et frais de port supprimés avec leur commande / collection (idempotent)';
    }

    public function up(Schema $schema): void
    {
        foreach (self::RULES as [$table, $column, $constraint, $target, $rule]) {
            $this->addSql(sprintf(
                "DO $$ BEGIN IF NOT EXISTS (SELECT 1 FROM information_schema.referential_constraints WHERE constraint_name = '%s' AND delete_rule = '%s') THEN " .
                'ALTER TABLE "%s" DROP CONSTRAINT IF EXISTS %s; ALTER TABLE "%s" ADD CONSTRAINT %s FOREIGN KEY (%s) REFERENCES "%s" (id) ON DELETE %s; END IF; END $$;',
                $constraint, $rule, $table, $constraint, $table, $constraint, $column, $target, $rule
            ));
        }
    }

    public function down(Schema $schema): void
    {
        foreach (self::RULES as [$table, $column, $constraint, $target]) {
            $this->addSql(sprintf('ALTER TABLE "%s" DROP CONSTRAINT IF EXISTS %s; ALTER TABLE "%s" ADD CONSTRAINT %s FOREIGN KEY (%s) REFERENCES "%s" (id)', $table, $constraint, $table, $constraint, $column, $target));
        }
    }
}
