<?php

namespace App\Tests\Functional\Boutique;

use App\Controller\Admin\CaisseCrudController;
use App\Controller\Admin\OrderAllCrudController;
use App\Controller\Admin\PaymentsCrudController;
use App\Controller\Admin\TransactionCaisseCrudController;
use App\Entity\Caisse;
use App\Entity\Order;
use App\Entity\Payments;
use App\Entity\TransactionCaisse;
use App\Entity\User;
use App\Services\BoutiqueDemoService\BoutiqueDemoSeeder;
use App\Services\TenantEntityManagerProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Chantier C2 du POS (19/10/2026) : journal d'audit inaltérable et chaîné, numéros de tickets et d'avoirs par poste,
 * ouverture du tiroir, identification du terminal, données de caisse verrouillées dans EasyAdmin.
 */
class PosAuditApiTest extends WebTestCase
{
    private const PASSWORD = 'Mot-de-passe-de-test-1!';
    private KernelBrowser $client;
    private \Doctrine\DBAL\Connection $db;
    private ?string $token = null;
    private ?string $terminal = null;
    private int $register;
    private int $variant;
    private string $email = '';

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $this->db = $provider->getEntityManager()->getConnection();
        static::getContainer()->get(BoutiqueDemoSeeder::class)->seed('client-caisse@example.invalid', false, false);
        $this->db->executeStatement('DELETE FROM boutique_setting');
        $this->db->executeStatement("UPDATE address_entreprise SET country = 'CA', state = 'QC', city = 'Montréal', zip = 'H1M 0A9'");
        $this->db->executeStatement('UPDATE entreprise SET pos_discrepancy_threshold = 0, pos_cash_rounding_step = NULL, pos_return_without_receipt = true');
        // Retour libre (typeOrder 2) soumis au réglage « retour sans ticket » depuis le lot P2-a (26/10/2026) : ce test exerce l'ancien parcours, réglage à oui
        $this->db->executeStatement('UPDATE caisse SET is_open = false, closed_at = NOW() WHERE is_open = true');
        $this->db->executeStatement('UPDATE entreprise SET pos_approval_refund = NULL, pos_approval_withdrawal = NULL, pos_approval_fund_withdrawal = NULL, pos_approval_drawer_open = false, pos_approval_close_without_count = false'); // validations du responsable : PosPinApiTest
        $this->db->executeStatement('UPDATE cash_register SET is_active = (id = (SELECT MIN(id) FROM cash_register))');
        $this->register = (int) $this->db->fetchOne('SELECT MIN(id) FROM cash_register');
        $this->variant = (int) $this->db->fetchOne("SELECT v.id FROM product_variant v JOIN product p ON p.id = v.product_id WHERE p.code = 'DEMO-CASQUETTE' ORDER BY v.id LIMIT 1");
        $this->db->executeStatement("UPDATE product SET price = 2900 WHERE code = 'DEMO-CASQUETTE'");
        $this->db->executeStatement('UPDATE product_variant SET stock_quantity = 500, price = NULL WHERE id = ?', [$this->variant]);
    }

    public function testTerminalLoginLogoutAndFailedLoginAreJournaled(): void
    {
        $this->loginAs(['ROLE_ADMIN', 'ROLE_USER_POS'], 'pos');
        $key = json_decode($this->request('POST', '/api/app-keys', json_encode(['app' => 'pos', 'label' => 'Tablette audit', 'sendEmail' => false, 'cashRegisterId' => $this->register]))->getContent(), true)['key'];
        $living = json_decode($this->request('POST', '/api/app-keys', json_encode(['app' => 'livingmemory', 'label' => 'Téléphone', 'sendEmail' => false]))->getContent(), true)['key'];
        $first = $this->activate($key, 'Tablette 1');
        $again = $this->activate($key, 'Tablette 1');
        $this->assertSame($first['terminal'], $again['terminal'], 'même clé : même terminal, sans en créer un autre');
        $this->assertArrayNotHasKey('terminal', $this->activate($living, 'Téléphone', 'livingmemory'), 'LivingMemory : réponse inchangée');
        $this->terminal = $first['terminal']['token'];

        $start = $this->lastSequence();
        $email = $this->user(['ROLE_USER_POS']);
        $this->assertSame(200, $this->login($email, 'pos')->getStatusCode());
        $this->assertSame(401, $this->login($email, 'pos', 'mauvais-mot-de-passe')->getStatusCode());
        $this->assertSame(200, $this->login($this->user(['ROLE_USER_INTERNET']), 'web')->getStatusCode(), 'connexion web : pas de journal');
        $refresh = json_decode($this->login($email, 'pos')->getContent(), true)['refresh_token'];
        $this->assertSame(200, $this->request('POST', '/api/logout', json_encode(['refresh_token' => $refresh]))->getStatusCode());

        $entries = $this->entriesSince($start);
        $this->assertSame(['LOGIN', 'LOGIN_FAILED', 'LOGIN', 'LOGOUT'], array_column($entries, 'type'));
        $this->assertSame(['id' => $first['terminal']['id'], 'label' => 'Tablette audit'], $entries[0]['terminal']);
        $this->assertSame($email, $entries[1]['data']['email']);
        $this->assertNull($entries[1]['user'], 'connexion refusée : e-mail seulement');
        $this->assertStringNotContainsString('mauvais-mot-de-passe', json_encode($entries[1]), 'jamais le mot de passe');
        $this->assertSame($email, $entries[3]['user']['email']);

        // En-tête invalide : terminal inconnu, aucun refus
        $this->terminal = $first['terminal']['id'] . '.' . str_repeat('0', 32);
        $this->assertSame(200, $this->login($email, 'pos')->getStatusCode());
        $this->assertNull($this->entriesSince($this->lastSequence() - 1)[0]['terminal']);
    }

    public function testCashActivityIsNumberedAndJournaledAndTheChainIsVerifiable(): void
    {
        $this->loginAs(['ROLE_USER_POS']);
        $start = $this->lastSequence();
        $this->assertSame(201, $this->request('POST', '/api/caisse/open', json_encode(['cashRegisterId' => $this->register, 'cashFund' => 10000]))->getStatusCode());
        $this->request('POST', '/api/caisse/deposit', json_encode(['amount' => 1000, 'cashRegisterId' => $this->register]));
        $this->request('POST', '/api/caisse/withdraw', json_encode(['amount' => 500, 'cashRegisterId' => $this->register]));
        $this->request('POST', '/api/caisse/cashfunddeposit', json_encode(['amount' => 200, 'cashRegisterId' => $this->register]));

        $quote = json_decode($this->request('POST', '/api/cart/quote', json_encode(['items' => [['productVariantId' => $this->variant, 'quantity' => 1]], 'cashRegisterId' => $this->register]))->getContent(), true);
        $sale1 = json_decode($this->sell($quote['cashTotal'] / 100)->getContent(), true);
        $sale2 = json_decode($this->sell($quote['cashTotal'] / 100)->getContent(), true);
        $this->assertSame(['ticket', 'ticket'], [$sale1['receiptKind'], $sale2['receiptKind']]);
        $this->assertSame($sale1['receiptNumber'] + 1, $sale2['receiptNumber'], 'tickets : suite continue');
        $return = json_decode($this->sell($quote['cashTotal'] / 100, 2)->getContent(), true);
        $this->assertSame('credit_note', $return['receiptKind'], 'retour : avoir');
        $cancel = $this->request('POST', '/api/order/cancel/' . $sale1['orderId'], json_encode(['paymentMethod' => 2]));
        $this->assertSame(200, $cancel->getStatusCode(), $cancel->getContent());
        $cancelBody = json_decode($cancel->getContent(), true);
        $this->assertSame($return['receiptNumber'] + 1, $cancelBody['creditNoteNumber'], 'avoirs : suite continue');
        $this->assertSame($this->register, $cancelBody['cashRegister']['id']);

        $this->assertSame(400, $this->request('POST', '/api/caisse/drawer-open', json_encode(['cashRegisterId' => $this->register]))->getStatusCode());
        $drawer = $this->request('POST', '/api/caisse/drawer-open', json_encode(['cashRegisterId' => $this->register, 'reason' => 'Monnaie pour un client']));
        $this->assertSame(201, $drawer->getStatusCode(), $drawer->getContent());
        $this->assertSame($this->register, json_decode($drawer->getContent(), true)['cashRegisterId']);
        $this->request('GET', '/api/caisse/report/x?cashRegisterId=' . $this->register);
        $preview = json_decode($this->request('GET', '/api/caisse/report/x?purpose=closing&cashRegisterId=' . $this->register)->getContent(), true);
        $this->assertSame(['first' => $sale1['receiptNumber'], 'last' => $sale2['receiptNumber'], 'count' => 2], $preview['receipts']);
        $this->assertSame(['first' => $return['receiptNumber'], 'last' => $cancelBody['creditNoteNumber'], 'count' => 2], $preview['creditNotes']);

        // Listes de commandes : numéros sur les ventes au comptoir seulement
        $this->loginAs(['ROLE_ADMIN', 'ROLE_USER_POS', 'ROLE_POS_MANAGER']);
        $pos = array_column(json_decode($this->request('GET', '/api/orders?orderSource=2')->getContent(), true), null, 'id');
        $this->assertSame([$sale2['receiptNumber'], null], [$pos[$sale2['orderId']]['receiptNumber'], $pos[$sale2['orderId']]['creditNoteNumber']]);
        $this->assertSame([$sale1['receiptNumber'], $cancelBody['creditNoteNumber']], [$pos[$sale1['orderId']]['receiptNumber'], $pos[$sale1['orderId']]['creditNoteNumber']]);
        foreach (json_decode($this->request('GET', '/api/orders?orderSource=1')->getContent(), true) as $web) {
            $this->assertArrayNotHasKey('receiptNumber', $web, 'commande web : objet inchangé');
        }

        $closed = json_decode($this->request('POST', '/api/caisse/close', json_encode(['cashRegisterId' => $this->register]))->getContent(), true);
        $this->assertSame(2, $closed['report']['receipts']['count']);

        $entries = $this->entriesSince($start);
        $this->assertSame(['SESSION_OPENED', 'CASH_DEPOSIT', 'CASH_WITHDRAWAL', 'FUND_DEPOSIT', 'SALE', 'SALE', 'REFUND', 'REFUND', 'DRAWER_OPENED', 'X_REPORT_VIEWED', 'CLOSING_PREVIEW', 'LOGIN', 'SESSION_CLOSED', 'Z_REPORT_CREATED'],
            array_column($entries, 'type'));
        $byType = [];
        foreach ($entries as $entry) {
            $byType[$entry['type']][] = $entry;
        }
        $this->assertSame($sale1['receiptNumber'], $byType['SALE'][0]['data']['receiptNumber']);
        $this->assertSame('return', $byType['REFUND'][0]['data']['kind']);
        $refund = $byType['REFUND'][1]['data'];
        $this->assertSame(['cancellation', $quote['total'], 0], [$refund['kind'], $refund['before']['totalAmount'], $refund['after']['totalAmount']], 'montants d\'origine conservés');
        $this->assertSame('Monnaie pour un client', $byType['DRAWER_OPENED'][0]['data']['reason']);
        $this->assertSame([$this->register, $closed['caisse_id']], [$byType['Z_REPORT_CREATED'][0]['cashRegisterId'], $byType['Z_REPORT_CREATED'][0]['sessionId']]);

        // Second poste : sa propre suite de tickets
        $second = (int) json_decode($this->request('POST', '/api/cash-registers', json_encode(['name' => 'Poste audit ' . bin2hex(random_bytes(2))]))->getContent(), true)['id'];
        $this->request('POST', '/api/caisse/open', json_encode(['cashRegisterId' => $second]));
        $other = json_decode($this->request('POST', '/api/order/create-multi-payment', json_encode(['items' => [['productVariantId' => $this->variant, 'quantity' => 1]], 'paymentMethods' => [['type' => 1, 'amount' => $quote['total'] / 100]], 'cashRegisterId' => $second]))->getContent(), true);
        $this->assertSame(1, $other['receiptNumber'], 'chaque poste commence à 1');

        // Journal : réservé au responsable et à l'administrateur ; filtres ; chaînage
        $list = json_decode($this->request('GET', '/api/pos/audit?type=SALE,REFUND&cashRegisterId=' . $this->register . '&pageSize=2')->getContent(), true);
        $this->assertSame(2, $list['meta']['pageSize']);
        $this->assertGreaterThanOrEqual(4, $list['meta']['total']);
        $this->assertGreaterThan($list['data'][1]['sequence'], $list['data'][0]['sequence'], 'plus récent d\'abord');
        $this->assertSame('INVALID_TYPE', json_decode($this->request('GET', '/api/pos/audit?type=NIMPORTE')->getContent(), true)['error']);
        $this->assertSame(['status' => 'intact', 'firstBroken' => null], array_diff_key(json_decode($this->request('GET', '/api/pos/audit/verify')->getContent(), true), ['checked' => 1]));
        $this->loginAs(['ROLE_USER_POS']);
        $this->assertSame(403, $this->request('GET', '/api/pos/audit')->getStatusCode(), 'caissier : refusé');
        $this->assertSame(403, $this->request('GET', '/api/pos/audit/verify')->getStatusCode());
    }

    public function testTheJournalCannotBeAlteredAndTamperingIsDetected(): void
    {
        $this->loginAs(['ROLE_USER_POS']);
        $this->request('POST', '/api/caisse/open', json_encode(['cashRegisterId' => $this->register]));
        $this->request('POST', '/api/caisse/drawer-open', json_encode(['cashRegisterId' => $this->register, 'reason' => 'Contrôle']));
        $target = $this->db->fetchAssociative('SELECT id, sequence, payload FROM pos_audit_log ORDER BY sequence DESC LIMIT 1');

        foreach (["UPDATE pos_audit_log SET type = 'X' WHERE id = {$target['id']}", "DELETE FROM pos_audit_log WHERE id = {$target['id']}", 'TRUNCATE pos_audit_log'] as $sql) {
            try {
                $this->db->executeStatement($sql);
                $this->fail("$sql aurait dû être refusé");
            } catch (\Doctrine\DBAL\Exception $e) {
                $this->assertStringContainsString('inaltérable', $e->getMessage());
            }
        }

        // Altération simulée (déclencheur désactivé le temps du test) : le contrôle désigne l'entrée en défaut
        $this->db->executeStatement('ALTER TABLE pos_audit_log DISABLE TRIGGER pos_audit_log_no_update');
        try {
            $this->db->executeStatement('UPDATE pos_audit_log SET payload = ? WHERE id = ?', [str_replace('Contrôle', 'Falsifié', $target['payload']), $target['id']]);
            $this->loginAs(['ROLE_ADMIN', 'ROLE_USER_POS']);
            $broken = json_decode($this->request('GET', '/api/pos/audit/verify')->getContent(), true);
            $this->assertSame(['broken', (int) $target['sequence']], [$broken['status'], $broken['firstBroken']['sequence']]);
        } finally {
            $this->db->executeStatement('UPDATE pos_audit_log SET payload = ? WHERE id = ?', [$target['payload'], $target['id']]);
            $this->db->executeStatement('ALTER TABLE pos_audit_log ENABLE TRIGGER pos_audit_log_no_update');
        }
        $this->assertSame('intact', json_decode($this->request('GET', '/api/pos/audit/verify')->getContent(), true)['status']);
    }

    public function testEasyAdminCannotEditOrDeleteCashData(): void
    {
        $this->loginAs(['ROLE_USER_POS']);
        $this->request('POST', '/api/caisse/open', json_encode(['cashRegisterId' => $this->register]));
        $quote = json_decode($this->request('POST', '/api/cart/quote', json_encode(['items' => [['productVariantId' => $this->variant, 'quantity' => 1]], 'cashRegisterId' => $this->register]))->getContent(), true);
        $orderId = json_decode($this->sell($quote['cashTotal'] / 100)->getContent(), true)['orderId'];

        $container = static::getContainer();
        $em = $container->get(TenantEntityManagerProvider::class)->getEntityManager();
        $order = $em->find(Order::class, $orderId);
        $payment = $em->getRepository(Payments::class)->findOneBy(['orderPayment' => $order]);
        $transaction = $em->getRepository(TransactionCaisse::class)->findOneBy(['orderCaisse' => $order]);
        $session = $em->getRepository(Caisse::class)->findOneBy(['cashRegister' => $this->register, 'isOpen' => true]);
        $locked = [
            [OrderAllCrudController::class, $order], [PaymentsCrudController::class, $payment],
            [TransactionCaisseCrudController::class, $transaction], [CaisseCrudController::class, $session],
        ];
        foreach ($locked as [$controller, $entity]) {
            foreach (['updateEntity', 'deleteEntity'] as $method) {
                try {
                    $container->get($controller)->$method($em, $entity);
                    $this->fail("$controller::$method aurait dû être refusé");
                } catch (AccessDeniedHttpException) {
                    $this->addToAssertionCount(1);
                }
            }
        }
        $this->assertNotNull($em->find(Order::class, $orderId), 'vente toujours là');

        // Commande web : modification toujours possible
        $web = $em->getRepository(Order::class)->findOneBy(['orderSource' => 1]);
        if ($web) {
            $container->get(OrderAllCrudController::class)->updateEntity($em, $web);
            $this->addToAssertionCount(1);
        }
    }

    public function testEasyAdminScreensStillRender(): void
    {
        $this->loginAs(['ROLE_USER_POS']);
        $this->request('POST', '/api/caisse/open', json_encode(['cashRegisterId' => $this->register]));
        $quote = json_decode($this->request('POST', '/api/cart/quote', json_encode(['items' => [['productVariantId' => $this->variant, 'quantity' => 1]], 'cashRegisterId' => $this->register]))->getContent(), true);
        $orderId = json_decode($this->sell($quote['cashTotal'] / 100)->getContent(), true)['orderId'];
        $this->request('POST', '/api/caisse/drawer-open', json_encode(['cashRegisterId' => $this->register, 'reason' => 'Écran']));
        $auditId = (int) $this->db->fetchOne('SELECT MAX(id) FROM pos_audit_log');
        $webOrder = (int) $this->db->fetchOne('SELECT MIN(id) FROM "order" WHERE order_source_id = 1');

        $admin = $this->user(['ROLE_ADMIN']);
        $em = static::getContainer()->get(TenantEntityManagerProvider::class)->getEntityManager();
        $this->client->loginUser($em->getRepository(User::class)->findOneBy(['email' => $admin]), 'main');
        $A = 'App\\Controller\\Admin\\';
        $pages = [];
        foreach (['OrderAllCrudController', 'OrderCrudController', 'OrderItemsCrudController', 'OrderItemsListController', 'OrderTaxCrudController', 'PaymentsCrudController',
            'CaisseCrudController', 'TransactionCaisseCrudController', 'TransactionCaisseListController', 'CashDetailsCrudController', 'CashDetailsListController',
            'CashRegisterCrudController', 'CashRegisterReportCrudController', 'PosAuditLogCrudController', 'TypeCashCrudController', 'EntrepriseCrudController'] as $controller) {
            $pages[] = ['index', $A . $controller, null];
        }
        $pages[] = ['detail', $A . 'OrderAllCrudController', $orderId];
        $pages[] = ['detail', $A . 'PosAuditLogCrudController', $auditId];
        $pages[] = ['new', $A . 'OrderAllCrudController', null];
        if ($webOrder) {
            $pages[] = ['edit', $A . 'OrderAllCrudController', $webOrder];
        }
        foreach ($pages as [$action, $controller, $id]) {
            $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin?' . http_build_query(array_filter(['crudAction' => $action, 'crudControllerFqcn' => $controller, 'entityId' => $id])), [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
            $this->assertSame(200, $this->client->getResponse()->getStatusCode(), "$action " . substr($controller, strrpos($controller, '\\') + 1) . ' : ' . substr(strip_tags($this->client->getResponse()->getContent()), 0, 300));
        }
        // Fiche d'une vente au comptoir : ni « Modifier » ni « Supprimer »
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin?' . http_build_query(['crudAction' => 'detail', 'crudControllerFqcn' => $A . 'OrderAllCrudController', 'entityId' => $orderId]), [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $this->assertStringNotContainsString('crudAction=edit', $this->client->getResponse()->getContent());
        $this->assertStringNotContainsString('action-delete', $this->client->getResponse()->getContent());
    }

    private function sell(float $amount, int $typeOrder = 1): Response
    {
        return $this->request('POST', '/api/order/create-multi-payment', json_encode([
            'items' => [['productVariantId' => $this->variant, 'quantity' => 1]], 'paymentMethods' => [['type' => 2, 'amount' => $amount]],
            'cashRegisterId' => $this->register, 'typeOrder' => $typeOrder,
        ]));
    }

    private function activate(string $key, string $device, string $app = 'pos'): array
    {
        $this->client->getCookieJar()->clear();
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . '/api/app/activate', [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], json_encode(['key' => $key, 'app' => $app, 'deviceName' => $device]));
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());

        return json_decode($this->client->getResponse()->getContent(), true);
    }

    private function lastSequence(): int
    {
        return (int) ($this->db->fetchOne('SELECT MAX(sequence) FROM pos_audit_log') ?: 0);
    }

    /** @return list<array<string, mixed>> contenu haché des entrées postérieures à $sequence */
    private function entriesSince(int $sequence): array
    {
        return array_map(fn ($payload) => json_decode($payload, true), $this->db->fetchFirstColumn('SELECT payload FROM pos_audit_log WHERE sequence > ? ORDER BY sequence', [$sequence]));
    }

    private function request(string $method, string $path, ?string $body = null): Response
    {
        $this->client->getCookieJar()->clear();
        $this->client->request($method, 'https://' . MV_TEST_TENANT_HOST . $path, [], [], array_filter([
            'HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => $this->token ? 'Bearer ' . $this->token : null,
            'HTTP_X_POS_TERMINAL' => $this->terminal,
        ]), $body);

        return $this->client->getResponse();
    }

    private function login(string $email, string $platform, string $password = self::PASSWORD): Response
    {
        $this->token = null;

        return $this->request('POST', '/api/login', json_encode(['username' => $email, 'password' => $password, 'platform' => $platform]));
    }

    private function loginAs(array $roles, string $platform = 'pos'): void
    {
        $login = $this->login($this->user($roles), $platform);
        $this->assertSame(200, $login->getStatusCode(), $login->getContent());
        $this->token = json_decode($login->getContent(), true)['token'];
    }

    private function user(array $roles): string
    {
        $this->email = 'audit-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $container = static::getContainer();
        $provider = $container->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $user = (new User())->setEmail($this->email)->setUsername($this->email)->setFirstname('Audit')->setLastname('Test')->setRoles($roles)->setIsVerified(true);
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD));
        $provider->getEntityManager()->persist($user);
        $provider->getEntityManager()->flush();

        return $this->email;
    }
}
