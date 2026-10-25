<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Caisse, complément F9 / C4 (01/11/2026) : vente d'une carte-cadeau payée au terminal Stripe. Destination d'un paiement terminal
 * (sale | gift_card) pour empêcher l'usage croisé. Additif. Équivalent : scripts/migrate_all_v2_pos_terminal_purpose.sh.
 */
final class Version20261101110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Caisse F9/C4 : destination d\'un paiement terminal (additif)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pos_terminal_payment ADD COLUMN IF NOT EXISTS purpose VARCHAR(16) DEFAULT \'sale\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pos_terminal_payment DROP COLUMN IF EXISTS purpose');
    }
}
