<?php

namespace App\Tests\Functional\Boutique;

use App\Entity\User;
use App\Services\BoutiqueDemoService\BoutiqueDemoSeeder;
use App\Services\ShippingService\EasyPostService;
use App\Services\TenantEntityManagerProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Livraison, lot 1 (07/11/2026) : réglages d'expédition dans le registre (mode, environnement EasyPost, poids par défaut, livraison
 * offerte), retrait en magasin et gratuité dans le devis, nature des transporteurs dans l'API, environnement EasyPost par site,
 * page Livraison et cheminement, expédition manuelle d'une commande (statut, suivi, courriel, lecture par le client), livraison.
 */
class ShippingLot1Test extends WebTestCase
{
    private KernelBrowser $client;
    private \Doctrine\DBAL\Connection $db;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $this->db = $provider->getEntityManager()->getConnection();
        static::getContainer()->get(BoutiqueDemoSeeder::class)->seed('client-livraison@example.invalid', false, false);
        $this->db->executeStatement('DELETE FROM boutique_setting');
        $this->db->executeStatement("UPDATE address_entreprise SET country = 'CA', state = 'QC'");
    }

    public function testShippingSettingsAreInTheRegistryAndDriveTheQuote(): void
    {
        $features = fn () => array_column(json_decode((string) $this->api('GET', '/api/site/features', null, 'admin')->getContent(), true)['features'], null, 'key');
        $f = $features();
        $this->assertSame(['none', 'test', 0.5, null], [$f['shipping.mode']['value'], $f['shipping.easypost_environment']['value'], $f['shipping.default_weight_kg']['value'], $f['shipping.free_above']['value']], 'valeurs par défaut');
        $this->assertSame('shipping', $f['shipping.mode']['href']);

        $refused = $this->api('PUT', '/api/site/features', ['values' => ['shipping.mode' => 'drone', 'shipping.free_above' => -1]], 'admin');
        $this->assertSame(422, $refused->getStatusCode(), $refused->getContent());
        $paths = array_column(json_decode((string) $refused->getContent(), true)['errors'], 'path');
        $this->assertContains('shipping.mode', $paths);
        $this->assertContains('shipping.free_above', $paths);

        $ok = $this->api('PUT', '/api/site/features', ['values' => ['shipping.mode' => 'own_key', 'shipping.easypost_environment' => 'live', 'shipping.default_weight_kg' => 1.25, 'shipping.free_above' => 5000]], 'admin');
        $this->assertSame(200, $ok->getStatusCode(), $ok->getContent());
        $f = $features();
        $this->assertSame(['own_key', 'live', 1.25, 5000], [$f['shipping.mode']['value'], $f['shipping.easypost_environment']['value'], $f['shipping.default_weight_kg']['value'], $f['shipping.free_above']['value']]);
        $this->assertSame('incomplete', $f['shipping.mode']['status'], 'clé EasyPost réelle absente');
        $this->assertSame('live', $f['shipping.easypost']['details']['environment']);

        // L'environnement EasyPost suit le réglage du site, plus l'environnement Symfony
        $easypost = static::getContainer()->get(EasyPostService::class);
        $this->assertSame('live', $easypost->environment());
        $this->api('PUT', '/api/site/features', ['values' => ['shipping.easypost_environment' => 'test']], 'admin');
        $this->assertSame('test', $easypost->environment());

        // Devis : retrait en magasin = 0 ; livraison offerte au-dessus du seuil sur un transporteur payant ; sous le seuil, prix fixe
        $variant = (int) $this->db->fetchOne("SELECT v.id FROM product_variant v JOIN product p ON p.id = v.product_id WHERE p.code = 'DEMO-CASQUETTE' ORDER BY v.id LIMIT 1");
        $this->assertGreaterThan(0, $variant);
        $pickup = (int) $this->db->fetchOne("SELECT id FROM carrier WHERE kind = 'pickup' ORDER BY id LIMIT 1");
        $paid = (int) $this->db->fetchOne("INSERT INTO carrier (id, name, description, price, created_at, update_at, kind) VALUES (nextval('carrier_id_seq'), 'Livraison test', 'test', 1500, NOW(), NOW(), 'fixed') RETURNING id");
        $quote = fn (int $carrierId, int $quantity) => json_decode((string) $this->api('POST', '/api/cart/quote', ['items' => [['productVariantId' => $variant, 'quantity' => $quantity]], 'carrierId' => $carrierId, 'shippingAddress' => ['country' => 'CA', 'province' => 'QC']], null)->getContent(), true);
        $q = $quote($pickup, 1);
        $this->assertSame([0, 'pickup'], [$q['shipping'], $q['carrier']['kind']], 'retrait : aucuns frais');
        $q = $quote($paid, 1);
        $this->assertSame(1500, $q['shipping'], 'sous le seuil de 50 $ : prix fixe');
        $this->assertFalse($q['carrier']['freeShippingApplied']);
        $q = $quote($paid, 4);
        $this->assertGreaterThanOrEqual(5000, $q['subtotal']);
        $this->assertSame([0, true], [$q['shipping'], $q['carrier']['freeShippingApplied']], 'au-dessus du seuil : offerte');
        $this->api('PUT', '/api/site/features', ['values' => ['shipping.free_above' => null]], 'admin');
        $this->assertSame(1500, $quote($paid, 4)['shipping'], 'seuil retiré : prix fixe');

        // API publique des transporteurs : nature et retrait (liste en cache : les transporteurs insérés en SQL ne l'invalident pas)
        static::getContainer()->get(\App\Services\TenantCacheService::class)->invalidateSite(MV_TEST_TENANT_CODE, MV_TEST_TENANT_DB);
        $carriers = array_column(json_decode((string) $this->api('GET', '/api/Carrier', null, null)->getContent(), true), null, 'id');
        $this->assertArrayHasKey($paid, $carriers, 'un transporteur sans traduction reste proposé (nom de base)');
        $this->assertSame(['pickup', true], [$carriers[$pickup]['kind'], $carriers[$pickup]['isPickup']]);
        $this->assertSame(['fixed', false], [$carriers[$paid]['kind'], $carriers[$paid]['isPickup']]);

        // Cheminement du site : l'étape Livraison mène à la page Livraison ; en mode own_key, les poids manquants sont une étape
        static::getContainer()->get(\App\Services\Platform\EntitlementService::class)->grant(MV_TEST_TENANT_CODE, 'module.boutique', 'manual', null, 'test', null, null, null, false);
        $steps = array_column(json_decode((string) $this->api('GET', '/api/site/readiness', null, 'admin')->getContent(), true)['steps'], null, 'key');
        $this->assertSame('shipping', $steps['boutique.carrier']['action']['screen']);
        $this->assertSame('todo', $steps['boutique.shipping_weights']['status'], 'produits de démonstration sans poids');
        $this->api('PUT', '/api/site/features', ['values' => ['shipping.mode' => 'none']], 'admin');
        $steps = array_column(json_decode((string) $this->api('GET', '/api/site/readiness', null, 'admin')->getContent(), true)['steps'], null, 'key');
        $this->assertArrayNotHasKey('boutique.shipping_weights', $steps, 'sans estimation, les poids ne sont pas exigés');
    }

    public function testTheMerchantShipsAnOrderHimselfAndTheCustomerSeesTheTracking(): void
    {
        $admin = $this->loginAdmin();
        [$customerEmail, $customerId] = $this->customer();
        $carrierId = (int) $this->db->fetchOne("SELECT id FROM carrier WHERE kind = 'fixed' ORDER BY id LIMIT 1");
        $orderId = (int) $this->db->fetchOne("INSERT INTO \"order\" (id, status_id, reference, order_date, total_amount, user_id_id, carrier_id, order_source_id) VALUES (nextval('order_id_seq'), 3, ?, NOW(), 57.49, ?, ?, 1) RETURNING id", ['LV-' . bin2hex(random_bytes(3)), $customerId, $carrierId]);

        // Page Livraison et actions sur la commande
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin/livraison');
        $html = html_entity_decode((string) $this->client->getResponse()->getContent());
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertStringContainsString('Préparer la livraison', $html);
        $this->assertStringContainsString('Retrait en magasin', $html, 'transporteur de retrait fourni d\'office');
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin?' . http_build_query(['crudAction' => 'detail', 'crudControllerFqcn' => 'App\Controller\Admin\OrderCrudController', 'entityId' => $orderId]));
        $this->assertStringContainsString('routeName=admin_order_ship', (string) $this->client->getResponse()->getContent(), 'action « Marquer expédiée » sur une commande payée');

        // Réglages depuis la page : seuil en dollars → cents
        $crawler = $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin/livraison');
        $token = $crawler->filter('form[data-livraison-form] input[name="_token"]')->attr('value');
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . '/admin/livraison/regler', ['_token' => $token, 'mode' => 'none', 'easypost_environment' => 'test', 'default_weight_kg' => '0,8', 'free_above' => '75']);
        $this->assertSame(302, $this->client->getResponse()->getStatusCode());
        $this->assertSame([7500, 0.8], [(int) $this->db->fetchOne("SELECT (configuration::jsonb -> 'commerce' ->> 'freeShippingAbove')::int FROM boutique_setting ORDER BY id LIMIT 1"), (float) $this->db->fetchOne("SELECT (configuration::jsonb -> 'commerce' ->> 'defaultWeightKg')::float FROM boutique_setting ORDER BY id LIMIT 1")]);

        // Expédition : page, saisie refusée puis acceptée, statut 5, suivi déduit, courriel au client
        $crawler = $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . "/admin/orders/$orderId/expedier");
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertStringContainsString('data-ship-form', (string) $this->client->getResponse()->getContent());
        $token = $crawler->filter('form[data-ship-form] input[name="_token"]')->attr('value');
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . "/admin/orders/$orderId/expedier", ['_token' => $token, 'carrier' => '', 'trackingNumber' => 'X', 'notify' => '1']);
        $this->assertStringContainsString('carrier :', html_entity_decode((string) $this->client->getResponse()->getContent()), 'transporteur obligatoire');
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . "/admin/orders/$orderId/expedier", ['_token' => $token, 'carrier' => 'Postes Canada', 'trackingNumber' => '7000123456789', 'notify' => '1']);
        $this->assertSame(302, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
        $this->assertEmailCount(1, null, 'courriel au client');
        $mail = $this->getMailerMessage();
        $this->assertSame($customerEmail, $mail->getTo()[0]->getAddress());
        $this->assertStringContainsString('7000123456789', (string) $mail->getHtmlBody());
        $this->assertStringContainsString('canadapost-postescanada.ca', (string) $mail->getHtmlBody(), 'lien de suivi déduit');
        $row = $this->db->fetchAssociative('SELECT status_id, shipped_at, tracking_carrier, tracking_number, tracking_url FROM "order" WHERE id = ?', [$orderId]);
        $this->assertSame([5, 'Postes Canada', '7000123456789'], [(int) $row['status_id'], $row['tracking_carrier'], $row['tracking_number']]);
        $this->assertNotNull($row['shipped_at']);
        $this->assertStringContainsString('searchFor=7000123456789', (string) $row['tracking_url']);
        $this->assertSame('ship', $this->db->fetchOne("SELECT action FROM content_audit_log WHERE resource = 'order' AND resource_id = ? ORDER BY id DESC LIMIT 1", [(string) $orderId]));

        // Déjà expédiée : une seconde expédition est refusée, la page propose « Marquer livrée », puis livrée (statut 6)
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . "/admin/orders/$orderId/expedier", ['_token' => $token, 'carrier' => 'UPS', 'notify' => '1']);
        $this->assertStringContainsString('déjà expédiée', html_entity_decode((string) $this->client->getResponse()->getContent()));
        $crawler = $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . "/admin/orders/$orderId/expedier");
        $this->assertStringContainsString('data-deliver-form', (string) $this->client->getResponse()->getContent());
        $deliverToken = $crawler->filter('div[data-deliver-form] input[name="_token"]')->attr('value');
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . "/admin/orders/$orderId/livree", ['_token' => $deliverToken]);
        $this->assertSame(302, $this->client->getResponse()->getStatusCode());
        $this->assertSame(6, (int) $this->db->fetchOne('SELECT status_id FROM "order" WHERE id = ?', [$orderId]));

        // Vente au comptoir et commande annulée : jamais d'expédition
        $counter = (int) $this->db->fetchOne("INSERT INTO \"order\" (id, status_id, reference, order_date, total_amount, user_id_id, order_source_id) VALUES (nextval('order_id_seq'), 3, ?, NOW(), 10, ?, 2) RETURNING id", ['POS-' . bin2hex(random_bytes(3)), $customerId]);
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . "/admin/orders/$counter/expedier");
        $this->assertStringContainsString('Vente au comptoir', (string) $this->client->getResponse()->getContent());
        $cancelled = (int) $this->db->fetchOne("INSERT INTO \"order\" (id, status_id, reference, order_date, total_amount, user_id_id, order_source_id) VALUES (nextval('order_id_seq'), 7, ?, NOW(), 0, ?, 1) RETURNING id", ['ANN-' . bin2hex(random_bytes(3)), $customerId]);
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . "/admin/orders/$cancelled/expedier", ['_token' => $token, 'carrier' => 'UPS', 'notify' => '1']);
        $this->assertStringContainsString('annulée', (string) $this->client->getResponse()->getContent());
        $this->assertSame(7, (int) $this->db->fetchOne('SELECT status_id FROM "order" WHERE id = ?', [$cancelled]));

        // Le client lit le suivi dans sa commande (jeton, hors session admin)
        $customerToken = static::getContainer()->get('lexik_jwt_authentication.jwt_manager')->create($this->userByEmail($customerEmail));
        $order = json_decode((string) $this->api('GET', "/api/orders/$orderId", null, null, $customerToken)->getContent(), true);
        $this->assertSame(['Postes Canada', '7000123456789'], [$order['shipment']['carrier'], $order['shipment']['trackingNumber']]);
        $this->assertStringContainsString('canadapost', (string) $order['shipment']['trackingUrl']);

        // Un simple client n'ouvre pas la page d'expédition
        $this->client->loginUser($this->userByEmail($customerEmail), 'main');
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . "/admin/orders/$orderId/expedier");
        $this->assertContains($this->client->getResponse()->getStatusCode(), [302, 403]);
    }

    private function api(string $method, string $path, ?array $body, ?string $as, ?string $bearer = null): Response
    {
        $server = ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json', 'HTTP_X_XSRF_TOKEN' => 'jeton-xsrf-de-test'];
        if ($as === 'admin') {
            $bearer = static::getContainer()->get('lexik_jwt_authentication.jwt_manager')->create($this->userByEmail('admin@arkanoa-media.com'));
        }
        if ($bearer !== null) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer ' . $bearer;
        }
        $this->client->getCookieJar()->clear();
        $this->client->request($method, 'https://' . MV_TEST_TENANT_HOST . $path . (str_contains($path, '?') ? '&' : '?') . 'locale=fr', [], [], $server, $body !== null ? json_encode($body) : null);

        return $this->client->getResponse();
    }

    private function userByEmail(string $email): User
    {
        $user = static::getContainer()->get(TenantEntityManagerProvider::class)->getEntityManager()->getRepository(User::class)->findOneBy(['email' => $email]);
        $this->assertNotNull($user, "compte $email présent");

        return $user;
    }

    private function loginAdmin(): User
    {
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $email = 'livreur-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Admin')->setLastname('Livraison')->setRoles(['ROLE_USER', 'ROLE_USER_INTERNET', 'ROLE_ADMIN'])->setIsVerified(true)->setPassword('x');
        $provider->getEntityManager()->persist($user);
        $provider->getEntityManager()->flush();
        $this->client->setServerParameter('HTTP_X_TENANT_HOST', MV_TEST_TENANT_HOST);
        $this->client->loginUser($user, 'main');
        \App\Tests\Functional\Support\OtpTestHelper::validateIfRequired($this->client, $user);

        return $user;
    }

    /** @return array{0: string, 1: int} */
    private function customer(): array
    {
        $email = 'client-colis-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $em = static::getContainer()->get(TenantEntityManagerProvider::class)->getEntityManager();
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Marie')->setLastname('Tremblay')->setRoles(['ROLE_USER', 'ROLE_USER_INTERNET'])->setIsVerified(true)->setPassword('x');
        $em->persist($user);
        $em->flush();

        return [$email, (int) $user->getId()];
    }
}
