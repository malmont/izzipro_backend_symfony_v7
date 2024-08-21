<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20240821154949 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE commande ADD fournisseur_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE commande ADD CONSTRAINT FK_6EEAA67D670C757F FOREIGN KEY (fournisseur_id) REFERENCES fournisseur (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX IDX_6EEAA67D670C757F ON commande (fournisseur_id)');
        $this->addSql('ALTER TABLE fournisseur DROP CONSTRAINT fk_369eca328bf5c2e6');
        $this->addSql('DROP INDEX idx_369eca328bf5c2e6');
        $this->addSql('ALTER TABLE fournisseur DROP commandes_id');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('ALTER TABLE fournisseur ADD commandes_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE fournisseur ADD CONSTRAINT fk_369eca328bf5c2e6 FOREIGN KEY (commandes_id) REFERENCES commande (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX idx_369eca328bf5c2e6 ON fournisseur (commandes_id)');
        $this->addSql('ALTER TABLE commande DROP CONSTRAINT FK_6EEAA67D670C757F');
        $this->addSql('DROP INDEX IDX_6EEAA67D670C757F');
        $this->addSql('ALTER TABLE commande DROP fournisseur_id');
    }
}
