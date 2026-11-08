<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Invitations par e-mail des chapitres Mémoires Vivantes (08/11/2026) : table mv_chapter_invitation. Idempotente ; script
 * scripts/migrate_all_v2_chapter_invitations.sh (toutes les bases, gmasuite et le modèle des tests compris).
 */
final class Version20261122100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'mv_chapter_invitation : invitations par e-mail des chapitres, renouvelées automatiquement';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS mv_chapter_invitation (
                id UUID NOT NULL PRIMARY KEY,
                chapter_id UUID NOT NULL REFERENCES mv_chapter(id) ON DELETE CASCADE,
                contributor_id UUID DEFAULT NULL REFERENCES mv_contributor(id) ON DELETE CASCADE,
                email VARCHAR(255) NOT NULL,
                locale VARCHAR(5) NOT NULL DEFAULT 'fr',
                status VARCHAR(20) NOT NULL DEFAULT 'active',
                first_sent_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                last_sent_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                renewals_used SMALLINT NOT NULL DEFAULT 0,
                completed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                unsubscribe_token VARCHAR(64) NOT NULL,
                backend_origin VARCHAR(255) NOT NULL DEFAULT '',
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL
            )
            SQL);
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_mv_chapter_invitation_chapter ON mv_chapter_invitation (chapter_id)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_mv_chapter_invitation_contributor ON mv_chapter_invitation (contributor_id)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_mv_chapter_invitation_due ON mv_chapter_invitation (status, expires_at)');
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS uniq_mv_chapter_invitation_token ON mv_chapter_invitation (unsubscribe_token)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS mv_chapter_invitation');
    }
}
