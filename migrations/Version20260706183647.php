<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260706183647 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SEQUENCE vehicle_translation_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE TABLE vehicle_translation (id INT NOT NULL, vehicle_id INT NOT NULL, language VARCHAR(10) NOT NULL, title VARCHAR(255) DEFAULT NULL, description TEXT DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_3A523819545317D1 ON vehicle_translation (vehicle_id)');
        $this->addSql('ALTER TABLE vehicle_translation ADD CONSTRAINT FK_3A523819545317D1 FOREIGN KEY (vehicle_id) REFERENCES vehicle (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('DROP SEQUENCE vehicle_translation_id_seq CASCADE');
        $this->addSql('ALTER TABLE vehicle_translation DROP CONSTRAINT FK_3A523819545317D1');
        $this->addSql('DROP TABLE vehicle_translation');
    }
}
