<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260929150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Assistant IA : tâches de fond (ai_job) et échéance des réservations (ai_usage.reserved_until) — équivalent de scripts/migrate_all_v2_landing_ai.sh, idempotent';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ai_usage ADD COLUMN IF NOT EXISTS reserved_until TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql("UPDATE ai_usage SET reserved_until = created_at + INTERVAL '5 minutes' WHERE reserved_until IS NULL");
        $this->addSql('CREATE TABLE IF NOT EXISTS ai_job (id VARCHAR(36) PRIMARY KEY, usage_id INT NOT NULL, user_identifier VARCHAR(180) DEFAULT NULL, status VARCHAR(10) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, started_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, finished_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, input TEXT DEFAULT NULL, result TEXT DEFAULT NULL, error TEXT DEFAULT NULL)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_ai_job_status ON ai_job (status)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS ai_job');
        $this->addSql('ALTER TABLE ai_usage DROP COLUMN IF EXISTS reserved_until');
    }
}
