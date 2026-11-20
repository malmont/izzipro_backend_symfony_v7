<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Lot ABO-3b (20/11/2026, `docs/boutique.md` § « Intervenant et fil de l'abonné ») :
 * - `benefit_occurrence.staff_user_id` : l'intervenant d'une date (prime sur celui du créneau, puis de la prestation) ;
 * - `benefit.at_customer` : la prestation se rend chez le client (l'intervenant voit alors l'adresse de l'intervention) ;
 * - `subscription_message` : le fil de l'abonné (abonné et équipe), effacé 24 mois après la fin de l'abonnement ;
 * - `subscription.thread_emailed_at` : au plus un courriel « Vous avez un message » par heure et par abonnement.
 * Colonnes vides ou à leur valeur par défaut, table neuve : aucune donnée existante modifiée. Idempotente ; équivalent
 * `scripts/migrate_all_v2_abo_intervenant.sh`.
 */
final class Version20270202200000 extends AbstractMigration
{
    public const STATEMENTS = [
        'ALTER TABLE benefit_occurrence ADD COLUMN IF NOT EXISTS staff_user_id INT DEFAULT NULL',
        'CREATE INDEX IF NOT EXISTS idx_benefit_occurrence_staff ON benefit_occurrence (staff_user_id) WHERE staff_user_id IS NOT NULL',
        'ALTER TABLE benefit ADD COLUMN IF NOT EXISTS at_customer BOOLEAN NOT NULL DEFAULT false',
        'ALTER TABLE subscription ADD COLUMN IF NOT EXISTS thread_emailed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL',
        'CREATE TABLE IF NOT EXISTS subscription_message (id SERIAL PRIMARY KEY, subscription_id INT NOT NULL REFERENCES subscription (id) ON DELETE CASCADE, author VARCHAR(10) NOT NULL, author_user_id INT DEFAULT NULL, author_name VARCHAR(100) DEFAULT NULL, body TEXT NOT NULL, occurrence_id INT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, read_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL)',
        'CREATE INDEX IF NOT EXISTS idx_subscription_message_thread ON subscription_message (subscription_id, id)',
        'CREATE INDEX IF NOT EXISTS idx_subscription_message_unread ON subscription_message (author, read_at)',
    ];

    public const DOWN_STATEMENTS = [
        'DROP TABLE IF EXISTS subscription_message',
        'ALTER TABLE subscription DROP COLUMN IF EXISTS thread_emailed_at',
        'ALTER TABLE benefit DROP COLUMN IF EXISTS at_customer',
        'DROP INDEX IF EXISTS idx_benefit_occurrence_staff',
        'ALTER TABLE benefit_occurrence DROP COLUMN IF EXISTS staff_user_id',
    ];

    public function getDescription(): string
    {
        return 'ABO-3b : intervenant d\'une date, prestation chez le client, fil de l\'abonné';
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
