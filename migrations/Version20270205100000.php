<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Lot ABO-WEB-2 (20/11/2026, décision du chef) : `product.web_hidden_by_abo`, la fiche d'un porteur créé par newProduct masquée par le
 * nettoyage qui suit la suppression de sa dernière formule (la réactivation de l'abonnement ne la rend visible que si ce drapeau est posé).
 * Colonne neuve à FALSE, rien d'existant modifié ; idempotente ; équivalent `scripts/migrate_all_v2_abo_web2.sh`.
 */
final class Version20270205100000 extends AbstractMigration
{
    public const STATEMENTS = [
        'ALTER TABLE product ADD COLUMN IF NOT EXISTS web_hidden_by_abo BOOLEAN NOT NULL DEFAULT FALSE',
    ];

    public const DOWN_STATEMENTS = [
        'ALTER TABLE product DROP COLUMN IF EXISTS web_hidden_by_abo',
    ];

    public function getDescription(): string
    {
        return 'ABO-WEB-2 : drapeau de la fiche masquée par le nettoyage du porteur';
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
