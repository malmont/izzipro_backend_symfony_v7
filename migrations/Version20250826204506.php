<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250826204506 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            CREATE SEQUENCE presentation_group_id_seq INCREMENT BY 1 MINVALUE 1 START 1
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE presentation_group (id INT NOT NULL, titre VARCHAR(255) NOT NULL, PRIMARY KEY(id))
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE presentation_group_presentation (presentation_group_id INT NOT NULL, presentation_id INT NOT NULL, PRIMARY KEY(presentation_group_id, presentation_id))
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX IDX_97C2189BEC7E4357 ON presentation_group_presentation (presentation_group_id)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX IDX_97C2189BAB627E8B ON presentation_group_presentation (presentation_id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE presentation_group_presentation ADD CONSTRAINT FK_97C2189BEC7E4357 FOREIGN KEY (presentation_group_id) REFERENCES presentation_group (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE presentation_group_presentation ADD CONSTRAINT FK_97C2189BAB627E8B FOREIGN KEY (presentation_id) REFERENCES presentation (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            CREATE SCHEMA public
        SQL);
        $this->addSql(<<<'SQL'
            DROP SEQUENCE presentation_group_id_seq CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE presentation_group_presentation DROP CONSTRAINT FK_97C2189BEC7E4357
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE presentation_group_presentation DROP CONSTRAINT FK_97C2189BAB627E8B
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE presentation_group
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE presentation_group_presentation
        SQL);
    }
}
