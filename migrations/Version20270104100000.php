<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Lot LIEN (14/11/2026, `docs/liens-espace-administration.md`) : réglage du site « Adresse de l'espace d'administration »
 * (`entreprise.admin_space_url`), base des liens que les courriels du serveur mettent vers un écran du web. Vide partout : sans
 * réglage, le lien part du domaine principal du site. Additive, idempotente, aucune donnée modifiée.
 * Équivalent `scripts/migrate_all_v2_lien.sh`.
 */
final class Version20270104100000 extends AbstractMigration
{
    public const STATEMENTS = [
        'ALTER TABLE entreprise ADD COLUMN IF NOT EXISTS admin_space_url VARCHAR(255) DEFAULT NULL',
    ];

    public function getDescription(): string
    {
        return 'Lot LIEN : adresse de l\'espace d\'administration du site (entreprise.admin_space_url)';
    }

    public function up(Schema $schema): void
    {
        foreach (self::STATEMENTS as $sql) {
            $this->addSql($sql);
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE entreprise DROP COLUMN IF EXISTS admin_space_url');
    }
}
