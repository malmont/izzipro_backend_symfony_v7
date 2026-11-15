<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Lot IMP-1 (15/11/2026, `docs/import-boutique.md`) : import du catalogue d'une boutique existante par fichier.
 * - `import_job` : un import (source, nature, état, fichier, choix, rapport d'analyse, résultat) ;
 * - `import_item` : ce qu'un import a créé (article, variante, catégorie, image) et sa référence d'origine : c'est elle qui empêche
 *   un doublon quand le même fichier est rejoué, et qui dit quoi retirer à l'annulation ;
 * - un type de mouvement de stock « Import » : le stock de départ d'un article importé n'est ni une réception ni un achat.
 * Additive, idempotente ; aucune donnée existante modifiée. Équivalent `scripts/migrate_all_v2_import.sh` (mêmes 7 instructions).
 */
final class Version20270108100000 extends AbstractMigration
{
    public const STATEMENTS = [
        'CREATE TABLE IF NOT EXISTS import_job (id SERIAL PRIMARY KEY, source VARCHAR(20) NOT NULL, kind VARCHAR(20) NOT NULL, status VARCHAR(20) NOT NULL, file_name VARCHAR(255) NOT NULL, stored_name VARCHAR(80) NOT NULL, file_hash VARCHAR(64) NOT NULL, row_count INT DEFAULT 0 NOT NULL, options JSONB DEFAULT NULL, report JSONB DEFAULT NULL, result JSONB DEFAULT NULL, error VARCHAR(255) DEFAULT NULL, created_by VARCHAR(180) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NOW() NOT NULL, analysed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, started_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, finished_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, cancelled_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL)',
        'CREATE INDEX IF NOT EXISTS idx_import_job_status ON import_job (status)',
        // un seul import actif par site, garanti par la base (deux dépôts au même instant)
        "CREATE UNIQUE INDEX IF NOT EXISTS uniq_import_job_active ON import_job ((1)) WHERE status IN ('uploaded', 'analysed', 'running')",
        'CREATE TABLE IF NOT EXISTS import_item (id BIGSERIAL PRIMARY KEY, job_id INT NOT NULL REFERENCES import_job (id) ON DELETE CASCADE, source VARCHAR(20) NOT NULL, kind VARCHAR(12) NOT NULL, entity_id INT NOT NULL, ref VARCHAR(255) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NOW() NOT NULL)',
        'CREATE INDEX IF NOT EXISTS idx_import_item_ref ON import_item (source, kind, ref)',
        'CREATE INDEX IF NOT EXISTS idx_import_item_job ON import_item (job_id)',
        // l'identifiant est donné à la main : selon les bases, la colonne a ou non une valeur par défaut ; la séquence, quand elle existe, est d'abord remise au
        // plus grand identifiant. Toujours 6 ou plus : les identifiants 1 à 5 sont ceux des types d'origine, lus en dur (5 = réception fournisseur)
        "DO \$\$ DECLARE new_id INT; BEGIN IF NOT EXISTS (SELECT 1 FROM movement_type WHERE name = 'Import') THEN IF to_regclass('movement_type_id_seq') IS NOT NULL THEN PERFORM setval('movement_type_id_seq', GREATEST((SELECT COALESCE(MAX(id), 1) FROM movement_type), (SELECT last_value FROM movement_type_id_seq), 5)); new_id := nextval('movement_type_id_seq'); ELSE new_id := (SELECT GREATEST(COALESCE(MAX(id), 0) + 1, 6) FROM movement_type); END IF; INSERT INTO movement_type (id, name, description) VALUES (new_id, 'Import', 'Stock de départ d''un article importé d''une autre boutique : ni une réception ni un achat'); END IF; END \$\$",
    ];

    public function getDescription(): string
    {
        return 'Lot IMP-1 : imports de catalogue (import_job, import_item) et type de mouvement de stock « Import »';
    }

    public function up(Schema $schema): void
    {
        foreach (self::STATEMENTS as $sql) {
            $this->addSql($sql);
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS import_item');
        $this->addSql('DROP TABLE IF EXISTS import_job');
        // le type de mouvement « Import » reste : des mouvements de stock peuvent le porter
    }
}
