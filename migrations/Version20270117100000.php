<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Lot IMP-3 (16/11/2026, `docs/import-boutique.md` § 10) : historique des commandes d'une boutique importée, en ARCHIVE À PART
 * (« ventes d'avant la migration »). Deux tables neuves, `import_order` et `import_order_line` : rien n'entre dans les commandes du
 * site, le journal de caisse, la numérotation des factures, les taxes, le stock, la fidélité ni le résultat des périodes.
 * - `import_order` : une commande d'avant (import qui l'a reprise, numéro d'origine unique par source, date, jour du magasin, état de
 *   paiement d'origine et état retenu, montants à titre d'information, montant net hors taxes et hors port qui entre dans la série,
 *   devise, compte du site rattaché par son adresse courriel). Aucune donnée personnelle n'y est recopiée ; `user_id` n'a PAS de clé
 *   étrangère : il est remis à vide quand le compte est effacé, la commande reste dans la série.
 * - `import_order_line` : ses lignes (nom de l'article tel que le fichier l'écrit, quantité, prix, article du site quand il se retrouve).
 * Additive, idempotente ; aucune donnée existante modifiée. Équivalent `scripts/migrate_all_v2_import_commandes.sh` (mêmes 7 instructions).
 */
final class Version20270117100000 extends AbstractMigration
{
    public const STATEMENTS = [
        "CREATE TABLE IF NOT EXISTS import_order (id SERIAL PRIMARY KEY, job_id INT NOT NULL, source VARCHAR(20) NOT NULL, ref VARCHAR(80) NOT NULL, ordered_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, day DATE NOT NULL, financial_status VARCHAR(30) NOT NULL DEFAULT '', state VARCHAR(12) NOT NULL, total_cents BIGINT NOT NULL DEFAULT 0, tax_cents BIGINT NOT NULL DEFAULT 0, shipping_cents BIGINT NOT NULL DEFAULT 0, refunded_cents BIGINT NOT NULL DEFAULT 0, net_cents BIGINT NOT NULL DEFAULT 0, currency VARCHAR(3) NOT NULL, user_id INT DEFAULT NULL, items_count INT NOT NULL DEFAULT 0, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT NOW(), CONSTRAINT chk_import_order_state CHECK (state IN ('paid', 'refunded', 'cancelled', 'unpaid')))",
        'CREATE TABLE IF NOT EXISTS import_order_line (id SERIAL PRIMARY KEY, order_id INT NOT NULL REFERENCES import_order (id) ON DELETE CASCADE, position INT NOT NULL, name VARCHAR(255) NOT NULL, quantity INT NOT NULL DEFAULT 1, unit_price_cents BIGINT NOT NULL DEFAULT 0, product_id INT DEFAULT NULL)',
        'CREATE UNIQUE INDEX IF NOT EXISTS uniq_import_order_ref ON import_order (source, ref)',
        "CREATE INDEX IF NOT EXISTS idx_import_order_day ON import_order (day) WHERE state = 'paid'",
        'CREATE INDEX IF NOT EXISTS idx_import_order_user ON import_order (user_id) WHERE user_id IS NOT NULL',
        'CREATE INDEX IF NOT EXISTS idx_import_order_job ON import_order (job_id)',
        'CREATE INDEX IF NOT EXISTS idx_import_order_line_order ON import_order_line (order_id)',
    ];

    public function getDescription(): string
    {
        return 'Lot IMP-3 : commandes d\'une boutique importée, en archive à part (import_order, import_order_line)';
    }

    public function up(Schema $schema): void
    {
        foreach (self::STATEMENTS as $sql) {
            $this->addSql($sql);
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS import_order_line');
        $this->addSql('DROP TABLE IF EXISTS import_order');
    }
}
