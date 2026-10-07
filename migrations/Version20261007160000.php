<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261007160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Formulaire de contact : téléphone facultatif, secteur, entreprise et fonction (équivalent de scripts/migrate_all_v2_contact_fields.sh, idempotent)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE IF EXISTS contact ALTER COLUMN phone DROP NOT NULL');
        $this->addSql('ALTER TABLE IF EXISTS contact ADD COLUMN IF NOT EXISTS industry VARCHAR(50) DEFAULT NULL');
        $this->addSql('ALTER TABLE IF EXISTS contact ADD COLUMN IF NOT EXISTS company_name VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE IF EXISTS contact ADD COLUMN IF NOT EXISTS job_function VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE IF EXISTS contact DROP COLUMN IF EXISTS industry');
        $this->addSql('ALTER TABLE IF EXISTS contact DROP COLUMN IF EXISTS company_name');
        $this->addSql('ALTER TABLE IF EXISTS contact DROP COLUMN IF EXISTS job_function');
    }
}
