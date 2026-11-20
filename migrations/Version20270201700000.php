<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Ajout à ABO-1 (19/11/2026, demande de la gestion web relayée par le chef) : l'historique d'un abonnement garde le droit utilisé
 * (`subscription_abo_event.occurrence_id`), pour proposer « Annuler l'utilisation » après un rechargement. Colonne ajoutée, nulle pour
 * les lignes déjà écrites : aucune donnée existante modifiée. Idempotente ; équivalent `scripts/migrate_all_v2_abo_history.sh`.
 */
final class Version20270201700000 extends AbstractMigration
{
    public const STATEMENTS = [
        'ALTER TABLE subscription_abo_event ADD COLUMN IF NOT EXISTS occurrence_id INT DEFAULT NULL',
    ];

    public const DOWN_STATEMENTS = [
        'ALTER TABLE subscription_abo_event DROP COLUMN IF EXISTS occurrence_id',
    ];

    public function getDescription(): string
    {
        return 'ABO-1 : droit utilisé gardé dans l\'historique d\'un abonnement';
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
