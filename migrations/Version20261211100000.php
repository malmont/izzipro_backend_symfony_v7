<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Application de gestion, lot G1b-2 (11/11/2026, `docs/guide-ia-gestion.md` § 9) : commentaires du simulateur par le guide
 * (`gestion_simulation_comment`, un par période, corps et jour : même demande le même jour = même commentaire, sans nouveau crédit).
 * Additive, idempotente ; équivalent `scripts/migrate_all_v2_gestion_simulation_commentaire.sh` pour toutes les bases.
 */
final class Version20261211100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Gestion, lot G1b-2 : commentaires du simulateur';
    }

    public function up(Schema $schema): void
    {
        foreach (self::STATEMENTS as $sql) {
            $this->addSql($sql);
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS gestion_simulation_comment');
    }

    public const STATEMENTS = [
        'CREATE TABLE IF NOT EXISTS gestion_simulation_comment (id SERIAL NOT NULL, period_id INT NOT NULL, body_hash VARCHAR(64) NOT NULL, store_day DATE NOT NULL, response JSON NOT NULL, ai_status VARCHAR(20) NOT NULL, ai_usage_id INT DEFAULT NULL, created_by VARCHAR(255) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id), CONSTRAINT fk_gestion_simulation_comment_period FOREIGN KEY (period_id) REFERENCES collections (id) ON DELETE CASCADE)',
        'CREATE UNIQUE INDEX IF NOT EXISTS uniq_gestion_simulation_comment ON gestion_simulation_comment (period_id, body_hash, store_day)',
        'CREATE INDEX IF NOT EXISTS idx_gestion_simulation_comment_day ON gestion_simulation_comment (store_day)',
    ];
}
