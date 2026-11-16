<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Lot SÉCURITÉ-2 (16/11/2026, `docs/architecture.md`, « Une adresse, un compte, à la casse près ») : une adresse ne désigne qu'un
 * compte, quelle que soit sa casse. Index unique sur l'adresse en minuscules ; l'ancien index unique (sensible à la casse) reste.
 * Aucune adresse n'est réécrite. S'il existe déjà deux comptes de même adresse à la casse près, l'instruction échoue et rien n'est
 * posé (le script s'arrête avant, en le disant). Script équivalent pour toutes les bases : scripts/migrate_all_v2_email_caseless.sh.
 */
final class Version20270121100000 extends AbstractMigration
{
    public const STATEMENTS = [
        'CREATE UNIQUE INDEX IF NOT EXISTS uniq_user_email_lower ON "user" (lower(email))',
    ];

    public function getDescription(): string
    {
        return 'Sécurité : une adresse, un compte, à la casse près (index unique sur lower(email))';
    }

    public function up(Schema $schema): void
    {
        foreach (self::STATEMENTS as $statement) {
            $this->addSql($statement);
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS uniq_user_email_lower');
    }
}
