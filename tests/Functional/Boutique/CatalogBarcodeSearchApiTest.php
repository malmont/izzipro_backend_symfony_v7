<?php

namespace App\Tests\Functional\Boutique;

use App\Entity\Product;
use App\Entity\ProductVariant;
use App\Services\TenantEntityManagerProvider;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Recherche en ligne par code-barres, lot CB-1 (15/11/2026, `docs/codes-barres.md`) : `GET /api/products/by-category?barcode=…`, lue par
 * la caisse en ligne (`limit=1&pageSize=1`, repli sur son pack seulement en cas d'échec ou de réponse VIDE). Le code d'une variante
 * rend l'article, avec toutes ses variantes, et `matchedVariantId` dit laquelle ; le code d'un article rend l'article,
 * `matchedVariantId` à null ; un code inconnu rend une liste vide. La recherche prend la forme canonique de ce qui est scanné.
 */
class CatalogBarcodeSearchApiTest extends WebTestCase
{
    private KernelBrowser $client;
    private string $suffix;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->suffix = bin2hex(random_bytes(3));
    }

    protected function tearDown(): void
    {
        $db = $this->em()->getConnection();
        $ids = $db->fetchFirstColumn('SELECT id FROM product WHERE name LIKE ?', ['% ' . $this->suffix]);
        if ($ids !== []) {
            $db->executeStatement('DELETE FROM product_variant WHERE product_id IN (?)', [$ids], [ArrayParameterType::INTEGER]);
            $db->executeStatement('DELETE FROM product WHERE id IN (?)', [$ids], [ArrayParameterType::INTEGER]);
        }
        parent::tearDown();
    }

    public function testAScannedCodeFindsTheArticleAndSaysWhichVariantItDesignates(): void
    {
        $s = strtoupper($this->suffix);
        $em = $this->em();
        $cap = (new Product())->setName("Casquette $this->suffix")->setSlug("casquette-$this->suffix")->setPrice(2900)->setIsWeb(true)->setIsPos(true)->setSaleEnabled(true)->setBarcode("ART-$s");
        $variants = [];
        foreach (['S', 'M', 'L'] as $label) {
            $variants[$label] = (new ProductVariant())->setStockQuantity(5)->setBarcode("VAR-$label-$s");
            $cap->addVariant($variants[$label]);
        }
        $bottle = (new Product())->setName("Gourde $this->suffix")->setSlug("gourde-$this->suffix")->setPrice(1500)->setIsWeb(true)->setIsPos(true)->setSaleEnabled(true)->setBarcode("SEUL-$s");
        $em->persist($cap);
        $em->persist($bottle);
        $em->flush();
        $capId = (int) $cap->getId();
        $ids = array_map(fn (ProductVariant $v) => (int) $v->getId(), $variants);
        $em->clear();
        $search = fn (string $barcode, string $more = '&limit=1&pageSize=1'): array => $this->get('/api/products/by-category?locale=fr&barcode=' . rawurlencode($barcode) . $more)['data'];

        // Le cas exact de la caisse : code d'une variante, sous toutes les casses, entouré d'espaces
        foreach (["VAR-M-$s", strtolower("VAR-M-$s"), "  var-m-$this->suffix "] as $scanned) {
            $found = $search($scanned);
            $this->assertCount(1, $found, $scanned);
            $this->assertSame([$capId, $ids['M'], "ART-$s"], [$found[0]['id'], $found[0]['matchedVariantId'], $found[0]['barcode']], $scanned);
            $this->assertSame(array_values($ids), array_column($found[0]['variants'], 'id'), 'l\'article est rendu avec TOUTES ses variantes');
        }
        foreach (['S', 'L'] as $label) {
            $this->assertSame([$capId, $ids[$label]], [$search("VAR-$label-$s")[0]['id'], $search("VAR-$label-$s")[0]['matchedVariantId']]);
        }

        // Code de l'article : l'article, sans variante désignée (comme avant le lot)
        $byArticle = $search(strtolower("ART-$s"));
        $this->assertSame([[$capId, null]], array_map(fn (array $p) => [$p['id'], $p['matchedVariantId']], $byArticle));
        $alone = $search("SEUL-$s");
        $this->assertSame([[(int) $bottle->getId(), null, []]], array_map(fn (array $p) => [$p['id'], $p['matchedVariantId'], $p['variants']], $alone));

        // Code inconnu : liste vide (la caisse se replie alors sur son pack)
        $this->assertSame([], $search("INCONNU-$s"));
        $this->assertSame([], $search("VAR-M-$s-X"));
        $this->assertSame([], $search("AR-$s"), 'pas de recherche par morceau de code');

        // Un code demandé mais vide une fois mis en forme (des espaces) ne désigne rien : liste vide, jamais le premier article du
        // catalogue (la caisse le mettrait au panier). « 0 » est un code comme un autre. `barcode=` sans valeur : pas de recherche par code
        foreach (['%20%20', '%20', '%09', '+'] as $blank) {
            $response = $this->get("/api/products/by-category?locale=fr&barcode=$blank&limit=1&pageSize=1");
            $this->assertSame([[], 0], [$response['data'], $response['meta']['total']], "barcode=$blank");
        }
        $this->assertSame([], $search('0'), 'aucune fiche ne porte le code « 0 »');
        $em2 = $this->em();
        $em2->getConnection()->executeStatement('UPDATE product_variant SET barcode = ? WHERE id = ?', ['0', $ids['S']]);
        // (autres paramètres que la recherche précédente : la réponse vide de celle-ci est en cache 300 s sous sa propre clé)
        $this->assertSame([[$capId, $ids['S']]], array_map(fn (array $p) => [$p['id'], $p['matchedVariantId']], $search('0', '&limit=1&pageSize=1&isPos=true')), 'le code « 0 » se cherche comme un autre');
        $em2->getConnection()->executeStatement('UPDATE product_variant SET barcode = ? WHERE id = ?', ["VAR-S-$s", $ids['S']]);
        $unfiltered = $this->get('/api/products/by-category?locale=fr&barcode=&pageSize=50&keyword=' . rawurlencode("Casquette $this->suffix"))['data'];
        $this->assertSame([[$capId, null]], array_map(fn (array $p) => [$p['id'], $p['matchedVariantId']], $unfiltered), 'barcode vide : comme sans le paramètre');

        // Les filtres habituels s'appliquent toujours ; sans recherche par code, le champ est là et vaut null
        $this->assertCount(1, $search("VAR-M-$s", '&isPos=true&pageSize=5'));
        $this->assertSame([], $search("VAR-M-$s", '&isPos=false&pageSize=5'));
        $listed = $this->get('/api/products/by-category?locale=fr&pageSize=50&keyword=' . rawurlencode("Casquette $this->suffix"))['data'];
        $this->assertCount(1, $listed);
        $this->assertArrayHasKey('matchedVariantId', $listed[0]);
        $this->assertNull($listed[0]['matchedVariantId']);
    }

    private function get(string $path): array
    {
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . $path, [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'HTTP_ACCEPT' => 'application/json']);
        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        return json_decode((string) $response->getContent(), true);
    }

    private function em(): EntityManagerInterface
    {
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);

        return $provider->getEntityManager();
    }
}
