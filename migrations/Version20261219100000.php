<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Lot LOC3 (11/11/2026, `docs/locations-gestion.md` § 3) : promotions de location par article (`rental_promotion` : pourcentage, fenêtre de
 * jours du magasin en UTC [starts_at, ends_at[, jours de la semaine cochés, lot) et leur journal (`rental_promotion_batch`), lues par le calcul
 * commun des devis (boutique et caisse). Additive, idempotente ; équivalent `scripts/migrate_all_v2_gestion_promotions_location.sh`.
 */
final class Version20261219100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Lot LOC3 : promotions de location par article';
    }

    public function up(Schema $schema): void
    {
        foreach (self::STATEMENTS as $sql) {
            $this->addSql($sql);
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS rental_promotion');
        $this->addSql('DROP TABLE IF EXISTS rental_promotion_batch');
    }

    public const STATEMENTS = [
        'CREATE TABLE IF NOT EXISTS rental_promotion_batch (id SERIAL NOT NULL, percent SMALLINT NOT NULL, start_date DATE NOT NULL, end_date DATE NOT NULL, weekdays JSON NOT NULL, advice_id INT DEFAULT NULL, created_by VARCHAR(255) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, ended_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, ended_by VARCHAR(255) DEFAULT NULL, PRIMARY KEY(id))',
        'CREATE TABLE IF NOT EXISTS rental_promotion (id SERIAL NOT NULL, product_id INT NOT NULL, batch_id INT DEFAULT NULL, percent SMALLINT NOT NULL, start_date DATE NOT NULL, end_date DATE NOT NULL, starts_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, ends_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, weekdays JSON NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, ended_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id))',
        'CREATE INDEX IF NOT EXISTS idx_rental_promotion_product ON rental_promotion (product_id, ends_at)',
        'CREATE INDEX IF NOT EXISTS idx_rental_promotion_batch ON rental_promotion (batch_id)',
    ];
}
