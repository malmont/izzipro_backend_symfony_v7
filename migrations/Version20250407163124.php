<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250407163124 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SEQUENCE otp_code_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE TABLE otp_code (id INT NOT NULL, user_otp_id INT DEFAULT NULL, code VARCHAR(255) DEFAULT NULL, expiration TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_93FE2319D35FFDA0 ON otp_code (user_otp_id)');
        $this->addSql('ALTER TABLE otp_code ADD CONSTRAINT FK_93FE2319D35FFDA0 FOREIGN KEY (user_otp_id) REFERENCES "user" (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('DROP SEQUENCE otp_code_id_seq CASCADE');
        $this->addSql('ALTER TABLE otp_code DROP CONSTRAINT FK_93FE2319D35FFDA0');
        $this->addSql('DROP TABLE otp_code');
    }
}
