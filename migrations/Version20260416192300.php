<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260416192300 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajout de la table sale_unit et de la relation avec product';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE sale_unit (id INT NOT NULL, name VARCHAR(255) NOT NULL, PRIMARY KEY(id))');
        $this->addSql('ALTER TABLE product ADD sale_unit_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE product ADD CONSTRAINT FK_D34A04AD48E7C567 FOREIGN KEY (sale_unit_id) REFERENCES sale_unit (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX IDX_D34A04AD48E7C567 ON product (sale_unit_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE product DROP CONSTRAINT FK_D34A04AD48E7C567');
        $this->addSql('DROP TABLE sale_unit');
        $this->addSql('DROP INDEX IDX_D34A04AD48E7C567');
        $this->addSql('ALTER TABLE product DROP sale_unit_id');
    }
}
