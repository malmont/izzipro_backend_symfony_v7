<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Lot PROVISION-3 (18/11/2026, `docs/console-pilotage.md`, « Paiements des offres ») : `subscription.stripe_event_at`, date chez Stripe du
 * dernier événement appliqué à l'abonnement (ou de la dernière relecture de son état chez Stripe). Un événement plus ancien arrivé en
 * retard est ignoré (`out_of_order`) et ne change rien. Les abonnements existants restent à vide (aucun événement n'est alors tenu pour
 * ancien). Additive, idempotente ; équivalent `scripts/migrate_all_v2_subscription_stripe_event_at.sh`.
 */
final class Version20270201300000 extends AbstractMigration
{
    public const STATEMENTS = [
        'ALTER TABLE subscription ADD COLUMN IF NOT EXISTS stripe_event_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL',
    ];

    public const DOWN_STATEMENTS = [
        'ALTER TABLE subscription DROP COLUMN IF EXISTS stripe_event_at',
    ];

    public function getDescription(): string
    {
        return 'PROVISION-3 : date du dernier événement Stripe appliqué à un abonnement';
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
