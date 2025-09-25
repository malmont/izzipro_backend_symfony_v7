<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250819134630 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            CREATE SEQUENCE presentation_id_seq INCREMENT BY 1 MINVALUE 1 START 1
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE presentation (id INT NOT NULL, titre VARCHAR(255) NOT NULL, texte VARCHAR(255) DEFAULT NULL, image VARCHAR(255) DEFAULT NULL, texte_bouton VARCHAR(255) DEFAULT NULL, lien_bouton VARCHAR(255) DEFAULT NULL, PRIMARY KEY(id))
        SQL);
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            CREATE SCHEMA public
        SQL);
        $this->addSql(<<<'SQL'
            DROP SEQUENCE presentation_id_seq CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE presentation
        SQL);
    }
}
