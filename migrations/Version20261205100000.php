<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Application de gestion, vignettes d'accueil (11/11/2026, `docs/vignettes-gestion.md`) : vignettes écartées par utilisateur
 * (`gestion_tile_dismissal`). Additive, idempotente ; équivalent `scripts/migrate_all_v2_gestion_vignettes.sh` pour toutes les bases.
 */
final class Version20261205100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Gestion : vignettes d'accueil écartées par utilisateur";
    }

    public function up(Schema $schema): void
    {
        foreach (self::STATEMENTS as $sql) {
            $this->addSql($sql);
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS gestion_tile_dismissal');
    }

    public const STATEMENTS = [
        'CREATE TABLE IF NOT EXISTS gestion_tile_dismissal (id SERIAL NOT NULL, user_id INT NOT NULL, tile_id VARCHAR(40) NOT NULL, signature VARCHAR(64) DEFAULT NULL, dismissed_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, until TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id))',
        'CREATE UNIQUE INDEX IF NOT EXISTS uniq_gestion_tile_dismissal_user_tile ON gestion_tile_dismissal (user_id, tile_id)',
    ];
}
