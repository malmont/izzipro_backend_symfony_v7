<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Location au comptoir avec caution (09/11/2026) : table pos_rental_deposit et mouvements de caisse 11 « Caution encaissée », 12 « Caution restituée ». Idempotente ; script scripts/migrate_all_v2_pos_rentals.sh. */
final class Version20261123100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'pos_rental_deposit (cautions de location au comptoir) et types de mouvement 11 / 12';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE IF NOT EXISTS pos_rental_deposit (id SERIAL PRIMARY KEY, order_id INT NOT NULL, booking_id INT DEFAULT NULL, amount INT NOT NULL, method VARCHAR(16) NOT NULL, status VARCHAR(16) NOT NULL, collected_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, collected_by_id INT DEFAULT NULL, collected_session_id INT DEFAULT NULL, cash_register_id INT DEFAULT NULL, returned_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, returned_by_id INT DEFAULT NULL, return_session_id INT DEFAULT NULL, return_method VARCHAR(16) DEFAULT NULL, returned_amount INT DEFAULT NULL, retained_amount INT DEFAULT 0 NOT NULL, retained_reason TEXT DEFAULT NULL, note TEXT DEFAULT NULL, terminal_payment_id INT DEFAULT NULL, terminal_refund_id VARCHAR(64) DEFAULT NULL)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_pos_rental_deposit_order ON pos_rental_deposit (order_id)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_pos_rental_deposit_status ON pos_rental_deposit (status)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_pos_rental_deposit_sessions ON pos_rental_deposit (collected_session_id, return_session_id)');
        $this->addSql("INSERT INTO transaction_type (id, name) VALUES (11, 'Caution encaissée'), (12, 'Caution restituée') ON CONFLICT (id) DO NOTHING");
        $this->addSql("SELECT setval(pg_get_serial_sequence('transaction_type', 'id'), GREATEST((SELECT MAX(id) FROM transaction_type), 1))");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS pos_rental_deposit');
        $this->addSql('DELETE FROM transaction_type WHERE id IN (11, 12)');
    }
}
