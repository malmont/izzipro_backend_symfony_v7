<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261009190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Paiements de panier en cours (checkout_session) : commande idempotente et créée par le webhook si le navigateur ne l\'a pas fait ; équivalent de scripts/migrate_all_v2_checkout_sessions.sh, idempotent';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE IF NOT EXISTS checkout_session (
    id SERIAL PRIMARY KEY,
    payment_intent_id VARCHAR(64) NOT NULL,
    status VARCHAR(12) NOT NULL DEFAULT \'open\',
    user_id INT DEFAULT NULL REFERENCES "user" (id) ON DELETE SET NULL,
    cart JSON NOT NULL,
    order_data JSON DEFAULT NULL,
    amount INT NOT NULL,
    currency VARCHAR(3) NOT NULL,
    locale VARCHAR(5) NOT NULL DEFAULT \'fr\',
    host VARCHAR(255) DEFAULT NULL,
    order_id INT DEFAULT NULL REFERENCES "order" (id) ON DELETE SET NULL,
    last_error VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
    updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL
)');
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS uniq_checkout_payment_intent ON checkout_session (payment_intent_id)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_checkout_session_user ON checkout_session (user_id)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_checkout_session_order ON checkout_session (order_id)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_payments_stripe_payment_id ON payments (stripe_payment_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS checkout_session');
    }
}
