<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Lot France (02/11/2026) : mode d'impression du ticket (auto | on_demand ; vide = selon le pays du magasin). Additif ; équivalent de scripts/migrate_all_v2_receipt_print_mode.sh. */
final class Version20261110100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'entreprise.pos_receipt_print_mode (auto | on_demand, vide = selon le pays : loi AGEC en France) ; additif';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE entreprise ADD COLUMN IF NOT EXISTS pos_receipt_print_mode VARCHAR(16) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE entreprise DROP COLUMN IF EXISTS pos_receipt_print_mode');
    }
}
