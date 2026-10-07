<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261007100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Landing pages : bibliothèque de modèles de site (table landing_site_model ; équivalent de scripts/migrate_all_v2_landing_site_models.sh, idempotent)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE IF NOT EXISTS landing_site_model (id SERIAL PRIMARY KEY, name VARCHAR(80) NOT NULL, description VARCHAR(300) DEFAULT NULL, configuration TEXT NOT NULL, tabs_count INT NOT NULL DEFAULT 0, sections_count INT NOT NULL DEFAULT 0, created_by VARCHAR(180) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_landing_site_model_updated_at ON landing_site_model (updated_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS landing_site_model');
    }
}
