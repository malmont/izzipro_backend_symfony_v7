<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Retours web, lot 7 (07/11/2026) : retours partiels ou totaux d'une commande web depuis le back-office (lignes et quantités, montant
 * remboursé chez Stripe, remise en stock, motif). Additif ; équivalent de scripts/migrate_all_v2_order_returns.sh.
 */
final class Version20261118100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'table order_return ; additif';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE IF NOT EXISTS order_return (id SERIAL PRIMARY KEY, order_id INT NOT NULL REFERENCES "order"(id) ON DELETE CASCADE, lines JSONB NOT NULL, amount INT NOT NULL,
            restock BOOLEAN NOT NULL DEFAULT TRUE, reason VARCHAR(500) DEFAULT NULL, refunds JSONB DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, created_by VARCHAR(255) DEFAULT NULL)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_order_return_order ON order_return (order_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS order_return');
    }
}
