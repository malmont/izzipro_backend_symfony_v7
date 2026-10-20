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
 * Plusieurs postes de caisse par site (15/10/2026) : sessions indépendantes, montant reporté par poste, choix du poste
 * (cashRegisterId facultatif), ventes et remboursements rattachés au bon poste, administration des postes.
 * Non-régression : une commande web payée par Stripe ne touche aucune caisse. Contrat : docs/caisse.md.
 */
class CashRegisterApiTest extends WebTestCase
{
    private const PASSWORD = 'Mot-de-passe-de-test-1!';
    private KernelBrowser $client;
    private \Doctrine\DBAL\Connection $db;
    private ?string $token = null;
    private string $email = '';
    private int $mainRegister;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $this->db = $provider->getEntityManager()->getConnection();
        static::getContainer()->get(BoutiqueDemoSeeder::class)->seed('client-caisse@example.invalid', false, false);
        $this->db->executeStatement('DELETE FROM boutique_setting');
        $this->db->executeStatement("UPDATE address_entreprise SET country = 'CA', state = 'QC', city = 'Montréal', zip = 'H1M 0A9'");
        $this->db->executeStatement('UPDATE caisse SET is_open = false, closed_at = NOW() WHERE is_open = true');
        $this->db->executeStatement('UPDATE entreprise SET pos_approval_refund = NULL, pos_approval_withdrawal = NULL, pos_approval_fund_withdrawal = NULL, pos_approval_drawer_open = false, pos_approval_close_without_count = false'); // validations du responsable : PosPinApiTest
        $this->db->executeStatement('UPDATE cash_register SET is_active = (id = (SELECT MIN(id) FROM cash_register))');
        $this->mainRegister = (int) $this->db->fetchOne('SELECT MIN(id) FROM cash_register');
        FakeCheckoutPaymentGateway::reset();
    }

    public function testTwoRegistersHaveIndependentSessionsAndCarriedAmounts(): void
    {
        $second = $this->createRegister('Comptoir ' . bin2hex(random_bytes(2)));
        $this->loginAs(['ROLE_USER_POS']);
        $registers = $this->json($this->request('GET', '/api/cash-registers'));
        $this->assertSame([$this->mainRegister, $second], array_column($registers, 'id'), 'postes actifs, par identifiant');
        $this->assertSame([null, null], array_column($registers, 'openSession'));

        $openA = $this->open($this->mainRegister);
        $this->assertSame(201, $openA->getStatusCode());
        $carried = (float) json_decode($openA->getContent(), true)['amountTotal']; // solde reporté des sessions précédentes du poste
        $openB = $this->open($second);
        $this->assertSame(201, $openB->getStatusCode(), $openB->getContent());
        $this->assertSame($second, json_decode($openB->getContent(), true)['cashRegisterId']);
        $again = $this->open($this->mainRegister);
        $this->assertSame(400, $again->getStatusCode());
        $this->assertSame('CAISSE_ALREADY_OPEN', json_decode($again->getContent(), true)['error']);

        $deposit = $this->request('POST', '/api/caisse/deposit', json_encode(['amount' => 10, 'cashDetails' => [], 'cashRegisterId' => $this->mainRegister]));
        $this->assertSame(201, $deposit->getStatusCode(), $deposit->getContent());
        $this->assertSame([$carried + 10.0, $this->mainRegister], [(float) json_decode($deposit->getContent(), true)['new_total'], json_decode($deposit->getContent(), true)['cashRegisterId']]);

        [$variant, $total] = $this->counterSale();
        $sale = $this->sell($variant, $total, $second);
        $this->assertSame(201, $sale->getStatusCode(), $sale->getContent());

        $sessions = array_column($this->json($this->request('GET', '/api/cash-registers')), 'openSession', 'id');
        $this->assertSame($carried + 10.0, (float) $sessions[$this->mainRegister]['amountTotal'], 'poste principal : son dépôt seulement');
        $this->assertSame((float) $total, (float) $sessions[$second]['amountTotal'], 'second poste : la vente en espèces');

        $list = $this->json($this->request('GET', "/api/caisse?cashRegisterId=$second"));
        $this->assertCount(1, $list);
        $this->assertSame(['id' => $second, 'name' => $registers[1]['name']], $list[0]['cashRegister']);
        $this->assertNull($list[0]['closedAt']);
        $this->assertTrue($list[0]['isOpen']);
        $this->assertArrayHasKey('transactionCaisses', $list[0], 'champs historiques conservés');
        $transactions = $this->json($this->request('GET', "/api/caisse/transactions?cashRegisterId=$second"));
        $this->assertSame(['Ouverture', 'Vente'], array_column($transactions, 'transactionType'));

        $close = $this->request('POST', '/api/caisse/close', json_encode(['cashRegisterId' => $second]));
        $this->assertSame(200, $close->getStatusCode(), $close->getContent());
        $this->assertNotNull($this->json($this->request('GET', "/api/caisse?cashRegisterId=$second"))[0]['closedAt']);

        // Réouverture du second poste : montant reporté de SA dernière session, le poste principal inchangé
        $this->assertSame(201, $this->open($second)->getStatusCode());
        $sessions = array_column($this->json($this->request('GET', '/api/cash-registers')), 'openSession', 'id');
        $this->assertSame((float) $total, (float) $sessions[$second]['amountTotal'], 'montant initial = dernière session fermée du même poste');
        $this->assertSame($carried + 10.0, (float) $sessions[$this->mainRegister]['amountTotal']);
    }

    public function testOpeningWithACashFundKeepsItAcrossSessionsAndRecordsTheTime(): void
    {
        $this->loginAs(['ROLE_USER_POS']);
        $invalid = $this->request('POST', '/api/caisse/open', json_encode(['cashRegisterId' => $this->mainRegister, 'cashFund' => -5]));
        $this->assertSame(400, $invalid->getStatusCode());
        $this->assertSame('INVALID_AMOUNT', json_decode($invalid->getContent(), true)['error']);
        $this->assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM caisse WHERE is_open AND cash_register_id = ?', [$this->mainRegister]), 'aucune session ouverte sur un montant invalide');

        $before = (float) ($this->db->fetchOne('SELECT amount_total FROM caisse WHERE cash_register_id = ? AND NOT is_open ORDER BY created_at DESC, id DESC LIMIT 1', [$this->mainRegister]) ?: 0);
        $fundBefore = (float) ($this->db->fetchOne('SELECT fon_de_caisse FROM caisse WHERE cash_register_id = ? AND NOT is_open ORDER BY created_at DESC, id DESC LIMIT 1', [$this->mainRegister]) ?: 0);
        $opened = $this->request('POST', '/api/caisse/open', json_encode(['cashRegisterId' => $this->mainRegister, 'cashFund' => 10000]));
        $this->assertSame(201, $opened->getStatusCode(), $opened->getContent());
        $body = json_decode($opened->getContent(), true);
        $this->assertSame([$before + 10000, $fundBefore + 10000], [(float) $body['amountTotal'], (float) $body['fonDeCaisse']], 'fond ajouté au solde et au fond de caisse');
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $body['createdAt']);
        $this->assertNotSame('00:00:00', substr($this->db->fetchOne('SELECT created_at FROM caisse WHERE id = ?', [$body['caisse_id']]), 11), 'heure d\'ouverture conservée');
        $transactions = $this->json($this->request('GET', '/api/caisse/transactions?cashRegisterId=' . $this->mainRegister));
        $this->assertSame(['Ouverture', 'Ajout fond de caisse'], array_column($transactions, 'transactionType'));

        // Fermeture puis réouverture sans cashFund : solde et fond de caisse reportés
        $this->assertSame(200, $this->request('POST', '/api/caisse/close', json_encode(['cashRegisterId' => $this->mainRegister]))->getStatusCode());
        $reopened = json_decode($this->request('POST', '/api/caisse/open', json_encode(['cashRegisterId' => $this->mainRegister]))->getContent(), true);
        $this->assertSame([$before + 10000, $fundBefore + 10000], [(float) $reopened['amountTotal'], (float) $reopened['fonDeCaisse']], 'reportés de la session fermée du même poste');
        $session = $this->json($this->request('GET', '/api/caisse?cashRegisterId=' . $this->mainRegister))[0];
        $this->assertNotSame('00:00:00', substr((string) $session['createdAt'], 11), 'GET /api/caisse : heure d\'ouverture');
    }

    public function testTheRegisterMustBeNamedWhenSeveralAreActive(): void
    {
        $second = $this->createRegister('Comptoir ' . bin2hex(random_bytes(2)));
        $this->loginAs(['ROLE_USER_POS']);
        $open = $this->open(null);
        $this->assertSame(400, $open->getStatusCode());
        $this->assertSame('CASH_REGISTER_REQUIRED', json_decode($open->getContent(), true)['error']);
        [$variant, $total] = $this->counterSale();
        $sale = $this->sell($variant, $total, null);
        $this->assertSame(400, $sale->getStatusCode(), $sale->getContent());
        $this->assertSame('CASH_REGISTER_REQUIRED', json_decode($sale->getContent(), true)['error']);
        $this->assertSame(400, $this->request('GET', '/api/caisse/transactions')->getStatusCode());
        $this->assertSame(200, $this->request('GET', '/api/caisse')->getStatusCode(), 'la liste des sessions de tous les postes reste lisible');
        $unknown = $this->open(999999);
        $this->assertSame(404, $unknown->getStatusCode());
        $this->assertSame('CASH_REGISTER_NOT_FOUND', json_decode($unknown->getContent(), true)['error']);
        $this->assertSame(201, $this->open($second)->getStatusCode(), 'avec le poste nommé, tout fonctionne');
    }

    public function testASaleOnAClosedRegisterIsRefusedAndNothingIsCreated(): void
    {
        $this->loginAs(['ROLE_USER_POS']);
        [$variant, $total] = $this->counterSale();
        $orders = (int) $this->db->fetchOne('SELECT COUNT(*) FROM "order"');
        $payments = (int) $this->db->fetchOne('SELECT COUNT(*) FROM payments');
        $sale = $this->sell($variant, $total, null);
        $this->assertSame(409, $sale->getStatusCode(), $sale->getContent());
        $this->assertSame('CASH_REGISTER_CLOSED', json_decode($sale->getContent(), true)['error']);
        $this->assertSame($orders, (int) $this->db->fetchOne('SELECT COUNT(*) FROM "order"'), 'aucune commande créée');
        $this->assertSame($payments, (int) $this->db->fetchOne('SELECT COUNT(*) FROM payments'), 'aucun paiement créé');
    }

    public function testARefundGoesToTheRegisterOfTheOriginalSale(): void
    {
        $second = $this->createRegister('Comptoir ' . bin2hex(random_bytes(2)));
        $this->loginAs(['ROLE_USER_POS']);
        $this->open($this->mainRegister);
        $this->open($second);
        $before = array_column($this->json($this->request('GET', '/api/cash-registers')), 'openSession', 'id'); // montants reportés des sessions précédentes
        [$variant, $total] = $this->counterSale();
        $orderId = json_decode($this->sell($variant, $total, $second)->getContent(), true)['orderId'];

        $cancel = $this->request('POST', "/api/order/cancel/$orderId", json_encode(['paymentMethod' => 2]));
        $this->assertSame(200, $cancel->getStatusCode(), $cancel->getContent());
        $sessions = array_column($this->json($this->request('GET', '/api/cash-registers')), 'openSession', 'id');
        $this->assertSame((float) $before[$second]['amountTotal'], (float) $sessions[$second]['amountTotal'], 'remboursé sur le poste de la vente');
        $this->assertSame((float) $before[$this->mainRegister]['amountTotal'], (float) $sessions[$this->mainRegister]['amountTotal'], 'poste principal intact');
        $this->assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(DISTINCT caisse_id) FROM transaction_caisse WHERE order_caisse_id = ?', [$orderId]), 'vente et remboursement sur la même session');

        // Session d'origine fermée : le remboursement va au poste demandé (cashRegisterId), s'il est ouvert
        $orderId = json_decode($this->sell($variant, $total, $second)->getContent(), true)['orderId'];
        $this->request('POST', '/api/caisse/close', json_encode(['cashRegisterId' => $second]));
        $this->request('POST', '/api/caisse/deposit', json_encode(['amount' => $total, 'cashDetails' => [], 'cashRegisterId' => $this->mainRegister]));
        $cancel = $this->request('POST', "/api/order/cancel/$orderId", json_encode(['paymentMethod' => 2, 'cashRegisterId' => $this->mainRegister]));
        $this->assertSame(200, $cancel->getStatusCode(), $cancel->getContent());
        $sessions = array_column($this->json($this->request('GET', '/api/cash-registers')), 'openSession', 'id');
        $this->assertSame((float) $before[$this->mainRegister]['amountTotal'], (float) $sessions[$this->mainRegister]['amountTotal'], 'le dépôt a servi au remboursement sur le poste demandé');
    }

    public function testCashRegisterAdministration(): void
    {
        $this->loginAs(['ROLE_USER_POS']);
        $this->assertSame(403, $this->request('POST', '/api/cash-registers', json_encode(['name' => 'Interdit']))->getStatusCode(), 'caissier : pas de création');

        $this->loginAs(['ROLE_ADMIN', 'ROLE_USER_POS']);
        $empty = $this->request('POST', '/api/cash-registers', json_encode(['name' => '  ']));
        $this->assertSame(422, $empty->getStatusCode());
        $this->assertSame('VALIDATION_FAILED', json_decode($empty->getContent(), true)['error']);
        $name = 'Comptoir ' . bin2hex(random_bytes(2));
        $id = $this->createRegister($name);
        $duplicate = $this->request('POST', '/api/cash-registers', json_encode(['name' => strtoupper($name)]));
        $this->assertSame(422, $duplicate->getStatusCode(), 'nom unique (sans distinction de casse)');

        $renamed = $this->request('PUT', "/api/cash-registers/$id", json_encode(['name' => $name . ' bis']));
        $this->assertSame(200, $renamed->getStatusCode(), $renamed->getContent());
        $this->assertSame($name . ' bis', json_decode($renamed->getContent(), true)['name']);

        $this->open($id);
        $busy = $this->request('PUT', "/api/cash-registers/$id", json_encode(['isActive' => false]));
        $this->assertSame(409, $busy->getStatusCode());
        $this->assertSame('CASH_REGISTER_OPEN', json_decode($busy->getContent(), true)['error']);
        $this->request('POST', '/api/caisse/close', json_encode(['cashRegisterId' => $id]));
        $off = $this->request('PUT', "/api/cash-registers/$id", json_encode(['isActive' => false]));
        $this->assertSame(200, $off->getStatusCode(), $off->getContent());
        $this->assertFalse(json_decode($off->getContent(), true)['isActive']);

        $this->assertNotContains($id, array_column($this->json($this->request('GET', '/api/cash-registers')), 'id'), 'inactif : absent de la liste');
        $this->assertContains($id, array_column($this->json($this->request('GET', '/api/cash-registers?all=1')), 'id'), 'admin ?all=1 : inclus');
        $this->assertSame(404, $this->request('PUT', '/api/cash-registers/999999', json_encode(['name' => 'x']))->getStatusCode());

        $this->loginAs(['ROLE_USER_POS']);
        $this->assertNotContains($id, array_column($this->json($this->request('GET', '/api/cash-registers?all=1')), 'id'), 'caissier : ?all ignoré');
    }

    public function testAWebOrderPaidWithStripeNeverTouchesACashRegister(): void
    {
        $this->loginAs(['ROLE_USER_POS']);
        $this->open($this->mainRegister);
        $transactions = (int) $this->db->fetchOne('SELECT COUNT(*) FROM transaction_caisse');

        $this->loginAs(['ROLE_USER_INTERNET'], 'mobile');
        $variant = $this->variant('DEMO-CASQUETTE');
        $order = ['addressId' => $this->address(), 'carrierId' => 6, 'items' => [['productVariantId' => $variant, 'quantity' => 1]]];
        $intent = $this->json($this->request('POST', '/api/stripe/create-intent', json_encode(['items' => $order['items'], 'carrierId' => 6, 'order' => $order])));
        FakeCheckoutPaymentGateway::confirm($intent['paymentIntentId']);
        $created = $this->request('POST', '/api/order/create', json_encode($order + ['paymentIntentId' => $intent['paymentIntentId']]));
        $this->assertSame(201, $created->getStatusCode(), $created->getContent());
        $orderId = json_decode($created->getContent(), true)['orderId'];
        $this->assertSame(1, (int) $this->db->fetchOne('SELECT order_source_id FROM "order" WHERE id = ?', [$orderId]), 'source e-commerce');
        $this->assertSame($transactions, (int) $this->db->fetchOne('SELECT COUNT(*) FROM transaction_caisse'), 'aucune transaction de caisse');
    }

    /** @return array{0: int, 1: int} variante et total (cents) d'une vente au comptoir d'une casquette, taxée au lieu de vente */
    private function counterSale(): array
    {
        $variant = $this->variant('DEMO-CASQUETTE');
        $quote = $this->json($this->request('POST', '/api/cart/quote', json_encode([
            'items' => [['productVariantId' => $variant, 'quantity' => 1]],
            'shippingAddress' => ['country' => 'CA', 'province' => 'QC', 'city' => 'Montréal', 'postalCode' => 'H1M 0A9'],
        ])));

        return [$variant, (int) $quote['total']];
    }

    private function sell(int $variant, int $total, ?int $cashRegisterId): Response
    {
        return $this->request('POST', '/api/order/create-multi-payment', json_encode(array_filter([
            'items' => [['productVariantId' => $variant, 'quantity' => 1]],
            'paymentMethods' => [['type' => 2, 'amount' => $total / 100]],
            'cashRegisterId' => $cashRegisterId,
        ], fn ($v) => $v !== null)));
    }

    private function open(?int $cashRegisterId): Response
    {
        return $this->request('POST', '/api/caisse/open', json_encode($cashRegisterId !== null ? ['cashRegisterId' => $cashRegisterId] : []));
    }

    private function createRegister(string $name): int
    {
        $previous = $this->token;
        $this->loginAs(['ROLE_ADMIN', 'ROLE_USER_POS']);
        $created = $this->request('POST', '/api/cash-registers', json_encode(['name' => $name]));
        $this->assertSame(201, $created->getStatusCode(), $created->getContent());
        $body = json_decode($created->getContent(), true);
        $this->assertSame([$name, true, null], [$body['name'], $body['isActive'], $body['openSession']]);
        $this->token = $previous;

        return (int) $body['id'];
    }

    private function variant(string $code): int
    {
        return (int) $this->db->fetchOne('SELECT v.id FROM product_variant v JOIN product p ON p.id = v.product_id WHERE p.code = ? ORDER BY v.id LIMIT 1', [$code]);
    }

    private function address(): int
    {
        $em = static::getContainer()->get(TenantEntityManagerProvider::class)->getEntityManager();
        $user = $em->getRepository(User::class)->findOneBy(['email' => $this->email]);
        $address = (new Adress())->setFirstname('Client')->setLastname('Web')->setFullname('Client Web')->setAddress('1 rue du Test')->setCity('Montréal')
            ->setCodepostal('H1A 1A1')->setCountry('CA')->setPhone('+1 514 555 0000')->setProvince('QC')->setUserAdress($user);
        $em->persist($address);
        $em->flush();

        return (int) $address->getId();
    }

    private function json(Response $response): array
    {
        $this->assertSame(200, $response->getStatusCode(), $response->getContent());

        return json_decode($response->getContent(), true);
    }

    private function request(string $method, string $path, ?string $body = null): Response
    {
        $this->client->getCookieJar()->clear();
        $this->client->request($method, 'https://' . MV_TEST_TENANT_HOST . $path, [], [], array_filter([
            'HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => $this->token ? 'Bearer ' . $this->token : null,
        ]), $body);

        return $this->client->getResponse();
    }

    private function loginAs(array $roles, string $platform = 'pos'): void
    {
        $this->email = 'postes-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $container = static::getContainer();
        $provider = $container->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $user = (new User())->setEmail($this->email)->setUsername($this->email)->setFirstname('Postes')->setLastname('Test')->setRoles($roles)->setIsVerified(true);
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD));
        $provider->getEntityManager()->persist($user);
        $provider->getEntityManager()->flush();
        $this->token = null;
        $login = $this->request('POST', '/api/login', json_encode(['username' => $this->email, 'password' => self::PASSWORD, 'platform' => $platform]));
        $this->assertSame(200, $login->getStatusCode(), $login->getContent());
        $this->token = json_decode($login->getContent(), true)['token'];
    }
}
