<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250723191145 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            CREATE SEQUENCE banniere_id_seq INCREMENT BY 1 MINVALUE 1 START 1
        SQL);
        $this->addSql(<<<'SQL'
            CREATE SEQUENCE candidature_id_seq INCREMENT BY 1 MINVALUE 1 START 1
        SQL);
        $this->addSql(<<<'SQL'
            CREATE SEQUENCE categorie_marque_id_seq INCREMENT BY 1 MINVALUE 1 START 1
        SQL);
        $this->addSql(<<<'SQL'
            CREATE SEQUENCE emploi_id_seq INCREMENT BY 1 MINVALUE 1 START 1
        SQL);
        $this->addSql(<<<'SQL'
            CREATE SEQUENCE marque_id_seq INCREMENT BY 1 MINVALUE 1 START 1
        SQL);
        $this->addSql(<<<'SQL'
            CREATE SEQUENCE multilien_id_seq INCREMENT BY 1 MINVALUE 1 START 1
        SQL);
        $this->addSql(<<<'SQL'
            CREATE SEQUENCE recherche_id_seq INCREMENT BY 1 MINVALUE 1 START 1
        SQL);
        $this->addSql(<<<'SQL'
            CREATE SEQUENCE service_offer_id_seq INCREMENT BY 1 MINVALUE 1 START 1
        SQL);
        $this->addSql(<<<'SQL'
            CREATE SEQUENCE video_id_seq INCREMENT BY 1 MINVALUE 1 START 1
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE banniere (id INT NOT NULL, titre VARCHAR(255) NOT NULL, texte TEXT DEFAULT NULL, image_de_fond VARCHAR(255) DEFAULT NULL, PRIMARY KEY(id))
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE candidature (id INT NOT NULL, emploi_id INT DEFAULT NULL, nom_complet VARCHAR(255) NOT NULL, courriel VARCHAR(255) NOT NULL, tel VARCHAR(255) DEFAULT NULL, adresse VARCHAR(255) DEFAULT NULL, ville VARCHAR(255) DEFAULT NULL, code_postal VARCHAR(255) DEFAULT NULL, annee_experience VARCHAR(255) DEFAULT NULL, date_disponibilite DATE DEFAULT NULL, question_commentaire VARCHAR(255) DEFAULT NULL, niveau_anglais VARCHAR(255) DEFAULT NULL, succursale VARCHAR(255) DEFAULT NULL, lien_cv VARCHAR(255) DEFAULT NULL, PRIMARY KEY(id))
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX IDX_E33BD3B8EC013E12 ON candidature (emploi_id)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE categorie_marque (id INT NOT NULL, nom VARCHAR(255) NOT NULL, PRIMARY KEY(id))
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE emploi (id INT NOT NULL, titre VARCHAR(255) NOT NULL, description VARCHAR(255) DEFAULT NULL, PRIMARY KEY(id))
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE marque (id INT NOT NULL, titre VARCHAR(255) NOT NULL, logos_marques VARCHAR(255) DEFAULT NULL, PRIMARY KEY(id))
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE marque_categorie_marque (marque_id INT NOT NULL, categorie_marque_id INT NOT NULL, PRIMARY KEY(marque_id, categorie_marque_id))
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX IDX_245ECD4A4827B9B2 ON marque_categorie_marque (marque_id)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX IDX_245ECD4AE578B6B5 ON marque_categorie_marque (categorie_marque_id)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE multilien (id INT NOT NULL, titre VARCHAR(255) NOT NULL, image_de_fond VARCHAR(255) DEFAULT NULL, lien VARCHAR(255) NOT NULL, PRIMARY KEY(id))
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE recherche (id INT NOT NULL, titre VARCHAR(255) NOT NULL, texte1 VARCHAR(255) DEFAULT NULL, texte2 VARCHAR(255) DEFAULT NULL, image_de_fond VARCHAR(255) DEFAULT NULL, PRIMARY KEY(id))
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE service_offer (id INT NOT NULL, titre VARCHAR(255) NOT NULL, logo VARCHAR(255) DEFAULT NULL, titre_commentaire VARCHAR(255) DEFAULT NULL, descriptions VARCHAR(255) DEFAULT NULL, PRIMARY KEY(id))
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE video (id INT NOT NULL, titre VARCHAR(255) NOT NULL, image_de_fond VARCHAR(255) DEFAULT NULL, lien_video VARCHAR(255) DEFAULT NULL, PRIMARY KEY(id))
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE candidature ADD CONSTRAINT FK_E33BD3B8EC013E12 FOREIGN KEY (emploi_id) REFERENCES emploi (id) NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE marque_categorie_marque ADD CONSTRAINT FK_245ECD4A4827B9B2 FOREIGN KEY (marque_id) REFERENCES marque (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE marque_categorie_marque ADD CONSTRAINT FK_245ECD4AE578B6B5 FOREIGN KEY (categorie_marque_id) REFERENCES categorie_marque (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            CREATE SCHEMA public
        SQL);
        $this->addSql(<<<'SQL'
            DROP SEQUENCE banniere_id_seq CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            DROP SEQUENCE candidature_id_seq CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            DROP SEQUENCE categorie_marque_id_seq CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            DROP SEQUENCE emploi_id_seq CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            DROP SEQUENCE marque_id_seq CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            DROP SEQUENCE multilien_id_seq CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            DROP SEQUENCE recherche_id_seq CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            DROP SEQUENCE service_offer_id_seq CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            DROP SEQUENCE video_id_seq CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE candidature DROP CONSTRAINT FK_E33BD3B8EC013E12
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE marque_categorie_marque DROP CONSTRAINT FK_245ECD4A4827B9B2
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE marque_categorie_marque DROP CONSTRAINT FK_245ECD4AE578B6B5
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE banniere
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE candidature
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE categorie_marque
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE emploi
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE marque
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE marque_categorie_marque
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE multilien
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE recherche
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE service_offer
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE video
        SQL);
    }
}
