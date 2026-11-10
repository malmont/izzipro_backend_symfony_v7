<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Application de gestion, lot L1 (10/11/2026, `docs/lecture-documents-gestion.md`) : lecture de documents par IA (factures fournisseurs,
 * notes de frais, factures de transport). Table `gestion_document` (fichiers privés, extraction, brouillon, confirmation) et
 * justificatif sur `commande`, `note_de_frais`, `frais_de_port` (`document_id`). Additive, idempotente ; équivalent
 * `scripts/migrate_all_v2_gestion_documents.sh` pour toutes les bases.
 */
final class Version20261201100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Gestion, lot L1 : table gestion_document, document_id sur commande / note_de_frais / frais_de_port';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE IF NOT EXISTS gestion_document (id SERIAL NOT NULL, type VARCHAR(16) NOT NULL, status VARCHAR(16) NOT NULL, locale VARCHAR(5) DEFAULT 'fr' NOT NULL, files JSON NOT NULL, extraction JSON DEFAULT NULL, draft JSON DEFAULT NULL, confirmed JSON DEFAULT NULL, error_code VARCHAR(40) DEFAULT NULL, ai_usage_id INT DEFAULT NULL, commande_id INT DEFAULT NULL, note_de_frais_id INT DEFAULT NULL, frais_de_port_id INT DEFAULT NULL, created_by VARCHAR(255) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, read_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, draft_updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, confirmed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, expires_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id))");
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_gestion_document_status ON gestion_document (type, status)');
        foreach (['commande', 'note_de_frais', 'frais_de_port'] as $table) {
            $this->addSql(sprintf('ALTER TABLE %s ADD COLUMN IF NOT EXISTS document_id INT DEFAULT NULL', $table));
            $this->addSql(sprintf("DO $$ BEGIN IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'fk_%s_document') THEN ALTER TABLE %s ADD CONSTRAINT fk_%s_document FOREIGN KEY (document_id) REFERENCES gestion_document (id) ON DELETE SET NULL; END IF; END $$", $table, $table, $table));
        }
    }

    public function down(Schema $schema): void
    {
        foreach (['commande', 'note_de_frais', 'frais_de_port'] as $table) {
            $this->addSql(sprintf('ALTER TABLE %s DROP COLUMN IF EXISTS document_id', $table));
        }
        $this->addSql('DROP TABLE IF EXISTS gestion_document');
    }
}
