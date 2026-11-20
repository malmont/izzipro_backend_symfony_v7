<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Lot FIS-1 (19/11/2026, `docs/plan-impots.md` § 3.1, `docs/prets-gestion.md`) : prêts de la gestion. Une fiche par prêt ; l'échéancier est
 * CALCULÉ (jamais stocké). Table neuve, aucune donnée existante modifiée ; idempotente ; équivalent `scripts/migrate_all_v2_gestion_loans.sh`.
 */
final class Version20270201600000 extends AbstractMigration
{
    public const STATEMENTS = [
        'CREATE TABLE IF NOT EXISTS gestion_loan (id SERIAL PRIMARY KEY, label VARCHAR(120) NOT NULL, lender VARCHAR(120) DEFAULT NULL, amount_cents BIGINT NOT NULL, currency VARCHAR(3) NOT NULL, rate_ppm INT NOT NULL, duration_months INT NOT NULL, frequency_months INT NOT NULL, disbursed_on DATE NOT NULL, first_due_on DATE NOT NULL, type VARCHAR(20) NOT NULL, deferral_months INT NOT NULL DEFAULT 0, deferral_kind VARCHAR(10) DEFAULT NULL, insurance_mode VARCHAR(10) DEFAULT NULL, insurance_amount_cents INT NOT NULL DEFAULT 0, insurance_rate_ppm INT NOT NULL DEFAULT 0, fees_cents INT NOT NULL DEFAULT 0, prepayments JSON DEFAULT NULL, notes VARCHAR(1000) DEFAULT NULL, aid_id INT DEFAULT NULL, created_by VARCHAR(180) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL)',
    ];

    public const DOWN_STATEMENTS = [
        'DROP TABLE IF EXISTS gestion_loan',
    ];

    public function getDescription(): string
    {
        return 'FIS-1 : prêts de la gestion';
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
