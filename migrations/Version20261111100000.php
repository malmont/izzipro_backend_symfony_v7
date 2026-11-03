<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Mémoires Vivantes, lot 3 (03/11/2026) : suivi de la mise en page PDF en tâche de fond sur mv_book. Additif ; équivalent de scripts/migrate_all_v2_mv_book_pdf_status.sh. */
final class Version20261111100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'mv_book.pdf_status, pdf_requested_at, pdf_generated_at, pdf_page_count, pdf_error (mise en page PDF par le worker pdf) ; additif';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mv_book ADD COLUMN IF NOT EXISTS pdf_status VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE mv_book ADD COLUMN IF NOT EXISTS pdf_requested_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE mv_book ADD COLUMN IF NOT EXISTS pdf_generated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE mv_book ADD COLUMN IF NOT EXISTS pdf_page_count INT DEFAULT NULL');
        $this->addSql('ALTER TABLE mv_book ADD COLUMN IF NOT EXISTS pdf_error TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        foreach (['pdf_status', 'pdf_requested_at', 'pdf_generated_at', 'pdf_page_count', 'pdf_error'] as $column) {
            $this->addSql("ALTER TABLE mv_book DROP COLUMN IF EXISTS $column");
        }
    }
}
