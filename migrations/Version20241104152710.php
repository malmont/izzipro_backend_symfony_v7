<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20241104152710 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE commande_statistiques ADD commande_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE commande_statistiques ADD transporteur_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE commande_statistiques ADD average_multiplier DOUBLE PRECISION DEFAULT NULL');
        $this->addSql('ALTER TABLE commande_statistiques ADD general_budget DOUBLE PRECISION DEFAULT NULL');
        $this->addSql('ALTER TABLE commande_statistiques ADD used_budget DOUBLE PRECISION DEFAULT NULL');
        $this->addSql('ALTER TABLE commande_statistiques ADD remaining_budget DOUBLE PRECISION DEFAULT NULL');
        $this->addSql('ALTER TABLE commande_statistiques ADD total_item_cost DOUBLE PRECISION DEFAULT NULL');
        $this->addSql('ALTER TABLE commande_statistiques ADD total_frais_de_port DOUBLE PRECISION DEFAULT NULL');
        $this->addSql('ALTER TABLE commande_statistiques ADD item_count INT DEFAULT NULL');
        $this->addSql('ALTER TABLE commande_statistiques ADD model_count INT DEFAULT NULL');
        $this->addSql('ALTER TABLE commande_statistiques ADD stock_value DOUBLE PRECISION DEFAULT NULL');
        $this->addSql('ALTER TABLE commande_statistiques ADD marge DOUBLE PRECISION DEFAULT NULL');
        $this->addSql('ALTER TABLE commande_statistiques ADD taux_marge DOUBLE PRECISION DEFAULT NULL');
        $this->addSql('ALTER TABLE commande_statistiques ADD taux_marque DOUBLE PRECISION DEFAULT NULL');
        $this->addSql('ALTER TABLE commande_statistiques ADD CONSTRAINT FK_89E6102D82EA2E54 FOREIGN KEY (commande_id) REFERENCES commande (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE commande_statistiques ADD CONSTRAINT FK_89E6102D97C86FA4 FOREIGN KEY (transporteur_id) REFERENCES transporteur (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX IDX_89E6102D82EA2E54 ON commande_statistiques (commande_id)');
        $this->addSql('CREATE INDEX IDX_89E6102D97C86FA4 ON commande_statistiques (transporteur_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('ALTER TABLE commande_statistiques DROP CONSTRAINT FK_89E6102D82EA2E54');
        $this->addSql('ALTER TABLE commande_statistiques DROP CONSTRAINT FK_89E6102D97C86FA4');
        $this->addSql('DROP INDEX IDX_89E6102D82EA2E54');
        $this->addSql('DROP INDEX IDX_89E6102D97C86FA4');
        $this->addSql('ALTER TABLE commande_statistiques DROP commande_id');
        $this->addSql('ALTER TABLE commande_statistiques DROP transporteur_id');
        $this->addSql('ALTER TABLE commande_statistiques DROP average_multiplier');
        $this->addSql('ALTER TABLE commande_statistiques DROP general_budget');
        $this->addSql('ALTER TABLE commande_statistiques DROP used_budget');
        $this->addSql('ALTER TABLE commande_statistiques DROP remaining_budget');
        $this->addSql('ALTER TABLE commande_statistiques DROP total_item_cost');
        $this->addSql('ALTER TABLE commande_statistiques DROP total_frais_de_port');
        $this->addSql('ALTER TABLE commande_statistiques DROP item_count');
        $this->addSql('ALTER TABLE commande_statistiques DROP model_count');
        $this->addSql('ALTER TABLE commande_statistiques DROP stock_value');
        $this->addSql('ALTER TABLE commande_statistiques DROP marge');
        $this->addSql('ALTER TABLE commande_statistiques DROP taux_marge');
        $this->addSql('ALTER TABLE commande_statistiques DROP taux_marque');
    }
}
