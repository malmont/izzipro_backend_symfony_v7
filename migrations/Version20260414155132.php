<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260414155132 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SEQUENCE rental_pack_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE TABLE rental_pack (id INT NOT NULL, name VARCHAR(255) NOT NULL, gemsuite_product_id INT DEFAULT NULL, hour_rate DOUBLE PRECISION DEFAULT NULL, half_day_rate DOUBLE PRECISION DEFAULT NULL, day_rate DOUBLE PRECISION DEFAULT NULL, week_rate DOUBLE PRECISION DEFAULT NULL, month_rate DOUBLE PRECISION DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE TABLE rental_pack_categories (rental_pack_id INT NOT NULL, categories_id INT NOT NULL, PRIMARY KEY(rental_pack_id, categories_id))');
        $this->addSql('CREATE INDEX IDX_439058AECAFF68F8 ON rental_pack_categories (rental_pack_id)');
        $this->addSql('CREATE INDEX IDX_439058AEA21214B7 ON rental_pack_categories (categories_id)');
        $this->addSql('ALTER TABLE rental_pack_categories ADD CONSTRAINT FK_439058AECAFF68F8 FOREIGN KEY (rental_pack_id) REFERENCES rental_pack (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE rental_pack_categories ADD CONSTRAINT FK_439058AEA21214B7 FOREIGN KEY (categories_id) REFERENCES categories (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE categories ADD category_type INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('DROP SEQUENCE rental_pack_id_seq CASCADE');
        $this->addSql('ALTER TABLE rental_pack_categories DROP CONSTRAINT FK_439058AECAFF68F8');
        $this->addSql('ALTER TABLE rental_pack_categories DROP CONSTRAINT FK_439058AEA21214B7');
        $this->addSql('DROP TABLE rental_pack');
        $this->addSql('DROP TABLE rental_pack_categories');
        $this->addSql('ALTER TABLE categories DROP category_type');
    }
}
