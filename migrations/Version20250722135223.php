<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250722135223 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            ALTER TABLE "user" ADD primary_address_id INT DEFAULT NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE "user" ADD CONSTRAINT FK_8D93D649CB134313 FOREIGN KEY (primary_address_id) REFERENCES adress (id) NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX UNIQ_8D93D649CB134313 ON "user" (primary_address_id)
        SQL);
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            CREATE SCHEMA public
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE "user" DROP CONSTRAINT FK_8D93D649CB134313
        SQL);
        $this->addSql(<<<'SQL'
            DROP INDEX UNIQ_8D93D649CB134313
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE "user" DROP primary_address_id
        SQL);
    }
}
