<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Application de gestion, lot A1 (10/11/2026, `docs/publicite-gestion.md`) : campagnes de publicité (`marketing_campaign`, montant dans la devise
 * de la régie avec un taux vers la devise du site, justificatif facultatif), leurs articles et commandes visés, et lien du document lu
 * (`gestion_document.campaign_id`). Additive, idempotente ; équivalent `scripts/migrate_all_v2_gestion_campagnes.sh` pour toutes les bases.
 */
final class Version20261204100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Gestion, lot A1 : campagnes de publicité, cibles, gestion_document.campaign_id';
    }

    public function up(Schema $schema): void
    {
        foreach (self::STATEMENTS as $sql) {
            $this->addSql($sql);
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE gestion_document DROP COLUMN IF EXISTS campaign_id');
        $this->addSql('DROP TABLE IF EXISTS marketing_campaign_commande');
        $this->addSql('DROP TABLE IF EXISTS marketing_campaign_product');
        $this->addSql('DROP TABLE IF EXISTS marketing_campaign');
    }

    public const STATEMENTS = [
        "CREATE TABLE IF NOT EXISTS marketing_campaign (id SERIAL NOT NULL, name VARCHAR(255) NOT NULL, channel VARCHAR(20) NOT NULL, start_date DATE NOT NULL, end_date DATE NOT NULL, amount_cents INT NOT NULL, currency VARCHAR(3) DEFAULT NULL, exchange_rate NUMERIC(12, 6) DEFAULT NULL, notes TEXT DEFAULT NULL, document_id INT DEFAULT NULL, created_by VARCHAR(255) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id), CONSTRAINT fk_marketing_campaign_document FOREIGN KEY (document_id) REFERENCES gestion_document (id) ON DELETE SET NULL)",
        'CREATE INDEX IF NOT EXISTS idx_marketing_campaign_start ON marketing_campaign (start_date)',
        'CREATE TABLE IF NOT EXISTS marketing_campaign_product (campaign_id INT NOT NULL, product_id INT NOT NULL, PRIMARY KEY(campaign_id, product_id), CONSTRAINT fk_mcp_campaign FOREIGN KEY (campaign_id) REFERENCES marketing_campaign (id) ON DELETE CASCADE, CONSTRAINT fk_mcp_product FOREIGN KEY (product_id) REFERENCES product (id) ON DELETE CASCADE)',
        'CREATE TABLE IF NOT EXISTS marketing_campaign_commande (campaign_id INT NOT NULL, commande_id INT NOT NULL, PRIMARY KEY(campaign_id, commande_id), CONSTRAINT fk_mcc_campaign FOREIGN KEY (campaign_id) REFERENCES marketing_campaign (id) ON DELETE CASCADE, CONSTRAINT fk_mcc_commande FOREIGN KEY (commande_id) REFERENCES commande (id) ON DELETE CASCADE)',
        'ALTER TABLE gestion_document ADD COLUMN IF NOT EXISTS campaign_id INT DEFAULT NULL',
    ];
}
