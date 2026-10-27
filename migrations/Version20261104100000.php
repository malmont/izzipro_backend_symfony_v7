<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Compte client (suite de S7) : user.password_set, faux seulement pour un compte dont le client n'a jamais choisi le mot de passe (créé par
 * un achat invité). Comptes existants : vrai, sauf ceux créés par un achat invité, reconnus avec certitude à leur mot de passe stocké en
 * clair (20 caractères hexadécimaux, aucun hachage n'a cette forme). Additif. Équivalent : scripts/migrate_all_v2_user_password_set.sh.
 */
final class Version20261104100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Compte : password_set (additif)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" ADD COLUMN IF NOT EXISTS password_set BOOLEAN DEFAULT true NOT NULL');
        $this->addSql('UPDATE "user" SET password_set = false WHERE password ~ \'^[0-9a-f]{20}$\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" DROP COLUMN IF EXISTS password_set');
    }
}
