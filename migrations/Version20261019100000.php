<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261019100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Assistant IA : consignes permanentes de l\'administrateur (ai_credit_setting.assistant_instructions, instructions_updated_at) (équivalent de scripts/migrate_all_v2_landing_ai.sh, idempotent)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("DO \$\$ BEGIN IF EXISTS (SELECT FROM information_schema.tables WHERE table_name = 'ai_credit_setting') THEN ALTER TABLE ai_credit_setting ADD COLUMN IF NOT EXISTS assistant_instructions TEXT DEFAULT NULL; ALTER TABLE ai_credit_setting ADD COLUMN IF NOT EXISTS instructions_updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL; END IF; END \$\$;");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ai_credit_setting DROP COLUMN IF EXISTS assistant_instructions');
        $this->addSql('ALTER TABLE ai_credit_setting DROP COLUMN IF EXISTS instructions_updated_at');
    }
}
