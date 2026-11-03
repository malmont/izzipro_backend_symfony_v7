<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Mémoires Vivantes, lot 3 bis (03/11/2026) : empreinte du contenu imprimé à la dernière génération PDF, pour réutiliser les fichiers à l'impression sans jamais imprimer un PDF périmé. Additif ; équivalent de scripts/migrate_all_v2_mv_book_pdf_hash.sh. */
final class Version20261112100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'mv_book.pdf_source_hash (empreinte du contenu à la dernière génération PDF) ; additif';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mv_book ADD COLUMN IF NOT EXISTS pdf_source_hash VARCHAR(64) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mv_book DROP COLUMN IF EXISTS pdf_source_hash');
    }
}
