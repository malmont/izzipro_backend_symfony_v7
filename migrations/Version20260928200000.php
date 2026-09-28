<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260928200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Assistant IA des landing pages : tables ai_usage et ai_credit_setting (équivalent de scripts/migrate_all_v2_landing_ai.sh, idempotent)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE IF NOT EXISTS ai_usage (id SERIAL PRIMARY KEY, tenant VARCHAR(64) NOT NULL, user_identifier VARCHAR(180) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, completed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, mode VARCHAR(10) NOT NULL, component_key VARCHAR(64) NOT NULL, status VARCHAR(10) NOT NULL, credits INT NOT NULL, model VARCHAR(64) DEFAULT NULL, attempts INT NOT NULL DEFAULT 0, input_tokens INT NOT NULL DEFAULT 0, output_tokens INT NOT NULL DEFAULT 0, cache_read_tokens INT NOT NULL DEFAULT 0, duration_ms INT DEFAULT NULL, prompt_excerpt VARCHAR(500) NOT NULL DEFAULT '')");
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_ai_usage_created_at ON ai_usage (created_at)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_ai_usage_status ON ai_usage (status)');
        $this->addSql('CREATE TABLE IF NOT EXISTS ai_credit_setting (id SERIAL PRIMARY KEY, monthly_credits INT NOT NULL DEFAULT 100)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS ai_usage');
        $this->addSql('DROP TABLE IF EXISTS ai_credit_setting');
    }
}
