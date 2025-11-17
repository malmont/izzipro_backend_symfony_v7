<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251113184954 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            ALTER TABLE sync_job ALTER status TYPE VARCHAR(50)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE sync_job ALTER total_items SET DEFAULT 0
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE sync_job ALTER processed_items SET DEFAULT 0
        SQL);
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            CREATE SCHEMA public
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE sync_job ALTER status TYPE VARCHAR(255)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE sync_job ALTER total_items DROP DEFAULT
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE sync_job ALTER processed_items DROP DEFAULT
        SQL);
    }
}
