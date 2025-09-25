<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250910135159 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            CREATE SEQUENCE carrier_translation_id_seq INCREMENT BY 1 MINVALUE 1 START 1
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE carrier_translation (id INT NOT NULL, carrier_id INT DEFAULT NULL, language VARCHAR(10) NOT NULL, name VARCHAR(255) NOT NULL, description VARCHAR(255) DEFAULT NULL, PRIMARY KEY(id))
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX IDX_859E287C21DFC797 ON carrier_translation (carrier_id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE carrier_translation ADD CONSTRAINT FK_859E287C21DFC797 FOREIGN KEY (carrier_id) REFERENCES carrier (id) NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            CREATE SCHEMA public
        SQL);
        $this->addSql(<<<'SQL'
            DROP SEQUENCE carrier_translation_id_seq CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE carrier_translation DROP CONSTRAINT FK_859E287C21DFC797
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE carrier_translation
        SQL);
    }
}
