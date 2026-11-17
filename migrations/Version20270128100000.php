<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Prévention des pertes, lot L5-c « avis de l'IA sur un constat » (17/11/2026, `docs/prevention-pertes.md` § 18.3 et § 18.10) :
 * - `loss_opinion` : l'avis COURANT d'un constat (un par constat, remplacé par « Actualiser ») : état, pourcentage calculé par le
 *   serveur, mesures, texte rédigé (résumé, facteurs, autres explications), qui l'a demandé, quand, unités. Effacé avec l'exercice
 *   (`LossFindingRepository`, sans clé étrangère : un TRUNCATE de `loss_finding` doit rester refusé par la garde du module). Le texte n'entre jamais au
 *   journal du module.
 *
 * Additive, idempotente ; équivalent `scripts/migrate_all_v2_pertes_l5c.sh`.
 */
final class Version20270128100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Prévention des pertes L5-c : avis de l\'IA sur un constat';
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
        'DROP TABLE IF EXISTS loss_opinion',
    ];

    public const STATEMENTS = [
        'CREATE TABLE IF NOT EXISTS loss_opinion (finding_id INT NOT NULL, exercise BOOLEAN DEFAULT false NOT NULL, state VARCHAR(10) NOT NULL,'
        . ' percent SMALLINT DEFAULT NULL, measures JSON NOT NULL, content JSON DEFAULT NULL, error VARCHAR(40) DEFAULT NULL, example BOOLEAN DEFAULT false NOT NULL,'
        . ' requested_by INT DEFAULT NULL, requested_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, generated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,'
        . ' evidence_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, credits_used SMALLINT DEFAULT 0 NOT NULL, usage_id INT DEFAULT NULL, attempt INT DEFAULT 1 NOT NULL, PRIMARY KEY(finding_id),'
        . " CONSTRAINT loss_opinion_state CHECK (state IN ('pending', 'done', 'failed')), CONSTRAINT loss_opinion_percent CHECK (percent IS NULL OR percent BETWEEN 0 AND 100))",
        "CREATE INDEX IF NOT EXISTS idx_loss_opinion_pending ON loss_opinion (requested_at) WHERE state = 'pending'",
        // une table créée avant le numéro de demande (relecture du 17/11/2026) reçoit la colonne
        'ALTER TABLE loss_opinion ADD COLUMN IF NOT EXISTS attempt INT DEFAULT 1 NOT NULL',
    ];
}
