<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Catalogue, lot CB-1 « code-barres de variante : remplir, rendre unique » (15/11/2026, décision 14 du propriétaire, prérequis du lot L2 de
 * la prévention des pertes ; `docs/codes-barres.md`). La règle : sur un site, un code-barres désigne UNE seule chose, un article ou une
 * variante. La base la garantit elle-même, quel que soit le chemin d'écriture :
 *
 * - le déclencheur `catalog_barcode_guard` met le code en forme canonique (espaces autour retirés, chaîne vide tenue pour « pas de code »,
 *   lettres en majuscules : « bar-1 » ne peut pas exister à côté de « BAR-1 »), refuse plus de 64 caractères, puis refuse un code déjà
 *   porté par l'AUTRE table (une variante ne porte ni le code d'un autre article, ni celui du sien) ;
 * - un index unique par table, sur les codes présents, tient l'unicité dans la table.
 * Le contrôle croisé se fait sous un verrou consultatif de transaction pris sur le code lui-même (les deux tables prennent le même verrou
 * pour le même code ; deux codes différents ne s'attendent pas). Il suppose le niveau d'isolation READ COMMITTED, celui de toutes les
 * écritures du catalogue : le verrou obtenu, la lecture de l'autre table voit ce que l'autre transaction vient de valider.
 *
 * La base garantit la structure (forme, longueur, unicité). Les formes réservées (préfixe `IZ-` des documents de la caisse, coupons de
 * fabricant) et les caractères permis sont une règle de métier, tenue par `App\Services\BarcodeService\CatalogBarcodeService`, qui met
 * aussi en forme AVANT d'écrire (Doctrine ne relit pas la ligne : le déclencheur n'est que le dernier rempart).
 *
 * `catalog_barcode_seq` numérote les codes générés (EAN-13 interne, préfixe 29 + 10 chiffres + clé de contrôle) : bornée à 10 chiffres,
 * sans retour au début ; un trou dans la suite est sans importance.
 *
 * Le code de la variante passe de 32 à 64 caractères (comme celui gardé sur les lignes de vente ; sans réécriture de la table). L'index
 * simple `idx_product_barcode` reste. La mise en forme de l'existant ne change rien aujourd'hui (état lu le 15/11/2026 : tous les codes sont
 * des chiffres, aucun doublon) ; le script des sites compte les doublons après mise en forme AVANT d'écrire et s'arrête s'il y en a.
 *
 * Additive, idempotente ; équivalent `scripts/migrate_all_v2_codes_barres.sh`.
 */
final class Version20270107100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Catalogue CB-1 : un code-barres désigne une seule chose (article ou variante), garanti par la base';
    }

    public function up(Schema $schema): void
    {
        foreach (self::STATEMENTS as $sql) {
            $this->addSql($sql);
        }
    }

    public function down(Schema $schema): void
    {
        foreach (self::DOWN_STATEMENTS as $sql) {
            $this->addSql($sql);
        }
    }

    public const STATEMENTS = [
        // Les déclencheurs d'abord retirés (recréés plus bas) : PostgreSQL refuse de changer le type d'une colonne nommée par un déclencheur
        // « UPDATE OF », même vers le même type ; sans cela, un second passage sur une base déjà migrée s'arrêterait à l'ALTER
        'DROP TRIGGER IF EXISTS product_barcode_guard ON product',
        'DROP TRIGGER IF EXISTS product_variant_barcode_guard ON product_variant',
        'ALTER TABLE product_variant ALTER COLUMN barcode TYPE VARCHAR(64)',
        // Mise en forme de l'existant (rien à changer aujourd'hui) : AVANT les index, pour que l'unicité porte sur la forme canonique
        "UPDATE product SET barcode = NULLIF(UPPER(BTRIM(barcode)), '') WHERE barcode IS NOT NULL AND barcode IS DISTINCT FROM NULLIF(UPPER(BTRIM(barcode)), '')",
        "UPDATE product_variant SET barcode = NULLIF(UPPER(BTRIM(barcode)), '') WHERE barcode IS NOT NULL AND barcode IS DISTINCT FROM NULLIF(UPPER(BTRIM(barcode)), '')",
        'CREATE SEQUENCE IF NOT EXISTS catalog_barcode_seq START 1 MINVALUE 1 MAXVALUE 9999999999 NO CYCLE',
        "CREATE OR REPLACE FUNCTION catalog_barcode_guard() RETURNS trigger LANGUAGE plpgsql AS \$fn\$ BEGIN"
        . " NEW.barcode := NULLIF(UPPER(BTRIM(NEW.barcode)), '');"
        . ' IF NEW.barcode IS NULL THEN RETURN NEW; END IF;'
        . " IF length(NEW.barcode) > 64 THEN RAISE EXCEPTION 'catalogue : code-barres de plus de 64 caractères' USING ERRCODE = 'string_data_right_truncation'; END IF;"
        . " IF TG_OP = 'UPDATE' AND NEW.barcode IS NOT DISTINCT FROM OLD.barcode THEN RETURN NEW; END IF;"
        . ' PERFORM pg_advisory_xact_lock(7461080, hashtext(NEW.barcode));'
        . " IF TG_TABLE_NAME = 'product' THEN"
        . " IF EXISTS (SELECT 1 FROM product_variant v WHERE v.barcode = NEW.barcode) THEN RAISE EXCEPTION 'catalogue : code-barres déjà porté par une variante' USING ERRCODE = 'unique_violation'; END IF;"
        . " ELSIF EXISTS (SELECT 1 FROM product p WHERE p.barcode = NEW.barcode) THEN RAISE EXCEPTION 'catalogue : code-barres déjà porté par un article' USING ERRCODE = 'unique_violation';"
        . ' END IF;'
        . " RETURN NEW; END \$fn\$",
        'CREATE TRIGGER product_barcode_guard BEFORE INSERT OR UPDATE OF barcode ON product FOR EACH ROW EXECUTE FUNCTION catalog_barcode_guard()',
        'CREATE TRIGGER product_variant_barcode_guard BEFORE INSERT OR UPDATE OF barcode ON product_variant FOR EACH ROW EXECUTE FUNCTION catalog_barcode_guard()',
        'CREATE UNIQUE INDEX IF NOT EXISTS uniq_product_barcode ON product (barcode) WHERE barcode IS NOT NULL',
        'CREATE UNIQUE INDEX IF NOT EXISTS uniq_product_variant_barcode ON product_variant (barcode) WHERE barcode IS NOT NULL',
    ];

    /** Retour arrière : la règle est retirée ; la colonne de la variante reste à 64 caractères (la rétrécir pourrait tronquer un code) */
    public const DOWN_STATEMENTS = [
        'DROP TRIGGER IF EXISTS product_variant_barcode_guard ON product_variant',
        'DROP TRIGGER IF EXISTS product_barcode_guard ON product',
        'DROP FUNCTION IF EXISTS catalog_barcode_guard()',
        'DROP INDEX IF EXISTS uniq_product_variant_barcode',
        'DROP INDEX IF EXISTS uniq_product_barcode',
        'DROP SEQUENCE IF EXISTS catalog_barcode_seq',
    ];
}
