<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250509133044 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE adress DROP CONSTRAINT FK_5CECC7BE84667448');
        $this->addSql('ALTER TABLE adress ALTER user_adress_id DROP NOT NULL');
        $this->addSql('ALTER TABLE adress ALTER phone TYPE VARCHAR(20)');
        $this->addSql('ALTER TABLE adress ALTER codepostal TYPE VARCHAR(10)');
        $this->addSql('ALTER TABLE adress ADD CONSTRAINT FK_5CECC7BE84667448 FOREIGN KEY (user_adress_id) REFERENCES "user" (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('ALTER TABLE adress DROP CONSTRAINT fk_5cecc7be84667448');
        $this->addSql('ALTER TABLE adress ALTER user_adress_id SET NOT NULL');
        $this->addSql('ALTER TABLE adress ALTER phone TYPE INT');
        $this->addSql('ALTER TABLE adress ALTER phone TYPE INT');
        $this->addSql('ALTER TABLE adress ALTER codepostal TYPE INT');
        $this->addSql('ALTER TABLE adress ALTER codepostal TYPE INT');
        $this->addSql('ALTER TABLE adress ADD CONSTRAINT fk_5cecc7be84667448 FOREIGN KEY (user_adress_id) REFERENCES "user" (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
    }
}
