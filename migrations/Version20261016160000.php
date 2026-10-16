<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261016160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Mémoires Vivantes : rédaction payante par chapitre (mv_chapter_payment, colonnes de paiement sur mv_chapter), tarifs par site (mv_pricing_setting), reprises par jour (mv_ai_quota_setting), facturation des impressions de l\'application (mv_book_print_order) (équivalent de scripts/migrate_all_v2_mv_paid_chapters.sh, idempotent)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE IF NOT EXISTS mv_chapter_payment (id SERIAL PRIMARY KEY, user_id INT NOT NULL, book_id UUID DEFAULT NULL, chapter_id UUID DEFAULT NULL, store VARCHAR(20) NOT NULL, product_id VARCHAR(100) NOT NULL, transaction_id VARCHAR(191) NOT NULL, amount NUMERIC(10, 2) DEFAULT NULL, currency VARCHAR(3) DEFAULT NULL, status VARCHAR(20) NOT NULL DEFAULT 'paid', purchased_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, assigned_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, refunded_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, refunded_amount NUMERIC(10, 2) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT NOW())");
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS uniq_mv_chapter_payment_tx ON mv_chapter_payment (store, transaction_id)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_mv_chapter_payment_user ON mv_chapter_payment (user_id)');
        $this->addSql("CREATE TABLE IF NOT EXISTS mv_pricing_setting (id SERIAL PRIMARY KEY, print_grid JSON NOT NULL, chapter_product VARCHAR(100) NOT NULL DEFAULT 'lm_chapter_writing', updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT NOW())");
        $this->addSql('ALTER TABLE mv_ai_quota_setting ADD COLUMN IF NOT EXISTS regenerations_per_day INT NOT NULL DEFAULT 3');
        $this->addSql("DO \$\$ BEGIN IF EXISTS (SELECT FROM information_schema.tables WHERE table_name = 'mv_chapter') THEN ALTER TABLE mv_chapter ADD COLUMN IF NOT EXISTS paid_amount NUMERIC(10, 2) DEFAULT NULL, ADD COLUMN IF NOT EXISTS paid_currency VARCHAR(3) DEFAULT NULL, ADD COLUMN IF NOT EXISTS paid_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, ADD COLUMN IF NOT EXISTS payment_ref VARCHAR(191) DEFAULT NULL, ADD COLUMN IF NOT EXISTS hidden_after_refund BOOLEAN NOT NULL DEFAULT FALSE; END IF; END \$\$;");
        $this->addSql("DO \$\$ BEGIN IF EXISTS (SELECT FROM information_schema.tables WHERE table_name = 'mv_book_print_order') THEN ALTER TABLE mv_book_print_order ADD COLUMN IF NOT EXISTS charged_amount NUMERIC(10, 2) DEFAULT NULL, ADD COLUMN IF NOT EXISTS charged_currency VARCHAR(3) DEFAULT NULL, ADD COLUMN IF NOT EXISTS payment_status VARCHAR(20) DEFAULT NULL, ADD COLUMN IF NOT EXISTS stripe_payment_intent_id VARCHAR(255) DEFAULT NULL; CREATE UNIQUE INDEX IF NOT EXISTS uniq_mv_print_order_intent ON mv_book_print_order (stripe_payment_intent_id) WHERE stripe_payment_intent_id IS NOT NULL; END IF; END \$\$;");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS mv_chapter_payment');
        $this->addSql('DROP TABLE IF EXISTS mv_pricing_setting');
        $this->addSql('ALTER TABLE mv_ai_quota_setting DROP COLUMN IF EXISTS regenerations_per_day');
        $this->addSql('ALTER TABLE mv_chapter DROP COLUMN IF EXISTS paid_amount, DROP COLUMN IF EXISTS paid_currency, DROP COLUMN IF EXISTS paid_at, DROP COLUMN IF EXISTS payment_ref, DROP COLUMN IF EXISTS hidden_after_refund');
        $this->addSql('ALTER TABLE mv_book_print_order DROP COLUMN IF EXISTS charged_amount, DROP COLUMN IF EXISTS charged_currency, DROP COLUMN IF EXISTS payment_status, DROP COLUMN IF EXISTS stripe_payment_intent_id');
    }
}
