<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Correctif AIDE-APP (18/11/2026, `docs/assistance.md`) : la version de l'application d'où vient une demande du client est rangée dans
 * `support_ticket.app_version`, visible de l'équipe sur la fiche de l'Assistance, et n'est plus ajoutée au premier message du client (où
 * il la voyait dans son propre fil, en français). Les demandes existantes ne bougent pas. Additive, idempotente ; équivalent
 * `scripts/migrate_all_v2_support_app_version.sh`.
 */
final class Version20270201200000 extends AbstractMigration
{
    public const STATEMENTS = [
        'ALTER TABLE support_ticket ADD COLUMN IF NOT EXISTS app_version VARCHAR(40) DEFAULT NULL',
    ];

    public const DOWN_STATEMENTS = [
        'ALTER TABLE support_ticket DROP COLUMN IF EXISTS app_version',
    ];

    public function getDescription(): string
    {
        return 'Correctif AIDE-APP : version de l\'application sur la demande, hors du message du client';
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
