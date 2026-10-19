<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261019120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Caisse : heure d\'ouverture des sessions (caisse.created_at en date et heure, sessions existantes recalées sur leur transaction d\'ouverture), fond de caisse à 0 au lieu de NULL ; équivalent de scripts/migrate_all_v2_caisse_opening.sh, idempotent';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("DO \$\$ BEGIN IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'caisse' AND column_name = 'created_at' AND data_type = 'date') THEN ALTER TABLE caisse ALTER COLUMN created_at TYPE TIMESTAMP(0) WITHOUT TIME ZONE USING created_at::timestamp; END IF; END \$\$");
        $this->addSql("UPDATE caisse c SET created_at = t.opened_at FROM (SELECT caisse_id, MIN(transaction_date) AS opened_at FROM transaction_caisse WHERE transaction_type_id = 4 GROUP BY caisse_id) t WHERE t.caisse_id = c.id AND c.created_at = date_trunc('day', c.created_at) AND t.opened_at::date = c.created_at::date");
        $this->addSql('UPDATE caisse SET fon_de_caisse = 0 WHERE fon_de_caisse IS NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE caisse ALTER COLUMN created_at TYPE DATE USING created_at::date');
    }
}
