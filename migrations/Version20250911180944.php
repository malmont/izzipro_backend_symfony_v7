<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250911180944 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            CREATE SEQUENCE service_offer_translation_id_seq INCREMENT BY 1 MINVALUE 1 START 1
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE service_offer_translation (id INT NOT NULL, service_offer_id INT DEFAULT NULL, language VARCHAR(10) NOT NULL, titre VARCHAR(255) NOT NULL, titre_commentaire VARCHAR(255) DEFAULT NULL, descriptions TEXT DEFAULT NULL, PRIMARY KEY(id))
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX IDX_5F5C9CB4AAB02FD3 ON service_offer_translation (service_offer_id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE service_offer_translation ADD CONSTRAINT FK_5F5C9CB4AAB02FD3 FOREIGN KEY (service_offer_id) REFERENCES service_offer (id) NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            CREATE SCHEMA public
        SQL);
        $this->addSql(<<<'SQL'
            DROP SEQUENCE service_offer_translation_id_seq CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE service_offer_translation DROP CONSTRAINT FK_5F5C9CB4AAB02FD3
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE service_offer_translation
        SQL);
    }
}
