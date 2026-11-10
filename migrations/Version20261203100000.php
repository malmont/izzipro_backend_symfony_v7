<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Application de gestion, lot R1 (10/11/2026, `docs/lignes-achat-gestion.md` § Réception) : type de mouvement de stock 5 « Réception
 * fournisseur » (id fixe : l'existant cherche les types par id) et lien du mouvement à sa ligne d'achat (`inventory_movements.commande_line_id`).
 * Additive, idempotente ; équivalent `scripts/migrate_all_v2_gestion_reception.sh` pour toutes les bases.
 */
final class Version20261203100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Gestion, lot R1 : type de mouvement 5 « Réception fournisseur », inventory_movements.commande_line_id';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("INSERT INTO movement_type (id, name, description) VALUES (5, 'Réception fournisseur', 'Réception d''une ligne d''achat (application de gestion)') ON CONFLICT (id) DO NOTHING");
        $this->addSql("SELECT setval(pg_get_serial_sequence('movement_type', 'id'), GREATEST((SELECT MAX(id) FROM movement_type), 1))");
        $this->addSql('ALTER TABLE inventory_movements ADD COLUMN IF NOT EXISTS commande_line_id INT DEFAULT NULL');
        $this->addSql("DO $$ BEGIN IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'fk_inventory_movements_commande_line') THEN ALTER TABLE inventory_movements ADD CONSTRAINT fk_inventory_movements_commande_line FOREIGN KEY (commande_line_id) REFERENCES commande_line (id) ON DELETE SET NULL; END IF; END $$");
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_inventory_movements_commande_line ON inventory_movements (commande_line_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE inventory_movements DROP COLUMN IF EXISTS commande_line_id');
    }
}
