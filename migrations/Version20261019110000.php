<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261019110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Assistant IA : mémoire des échanges (ai_usage.memory_key, summary, outcome) (équivalent de scripts/migrate_all_v2_landing_ai.sh, idempotent)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("DO \$\$ BEGIN IF EXISTS (SELECT FROM information_schema.tables WHERE table_name = 'ai_usage') THEN ALTER TABLE ai_usage ADD COLUMN IF NOT EXISTS memory_key VARCHAR(120) DEFAULT NULL; ALTER TABLE ai_usage ADD COLUMN IF NOT EXISTS summary VARCHAR(1000) DEFAULT NULL; ALTER TABLE ai_usage ADD COLUMN IF NOT EXISTS outcome VARCHAR(10) DEFAULT NULL; CREATE INDEX IF NOT EXISTS idx_ai_usage_memory_key ON ai_usage (memory_key); END IF; END \$\$;");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_ai_usage_memory_key');
        $this->addSql('ALTER TABLE ai_usage DROP COLUMN IF EXISTS memory_key');
        $this->addSql('ALTER TABLE ai_usage DROP COLUMN IF EXISTS summary');
        $this->addSql('ALTER TABLE ai_usage DROP COLUMN IF EXISTS outcome');
    }
}
