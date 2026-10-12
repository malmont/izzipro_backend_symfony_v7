<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261012100000 extends AbstractMigration
{
    /** Tables des fiches de contenu d'exemple (voir App\Services\SiteContentService\SiteContentSpec) */
    private const TABLES = ['presentation', 'presentation_group', 'baniere_statique', 'banniere', 'video', 'service_offer', 'marque', 'explore_card', 'categories', 'product', 'home_slider', 'feature', 'product_option'];

    public function getDescription(): string
    {
        return 'Contenu d\'exemple : colonne is_demo sur les fiches de contenu ; équivalent de scripts/migrate_all_v2_site_demo_content.sh, idempotent';
    }

    public function up(Schema $schema): void
    {
        foreach (self::TABLES as $table) {
            $this->addSql(sprintf('ALTER TABLE IF EXISTS %s ADD COLUMN IF NOT EXISTS is_demo BOOLEAN NOT NULL DEFAULT false', $table));
        }
    }

    public function down(Schema $schema): void
    {
        foreach (self::TABLES as $table) {
            $this->addSql(sprintf('ALTER TABLE IF EXISTS %s DROP COLUMN IF EXISTS is_demo', $table));
        }
    }
}
