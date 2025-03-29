<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250328212925 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE collections ADD photo_collections_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE collections ADD CONSTRAINT FK_D325D3EE3C1F0C8F FOREIGN KEY (photo_collections_id) REFERENCES collection_picture (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX IDX_D325D3EE3C1F0C8F ON collections (photo_collections_id)');
        $this->addSql('ALTER TABLE commande ADD commandepictures_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE commande ADD CONSTRAINT FK_6EEAA67D47C88A1B FOREIGN KEY (commandepictures_id) REFERENCES collection_picture (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX IDX_6EEAA67D47C88A1B ON commande (commandepictures_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('ALTER TABLE commande DROP CONSTRAINT FK_6EEAA67D47C88A1B');
        $this->addSql('DROP INDEX IDX_6EEAA67D47C88A1B');
        $this->addSql('ALTER TABLE commande DROP commandepictures_id');
        $this->addSql('ALTER TABLE collections DROP CONSTRAINT FK_D325D3EE3C1F0C8F');
        $this->addSql('DROP INDEX IDX_D325D3EE3C1F0C8F');
        $this->addSql('ALTER TABLE collections DROP photo_collections_id');
    }
}
