<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Lot G-1a (18/11/2026, `docs/ecrans-et-contextes.md` § 12) : module « Multi-boutiques » (clé `module.multi_shop`), une colonne de la fiche du
 * site comme tout module, fausse par défaut. Additive, idempotente ; équivalent `scripts/migrate_all_v2_multi_boutiques.sh`.
 */
final class Version20270131100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'G-1a : module Multi-boutiques (colonne de la fiche du site)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE entreprise ADD COLUMN IF NOT EXISTS is_multi_shop_active BOOLEAN DEFAULT false NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE entreprise DROP COLUMN IF EXISTS is_multi_shop_active');
    }
}
