<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * IA-ACT-5 (13/11/2026, `docs/prix-gestion.md` § 5 et `docs/conversation-gestion.md` § 13) : réglages de prix du site (coefficient par
 * défaut, coefficient minimum, arrondi d'un prix calculé) et marques « mettre en ligne à la réception du stock » d'un article, une par
 * canal (boutique en ligne, caisse). Additive, idempotente ; équivalent `scripts/migrate_all_v2_gestion_prix_reglages.sh`.
 */
final class Version20261228100000 extends AbstractMigration
{
    public const STATEMENTS = [
        'ALTER TABLE entreprise ADD COLUMN IF NOT EXISTS gestion_default_coefficient NUMERIC(6, 2) DEFAULT NULL',
        'ALTER TABLE entreprise ADD COLUMN IF NOT EXISTS gestion_min_coefficient NUMERIC(6, 2) DEFAULT NULL',
        'ALTER TABLE entreprise ADD COLUMN IF NOT EXISTS gestion_price_rounding VARCHAR(12) DEFAULT NULL',
        'ALTER TABLE product ADD COLUMN IF NOT EXISTS publish_web_on_reception BOOLEAN DEFAULT false NOT NULL',
        'ALTER TABLE product ADD COLUMN IF NOT EXISTS publish_pos_on_reception BOOLEAN DEFAULT false NOT NULL',
    ];

    public function getDescription(): string
    {
        return 'IA-ACT-5 : réglages de prix du site et mise en ligne d\'un article à la réception du stock';
    }

    public function up(Schema $schema): void
    {
        foreach (self::STATEMENTS as $sql) {
            $this->addSql($sql);
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE product DROP COLUMN IF EXISTS publish_pos_on_reception');
        $this->addSql('ALTER TABLE product DROP COLUMN IF EXISTS publish_web_on_reception');
        $this->addSql('ALTER TABLE entreprise DROP COLUMN IF EXISTS gestion_price_rounding');
        $this->addSql('ALTER TABLE entreprise DROP COLUMN IF EXISTS gestion_min_coefficient');
        $this->addSql('ALTER TABLE entreprise DROP COLUMN IF EXISTS gestion_default_coefficient');
    }
}
