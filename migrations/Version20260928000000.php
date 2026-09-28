<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260928000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Médiathèque de partage : table shared_media (équivalent de scripts/migrate_all_v2_shared_media.sh, idempotent)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE IF NOT EXISTS shared_media (id SERIAL PRIMARY KEY, titre VARCHAR(255) NOT NULL, filename VARCHAR(255) NOT NULL, original_filename VARCHAR(255) DEFAULT NULL, media_type VARCHAR(20) NOT NULL DEFAULT 'document', mime_type VARCHAR(100) DEFAULT NULL, file_size INT DEFAULT NULL, visibility VARCHAR(10) NOT NULL DEFAULT 'public', access_key VARCHAR(64) DEFAULT NULL, expires_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, download_count INT NOT NULL DEFAULT 0, description TEXT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT NOW(), updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL)");
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_shared_media_access_key ON shared_media (access_key)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_shared_media_visibility ON shared_media (visibility)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_shared_media_type ON shared_media (media_type)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_shared_media_created_at ON shared_media (created_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS shared_media');
    }
}
