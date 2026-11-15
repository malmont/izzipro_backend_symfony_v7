<?php

namespace App\Tests\Functional\Boutique;

use App\Entity\User;
use App\EventSubscriber\CacheInvalidationSubscriber;
use App\Services\ProductAiService\ProductSheetCreator;
use App\Services\TenantEntityManagerProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * La liste des variantes d'un article (`GET /api/products/{id}/variants`, gardée 5 minutes en cache) rend le code-barres de chaque
 * variante, et le montre à jour aussitôt qu'un code est écrit : posé par la gestion (CB-3, écriture par l'ORM) ou généré en masse
 * (écriture en SQL). Demande du web du 15/11/2026 : le panneau des variantes montrait un code périmé.
 */
class ProductVariantBarcodeListTest extends WebTestCase
{
    private KernelBrowser $client;
    private string $token = '';
    private string $tag;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->tag = strtoupper(bin2hex(random_bytes(3)));
        $email = 'liste-codes-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $em = $this->em();
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Lise')->setLastname('Liste')->setRoles(['ROLE_ADMIN'])->setIsVerified(true)->setPassword('inutilisable');
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

    public function testTheVariantListShowsTheBarcodeAsSoonAsItIsWritten(): void
    {
        $made = static::getContainer()->get(ProductSheetCreator::class)->create(['name' => "Liste des codes {$this->tag}", 'priceCents' => 1500, 'variants' => ['sizes' => ['S', 'M']]], 'test');
        $this->assertSame(201, $made['status']);
        $this->endOfWrite();
        $this->em()->clear();
        $id = (int) $made['body']['productId'];
        $list = fn (): array => array_column($this->json('GET', "/api/products/$id/variants", null, false), 'barcode', 'id');

        // La liste (publique) rend le champ, à null sans code ; cette lecture la met en cache
        $before = $list();
        $this->assertCount(2, $before);
        $this->assertSame([null, null], array_values($before));
        [$first, $second] = array_keys($before);

        // Un code posé par la gestion (CB-3) se lit aussitôt, en forme canonique
        $put = $this->json('PUT', "/api/gestion/codes-barres/variantes/$first", ['barcode' => " liste-{$this->tag} "]);
        $this->assertSame("LISTE-{$this->tag}", $put['barcode']);
        $this->assertSame(["LISTE-{$this->tag}", null], array_values($list()));

        // Un code généré en masse (écrit en SQL) se lit aussitôt aussi
        $this->json('POST', "/api/gestion/codes-barres/variantes/$second/generer");
        $after = $list();
        $this->assertSame("LISTE-{$this->tag}", $after[$first]);
        $this->assertMatchesRegularExpression('/^29\d{11}$/', (string) $after[$second]);

        // Le code retiré disparaît de la liste
        $this->json('PUT', "/api/gestion/codes-barres/variantes/$first", ['barcode' => null, 'replace' => true]);
        $this->assertNull($list()[$first]);
    }

    private function json(string $method, string $route, ?array $body = null, bool $auth = true): array
    {
        $this->client->request($method, $route, [], [], array_filter(['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => $auth ? 'Bearer ' . $this->token : null]), $body === null ? null : json_encode($body));
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), "$method $route : " . substr((string) $this->client->getResponse()->getContent(), 0, 300));

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
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
