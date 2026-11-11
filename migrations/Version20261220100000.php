<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Lot LOC4 (11/11/2026, `docs/locations-gestion.md` § 4) : journal des changements de grille de location faits depuis la gestion
 * (`gestion_rental_grid_change` : tarifs avant / après, articles touchés, annulation 24 h). Additive, idempotente ; équivalent
 * `scripts/migrate_all_v2_gestion_grilles_location.sh` pour toutes les bases.
 */
final class Version20261220100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Lot LOC4 : journal des changements de grille de location';
    }

    public function up(Schema $schema): void
    {
        foreach (self::STATEMENTS as $sql) {
            $this->addSql($sql);
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS gestion_rental_grid_change');
    }

    public const STATEMENTS = [
        'CREATE TABLE IF NOT EXISTS gestion_rental_grid_change (id SERIAL NOT NULL, rental_pack_id INT NOT NULL, before_rates JSON NOT NULL, after_rates JSON NOT NULL, product_ids JSON NOT NULL, advice_id INT DEFAULT NULL, created_by VARCHAR(255) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, undone_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, undone_by VARCHAR(255) DEFAULT NULL, PRIMARY KEY(id))',
        'CREATE INDEX IF NOT EXISTS idx_gestion_rental_grid_change_pack ON gestion_rental_grid_change (rental_pack_id)',
    ];
}
