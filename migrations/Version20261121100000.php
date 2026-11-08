<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Charte et brief du site (08/11/2026, « Mon site pas à pas ») : table site_charter, une ligne par site. Idempotente ; script scripts/migrate_all_v2_site_charter.sh. */
final class Version20261121100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'site_charter : brief, charte, style, DESIGN.md, document PDF, dates de sauvegarde et de composition des pages';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE IF NOT EXISTS site_charter (id SERIAL PRIMARY KEY, brief JSON DEFAULT NULL, charter JSON DEFAULT NULL, style JSON DEFAULT NULL, design_md TEXT DEFAULT NULL, document_name VARCHAR(255) DEFAULT NULL, document_path VARCHAR(255) DEFAULT NULL, pages_saved_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, composed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, last_job_id VARCHAR(36) DEFAULT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, updated_by VARCHAR(255) DEFAULT NULL)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS site_charter');
    }
}
