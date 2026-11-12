<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * PAYS-1 (12/11/2026, `docs/pays-audit.md`) : taxe gelée sur chaque ligne order_tax (libellé, taux en millionièmes, code, juridiction,
 * catégorie, mode TTC, source), reprise des lignes existantes (source « backfill », taux actuel de la taxe liée), déclencheur qui refuse la
 * modification d'une ligne gelée (le DELETE reste permis : nettoyage des tests, anonymisation) ; tax.code, tax.archived_at, tax.valid_from,
 * tax.valid_to. Additive, idempotente ; équivalent `scripts/migrate_all_v2_taxes_gel.sh`.
 */
final class Version20261223100000 extends AbstractMigration
{
    public const STATEMENTS = [
        'ALTER TABLE tax ADD COLUMN IF NOT EXISTS code VARCHAR(20) DEFAULT NULL',
        'ALTER TABLE tax ADD COLUMN IF NOT EXISTS archived_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL',
        'ALTER TABLE tax ADD COLUMN IF NOT EXISTS valid_from DATE DEFAULT NULL',
        'ALTER TABLE tax ADD COLUMN IF NOT EXISTS valid_to DATE DEFAULT NULL',
        'ALTER TABLE order_tax ADD COLUMN IF NOT EXISTS tax_label VARCHAR(120) DEFAULT NULL',
        'ALTER TABLE order_tax ADD COLUMN IF NOT EXISTS tax_rate_ppm INT DEFAULT NULL',
        'ALTER TABLE order_tax ADD COLUMN IF NOT EXISTS tax_code VARCHAR(20) DEFAULT NULL',
        'ALTER TABLE order_tax ADD COLUMN IF NOT EXISTS tax_jurisdiction VARCHAR(20) DEFAULT NULL',
        'ALTER TABLE order_tax ADD COLUMN IF NOT EXISTS tax_category VARCHAR(16) DEFAULT NULL',
        'ALTER TABLE order_tax ADD COLUMN IF NOT EXISTS prices_include_tax BOOLEAN DEFAULT NULL',
        'ALTER TABLE order_tax ADD COLUMN IF NOT EXISTS tax_source VARCHAR(12) DEFAULT NULL',
        // code déduit du libellé, même règle que TaxCode::fromLabel (lettres ASCII du nom, en majuscules)
        "UPDATE tax SET code = CASE upper(regexp_replace(name, '[^A-Za-z]', '', 'g')) WHEN 'TPS' THEN 'gst' WHEN 'GST' THEN 'gst' WHEN 'TVQ' THEN 'qst' WHEN 'QST' THEN 'qst' WHEN 'TVH' THEN 'hst' WHEN 'HST' THEN 'hst' WHEN 'TVP' THEN 'pst' WHEN 'PST' THEN 'pst' WHEN 'TVA' THEN 'vat' WHEN 'VAT' THEN 'vat' ELSE 'other' END WHERE code IS NULL",
        // reprise : taux actuel de la taxe liée (aucune vente réelle au 12/11/2026) ; lignes sans taxe liée (abonnements) : « Taxe », taux inconnu
        "UPDATE order_tax ot SET tax_label = t.name, tax_rate_ppm = round(t.rate * 1000000)::int, tax_code = t.code, tax_category = t.category, prices_include_tax = (SELECT o.prices_include_tax FROM \"order\" o WHERE o.id = ot.order_tax_id), tax_source = 'backfill' FROM tax t WHERE t.id = ot.tax_id AND ot.tax_source IS NULL",
        "UPDATE order_tax ot SET tax_label = 'Taxe', prices_include_tax = (SELECT o.prices_include_tax FROM \"order\" o WHERE o.id = ot.order_tax_id), tax_source = 'backfill' WHERE ot.tax_id IS NULL AND ot.tax_source IS NULL",
        "CREATE OR REPLACE FUNCTION order_tax_frozen_guard() RETURNS trigger LANGUAGE plpgsql AS \$fn\$ BEGIN IF OLD.tax_source IS NOT NULL AND (NEW.tax_label IS DISTINCT FROM OLD.tax_label OR NEW.tax_rate_ppm IS DISTINCT FROM OLD.tax_rate_ppm OR NEW.tax_code IS DISTINCT FROM OLD.tax_code OR NEW.tax_jurisdiction IS DISTINCT FROM OLD.tax_jurisdiction OR NEW.tax_category IS DISTINCT FROM OLD.tax_category OR NEW.prices_include_tax IS DISTINCT FROM OLD.prices_include_tax OR NEW.tax_source IS DISTINCT FROM OLD.tax_source) THEN RAISE EXCEPTION 'ORDER_TAX_FROZEN : taxe gelée à la vente (ligne order_tax %), modification refusée', OLD.id; END IF; RETURN NEW; END \$fn\$",
        'DROP TRIGGER IF EXISTS order_tax_frozen_guard ON order_tax',
        'CREATE TRIGGER order_tax_frozen_guard BEFORE UPDATE ON order_tax FOR EACH ROW EXECUTE FUNCTION order_tax_frozen_guard()',
    ];

    public function getDescription(): string
    {
        return 'PAYS-1 : taxes gelées sur les commandes, taxes datées et archivables';
    }

    public function up(Schema $schema): void
    {
        foreach (self::STATEMENTS as $sql) {
            $this->addSql($sql);
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TRIGGER IF EXISTS order_tax_frozen_guard ON order_tax');
        $this->addSql('DROP FUNCTION IF EXISTS order_tax_frozen_guard()');
        foreach (['tax_label', 'tax_rate_ppm', 'tax_code', 'tax_jurisdiction', 'tax_category', 'prices_include_tax', 'tax_source'] as $c) {
            $this->addSql('ALTER TABLE order_tax DROP COLUMN IF EXISTS ' . $c);
        }
        foreach (['code', 'archived_at', 'valid_from', 'valid_to'] as $c) {
            $this->addSql('ALTER TABLE tax DROP COLUMN IF EXISTS ' . $c);
        }
    }
}
