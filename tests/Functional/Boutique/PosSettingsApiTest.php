<?php

namespace App\Tests\Functional\Boutique;

use App\Entity\CashRegister;
use App\Entity\User;
use App\Services\AppKeyService\AppKeyService;
use App\Services\BoutiqueDemoService\BoutiqueDemoSeeder;
use App\Services\PosAuditService\PosPinService;
use App\Services\PosTerminalService\PosTerminalPaymentService;
use App\Services\StripeService\StripeService;
use App\Services\TenantEntityManagerProvider;
use App\Tests\Fake\FakeTerminalGateway;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Lot R1 (23/10/2026) : réglages de caisse par site (fiche entreprise), lus par GET /api/pos/settings. Défauts =
 * comportement d'avant ; PIN, moyens de paiement refusés, ticket (préfixe, en-tête, numéros de taxes), alerte espèces,
 * commission en ligne (troncature identique), terminal révoqué ou exigé.
 */
class PosSettingsApiTest extends WebTestCase
{
    private const PASSWORD = 'Mot-de-passe-de-test-1!';
    private KernelBrowser $client;
    private \Doctrine\DBAL\Connection $db;
    private ?string $token = null;
    private ?string $terminal = null;
    private int $register;
    private int $variant;
    private int $total = 3334;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        FakeTerminalGateway::reset();
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $this->db = $provider->getEntityManager()->getConnection();
        static::getContainer()->get(BoutiqueDemoSeeder::class)->seed('client-reglages@example.invalid', false, false);
        $this->db->executeStatement('DELETE FROM boutique_setting');
        $this->db->executeStatement("UPDATE address_entreprise SET country = 'CA', state = 'QC', city = 'Montréal', zip = 'H1M 0A9'");
        $this->db->executeStatement("UPDATE entreprise SET currency = 'CAD', pos_discrepancy_threshold = 0, pos_cash_rounding_step = NULL, pos_approval_refund = NULL, pos_approval_withdrawal = NULL, pos_approval_fund_withdrawal = NULL, pos_approval_drawer_open = false, pos_approval_close_without_count = false,
            pos_pin_min_length = 4, pos_pin_max_length = 6, pos_pin_max_attempts = 5, pos_pin_lock_minutes = 15, pos_opening_fund_suggested = NULL, pos_closing_kept_fund_suggested = NULL,
            pos_pay_cash = true, pos_pay_declared_card = true, pos_pay_paypal = true, pos_pay_multi = true, pos_declared_card_blocked_with_terminal = false,
            pos_receipt_header = NULL, pos_receipt_footer = NULL, pos_receipt_show_tax_numbers = true, gst_number = NULL, qst_number = NULL, pos_held_carts_max = 10, pos_held_carts_alert_hours = 24,
            pos_cash_alert_threshold = NULL, pos_terminal_header_required = false, tva_intracommunautaire = 'FR123', ein = NULL");
        $this->db->executeStatement('UPDATE stripe_config SET online_fee_bp = 100');
        $this->db->executeStatement('UPDATE caisse SET is_open = false, closed_at = NOW() WHERE is_open = true');
        $this->db->executeStatement('UPDATE cash_register SET is_active = (id = (SELECT MIN(id) FROM cash_register)), terminal_enabled = false, stripe_reader_id = NULL, receipt_prefix = NULL');
        $this->register = (int) $this->db->fetchOne('SELECT MIN(id) FROM cash_register');
        $this->variant = (int) $this->db->fetchOne("SELECT v.id FROM product_variant v JOIN product p ON p.id = v.product_id WHERE p.code = 'DEMO-CASQUETTE' ORDER BY v.id LIMIT 1");
        $this->db->executeStatement("UPDATE product SET price = 2900 WHERE code = 'DEMO-CASQUETTE'");
        $this->db->executeStatement('UPDATE product_variant SET stock_quantity = 500, price = NULL WHERE id = ?', [$this->variant]);
    }

