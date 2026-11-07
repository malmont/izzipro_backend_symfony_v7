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
 * Fiche commande refondue et vue « À traiter », lot 4 (07/11/2026) : la fiche d'une commande web payée montre le client, les articles,
 * le paiement, la chronologie et les actions permises par l'état ; « À traiter » classe la commande (à expédier, puis en transit depuis
 * trop longtemps, puis incomplète) ; le tableau de bord affiche le compteur ; une vente au comptoir n'a aucune action ; la liste
 * EasyAdmin pointe vers la fiche ; un anonyme est repoussé.
 */
class OrderDeskTest extends WebTestCase
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
        static::getContainer()->get(BoutiqueDemoSeeder::class)->seed('client-desk@example.invalid', false, false);
        FakeCheckoutPaymentGateway::reset();
    }

    public function testSheetTodoAndDashboardFollowTheOrderLife(): void
    {
        [$orderId, , $total] = $this->paidOrder();
        $this->loginAdmin();

        // Fiche : état, client, article, paiement, chronologie, actions permises (pas de capture : déjà encaissé)
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . "/admin/commandes/$orderId");
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $html = $this->page();
        $this->assertStringContainsString('data-order-sheet="' . $orderId . '"', $html);
        $this->assertStringContainsString('Payée', $html);
        $this->assertStringContainsString($this->session['email'], $html, 'client');
        $this->assertStringContainsString('Casquette', $html, 'article');
        $this->assertStringContainsString('Encaissement', $html);
        $this->assertStringContainsString('Payé par carte ' . number_format($total / 100, 2, ',', ' '), $html);
        $this->assertStringContainsString('Commande passée', $html);
        $this->assertStringContainsString('Paiement encaissé', $html);
        // Les liens sont habillés EasyAdmin (routeName=…) pour garder le menu bleu
        foreach (['admin_order_ship', 'admin_order_label', 'admin_order_refund', 'admin_order_cancel', 'admin_order_emails'] as $action) {
            $this->assertStringContainsString("routeName=$action", $html, "action $action proposée");
        }
        $this->assertStringNotContainsString('Capturer le paiement', $html);
        $this->assertStringNotContainsString('Marquer livrée', $html);

        // À traiter : à expédier ; tableau de bord : compteur
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin/commandes/a-traiter');
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertStringContainsString('data-todo-order="' . $orderId . '"', $this->page());
        $this->assertStringContainsString('routeName=admin_order_sheet', $this->page(), 'lien vers la fiche');
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin');
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertStringContainsString('dashboard-a-traiter', $this->page());
        $this->assertStringContainsString('à expédier', $this->page());

        // Expédiée : la fiche montre le suivi et « Marquer livrée », plus d'achat d'étiquette ; sortie de « à expédier »
        $crawler = $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . "/admin/orders/$orderId/expedier");
        $token = $crawler->filter('form[data-ship-form] input[name="_token"]')->attr('value');
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . "/admin/orders/$orderId/expedier", ['_token' => $token, 'carrier' => 'Postes Canada', 'trackingNumber' => '7000123456789', 'notify' => '0']);
        $this->assertSame(302, $this->client->getResponse()->getStatusCode());
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . "/admin/commandes/$orderId");
        $html = $this->page();
        $this->assertStringContainsString('7000123456789', $html);
        $this->assertStringContainsString('Marquer livrée', $html);
        $this->assertStringContainsString('Expédiée par Postes Canada', $html, 'chronologie');
        $this->assertStringNotContainsString('routeName=admin_order_label', $html);
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin/commandes/a-traiter');
        $this->assertStringNotContainsString('data-todo-order="' . $orderId . '"', $this->page());

        // En transit depuis 11 jours : signalée
        $this->db->executeStatement('UPDATE "order" SET shipped_at = NOW() - INTERVAL \'11 days\' WHERE id = ?', [$orderId]);
        $crawler = $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin/commandes/a-traiter');
        $this->assertStringContainsString('data-todo-order="' . $orderId . '"', $this->page());
        $this->assertSame(1, $crawler->filter('[data-todo-order="' . $orderId . '"]')->count(), 'une seule fois (les autres tests laissent des commandes)');

        // Livrée depuis la fiche : plus rien à traiter
        $crawler = $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . "/admin/commandes/$orderId");
        $deliverToken = $crawler->filter('form[action$="/livree"] input[name="_token"]')->attr('value');
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . "/admin/orders/$orderId/livree", ['_token' => $deliverToken]);
        $this->assertSame(302, $this->client->getResponse()->getStatusCode());
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . "/admin/commandes/$orderId");
        $this->assertStringContainsString('Livrée', $this->page());
        $this->assertStringNotContainsString('Marquer livrée', $this->page());
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin/commandes/a-traiter');
        $this->assertStringNotContainsString('data-todo-order="' . $orderId . '"', $this->page());

        // Incomplète depuis plus d'une heure : signalée, annulation proposée
        $this->db->executeStatement('UPDATE "order" SET status_id = 1, order_date = NOW() - INTERVAL \'2 hours\', shipped_at = NULL WHERE id = ?', [$orderId]);
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin/commandes/a-traiter');
        $this->assertStringContainsString('data-todo-order="' . $orderId . '"', $this->page());
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . "/admin/commandes/$orderId");
        $this->assertStringContainsString('Incomplète', $this->page());
        $this->assertStringContainsString('routeName=admin_order_cancel', $this->page());
    }

    public function testCounterSaleHasNoActionAndListLinksToTheSheet(): void
    {
        [$orderId] = $this->paidOrder();
        $this->db->executeStatement('UPDATE "order" SET order_source_id = 2 WHERE id = ?', [$orderId]);
        $this->loginAdmin();
        $crawler = $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . "/admin/commandes/$orderId");
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertStringContainsString('vente au comptoir', $this->page());
        $this->assertSame(1, $crawler->filter('[data-order-actions] a, [data-order-actions] button')->count(), 'seul « Modifier (avancé) » reste');
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin/commandes/a-traiter');
        $this->assertStringNotContainsString('data-todo-order="' . $orderId . '"', $this->page(), 'les ventes au comptoir ne sont pas à traiter ici');

        // Liste EasyAdmin : libellé français, lien « Fiche »
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin?crudAction=index&crudControllerFqcn=' . urlencode(\App\Controller\Admin\OrderCrudController::class));
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertStringContainsString('Commandes', $this->page());
        $this->assertStringContainsString('routeName=admin_order_sheet', $this->page());
        $this->assertStringContainsString('À traiter', $this->page(), 'menu');
        $this->assertStringContainsString('Réglages avancés', $this->page(), 'menu');
    }

    public function testAnonymousIsRejected(): void
    {
        $this->client->getCookieJar()->clear();
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin/commandes/a-traiter', [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $this->assertContains($this->client->getResponse()->getStatusCode(), [302, 401, 403]);
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin/commandes/1', [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $this->assertContains($this->client->getResponse()->getStatusCode(), [302, 401, 403]);
    }

    private function page(): string
    {
        return html_entity_decode((string) $this->client->getResponse()->getContent());
    }

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
        $address = (new Adress())->setFirstname('Test')->setLastname('Desk')->setFullname('Test Desk')->setAddress('1 rue du Test')->setCity('Montréal')
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
        $email = 'desk-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $container = static::getContainer();
        $provider = $container->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Client')->setLastname('Desk')->setRoles($roles)->setIsVerified(true);
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
        $email = 'desk-admin-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Admin')->setLastname('Desk')->setRoles(['ROLE_USER', 'ROLE_USER_INTERNET', 'ROLE_ADMIN'])->setIsVerified(true)->setPassword('x');
        $provider->getEntityManager()->persist($user);
        $provider->getEntityManager()->flush();
        $this->client->getCookieJar()->clear();
        $this->client->setServerParameter('HTTP_X_TENANT_HOST', MV_TEST_TENANT_HOST);
        $this->client->loginUser($user, 'main');
        \App\Tests\Functional\Support\OtpTestHelper::validateIfRequired($this->client, $user);
    }
}
