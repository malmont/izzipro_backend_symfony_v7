<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261020120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Profils des applications : traductions (app_profile_translation : nom affiché, accroche, à propos, textes légaux par langue), sur le modèle de entreprise_translation (équivalent de scripts/migrate_all_v2_app_profile.sh, idempotent)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE IF NOT EXISTS app_profile_translation (id SERIAL PRIMARY KEY, app_profile_id INT DEFAULT NULL, language VARCHAR(10) NOT NULL, app_name VARCHAR(255) DEFAULT NULL, tagline VARCHAR(255) DEFAULT NULL, apropos TEXT DEFAULT NULL, privacy_policy TEXT DEFAULT NULL, condition_of_use TEXT DEFAULT NULL, legal_notice TEXT DEFAULT NULL, CONSTRAINT fk_app_profile_translation_profile FOREIGN KEY (app_profile_id) REFERENCES app_profile (id) ON DELETE CASCADE)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_app_profile_translation_profile ON app_profile_translation (app_profile_id)');
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS uniq_app_profile_translation_language ON app_profile_translation (app_profile_id, language)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS app_profile_translation');
    }
}
