<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Application de gestion, lot G1a (11/11/2026, `docs/guide-ia-gestion.md`) : conseils du guide (`gestion_advice`, un par signature et par
 * période, statut porté par le site) et générations (`gestion_advice_run`, une automatique par jour et par période, relances manuelles).
 * Additive, idempotente ; équivalent `scripts/migrate_all_v2_gestion_conseils.sh` pour toutes les bases.
 */
final class Version20261209100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Gestion, lot G1a : conseils du guide IA et générations';
    }

    public function up(Schema $schema): void
    {
        foreach (self::STATEMENTS as $sql) {
            $this->addSql($sql);
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS gestion_advice_run');
        $this->addSql('DROP TABLE IF EXISTS gestion_advice');
    }

    public const STATEMENTS = [
        'CREATE TABLE IF NOT EXISTS gestion_advice (id SERIAL NOT NULL, period_id INT NOT NULL, signature VARCHAR(120) NOT NULL, moment VARCHAR(12) NOT NULL, kind VARCHAR(30) NOT NULL, subject JSON NOT NULL, action JSON NOT NULL, effect_cents BIGINT NOT NULL, effect_label VARCHAR(40) NOT NULL, confidence VARCHAR(6) NOT NULL, sources JSON NOT NULL, figures JSON NOT NULL, params JSON NOT NULL, ai_title VARCHAR(255) DEFAULT NULL, ai_text TEXT DEFAULT NULL, ai_locale VARCHAR(5) DEFAULT NULL, rank INT NOT NULL DEFAULT 0, active BOOLEAN NOT NULL DEFAULT TRUE, status VARCHAR(10) NOT NULL DEFAULT \'new\', status_by VARCHAR(255) DEFAULT NULL, status_note TEXT DEFAULT NULL, done_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, dismissed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, detected_on DATE NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id), CONSTRAINT fk_gestion_advice_period FOREIGN KEY (period_id) REFERENCES collections (id) ON DELETE CASCADE)',
        'CREATE UNIQUE INDEX IF NOT EXISTS uniq_gestion_advice_period_signature ON gestion_advice (period_id, signature)',
        'CREATE TABLE IF NOT EXISTS gestion_advice_run (id SERIAL NOT NULL, period_id INT NOT NULL, store_day DATE NOT NULL, run_trigger VARCHAR(10) NOT NULL, status VARCHAR(20) NOT NULL, headline TEXT DEFAULT NULL, headline_locale VARCHAR(5) DEFAULT NULL, ai_usage_id INT DEFAULT NULL, rejected INT NOT NULL DEFAULT 0, created_by VARCHAR(255) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, finished_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id), CONSTRAINT fk_gestion_advice_run_period FOREIGN KEY (period_id) REFERENCES collections (id) ON DELETE CASCADE)',
        'CREATE INDEX IF NOT EXISTS idx_gestion_advice_run_period_day ON gestion_advice_run (period_id, store_day)',
    ];
}
