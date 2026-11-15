<?php

namespace App\Tests\Functional\Boutique;

use App\Entity\Product;
use App\Entity\User;
use App\EventSubscriber\CacheInvalidationSubscriber;
use App\Services\ProductAiService\ProductSheetCreator;
use App\Services\TenantEntityManagerProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Lot ART-SUPPR-2 (15/11/2026) : un article supprimé ne revient pas. Avant, un rappel de la variante réenregistrait l'article pendant
 * sa suppression : il reparaissait sous un nouvel identifiant, avec ses variantes. Les tests d'alors ne regardaient que l'ancien
 * identifiant ; ici la preuve est faite par le NOM, sur chaque chemin : la suppression par l'ORM (celle d'EasyAdmin),
 * `DELETE /api/products/{id}` (le mobile), et `DELETE /api/product-variants/{id}`, qui ne doit ni retirer ni dédoubler l'article.
 * L'annulation d'une facture de la gestion est couverte par `InvoicePricingConversationTest`.
 */
class ProductDeletionTest extends WebTestCase
{
    private KernelBrowser $client;
    private string $token = '';
    private string $tag;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->tag = strtoupper(bin2hex(random_bytes(3)));
        $email = 'art-suppr-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $em = $this->em();
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Sarah')->setLastname('Suppression')->setRoles(['ROLE_ADMIN'])->setIsVerified(true)->setPassword('inutilisable');
        $em->persist($user);
        $em->flush();
        $this->endOfWrite();
        $this->token = static::getContainer()->get('lexik_jwt_authentication.jwt_manager')->create($user);
    }

    protected function tearDown(): void
    {
        $conn = $this->em()->getConnection();
        $ids = array_map('intval', $conn->fetchFirstColumn('SELECT id FROM product WHERE name LIKE ?', ['% ' . $this->tag]));
        if ($ids !== []) {
            $in = implode(',', $ids);
            $conn->executeStatement("DELETE FROM product_variant WHERE product_id IN ($in)");
            foreach (['product_translation', 'product_picture', 'product_categories'] as $table) {
                $conn->executeStatement("DELETE FROM $table WHERE product_id IN ($in)");
            }
            $conn->executeStatement("DELETE FROM product WHERE id IN ($in)");
        }
        $this->endOfWrite();
        parent::tearDown();
    }

    public function testAnArticleRemovedThroughTheOrmDoesNotComeBack(): void
    {
        [$id, $name] = $this->article('Supprimé par EasyAdmin');
        $em = $this->em();
        $em->remove($em->getRepository(Product::class)->find($id)); // ce que fait la suppression d'EasyAdmin
        $em->flush();
        $this->assertSame([0, 0], $this->counts($name, $id), 'ni l\'article, ni une copie sous un autre identifiant, ni ses variantes');
    }

    public function testAnArticleDeletedThroughTheApiDoesNotComeBack(): void
    {
        [$id, $name] = $this->article('Supprimé par le mobile');
        $this->request('DELETE', "/api/products/$id");
        $this->assertSame(204, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
        $this->assertSame([0, 0], $this->counts($name, $id));
        $this->request('DELETE', "/api/products/$id");
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testAVariantDeletedThroughTheApiLeavesItsArticleAloneAndSingle(): void
    {
        [$id, $name] = $this->article('Variante supprimée');
        $conn = $this->em()->getConnection();
        $variants = array_map('intval', $conn->fetchFirstColumn('SELECT id FROM product_variant WHERE product_id = ? ORDER BY id', [$id]));
        $this->assertCount(2, $variants);
        $this->request('DELETE', '/api/product-variants/' . $variants[1]);
        $this->assertSame(204, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
        $this->assertSame([$id], array_map('intval', $conn->fetchFirstColumn('SELECT id FROM product WHERE name = ?', [$name])), 'l\'article reste, le même, une seule fois');
        $this->assertSame([$variants[0]], array_map('intval', $conn->fetchFirstColumn('SELECT id FROM product_variant WHERE product_id = ?', [$id])));
        $this->assertSame((int) $conn->fetchOne('SELECT COALESCE(SUM(stock_quantity), 0) FROM product_variant WHERE product_id = ?', [$id]), (int) $conn->fetchOne('SELECT quantity FROM product WHERE id = ?', [$id]), 'la quantité de l\'article est celle des variantes qui restent');
    }

    /** @return array{0: int, 1: string} un article à deux variantes */
    private function article(string $label): array
    {
        $name = "$label {$this->tag}";
        $made = static::getContainer()->get(ProductSheetCreator::class)->create(['name' => $name, 'priceCents' => 1500, 'stockQuantity' => 2, 'variants' => ['sizes' => ['S', 'M']]], 'test');
        $this->assertSame(201, $made['status']);
        $this->endOfWrite();
        $this->em()->clear(); // comme dans une vraie requête : l'article est relu de la base, avec ses variantes

        return [(int) $made['body']['productId'], $name];
    }

    /** @return array{0: int, 1: int} articles de ce nom ; variantes de l'article d'origine ou d'une copie */
    private function counts(string $name, int $id): array
    {
        $conn = $this->em()->getConnection();

        return [(int) $conn->fetchOne('SELECT COUNT(*) FROM product WHERE name = ?', [$name]),
            (int) $conn->fetchOne('SELECT COUNT(*) FROM product_variant WHERE product_id = ? OR product_id IN (SELECT id FROM product WHERE name = ?)', [$id, $name])];
    }

    private function request(string $method, string $route): void
    {
        $this->client->request($method, $route, [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $this->token]);
    }

    private function em(): EntityManagerInterface
    {
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);

        return $provider->getEntityManager();
    }

    private function endOfWrite(): void
    {
        static::getContainer()->get(CacheInvalidationSubscriber::class)->invalidateWrittenTenants();
    }
}
