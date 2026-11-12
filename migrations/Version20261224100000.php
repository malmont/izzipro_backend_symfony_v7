<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * PAYS-1b (12/11/2026, `docs/caisse.md` « Annulation sans remise à zéro ») : order.canceled_at, posé par l'annulation à partir de PAYS-1b
 * (la vente garde ses montants ; l'avoir et le journal portent l'annulation). Les annulations antérieures restent à null (montants déjà remis
 * à 0, traitement inchangé). Additive, idempotente ; équivalent `scripts/migrate_all_v2_annulation.sh`.
 */
final class Version20261224100000 extends AbstractMigration
{
    public const STATEMENTS = [
        'ALTER TABLE "order" ADD COLUMN IF NOT EXISTS canceled_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL',
    ];

    public function getDescription(): string
    {
        return 'PAYS-1b : date d\'annulation des ventes (montants gardés)';
    }

    public function up(Schema $schema): void
    {
        foreach (self::STATEMENTS as $sql) {
            $this->addSql($sql);
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "order" DROP COLUMN IF EXISTS canceled_at');
    }
}
