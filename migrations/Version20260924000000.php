<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260924000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add biographer_id to reservation with FK, index and partial unique constraint';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE reservation ADD COLUMN IF NOT EXISTS biographer_id INT DEFAULT NULL');
        $this->addSql('DO $$ BEGIN IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = \'fk_reservation_biographer\') THEN ALTER TABLE reservation ADD CONSTRAINT fk_reservation_biographer FOREIGN KEY (biographer_id) REFERENCES "user" (id) ON DELETE SET NULL; END IF; END $$');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_reservation_biographer ON reservation (biographer_id)');
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS uniq_biographer_active_slot ON reservation (biographer_id, reservation_date, reservation_slot) WHERE status != \'cancelled\' AND biographer_id IS NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS uniq_biographer_active_slot');
        $this->addSql('DROP INDEX IF EXISTS idx_reservation_biographer');
        $this->addSql('ALTER TABLE reservation DROP CONSTRAINT IF EXISTS fk_reservation_biographer');
        $this->addSql('ALTER TABLE reservation DROP COLUMN IF EXISTS biographer_id');
    }
}
