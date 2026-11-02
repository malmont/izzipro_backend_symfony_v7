<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** F18 partie 2b (02/11/2026) : prix taxes comprises figé sur la commande. Additif ; équivalent de scripts/migrate_all_v2_order_prices_include_tax.sh. */
final class Version20261109100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'order.prices_include_tax (faux) : la commande sait si ses lignes sont TTC (F18 partie 2b) ; additif';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "order" ADD COLUMN IF NOT EXISTS prices_include_tax BOOLEAN DEFAULT false NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "order" DROP COLUMN IF EXISTS prices_include_tax');
    }
}
