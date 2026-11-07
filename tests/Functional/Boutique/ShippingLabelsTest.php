<?php

namespace App\Tests\Functional\Boutique;

use App\Entity\User;
use App\Services\BoutiqueDemoService\BoutiqueDemoSeeder;
use App\Services\Platform\ShippingBalanceRepository;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use App\Tests\Fake\FakeEasyPostGateway;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Livraison, lot 2 (07/11/2026) : étiquettes par la plateforme avec solde prépayé (réglages et crédit par le super admin, API et page),
 * devis d'une commande (colis, tarifs dans la devise du site, frais de service), refus sans solde, achat du tarif exact (jamais un autre),
 * lignes d'expédition, débit et frais enregistrés, commande expédiée avec suivi et courriel, suivi automatique → livrée, mode clé propre.
 */
class ShippingLabelsTest extends WebTestCase
{
    private KernelBrowser $client;
    private \Doctrine\DBAL\Connection $db;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $this->db = $provider->getEntityManager()->getConnection();
        static::getContainer()->get(BoutiqueDemoSeeder::class)->seed('client-etiquette@example.invalid', false, false);
        $this->db->executeStatement('DELETE FROM boutique_setting');
        $this->db->executeStatement("UPDATE address_entreprise SET country = 'CA', state = 'QC', phone = COALESCE(NULLIF(phone, ''), '+1 514 555 0199'), street1 = COALESCE(NULLIF(street1, ''), '1 rue du Test'), city = COALESCE(NULLIF(city, ''), 'Montréal'), zip = COALESCE(NULLIF(zip, ''), 'H1A 1A1')");
        FakeEasyPostGateway::reset();
        $master = static::getContainer()->get(TenantConnectionManager::class)->getPdoMaster();
        $master->exec("DELETE FROM platform_shipping_balance_events; DELETE FROM platform_shipping_charges; DELETE FROM platform_shipping_balances; DELETE FROM platform_settings WHERE key IN ('shipping_fee_bp', 'shipping_fee_fixed_cents', 'easypost_platform_carriers')");
    }

    public function testThePlatformOwnerSetsFeesCarriersAndCreditsASite(): void
    {
        $this->assertSame(403, $this->api('GET', '/api/platform/shipping', null, 'admin')->getStatusCode(), 'réservé au super administrateur');
        $r = $this->api('PUT', '/api/platform/shipping/settings', ['feeBp' => 1000, 'feeFixedCents' => 50, 'carriers' => [['id' => 'ca_plateforme_postes', 'carrier' => 'CanadaPost', 'label' => 'Postes Canada'], ['id' => 'ca_plateforme_usps', 'carrier' => 'USPS', 'label' => 'USPS']]], 'super');
        $this->assertSame(200, $r->getStatusCode(), $r->getContent());
        $this->assertSame(422, $this->api('PUT', '/api/platform/shipping/settings', ['carriers' => [['id' => 'pas-un-compte']]], 'super')->getStatusCode());
        $this->assertSame(422, $this->api('POST', '/api/platform/shipping/balances/nulle-part/credit', ['amountCents' => 5000], 'super')->getStatusCode(), 'site inconnu');
        $r = $this->api('POST', '/api/platform/shipping/balances/' . MV_TEST_TENANT_CODE . '/credit', ['amountCents' => 5000, 'note' => 'cadeau de bienvenue'], 'super');
        $this->assertSame(200, $r->getStatusCode(), $r->getContent());
        $this->assertSame(['balanceCents' => 5000, 'currency' => 'CAD'], json_decode((string) $r->getContent(), true));
        $all = json_decode((string) $this->api('GET', '/api/platform/shipping', null, 'super')->getContent(), true);
        $this->assertSame([1000, 50, 2], [$all['settings']['feeBp'], $all['settings']['feeFixedCents'], count($all['settings']['carriers'])]);
        $this->assertSame(5000, array_column($all['balances'], 'balanceCents', 'tenantCode')[MV_TEST_TENANT_CODE]);
        $detail = json_decode((string) $this->api('GET', '/api/platform/shipping/balances/' . MV_TEST_TENANT_CODE, null, 'super')->getContent(), true);
        $this->assertSame(['credit', 5000, 'cadeau de bienvenue'], [$detail['events'][0]['kind'], $detail['events'][0]['deltaCents'], $detail['events'][0]['note']]);

        // Le site voit son solde ; le registre exige la clé de la plateforme et un solde positif en mode plateforme
        $this->api('PUT', '/api/site/features', ['values' => ['shipping.mode' => 'platform']], 'admin');
        $site = json_decode((string) $this->api('GET', '/api/site/shipping', null, 'admin')->getContent(), true);
        $this->assertSame(5000, $site['balance']['balanceCents']);
        $this->assertSame('done', array_column($site['overview']['steps'], 'status', 'key')['shipping.platform']);
        $f = array_column(json_decode((string) $this->api('GET', '/api/site/features', null, 'admin')->getContent(), true)['features'], null, 'key');
        $this->assertSame('ready', $f['shipping.mode']['status'], json_encode($f['shipping.mode']['prerequisites']));

        // Page Plateforme : section et crédit par formulaire
        $super = $this->login(['ROLE_USER', 'ROLE_USER_INTERNET', 'ROLE_ADMIN', 'ROLE_SUPER_ADMIN']);
        $crawler = $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin/plateforme');
        $html = html_entity_decode((string) $this->client->getResponse()->getContent());
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertStringContainsString('Étiquettes par la plateforme', $html);
        $this->assertStringContainsString('ca_plateforme_postes|CanadaPost|Postes Canada', $html);
        $token = $crawler->filter('form.plateforme-crediter input[name="_token"]')->attr('value');
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . '/admin/plateforme/livraison/credit', ['_token' => $token, 'tenant' => MV_TEST_TENANT_CODE, 'amount' => '12,50', 'note' => 'appoint']);
        $this->assertSame(302, $this->client->getResponse()->getStatusCode());
        $this->assertSame(6250, static::getContainer()->get(ShippingBalanceRepository::class)->balance(MV_TEST_TENANT_CODE)['balanceCents']);
    }

    public function testTheMerchantBuysALabelWithThePrepaidBalance(): void
    {
        $this->api('PUT', '/api/platform/shipping/settings', ['feeBp' => 1000, 'feeFixedCents' => 50, 'carriers' => [['id' => 'ca_plateforme_postes', 'carrier' => 'CanadaPost', 'label' => 'Postes Canada'], ['id' => 'ca_plateforme_usps', 'carrier' => 'USPS', 'label' => 'USPS']]], 'super');
        $this->api('PUT', '/api/site/features', ['values' => ['shipping.mode' => 'platform']], 'admin');
        $admin = $this->login(['ROLE_USER', 'ROLE_USER_INTERNET', 'ROLE_ADMIN']);
        [$orderId, $customerEmail] = $this->order();

        // Sans solde : page bloquée sur le tarif, achat refusé
        $crawler = $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . "/admin/orders/$orderId/etiquette");
        $html = html_entity_decode((string) $this->client->getResponse()->getContent());
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertStringContainsString('Colis calculés (1)', $html);
        $this->assertStringContainsString('ExpeditedParcel', $html);
        $this->assertSame(2, $crawler->filter('input[name="rate"]')->count(), 'tarif en USD écarté : le site est en CAD');
        $this->assertStringContainsString('devise USD', $html);
        $this->assertStringContainsString('14,25 CAD', $html, '12,50 + 10 % (1,25) + 0,50 de frais');
        $this->assertStringContainsString('Solde insuffisant', $html);
        $token = $crawler->filter('form[data-label-form] input[name="_token"]')->attr('value');
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . "/admin/orders/$orderId/etiquette", ['_token' => $token, 'rate' => 'ca_plateforme_postes|ExpeditedParcel', 'notify' => '1']);
        $this->client->followRedirect();
        $this->assertStringContainsString('insuffisant', html_entity_decode((string) $this->client->getResponse()->getContent()));
        $this->assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM shipping_order WHERE odershipping_id = ?', [$orderId]));
        $this->assertSame(0, FakeEasyPostGateway::$labels, 'rien n\'est acheté sans solde');

        // Crédit, tarif introuvable refusé sans substitution, puis achat du tarif exact
        $this->api('POST', '/api/platform/shipping/balances/' . MV_TEST_TENANT_CODE . '/credit', ['amountCents' => 2000], 'super');
        $this->client->loginUser($admin, 'main'); // nouvelle session (l'appel API a vidé les cookies) : nouveau jeton CSRF
        $token = $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . "/admin/orders/$orderId/etiquette")->filter('form[data-label-form] input[name="_token"]')->attr('value');
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . "/admin/orders/$orderId/etiquette", ['_token' => $token, 'rate' => 'ca_plateforme_postes|Inexistant', 'notify' => '1']);
        $this->client->followRedirect();
        $this->assertStringContainsString('plus proposé', html_entity_decode((string) $this->client->getResponse()->getContent()));
        $this->assertSame(2000, static::getContainer()->get(ShippingBalanceRepository::class)->balance(MV_TEST_TENANT_CODE)['balanceCents'], 'rien n\'est débité');

        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . "/admin/orders/$orderId/etiquette", ['_token' => $token, 'rate' => 'ca_plateforme_postes|ExpeditedParcel', 'notify' => '1']);
        $this->assertSame(302, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
        $this->assertEmailCount(1, null, 'le client reçoit le suivi');
        $this->assertStringContainsString('TRK00000001', (string) $this->getMailerMessage()->getHtmlBody());
        $buy = array_values(array_filter(FakeEasyPostGateway::$requests, fn ($r) => $r['kind'] === 'buy'));
        $this->assertCount(1, $buy);
        $this->assertSame(['ca_plateforme_postes', 'ExpeditedParcel'], [$buy[0]['carrierAccountId'], $buy[0]['service']]);
        $this->assertSame('PDF', $buy[0]['data']['options']['label_format'] ?? null, 'étiquette en PDF');
        $this->assertGreaterThan(0, $buy[0]['data']['parcel']['weight'], 'poids en onces');
        $this->assertSame('QC', $buy[0]['data']['to_address']['state']);
        $this->assertArrayNotHasKey('customs_info', $buy[0]['data'], 'envoi national : pas de douane');
        $row = $this->db->fetchAssociative('SELECT status_id, tracking_number, tracking_carrier FROM "order" WHERE id = ?', [$orderId]);
        $this->assertSame([5, 'TRK00000001', 'CanadaPost (ExpeditedParcel)'], [(int) $row['status_id'], $row['tracking_number'], $row['tracking_carrier']]);
        $this->assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM shipping_label WHERE tracking_code = ?', ['TRK00000001']));
        $this->assertSame(2000 - 1425, static::getContainer()->get(ShippingBalanceRepository::class)->balance(MV_TEST_TENANT_CODE)['balanceCents'], 'coût + frais débités');
        $charge = static::getContainer()->get(ShippingBalanceRepository::class)->charges(MV_TEST_TENANT_CODE)[0];
        $this->assertSame([1250, 175, 1425, 'CAD', ['TRK00000001'], 'test'], [$charge['easypostCostCents'], $charge['feeCents'], $charge['totalCents'], $charge['currency'], $charge['trackingCodes'], $charge['environment']]);
        $this->client->followRedirect();
        $this->assertStringContainsString('Étiquette(s) achetée(s)', html_entity_decode((string) $this->client->getResponse()->getContent()));
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . "/admin/orders/$orderId/etiquette");
        $this->assertStringContainsString('data-label-bought', (string) $this->client->getResponse()->getContent(), 'étiquette déjà achetée : lien PDF, pas de second achat');

        // Le client voit le suivi ; le suivi automatique passe la commande « Livrée »
        $customerToken = static::getContainer()->get('lexik_jwt_authentication.jwt_manager')->create($this->userByEmail($customerEmail));
        $order = json_decode((string) $this->api('GET', "/api/orders/$orderId", null, null, $customerToken)->getContent(), true);
        $this->assertSame('TRK00000001', $order['shipment']['trackingNumber']);
        FakeEasyPostGateway::$trackingStatus = 'in_transit';
        $this->track();
        $this->assertSame(5, (int) $this->db->fetchOne('SELECT status_id FROM "order" WHERE id = ?', [$orderId]));
        FakeEasyPostGateway::$trackingStatus = 'delivered';
        $this->track();
        $this->assertSame(6, (int) $this->db->fetchOne('SELECT status_id FROM "order" WHERE id = ?', [$orderId]), 'livrée par le suivi automatique');
    }

    public function testOwnKeyModeNeedsNoBalanceAndInternationalShipmentsCarryCustoms(): void
    {
        $this->api('PUT', '/api/site/features', ['values' => ['shipping.mode' => 'own_key', 'shipping.easypost_environment' => 'test']], 'admin');
        // Un transporteur EasyPost propre au test : ne jamais modifier les transporteurs de départ (d'autres tests comptent sur le n° 1 à prix fixe)
        $this->db->executeStatement("INSERT INTO carrier (id, name, description, price, created_at, update_at, kind, carrier_account_id) VALUES (nextval('carrier_id_seq'), 'Postes Canada (test)', 'tarif en direct', 0, NOW(), NOW(), 'easypost', 'ca_plateforme_postes')");
        $this->login(['ROLE_USER', 'ROLE_USER_INTERNET', 'ROLE_ADMIN']);
        [$orderId] = $this->order('US', 'NY', '10001');
        $crawler = $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . "/admin/orders/$orderId/etiquette");
        $html = html_entity_decode((string) $this->client->getResponse()->getContent());
        $this->assertStringContainsString('déclaration douanière', $html);
        $this->assertStringNotContainsString('Frais de service', $html, 'clé propre : pas de frais de plateforme');
        $token = $crawler->filter('form[data-label-form] input[name="_token"]')->attr('value');
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . "/admin/orders/$orderId/etiquette", ['_token' => $token, 'rate' => 'ca_plateforme_postes|Xpresspost', 'notify' => '0']);
        $this->assertSame(302, $this->client->getResponse()->getStatusCode());
        $buy = array_values(array_filter(FakeEasyPostGateway::$requests, fn ($r) => $r['kind'] === 'buy'))[0];
        $this->assertSame('merchandise', $buy['data']['customs_info']['contents_type']);
        $this->assertNotEmpty($buy['data']['customs_info']['customs_items']);
        $this->assertSame('CA', $buy['data']['customs_info']['customs_items'][0]['origin_country']);
        $this->assertSame(0, static::getContainer()->get(ShippingBalanceRepository::class)->balance(MV_TEST_TENANT_CODE)['balanceCents'], 'aucun débit en clé propre');
        $this->assertSame([], static::getContainer()->get(ShippingBalanceRepository::class)->charges(MV_TEST_TENANT_CODE));
        $this->assertSame(5, (int) $this->db->fetchOne('SELECT status_id FROM "order" WHERE id = ?', [$orderId]));
    }

    private function track(): void
    {
        $application = new \Symfony\Bundle\FrameworkBundle\Console\Application(static::$kernel);
        $application->setAutoExit(false);
        $tester = new \Symfony\Component\Console\Tester\ApplicationTester($application);
        $tester->run(['command' => 'app:shipping:track', '--tenant' => MV_TEST_TENANT_CODE]);
        $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        static::getContainer()->get(TenantEntityManagerProvider::class)->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
    }

    /** @return array{0: int, 1: string} commande payée d'une casquette avec adresse de livraison */
    private function order(string $country = 'CA', string $province = 'QC', string $zip = 'H2X 1Y4'): array
    {
        $email = 'client-colis-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $em = static::getContainer()->get(TenantEntityManagerProvider::class)->getEntityManager();
        $user = (new \App\Entity\User())->setEmail($email)->setUsername($email)->setFirstname('Marie')->setLastname('Tremblay')->setRoles(['ROLE_USER', 'ROLE_USER_INTERNET'])->setIsVerified(true)->setPassword('x');
        $address = (new \App\Entity\Adress())->setFirstname('Marie')->setLastname('Tremblay')->setFullname('Marie Tremblay')->setAddress('12 rue des Érables')->setCity($country === 'CA' ? 'Montréal' : 'New York')->setCodepostal($zip)->setCountry($country)->setPhone('+1 514 555 0000')->setProvince($province)->setUserAdress($user);
        $em->persist($user);
        $em->persist($address);
        $em->flush();
        $variant = (int) $this->db->fetchOne("SELECT v.id FROM product_variant v JOIN product p ON p.id = v.product_id WHERE p.code = 'DEMO-CASQUETTE' ORDER BY v.id LIMIT 1");
        $orderId = (int) $this->db->fetchOne("INSERT INTO \"order\" (id, status_id, reference, order_date, total_amount, sub_total, user_id_id, shipping_adress_id, order_source_id) VALUES (nextval('order_id_seq'), 3, ?, NOW(), 57.49, 50, ?, ?, 1) RETURNING id", ['ET-' . bin2hex(random_bytes(3)), $user->getId(), $address->getId()]);
        $this->db->executeStatement("INSERT INTO order_items (id, order_associated_id, product_variant_id, quantity, unit_price, total_price, product_name) VALUES (nextval('order_items_id_seq'), ?, ?, 2, 25.00, 50.00, 'Casquette')", [$orderId, $variant]);

        return [$orderId, $email];
    }

    private function api(string $method, string $path, ?array $body, ?string $as, ?string $bearer = null): Response
    {
        $server = ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json', 'HTTP_X_XSRF_TOKEN' => 'jeton-xsrf-de-test'];
        if ($as === 'admin') {
            $bearer = static::getContainer()->get('lexik_jwt_authentication.jwt_manager')->create($this->userByEmail('admin@arkanoa-media.com'));
        } elseif ($as === 'super') {
            $bearer = static::getContainer()->get('lexik_jwt_authentication.jwt_manager')->create($this->superUser());
        }
        if ($bearer !== null) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer ' . $bearer;
        }
        $this->client->getCookieJar()->clear();
        $this->client->request($method, 'https://' . MV_TEST_TENANT_HOST . $path . (str_contains($path, '?') ? '&' : '?') . 'locale=fr', [], [], $server, $body !== null ? json_encode($body) : null);

        return $this->client->getResponse();
    }

    private function superUser(): User
    {
        $em = static::getContainer()->get(TenantEntityManagerProvider::class)->getEntityManager();
        $user = $em->getRepository(User::class)->findOneBy(['email' => 'super-etiquette@example.invalid']);
        if (!$user) {
            $user = (new User())->setEmail('super-etiquette@example.invalid')->setUsername('super-etiquette@example.invalid')->setFirstname('Super')->setLastname('Admin')->setRoles(['ROLE_USER_INTERNET', 'ROLE_ADMIN', 'ROLE_SUPER_ADMIN'])->setPassword('inutilisable')->setIsVerified(true);
            $em->persist($user);
            $em->flush();
        }

        return $user;
    }

    private function userByEmail(string $email): User
    {
        $user = static::getContainer()->get(TenantEntityManagerProvider::class)->getEntityManager()->getRepository(User::class)->findOneBy(['email' => $email]);
        $this->assertNotNull($user, "compte $email présent");

        return $user;
    }

    private function login(array $roles): User
    {
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $email = 'etiq-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Admin')->setLastname('Étiquette')->setRoles($roles)->setIsVerified(true)->setPassword('x');
        $provider->getEntityManager()->persist($user);
        $provider->getEntityManager()->flush();
        $this->client->setServerParameter('HTTP_X_TENANT_HOST', MV_TEST_TENANT_HOST);
        $this->client->loginUser($user, 'main');
        \App\Tests\Functional\Support\OtpTestHelper::validateIfRequired($this->client, $user);

        return $user;
    }
}
