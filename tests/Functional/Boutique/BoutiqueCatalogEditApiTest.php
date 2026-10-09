<?php

namespace App\Tests\Functional\Boutique;

use App\Entity\User;
use App\Services\BoutiqueDemoService\BoutiqueDemoSeeder;
use App\Services\TenantEntityManagerProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Données de la boutique éditées depuis la page (09/10/2026) : variantes, couleurs et tailles, options, valeurs et
 * combinaisons de personnalisation, textes des formules, atouts de l'accueil ; journal et retour en arrière ; ROLE_ADMIN.
 */
class BoutiqueCatalogEditApiTest extends WebTestCase
{
    private const PASSWORD = 'Mot-de-passe-de-test-1!';
    private const IMAGE_KEY = 'cd01cd01cd01cd01cd01cd01cd01cd01cd01cd01cd01cd01cd01cd01cd01cd01';

    private KernelBrowser $client;
    private array $session = [];
    private \Doctrine\DBAL\Connection $db;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $this->db = $provider->getEntityManager()->getConnection();
        static::getContainer()->get(BoutiqueDemoSeeder::class)->seed('client-catalogue@example.invalid', false, false);
        $this->db->executeStatement('DELETE FROM shared_media WHERE access_key = ?', [self::IMAGE_KEY]);
        $this->db->executeStatement("INSERT INTO shared_media (titre, filename, media_type, mime_type, visibility, access_key, created_at) VALUES ('Icône', 'i.png', 'image', 'image/png', 'private', '" . self::IMAGE_KEY . "', NOW())");
        $this->loginAs(['ROLE_ADMIN', 'ROLE_USER_INTERNET']);
    }

    public function testVariantsAreCreatedPatchedWithTracedStockAndDeletedWhenNeverOrdered(): void
    {
        $product = $this->id("SELECT id FROM product WHERE code = 'DEMO-SAC'");
        $color = $this->json($this->request('POST', '/api/colors?locale=fr', ['name' => 'Bleu nuit ' . bin2hex(random_bytes(2)), 'codeHexa' => '#1E2A4A']), 201);
        $this->assertSame('#1E2A4A', $color['codeHexa']);
        $this->assertContains($color['id'], array_column($this->json($this->request('GET', '/api/colors?locale=fr')), 'id'), 'nouvelle couleur listée');
        $sizes = [];
        foreach (['XS', 'S'] as $name) {
            $sizes[$name] = $this->json($this->request('POST', '/api/sizes?locale=fr', ['name' => $name . ' ' . bin2hex(random_bytes(2))]), 201)['id'];
        }
        $this->assertSame(422, $this->request('POST', '/api/sizes', ['name' => '<b>XL</b>'])->getStatusCode(), 'nom sans balise');

        $variant = $this->json($this->request('POST', "/api/products/$product/variants?locale=fr", ['colorId' => $color['id'], 'sizeId' => $sizes['XS'], 'stockQuantity' => 4, 'price' => 15900]), 201);
        $this->assertSame([4, 15900, $color['id'], $sizes['XS']], [$variant['stockQuantity'], $variant['price'], $variant['color']['id'], $variant['size']['id']]);
        $this->assertSame(422, $this->request('POST', "/api/products/$product/variants", ['colorId' => $color['id'], 'sizeId' => $sizes['XS'], 'stockQuantity' => 1])->getStatusCode(), 'couple couleur + taille déjà pris');
        $second = $this->json($this->request('POST', "/api/products/$product/variants", ['colorId' => $color['id'], 'sizeId' => $sizes['S'], 'stockQuantity' => 0]), 201);

        $patched = $this->json($this->request('PATCH', '/api/product-variants/' . $variant['id'], ['stockQuantity' => 9, 'price' => null]));
        $this->assertSame([9, null], [$patched['stockQuantity'], $patched['price']], 'prix null = prix du produit');
        $this->assertSame(2, (int) $this->db->fetchOne('SELECT COUNT(*) FROM inventory_movements WHERE product_variant_id = ?', [$variant['id']]), 'création + modification : deux mouvements de stock');
        $this->assertSame(422, $this->request('PATCH', '/api/product-variants/' . $second['id'], ['sizeId' => $sizes['XS']])->getStatusCode());
        $this->assertSame(422, $this->request('PATCH', '/api/product-variants/' . $variant['id'], ['stockQuantity' => -1])->getStatusCode());

        $journal = $this->json($this->request('GET', '/api/landingpage-audit?resource=product-variants&resourceId=' . $variant['id']));
        $update = array_values(array_filter($journal['items'], fn ($e) => $e['action'] === 'update'))[0];
        $restored = $this->request('POST', '/api/landingpage-audit/' . $update['id'] . '/restore');
        $this->assertSame(200, $restored->getStatusCode(), $restored->getContent());
        $this->assertSame([4, 15900], [(int) $this->db->fetchOne('SELECT stock_quantity FROM product_variant WHERE id = ?', [$variant['id']]), (int) $this->db->fetchOne('SELECT price FROM product_variant WHERE id = ?', [$variant['id']])]);

        $this->assertSame(204, $this->request('DELETE', '/api/product-variants/' . $second['id'])->getStatusCode());
        $this->assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM product_variant WHERE id = ?', [$second['id']]));
        $ordered = $this->id('SELECT product_variant_id FROM order_items WHERE product_variant_id IS NOT NULL LIMIT 1');
        if ($ordered > 0) {
            $this->assertSame(409, $this->request('DELETE', '/api/product-variants/' . $ordered)->getStatusCode(), 'variante déjà commandée');
        }
    }

    public function testCustomizationIsEditedAndEachWriteReturnsTheVariantConfiguration(): void
    {
        $variant = $this->id("SELECT v.id FROM product_variant v JOIN product p ON p.id = v.product_id WHERE p.code = 'DEMO-GOURDE' ORDER BY v.id LIMIT 1");
        $config = $this->json($this->request('GET', "/api/customization/config/$variant"));
        $option = $config['options'][0];
        $value = $option['values'][0];

        $renamed = $this->json($this->request('PATCH', '/api/customization-options/' . $option['id'] . "?locale=en&variantId=$variant", ['name' => 'Engraving']));
        $this->assertSame($variant, $renamed['variantId'], 'avec variantId : la configuration de la variante');
        $this->assertSame(422, $this->request('PATCH', '/api/customization-options/' . $option['id'], ['name' => ''])->getStatusCode());

        $updated = $this->json($this->request('PATCH', '/api/customization-values/' . $value['id'] . "?variantId=$variant", ['priceDelta' => 750, 'icon' => self::IMAGE_KEY]));
        $values = array_column(array_merge(...array_column($updated['options'], 'values')), null, 'id');
        $this->assertSame(750.0, (float) $values[$value['id']]['priceDelta'], 'supplément en cents dans la configuration');
        $this->assertSame(7.5, (float) $this->db->fetchOne('SELECT price_delta FROM product_option_value WHERE id = ?', [$value['id']]), 'stocké en dollars');
        $this->assertStringEndsWith('/media/secure/' . self::IMAGE_KEY, (string) $values[$value['id']]['iconUrl']);

        $added = $this->json($this->request('POST', '/api/customization-options/' . $option['id'] . '/values?locale=fr', ['name' => 'Gravure dorée', 'priceDelta' => 1200]), 201);
        $this->assertSame(['Gravure dorée', 1200], [$added['name'], $added['priceDelta']]);
        $this->assertSame(409, $this->request('DELETE', '/api/customization-values/' . $value['id'])->getStatusCode(), 'valeur utilisée par une combinaison');

        $created = $this->json($this->request('POST', "/api/product-variants/$variant/customization-combinations", ['optionIds' => [$added['id']], 'image' => self::IMAGE_KEY, 'stock' => 3]), 201);
        $combination = array_values(array_filter($created['combinations'], fn ($c) => $c['optionIds'] === [$added['id']]))[0];
        $this->assertSame(3, $combination['stock']);
        $this->assertSame(422, $this->request('POST', "/api/product-variants/$variant/customization-combinations", ['optionIds' => [$added['id']], 'image' => self::IMAGE_KEY])->getStatusCode(), 'doublon');
        $this->assertSame(422, $this->request('POST', "/api/product-variants/$variant/customization-combinations", ['optionIds' => [999999], 'image' => self::IMAGE_KEY])->getStatusCode());

        $patched = $this->json($this->request('PATCH', '/api/customization-combinations/' . $combination['id'], ['stock' => 8]));
        $this->assertSame(8, array_values(array_filter($patched['combinations'], fn ($c) => $c['id'] === $combination['id']))[0]['stock']);
        $afterDelete = $this->json($this->request('DELETE', '/api/customization-combinations/' . $combination['id']));
        $this->assertNotContains($combination['id'], array_column($afterDelete['combinations'], 'id'));
        $this->assertContains($this->request('DELETE', '/api/customization-values/' . $added['id'])->getStatusCode(), [200, 204], 'valeur libérée : supprimée');
    }

    public function testPlanTextsAreEditableButNotTheirPrice(): void
    {
        $plans = $this->json($this->request('GET', '/api/subscription-plans?productId=' . $this->id("SELECT id FROM product WHERE code = 'DEMO-PANIER'")));
        $plan = $plans[0];
        // la base de test est partagée : les formules retrouvent leur état d'origine à la fin
        $saved = $this->db->fetchAllAssociative('SELECT id, names::text AS names, descriptions::text AS descriptions, features::text AS features, badges::text AS badges, highlighted FROM subscription_plan WHERE product_id = ?', [$plan['productId']]);
        try {
            $this->planAssertions($plan);
        } finally {
            foreach ($saved as $row) {
                $this->db->executeStatement('UPDATE subscription_plan SET names = ?::json, descriptions = ?::json, features = ?::json, badges = ?::json, highlighted = ? WHERE id = ?',
                    [$row['names'], $row['descriptions'], $row['features'], $row['badges'], $row['highlighted'] ? 'true' : 'false', $row['id']]);
            }
        }
    }

    private function planAssertions(array $plan): void
    {
        $patched = $this->json($this->request('PATCH', '/api/subscription-plans/' . $plan['id'] . '?locale=en', [
            'name' => 'Weekly box', 'description' => 'Fresh every week', 'features' => ['Local produce', 'Cancel anytime'], 'badge' => 'Best value', 'highlighted' => true,
        ]));
        $this->assertSame(['Weekly box', 'Fresh every week', ['Local produce', 'Cancel anytime'], 'Best value', true], [$patched['name'], $patched['description'], $patched['features'], $patched['badge'], $patched['highlighted']]);
        $this->assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM subscription_plan WHERE product_id = ? AND highlighted', [$plan['productId']]), 'une seule recommandée');
        $fr = array_values(array_filter($this->json($this->request('GET', '/api/subscription-plans?locale=fr&productId=' . $plan['productId'])), fn ($p) => $p['id'] === $plan['id']))[0];
        $this->assertSame($plan['name'], $fr['name'], 'le français reste inchangé');

        $refused = $this->request('PATCH', '/api/subscription-plans/' . $plan['id'], ['price' => 100]);
        $this->assertSame(422, $refused->getStatusCode(), 'prix, périodicité et essai : administration seulement');
        $this->assertSame(422, $this->request('PATCH', '/api/subscription-plans/' . $plan['id'], ['features' => array_fill(0, 21, 'x')])->getStatusCode());
        $this->assertSame(422, $this->request('PATCH', '/api/subscription-plans/' . $plan['id'], ['badge' => '<i>x</i>'])->getStatusCode());
    }

    public function testFeaturesAreCreatedRenamedReorderedRestoredAndDeleted(): void
    {
        $this->db->executeStatement('DELETE FROM feature_translation');
        $this->db->executeStatement('DELETE FROM feature');
        $ids = [];
        foreach (['Livraison rapide', 'Paiement sécurisé', 'Retours gratuits'] as $title) {
            $ids[] = $this->json($this->request('POST', '/api/features?locale=fr', ['title' => $title, 'icon' => self::IMAGE_KEY]), 201)['id'];
        }
        $renamed = $this->json($this->request('PATCH', '/api/features/' . $ids[0] . '?locale=en', ['title' => 'Fast delivery']));
        $this->assertSame('Fast delivery', $renamed['title']);
        $this->assertSame(422, $this->request('PATCH', '/api/features/' . $ids[0], ['title' => str_repeat('a', 101)])->getStatusCode(), '100 caractères au plus');

        $reordered = $this->json($this->request('PUT', '/api/features/order', ['order' => [$ids[2], $ids[0], $ids[1]]]));
        $this->assertSame([$ids[2], $ids[0], $ids[1]], array_column($reordered, 'id'));
        $this->assertSame([$ids[2], $ids[0], $ids[1]], array_column($this->json($this->request('GET', '/api/features?locale=fr')), 'id'), 'ordre lu par le site');
        $this->assertSame(422, $this->request('PUT', '/api/features/order', ['order' => [$ids[0]]])->getStatusCode());

        $entry = array_values(array_filter($this->json($this->request('GET', '/api/landingpage-audit?resource=features'))['items'], fn ($e) => $e['action'] === 'reorder'))[0];
        $this->assertSame(200, $this->request('POST', '/api/landingpage-audit/' . $entry['id'] . '/restore')->getStatusCode());
        $this->assertSame($ids, array_column($this->json($this->request('GET', '/api/features?locale=fr')), 'id'), 'ordre rétabli');

        $this->assertSame(204, $this->request('DELETE', '/api/features/' . $ids[1])->getStatusCode());
        $this->assertCount(2, $this->json($this->request('GET', '/api/features?locale=fr')));
    }

    public function testOnlyAnAdminWritesShopData(): void
    {
        $variant = $this->id("SELECT v.id FROM product_variant v JOIN product p ON p.id = v.product_id WHERE p.code = 'DEMO-SAC' LIMIT 1");
        $this->loginAs(['ROLE_USER_INTERNET']);
        $this->assertSame(403, $this->request('DELETE', "/api/product-variants/$variant")->getStatusCode(), 'un client ne supprime plus une variante');
        $this->assertSame(403, $this->request('PATCH', "/api/product-variants/$variant", ['stockQuantity' => 0])->getStatusCode());
        $this->assertSame(403, $this->request('POST', '/api/colors', ['name' => 'x'])->getStatusCode());
        $this->assertSame(403, $this->request('POST', '/api/features', ['title' => 'x'])->getStatusCode());
        $this->session = [];
        $this->assertContains($this->request('PATCH', '/api/subscription-plans/1', ['name' => 'x'])->getStatusCode(), [401, 403]);
    }

    private function id(string $sql): int
    {
        return (int) $this->db->fetchOne($sql);
    }

    private function json(Response $response, int $status = 200): array
    {
        $this->assertSame($status, $response->getStatusCode(), (string) $response->getContent());

        return json_decode((string) $response->getContent(), true);
    }

    private function request(string $method, string $path, ?array $body = null): Response
    {
        $jar = $this->client->getCookieJar();
        $jar->clear();
        foreach ($this->session as $name => $value) {
            $jar->set(new \Symfony\Component\BrowserKit\Cookie($name, $value, null, '/', MV_TEST_TENANT_HOST, true));
        }
        $this->client->request($method, 'https://' . MV_TEST_TENANT_HOST . $path, [], [], array_filter([
            'HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_XSRF_TOKEN' => $this->session['XSRF-TOKEN_' . MV_TEST_TENANT_CODE] ?? null,
        ]), $body === null ? null : json_encode($body, JSON_UNESCAPED_UNICODE));

        return $this->client->getResponse();
    }

    private function loginAs(array $roles): void
    {
        $email = 'catalogue-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $container = static::getContainer();
        $provider = $container->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Catalogue')->setLastname('Boutique')->setRoles($roles)->setIsVerified(true);
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD));
        $provider->getEntityManager()->persist($user);
        $provider->getEntityManager()->flush();

        $this->client->getCookieJar()->clear();
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . '/api/login', [], [], [
            'HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
        ], json_encode(['username' => $email, 'password' => self::PASSWORD, 'platform' => 'web']));
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());
        $this->session = [];
        foreach ($this->client->getResponse()->headers->getCookies() as $cookie) {
            $this->session[$cookie->getName()] = $cookie->getValue();
        }
    }
}
