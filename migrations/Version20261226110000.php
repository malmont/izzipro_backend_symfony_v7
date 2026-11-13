<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Mémoires Vivantes, lot MV-AFFECTATION (13/11/2026) : biographe affecté au livre, mv_book.biographer_id (compte de la base
 * commune, effacé si le compte disparaît). Idempotente ; script scripts/migrate_all_v2_book_biographer.sh (toutes les bases,
 * gmasuite et le modèle des tests compris). Aucune affectation automatique des livres existants.
 */
final class Version20261226110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'mv_book.biographer_id : biographe affecté au livre';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mv_book ADD COLUMN IF NOT EXISTS biographer_id INT DEFAULT NULL REFERENCES "user"(id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_mv_book_biographer ON mv_book (biographer_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mv_book DROP COLUMN IF EXISTS biographer_id');
    }
}
