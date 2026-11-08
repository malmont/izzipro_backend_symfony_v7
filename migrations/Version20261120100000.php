<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Location « prise et retour avec date et heure » (08/11/2026) : tolérance de retard et pas des heures sur booking_configuration. Idempotente ; script scripts/migrate_all_v2_booking_datetime.sh. */
final class Version20261120100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'booking_configuration.return_tolerance_minutes et time_step_minutes (grille datetime)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE booking_configuration ADD COLUMN IF NOT EXISTS return_tolerance_minutes INT DEFAULT NULL');
        $this->addSql('ALTER TABLE booking_configuration ADD COLUMN IF NOT EXISTS time_step_minutes INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE booking_configuration DROP COLUMN IF EXISTS return_tolerance_minutes');
        $this->addSql('ALTER TABLE booking_configuration DROP COLUMN IF EXISTS time_step_minutes');
    }
}
