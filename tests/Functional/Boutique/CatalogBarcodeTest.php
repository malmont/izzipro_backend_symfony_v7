<?php

namespace App\Tests\Functional\Boutique;

use App\Entity\Color;
use App\Entity\Product;
use App\Entity\ProductVariant;
use App\Entity\Size;
use App\Services\BarcodeService\CatalogBarcode;
use App\Services\BarcodeService\CatalogBarcodeException;
use App\Services\BarcodeService\CatalogBarcodeService;
use App\Services\TenantEntityManagerProvider;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Codes-barres du catalogue, lot CB-1 (15/11/2026, `docs/codes-barres.md`) : sur un site, un code désigne UNE seule chose, un article ou
 * une variante.
 *
 * Deux étages, vérifiés séparément :
 * - la BASE seule (déclencheur `catalog_barcode_guard`, index uniques), par des écritures SQL qui ne passent par aucun code de
 *   l'application : forme canonique, quatre croisements, casse et espaces, longueur, écritures simultanées sur les deux tables, code
 *   libéré avec sa ligne, rejeu de la migration ;
 * - le SERVICE (`CatalogBarcodeService`) : refus clairs avant d'écrire, formes réservées à la caisse, génération qui n'écrase jamais.
 */
class CatalogBarcodeTest extends KernelTestCase
{
    private Connection $db;
    private EntityManagerInterface $em;
    private CatalogBarcodeService $barcodes;
    private string $suffix;
    /** @var array<string, array<int, string>> codes présents avant le test, par table */
    private array $codesBefore = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $this->em = $provider->getEntityManager();
        $this->db = $this->em->getConnection();
        // Le service du conteneur, avec sa vraie file d'invalidation du cache (les écrans l'injectent : il n'est pas retiré)
        $this->barcodes = static::getContainer()->get(CatalogBarcodeService::class);
        $this->suffix = bin2hex(random_bytes(3));
        // La base de test est partagée : les codes déjà là sont mis de côté le temps du test, puis remis (tearDown)
        $this->codesBefore = [
            'product' => $this->db->fetchAllKeyValue('SELECT id, barcode FROM product WHERE barcode IS NOT NULL'),
            'product_variant' => $this->db->fetchAllKeyValue('SELECT id, barcode FROM product_variant WHERE barcode IS NOT NULL'),
        ];
        $this->clearCodes();
    }

    protected function tearDown(): void
    {
        if ($this->db->isTransactionActive()) {
            $this->db->rollBack();
        }
        // D'autres tests comptent les articles : ceux du test (et leurs copies, qui portent le même nom) sont effacés, avec leurs variantes
        $ids = $this->db->fetchFirstColumn('SELECT id FROM product WHERE name LIKE ?', ['% ' . $this->suffix]);
        if ($ids !== []) {
            $this->db->executeStatement('DELETE FROM product_variant WHERE product_id IN (?)', [$ids], [ArrayParameterType::INTEGER]);
            $this->db->executeStatement('DELETE FROM product WHERE id IN (?)', [$ids], [ArrayParameterType::INTEGER]);
        }
        $this->db->executeStatement('DELETE FROM size WHERE code = ?', ['cb-m-' . $this->suffix]);
        $this->db->executeStatement('DELETE FROM color WHERE code = ?', ['cb-rouge-' . $this->suffix]);
        // Les codes des autres tests sont remis tels qu'ils étaient
        $this->clearCodes();
        foreach ($this->codesBefore as $table => $codes) {
            foreach ($codes as $id => $code) {
                $this->db->executeStatement("UPDATE $table SET barcode = ? WHERE id = ?", [$code, $id]);
            }
        }
        parent::tearDown();
    }

    public function testCanonicalFormAcceptedCharactersReservedShapesAndTheGeneratedCode(): void
    {
        foreach ([[null, null], ['', null], ["  \t ", null], [' abc-1 ', 'ABC-1'], ['x1', 'X1'], ["4006381333931\r\n", '4006381333931'], ['A B', 'A B']] as [$raw, $expected]) {
            $this->assertSame($expected, CatalogBarcode::normalize($raw), var_export($raw, true));
        }
        foreach ([
            'A' => null, 'A/B.C_D-1' => null, '4006381333931' => null, str_repeat('A', 64) => null, 'BAR-0A1B2C3D4E5F' => null,
            str_repeat('A', 65) => CatalogBarcode::INVALID, 'A B' => CatalogBarcode::INVALID, 'ÉTÉ-1' => CatalogBarcode::INVALID, 'A+B' => CatalogBarcode::INVALID,
            "A\nB" => CatalogBarcode::INVALID, 'abc' => CatalogBarcode::INVALID, // « abc » : la règle se lit sur la forme canonique, jamais sur le texte brut
            'IZ-T-1-000042' => CatalogBarcode::RESERVED, 'IZ-' => CatalogBarcode::RESERVED,
            '512345678901' => CatalogBarcode::RESERVED, // coupon de fabricant, ancien UPC-A à système 5
            '412345678901' => null, '5123456789012' => null, // 12 chiffres qui ne commencent pas par 5 ; 13 chiffres qui commencent par 5 : permis
            '8110' . str_repeat('1', 20) => CatalogBarcode::RESERVED, '8110' . str_repeat('1', 40) => CatalogBarcode::RESERVED,
            '8110' . str_repeat('1', 19) => null, '9110' . str_repeat('1', 20) => null,
        ] as $code => $expected) {
            $this->assertSame($expected, CatalogBarcode::refusal((string) $code), (string) $code);
        }
        $this->assertSame('2900000000018', CatalogBarcode::generated(1));
        $this->assertSame('29' . '9999999999' . CatalogBarcode::checkDigit('299999999999'), CatalogBarcode::generated(9999999999));
        foreach ([1, 7, 42, 1234567890, 9999999999] as $n) {
            $code = CatalogBarcode::generated($n);
            $this->assertTrue(strlen($code) === 13 && str_starts_with($code, '29') && CatalogBarcode::isEan13($code), $code);
            $this->assertNull(CatalogBarcode::refusal($code), 'un code généré est toujours acceptable');
        }
        foreach ([0, -1, 10000000000] as $n) {
            try {
                CatalogBarcode::generated($n);
                $this->fail("numéro $n : hors de la plage");
            } catch (\OutOfRangeException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame([true, false, false, true, false], [CatalogBarcode::isEan13('4006381333931'), CatalogBarcode::isEan13('4006381333932'),
            CatalogBarcode::hasWrongCheckDigit('4006381333931'), CatalogBarcode::hasWrongCheckDigit('4006381333932'), CatalogBarcode::hasWrongCheckDigit('400638133393')]);
        $this->assertNull($this->barcodes->warning('4006381333931'));
        $this->assertNull($this->barcodes->warning(null));
        $this->assertStringContainsString('Code 128', (string) $this->barcodes->warning('4006381333932'));
    }

    public function testTheObjectAndTheRowHoldTheSameCanonicalFormWhateverThePath(): void
    {
        $product = $this->article('Forme', 1);
        $variant = $product->getVariants()->first();

        // Par les entités : en mémoire ET en base, la même forme, sans relire la ligne
        $product->setBarcode('  abc-' . $this->suffix . ' ');
        $variant->setBarcode('x' . $this->suffix);
        $this->em->flush();
        $expected = ['ABC-' . strtoupper($this->suffix), 'X' . strtoupper($this->suffix)];
        $this->assertSame($expected, [$product->getBarcode(), $variant->getBarcode()], 'en mémoire');
        $this->assertSame($expected, [$this->codeOf('product', $product->getId()), $this->codeOf('product_variant', $variant->getId())], 'en base');
        $product->setBarcode('   ');
        $variant->setBarcode('');
        $this->em->flush();
        $this->assertSame([null, null, null, null], [$product->getBarcode(), $variant->getBarcode(), $this->codeOf('product', $product->getId()), $this->codeOf('product_variant', $variant->getId())],
            'une chaîne vide ou d\'espaces vaut « pas de code »');

        // Par du SQL qui ne passe par aucun code de l'application : la base met en forme elle-même (mise à jour)
        $this->db->executeStatement("UPDATE product SET barcode = '  def-{$this->suffix} ' WHERE id = ?", [$product->getId()]);
        $this->db->executeStatement("UPDATE product_variant SET barcode = 'y{$this->suffix}' WHERE id = ?", [$variant->getId()]);
        $this->assertSame(['DEF-' . strtoupper($this->suffix), 'Y' . strtoupper($this->suffix)], [$this->codeOf('product', $product->getId()), $this->codeOf('product_variant', $variant->getId())]);
        $this->db->executeStatement("UPDATE product SET barcode = '   ' WHERE id = ?", [$product->getId()]);
        $this->assertNull($this->codeOf('product', $product->getId()));

        // … et à l'insertion (lignes copiées telles quelles, seul le code change)
        $newVariant = $this->copyVariant((int) $variant->getId(), '  ins-' . $this->suffix . ' ');
        $this->assertSame('INS-' . strtoupper($this->suffix), $this->codeOf('product_variant', $newVariant));
        $newProduct = $this->copyProduct((int) $product->getId(), ' pins-' . $this->suffix);
        $this->assertSame('PINS-' . strtoupper($this->suffix), $this->codeOf('product', $newProduct));

        // 64 caractères au plus, sur les deux tables ; la variante en tient bien 64 (32 avant le lot)
        $long = str_repeat('L', 58) . strtoupper($this->suffix);
        $this->db->executeStatement('UPDATE product_variant SET barcode = ? WHERE id = ?', [$long, $variant->getId()]);
        $this->assertSame($long, $this->codeOf('product_variant', $variant->getId()));
        foreach (['product' => $product->getId(), 'product_variant' => $newVariant] as $table => $id) {
            $message = $this->refused(fn () => $this->db->executeStatement("UPDATE $table SET barcode = ? WHERE id = ?", [str_repeat('M', 65), $id]));
            // l'article (colonne de 255) est refusé par le déclencheur ; la variante, par sa colonne de 64 avant même le déclencheur
            $this->assertMatchesRegularExpression('/plus de 64 caractères|too long|trop longue/', $message, $table);
            $this->assertNotSame(str_repeat('M', 65), $this->codeOf($table, $id));
        }
    }

    public function testTheDatabaseAloneRefusesEveryCrossingEvenByCaseOrSpaces(): void
    {
        $a = $this->article('Croisement A', 2);
        $b = $this->article('Croisement B', 1);
        [$a1, $a2] = array_map(fn (ProductVariant $v) => (int) $v->getId(), $a->getVariants()->toArray());
        $b1 = (int) $b->getVariants()->first()->getId();
        $s = strtoupper($this->suffix);
        $set = fn (string $table, int $id, ?string $code) => $this->db->executeStatement("UPDATE $table SET barcode = ? WHERE id = ?", [$code, $id]);
        $set('product', $a->getId(), "ART-$s");
        $set('product_variant', $a1, "VAR-$s");

        $cases = [
            'deux articles' => ['product', $b->getId(), "ART-$s", 'uniq_product_barcode'],
            'deux variantes du même article' => ['product_variant', $a2, "VAR-$s", 'uniq_product_variant_barcode'],
            'deux variantes de deux articles' => ['product_variant', $b1, "VAR-$s", 'uniq_product_variant_barcode'],
            'une variante et un autre article' => ['product_variant', $b1, "ART-$s", 'déjà porté par un article'],
            'une variante et son propre article' => ['product_variant', $a2, "ART-$s", 'déjà porté par un article'],
            'un article et la variante d\'un autre' => ['product', $b->getId(), "VAR-$s", 'déjà porté par une variante'],
            'un article et sa propre variante' => ['product', $a->getId(), "VAR-$s", 'déjà porté par une variante'],
        ];
        foreach ($cases as $what => [$table, $id, $code, $expected]) {
            $before = $this->codeOf($table, $id);
            foreach (['tel quel' => $code, 'en minuscules' => strtolower($code), 'entouré d\'espaces' => "  $code ", 'minuscules et espaces' => ' ' . strtolower($code) . '  '] as $how => $written) {
                $message = $this->refused(fn () => $set($table, $id, $written));
                $this->assertStringContainsString($expected, $message, "$what, $how");
                $this->assertSame($before, $this->codeOf($table, $id), "$what, $how : rien n'est écrit");
            }
        }
        // À l'insertion aussi (lignes copiées, seul le code change)
        $this->assertStringContainsString('déjà porté par un article', $this->refused(fn () => $this->copyVariant($b1, strtolower("ART-$s"))));
        $this->assertStringContainsString('uniq_product_variant_barcode', $this->refused(fn () => $this->copyVariant($b1, " var-" . strtolower($s))));
        $this->assertStringContainsString('déjà porté par une variante', $this->refused(fn () => $this->copyProduct((int) $b->getId(), strtolower("VAR-$s"))));
        $this->assertStringContainsString('uniq_product_barcode', $this->refused(fn () => $this->copyProduct((int) $b->getId(), "ART-$s ")));

        // Réécrire son propre code (même sous une autre casse) n'est pas un doublon ; plusieurs lignes sans code non plus
        $set('product', $a->getId(), strtolower("ART-$s"));
        $set('product_variant', $a1, " VAR-$s ");
        $this->assertSame(["ART-$s", "VAR-$s"], [$this->codeOf('product', $a->getId()), $this->codeOf('product_variant', $a1)]);
        $set('product_variant', $a2, null);
        $set('product_variant', $b1, '');
        $set('product', $b->getId(), null);
        $this->assertSame([null, null, null], [$this->codeOf('product_variant', $a2), $this->codeOf('product_variant', $b1), $this->codeOf('product', $b->getId())]);

        // Un code est réservé tant que sa ligne existe : la ligne effacée (ou le code retiré), il redevient libre
        $spare = $this->copyVariant($b1, "LIBRE-$s");
        $this->assertStringContainsString('déjà porté par une variante', $this->refused(fn () => $set('product', $b->getId(), "LIBRE-$s")));
        $this->db->executeStatement('DELETE FROM product_variant WHERE id = ?', [$spare]);
        $set('product', $b->getId(), "LIBRE-$s");
        $this->assertSame("LIBRE-$s", $this->codeOf('product', $b->getId()));
        $set('product', $a->getId(), null);
        $set('product_variant', $b1, "ART-$s");
        $this->assertSame("ART-$s", $this->codeOf('product_variant', $b1), 'le code retiré de l\'article passe à une variante');
    }

    public function testTwoSimultaneousWritesOfTheSameCodeOnTheTwoTablesCannotBothPass(): void
    {
        $a = $this->article('Course A', 0);
        $b = $this->article('Course B', 1);
        $variant = (int) $b->getVariants()->first()->getId();
        $s = strtoupper($this->suffix);
        $other = DriverManager::getConnection($this->db->getParams());
        try {
            $other->executeStatement("SET lock_timeout = '400ms'");
            foreach ([
                'article d\'abord' => [fn (Connection $c, ?string $code) => $c->executeStatement('UPDATE product SET barcode = ? WHERE id = ?', [$code, $a->getId()]),
                    fn (Connection $c, ?string $code) => $c->executeStatement('UPDATE product_variant SET barcode = ? WHERE id = ?', [$code, $variant]), 'déjà porté par un article'],
                'variante d\'abord' => [fn (Connection $c, ?string $code) => $c->executeStatement('UPDATE product_variant SET barcode = ? WHERE id = ?', [$code, $variant]),
                    fn (Connection $c, ?string $code) => $c->executeStatement('UPDATE product SET barcode = ? WHERE id = ?', [$code, $a->getId()]), 'déjà porté par une variante'],
            ] as $what => [$first, $second, $expected]) {
                $code = 'COURSE-' . $s . '-' . strlen($what);
                $this->db->beginTransaction();
                $first($this->db, $code); // écrit, pas encore validé : l'autre connexion ne le voit pas
                // L'autre table, même code sous une autre casse : attend le verrou du code au lieu de passer sans rien voir
                $message = $this->refused(fn () => $second($other, strtolower($code)));
                $this->assertStringContainsString('lock timeout', strtolower($message), "$what : la seconde écriture attend la première");
                // Un AUTRE code ne s'attend pas
                $second($other, 'AUTRE-' . $s);
                $second($other, null);
                $this->db->commit();
                // La première validée : la seconde est refusée par la règle, pas écrite
                $this->assertStringContainsString($expected, $this->refused(fn () => $second($other, strtolower($code))), $what);
                $this->assertSame(1, (int) $this->db->fetchOne('SELECT (SELECT COUNT(*) FROM product WHERE barcode = :c) + (SELECT COUNT(*) FROM product_variant WHERE barcode = :c)', ['c' => $code]), $what);
                $this->db->executeStatement('UPDATE product SET barcode = NULL WHERE id = ?', [$a->getId()]);
                $this->db->executeStatement('UPDATE product_variant SET barcode = NULL WHERE id = ?', [$variant]);
            }
            // Première écriture annulée : la seconde passe
            $this->db->beginTransaction();
            $this->db->executeStatement('UPDATE product SET barcode = ? WHERE id = ?', ["ANNULE-$s", $a->getId()]);
            $this->db->rollBack();
            $other->executeStatement('UPDATE product_variant SET barcode = ? WHERE id = ?', [strtolower("ANNULE-$s"), $variant]);
            $this->assertSame([null, "ANNULE-$s"], [$this->codeOf('product', $a->getId()), $this->codeOf('product_variant', $variant)]);
        } finally {
            if ($this->db->isTransactionActive()) {
                $this->db->rollBack();
            }
            $other->close();
        }
    }

    public function testTheMigrationReplaysOnABaseThatAlreadyHasItAndItsRollbackRemovesTheRule(): void
    {
        $product = $this->article('Rejeu', 1);
        $variant = (int) $product->getVariants()->first()->getId();
        $s = strtoupper($this->suffix);
        $this->db->executeStatement('UPDATE product SET barcode = ? WHERE id = ?', ["REJEU-$s", $product->getId()]);
        $this->db->executeStatement('UPDATE product_variant SET barcode = ? WHERE id = ?', ["REJEU-V-$s", $variant]);
        $schema = fn (): array => [
            $this->db->fetchFirstColumn("SELECT tgname FROM pg_trigger WHERE tgname LIKE '%barcode_guard' AND NOT tgisinternal ORDER BY tgname"),
            $this->db->fetchFirstColumn("SELECT indexname FROM pg_indexes WHERE indexname IN ('uniq_product_barcode', 'uniq_product_variant_barcode', 'idx_product_barcode') ORDER BY indexname"),
            (int) $this->db->fetchOne("SELECT character_maximum_length FROM information_schema.columns WHERE table_name = 'product_variant' AND column_name = 'barcode'"),
            $this->db->fetchAssociative("SELECT minimum_value, maximum_value, cycle_option FROM information_schema.sequences WHERE sequence_name = 'catalog_barcode_seq'"),
            (int) $this->db->fetchOne("SELECT COUNT(*) FROM pg_proc WHERE proname = 'catalog_barcode_guard'"),
        ];
        $data = fn (): array => [
            $this->db->fetchAllKeyValue('SELECT id, barcode FROM product WHERE barcode IS NOT NULL ORDER BY id'),
            $this->db->fetchAllKeyValue('SELECT id, barcode FROM product_variant WHERE barcode IS NOT NULL ORDER BY id'),
        ];
        $schemaBefore = $schema();
        $dataBefore = $data();
        $this->assertSame([['product_barcode_guard', 'product_variant_barcode_guard'], ['idx_product_barcode', 'uniq_product_barcode', 'uniq_product_variant_barcode'], 64,
            ['minimum_value' => '1', 'maximum_value' => '9999999999', 'cycle_option' => 'NO'], 1], $schemaBefore, 'la base de test porte la migration');
        $drawn = (int) $this->db->fetchOne("SELECT nextval('catalog_barcode_seq')");

        // 1. Rejeu, deux fois de suite, sur une base où les déclencheurs existent déjà (le cas qui cassait : le type d'une colonne nommée
        //    par un déclencheur ne se change pas) : ni le schéma ni les données ne bougent, la séquence ne repart pas du début
        require_once dirname(__DIR__, 3) . '/migrations/Version20270107100000.php';
        foreach ([1, 2] as $pass) {
            try {
                foreach (\DoctrineMigrations\Version20270107100000::STATEMENTS as $sql) {
                    $this->db->executeStatement($sql);
                }
            } catch (\Throwable $e) {
                // Le rejeu se fait hors transaction : s'il casse en route, la base de test resterait sans déclencheurs pour toute la
                // suite. Elle est remise d'aplomb (déclencheurs et index reposés) avant de rendre l'erreur
                foreach (\DoctrineMigrations\Version20270107100000::STATEMENTS as $sql) {
                    try {
                        $this->db->executeStatement($sql);
                    } catch (\Throwable) {
                    }
                }
                throw $e;
            }
            $this->assertSame([$schemaBefore, $dataBefore], [$schema(), $data()], "rejeu $pass");
            $this->assertStringContainsString('déjà porté par un article', $this->refused(fn () => $this->db->executeStatement('UPDATE product_variant SET barcode = ? WHERE id = ?', [strtolower("REJEU-$s"), $variant])), "rejeu $pass : la règle tient");
        }
        $this->assertGreaterThan($drawn, (int) $this->db->fetchOne("SELECT nextval('catalog_barcode_seq')"), 'un numéro tiré ne revient pas après un rejeu');

        // 2. Le retour arrière, joué dans une transaction puis annulé (la base de test retrouve la migration) : la règle part, la colonne
        //    de la variante reste à 64 caractères (la rétrécir pourrait couper un code), les codes ne changent pas
        $this->db->beginTransaction();
        try {
            foreach (\DoctrineMigrations\Version20270107100000::DOWN_STATEMENTS as $sql) {
                $this->db->executeStatement($sql);
            }
            $this->assertSame([[], ['idx_product_barcode'], 64, false, 0], $schema());
            $this->assertSame($dataBefore, $data());
            $this->db->executeStatement('UPDATE product_variant SET barcode = ? WHERE id = ?', [strtolower("REJEU-$s"), $variant]);
            $this->assertSame(strtolower("REJEU-$s"), $this->codeOf('product_variant', $variant), 'sans la règle, la base ne met plus en forme et ne refuse plus');
        } finally {
            $this->db->rollBack();
        }
        $this->assertSame([$schemaBefore, $dataBefore], [$schema(), $data()], 'retour arrière annulé : la base de test est intacte');
    }

    public function testTheServiceRefusesClearlyBeforeWriting(): void
    {
        $size = (new Size())->setName('M')->setCode('cb-m-' . $this->suffix);
        $color = (new Color())->setName('Rouge')->setCode('cb-rouge-' . $this->suffix)->setCodeHexa('#cc0000');
        $this->em->persist($size);
        $this->em->persist($color);
        $cap = $this->article('Casquette', 3);
        [$sized, $coloured, $plain] = $cap->getVariants()->toArray();
        $sized->setSize($size);
        $coloured->setSize($size)->setColor($color);
        $bottle = $this->article('Gourde', 0);
        $s = strtoupper($this->suffix);
        $cap->setBarcode("CAP-$s");
        $sized->setBarcode("CAP-M-$s");
        $coloured->setBarcode("CAP-RM-$s");
        $plain->setBarcode("CAP-X-$s");
        $this->em->flush();

        // Pas de code : rien à vérifier
        $this->assertSame([null, null, null], [$this->barcodes->check(null), $this->barcodes->check(''), $this->barcodes->check('   ', $bottle)]);
        // Un code libre revient en forme canonique ; son propre code ne gêne pas la fiche qui le porte
        $this->assertSame("NEUF-$s", $this->barcodes->check(' neuf-' . strtolower($s) . ' ', $bottle));
        $this->assertSame("CAP-$s", $this->barcodes->check(strtolower("CAP-$s"), $cap));
        $this->assertSame("CAP-M-$s", $this->barcodes->check("CAP-M-$s", $sized));
        $this->assertSame("NEUF-$s", $this->barcodes->normalize(' neuf-' . strtolower($s)));

        // Forme et formes réservées : refus avant toute lecture
        foreach (['A B' => CatalogBarcode::INVALID, 'é1' => CatalogBarcode::INVALID, str_repeat('a', 65) => CatalogBarcode::INVALID,
            'iz-t-1-000042' => CatalogBarcode::RESERVED, ' 512345678901 ' => CatalogBarcode::RESERVED, '8110' . str_repeat('7', 24) => CatalogBarcode::RESERVED] as $raw => $expected) {
            $e = $this->refusal(fn () => $this->barcodes->check((string) $raw, $bottle));
            $this->assertSame([$expected, null], [$e->errorCode, $e->holder], (string) $raw);
            $this->assertSame($expected, $this->refusal(fn () => $this->barcodes->validate((string) $raw))->errorCode);
        }
        $this->assertStringContainsString('caisse', $this->refusal(fn () => $this->barcodes->validate('IZ-G-1'))->getMessage());

        // Code déjà porté : le refus dit par quoi, sous toutes les casses, pour les quatre croisements
        $holders = [
            "CAP-$s" => ['type' => 'product', 'id' => $cap->getId(), 'productId' => $cap->getId(), 'label' => $cap->getName()],
            "CAP-M-$s" => ['type' => 'variant', 'id' => $sized->getId(), 'productId' => $cap->getId(), 'label' => $cap->getName() . ', M'],
            "CAP-RM-$s" => ['type' => 'variant', 'id' => $coloured->getId(), 'productId' => $cap->getId(), 'label' => $cap->getName() . ', Rouge / M'],
            "CAP-X-$s" => ['type' => 'variant', 'id' => $plain->getId(), 'productId' => $cap->getId(), 'label' => $cap->getName() . ', variante n° ' . $plain->getId()],
        ];
        foreach ($holders as $code => $holder) {
            foreach ([null, $bottle, $cap, $sized, $coloured, $plain] as $for) {
                $own = $for !== null && $holder['id'] === $for->getId() && $holder['type'] === ($for instanceof Product ? 'product' : 'variant');
                if ($own) {
                    $this->assertNull($this->barcodes->holder($code, $for));
                    continue;
                }
                $e = $this->refusal(fn () => $this->barcodes->check(' ' . strtolower($code), $for));
                $this->assertSame([CatalogBarcodeService::ALREADY_USED, $holder], [$e->errorCode, $e->holder], $code);
                $this->assertSame(sprintf('Ce code-barres est déjà celui de « %s ».', $holder['label']), $e->getMessage());
                $this->assertSame($holder, $this->barcodes->holder($code, $for));
            }
        }
        $this->assertNull($this->barcodes->holder("NEUF-$s"));
        // Une fiche pas encore enregistrée (création) n'écarte rien
        $this->assertSame(CatalogBarcodeService::ALREADY_USED, $this->refusal(fn () => $this->barcodes->check("CAP-$s", new ProductVariant()))->errorCode);
        // Un identifiant de variante égal à celui d'un article ne fait pas passer le code de l'article pour le sien : le cas est
        // fabriqué (une variante copiée sous l'identifiant de la gourde), jamais laissé au hasard des identifiants de la base
        $twinId = (int) $bottle->getId();
        if ($this->db->fetchOne('SELECT 1 FROM product_variant WHERE id = ?', [$twinId]) === false) {
            $this->copyVariant((int) $plain->getId(), null, $twinId);
        }
        $this->db->executeStatement('UPDATE product SET barcode = ? WHERE id = ?', ["JUMEAU-$s", $twinId]);
        $this->assertSame(['product', $twinId], array_values(array_intersect_key((array) $this->barcodes->holder("JUMEAU-$s", $this->em->getReference(ProductVariant::class, $twinId)), ['type' => 1, 'id' => 1])),
            'la variante de même identifiant n\'est pas « elle-même »');
        $this->assertNull($this->barcodes->holder("JUMEAU-$s", $bottle));
        if ((int) $this->db->fetchOne('SELECT product_id FROM product_variant WHERE id = ?', [$twinId]) === (int) $cap->getId()) {
            $this->db->executeStatement('DELETE FROM product_variant WHERE id = ?', [$twinId]); // la copie du test : la gourde reste sans variante
        }
    }

    public function testAGeneratedCodeIsAnInternalEan13ThatNeverComesBackAndSkipsATakenOne(): void
    {
        $first = $this->barcodes->generate();
        $this->assertMatchesRegularExpression('/\A29\d{11}\z/', $first);
        $this->assertTrue(CatalogBarcode::isEan13($first));
        $number = (int) substr($first, 2, 10);
        $this->assertSame(CatalogBarcode::generated($number + 1), $this->barcodes->generate(), 'le suivant de la séquence');
        // Le code du numéro suivant existe déjà, saisi à la main sur un article ; le suivant encore, sur une variante : le générateur les saute
        $product = $this->article('Saisi à la main', 1);
        $this->db->executeStatement('UPDATE product SET barcode = ? WHERE id = ?', [CatalogBarcode::generated($number + 2), $product->getId()]);
        $this->db->executeStatement('UPDATE product_variant SET barcode = ? WHERE id = ?', [CatalogBarcode::generated($number + 3), $product->getVariants()->first()->getId()]);
        $this->assertSame(CatalogBarcode::generated($number + 4), $this->barcodes->generate());
        $this->assertNull($this->barcodes->holder($first), 'générer n\'écrit rien');
    }

    public function testGeneratingForOneSheetNeverOverwritesAndGoesToTheVariant(): void
    {
        $cap = $this->article('Casquette générée', 2);
        [$v1, $v2] = $cap->getVariants()->toArray();
        $bottle = $this->article('Gourde générée', 0);

        $code = $this->barcodes->generateFor($v1);
        $this->assertTrue(CatalogBarcode::isEan13($code) && str_starts_with($code, '29'));
        $this->assertSame([$code, $code], [$v1->getBarcode(), $this->codeOf('product_variant', $v1->getId())]);
        // Une seconde demande ne renumérote pas
        $this->assertSame(CatalogBarcodeService::ALREADY_SET, $this->refusal(fn () => $this->barcodes->generateFor($v1))->errorCode);
        $this->assertSame($code, $this->codeOf('product_variant', $v1->getId()));

        // Un code posé entre-temps par un autre chemin (l'objet en mémoire n'en sait rien) n'est pas écrasé : c'est la base qui est relue
        $s = strtoupper($this->suffix);
        $this->db->executeStatement('UPDATE product_variant SET barcode = ? WHERE id = ?', ["FABRICANT-$s", $v2->getId()]);
        $this->assertNull($v2->getBarcode());
        $this->assertSame(CatalogBarcodeService::ALREADY_SET, $this->refusal(fn () => $this->barcodes->generateFor($v2))->errorCode);
        $this->assertSame("FABRICANT-$s", $this->codeOf('product_variant', $v2->getId()));
        $this->assertFalse($this->db->isTransactionActive(), 'le refus referme sa transaction');

        // L'article qui a des variantes ne reçoit pas de code généré ; celui qui n'en a pas en reçoit un
        $this->assertSame(CatalogBarcodeService::HAS_VARIANTS, $this->refusal(fn () => $this->barcodes->generateFor($cap))->errorCode);
        $this->assertNull($this->codeOf('product', $cap->getId()));
        $bottleCode = $this->barcodes->generateFor($bottle);
        $this->assertSame([$bottleCode, $bottleCode], [$bottle->getBarcode(), $this->codeOf('product', $bottle->getId())]);
        $this->assertNotSame($code, $bottleCode);
        $this->assertSame(CatalogBarcodeService::ALREADY_SET, $this->refusal(fn () => $this->barcodes->generateFor($bottle))->errorCode);

        // Deux demandes simultanées pour la même fiche : la première (un autre processus, sa propre connexion) verrouille la ligne, écrit
        // son code et ne valide qu'une seconde et demie plus tard ; la seconde ATTEND la ligne, puis voit le code de la première et
        // refuse. Sans le verrou pris avant la lecture, elle lirait « pas de code » et renuméroterait par-dessus.
        $v3 = (new ProductVariant())->setStockQuantity(1);
        $cap->addVariant($v3);
        $this->em->flush();
        $first = "PREMIER-$s";
        $params = $this->db->getParams();
        $child = proc_open([PHP_BINARY, '-r', <<<'PHP'
            $pdo = new PDO(getenv('CB_DSN'), getenv('CB_USER'), getenv('CB_PASS'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $pdo->beginTransaction();
            $pdo->query('SELECT barcode FROM product_variant WHERE id = ' . (int) getenv('CB_ID') . ' FOR NO KEY UPDATE')->fetchAll();
            $pdo->prepare('UPDATE product_variant SET barcode = ? WHERE id = ?')->execute([getenv('CB_CODE'), (int) getenv('CB_ID')]);
            echo "tenu\n";
            usleep(1500000);
            $pdo->commit();
            echo "valide\n";
            PHP], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, [
            'CB_DSN' => sprintf('pgsql:host=%s;port=%d;dbname=%s', $params['host'] ?? 'localhost', (int) ($params['port'] ?? 5432), $params['dbname']),
            'CB_USER' => (string) ($params['user'] ?? ''), 'CB_PASS' => (string) ($params['password'] ?? ''), 'CB_ID' => (string) $v3->getId(), 'CB_CODE' => $first,
        ]);
        $this->assertIsResource($child);
        // Rien ici ne peut se bloquer (le verrou commun des tests arrêterait toutes les sessions) : lecture de la sortie du premier
        // processus avec un délai, attente de verrou bornée pour la seconde demande, et le processus est arrêté quoi qu'il arrive
        $readLine = static function ($pipe, int $seconds): string|false {
            $read = [$pipe];
            $write = $except = null;

            return stream_select($read, $write, $except, $seconds) > 0 ? fgets($pipe) : false;
        };
        try {
            $line = $readLine($pipes[1], 15); // rend la main dès que le premier processus tient la ligne (sa sortie d'erreur n'est lue qu'en cas d'échec : la lire avant attendrait sa fin)
            if ($line !== "tenu\n") {
                proc_terminate($child);
                $this->fail('le premier processus ne tient pas la ligne : ' . var_export($line, true) . ' ' . stream_get_contents($pipes[2]));
            }
            $this->db->executeStatement("SET lock_timeout = '10s'");
            $started = microtime(true);
            $refused = $this->refusal(fn () => $this->barcodes->generateFor($v3));
            $waited = microtime(true) - $started;
            $this->assertSame(CatalogBarcodeService::ALREADY_SET, $refused->errorCode);
            $this->assertGreaterThan(0.8, $waited, 'la seconde demande a attendu la validation de la première');
            $this->assertSame("valide\n", $readLine($pipes[1], 15));
        } finally {
            proc_terminate($child);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($child);
            if ($this->db->isTransactionActive()) {
                $this->db->rollBack();
            }
            $this->db->executeStatement('SET lock_timeout = 0');
        }
        $this->assertSame($first, $this->codeOf('product_variant', $v3->getId()), 'le code de la première demande n\'est pas renuméroté');
        $this->assertFalse($this->db->isTransactionActive());

        // Une panne pendant la génération d'un bouton (ici : la séquence a disparu) revient en message neutre, jamais en exception
        $v4 = (new ProductVariant())->setStockQuantity(1);
        $cap->addVariant($v4);
        $this->em->flush();
        $this->db->beginTransaction();
        try {
            $this->db->executeStatement('ALTER SEQUENCE catalog_barcode_seq RENAME TO catalog_barcode_seq_ailleurs');
            $failed = $this->barcodes->tryGenerateFor($v4);
            $this->assertSame([null, true], [$failed['code'], $failed['failed']]);
            $this->assertStringNotContainsStringIgnoringCase('sequence', (string) $failed['refusal']);
            $this->assertStringNotContainsStringIgnoringCase('sqlstate', (string) $failed['refusal']);
        } finally {
            $this->db->rollBack();
        }
        $this->assertNull($this->codeOf('product_variant', $v4->getId()));
        // … un refus de la règle revient tel quel, et un succès rend le code
        $this->assertSame(['code' => null, 'refusal' => 'Cette fiche porte déjà un code-barres : un code saisi n\'est jamais remplacé par un code généré.', 'failed' => false], $this->barcodes->tryGenerateFor($v3));
        $this->em->refresh($v4);
        $ok = $this->barcodes->tryGenerateFor($v4);
        $this->assertSame([$this->codeOf('product_variant', $v4->getId()), null, false], array_values($ok));
        $this->assertTrue(CatalogBarcode::isEan13((string) $ok['code']));
    }

    public function testGeneratingTheMissingCodesIsCappedTracedAndNeverTouchesACodeAlreadyThere(): void
    {
        $cap = $this->article('Lot casquette', 3);
        [$v1, $v2, $v3] = $cap->getVariants()->toArray();
        $bottle = $this->article('Lot gourde', 0);
        $shirt = $this->article('Lot maillot', 1);
        $s = strtoupper($this->suffix);
        // Déjà là : le code d'un article à variantes, le code saisi d'une variante, et un code posé par un autre chemin sur une variante
        // dont l'objet en mémoire dit encore « pas de code »
        $this->db->executeStatement('UPDATE product SET barcode = ? WHERE id = ?', ["ARTICLE-$s", $cap->getId()]);
        $this->db->executeStatement('UPDATE product_variant SET barcode = ? WHERE id = ?', ["SAISI-$s", $v2->getId()]);
        $this->assertNull($v2->getBarcode());
        $kept = fn (): array => [$this->codeOf('product', $cap->getId()), $this->codeOf('product_variant', $v2->getId()), $this->codeOf('product', $shirt->getId())];
        $keptBefore = $kept();
        $this->assertSame(["ARTICLE-$s", "SAISI-$s", null], $keptBefore);

        $missing = $this->barcodes->missingCount();
        $variantIds = array_map('intval', $this->db->fetchFirstColumn('SELECT id FROM product_variant WHERE barcode IS NULL ORDER BY id'));
        $productIds = array_map('intval', $this->db->fetchFirstColumn('SELECT p.id FROM product p WHERE p.barcode IS NULL AND NOT EXISTS (SELECT 1 FROM product_variant v WHERE v.product_id = p.id) ORDER BY p.id'));
        $this->assertSame(['variants' => count($variantIds), 'products' => count($productIds), 'total' => count($variantIds) + count($productIds)], $missing);
        $this->assertContains((int) $bottle->getId(), $productIds);
        $this->assertNotContains((int) $cap->getId(), $productIds);
        $this->assertNotContains((int) $shirt->getId(), $productIds, 'un article à variante ne reçoit pas de code généré, même sans code');
        $this->assertNotContains((int) $v2->getId(), $variantIds);
        $auditCount = fn (): int => (int) $this->db->fetchOne("SELECT COUNT(*) FROM content_audit_log WHERE resource = 'catalog_barcode' AND action = 'generate'");
        $auditBefore = $auditCount();

        // 1. Plafond : au plus `limit` codes par lancement, les variantes d'abord, par identifiant
        $run = $this->barcodes->generateMissing(2);
        $this->assertSame(['written' => 2, 'variants' => 2, 'products' => 0, 'remaining' => $missing['total'] - 2], $run);
        $this->assertSame(array_slice($variantIds, 2), array_map('intval', $this->db->fetchFirstColumn('SELECT id FROM product_variant WHERE barcode IS NULL ORDER BY id')));
        $this->assertSame($auditBefore + 1, $auditCount(), 'une ligne au journal du site par lancement');
        $line = $this->db->fetchAssociative("SELECT resource_id, after_state, before_state FROM content_audit_log WHERE resource = 'catalog_barcode' ORDER BY id DESC LIMIT 1");
        $after = json_decode((string) $line['after_state'], true);
        $this->assertSame([2, 2, 0, $missing['total'] - 2, 'catalog'], [$after['written'], $after['variants'], $after['products'], $after['remaining'], $after['scope']]);
        $this->assertSame([$this->codeOf('product_variant', $variantIds[0]), $this->codeOf('product_variant', $variantIds[1])], [$after['first'], $after['last']]);
        $this->assertNull($line['resource_id']);

        // 2. La suite : tout ce qui manque, jusqu'au plafond du service
        $total = $missing['total'] - 2;
        $run = $this->barcodes->generateMissing();
        $written = min($total, CatalogBarcodeService::BULK_LIMIT);
        $this->assertSame([$written, $total - $written], [$run['written'], $run['remaining']]);
        $this->assertSame($auditBefore + 2, $auditCount());
        // Ce qui était là n'a pas bougé (ni le code d'un article à variantes, ni un code saisi, même inconnu de l'objet en mémoire)
        $this->assertSame($keptBefore, $kept());
        $this->assertNull($v2->getBarcode(), 'la variante au code saisi n\'a pas été touchée, pas même en mémoire');
        $this->assertFalse($this->db->isTransactionActive());
        // Ce qui est écrit est un EAN-13 interne, unique sur les deux tables
        $generated = $this->db->fetchFirstColumn("SELECT barcode FROM product WHERE barcode LIKE '29%' UNION ALL SELECT barcode FROM product_variant WHERE barcode LIKE '29%'");
        $this->assertCount(2 + $written, $generated);
        $this->assertSame(count($generated), count(array_unique($generated)));
        foreach ($generated as $code) {
            $this->assertTrue(CatalogBarcode::isEan13((string) $code), (string) $code);
        }
        $this->assertLessThanOrEqual(CatalogBarcodeService::BULK_LIMIT, $total, 'la base de test tient sous le plafond : la suite du test le suppose');
        $this->assertSame(['variants' => 0, 'products' => 0, 'total' => 0], $this->barcodes->missingCount());
        $this->assertSame(count($productIds), $run['products']);
        // Le même code en mémoire et en base
        $this->assertNotNull($v1->getBarcode());
        $this->assertSame([$v1->getBarcode(), $v3->getBarcode(), $bottle->getBarcode()], [$this->codeOf('product_variant', $v1->getId()), $this->codeOf('product_variant', $v3->getId()), $this->codeOf('product', $bottle->getId())]);
        $this->assertSame([null, "ARTICLE-$s"], [$shirt->getBarcode(), $this->codeOf('product', $cap->getId())]);

        // 3. Plus rien à écrire : rien d'écrit, pas de ligne au journal
        $this->assertSame(['written' => 0, 'variants' => 0, 'products' => 0, 'remaining' => 0], $this->barcodes->generateMissing());
        $this->assertSame($auditBefore + 2, $auditCount());

        // 4. Un code retiré par un autre chemin (l'objet en mémoire porte encore l'ancien) : la ligne est relue, un nouveau code est écrit ;
        //    un plafond demandé au-dessus de celui du service est ramené au plafond
        $old = $v1->getBarcode();
        $this->db->executeStatement('UPDATE product_variant SET barcode = NULL WHERE id = ?', [$v1->getId()]);
        $this->assertSame(1, $this->barcodes->generateMissing(100000)['written']);
        $this->assertSame($v1->getBarcode(), $this->codeOf('product_variant', $v1->getId()));
        $this->assertNotNull($v1->getBarcode());
        $this->assertNotSame($old, $v1->getBarcode(), 'un code généré n\'est jamais redonné');
    }

    public function testTheBulkGenerationKeepsItsCeilingAndStaysFastOnMoreThanFiveHundredRows(): void
    {
        // 520 variantes sans code de plus (copiées en SQL) : un lancement en écrit exactement 500, vite, et dit ce qui reste. Avant la
        // correction de la relecture, l'écriture par les entités coûtait N² flush (le rappel de la variante re-flushe) : plusieurs
        // minutes à 500, verrous tenus
        $product = $this->article('Lot plafond', 1);
        $source = (int) $product->getVariants()->first()->getId();
        $base = (int) $this->db->fetchOne('SELECT MAX(id) + 2000 FROM product_variant');
        $this->db->executeStatement(
            "INSERT INTO product_variant SELECT (jsonb_populate_record(NULL::product_variant, to_jsonb(v) || jsonb_build_object('id', g.id, 'barcode', NULL))).* FROM product_variant v, generate_series(CAST(:from AS integer), CAST(:to AS integer)) AS g(id) WHERE v.id = :source",
            ['from' => $base + 1, 'to' => $base + 520, 'source' => $source]
        );
        $missing = $this->barcodes->missingCount()['total'];
        $this->assertGreaterThanOrEqual(521, $missing);
        $audit = fn (): int => (int) $this->db->fetchOne("SELECT COUNT(*) FROM content_audit_log WHERE resource = 'catalog_barcode' AND action = 'generate'");
        $auditBefore = $audit();

        $started = microtime(true);
        $run = $this->barcodes->generateMissing(100000); // un plafond demandé au-dessus de celui du service est ramené à 500
        $seconds = microtime(true) - $started;
        fwrite(STDERR, sprintf("\n[CB-1] generateMissing : %d codes écrits en %.2f s (%d fiches sans code au départ)\n", $run['written'], $seconds, $missing));
        $this->assertSame([CatalogBarcodeService::BULK_LIMIT, 500, $missing - 500], [$run['written'], CatalogBarcodeService::BULK_LIMIT, $run['remaining']], 'exactement le plafond, pas une fiche de plus');
        $this->assertLessThan(20.0, $seconds, 'le plafond de 500 doit tenir très loin du délai du serveur (300 s)');
        $this->assertSame($auditBefore + 1, $audit(), 'une seule ligne au journal pour le lancement');
        $codes = $this->db->fetchFirstColumn("SELECT barcode FROM product_variant WHERE barcode LIKE '29%' UNION ALL SELECT barcode FROM product WHERE barcode LIKE '29%'");
        $this->assertSame([500, 500], [count($codes), count(array_unique($codes))]);
        $this->assertFalse($this->db->isTransactionActive());
        // La suite : le reste, sans redonner un code ni toucher aux 500 premiers
        $rest = $this->barcodes->generateMissing();
        $this->assertSame([min(500, $missing - 500), max(0, $missing - 1000)], [$rest['written'], $rest['remaining']]);
        $this->assertSame(500, count(array_intersect($codes, $this->db->fetchFirstColumn("SELECT barcode FROM product_variant WHERE barcode LIKE '29%' UNION ALL SELECT barcode FROM product WHERE barcode LIKE '29%'"))));

        // Une ligne tenue par une vente en cours (verrou de ligne d'une autre connexion) n'est pas attendue : elle est laissée au
        // lancement suivant, et rien ne bloque
        $this->db->executeStatement('UPDATE product_variant SET barcode = NULL WHERE id IN (?, ?)', [$base + 1, $base + 2]);
        $other = DriverManager::getConnection($this->db->getParams());
        // Attente de verrou bornée : si le lancement se mettait à attendre l'autre connexion, le test échoue au lieu de se bloquer
        $this->db->executeStatement("SET lock_timeout = '5s'");
        try {
            $other->beginTransaction();
            $other->fetchOne('SELECT id FROM product_variant WHERE id = ? FOR UPDATE', [$base + 1]); // comme la vente d'une variante
            $started = microtime(true);
            $skipping = $this->barcodes->generateMissing();
            $this->assertLessThan(4.0, microtime(true) - $started, 'aucune attente');
            $this->assertSame([1, 1], [$skipping['written'], $skipping['remaining']], 'la ligne libre reçoit son code, la ligne tenue reste à faire');
            $this->assertSame([null, true], [$this->codeOf('product_variant', $base + 1), $this->codeOf('product_variant', $base + 2) !== null]);
            $other->rollBack();

            // Une ligne seulement RÉFÉRENCÉE par une écriture en cours (une ligne de vente qu'on insère prend FOR KEY SHARE sur la
            // variante) n'est pas « occupée » : le lancement prend le verrou d'une mise à jour ordinaire (FOR NO KEY UPDATE), qui
            // s'en accommode, et lui donne son code. Avec FOR UPDATE, il la sauterait à chaque vente en cours
            $other->beginTransaction();
            $other->fetchOne('SELECT id FROM product_variant WHERE id = ? FOR KEY SHARE', [$base + 1]);
            $started = microtime(true);
            $referenced = $this->barcodes->generateMissing();
            $this->assertLessThan(4.0, microtime(true) - $started, 'aucune attente');
            $this->assertSame([1, 0], [$referenced['written'], $referenced['remaining']], 'la ligne référencée par une vente en cours reçoit son code');
            $this->assertNotNull($this->codeOf('product_variant', $base + 1));
        } finally {
            if ($this->db->isTransactionActive()) {
                $this->db->rollBack();
            }
            $this->db->executeStatement('SET lock_timeout = 0');
            if ($other->isTransactionActive()) {
                $other->rollBack();
            }
            $other->close();
        }
        $this->assertSame(0, $this->barcodes->generateMissing()['written']);

        // Dernier garde-fou de l'écriture : même si un appelant lui passait une fiche qui a déjà un code (verrou oublié), ce code
        // n'est pas remplacé et rien n'est écrit
        $kept = (string) $this->codeOf('product_variant', $base + 2);
        $this->db->executeStatement('UPDATE product_variant SET barcode = NULL WHERE id = ?', [$base + 3]);
        $write = new \ReflectionMethod(CatalogBarcodeService::class, 'write');
        $refusedWrite = null;
        $this->db->beginTransaction();
        try {
            $write->invoke($this->barcodes, [$base + 3, $base + 2], []);
        } catch (\RuntimeException $e) {
            $refusedWrite = $e->getMessage();
        } finally {
            $this->db->rollBack();
        }
        $this->assertStringContainsString('rien n\'est écrit', (string) $refusedWrite, 'l\'écriture devait refuser une fiche qui porte déjà un code');
        $this->assertSame([$kept, null], [$this->codeOf('product_variant', $base + 2), $this->codeOf('product_variant', $base + 3)]);
    }

    public function testTheSheetConstraintAlsoComparesTheCodesOfTheSameFormThatTheDatabaseDoesNotKnowYet(): void
    {
        // Contrainte des fiches (`CatalogBarcodeFree`) : la base ne connaît pas encore ce qu'on vient de saisir dans le MÊME formulaire
        $validator = static::getContainer()->get('validator');
        $codes = static fn ($entity): array => array_values(array_map(
            static fn ($v): string => $v->getCode() . ' ' . $v->getPropertyPath() . ' ' . $v->getMessage(),
            array_filter(iterator_to_array($validator->validate($entity)), static fn ($v): bool => str_starts_with((string) $v->getCode(), 'BARCODE_'))
        ));
        $s = strtoupper($this->suffix);
        $cap = $this->article('Formulaire casquette', 2);
        [$v1, $v2] = $cap->getVariants()->toArray();
        $this->assertSame([], $codes($cap));
        $this->assertSame([], $codes($v1));

        // La variante prend le code qu'on vient de saisir sur son article (rien n'est encore en base)
        $cap->setBarcode("MEME-$s");
        $v1->setBarcode("meme-$s ");
        $this->assertSame(["BARCODE_ALREADY_USED barcode Ce code-barres est déjà celui de l'article : une variante porte son propre code."], $codes($v1));
        $this->assertSame(["BARCODE_ALREADY_USED barcode Ce code-barres est déjà celui d'une variante de cet article : l'article et ses variantes portent des codes différents."], $codes($cap));
        // Deux variantes du formulaire portent le même code
        $cap->setBarcode(null);
        $v2->setBarcode("MEME-$s");
        $this->assertSame(["BARCODE_ALREADY_USED barcode Ce code-barres est déjà celui d'une autre variante de cet article."], $codes($v1));
        $this->assertSame(["BARCODE_ALREADY_USED barcode Ce code-barres est déjà celui d'une autre variante de cet article."], $codes($v2));
        // Des codes différents : rien à redire
        $v2->setBarcode("AUTRE-$s");
        $this->assertSame([[], [], []], [$codes($v1), $codes($v2), $codes($cap)]);
        $this->assertNull($this->codeOf('product_variant', $v1->getId()), 'la contrainte n\'écrit rien');
        $this->em->clear(); // rien de ces saisies ne doit partir au prochain flush
    }

    /** Article d'essai, avec `$variants` variantes sans taille ni couleur */
    private function article(string $name, int $variants): Product
    {
        $slug = strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', $name)) . '-' . $this->suffix . '-' . bin2hex(random_bytes(2));
        $product = (new Product())->setName("$name {$this->suffix}")->setSlug($slug)->setPrice(1990)->setIsWeb(true);
        for ($i = 0; $i < $variants; ++$i) {
            $product->addVariant((new ProductVariant())->setStockQuantity(5));
        }
        $this->em->persist($product);
        $this->em->flush();

        return $product;
    }

    private function codeOf(string $table, ?int $id): ?string
    {
        $code = $this->db->fetchOne("SELECT barcode FROM $table WHERE id = ?", [$id]);

        return $code === false ? null : $code;
    }

    /** Copie une variante telle quelle sous un nouvel identifiant, seul le code change (insertion SQL, sans le code de l'application) */
    private function copyVariant(int $id, ?string $code, ?int $as = null): int
    {
        $new = $as ?? (int) $this->db->fetchOne('SELECT MAX(id) + 1000 + (random() * 100000)::int FROM product_variant');
        $this->db->executeStatement(
            "INSERT INTO product_variant SELECT (jsonb_populate_record(NULL::product_variant, to_jsonb(v) || jsonb_build_object('id', :new::int, 'barcode', :code::text))).* FROM product_variant v WHERE v.id = :id",
            ['new' => $new, 'code' => $code, 'id' => $id]
        );

        return $new;
    }

    /** Copie la ligne d'un article sous un nouvel identifiant (autre slug, autre code d'article), seul le code-barres change */
    private function copyProduct(int $id, ?string $code): int
    {
        $new = (int) $this->db->fetchOne('SELECT MAX(id) + 1000 + (random() * 100000)::int FROM product');
        $this->db->executeStatement(
            "INSERT INTO product SELECT (jsonb_populate_record(NULL::product, to_jsonb(p) || jsonb_build_object('id', :new::int, 'barcode', :code::text, 'slug', :slug::text, 'code', NULL))).* FROM product p WHERE p.id = :id",
            ['new' => $new, 'code' => $code, 'slug' => 'cb-copie-' . $new, 'id' => $id]
        );

        return $new;
    }

    /** Message du refus de la base ; échoue si l'écriture passe */
    private function refused(callable $write): string
    {
        try {
            $write();
        } catch (\Doctrine\DBAL\Exception $e) {
            if (str_contains($e->getMessage(), 'uniq_') || str_contains($e->getMessage(), 'déjà porté')) {
                $this->assertInstanceOf(UniqueConstraintViolationException::class, $e, 'un doublon est rendu comme une violation d\'unicité');
            }

            return $e->getMessage();
        }
        $this->fail('la base devait refuser cette écriture');
    }

    private function refusal(callable $call): CatalogBarcodeException
    {
        try {
            $call();
        } catch (CatalogBarcodeException $e) {
            return $e;
        }
        $this->fail('le service devait refuser');
    }

    private function clearCodes(): void
    {
        $this->db->executeStatement('UPDATE product SET barcode = NULL WHERE barcode IS NOT NULL');
        $this->db->executeStatement('UPDATE product_variant SET barcode = NULL WHERE barcode IS NOT NULL');
    }
}