    public function testDefaultSettingsMatchPreviousBehaviourAndEverythingIsConfigurable(): void
    {
        $this->loginAs(['ROLE_USER_POS']);
        $s = $this->json($this->request('GET', '/api/pos/settings'));
        $this->assertSame(['minLength' => 4, 'maxLength' => 6, 'maxAttempts' => 5, 'lockMinutes' => 15], $s['pin'], 'bloc pin inchangé');
        $this->assertSame(['openingSuggested' => null, 'closingKeptSuggested' => null], $s['cashFund']);
        $this->assertSame(['cash' => true, 'declaredCard' => true, 'paypal' => true, 'multiPayment' => true, 'declaredCardBlockedWithTerminal' => false], $s['paymentMethods']);
        $this->assertSame(['header' => null, 'footer' => null, 'showTaxNumbers' => true, 'emailEnabled' => true, 'qrEnabled' => true, 'linkDays' => 90, 'qrPrintDefault' => true], $s['receipt']); // ajouts F3
        $this->assertSame(['max' => 10, 'alertHours' => 24], $s['heldCarts']);
        $this->assertNull($s['cashAlertThreshold']);
        $this->assertFalse($s['terminalRequired']);
        $this->assertArrayHasKey('approvals', $s);
        $this->assertArrayHasKey('lockAfterMinutes', $s);
        $this->assertArrayHasKey('terminal', $s);

        $this->db->executeStatement("UPDATE entreprise SET pos_pin_min_length = 6, pos_pin_max_length = 8, pos_pin_max_attempts = 3, pos_pin_lock_minutes = 30, pos_opening_fund_suggested = 10000, pos_closing_kept_fund_suggested = 5000,
            pos_pay_paypal = false, pos_receipt_header = E'Boutique Démo\\n<b>Rue</b> 1', pos_receipt_footer = 'Merci !', pos_held_carts_max = 20, pos_held_carts_alert_hours = 48, pos_cash_alert_threshold = 50000, gst_number = '123456789 RT0001', qst_number = '1234567890 TQ0001'");
        $s = $this->json($this->request('GET', '/api/pos/settings'));
        $this->assertSame(['minLength' => 6, 'maxLength' => 8, 'maxAttempts' => 3, 'lockMinutes' => 30], $s['pin']);
        $this->assertSame(['openingSuggested' => 10000, 'closingKeptSuggested' => 5000], $s['cashFund']);
        $this->assertFalse($s['paymentMethods']['paypal']);
        $this->assertSame(['max' => 20, 'alertHours' => 48], $s['heldCarts']);
        $this->assertSame(50000, $s['cashAlertThreshold']);
        $this->assertSame("Boutique Démo\n<b>Rue</b> 1", $s['receipt']['header'], 'lecture brute : le nettoyage HTML se fait à la saisie EasyAdmin (setter)');

        // Nettoyage du texte à la saisie : balises retirées, retours à la ligne conservés, 500 caractères
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $e = $provider->getEntityManager()->getRepository(\App\Entity\Entreprise::class)->findOneBy([]);
        $e->setPosReceiptHeader("Boutique <b>Démo</b>\r\n<script>x</script>Rue 1  ")->setPosReceiptFooter(str_repeat('a', 600))->setPosPinMinLength(2)->setPosPinMaxAttempts(50);
        $provider->getEntityManager()->flush();
        $this->assertSame("Boutique Démo\nxRue 1", $e->getPosReceiptHeader());
        $this->assertSame(500, mb_strlen($e->getPosReceiptFooter()));
        $this->assertSame([4, 10], [$e->getPosPinMinLength(), $e->getPosPinMaxAttempts()], 'garde-fous');
    }

    public function testPinRulesComeFromTheSite(): void
    {
        $this->db->executeStatement('UPDATE entreprise SET pos_pin_min_length = 6, pos_pin_max_length = 8, pos_pin_max_attempts = 3, pos_pin_lock_minutes = 2');
        $this->loginAs(['ROLE_USER_POS', 'ROLE_POS_MANAGER']);
        $terminal = $this->terminal();
        $short = $this->request('POST', '/api/pos/pin', ['newPin' => '1234', 'password' => self::PASSWORD], $terminal);
        $this->assertSame([422, 'newPin : 6 à 8 chiffres attendus (chaîne)'], [$short->getStatusCode(), json_decode($short->getContent(), true)['message']], $short->getContent());
        $set = $this->request('POST', '/api/pos/pin', ['newPin' => '123456', 'password' => self::PASSWORD], $terminal);
        $this->assertContains($set->getStatusCode(), [200, 201, 204], $set->getContent());
        for ($i = 1; $i <= 2; $i++) {
            $bad = $this->request('POST', '/api/pos/unlock', ['pin' => '000000'], $terminal);
            $this->assertSame([400, 3 - $i], [$bad->getStatusCode(), json_decode($bad->getContent(), true)['attemptsLeft']], $bad->getContent());
        }
        $locked = $this->request('POST', '/api/pos/unlock', ['pin' => '000000'], $terminal);
        $body = json_decode($locked->getContent(), true);
        $this->assertSame([423, 'PIN_LOCKED'], [$locked->getStatusCode(), $body['error']]);
        $this->assertLessThanOrEqual(120, $body['retryAfter'], 'blocage de 2 minutes');
        $this->assertStringContainsString('après 3 essais', $body['message']);
    }

    public function testDisabledPaymentMethodsAreRefusedOnlyForCounterSales(): void
    {
        $this->loginAs(['ROLE_USER_POS']);
        $this->request('POST', '/api/caisse/open', ['cashRegisterId' => $this->register]);
        $items = [['productVariantId' => $this->variant, 'quantity' => 1]];
        $cash = fn () => $this->request('POST', '/api/order/create-multi-payment', ['items' => $items, 'paymentMethods' => [['type' => 2, 'amount' => $this->total / 100]], 'cashRegisterId' => $this->register]);
        $this->assertSame(201, $cash()->getStatusCode(), 'tout actif par défaut');
        $orders = (int) $this->db->fetchOne('SELECT COUNT(*) FROM "order"');
        $this->db->executeStatement('UPDATE entreprise SET pos_pay_cash = false, pos_pay_multi = false');
        $refused = $cash();
        $this->assertSame([422, 'PAYMENT_METHOD_DISABLED', 2], [$refused->getStatusCode(), json_decode($refused->getContent(), true)['error'], json_decode($refused->getContent(), true)['type']]);
        $multi = $this->request('POST', '/api/order/create-multi-payment', ['items' => $items, 'paymentMethods' => [['type' => 1, 'amount' => 20], ['type' => 3, 'amount' => 13.34]], 'cashRegisterId' => $this->register]);
        $this->assertSame('MULTI_PAYMENT_DISABLED', json_decode($multi->getContent(), true)['error']);
        $this->assertSame(201, $this->request('POST', '/api/order/create-multi-payment', ['items' => $items, 'paymentMethods' => [['type' => 1, 'amount' => $this->total / 100]], 'cashRegisterId' => $this->register])->getStatusCode(), 'carte déclarée seule : acceptée');
        $this->db->executeStatement('UPDATE entreprise SET pos_pay_cash = true, pos_pay_multi = true, pos_declared_card_blocked_with_terminal = true');
        $this->assertSame(201, $this->request('POST', '/api/order/create-multi-payment', ['items' => $items, 'paymentMethods' => [['type' => 1, 'amount' => $this->total / 100]], 'cashRegisterId' => $this->register])->getStatusCode(), 'pas de terminal sur le poste : carte déclarée acceptée');
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        static::getContainer()->get(PosTerminalPaymentService::class)->createSimulatedReader($provider->getEntityManager()->find(CashRegister::class, $this->register));
        $blocked = $this->request('POST', '/api/order/create-multi-payment', ['items' => $items, 'paymentMethods' => [['type' => 1, 'amount' => $this->total / 100]], 'cashRegisterId' => $this->register]);
        $this->assertSame([422, 'DECLARED_CARD_BLOCKED', $this->register], [$blocked->getStatusCode(), json_decode($blocked->getContent(), true)['error'], json_decode($blocked->getContent(), true)['cashRegisterId']]);
        $this->assertSame($orders + 2, (int) $this->db->fetchOne('SELECT COUNT(*) FROM "order"'), 'deux ventes acceptées, rien créé par les trois refus');
    }

    public function testReceiptPrefixTaxNumbersTextsAndCashAlert(): void
    {
        $this->db->executeStatement("UPDATE cash_register SET receipt_prefix = 'C1-' WHERE id = ?", [$this->register]);
        $this->db->executeStatement("UPDATE entreprise SET gst_number = '123456789 RT0001', qst_number = '1234567890 TQ0001', pos_receipt_header = 'En-tête', pos_receipt_footer = 'Merci'");
        $this->loginAs(['ROLE_USER_POS', 'ROLE_POS_MANAGER']);
        $this->request('POST', '/api/caisse/open', ['cashRegisterId' => $this->register, 'cashFund' => 90000]);
        $expected = (int) $this->json($this->request('GET', "/api/caisse/report/x?cashRegisterId=$this->register"))['expectedCash'];
        $this->db->executeStatement('UPDATE entreprise SET pos_cash_alert_threshold = ?', [$expected + 3334 + 5000]); // seuil : espèces attendues après la vente + 50,00
        $items = [['productVariantId' => $this->variant, 'quantity' => 1]];
        $sale = $this->json($this->request('POST', '/api/order/create-multi-payment', ['items' => $items, 'paymentMethods' => [['type' => 2, 'amount' => $this->total / 100]], 'cashRegisterId' => $this->register]), 201);
        $number = $sale['receiptNumber'];
        $this->assertSame("C1-$number", $sale['receiptReference']);
        $this->assertSame(['gst' => '123456789 RT0001', 'qst' => '1234567890 TQ0001'], $sale['store']['taxNumbers']);
        $this->assertSame(['FR123', true, 'En-tête', 'Merci'], [$sale['store']['taxNumber'], $sale['store']['showTaxNumbers'], $sale['store']['receiptHeader'], $sale['store']['receiptFooter']]);
        $this->assertSame(1 + (int) $this->db->fetchOne("SELECT COUNT(*) FROM pos_receipt WHERE kind = 'ticket' AND cash_register_id = ? AND number < ?", [$this->register, $number]), $number, 'suite numérique continue, préfixe en présentation seulement');
        $x = $this->json($this->request('GET', "/api/caisse/report/x?cashRegisterId=$this->register"));
        $this->assertSame(['C1-', false], [$x['receiptPrefix'], $x['cashAlert']], 'sous le seuil');
        $this->request('POST', '/api/caisse/deposit', ['cashRegisterId' => $this->register, 'amount' => 10000]);
        $this->assertTrue($this->json($this->request('GET', "/api/caisse/report/x?cashRegisterId=$this->register"))['cashAlert'], 'au-delà du seuil');
        $cancel = $this->json($this->request('POST', '/api/order/cancel/' . $sale['orderId'], ['paymentMethod' => 2]));
        $this->assertSame('C1-' . $cancel['creditNoteNumber'], $cancel['creditNoteReference']);
        // Numéros de taxes masqués
        $this->db->executeStatement('UPDATE entreprise SET pos_receipt_show_tax_numbers = false, pos_cash_alert_threshold = NULL');
        $sale2 = $this->json($this->request('POST', '/api/order/create-multi-payment', ['items' => $items, 'paymentMethods' => [['type' => 2, 'amount' => $this->total / 100]], 'cashRegisterId' => $this->register]), 201);
        $this->assertSame([null, null, false], [$sale2['store']['taxNumber'], $sale2['store']['taxNumbers'], $sale2['store']['showTaxNumbers']]);
        $this->assertSame(['FR123'], [$sale['store']['taxNumber']]);
        $this->assertNull($this->json($this->request('GET', "/api/caisse/report/x?cashRegisterId=$this->register"))['cashAlert'], 'sans seuil : null');
    }

    public function testOnlineFeeIsConfigurablePerSiteWithTheSameTruncation(): void
    {
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $stripe = static::getContainer()->get(StripeService::class);
        $this->assertSame((int) ((3334 * 1) / 100), $stripe->onlineFee(3334), 'défaut : exactement l\'ancien calcul');
        $this->assertSame([33, 0, 1, 9], [$stripe->onlineFee(3334), $stripe->onlineFee(99), $stripe->onlineFee(199), $stripe->onlineFee(999)], 'troncature, pas d\'arrondi');
        $this->db->executeStatement('UPDATE stripe_config SET online_fee_bp = 250');
        $provider->getEntityManager()->clear();
        $this->assertSame((int) (3334 * 250 / 10000), $stripe->onlineFee(3334));
        $this->assertSame(83, $stripe->onlineFee(3334), '2,5 %');
        $this->db->executeStatement('UPDATE stripe_config SET online_fee_bp = 0');
        $provider->getEntityManager()->clear();
        $this->assertSame(0, $stripe->onlineFee(3334));
        $this->db->executeStatement('UPDATE stripe_config SET online_fee_bp = 100');
    }

    public function testRevokedTerminalIsRefusedEverywhereAndTerminalCanBeRequired(): void
    {
        $this->loginAs(['ROLE_USER_POS', 'ROLE_POS_MANAGER']);
        $terminal = $this->terminal();
        $this->request('POST', '/api/caisse/open', ['cashRegisterId' => $this->register], $terminal);
        $this->assertSame(200, $this->request('GET', "/api/caisse?cashRegisterId=$this->register", null, $terminal)->getStatusCode());
        $keyId = (int) explode('.', $terminal)[0];
        $keys = static::getContainer()->get(\App\Repository\TenantAppKeyRepository::class);
        $this->assertNotNull($keys->findOneForTenant($keyId, MV_TEST_TENANT_CODE)['last_used_at'], 'dernière activité renseignée');
        $keys->revoke($keyId, MV_TEST_TENANT_CODE);
        foreach (["/api/caisse?cashRegisterId=$this->register", '/api/pos/settings', '/api/cart/quote'] as $path) {
            $r = $path === '/api/cart/quote' ? $this->request('POST', $path, ['items' => [['productVariantId' => $this->variant, 'quantity' => 1]]], $terminal) : $this->request('GET', $path, null, $terminal);
            $this->assertSame([403, 'TERMINAL_REVOKED', $keyId], [$r->getStatusCode(), json_decode($r->getContent(), true)['error'], json_decode($r->getContent(), true)['terminalId']], $path);
        }
        $this->assertSame(200, $this->request('GET', '/api/pos/settings')->getStatusCode(), 'sans en-tête : rien ne change');
        // Réactivation avec la clé révoquée : refus explicite
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . '/api/app/activate', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['key' => $this->lastKey, 'app' => 'pos']));
        $this->assertSame([403, 'APP_KEY_REVOKED'], [$this->client->getResponse()->getStatusCode(), json_decode($this->client->getResponse()->getContent(), true)['error']]);
        // Terminal exigé sur les routes de caisse (désactivé par défaut)
        $this->db->executeStatement('UPDATE entreprise SET pos_terminal_header_required = true');
        $required = $this->request('GET', "/api/caisse?cashRegisterId=$this->register");
        $this->assertSame([403, 'TERMINAL_REQUIRED'], [$required->getStatusCode(), json_decode($required->getContent(), true)['error']]);
        $this->assertSame(403, $this->request('POST', '/api/order/create-multi-payment', ['items' => [['productVariantId' => $this->variant, 'quantity' => 1]], 'paymentMethods' => [['type' => 2, 'amount' => $this->total / 100]], 'cashRegisterId' => $this->register])->getStatusCode());
        $this->assertSame(200, $this->request('POST', '/api/cart/quote', ['items' => [['productVariantId' => $this->variant, 'quantity' => 1]]])->getStatusCode(), 'boutique : jamais concernée');
        $this->assertSame(200, $this->request('GET', '/api/category')->getStatusCode(), 'autres applications et boutique : jamais concernées');
        $fresh = $this->terminal();
        $this->assertSame(200, $this->request('GET', "/api/caisse?cashRegisterId=$this->register", null, $fresh)->getStatusCode(), 'avec un terminal valide : ok');
        $this->db->executeStatement('UPDATE entreprise SET pos_terminal_header_required = false'); // base partagée par les autres tests
    }

    // ---------------------------------------------------------------- aides

    private string $lastKey = '';

    private function terminal(): string
    {
        $saved = $this->token;
        $this->loginAs(['ROLE_ADMIN', 'ROLE_USER_POS']);
        $this->lastKey = $this->json($this->request('POST', '/api/app-keys', ['app' => 'pos', 'label' => 'Tablette réglages', 'sendEmail' => false]), 201)['key'];
        $this->token = $saved;
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . '/api/app/activate', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['key' => $this->lastKey, 'app' => 'pos']));

        return json_decode($this->client->getResponse()->getContent(), true)['terminal']['token'];
    }

    private function json(Response $response, int $expected = 200): array
    {
        $this->assertSame($expected, $response->getStatusCode(), $response->getContent());

        return json_decode($response->getContent(), true);
    }

    private function request(string $method, string $path, ?array $body = null, ?string $terminal = null): Response
    {
        $this->client->getCookieJar()->clear();
        $this->client->request($method, 'https://' . MV_TEST_TENANT_HOST . $path, [], [], array_filter([
            'HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => $this->token ? 'Bearer ' . $this->token : null,
            'HTTP_X_POS_TERMINAL' => $terminal,
        ]), $body !== null ? json_encode($body) : null);

        return $this->client->getResponse();
    }

    private function loginAs(array $roles): User
    {
        $email = 'reglages-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $container = static::getContainer();
        $provider = $container->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Regl')->setLastname('Test')->setRoles($roles)->setIsVerified(true);
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD));
        $provider->getEntityManager()->persist($user);
        $provider->getEntityManager()->flush();
        $this->token = null;
        $login = $this->request('POST', '/api/login', ['username' => $email, 'password' => self::PASSWORD, 'platform' => 'pos']);
        $this->assertSame(200, $login->getStatusCode(), $login->getContent());
        $this->token = json_decode($login->getContent(), true)['token'];

        return $user;
    }
}
