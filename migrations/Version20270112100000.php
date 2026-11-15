<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Lot IMP-2 (15/11/2026, `docs/import-boutique.md` § 9) : clients d'une boutique importée, avec leur consentement. Une table,
 * `import_customer_invitation` : une ligne par compte « invité par import » (l'import qui l'a créé, son état, l'empreinte du jeton de
 * son lien, les trois dates : repris, invité, accepté, et la date de son effacement s'il n'accepte pas). Additive, idempotente ;
 * aucune donnée existante modifiée. Équivalent `scripts/migrate_all_v2_import_clients.sh` (mêmes 4 instructions).
 */
final class Version20270112100000 extends AbstractMigration
{
    public const STATEMENTS = [
        'CREATE TABLE IF NOT EXISTS import_customer_invitation (id SERIAL PRIMARY KEY, user_id INT NOT NULL UNIQUE REFERENCES "user" (id) ON DELETE CASCADE, job_id INT NOT NULL, state VARCHAR(12) NOT NULL DEFAULT \'imported\', token_hash VARCHAR(64) DEFAULT NULL, locale VARCHAR(5) NOT NULL DEFAULT \'fr\', marketing_claimed BOOLEAN NOT NULL DEFAULT FALSE, imported_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT NOW(), invited_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, expires_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, erase_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, accepted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL)',
        // le jeton d'un lien ne désigne qu'une invitation ; seule son empreinte est gardée
        'CREATE UNIQUE INDEX IF NOT EXISTS uniq_import_invitation_token ON import_customer_invitation (token_hash) WHERE token_hash IS NOT NULL',
        'CREATE INDEX IF NOT EXISTS idx_import_invitation_job ON import_customer_invitation (job_id, state)',
        'CREATE INDEX IF NOT EXISTS idx_import_invitation_erase ON import_customer_invitation (erase_at) WHERE accepted_at IS NULL',
    ];

    public function getDescription(): string
    {
        return 'Lot IMP-2 : clients d\'une boutique importée, invités avec leur consentement (import_customer_invitation)';
    }

    public function up(Schema $schema): void
    {
        foreach (self::STATEMENTS as $sql) {
            $this->addSql($sql);
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS import_customer_invitation');
    }
}
