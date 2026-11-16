<?php

namespace App\Tests\Functional\Storefront;

use App\Entity\Product;
use App\Entity\ProductVariant;
use App\Services\Storefront\LegalTextLanguages;
use App\Services\Storefront\SellableProduct;
use App\Services\Storefront\StorefrontService;
use App\Services\TenantEntityManagerProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Correctif du 16/11/2026 (relevé par le propriétaire) : un article est VENDABLE si son propre prix est supérieur à zéro, ou si l'une de
 * ses variantes a un prix supérieur à zéro. Avant, seule la seconde forme comptait : un site dont les prix sont portés par l'article
 * (variantes sans prix, ou aucune variante) lisait « Aucun article n'est visible sur la boutique ». Une seule règle (`SellableProduct`)
 * pour la liste de contrôle d'ouverture, les contrôles du site et le cheminement.
 */
class SellableProductTest extends KernelTestCase
{
    /** @var list<int> */
    private array $created = [];

    protected function tearDown(): void
    {
        $em = static::getContainer()->get(TenantEntityManagerProvider::class)->getEntityManager();
        foreach ($this->created as $id) {
            $product = $em->find(Product::class, $id);
            if ($product !== null) {
                $em->remove($product);
            }
        }
        $em->flush();
        parent::tearDown();
    }

    public function testAProductIsSellableByItsOwnPriceOrByAPricedVariant(): void
    {
        self::bootKernel();
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $em = $provider->getEntityManager();
        $tag = bin2hex(random_bytes(3));
        $make = function (string $name, float $price, array $variantPrices) use ($em, $tag): int {
            $product = (new Product())->setName("Vendable $name $tag")->setSlug("vendable-$name-$tag")->setPrice($price)->setIsWeb(true)->setSaleEnabled(true);
            foreach ($variantPrices as $variantPrice) {
                $product->addVariant((new ProductVariant())->setStockQuantity(1)->setPrice($variantPrice));
            }
            $em->persist($product);
            $em->flush();
            $this->created[] = (int) $product->getId();

            return (int) $product->getId();
        };
        // les formes d'un catalogue : prix sur l'article (variantes sans prix), prix sur les variantes, article sans variante
        $ownPriceNoVariant = $make('article-sans-variante', 20, []);
        $ownPriceUnpricedVariants = $make('article-variantes-sans-prix', 20, [null, null]);
        $variantPrice = $make('variante', 0, [0, 15]);
        $zeroVariant = $make('variante-a-zero', 20, [0]);
        $nothing = $make('sans-prix', 0, [null]);

        $ids = implode(', ', [$ownPriceNoVariant, $ownPriceUnpricedVariants, $variantPrice, $zeroVariant, $nothing]);
        $sellable = array_map('intval', $em->getConnection()->fetchFirstColumn("SELECT p.id FROM product p WHERE p.id IN ($ids) AND " . SellableProduct::CONDITION . ' ORDER BY p.id'));
        $this->assertSame([$ownPriceUnpricedVariants, $variantPrice], $sellable, 'règle du panier : une variante au prix (le sien, ou celui de l\'article) > 0 ; sans variante, ou à prix nul, l\'article ne s\'achète pas');

        // la liste de contrôle d'ouverture : un site aux prix sur l'article (forme relevée par le propriétaire) a ses articles comptés, et
        // l'article en ligne sans variante est signalé, sans bloquer
        $hidden = array_map('intval', $em->getConnection()->fetchFirstColumn("SELECT id FROM product WHERE is_web = TRUE AND id NOT IN ($ownPriceNoVariant, $ownPriceUnpricedVariants)"));
        if ($hidden !== []) {
            $em->getConnection()->executeStatement('UPDATE product SET is_web = FALSE WHERE id IN (' . implode(', ', $hidden) . ')');
        }
        try {
            $items = array_column(static::getContainer()->get(StorefrontService::class)->checklist()['items'], null, 'key');
            if ($items !== []) {
                $this->assertTrue($items['products']['ok'], $items['products']['message']);
                $this->assertSame([false, false, '1 article en ligne ne peut pas être acheté : ajoutez-lui une variante avec un prix.'], [$items['unbuyable']['ok'], $items['unbuyable']['blocking'], $items['unbuyable']['message']]);
            }
        } finally {
            // les articles mis hors ligne pour l'essai, et eux seuls, reviennent en ligne
            if ($hidden !== []) {
                $em->getConnection()->executeStatement('UPDATE product SET is_web = TRUE WHERE id IN (' . implode(', ', $hidden) . ')');
            }
        }
    }

    /** Les conditions de vente dans chaque langue que sert le site : une ligne de traduction vide n'est pas comblée par le français */
    public function testLegalTextsAreCheckedInEveryLanguageTheSiteServes(): void
    {
        self::bootKernel();
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $pdo = static::getContainer()->get(\App\Services\Platform\TenantDatabaseConnector::class)->pdo(MV_TEST_TENANT_DB);
        $rows = $pdo->query('SELECT * FROM entreprise_translation ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC);
        $sheet = $pdo->query('SELECT id, locale, condition_of_use FROM entreprise ORDER BY id LIMIT 1')->fetch(\PDO::FETCH_ASSOC);
        try {
            $pdo->exec('DELETE FROM entreprise_translation');
            $pdo->prepare('UPDATE entreprise SET locale = ?, condition_of_use = ? WHERE id = ?')->execute(['fr', 'Conditions de la fiche', $sheet['id']]);
            $this->assertSame([], LegalTextLanguages::missing($pdo, 'condition_of_use'), 'sans traduction : le texte de la fiche');
            $insert = $pdo->prepare('INSERT INTO entreprise_translation (id, entreprise_id, language, condition_of_use) VALUES (nextval(\'entreprise_translation_id_seq\'), ?, ?, ?)');
            $insert->execute([$sheet['id'], 'fr', 'Conditions']);
            $insert->execute([$sheet['id'], 'en', '']);
            $this->assertSame(['en'], LegalTextLanguages::missing($pdo, 'condition_of_use'), 'la ligne anglaise vide manque : le site ne la comble pas par le français');
            $pdo->exec("UPDATE entreprise_translation SET condition_of_use = 'Terms' WHERE language = 'en'");
            $this->assertSame([], LegalTextLanguages::missing($pdo, 'condition_of_use'));
            $pdo->prepare('UPDATE entreprise SET locale = ? WHERE id = ?')->execute(['de', $sheet['id']]);
            $this->assertSame([], LegalTextLanguages::missing($pdo, 'condition_of_use'), 'langue du site sans ligne : le français, comme le site public');
            $pdo->exec("UPDATE entreprise_translation SET condition_of_use = '' WHERE language = 'fr'");
            $this->assertSame(['de', 'fr'], LegalTextLanguages::missing($pdo, 'condition_of_use'));
        } finally {
            $pdo->exec('DELETE FROM entreprise_translation');
            foreach ($rows as $row) {
                $columns = array_keys($row);
                $pdo->prepare('INSERT INTO entreprise_translation (' . implode(', ', $columns) . ') VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')')->execute(array_values($row));
            }
            $pdo->prepare('UPDATE entreprise SET locale = ?, condition_of_use = ? WHERE id = ?')->execute([$sheet['locale'], $sheet['condition_of_use'], $sheet['id']]);
        }
    }
}
