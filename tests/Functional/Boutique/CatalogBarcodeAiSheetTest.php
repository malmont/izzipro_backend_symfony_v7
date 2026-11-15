<?php

namespace App\Tests\Functional\Boutique;

use App\Entity\Product;
use App\Services\BarcodeService\CatalogBarcodeService;
use App\Services\ProductAiService\ProductSheetCreator;
use App\Services\TenantEntityManagerProvider;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Création d'une fiche par l'IA et code-barres, lot CB-1 (15/11/2026, `docs/codes-barres.md`) : le code passe par
 * `CatalogBarcodeService` AVANT que rien ne soit créé. Avant le lot : coupé à 64 caractères et écrit tel quel, même déjà porté par un
 * autre article.
 */
class CatalogBarcodeAiSheetTest extends KernelTestCase
{
    private Connection $db;
    private ProductSheetCreator $creator;
    private string $suffix;

    protected function setUp(): void
    {
        self::bootKernel();
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $this->db = $provider->getEntityManager()->getConnection();
        $this->creator = static::getContainer()->get(ProductSheetCreator::class);
        $this->suffix = bin2hex(random_bytes(3));
    }

    protected function tearDown(): void
    {
        // D'autres tests comptent les articles : ceux du test sont effacés, avec ce que la création leur a donné
        $ids = $this->db->fetchFirstColumn('SELECT id FROM product WHERE name LIKE ?', ['% ' . $this->suffix]);
        if ($ids !== []) {
            foreach (['product_variant', 'product_translation', 'product_picture', 'product_categories'] as $table) {
                $this->db->executeStatement("DELETE FROM $table WHERE product_id IN (?)", [$ids], [ArrayParameterType::INTEGER]);
            }
            $this->db->executeStatement('DELETE FROM product WHERE id IN (?)', [$ids], [ArrayParameterType::INTEGER]);
        }
        parent::tearDown();
    }

    public function testTheBarcodeOfACreatedSheetGoesThroughTheRuleBeforeAnythingIsWritten(): void
    {
        $s = strtoupper($this->suffix);
        $count = fn (): int => (int) $this->db->fetchOne('SELECT COUNT(*) FROM product WHERE name LIKE ?', ['% ' . $this->suffix]);
        $create = fn (string $name, mixed $barcode): array => $this->creator->create(['name' => "$name $this->suffix", 'priceCents' => 2500] + ($barcode === null ? [] : ['barcode' => $barcode]), 'test-codes@example.invalid');

        // Code libre, saisi en minuscules et entouré d'espaces : écrit en forme canonique
        $first = $create('Casquette', ' neuf-' . $this->suffix . ' ');
        $this->assertSame(201, $first['status'], json_encode($first['body']));
        $firstId = (int) $first['body']['productId'];
        $this->assertSame("NEUF-$s", $this->db->fetchOne('SELECT barcode FROM product WHERE id = ?', [$firstId]));
        $this->assertSame(1, $count());

        // Le même code, sous une autre casse : refusé avec ce qui le porte, rien n'est créé
        $taken = $create('Gourde', "Neuf-$s");
        $this->assertSame([422, CatalogBarcodeService::ALREADY_USED], [$taken['status'], $taken['body']['error']]);
        $this->assertSame(['type' => 'product', 'id' => $firstId, 'productId' => $firstId, 'label' => "Casquette $this->suffix"], $taken['body']['holder']);
        $this->assertSame("Ce code-barres est déjà celui de « Casquette $this->suffix ».", $taken['body']['message']);
        // Le code d'une variante est pris lui aussi
        $variantId = (int) $this->db->fetchOne('SELECT MIN(id) FROM product_variant WHERE product_id = ?', [$firstId]);
        $this->db->executeStatement('UPDATE product_variant SET barcode = ? WHERE id = ?', ["PIECE-$s", $variantId]);
        $byVariant = $create('Gourde', "piece-$this->suffix");
        $this->assertSame([422, CatalogBarcodeService::ALREADY_USED, 'variant', $variantId], [$byVariant['status'], $byVariant['body']['error'], $byVariant['body']['holder']['type'], $byVariant['body']['holder']['id']]);

        // Formes réservées à la caisse, forme interdite, code trop long (avant : coupé à 64 caractères)
        foreach (['IZ-T-1-000042' => 'BARCODE_RESERVED', '512345678901' => 'BARCODE_RESERVED', 'A B' => 'BARCODE_INVALID', str_repeat('7', 65) => 'BARCODE_INVALID'] as $barcode => $code) {
            $refused = $create('Gourde', (string) $barcode);
            $this->assertSame([422, $code], [$refused['status'], $refused['body']['error']], (string) $barcode);
            $this->assertArrayNotHasKey('holder', $refused['body']);
            $this->assertNotSame('', (string) $refused['body']['message']);
        }
        foreach ([['tableau'], true, 12.5] as $notText) { // ni tableau, ni booléen, ni décimal : refusé, pas ignoré en silence
            $refused = $create('Gourde', $notText);
            $this->assertSame([422, 'BARCODE_INVALID'], [$refused['status'], $refused['body']['error']], var_export($notText, true));
        }
        $this->assertSame(201, $create('Tasse', 9000000000000 + random_int(1, 999999999))['status'], 'un code donné en nombre entier est accepté');
        $this->assertSame(2, $count(), 'aucun refus n\'a rien créé');

        // Sans code, ou avec un code vide : créé sans code
        foreach ([null, '', '   '] as $i => $barcode) {
            $created = $create("Maillot $i", $barcode);
            $this->assertSame(201, $created['status'], json_encode($created['body']));
            $this->assertNull($this->db->fetchOne('SELECT barcode FROM product WHERE id = ?', [$created['body']['productId']]));
        }
        $this->assertSame(5, $count());
        $this->assertInstanceOf(Product::class, static::getContainer()->get(TenantEntityManagerProvider::class)->getEntityManager()->find(Product::class, $firstId));
    }
}
