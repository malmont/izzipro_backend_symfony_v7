<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Caisse, chantier C5 partie 1 (22/10/2026) : clés d'idempotence des écritures de caisse (pos_idempotency_key).
 * Strictement additif. Équivalent : scripts/migrate_all_v2_pos_idempotency.sh. Idempotent.
 */
final class Version20261022100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Caisse C5 : clés d\'idempotence des écritures de caisse (additif)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE IF NOT EXISTS pos_idempotency_key (id SERIAL PRIMARY KEY, idem_key VARCHAR(36) NOT NULL, user_id INT NOT NULL, terminal_id INT DEFAULT NULL, route VARCHAR(80) NOT NULL, body_hash VARCHAR(64) NOT NULL, status VARCHAR(12) NOT NULL, subject_type VARCHAR(20) DEFAULT NULL, subject_id INT DEFAULT NULL, response_status INT DEFAULT NULL, response_body TEXT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, finished_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL)');
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS uniq_pos_idempotency_key ON pos_idempotency_key (idem_key)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_pos_idempotency_created ON pos_idempotency_key (created_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS pos_idempotency_key');
    }
}
