<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261009110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Taxes par région : pays d\'une taxe (tax.country) et transaction fiscale Stripe d\'une commande (order.tax_transaction_id ; équivalent de scripts/migrate_all_v2_tax_regions.sh, idempotent)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE IF EXISTS tax ADD COLUMN IF NOT EXISTS country VARCHAR(2) DEFAULT 'CA'");
        $this->addSql('ALTER TABLE IF EXISTS "order" ADD COLUMN IF NOT EXISTS tax_transaction_id VARCHAR(64) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE IF EXISTS tax DROP COLUMN IF EXISTS country');
        $this->addSql('ALTER TABLE IF EXISTS "order" DROP COLUMN IF EXISTS tax_transaction_id');
    }
}
