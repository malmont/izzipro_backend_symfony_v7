<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261016120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Mémoires Vivantes : quotas IA par site (mv_ai_quota_setting : réglages, une ligne ; mv_ai_usage : appels aboutis par utilisateur) (équivalent de scripts/migrate_all_v2_mv_ai_quotas.sh, idempotent)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE IF NOT EXISTS mv_ai_quota_setting (id SERIAL PRIMARY KEY, transcriptions_per_day INT NOT NULL DEFAULT 40, improvements_per_day INT NOT NULL DEFAULT 60, max_audio_seconds INT NOT NULL DEFAULT 300, apply_to_web_books BOOLEAN NOT NULL DEFAULT FALSE, timezone VARCHAR(64) NOT NULL DEFAULT 'America/Martinique', updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT NOW())");
        $this->addSql("CREATE TABLE IF NOT EXISTS mv_ai_usage (id SERIAL PRIMARY KEY, user_id INT NOT NULL, kind VARCHAR(20) NOT NULL, book_id UUID DEFAULT NULL, chapter_id UUID DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT NOW())");
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_mv_ai_usage_user_kind_date ON mv_ai_usage (user_id, kind, created_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS mv_ai_usage');
        $this->addSql('DROP TABLE IF EXISTS mv_ai_quota_setting');
    }
}
