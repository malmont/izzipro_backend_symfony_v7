<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Lot FIS-2 (21/11/2026, `docs/plan-impots.md` § 3.4, `docs/aides-gestion.md`) : aides de la gestion. Une fiche par aide (versements,
 * conditions, justificatifs, remboursement décidé, termes d'une avance remboursable) ; le produit et les flux sont CALCULÉS. Les rappels
 * déjà faits sont notés à part (`gestion_aid_reminder`, une ligne par élément rappelé). Le lien d'un prêt avec son aide existe déjà
 * (`gestion_loan.aid_id`, lot FIS-1). Tables neuves, aucune donnée existante modifiée ; idempotente ; équivalent
 * `scripts/migrate_all_v2_gestion_aids.sh`.
 */
final class Version20270202500000 extends AbstractMigration
{
    public const STATEMENTS = [
        'CREATE TABLE IF NOT EXISTS gestion_aid (id SERIAL PRIMARY KEY, label VARCHAR(120) NOT NULL, organism VARCHAR(120) DEFAULT NULL, kind VARCHAR(20) NOT NULL, amount_cents BIGINT NOT NULL, currency VARCHAR(3) NOT NULL, status VARCHAR(12) NOT NULL, covered_from DATE DEFAULT NULL, covered_to DATE DEFAULT NULL, payments JSON DEFAULT NULL, conditions JSON DEFAULT NULL, documents JSON DEFAULT NULL, repayment JSON DEFAULT NULL, advance JSON DEFAULT NULL, notes VARCHAR(1000) DEFAULT NULL, created_by VARCHAR(180) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL)',
        'CREATE TABLE IF NOT EXISTS gestion_aid_reminder (aid_id INT NOT NULL, item VARCHAR(40) NOT NULL, sent_on DATE NOT NULL, PRIMARY KEY (aid_id, item))',
        'CREATE INDEX IF NOT EXISTS idx_gestion_loan_aid ON gestion_loan (aid_id)',
    ];

    public const DOWN_STATEMENTS = [
        'DROP INDEX IF EXISTS idx_gestion_loan_aid',
        'DROP TABLE IF EXISTS gestion_aid_reminder',
        'DROP TABLE IF EXISTS gestion_aid',
    ];

    public function getDescription(): string
    {
        return 'FIS-2 : aides de la gestion';
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
