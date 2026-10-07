<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261007140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Journal des écritures de l\'éditeur des landing pages (table content_audit_log ; équivalent de scripts/migrate_all_v2_content_audit.sh, idempotent)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE IF NOT EXISTS content_audit_log (id SERIAL PRIMARY KEY, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, user_identifier VARCHAR(180) DEFAULT NULL, tenant VARCHAR(64) NOT NULL DEFAULT '', resource VARCHAR(40) NOT NULL, resource_id VARCHAR(40) DEFAULT NULL, action VARCHAR(20) NOT NULL, locale VARCHAR(5) DEFAULT NULL, fields VARCHAR(500) NOT NULL DEFAULT '', before_state TEXT DEFAULT NULL, after_state TEXT DEFAULT NULL, restored_from INT DEFAULT NULL)");
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_content_audit_created_at ON content_audit_log (created_at)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_content_audit_resource ON content_audit_log (resource, resource_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS content_audit_log');
    }
}
