<?php

namespace App\Tests\Functional\Boutique;

use App\Entity\Adress;
use App\Entity\User;
use App\Services\BoutiqueDemoService\BoutiqueDemoSeeder;
use App\Services\PosAuditService\PosPinService;
use App\Services\TenantEntityManagerProvider;
use App\Tests\Fake\FakeCheckoutPaymentGateway;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Chantier C3 du POS (20/10/2026) : PIN de caisse, déverrouillage, connexion par PIN depuis un terminal, validation d'un
 * responsable pour les actions sensibles (jeton court, usage unique, consommé dans la transaction de l'action), réglages.
 */
class PosPinApiTest extends WebTestCase
{
    private const PASSWORD = 'Mot-de-passe-de-test-1!';
    private KernelBrowser $client;
    private \Doctrine\DBAL\Connection $db;
    private ?string $token = null;
    private ?string $terminal = null;
    private ?string $approval = null;
    private int $register;
    private int $variant;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $this->db = $provider->getEntityManager()->getConnection();
        static::getContainer()->get(BoutiqueDemoSeeder::class)->seed('client-caisse@example.invalid', false, false);
        $this->db->executeStatement('DELETE FROM boutique_setting');
        $this->db->executeStatement("UPDATE address_entreprise SET country = 'CA', state = 'QC', city = 'Montréal', zip = 'H1M 0A9'");
        // Réglages par défaut du chantier C3 (validation toujours exigée, verrouillage 5 minutes)
        $this->db->executeStatement('UPDATE entreprise SET pos_discrepancy_threshold = 0, pos_cash_rounding_step = NULL, pos_approval_refund = 0, pos_approval_withdrawal = 0, pos_approval_fund_withdrawal = 0, pos_approval_drawer_open = true, pos_approval_close_without_count = true, pos_lock_minutes = 5');
        $this->db->executeStatement('UPDATE caisse SET is_open = false, closed_at = NOW() WHERE is_open = true');
        $this->db->executeStatement('UPDATE cash_register SET is_active = (id = (SELECT MIN(id) FROM cash_register))');
        $this->register = (int) $this->db->fetchOne('SELECT MIN(id) FROM cash_register');
        $this->variant = (int) $this->db->fetchOne("SELECT v.id FROM product_variant v JOIN product p ON p.id = v.product_id WHERE p.code = 'DEMO-CASQUETTE' ORDER BY v.id LIMIT 1");
        $this->db->executeStatement("UPDATE product SET price = 2900 WHERE code = 'DEMO-CASQUETTE'");
        $this->db->executeStatement('UPDATE product_variant SET stock_quantity = 500, price = NULL WHERE id = ?', [$this->variant]);
        $this->terminal = $this->newTerminal('Tablette PIN');
    }

    public function testPinLifecycleUnlockAndLockout(): void
    {
        $cashier = $this->loginAs(['ROLE_USER_POS']);
        $this->assertSame(409, $this->status('POST', '/api/pos/unlock', ['pin' => '1234'], 'PIN_NOT_SET'));
        $this->assertSame(400, $this->status('POST', '/api/pos/pin', ['password' => 'faux', 'newPin' => '2468'], 'PASSWORD_INVALID'));
        $this->assertSame(422, $this->status('POST', '/api/pos/pin', ['password' => self::PASSWORD, 'newPin' => '12a4'], 'VALIDATION_FAILED'));
        $this->assertSame(422, $this->status('POST', '/api/pos/pin', ['password' => self::PASSWORD, 'newPin' => 1234], 'VALIDATION_FAILED'), 'PIN envoyé en chaîne');
        $this->assertSame(204, $this->request('POST', '/api/pos/pin', ['password' => self::PASSWORD, 'newPin' => '2468'])->getStatusCode());
        $this->assertStringStartsNotWith('2468', (string) $this->db->fetchOne('SELECT pin_hash FROM pos_user_pin WHERE user_id = ?', [$cashier->getId()]), 'empreinte, jamais le PIN');

        $start = $this->lastSequence();
        $wrong = $this->request('POST', '/api/pos/unlock', ['pin' => '1111']);
        $this->assertSame([400, 'PIN_INVALID', 4], [$wrong->getStatusCode(), $this->body($wrong)['error'], $this->body($wrong)['attemptsLeft']]);
        $ok = $this->request('POST', '/api/pos/unlock', ['pin' => '2468']);
        $this->assertSame(200, $ok->getStatusCode());
        $this->assertNotEmpty($this->json($ok)['unlockedAt']);
        $this->assertSame(0, (int) $this->db->fetchOne('SELECT failed_attempts FROM pos_user_pin WHERE user_id = ?', [$cashier->getId()]), 'un PIN juste remet le compteur à 0');

        for ($i = 1; $i <= 4; $i++) {
            $this->assertSame(400, $this->status('POST', '/api/pos/unlock', ['pin' => '0000'], 'PIN_INVALID'));
        }
        $locked = $this->request('POST', '/api/pos/unlock', ['pin' => '0000']);
        $this->assertSame([423, 'PIN_LOCKED', 900], [$locked->getStatusCode(), $this->body($locked)['error'], $this->body($locked)['retryAfter']]);
        $this->assertSame('900', $locked->headers->get('Retry-After'));
        $this->assertSame(423, $this->status('POST', '/api/pos/unlock', ['pin' => '2468'], 'PIN_LOCKED'), 'bloqué même avec le bon PIN');
        $this->db->executeStatement('UPDATE pos_user_pin SET locked_until = ? WHERE user_id = ?', [(new \DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:s'), $cashier->getId()]);
        $this->assertSame(200, $this->request('POST', '/api/pos/unlock', ['pin' => '2468'])->getStatusCode(), 'après 15 minutes');

        $types = array_column($this->entriesSince($start), 'type');
        $this->assertSame(['PIN_FAILED', 'UNLOCK', 'PIN_FAILED', 'PIN_FAILED', 'PIN_FAILED', 'PIN_FAILED', 'PIN_FAILED', 'PIN_LOCKED', 'UNLOCK'], $types);
        $this->assertStringNotContainsString('0000', json_encode($this->entriesSince($start)), 'jamais le PIN au journal');
    }

    public function testUsersListAndPinLogin(): void
    {
        $cashier = $this->user(['ROLE_USER_POS'], 'Camille', 'Caisse');
        $manager = $this->user(['ROLE_USER_POS', 'ROLE_POS_MANAGER'], 'Rémi', 'Responsable');
        $admin = $this->user(['ROLE_ADMIN', 'ROLE_USER_POS'], 'Alix', 'Admin');
        $otp = $this->user(['ROLE_USER_POS'], 'Odile', 'Otp');
        $this->db->executeStatement('UPDATE "user" SET otp_enabled = true WHERE id = ?', [$otp->getId()]);
        $noPin = $this->user(['ROLE_USER_POS'], 'Nina', 'Sanspin');
        foreach ([$cashier, $manager, $admin, $otp] as $user) {
            $this->setPin($user, '1357');
        }

        $this->token = null;
        $terminal = $this->terminal;
        $this->terminal = null;
        $this->assertSame(403, $this->status('GET', '/api/pos/users', null, 'TERMINAL_REQUIRED'));
        $this->assertSame(403, $this->status('POST', '/api/pos/pin-login', ['userId' => $cashier->getId(), 'pin' => '1357'], 'TERMINAL_REQUIRED'));
        $this->terminal = $terminal;
        $users = array_column($this->json($this->request('GET', '/api/pos/users')), null, 'id');
        $this->assertArrayHasKey($cashier->getId(), $users);
        $this->assertSame(['name' => 'Rémi Responsable', 'initials' => 'RR', 'roles' => ['ROLE_USER_POS', 'ROLE_POS_MANAGER']], array_diff_key($users[$manager->getId()], ['id' => 1]));
        foreach ([$admin, $otp, $noPin] as $excluded) {
            $this->assertArrayNotHasKey($excluded->getId(), $users, 'administrateur, compte à code e-mail ou sans PIN : absent');
        }

        $start = $this->lastSequence();
        $this->assertSame(401, $this->status('POST', '/api/pos/pin-login', ['userId' => $cashier->getId(), 'pin' => '9999'], 'PIN_INVALID'));
        $login = $this->request('POST', '/api/pos/pin-login', ['userId' => $cashier->getId(), 'pin' => '1357']);
        $this->assertSame(200, $login->getStatusCode(), $login->getContent());
        $tokens = $this->json($login);
        $this->assertNotEmpty($tokens['refresh_token']);
        $this->token = $tokens['token'];
        $this->assertSame(200, $this->request('GET', '/api/pos/settings')->getStatusCode(), 'jeton utilisable');
        $this->token = null;
        $this->assertSame(403, $this->status('POST', '/api/pos/pin-login', ['userId' => $admin->getId(), 'pin' => '1357'], 'PIN_LOGIN_NOT_ALLOWED'), 'administrateur : jamais par PIN');
        $this->assertSame(403, $this->status('POST', '/api/pos/pin-login', ['userId' => $otp->getId(), 'pin' => '1357'], 'PIN_LOGIN_NOT_ALLOWED'), 'code e-mail : jamais contourné');
        $this->assertSame(409, $this->status('POST', '/api/pos/pin-login', ['userId' => $noPin->getId(), 'pin' => '1357'], 'PIN_NOT_SET'));

        $entries = $this->entriesSince($start);
        $logins = array_values(array_filter($entries, fn ($e) => in_array($e['type'], ['LOGIN', 'LOGIN_FAILED'], true)));
        $this->assertSame(['LOGIN_FAILED', 'LOGIN', 'LOGIN_FAILED', 'LOGIN_FAILED', 'LOGIN_FAILED'], array_column($logins, 'type'));
        $this->assertSame(['pin', 'pin'], [$logins[0]['data']['method'], $logins[1]['data']['method']]);
        $this->assertSame('Tablette PIN', $logins[1]['terminal']['label']);
        $this->assertSame('admin', $logins[2]['data']['reason']);
    }

    public function testSensitiveActionsNeedAManagerApproval(): void
    {
        $manager = $this->user(['ROLE_USER_POS', 'ROLE_POS_MANAGER'], 'Rémi', 'Responsable');
        $this->setPin($manager, '8642');
        $managerNoPin = $this->user(['ROLE_POS_MANAGER'], 'Sans', 'Pin');
        $cashier = $this->loginAs(['ROLE_USER_POS']);
        $this->assertSame(201, $this->request('POST', '/api/caisse/open', ['cashRegisterId' => $this->register, 'cashFund' => 2000])->getStatusCode());
        $quote = $this->json($this->request('POST', '/api/cart/quote', ['items' => [['productVariantId' => $this->variant, 'quantity' => 1]], 'cashRegisterId' => $this->register]));
        $sale = $this->body($this->request('POST', '/api/order/create-multi-payment', ['items' => [['productVariantId' => $this->variant, 'quantity' => 1]], 'paymentMethods' => [['type' => 2, 'amount' => $quote['cashTotal'] / 100]], 'cashRegisterId' => $this->register]));

        // Retrait supérieur au solde de la session (le poste peut hériter d'un solde reporté par d'autres tests)
        $big = (int) $this->db->fetchOne('SELECT amount_total FROM caisse WHERE cash_register_id = ? AND is_open', [$this->register]) + 50000;

        // Sans validation : refus avec l'action et le contexte attendus
        $required = $this->request('POST', '/api/caisse/withdraw', ['amount' => $big, 'cashRegisterId' => $this->register]);
        $this->assertSame(403, $required->getStatusCode());
        $this->assertSame(['APPROVAL_REQUIRED', 'withdrawal', ['amount' => $big, 'cashRegisterId' => $this->register]], [$this->body($required)['error'], $this->body($required)['action'], $this->body($required)['context']]);

        // Demandes refusées
        $ctx = ['amount' => $big, 'cashRegisterId' => $this->register];
        $this->assertSame(403, $this->status('POST', '/api/pos/approvals', ['action' => 'withdrawal', 'managerId' => $cashier->getId(), 'pin' => '8642', 'context' => $ctx], 'APPROVER_NOT_ALLOWED'));
        $noPin = $this->request('POST', '/api/pos/approvals', ['action' => 'withdrawal', 'managerId' => $managerNoPin->getId(), 'pin' => '8642', 'context' => $ctx]);
        $this->assertSame([409, 'PIN_NOT_SET', $managerNoPin->getId()], [$noPin->getStatusCode(), $this->body($noPin)['error'], $this->body($noPin)['managerId']]);
        $this->assertSame(400, $this->status('POST', '/api/pos/approvals', ['action' => 'withdrawal', 'managerId' => $manager->getId(), 'pin' => '0000', 'context' => $ctx], 'PIN_INVALID'));
        $this->assertSame(422, $this->status('POST', '/api/pos/approvals', ['action' => 'withdrawal', 'managerId' => $manager->getId(), 'pin' => '8642', 'context' => ['amount' => 50000]], 'VALIDATION_FAILED'));
        $terminal = $this->terminal;
        $this->terminal = null;
        $this->assertSame(403, $this->status('POST', '/api/pos/approvals', ['action' => 'withdrawal', 'managerId' => $manager->getId(), 'pin' => '8642', 'context' => $ctx], 'TERMINAL_REQUIRED'));
        $this->terminal = $terminal;

        // Jeton valable pour un retrait trop gros : l'action échoue, le jeton reste utilisable (consommé dans la transaction)
        $token = $this->grant($manager, 'withdrawal', $ctx);
        $this->approval = $token;
        $this->assertSame(400, $this->status('POST', '/api/caisse/withdraw', $ctx, 'INSUFFICIENT_FUNDS'));
        $this->approval = null;
        $this->loginAs(['ROLE_ADMIN', 'ROLE_USER_POS']); // un administrateur dépose de quoi couvrir le retrait
        $this->assertSame(201, $this->request('POST', '/api/caisse/deposit', ['amount' => $big, 'cashRegisterId' => $this->register])->getStatusCode());
        $this->token = $this->tokenFor($cashier);
        $this->approval = $token;
        $done = $this->request('POST', '/api/caisse/withdraw', $ctx);
        $this->assertSame(201, $done->getStatusCode(), 'même jeton, après l\'échec : ' . $done->getContent());
        $this->assertSame(403, $this->status('POST', '/api/caisse/withdraw', $ctx, 'APPROVAL_INVALID'), 'usage unique');

        // Autre contexte, autre terminal : refusé
        $this->approval = $this->grant($manager, 'withdrawal', ['amount' => 100, 'cashRegisterId' => $this->register]);
        $this->assertSame(403, $this->status('POST', '/api/caisse/withdraw', ['amount' => 200, 'cashRegisterId' => $this->register], 'APPROVAL_INVALID'));
        $other = $this->newTerminal('Tablette B');
        $this->approval = $this->grant($manager, 'withdrawal', ['amount' => 100, 'cashRegisterId' => $this->register], $other);
        $this->assertSame(403, $this->status('POST', '/api/caisse/withdraw', ['amount' => 100, 'cashRegisterId' => $this->register], 'APPROVAL_INVALID'), 'jeton d\'un autre terminal');

        // Retrait de fond, tiroir, remboursement, fermeture sans comptage : validés
        $start = $this->lastSequence();
        $this->approval = $this->grant($manager, 'fund_withdrawal', ['amount' => 500, 'cashRegisterId' => $this->register]);
        $this->assertSame(201, $this->request('POST', '/api/caisse/cashfundwithdraw', ['amount' => 500, 'cashRegisterId' => $this->register])->getStatusCode());
        $this->approval = null;
        $this->assertSame(403, $this->status('POST', '/api/caisse/drawer-open', ['cashRegisterId' => $this->register, 'reason' => 'Monnaie'], 'APPROVAL_REQUIRED'));
        $this->approval = $this->grant($manager, 'drawer_open', ['cashRegisterId' => $this->register]);
        $this->assertSame(201, $this->request('POST', '/api/caisse/drawer-open', ['cashRegisterId' => $this->register, 'reason' => 'Monnaie'])->getStatusCode());
        $this->approval = null;
        $this->assertSame(403, $this->status('POST', '/api/order/cancel/' . $sale['orderId'], ['paymentMethod' => 2], 'APPROVAL_REQUIRED'));
        $this->approval = $this->grant($manager, 'refund', ['orderId' => $sale['orderId']]);
        $refund = $this->request('POST', '/api/order/cancel/' . $sale['orderId'], ['paymentMethod' => 2]);
        $this->assertSame(200, $refund->getStatusCode(), $refund->getContent());
        $this->approval = null;
        $this->assertSame(403, $this->status('POST', '/api/caisse/close', ['cashRegisterId' => $this->register], 'APPROVAL_REQUIRED'));
        $this->assertSame(200, $this->request('POST', '/api/caisse/close', ['cashRegisterId' => $this->register, 'countedCash' => 0, 'discrepancyReason' => 'Test'])->getStatusCode(), 'fermeture avec comptage : pas de validation');
        $this->request('POST', '/api/caisse/open', ['cashRegisterId' => $this->register]);
        $this->approval = $this->grant($manager, 'close_without_count', ['cashRegisterId' => $this->register]);
        $this->assertSame(200, $this->request('POST', '/api/caisse/close', ['cashRegisterId' => $this->register])->getStatusCode());
        $this->approval = null;

        $entries = $this->entriesSince($start);
        $approved = array_values(array_filter($entries, fn ($e) => in_array($e['type'], ['FUND_WITHDRAWAL', 'DRAWER_OPENED', 'REFUND'], true)));
        $this->assertCount(3, $approved);
        foreach ($approved as $entry) {
            $this->assertSame(['id' => $manager->getId(), 'name' => 'Rémi Responsable'], $entry['data']['approvedBy'] ?? null, $entry['type'] . ' porte approvedBy');
        }
        $this->assertContains('APPROVAL_GRANTED', array_column($entries, 'type'));
        $closures = array_values(array_filter($entries, fn ($e) => $e['type'] === 'SESSION_CLOSED'));
        $this->assertArrayNotHasKey('approvedBy', $closures[0]['data'], 'fermeture avec comptage : sans validation');
        $this->assertSame($manager->getId(), $closures[1]['data']['approvedBy']['id']);

        // Seuils et réglage « jamais » ; responsable qui agit lui-même
        $this->request('POST', '/api/caisse/open', ['cashRegisterId' => $this->register, 'cashFund' => 5000]);
        $this->db->executeStatement('UPDATE entreprise SET pos_approval_withdrawal = 1000, pos_approval_drawer_open = false');
        $this->assertSame(201, $this->request('POST', '/api/caisse/withdraw', ['amount' => 500, 'cashRegisterId' => $this->register])->getStatusCode(), 'sous le seuil');
        $this->assertSame(403, $this->status('POST', '/api/caisse/withdraw', ['amount' => 1500, 'cashRegisterId' => $this->register], 'APPROVAL_REQUIRED'), 'au-delà du seuil');
        $this->assertSame(201, $this->request('POST', '/api/caisse/drawer-open', ['cashRegisterId' => $this->register, 'reason' => 'Réglage'])->getStatusCode(), 'validation désactivée');
        $this->db->executeStatement('UPDATE entreprise SET pos_approval_withdrawal = 0');
        $this->token = $this->tokenFor($manager);
        $this->assertSame(201, $this->request('POST', '/api/caisse/withdraw', ['amount' => 500, 'cashRegisterId' => $this->register])->getStatusCode(), 'responsable : pas de validation');
    }

    public function testSettingsWebCancellationAndEasyAdminReset(): void
    {
        $cashier = $this->loginAs(['ROLE_USER_POS']);
        $settings = $this->json($this->request('GET', '/api/pos/settings'));
        $this->assertSame(5, $settings['lockAfterMinutes']);
        $this->assertSame(['required' => true, 'threshold' => 0], $settings['approvals']['refund']);
        $this->assertSame(['required' => false, 'threshold' => null], $settings['approvals']['discount']);
        $this->assertSame(['minLength' => 4, 'maxLength' => 6, 'maxAttempts' => 5, 'lockMinutes' => 15], $settings['pin']);
        $this->db->executeStatement('UPDATE entreprise SET pos_lock_minutes = 0, pos_approval_refund = NULL');
        $settings = $this->json($this->request('GET', '/api/pos/settings'));
        $this->assertSame([0, ['required' => false, 'threshold' => null]], [$settings['lockAfterMinutes'], $settings['approvals']['refund']], '0 = jamais de verrouillage ; vide = jamais de validation');
        $this->db->executeStatement('UPDATE entreprise SET pos_lock_minutes = 5, pos_approval_refund = 0');

        // Annulation d'une commande web par son client : aucune validation
        $customer = $this->loginAs(['ROLE_USER_INTERNET'], 'mobile');
        $order = ['addressId' => $this->address($customer), 'carrierId' => 6, 'items' => [['productVariantId' => $this->variant, 'quantity' => 1]]];
        FakeCheckoutPaymentGateway::reset();
        $intent = $this->json($this->request('POST', '/api/stripe/create-intent', ['items' => $order['items'], 'carrierId' => 6, 'order' => $order]));
        FakeCheckoutPaymentGateway::confirm($intent['paymentIntentId']);
        $webOrder = $this->json($this->request('POST', '/api/order/create', $order + ['paymentIntentId' => $intent['paymentIntentId']]), 201)['orderId'];
        $cancel = $this->request('POST', "/api/order/cancel/$webOrder", ['paymentMethod' => 1]);
        $this->assertSame(200, $cancel->getStatusCode(), 'commande web : inchangé ' . $cancel->getContent());
        $this->assertSame(['message' => 'Order canceled and refunded successfully'], $this->json($cancel));

        // EasyAdmin : « Réinitialiser le PIN de caisse » (lien signé CSRF depuis la fiche)
        $this->setPin($cashier, '4826');
        $this->db->executeStatement('UPDATE pos_user_pin SET failed_attempts = 3 WHERE user_id = ?', [$cashier->getId()]);
        $admin = $this->user(['ROLE_ADMIN']);
        $this->client->loginUser($admin, 'main');
        $crawler = $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin?' . http_build_query(['crudAction' => 'detail', 'crudControllerFqcn' => \App\Controller\Admin\UserCrudController::class, 'entityId' => $cashier->getId()]), [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $link = $crawler->filter('a[href*="resetPosPin"]');
        $this->assertCount(1, $link, 'action présente sur la fiche d\'un caissier');
        $start = $this->lastSequence();
        $this->client->request('GET', $link->attr('href'), [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $this->assertTrue($this->client->getResponse()->isRedirect());
        $this->assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM pos_user_pin WHERE user_id = ?', [$cashier->getId()]), 'PIN effacé');
        $this->assertSame(['PIN_RESET'], array_column($this->entriesSince($start), 'type'));
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin?' . http_build_query(['crudAction' => 'resetPosPin', 'crudControllerFqcn' => \App\Controller\Admin\UserCrudController::class, 'entityId' => $cashier->getId(), '_csrf' => 'faux']), [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $this->assertSame(['PIN_RESET'], array_column($this->entriesSince($start), 'type'), 'jeton CSRF faux : rien');
    }

    private function grant(User $manager, string $action, array $context, ?string $terminal = null): string
    {
        $previous = $this->terminal;
        $this->terminal = $terminal ?? $this->terminal;
        $granted = $this->request('POST', '/api/pos/approvals', ['action' => $action, 'managerId' => $manager->getId(), 'pin' => '8642', 'context' => $context]);
        $this->terminal = $previous;
        $this->assertSame(201, $granted->getStatusCode(), $granted->getContent());
        $body = $this->body($granted);
        $this->assertGreaterThan(time() + 60, strtotime($body['expiresAt']));

        return $body['approvalToken'];
    }

    private function newTerminal(string $label): string
    {
        $token = $this->token;
        $admin = $this->user(['ROLE_ADMIN', 'ROLE_USER_POS']);
        $this->token = $this->tokenFor($admin);
        $key = $this->json($this->request('POST', '/api/app-keys', ['app' => 'pos', 'label' => $label, 'sendEmail' => false]), 201)['key'];
        $this->token = $token;
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . '/api/app/activate', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['key' => $key, 'app' => 'pos']));

        return json_decode($this->client->getResponse()->getContent(), true)['terminal']['token'];
    }

    private function setPin(User $user, string $pin): void
    {
        static::getContainer()->get(PosPinService::class)->set($user, $pin);
    }

    private function status(string $method, string $path, ?array $body, string $error): int
    {
        $response = $this->request($method, $path, $body);
        $this->assertSame($error, json_decode($response->getContent(), true)['error'] ?? null, $response->getContent());

        return $response->getStatusCode();
    }

    private function lastSequence(): int
    {
        return (int) ($this->db->fetchOne('SELECT MAX(sequence) FROM pos_audit_log') ?: 0);
    }

    private function entriesSince(int $sequence): array
    {
        return array_map(fn ($payload) => json_decode($payload, true), $this->db->fetchFirstColumn('SELECT payload FROM pos_audit_log WHERE sequence > ? ORDER BY sequence', [$sequence]));
    }

    private function address(User $user): int
    {
        $em = static::getContainer()->get(TenantEntityManagerProvider::class)->getEntityManager();
        $address = (new Adress())->setFirstname('Client')->setLastname('Web')->setFullname('Client Web')->setAddress('1 rue du Test')->setCity('Montréal')
            ->setCodepostal('H1A 1A1')->setCountry('CA')->setPhone('+1 514 555 0000')->setProvince('QC')->setUserAdress($em->find(User::class, $user->getId()));
        $em->persist($address);
        $em->flush();

        return (int) $address->getId();
    }

    /** Corps JSON sans contrôle du statut (réponses d'erreur, 201) */
    private function body(Response $response): array
    {
        return json_decode($response->getContent(), true) ?? [];
    }

    private function json(Response $response, int $status = 200): array
    {
        $this->assertSame($status, $response->getStatusCode(), $response->getContent());

        return json_decode($response->getContent(), true);
    }

    private function request(string $method, string $path, ?array $body = null): Response
    {
        $this->client->getCookieJar()->clear();
        $this->client->request($method, 'https://' . MV_TEST_TENANT_HOST . $path, [], [], array_filter([
            'HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => $this->token ? 'Bearer ' . $this->token : null,
            'HTTP_X_POS_TERMINAL' => $this->terminal,
            'HTTP_X_POS_APPROVAL' => $this->approval,
        ]), $body !== null ? json_encode($body) : null);

        return $this->client->getResponse();
    }

    private function tokenFor(User $user, string $platform = 'pos'): string
    {
        $saved = $this->token;
        $this->token = null;
        $login = $this->request('POST', '/api/login', ['username' => $user->getEmail(), 'password' => self::PASSWORD, 'platform' => $platform]);
        $this->assertSame(200, $login->getStatusCode(), $login->getContent());
        $this->token = $saved;

        return json_decode($login->getContent(), true)['token'];
    }

    private function loginAs(array $roles, string $platform = 'pos'): User
    {
        $user = $this->user($roles);
        $this->token = $this->tokenFor($user, $platform);

        return $user;
    }

    private function user(array $roles, string $first = 'Pin', string $last = 'Test'): User
    {
        $email = 'pin-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $container = static::getContainer();
        $provider = $container->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname($first)->setLastname($last)->setRoles($roles)->setIsVerified(true);
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD));
        $provider->getEntityManager()->persist($user);
        $provider->getEntityManager()->flush();

        return $user;
    }
}
