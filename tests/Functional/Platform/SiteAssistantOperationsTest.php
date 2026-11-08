<?php

namespace App\Tests\Functional\Platform;

use App\Entity\Adress;
use App\Entity\User;
use App\Services\BoutiqueDemoService\BoutiqueDemoSeeder;
use App\Services\Platform\EntitlementService;
use App\Services\Platform\PlatformModeService;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use App\Tests\Fake\FakeCheckoutPaymentGateway;
use App\Tests\Fake\FakeLandingAiClient;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Console IA, lot 6 (07/11/2026) : l'assistant lit les commandes à traiter et une commande, propose un remboursement puis une annulation
 * qui ne s'appliquent qu'après confirmation (Stripe simulé, courriels) ; paliers « Aide IA » / « IA complète » par les droits d'usage en
 * mode réel de la plateforme (403 sans forfait, outils de lecture seulement en Aide IA, tout en IA complète, super admin toujours complet).
 */
class SiteAssistantOperationsTest extends WebTestCase
{
    private const PASSWORD = 'Mot-de-passe-de-test-1!';
    private KernelBrowser $client;
    private \Doctrine\DBAL\Connection $db;
    private \PDO $master;
    private array $session = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        FakeLandingAiClient::reset();
        FakeCheckoutPaymentGateway::reset();
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $this->db = $provider->getEntityManager()->getConnection();
        $this->master = static::getContainer()->get(TenantConnectionManager::class)->getPdoMaster();
        $this->db->executeStatement('DELETE FROM ai_usage');
        static::getContainer()->get(BoutiqueDemoSeeder::class)->seed('client-ia-ops@example.invalid', false, false);
    }

    protected function tearDown(): void
    {
        $this->master->prepare("DELETE FROM tenant_entitlements WHERE tenant_code = ? AND feature_key IN ('ai.help', 'ai.full')")->execute([MV_TEST_TENANT_CODE]);
        $this->master->exec("DELETE FROM tenant_entitlement_events WHERE feature_key IN ('ai.help', 'ai.full')");
        static::getContainer()->get(PlatformModeService::class)->setMode(PlatformModeService::DEMO, 'test');
        $this->db->executeStatement('UPDATE entreprise SET is_ai_help_active = FALSE, is_ai_full_active = FALSE');
        $this->master->prepare("INSERT INTO tenant_entitlements (tenant_code, feature_key, source, status, granted_by, note) VALUES (?, 'ai.full', 'manual', 'active', 'bootstrap', 'IA complète du site de test')")->execute([MV_TEST_TENANT_CODE]); // comme l'amorçage : les autres tests comptent dessus (08/11/2026)
        static::getContainer()->get(\App\Services\TenantCacheService::class)->invalidateTenant();
        parent::tearDown();
    }

    public function testTheAssistantReadsOrdersAndRefundsThenCancelsOnlyAfterConfirmation(): void
    {
        static::getContainer()->get(EntitlementService::class)->free(MV_TEST_TENANT_CODE, 'ai.full', 'test'); // depuis le 08/11/2026 le mode démo n'ouvre plus l'assistant : droit explicite
        [$orderId, $pi, $total] = $this->paidOrder();
        $reference = (string) $this->db->fetchOne('SELECT reference FROM "order" WHERE id = ?', [$orderId]);

        FakeLandingAiClient::$queue[] = (object) ['content' => [
            (object) ['type' => 'tool_use', 'id' => 'tu_1', 'name' => 'get_orders_todo', 'input' => (object) []],
            (object) ['type' => 'tool_use', 'id' => 'tu_2', 'name' => 'get_order', 'input' => (object) ['reference' => $reference]],
            (object) ['type' => 'tool_use', 'id' => 'tu_3', 'name' => 'refund_order', 'input' => (object) ['orderId' => $orderId, 'amountCents' => 500, 'reason' => 'Geste commercial', 'notify' => true]],
            (object) ['type' => 'tool_use', 'id' => 'tu_4', 'name' => 'refund_order', 'input' => (object) ['orderId' => $orderId, 'amountCents' => $total + 100]],
            (object) ['type' => 'tool_use', 'id' => 'tu_5', 'name' => 'capture_order', 'input' => (object) ['orderId' => $orderId]],
        ], 'usage' => (object) ['input_tokens' => 10, 'output_tokens' => 5]];
        FakeLandingAiClient::$queue[] = (object) ['content' => [(object) ['type' => 'text', 'text' => 'Je propose de rembourser 5,00 CAD ; confirmez.']], 'usage' => (object) ['input_tokens' => 10, 'output_tokens' => 5]];
        $r = $this->api('POST', '/api/site/assistant', ['messages' => [['role' => 'user', 'content' => "Rembourse 5 $ sur la commande $reference"]]], 'admin');
        $this->assertSame(200, $r->getStatusCode(), $r->getContent());
        $body = json_decode((string) $r->getContent(), true);
        $this->assertSame('full', $body['tier'], 'droit « IA complète » : palier complet (le mode démo seul n\'ouvre plus l\'assistant, 08/11/2026)');
        $this->assertCount(1, $body['actions'], 'une seule proposition : le dépassement et la capture sans autorisation sont refusés');
        $this->assertSame('refund_order', $body['actions'][0]['tool']);
        $this->assertStringContainsString('5,00', $body['actions'][0]['summary']);
        $this->assertStringContainsString($reference, $body['actions'][0]['summary']);
        $this->assertContains(['label' => \App\Services\SiteAssistantService\SiteAssistantTools::SCREENS['orders'], 'screen' => 'orders'], $body['links']);
        $results = FakeLandingAiClient::$requests[1]['messages'][2]['content'];
        $todo = json_decode($results[0]['content'], true);
        $this->assertGreaterThanOrEqual(1, $todo['counts']['toShip'], 'la commande payée est à expédier (la liste rendue à l\'IA est limitée aux 10 plus anciennes : les autres tests en laissent)');
        $order = json_decode($results[1]['content'], true);
        $this->assertSame([$reference, 'Payée', 'paid', $total, true, false, false], [$order['reference'], $order['status'], $order['paymentStatus'], $order['totalCents'], $order['actions']['refund'], $order['actions']['capture'], $order['actions']['counterSale']]);
        $this->assertStringContainsString('remboursable', json_decode($results[3]['content'], true)['error']);
        $this->assertSame([], FakeCheckoutPaymentGateway::$refunds, 'rien chez Stripe avant confirmation');

        // Confirmation : remboursement réel (simulé), écriture, courriel
        $r = $this->api('POST', '/api/site/assistant/actions/' . $body['actions'][0]['id'] . '/confirm', null, 'admin');
        $this->assertSame(200, $r->getStatusCode(), $r->getContent());
        $this->assertSame($orderId, json_decode((string) $r->getContent(), true)['result']['orderId']);
        $this->assertCount(1, FakeCheckoutPaymentGateway::$refunds);
        $this->assertSame([$pi, 500], [FakeCheckoutPaymentGateway::$refunds[0]['intent'], FakeCheckoutPaymentGateway::$refunds[0]['amount']]);
        $this->assertEmailCount(1);
        $this->assertStringContainsString('Geste commercial', (string) $this->getMailerMessage()->getHtmlBody());
        $this->assertSame(-500, (int) $this->db->fetchOne('SELECT amount FROM payments WHERE order_payment_id = ? AND amount < 0 ORDER BY id DESC LIMIT 1', [$orderId]));

        // Annulation : motif obligatoire, puis confirmée → statut annulée et reste remboursé
        FakeLandingAiClient::reset();
        FakeLandingAiClient::$queue[] = (object) ['content' => [
            (object) ['type' => 'tool_use', 'id' => 'tu_1', 'name' => 'cancel_order', 'input' => (object) ['orderId' => $orderId, 'reason' => '']],
            (object) ['type' => 'tool_use', 'id' => 'tu_2', 'name' => 'cancel_order', 'input' => (object) ['orderId' => $orderId, 'reason' => 'Rupture de stock', 'notify' => false]],
        ], 'usage' => (object) ['input_tokens' => 10, 'output_tokens' => 5]];
        FakeLandingAiClient::$queue[] = (object) ['content' => [(object) ['type' => 'text', 'text' => 'Je propose d\'annuler ; confirmez.']], 'usage' => (object) ['input_tokens' => 10, 'output_tokens' => 5]];
        $body = json_decode((string) $this->api('POST', '/api/site/assistant', ['messages' => [['role' => 'user', 'content' => 'Annule cette commande, rupture de stock']]], 'admin')->getContent(), true);
        $this->assertCount(1, $body['actions']);
        $this->assertSame('cancel_order', $body['actions'][0]['tool']);
        $this->assertStringContainsString('rembourser le paiement', $body['actions'][0]['summary']);
        $r = $this->api('POST', '/api/site/assistant/actions/' . $body['actions'][0]['id'] . '/confirm', null, 'admin');
        $this->assertSame(200, $r->getStatusCode(), $r->getContent());
        $this->assertSame(7, (int) $this->db->fetchOne('SELECT status_id FROM "order" WHERE id = ?', [$orderId]), 'annulée');
        $this->assertCount(2, FakeCheckoutPaymentGateway::$refunds, 'le reste est remboursé chez Stripe');
        $this->assertNull(FakeCheckoutPaymentGateway::$refunds[1]['amount'], 'annulation : remboursement du reste (montant laissé à Stripe)');

        // Commande annulée : plus rien à proposer, l'IA reçoit le refus
        FakeLandingAiClient::reset();
        FakeLandingAiClient::$queue[] = (object) ['content' => [(object) ['type' => 'tool_use', 'id' => 'tu_1', 'name' => 'ship_order', 'input' => (object) ['orderId' => $orderId, 'carrier' => 'Postes Canada']]], 'usage' => (object) ['input_tokens' => 1, 'output_tokens' => 1]];
        FakeLandingAiClient::$queue[] = (object) ['content' => [(object) ['type' => 'text', 'text' => 'Impossible.']], 'usage' => (object) ['input_tokens' => 1, 'output_tokens' => 1]];
        $body = json_decode((string) $this->api('POST', '/api/site/assistant', ['messages' => [['role' => 'user', 'content' => 'Expédie-la']]], 'admin')->getContent(), true);
        $this->assertSame([], $body['actions']);
        $this->assertArrayHasKey('error', json_decode(FakeLandingAiClient::$requests[1]['messages'][2]['content'][0]['content'], true));
    }

    public function testTiersFollowThePlatformEntitlementsInLiveMode(): void
    {
        $mode = static::getContainer()->get(PlatformModeService::class);
        $entitlements = static::getContainer()->get(EntitlementService::class);
        $this->master->prepare("DELETE FROM tenant_entitlements WHERE tenant_code = ? AND feature_key IN ('ai.help', 'ai.full')")->execute([MV_TEST_TENANT_CODE]); // l'amorçage donne « IA complète » au site de test (08/11/2026) : on part de rien
        $mode->setMode(PlatformModeService::LIVE, 'test');

        // Aucun forfait IA : refusé avant tout appel à l'IA ; le propriétaire de la plateforme passe toujours
        $r = $this->api('POST', '/api/site/assistant', ['messages' => [['role' => 'user', 'content' => 'Bonjour']]], 'admin');
        $this->assertSame(403, $r->getStatusCode(), $r->getContent());
        $this->assertSame('AI_NOT_INCLUDED', json_decode((string) $r->getContent(), true)['code']);
        $this->assertSame([], FakeLandingAiClient::$requests);
        FakeLandingAiClient::$queue[] = (object) ['content' => [(object) ['type' => 'text', 'text' => 'Bonjour, propriétaire.']], 'usage' => (object) ['input_tokens' => 1, 'output_tokens' => 1]];
        $body = json_decode((string) $this->api('POST', '/api/site/assistant', ['messages' => [['role' => 'user', 'content' => 'Bonjour']]], 'super')->getContent(), true);
        $this->assertSame(['full', 'full'], [$body['access'], $body['tier']]);

        // Aide IA : outils de lecture seulement ; une proposition est refusée à l'IA, rien n'est proposé
        $entitlements->free(MV_TEST_TENANT_CODE, 'ai.help', 'test');
        static::getContainer()->get(\App\Services\TenantCacheService::class)->invalidateTenant();
        FakeLandingAiClient::reset();
        FakeLandingAiClient::$queue[] = (object) ['content' => [
            (object) ['type' => 'tool_use', 'id' => 'tu_1', 'name' => 'set_site_settings', 'input' => (object) ['values' => (object) ['site.locale' => 'en'], 'summary' => 'anglais']],
            (object) ['type' => 'tool_use', 'id' => 'tu_2', 'name' => 'get_orders_todo', 'input' => (object) []],
            (object) ['type' => 'tool_use', 'id' => 'tu_3', 'name' => 'get_configuration_paths', 'input' => (object) []],
        ], 'usage' => (object) ['input_tokens' => 1, 'output_tokens' => 1]];
        FakeLandingAiClient::$queue[] = (object) ['content' => [(object) ['type' => 'text', 'text' => 'Avec Aide IA je vous guide ; voici comment faire.']], 'usage' => (object) ['input_tokens' => 1, 'output_tokens' => 1]];
        $r = $this->api('POST', '/api/site/assistant', ['messages' => [['role' => 'user', 'content' => 'Passe le site en anglais']]], 'admin');
        $this->assertSame(200, $r->getStatusCode(), $r->getContent());
        $body = json_decode((string) $r->getContent(), true);
        $this->assertSame(['site', 'help', []], [$body['access'], $body['tier'], $body['actions']]);
        $this->assertSame(\App\Services\SiteAssistantService\SiteAssistantTools::HELP_TOOLS, array_column(FakeLandingAiClient::$requests[0]['tools'], 'name'));
        $this->assertStringContainsString('Aide IA', FakeLandingAiClient::$requests[0]['system']);
        $results = FakeLandingAiClient::$requests[1]['messages'][2]['content'];
        $this->assertStringContainsString('IA complète', json_decode($results[0]['content'], true)['error']);
        $this->assertArrayHasKey('counts', json_decode($results[1]['content'], true));
        $this->assertArrayHasKey('stripe', json_decode($results[2]['content'], true));
        $this->assertContains(['label' => \App\Services\SiteAssistantService\SiteAssistantTools::SCREENS['configuration'], 'screen' => 'configuration'], $body['links']);
        $this->assertTrue((bool) $this->db->fetchOne('SELECT is_ai_help_active FROM entreprise ORDER BY id LIMIT 1'), 'droit projeté sur la fiche Entreprise');

        // IA complète : tous les outils
        $entitlements->free(MV_TEST_TENANT_CODE, 'ai.full', 'test');
        static::getContainer()->get(\App\Services\TenantCacheService::class)->invalidateTenant();
        FakeLandingAiClient::reset();
        FakeLandingAiClient::$queue[] = (object) ['content' => [(object) ['type' => 'text', 'text' => 'Tout est possible.']], 'usage' => (object) ['input_tokens' => 1, 'output_tokens' => 1]];
        $body = json_decode((string) $this->api('POST', '/api/site/assistant', ['messages' => [['role' => 'user', 'content' => 'Bonjour']]], 'admin')->getContent(), true);
        $this->assertSame('full', $body['tier']);
        $this->assertCount(21, FakeLandingAiClient::$requests[0]['tools']);
    }

    private function api(string $method, string $path, ?array $body, ?string $as): Response
    {
        $server = ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json', 'HTTP_X_XSRF_TOKEN' => 'jeton-xsrf-de-test'];
        if ($as !== null) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer ' . $this->token($as);
        }
        $this->client->getCookieJar()->clear();
        $this->client->request($method, 'https://' . MV_TEST_TENANT_HOST . $path . '?locale=fr', [], [], $server, $body !== null ? json_encode($body) : null);

        return $this->client->getResponse();
    }

    private function token(string $as): string
    {
        $container = static::getContainer();
        $container->get(TenantEntityManagerProvider::class)->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $em = $container->get(TenantEntityManagerProvider::class)->getEntityManager();
        $email = $as === 'super' ? 'super-assistant@example.invalid' : 'admin@arkanoa-media.com';
        $user = $em->getRepository(User::class)->findOneBy(['email' => $email]);
        if (!$user) {
            $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Super')->setLastname('Test')->setRoles(['ROLE_USER_INTERNET', 'ROLE_ADMIN', 'ROLE_SUPER_ADMIN'])->setPassword('inutilisable')->setIsVerified(true);
            $em->persist($user);
            $em->flush();
        }

        return $container->get('lexik_jwt_authentication.jwt_manager')->create($user);
    }

    // ---------------------------------------------------------------- commande payée par un client (même fixture que le lot 3)

    private function paidOrder(): array
    {
        $this->loginAs(['ROLE_USER_INTERNET']);
        $variant = (int) $this->db->fetchOne("SELECT v.id FROM product_variant v JOIN product p ON p.id = v.product_id WHERE p.code = 'DEMO-CASQUETTE' ORDER BY v.id LIMIT 1");
        $order = ['addressId' => $this->address(), 'carrierId' => 6, 'items' => [['productVariantId' => $variant, 'quantity' => 1]]];
        $intent = $this->request('POST', '/api/stripe/create-intent', json_encode(['items' => $order['items'], 'carrierId' => 6, 'order' => $order]));
        $this->assertSame(200, $intent->getStatusCode(), $intent->getContent());
        $pi = json_decode((string) $intent->getContent(), true)['paymentIntentId'];
        FakeCheckoutPaymentGateway::confirm($pi);
        $created = $this->request('POST', '/api/order/create', json_encode($order + ['paymentIntentId' => $pi]));
        $this->assertSame(201, $created->getStatusCode(), $created->getContent());
        $orderId = (int) json_decode((string) $created->getContent(), true)['orderId'];

        return [$orderId, $pi, (int) round((float) $this->db->fetchOne('SELECT total_amount FROM "order" WHERE id = ?', [$orderId]))];
    }

    private function address(): int
    {
        $em = static::getContainer()->get(TenantEntityManagerProvider::class)->getEntityManager();
        $user = $em->getRepository(User::class)->findOneBy(['email' => $this->session['email']]);
        $address = (new Adress())->setFirstname('Test')->setLastname('IA')->setFullname('Test IA')->setAddress('1 rue du Test')->setCity('Montréal')
            ->setCodepostal('H1A 1A1')->setCountry('CA')->setPhone('+1 514 555 0000')->setProvince('QC')->setUserAdress($user);
        $em->persist($address);
        $em->flush();

        return (int) $address->getId();
    }

    private function request(string $method, string $path, ?string $body = null): Response
    {
        $this->client->getCookieJar()->clear();
        foreach ($this->session as $name => $value) {
            if ($name !== 'email') {
                $this->client->getCookieJar()->set(new \Symfony\Component\BrowserKit\Cookie($name, $value, null, '/', MV_TEST_TENANT_HOST, true));
            }
        }
        $this->client->request($method, 'https://' . MV_TEST_TENANT_HOST . $path, [], [], array_filter([
            'HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_XSRF_TOKEN' => $this->session['XSRF-TOKEN_' . MV_TEST_TENANT_CODE] ?? null,
        ]), $body);

        return $this->client->getResponse();
    }

    private function loginAs(array $roles): void
    {
        $email = 'ia-ops-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $container = static::getContainer();
        $provider = $container->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Client')->setLastname('IA')->setRoles($roles)->setIsVerified(true);
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD));
        $provider->getEntityManager()->persist($user);
        $provider->getEntityManager()->flush();
        $this->client->getCookieJar()->clear();
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . '/api/login', [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json'], json_encode(['username' => $email, 'password' => self::PASSWORD, 'platform' => 'web']));
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());
        $this->session = ['email' => $email];
        foreach ($this->client->getCookieJar()->all() as $cookie) {
            $this->session[$cookie->getName()] = $cookie->getValue();
        }
    }
}
