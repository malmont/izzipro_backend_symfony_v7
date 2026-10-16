<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261016180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Mémoires Vivantes : registre des paiements (environnement RevenueCat sur mv_chapter_payment, remboursement Stripe sur mv_book_print_order) (équivalent de scripts/migrate_all_v2_mv_payments_ledger.sh, idempotent)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("DO \$\$ BEGIN IF EXISTS (SELECT FROM information_schema.tables WHERE table_name = 'mv_chapter_payment') THEN ALTER TABLE mv_chapter_payment ADD COLUMN IF NOT EXISTS environment VARCHAR(20) DEFAULT NULL; END IF; END \$\$;");
        $this->addSql("DO \$\$ BEGIN IF EXISTS (SELECT FROM information_schema.tables WHERE table_name = 'mv_book_print_order') THEN ALTER TABLE mv_book_print_order ADD COLUMN IF NOT EXISTS refunded_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, ADD COLUMN IF NOT EXISTS refunded_amount NUMERIC(10, 2) DEFAULT NULL; END IF; END \$\$;");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mv_chapter_payment DROP COLUMN IF EXISTS environment');
        $this->addSql('ALTER TABLE mv_book_print_order DROP COLUMN IF EXISTS refunded_at, DROP COLUMN IF EXISTS refunded_amount');
    }
}
