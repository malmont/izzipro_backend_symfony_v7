<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Lot ABO-4 (21/11/2026, `docs/boutique.md` § « Liste d'attente, rappels et calendrier ») :
 * - `benefit_waitlist` : la liste d'attente d'un créneau complet (une attente ouverte par créneau et par abonnement, le droit qu'elle porte,
 *   l'offre d'une place libérée et son échéance, la fermeture et son motif) ; le créneau supprimé laisse l'attente, fermée (`left`) ;
 * - `benefit_occurrence.reminded_at` : le rappel de la veille, une fois par date ;
 * - `subscription.reminder_emails` : l'abonné coupe ou rallume le rappel (allumé par défaut) ;
 * - `calendar_feed` : les liens d'agenda `.ics` d'un abonné ou d'un intervenant (jeton gardé haché, jamais en clair ; changé = l'ancien
 *   ne vaut plus).
 * Tables neuves, colonnes vides ou à leur valeur par défaut : aucune donnée existante modifiée. Idempotente ; équivalent
 * `scripts/migrate_all_v2_abo_attente.sh`.
 */
final class Version20270202400000 extends AbstractMigration
{
    public const STATEMENTS = [
        'CREATE TABLE IF NOT EXISTS benefit_waitlist (id SERIAL PRIMARY KEY, slot_id INT DEFAULT NULL REFERENCES benefit_slot (id) ON DELETE SET NULL, subscription_id INT NOT NULL REFERENCES subscription (id) ON DELETE CASCADE, benefit_id INT NOT NULL, occurrence_id INT DEFAULT NULL, status VARCHAR(10) NOT NULL, offered_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, offer_expires_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, offered_by VARCHAR(180) DEFAULT NULL, closed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, closed_reason VARCHAR(20) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL)',
        "CREATE UNIQUE INDEX IF NOT EXISTS uniq_benefit_waitlist_open ON benefit_waitlist (slot_id, subscription_id) WHERE status IN ('waiting', 'offered')",
        'CREATE INDEX IF NOT EXISTS idx_benefit_waitlist_slot ON benefit_waitlist (slot_id, status, id)',
        'CREATE INDEX IF NOT EXISTS idx_benefit_waitlist_subscription ON benefit_waitlist (subscription_id, status)',
        'ALTER TABLE benefit_occurrence ADD COLUMN IF NOT EXISTS reminded_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL',
        'ALTER TABLE subscription ADD COLUMN IF NOT EXISTS reminder_emails BOOLEAN NOT NULL DEFAULT true',
        'CREATE TABLE IF NOT EXISTS calendar_feed (id SERIAL PRIMARY KEY, owner_type VARCHAR(12) NOT NULL, owner_id INT NOT NULL, token_hash VARCHAR(64) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL)',
        'CREATE UNIQUE INDEX IF NOT EXISTS uniq_calendar_feed_token ON calendar_feed (token_hash)',
        'CREATE UNIQUE INDEX IF NOT EXISTS uniq_calendar_feed_owner ON calendar_feed (owner_type, owner_id)',
    ];

    public const DOWN_STATEMENTS = [
        'DROP TABLE IF EXISTS calendar_feed',
        'ALTER TABLE subscription DROP COLUMN IF EXISTS reminder_emails',
        'ALTER TABLE benefit_occurrence DROP COLUMN IF EXISTS reminded_at',
        'DROP TABLE IF EXISTS benefit_waitlist',
    ];

    public function getDescription(): string
    {
        return 'ABO-4 : liste d\'attente, rappel de la veille, liens d\'agenda .ics';
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
