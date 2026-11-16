<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Train de sécurité du 16/11/2026 (`docs/memoires-vivantes.md`, « Activation d'un compte invité ») : le lien d'activation d'un compte
 * invité par la console de Mémoires Vivantes a une FIN DE VALIDITÉ (7 jours). Une colonne sur le compte, lue par la route
 * d'activation. Additive, idempotente ; aucune donnée existante n'est touchée ici (l'invalidation des jetons en attente est un geste
 * de la bascule, voir le script). Script équivalent pour toutes les bases : scripts/migrate_all_v2_activation_expiry.sh.
 */
final class Version20270119100000 extends AbstractMigration
{
    public const STATEMENTS = [
        'ALTER TABLE "user" ADD COLUMN IF NOT EXISTS verification_token_expires_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL',
    ];

    public function getDescription(): string
    {
        return 'Sécurité : fin de validité du lien d\'activation d\'un compte invité';
    }

    public function up(Schema $schema): void
    {
        foreach (self::STATEMENTS as $statement) {
            $this->addSql($statement);
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" DROP COLUMN IF EXISTS verification_token_expires_at');
    }
}
