<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261002010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Assistant IA des landing pages : jetons écrits dans le cache (ai_usage.cache_write_tokens), pour le coût estimé de l\'historique (équivalent de scripts/migrate_all_v2_landing_ai.sh, idempotent)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("DO \$\$ BEGIN IF EXISTS (SELECT FROM information_schema.tables WHERE table_name = 'ai_usage') THEN ALTER TABLE ai_usage ADD COLUMN IF NOT EXISTS cache_write_tokens INT NOT NULL DEFAULT 0; END IF; END \$\$;");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ai_usage DROP COLUMN IF EXISTS cache_write_tokens');
    }
}
