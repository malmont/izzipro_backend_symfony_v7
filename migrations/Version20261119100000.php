<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Fiche produit par IA (07/11/2026) : origine d'une photo produit (`upload` ou `ai`, images mannequin générées). Additif ; script migrate_all_v2_product_picture_source.sh. */
final class Version20261119100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'product_picture.source ; additif';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE product_picture ADD COLUMN IF NOT EXISTS source VARCHAR(20) NOT NULL DEFAULT 'upload'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE product_picture DROP COLUMN IF EXISTS source');
    }
}
