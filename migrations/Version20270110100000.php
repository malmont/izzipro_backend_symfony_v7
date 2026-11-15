<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Lot DOC-1 (15/11/2026, `docs/rapports-gestion.md`) : rapports PDF de la gestion. Une table, `gestion_report` : la liste des rapports
 * du site (genre, paramètres, état, fichier, pages, empreinte de la demande, demandeur, dates). Additive, idempotente ; aucune donnée
 * existante modifiée. Équivalent `scripts/migrate_all_v2_rapports.sh` (mêmes 5 instructions).
 */
final class Version20270110100000 extends AbstractMigration
{
    public const STATEMENTS = [
        'CREATE TABLE IF NOT EXISTS gestion_report (id SERIAL PRIMARY KEY, kind VARCHAR(30) NOT NULL, params JSONB NOT NULL, state VARCHAR(12) NOT NULL, title VARCHAR(255) NOT NULL, locale VARCHAR(5) NOT NULL, fingerprint VARCHAR(64) NOT NULL, file_name VARCHAR(120) DEFAULT NULL, size INT DEFAULT NULL, pages INT DEFAULT NULL, error_code VARCHAR(40) DEFAULT NULL, units_charged INT DEFAULT 0 NOT NULL, created_by VARCHAR(180) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NOW() NOT NULL, ready_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL)',
        'CREATE INDEX IF NOT EXISTS idx_gestion_report_created ON gestion_report (created_at)',
        'CREATE INDEX IF NOT EXISTS idx_gestion_report_fingerprint ON gestion_report (fingerprint)',
        // un rapport effacé garde sa ligne jusqu'à la fin de sa conservation : le plafond du jour le compte encore
        'ALTER TABLE gestion_report ADD COLUMN IF NOT EXISTS deleted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL',
        // deux demandes simultanées de la même réponse : une seule préparation
        "CREATE UNIQUE INDEX IF NOT EXISTS uniq_gestion_report_pending ON gestion_report (fingerprint) WHERE state = 'pending'",
    ];

    public function getDescription(): string
    {
        return 'Lot DOC-1 : rapports PDF de la gestion (gestion_report)';
    }

    public function up(Schema $schema): void
    {
        foreach (self::STATEMENTS as $sql) {
            $this->addSql($sql);
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS gestion_report');
    }
}
