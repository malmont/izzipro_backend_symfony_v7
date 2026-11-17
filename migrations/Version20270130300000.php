<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Lot AG-3d (18/11/2026, `docs/ecrans-et-contextes.md` § 10) : « Taxes vérifiées par le commerçant ».
 * - `entreprise.taxes_verified_at` et `taxes_verified_by` (prénom) : posés par un administrateur DU site (`POST /api/site/taxes/verify`).
 * - Déclencheur `taxes_verification_falls` sur `tax` (par instruction) : TOUTE écriture d'une taxe, par quelque chemin que ce soit (EasyAdmin,
 *   Doctrine, requête directe, import, console de l'IA, valeurs d'un site neuf), fait tomber la vérification ; le commerçant la redonne.
 *
 * Additive, idempotente ; équivalent `scripts/migrate_all_v2_taxes_verifiees.sh`.
 */
final class Version20270130300000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'AG-3d : taxes vérifiées par le commerçant (colonnes de la fiche du site, déclencheur sur les taxes)';
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

    public const DOWN_STATEMENTS = [
        'DROP TRIGGER IF EXISTS taxes_verification_falls ON tax',
        'DROP FUNCTION IF EXISTS taxes_verification_falls()',
        'ALTER TABLE entreprise DROP COLUMN IF EXISTS taxes_verified_by',
        'ALTER TABLE entreprise DROP COLUMN IF EXISTS taxes_verified_at',
    ];

    public const STATEMENTS = [
        'ALTER TABLE entreprise ADD COLUMN IF NOT EXISTS taxes_verified_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL',
        'ALTER TABLE entreprise ADD COLUMN IF NOT EXISTS taxes_verified_by VARCHAR(100) DEFAULT NULL',
        'CREATE OR REPLACE FUNCTION taxes_verification_falls() RETURNS trigger LANGUAGE plpgsql AS $fn$ BEGIN UPDATE entreprise SET taxes_verified_at = NULL, taxes_verified_by = NULL WHERE taxes_verified_at IS NOT NULL; RETURN NULL; END $fn$',
        'DROP TRIGGER IF EXISTS taxes_verification_falls ON tax',
        'CREATE TRIGGER taxes_verification_falls AFTER INSERT OR UPDATE OR DELETE OR TRUNCATE ON tax FOR EACH STATEMENT EXECUTE FUNCTION taxes_verification_falls()',
    ];
}
