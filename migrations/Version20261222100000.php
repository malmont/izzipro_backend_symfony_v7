<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Module Assistance, lot 1 (12/11/2026, `docs/assistance.md`) : boîte de demandes du site (tickets, messages, pièces jointes), réglages du
 * délai de réponse et de l'envoi des courriels, interrupteur du module (`entreprise.is_support_active`, droit `module.support`, faux partout).
 * Additive, idempotente ; équivalent `scripts/migrate_all_v2_support.sh`.
 */
final class Version20261222100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Assistance lot 1 : boîte de demandes';
    }

    public function up(Schema $schema): void
    {
        foreach (self::STATEMENTS as $sql) {
            $this->addSql($sql);
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS support_attachment');
        $this->addSql('DROP TABLE IF EXISTS support_message');
        $this->addSql('DROP TABLE IF EXISTS support_ticket');
        $this->addSql('DROP TABLE IF EXISTS support_setting');
        $this->addSql('ALTER TABLE entreprise DROP COLUMN IF EXISTS is_support_active');
    }

    public const STATEMENTS = [
        "CREATE TABLE IF NOT EXISTS support_ticket (id SERIAL NOT NULL, reference VARCHAR(20) NOT NULL, subject VARCHAR(200) NOT NULL, status VARCHAR(20) DEFAULT 'new' NOT NULL, priority VARCHAR(10) DEFAULT 'normal' NOT NULL, channel VARCHAR(10) NOT NULL, customer_user_id INT DEFAULT NULL, customer_name VARCHAR(160) DEFAULT NULL, customer_email VARCHAR(180) DEFAULT NULL, customer_locale VARCHAR(5) DEFAULT 'fr' NOT NULL, order_id INT DEFAULT NULL, cash_register_id INT DEFAULT NULL, assigned_user_id INT DEFAULT NULL, due_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, first_response_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, last_message_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, last_customer_message_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, closed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, anonymized_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_by VARCHAR(180) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))",
        'CREATE UNIQUE INDEX IF NOT EXISTS uniq_support_ticket_reference ON support_ticket (reference)',
        'CREATE INDEX IF NOT EXISTS idx_support_ticket_status ON support_ticket (status, due_at)',
        'CREATE INDEX IF NOT EXISTS idx_support_ticket_order ON support_ticket (order_id)',
        'CREATE TABLE IF NOT EXISTS support_message (id SERIAL NOT NULL, ticket_id INT NOT NULL, author_type VARCHAR(10) NOT NULL, author_user_id INT DEFAULT NULL, author_name VARCHAR(160) DEFAULT NULL, body TEXT NOT NULL, internal BOOLEAN DEFAULT false NOT NULL, email_status VARCHAR(10) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))',
        'CREATE INDEX IF NOT EXISTS idx_support_message_ticket ON support_message (ticket_id, created_at)',
        'CREATE TABLE IF NOT EXISTS support_attachment (id SERIAL NOT NULL, message_id INT NOT NULL, stored_name VARCHAR(80) NOT NULL, original_name VARCHAR(200) NOT NULL, mime VARCHAR(60) NOT NULL, size INT NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))',
        'CREATE INDEX IF NOT EXISTS idx_support_attachment_message ON support_attachment (message_id)',
        "CREATE TABLE IF NOT EXISTS support_setting (id SERIAL NOT NULL, response_hours SMALLINT DEFAULT 48 NOT NULL, business_days JSON NOT NULL, outbound_mode VARCHAR(10) DEFAULT 'log' NOT NULL, saved_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, updated_by VARCHAR(180) DEFAULT NULL, PRIMARY KEY(id))",
        'ALTER TABLE entreprise ADD COLUMN IF NOT EXISTS is_support_active BOOLEAN DEFAULT false NOT NULL',
    ];
}
