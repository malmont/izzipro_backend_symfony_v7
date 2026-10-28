<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Formulaires à étapes, lot 1 (28/10/2026) : demandes reçues (form_submission), destinataire et durée de conservation
 * sur la fiche entreprise, familles de prestations (service_family + traductions) et réglages des prestations (famille,
 * ordre, réservable, durée). Additif et idempotent ; les prestations existantes restent réservables (bookable vrai).
 * Équivalent : scripts/migrate_all_v2_form_submissions.sh.
 */
final class Version20261105100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Formulaires à étapes : form_submission, entreprise.form_submissions_*, service_family, service_offer famille/ordre/réservable/durée (additif)';
    }

    public function up(Schema $schema): void
    {
        foreach (self::statements() as $sql) {
            $this->addSql($sql);
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE IF EXISTS service_offer DROP CONSTRAINT IF EXISTS fk_service_offer_family');
        $this->addSql('ALTER TABLE IF EXISTS service_offer DROP COLUMN IF EXISTS family_id, DROP COLUMN IF EXISTS position, DROP COLUMN IF EXISTS bookable, DROP COLUMN IF EXISTS duration_minutes');
        $this->addSql('DROP TABLE IF EXISTS service_family_translation');
        $this->addSql('DROP TABLE IF EXISTS service_family');
        $this->addSql('ALTER TABLE IF EXISTS entreprise DROP COLUMN IF EXISTS form_submissions_email, DROP COLUMN IF EXISTS form_submissions_retention_months');
        $this->addSql('DROP TABLE IF EXISTS form_submission');
    }

    /** @return list<string> mêmes requêtes que le script (une base sans entreprise ni service_offer n'est pas en erreur) */
    public static function statements(): array
    {
        return [
            'CREATE TABLE IF NOT EXISTS form_submission (id SERIAL PRIMARY KEY, reference VARCHAR(32) NOT NULL, form_name VARCHAR(255) NOT NULL DEFAULT \'\', app VARCHAR(20) NOT NULL, section_uid VARCHAR(100) NOT NULL, form_id VARCHAR(100) NOT NULL, form_hash VARCHAR(64) DEFAULT NULL, answers JSON NOT NULL, locale VARCHAR(5) NOT NULL DEFAULT \'fr\', status VARCHAR(20) NOT NULL DEFAULT \'new\', target VARCHAR(20) NOT NULL DEFAULT \'generic\', reservation_id INT DEFAULT NULL, contact_id INT DEFAULT NULL, notified_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL)',
            'CREATE UNIQUE INDEX IF NOT EXISTS uniq_form_submission_reference ON form_submission (reference)',
            'CREATE INDEX IF NOT EXISTS idx_form_submission_created_at ON form_submission (created_at)',
            'CREATE INDEX IF NOT EXISTS idx_form_submission_form_status ON form_submission (form_id, status)',
            'ALTER TABLE IF EXISTS entreprise ADD COLUMN IF NOT EXISTS form_submissions_email VARCHAR(255) DEFAULT NULL',
            'ALTER TABLE IF EXISTS entreprise ADD COLUMN IF NOT EXISTS form_submissions_retention_months INT DEFAULT NULL',
            'CREATE TABLE IF NOT EXISTS service_family (id SERIAL PRIMARY KEY, name VARCHAR(255) NOT NULL, position INT NOT NULL DEFAULT 0)',
            'CREATE TABLE IF NOT EXISTS service_family_translation (id SERIAL PRIMARY KEY, service_family_id INT NOT NULL REFERENCES service_family(id) ON DELETE CASCADE, language VARCHAR(10) NOT NULL, name VARCHAR(255) NOT NULL)',
            'CREATE INDEX IF NOT EXISTS idx_service_family_translation_family ON service_family_translation (service_family_id)',
            'ALTER TABLE IF EXISTS service_offer ADD COLUMN IF NOT EXISTS family_id INT DEFAULT NULL',
            'ALTER TABLE IF EXISTS service_offer ADD COLUMN IF NOT EXISTS position INT NOT NULL DEFAULT 0',
            'ALTER TABLE IF EXISTS service_offer ADD COLUMN IF NOT EXISTS bookable BOOLEAN NOT NULL DEFAULT TRUE',
            'ALTER TABLE IF EXISTS service_offer ADD COLUMN IF NOT EXISTS duration_minutes INT DEFAULT NULL',
            'DO $$ BEGIN IF to_regclass(\'service_offer\') IS NOT NULL THEN '
                . 'IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = \'fk_service_offer_family\') THEN '
                . 'ALTER TABLE service_offer ADD CONSTRAINT fk_service_offer_family FOREIGN KEY (family_id) REFERENCES service_family(id) ON DELETE SET NULL; END IF; '
                . 'CREATE INDEX IF NOT EXISTS idx_service_offer_family ON service_offer (family_id); END IF; END $$',
        ];
    }
}
