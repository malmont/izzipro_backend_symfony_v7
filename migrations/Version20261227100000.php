<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * IA-FACT-1 (13/11/2026, `docs/conversation-gestion.md`) : le fil de conversation de la gestion et ses messages. Un fil mène une facture
 * fournisseur de la pièce jointe à la commande ; un seul fil actif par document (index unique partiel). Les fils et leurs messages
 * disparaissent avec leur document (ON DELETE CASCADE). Additive, idempotente ; équivalent `scripts/migrate_all_v2_gestion_conversations.sh`.
 */
final class Version20261227100000 extends AbstractMigration
{
    public const STATEMENTS = [
        "CREATE TABLE IF NOT EXISTS gestion_conversation (id SERIAL NOT NULL, document_id INT DEFAULT NULL, kind VARCHAR(20) DEFAULT 'invoice' NOT NULL, status VARCHAR(20) DEFAULT 'open' NOT NULL, answers JSON DEFAULT NULL, locale VARCHAR(5) DEFAULT 'fr' NOT NULL, created_by VARCHAR(255) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))",
        "DO $$ BEGIN IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'fk_gestion_conversation_document') THEN ALTER TABLE gestion_conversation ADD CONSTRAINT fk_gestion_conversation_document FOREIGN KEY (document_id) REFERENCES gestion_document (id) ON DELETE CASCADE; END IF; END $$",
        "CREATE UNIQUE INDEX IF NOT EXISTS uniq_gestion_conversation_open_document ON gestion_conversation (document_id) WHERE document_id IS NOT NULL AND status = 'open'",
        'CREATE TABLE IF NOT EXISTS gestion_conversation_message (id SERIAL NOT NULL, conversation_id INT NOT NULL, role VARCHAR(12) NOT NULL, kind VARCHAR(20) NOT NULL, content JSON NOT NULL, created_by VARCHAR(255) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))',
        "DO $$ BEGIN IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'fk_gestion_conversation_message_conversation') THEN ALTER TABLE gestion_conversation_message ADD CONSTRAINT fk_gestion_conversation_message_conversation FOREIGN KEY (conversation_id) REFERENCES gestion_conversation (id) ON DELETE CASCADE; END IF; END $$",
        'CREATE INDEX IF NOT EXISTS idx_gestion_conversation_message_conversation ON gestion_conversation_message (conversation_id, id)',
    ];

    public function getDescription(): string
    {
        return 'IA-FACT-1 : fil de conversation de la gestion (facture par conversation)';
    }

    public function up(Schema $schema): void
    {
        foreach (self::STATEMENTS as $sql) {
            $this->addSql($sql);
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS gestion_conversation_message');
        $this->addSql('DROP TABLE IF EXISTS gestion_conversation');
    }
}
