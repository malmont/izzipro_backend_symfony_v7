<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250915153931 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            ALTER TABLE product ALTER description DROP NOT NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE product ALTER moreinformations DROP NOT NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE product ALTER image DROP NOT NULL
        SQL);
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            CREATE SCHEMA public
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE product ALTER description SET NOT NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE product ALTER moreinformations SET NOT NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE product ALTER image SET NOT NULL
        SQL);
    }
}
