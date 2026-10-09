<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261009130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Boutique réglable : abonnements (tables subscription_plan et subscription, order.subscription_id, user.stripe_customer_id ; équivalent de scripts/migrate_all_v2_subscriptions.sh, idempotent)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE IF NOT EXISTS subscription_plan (
            id SERIAL PRIMARY KEY,
            product_id INT NOT NULL REFERENCES product (id) ON DELETE CASCADE,
            names JSON NOT NULL,
            interval VARCHAR(10) NOT NULL,
            interval_count INT NOT NULL DEFAULT 1,
            price INT NOT NULL,
            currency VARCHAR(3) NOT NULL DEFAULT \'CAD\',
            trial_days INT NOT NULL DEFAULT 0,
            minimum_terms INT NOT NULL DEFAULT 0,
            stripe_product_id VARCHAR(64) DEFAULT NULL,
            stripe_price_id VARCHAR(64) DEFAULT NULL,
            active BOOLEAN NOT NULL DEFAULT TRUE,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL
        )');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_subscription_plan_product ON subscription_plan (product_id)');
        $this->addSql('CREATE TABLE IF NOT EXISTS subscription (
            id SERIAL PRIMARY KEY,
            user_id INT NOT NULL REFERENCES "user" (id) ON DELETE CASCADE,
            plan_id INT NOT NULL REFERENCES subscription_plan (id),
            quantity INT NOT NULL DEFAULT 1,
            status VARCHAR(20) NOT NULL,
            current_period_end TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
            cancel_at_period_end BOOLEAN NOT NULL DEFAULT FALSE,
            address_id INT DEFAULT NULL REFERENCES adress (id) ON DELETE SET NULL,
            carrier_id INT DEFAULT NULL REFERENCES carrier (id) ON DELETE SET NULL,
            stripe_subscription_id VARCHAR(64) DEFAULT NULL,
            stripe_customer_id VARCHAR(64) DEFAULT NULL,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            canceled_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
            paused_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL
        )');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_subscription_user ON subscription (user_id)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_subscription_stripe ON subscription (stripe_subscription_id)');
        $this->addSql('ALTER TABLE IF EXISTS "order" ADD COLUMN IF NOT EXISTS subscription_id INT DEFAULT NULL REFERENCES subscription (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE IF EXISTS "order" ADD COLUMN IF NOT EXISTS stripe_invoice_id VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE IF EXISTS "user" ADD COLUMN IF NOT EXISTS stripe_customer_id VARCHAR(64) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE IF EXISTS "order" DROP COLUMN IF EXISTS subscription_id');
        $this->addSql('ALTER TABLE IF EXISTS "order" DROP COLUMN IF EXISTS stripe_invoice_id');
        $this->addSql('ALTER TABLE IF EXISTS "user" DROP COLUMN IF EXISTS stripe_customer_id');
        $this->addSql('DROP TABLE IF EXISTS subscription');
        $this->addSql('DROP TABLE IF EXISTS subscription_plan');
    }
}
