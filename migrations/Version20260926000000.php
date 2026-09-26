<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260926000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Mémoires Vivantes : types de livre configurables (mv_book_type, mv_book_type_chapter, mv_book_type_role)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE SEQUENCE IF NOT EXISTS mv_book_type_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE IF NOT EXISTS mv_book_type_chapter_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE IF NOT EXISTS mv_book_type_role_id_seq INCREMENT BY 1 MINVALUE 1 START 1');

        $this->addSql('CREATE TABLE IF NOT EXISTS mv_book_type (id INT NOT NULL, code VARCHAR(50) NOT NULL, label VARCHAR(255) NOT NULL, description TEXT DEFAULT NULL, family VARCHAR(20) NOT NULL, speaker_count SMALLINT DEFAULT NULL, speaker1_label VARCHAR(100) DEFAULT NULL, speaker2_label VARCHAR(100) DEFAULT NULL, subjects_may_be_absent BOOLEAN DEFAULT false NOT NULL, default_role VARCHAR(50) DEFAULT NULL, default_role_when_subjects_absent VARCHAR(50) DEFAULT NULL, prompt_raw TEXT DEFAULT NULL, prompt_optimized TEXT DEFAULT NULL, prompt_source VARCHAR(20) DEFAULT \'database\' NOT NULL, is_system BOOLEAN DEFAULT false NOT NULL, is_active BOOLEAN DEFAULT true NOT NULL, display_order INT DEFAULT 0 NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS UNIQ_E0EAE67377153098 ON mv_book_type (code)');
        $this->addSql('COMMENT ON COLUMN mv_book_type.created_at IS \'(DC2Type:datetime_immutable)\'');

        $this->addSql('CREATE TABLE IF NOT EXISTS mv_book_type_chapter (id INT NOT NULL, book_type_id INT NOT NULL, code VARCHAR(100) NOT NULL, title VARCHAR(255) NOT NULL, position INT NOT NULL, speaker VARCHAR(20) NOT NULL, prompt_raw TEXT DEFAULT NULL, prompt_optimized TEXT DEFAULT NULL, is_active BOOLEAN DEFAULT true NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IF NOT EXISTS IDX_506DAE36566CA405 ON mv_book_type_chapter (book_type_id)');
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS uniq_mv_book_type_chapter_code ON mv_book_type_chapter (book_type_id, code)');
        $this->addSql('COMMENT ON COLUMN mv_book_type_chapter.created_at IS \'(DC2Type:datetime_immutable)\'');

        $this->addSql('CREATE TABLE IF NOT EXISTS mv_book_type_role (id INT NOT NULL, book_type_id INT NOT NULL, code VARCHAR(50) NOT NULL, label VARCHAR(255) NOT NULL, display_order INT DEFAULT 0 NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IF NOT EXISTS IDX_BD21D810566CA405 ON mv_book_type_role (book_type_id)');
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS uniq_mv_book_type_role_code ON mv_book_type_role (book_type_id, code)');

        $this->addSql('DO $$ BEGIN IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = \'fk_506dae36566ca405\') THEN ALTER TABLE mv_book_type_chapter ADD CONSTRAINT FK_506DAE36566CA405 FOREIGN KEY (book_type_id) REFERENCES mv_book_type (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE; END IF; END $$');
        $this->addSql('DO $$ BEGIN IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = \'fk_bd21d810566ca405\') THEN ALTER TABLE mv_book_type_role ADD CONSTRAINT FK_BD21D810566CA405 FOREIGN KEY (book_type_id) REFERENCES mv_book_type (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE; END IF; END $$');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS mv_book_type_role');
        $this->addSql('DROP TABLE IF EXISTS mv_book_type_chapter');
        $this->addSql('DROP TABLE IF EXISTS mv_book_type');
        $this->addSql('DROP SEQUENCE IF EXISTS mv_book_type_role_id_seq');
        $this->addSql('DROP SEQUENCE IF EXISTS mv_book_type_chapter_id_seq');
        $this->addSql('DROP SEQUENCE IF EXISTS mv_book_type_id_seq');
    }
}
