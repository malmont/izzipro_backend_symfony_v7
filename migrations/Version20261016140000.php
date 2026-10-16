<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261016140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Comptes : origine de l\'inscription (user.signup_channel), date de création (user.created_at) et dernière connexion (user.last_login_at), pour la console d\'administration de Mémoires Vivantes (équivalent de scripts/migrate_all_v2_user_signup.sh, idempotent)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" ADD COLUMN IF NOT EXISTS signup_channel VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE "user" ADD COLUMN IF NOT EXISTS created_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE "user" ADD COLUMN IF NOT EXISTS last_login_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" DROP COLUMN IF EXISTS signup_channel, DROP COLUMN IF EXISTS created_at, DROP COLUMN IF EXISTS last_login_at');
    }
}
