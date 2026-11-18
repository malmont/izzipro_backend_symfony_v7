<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Lot AIDE-APP (18/11/2026, `docs/assistance.md` § « Aide et support des applications ») : une demande d'Assistance envoyée depuis une
 * application du client (Living Memory, commerce mobile).
 * - `support_ticket.source_app` : l'application d'origine, lue dans le jeton (`livingmemory`, `boutique`), vide pour les autres demandes ;
 * - `support_ticket.category` : la catégorie choisie par le client (7 valeurs fixes), vide pour les autres demandes ;
 * - `support_ticket.customer_read_at` : la dernière lecture de la demande par le client (le point « réponse non lue »).
 * Index pour « mes demandes » d'un client. Additive, idempotente, sans rien changer aux demandes existantes ; équivalent
 * `scripts/migrate_all_v2_support_app.sh`.
 */
final class Version20270201100000 extends AbstractMigration
{
    public const STATEMENTS = [
        'ALTER TABLE support_ticket ADD COLUMN IF NOT EXISTS source_app VARCHAR(20) DEFAULT NULL',
        'ALTER TABLE support_ticket ADD COLUMN IF NOT EXISTS category VARCHAR(20) DEFAULT NULL',
        'ALTER TABLE support_ticket ADD COLUMN IF NOT EXISTS customer_read_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL',
        'CREATE INDEX IF NOT EXISTS idx_support_ticket_customer ON support_ticket (customer_user_id, created_at)',
    ];

    public const DOWN_STATEMENTS = [
        'DROP INDEX IF EXISTS idx_support_ticket_customer',
        'ALTER TABLE support_ticket DROP COLUMN IF EXISTS customer_read_at',
        'ALTER TABLE support_ticket DROP COLUMN IF EXISTS category',
        'ALTER TABLE support_ticket DROP COLUMN IF EXISTS source_app',
    ];

    public function getDescription(): string
    {
        return 'AIDE-APP : application d\'origine, catégorie et dernière lecture du client sur les demandes d\'Assistance';
    }

    public function up(Schema $schema): void
    {
        foreach (self::STATEMENTS as $sql) {
            $this->addSql($sql);
        }
    }

    public function down(Schema $schema): void
    {
        foreach (self::DOWN_STATEMENTS as $sql) {
            $this->addSql($sql);
        }
    }
}
