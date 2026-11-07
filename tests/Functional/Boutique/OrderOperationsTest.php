<?php

namespace App\Tests\Functional\Boutique;

use App\Entity\Adress;
use App\Entity\User;
use App\Services\BoutiqueDemoService\BoutiqueDemoSeeder;
use App\Services\TenantEntityManagerProvider;
use App\Tests\Fake\FakeCheckoutPaymentGateway;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Opérations de support, lot 3 (07/11/2026) : remboursement partiel puis total d'une commande web payée par carte (Stripe simulé,
 * écritures de remboursement, courriel), refus au-delà du remboursable, annulation avec motif qui rembourse réellement chez Stripe
 * (et n'annule rien si Stripe refuse), capture d'un paiement autorisé, renvoi des courriels, vente au comptoir exclue.
 */
class OrderOperationsTest extends WebTestCase
{
    private const PASSWORD = 'Mot-de-passe-de-test-1!';
    private KernelBrowser $client;
    private \Doctrine\DBAL\Connection $db;
    private array $session = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $this->db = $provider->getEntityManager()->getConnection();
        static::getContainer()->get(BoutiqueDemoSeeder::class)->seed('client-ops@example.invalid', false, false);
        FakeCheckoutPaymentGateway::reset();
    }

    public function testRefundsPartialThenFullAndNeverBeyondWhatWasCaptured(): void
    {
        [$orderId, $pi, $total] = $this->paidOrder();
        $this->loginAdmin();
        $crawler = $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . "/admin/orders/$orderId/rembourser");
        $html = html_entity_decode((string) $this->client->getResponse()->getContent());
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertStringContainsString('remboursable ' . number_format($total / 100, 2, ',', ' '), $html);
        $token = $crawler->filter('form[data-ops-form] input[name="_token"]')->attr('value');

        // Au-delà du remboursable : refusé, rien chez Stripe
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . "/admin/orders/$orderId/rembourser", ['_token' => $token, 'amount' => number_format(($total + 100) / 100, 2, '.', ''), 'reason' => 'trop', 'notify' => '1']);
        $this->client->followRedirect();
        $this->assertStringContainsString('dépasse', html_entity_decode((string) $this->client->getResponse()->getContent()));
        $this->assertSame([], FakeCheckoutPaymentGateway::$refunds);

        // Remboursement partiel de 10 $ : Stripe, écriture négative, courriel
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . "/admin/orders/$orderId/rembourser", ['_token' => $token, 'amount' => '10', 'reason' => 'Article manquant', 'notify' => '1']);
        $this->assertSame(302, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
        $this->assertEmailCount(1);
        $this->assertStringContainsString('10,00 CAD', (string) $this->getMailerMessage()->getHtmlBody());
        $this->assertStringContainsString('Article manquant', (string) $this->getMailerMessage()->getHtmlBody());
        $this->assertCount(1, FakeCheckoutPaymentGateway::$refunds);
        $this->assertSame([$pi, 1000], [FakeCheckoutPaymentGateway::$refunds[0]['intent'], FakeCheckoutPaymentGateway::$refunds[0]['amount']]);
        $row = $this->db->fetchAssociative('SELECT amount, payment_type_id, stripe_status, stripe_payment_id FROM payments WHERE order_payment_id = ? AND amount < 0 ORDER BY id DESC LIMIT 1', [$orderId]);
        $this->assertSame([-1000, 1, 'refunded'], [(int) $row['amount'], (int) $row['payment_type_id'], $row['stripe_status']]);
        $this->assertStringStartsWith('re_test_', (string) $row['stripe_payment_id']);
        $this->assertSame('refund', $this->db->fetchOne("SELECT action FROM content_audit_log WHERE resource = 'order' AND resource_id = ? ORDER BY id DESC LIMIT 1", [(string) $orderId]));

        // Le reste : remboursable = total − 10 ; remboursement total du reste puis plus rien à rembourser
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . "/admin/orders/$orderId/rembourser");
        $this->assertStringContainsString('remboursable ' . number_format(($total - 1000) / 100, 2, ',', ' '), html_entity_decode((string) $this->client->getResponse()->getContent()));
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . "/admin/orders/$orderId/rembourser", ['_token' => $token, 'amount' => number_format(($total - 1000) / 100, 2, '.', ''), 'reason' => '', 'notify' => '0']);
        $this->assertSame(302, $this->client->getResponse()->getStatusCode());
        $this->assertCount(2, FakeCheckoutPaymentGateway::$refunds);
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . "/admin/orders/$orderId/rembourser");
        $this->assertStringContainsString('data-ops-blocked', (string) $this->client->getResponse()->getContent(), 'plus rien à rembourser');
        $order = $this->request('GET', "/api/orders/$orderId");
        $this->assertSame(200, $order->getStatusCode(), $order->getContent());
        $this->assertSame('refunded', json_decode((string) $order->getContent(), true)['paymentStatus'], 'le client voit sa commande remboursée');
    }

    public function testCancellationRefundsAtStripeOrDoesNothingIfStripeRefuses(): void
    {
        [$orderId, $pi, $total] = $this->paidOrder();
        $this->loginAdmin();
        $crawler = $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . "/admin/orders/$orderId/annuler");
        $this->assertStringContainsString('sont remboursés chez Stripe', html_entity_decode((string) $this->client->getResponse()->getContent()));
        $token = $crawler->filter('form[data-ops-form] input[name="_token"]')->attr('value');

        // Stripe refuse : rien n'est annulé, aucune écriture
        FakeCheckoutPaymentGateway::$refundFails = true;
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . "/admin/orders/$orderId/annuler", ['_token' => $token, 'reason' => 'Rupture', 'notify' => '1', 'confirm' => '1']);
        $this->client->followRedirect();
        $this->assertStringContainsString('Annulation impossible', html_entity_decode((string) $this->client->getResponse()->getContent()));
        $this->assertSame(3, (int) $this->db->fetchOne('SELECT status_id FROM "order" WHERE id = ?', [$orderId]));
        $this->assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM payments WHERE order_payment_id = ? AND amount < 0', [$orderId]), 'aucune écriture de remboursement sans remboursement');

        // Stripe accepte : annulée, remboursée, stock réintégré, courriel avec le motif
        FakeCheckoutPaymentGateway::$refundFails = false;
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . "/admin/orders/$orderId/annuler", ['_token' => $token, 'reason' => 'Rupture de stock', 'notify' => '1', 'confirm' => '1']);
        $this->assertSame(302, $this->client->getResponse()->getStatusCode());
        $this->assertEmailCount(1);
        $this->assertStringContainsString('Rupture de stock', (string) $this->getMailerMessage()->getHtmlBody());
        $this->assertSame(7, (int) $this->db->fetchOne('SELECT status_id FROM "order" WHERE id = ?', [$orderId]));
        $this->assertCount(1, FakeCheckoutPaymentGateway::$refunds);
        $this->assertSame([$pi, null], [FakeCheckoutPaymentGateway::$refunds[0]['intent'], FakeCheckoutPaymentGateway::$refunds[0]['amount']], 'remboursement intégral');
        $row = $this->db->fetchAssociative('SELECT amount, stripe_status FROM payments WHERE order_payment_id = ? AND amount < 0 ORDER BY id DESC LIMIT 1', [$orderId]);
        $this->assertSame([-$total, 'refunded'], [(int) $row['amount'], $row['stripe_status']]);
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . "/admin/orders/$orderId/rembourser");
        $this->assertStringContainsString('déjà remboursé', html_entity_decode((string) $this->client->getResponse()->getContent()));
    }

    public function testCaptureResendAndCounterSaleRefusal(): void
    {
        [$orderId, $pi] = $this->paidOrder();
        // Paiement remis « autorisé non capturé » (webhook arrivé avant la capture) : bouton Capturer, puis commande payée
        $this->db->executeStatement("UPDATE payments SET stripe_status = 'requires_capture' WHERE order_payment_id = ? AND amount > 0", [$orderId]);
        $this->db->executeStatement('UPDATE "order" SET status_id = 2 WHERE id = ?', [$orderId]);
        FakeCheckoutPaymentGateway::$intents[$pi]['status'] = 'requires_capture';
        $this->loginAdmin();
        $crawler = $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . "/admin/orders/$orderId/rembourser");
        $this->assertStringContainsString('data-ops-capture', (string) $this->client->getResponse()->getContent());
        $token = $crawler->filter('form[data-ops-capture] input[name="_token"]')->attr('value');
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . "/admin/orders/$orderId/capturer", ['_token' => $token]);
        $this->assertSame(302, $this->client->getResponse()->getStatusCode());
        $this->assertSame('succeeded', FakeCheckoutPaymentGateway::$intents[$pi]['status']);
        $this->assertSame(['succeeded', 3], [$this->db->fetchOne('SELECT stripe_status FROM payments WHERE order_payment_id = ? AND amount > 0', [$orderId]), (int) $this->db->fetchOne('SELECT status_id FROM "order" WHERE id = ?', [$orderId])]);

        // Renvoi des courriels : confirmation et reçu ; expédition refusée tant que rien n'est expédié
        $crawler = $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . "/admin/orders/$orderId/courriels");
        $token = $crawler->filter('form[data-ops-form] input[name="_token"]')->attr('value');
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . "/admin/orders/$orderId/courriels", ['_token' => $token, 'kind' => 'receipt']);
        $this->assertEmailCount(1);
        $this->assertStringContainsString('pay.stripe.test/receipt', (string) $this->getMailerMessage()->getHtmlBody(), 'lien du reçu Stripe');
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . "/admin/orders/$orderId/courriels", ['_token' => $token, 'kind' => 'confirmation']);
        $this->assertEmailCount(1);
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . "/admin/orders/$orderId/courriels", ['_token' => $token, 'kind' => 'shipped']);
        $this->client->followRedirect();
        $this->assertStringContainsString('pas expédiée', html_entity_decode((string) $this->client->getResponse()->getContent()));

        // Vente au comptoir : aucune opération ici
        $pos = (int) $this->db->fetchOne("INSERT INTO \"order\" (id, status_id, reference, order_date, total_amount, order_source_id) VALUES (nextval('order_id_seq'), 3, 'POS-OPS', NOW(), 1000, 2) RETURNING id");
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . "/admin/orders/$pos/rembourser");
        $this->assertStringContainsString('Vente au comptoir', (string) $this->client->getResponse()->getContent());
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . "/admin/orders/$pos/annuler");
        $this->assertStringContainsString('Vente au comptoir', (string) $this->client->getResponse()->getContent());
    }

    /** @return array{0: int, 1: string, 2: int} commande web payée par carte (intent simulé capturé) : id, intent, total en cents */
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
        $this->assertSame('succeeded', FakeCheckoutPaymentGateway::$intents[$pi]['status'], 'paiement capturé à la création');

        return [$orderId, $pi, (int) round((float) $this->db->fetchOne('SELECT total_amount FROM "order" WHERE id = ?', [$orderId]))];
    }

    private function api(string $method, string $path): Response
    {
        $bearer = static::getContainer()->get('lexik_jwt_authentication.jwt_manager')->create($this->userByEmail('admin@arkanoa-media.com'));
        $this->client->getCookieJar()->clear();
        $this->client->request($method, 'https://' . MV_TEST_TENANT_HOST . $path . '?locale=fr', [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $bearer]);

        return $this->client->getResponse();
    }

    private function userByEmail(string $email): User
    {
        $user = static::getContainer()->get(TenantEntityManagerProvider::class)->getEntityManager()->getRepository(User::class)->findOneBy(['email' => $email]);
        $this->assertNotNull($user);

        return $user;
    }

    private function address(): int
    {
        $em = static::getContainer()->get(TenantEntityManagerProvider::class)->getEntityManager();
        $user = $em->getRepository(User::class)->findOneBy(['email' => $this->session['email']]);
        $address = (new Adress())->setFirstname('Test')->setLastname('Ops')->setFullname('Test Ops')->setAddress('1 rue du Test')->setCity('Montréal')
            ->setCodepostal('H1A 1A1')->setCountry('CA')->setPhone('+1 514 555 0000')->setProvince('QC')->setUserAdress($user);
        $em->persist($address);
        $em->flush();

        return (int) $address->getId();
    }

    private function request(string $method, string $path, ?string $body = null): Response
    {
        $jar = $this->client->getCookieJar();
        $jar->clear();
        foreach ($this->session as $name => $value) {
            if ($name !== 'email') {
                $jar->set(new \Symfony\Component\BrowserKit\Cookie($name, $value, null, '/', MV_TEST_TENANT_HOST, true));
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
        $email = 'ops-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $container = static::getContainer();
        $provider = $container->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Client')->setLastname('Ops')->setRoles($roles)->setIsVerified(true);
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

    private function loginAdmin(): void
    {
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $email = 'ops-admin-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Admin')->setLastname('Ops')->setRoles(['ROLE_USER', 'ROLE_USER_INTERNET', 'ROLE_ADMIN'])->setIsVerified(true)->setPassword('x');
        $provider->getEntityManager()->persist($user);
        $provider->getEntityManager()->flush();
        $this->client->getCookieJar()->clear();
        $this->client->setServerParameter('HTTP_X_TENANT_HOST', MV_TEST_TENANT_HOST);
        $this->client->loginUser($user, 'main');
        \App\Tests\Functional\Support\OtpTestHelper::validateIfRequired($this->client, $user);
    }
}
