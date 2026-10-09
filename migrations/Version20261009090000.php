<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261009090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Boutique réglable : jeton d\'accès d\'un invité à sa commande (order.guest_token ; équivalent de scripts/migrate_all_v2_order_guest_token.sh, idempotent)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE IF EXISTS "order" ADD COLUMN IF NOT EXISTS guest_token VARCHAR(64) DEFAULT NULL');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_order_guest_token ON "order" (guest_token)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_order_guest_token');
        $this->addSql('ALTER TABLE IF EXISTS "order" DROP COLUMN IF EXISTS guest_token');
    }
}
