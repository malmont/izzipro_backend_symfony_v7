<?php

namespace App\Tests\Functional\Boutique;

use App\Entity\Adress;
use App\Entity\Order;
use App\Entity\User;
use App\Security\Entry\EntryModeGuardSubscriber;
use App\Security\Entry\ExternalOperator;
use App\Security\Entry\GroupOperator;
use App\Services\BoutiqueDemoService\BoutiqueDemoSeeder;
use App\Services\OrderService\OrderDeskService;
use App\Services\OrderService\OrderDeskView;
use App\Services\TenantEntityManagerProvider;
use App\Tests\Fake\FakeCheckoutPaymentGateway;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Lot CMD-WEB-1 (20/11/2026, contrat validé par le chef) : « À traiter » et la fiche d'une commande pour la gestion web, en lecture seule.
 * - L'administrateur reçoit la fiche entière, comme `/admin/commandes/{id}` (même service).
 * - L'agent d'Assistance reçoit la vue limitée (liste blanche) : ni identifiant Stripe, ni reçu, ni contenu brut du journal, aucune action.
 * - Le directeur entré : en plus, ni courriel ni adresse du client, ni lien d'étiquette.
 * - Un client est refusé, un anonyme aussi ; une commande inconnue : 404 ORDER_NOT_FOUND.
 */
