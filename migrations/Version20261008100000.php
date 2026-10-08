<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261008100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Boutique réglable : réglages publiés (table boutique_setting) et application des modèles de site (landing_site_model.app ; équivalent de scripts/migrate_all_v2_boutique_settings.sh, idempotent)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE IF NOT EXISTS boutique_setting (id SERIAL PRIMARY KEY, configuration JSON NOT NULL)');
        $this->addSql("ALTER TABLE IF EXISTS landing_site_model ADD COLUMN IF NOT EXISTS app VARCHAR(20) NOT NULL DEFAULT 'landingpage'");
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_landing_site_model_app ON landing_site_model (app)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS boutique_setting');
        $this->addSql('DROP INDEX IF EXISTS idx_landing_site_model_app');
        $this->addSql('ALTER TABLE IF EXISTS landing_site_model DROP COLUMN IF EXISTS app');
    }
}
