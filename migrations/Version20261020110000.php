<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261020110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Profils des applications (app_profile) : nom, logo, coordonnées et textes propres à une application du site (Living Memory, Mémoires Vivantes, Boussole ESG, POS…), repli sur la fiche entreprise (équivalent de scripts/migrate_all_v2_app_profile.sh, idempotent)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE IF NOT EXISTS app_profile (id SERIAL PRIMARY KEY, app VARCHAR(30) NOT NULL, app_name VARCHAR(255) DEFAULT NULL, tagline VARCHAR(255) DEFAULT NULL, logo VARCHAR(255) DEFAULT NULL, icon VARCHAR(255) DEFAULT NULL, primary_color VARCHAR(7) DEFAULT NULL, email VARCHAR(255) DEFAULT NULL, phone VARCHAR(50) DEFAULT NULL, website VARCHAR(255) DEFAULT NULL, support_url VARCHAR(255) DEFAULT NULL, privacy_policy_url VARCHAR(255) DEFAULT NULL, street1 VARCHAR(255) DEFAULT NULL, street2 VARCHAR(255) DEFAULT NULL, city VARCHAR(100) DEFAULT NULL, zip VARCHAR(20) DEFAULT NULL, province VARCHAR(100) DEFAULT NULL, country VARCHAR(100) DEFAULT NULL, apropos TEXT DEFAULT NULL, privacy_policy TEXT DEFAULT NULL, condition_of_use TEXT DEFAULT NULL, legal_notice TEXT DEFAULT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT NOW())");
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS uniq_app_profile_app ON app_profile (app)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS app_profile');
    }
}