class OrderDeskApiTest extends WebTestCase
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

    public function testTheAdminGetsTheWholeSheetAndTheSameTodoAsTheBackOffice(): void
    {
        [$orderId] = $this->paidOrder();
        $admin = $this->token(['ROLE_ADMIN']);
        $sheet = $this->json('GET', "/api/site/commandes/$orderId", $admin);
        $this->assertSame([$orderId, 3, true, true], [$sheet['order']['id'], $sheet['statusId'], $sheet['actions']['ship'], $sheet['actions']['refund']]);
        $this->assertNotEmpty($sheet['payments'][0]['stripeId'], 'l\'administrateur voit l\'identifiant du paiement');
        $this->assertArrayHasKey('intentId', $sheet['money']['captured'][0]);
        $this->assertArrayNotHasKey('readOnly', $sheet);
        $this->assertSame($this->session['email'], $sheet['customer']['email']);
        $this->assertNotNull($sheet['order']['shippingAdress']);

        $todo = $this->json('GET', '/api/site/commandes/a-traiter', $admin);
        $this->assertContains($orderId, array_column($todo['toShip'], 'id'), 'payée, avec une adresse, pas expédiée : à expédier');
        $this->assertSame(static::getContainer()->get(OrderDeskService::class)->todo()['counts'], $todo['counts'], 'le même calcul que /admin/commandes/a-traiter');
        $this->assertSame(1, $this->json('GET', '/api/site/commandes/a-traiter?limit=1', $admin)['counts']['toShip']);
        $this->assertSame(['ORDER_NOT_FOUND', 404], [$this->json('GET', '/api/site/commandes/99999999', $admin, 404)['code'], 404]);
    }

    public function testTheSupportAgentReadsALimitedViewWithoutAnyAction(): void
    {
        [$orderId] = $this->paidOrder();
        $agent = $this->token(['ROLE_SUPPORT']);
        $sheet = $this->json('GET', "/api/site/commandes/$orderId", $agent);
        $this->assertTrue($sheet['readOnly']);
        $this->assertSame([], array_filter($sheet['actions']), 'aucune action : les gestes et leurs droits sont le lot CMD-WEB');
        foreach ($sheet['payments'] as $p) {
            $this->assertSame([], array_intersect(['stripeId', 'receiptUrl'], array_keys($p)), 'ni identifiant Stripe ni reçu');
            $this->assertStringNotContainsString('pi_', (string) json_encode($p));
        }
        $this->assertSame(['paymentId', 'amountCents'], array_keys($sheet['money']['captured'][0]));
        foreach ($sheet['journal'] as $j) {
            $this->assertArrayNotHasKey('after', $j);
        }
        $this->assertStringNotContainsString('"intentId"', (string) json_encode($sheet));
        $this->assertSame($this->session['email'], $sheet['customer']['email'], 'l\'agent répond au client : il voit son courriel');
        $this->assertNotNull($sheet['order']['shippingAdress'], 'et l\'adresse (suivi de la livraison)');
        $todo = $this->json('GET', '/api/site/commandes/a-traiter', $agent);
        $this->assertTrue($todo['readOnly']);
        $this->assertContains($orderId, array_column($todo['toShip'], 'id'));

        // Rien d'autre ne s'ouvre à lui : ni un autre écran du site, ni une écriture sur les commandes
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/api/site/features', [], [], $this->headers($agent));
        $this->assertSame([403, 'SUPPORT_SCOPE_ONLY'], [$this->client->getResponse()->getStatusCode(), json_decode((string) $this->client->getResponse()->getContent(), true)['code'] ?? null]);
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/api/payments?orderSource=1', [], [], $this->headers($agent));
        $this->assertSame(403, $this->client->getResponse()->getStatusCode(), 'les paiements (finances) restent fermés à l\'agent');
    }

    public function testACustomerAndAnAnonymousAreRejected(): void
    {
        [$orderId] = $this->paidOrder();
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . "/api/site/commandes/$orderId", [], [], $this->headers($this->token(['ROLE_USER_INTERNET'])));
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/api/site/commandes/a-traiter', [], [], $this->headers(null));
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
    }

    public function testTheDirectorViewCarriesNeitherTheEmailNorTheAddressOfTheCustomer(): void
    {
        $this->assertSame(OrderDeskView::DIRECTOR, OrderDeskView::audience(new GroupOperator(1, 1, 'Siège', 'Hélène', 'siege')));
        $this->assertSame(OrderDeskView::LIMITED, OrderDeskView::audience(new ExternalOperator(1, 1, 'Agence', 'Paul', 'agence')));
        $this->assertSame(OrderDeskView::LIMITED, OrderDeskView::audience((new User())->setRoles(['ROLE_SUPPORT'])));
        $this->assertSame(OrderDeskView::FULL, OrderDeskView::audience((new User())->setRoles(['ROLE_ADMIN'])));
        $this->assertSame(OrderDeskView::LIMITED, OrderDeskView::audience(null));

        [$orderId] = $this->paidOrder();
        $em = static::getContainer()->get(TenantEntityManagerProvider::class)->getEntityManager();
        $desk = static::getContainer()->get(OrderDeskService::class);
        $full = $desk->sheet($em->find(Order::class, $orderId), 'https://' . MV_TEST_TENANT_HOST);
        $full['labels'] = [['index' => 1, 'url' => 'https://etiquette.example/1.pdf', 'tracking' => 'TRK1', 'weight' => 1.2]];
        $director = OrderDeskView::sheet($full, OrderDeskView::DIRECTOR);
        $this->assertSame(['name', 'ordersCount'], array_keys($director['customer']));
        $this->assertArrayNotHasKey('shippingAdress', $director['order']);
        $this->assertArrayNotHasKey('userId', $director['order']);
        $this->assertTrue($director['order']['hasShippingAddress']);
        $this->assertSame(['index' => 1, 'tracking' => 'TRK1', 'weight' => 1.2], $director['labels'][0], 'l\'étiquette porte l\'adresse : pas de lien');
        $this->assertStringNotContainsString($this->session['email'], (string) json_encode($director));
        $this->assertStringNotContainsString('1 rue du Test', (string) json_encode($director));
        $this->assertSame('https://etiquette.example/1.pdf', OrderDeskView::sheet($full, OrderDeskView::LIMITED)['labels'][0]['url']);

        $todo = OrderDeskView::todo(['counts' => ['toShip' => 2], 'toShip' => [
            ['id' => 1, 'reference' => 'R1', 'date' => '2026-11-20 10:00', 'statusId' => 3, 'status' => 'Payée', 'totalCents' => 100, 'customer' => 'Ana Lima', 'email' => 'ana@example.invalid', 'shippedAt' => null],
            ['id' => 2, 'reference' => 'R2', 'date' => '2026-11-20 11:00', 'statusId' => 3, 'status' => 'Payée', 'totalCents' => 200, 'customer' => 'sans-nom@example.invalid', 'email' => 'sans-nom@example.invalid', 'shippedAt' => null],
        ]], OrderDeskView::DIRECTOR);
        $this->assertSame(['Ana Lima', null], array_column($todo['toShip'], 'customer'), 'le courriel rendu à la place d\'un nom n\'est pas servi');
        $this->assertStringNotContainsString('@', (string) json_encode($todo));

        // La garde du mode : le directeur lit les commandes ; il ne confirme ni n'annule un rendez-vous
        $this->assertNull(EntryModeGuardSubscriber::groupDecision('GET', '/api/site/commandes/1'));
        $this->assertNull(EntryModeGuardSubscriber::groupDecision('GET', '/api/site/reservations-boutique/1'));
        $this->assertSame('rights', EntryModeGuardSubscriber::groupDecision('POST', '/api/site/reservations-boutique/1/confirmer'));
        $this->assertSame('rights', EntryModeGuardSubscriber::groupDecision('POST', '/api/site/reservations-boutique/1/annuler'));
        // … ni par l'écran EasyAdmin (son formulaire change le statut et écrit au client) : lecture seule
        $this->assertSame('rights', EntryModeGuardSubscriber::groupScreenDecision(\App\Controller\Admin\ReservationCrudController::class, 'edit'));
        $this->assertNull(EntryModeGuardSubscriber::groupScreenDecision(\App\Controller\Admin\ReservationCrudController::class, 'index'));
    }

    /** @return array<string, mixed> */
    private function json(string $method, string $path, ?string $token, int $status = 200): array
    {
        $this->client->request($method, 'https://' . MV_TEST_TENANT_HOST . $path, [], [], $this->headers($token));
        $this->assertSame($status, $this->client->getResponse()->getStatusCode(), $path . ' : ' . substr((string) $this->client->getResponse()->getContent(), 0, 300));

        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    private function headers(?string $token): array
    {
        return ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'HTTP_ACCEPT' => 'application/json'] + ($token !== null ? ['HTTP_AUTHORIZATION' => 'Bearer ' . $token] : []);
    }

    private function token(array $roles): string
    {
        $em = static::getContainer()->get(TenantEntityManagerProvider::class)->getEntityManager();
        $email = 'cmdweb1-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Essai')->setLastname('CmdWeb')->setRoles($roles)->setIsVerified(true)->setPassword('x');
        $em->persist($user);
        $em->flush();

        return static::getContainer()->get('lexik_jwt_authentication.jwt_manager')->create($user);
    }

    /** Une commande web payée par le parcours de paiement (passerelle simulée) : à expédier */
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
        $this->client->getCookieJar()->clear();

        return [(int) json_decode((string) $created->getContent(), true)['orderId'], $pi];
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
        $email = 'cmdweb1-client-' . bin2hex(random_bytes(3)) . '@example.invalid';
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
}
