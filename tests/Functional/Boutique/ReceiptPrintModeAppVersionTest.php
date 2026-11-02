<?php

namespace App\Tests\Functional\Boutique;

use App\Controller\Admin\EntrepriseCrudController;
use App\Entity\User;
use App\Services\BoutiqueDemoService\BoutiqueDemoSeeder;
use App\Services\TenantEntityManagerProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Lot France (02/11/2026) : settings.receipt.printMode (auto | on_demand ; défaut selon le pays du magasin : loi AGEC en France et dans les DROM ; PM, BL, MF restent auto),
 * champ EasyAdmin ; en-tête X-App-Version gardé dans le journal (data.appVersion des entrées LOGIN et SALE).
 */
class ReceiptPrintModeAppVersionTest extends WebTestCase
{
    private const PASSWORD = 'Mot-de-passe-de-test-1!';
    private KernelBrowser $client;
    private \Doctrine\DBAL\Connection $db;
    private ?string $token = null;
    private ?string $appVersion = null;
    private int $register;
    private int $variant;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $this->db = $provider->getEntityManager()->getConnection();
        static::getContainer()->get(BoutiqueDemoSeeder::class)->seed('client-agec@example.invalid', false, false);
        $this->db->executeStatement("UPDATE address_entreprise SET country = 'CA', state = 'QC', city = 'Montréal', zip = 'H1M 0A9'");
        $this->db->executeStatement("UPDATE entreprise SET currency = 'CAD', prices_include_tax = false, pos_receipt_print_mode = NULL, pos_cash_rounding_step = NULL, pos_terminal_header_required = false, pos_approval_drawer_open = false, pos_approval_close_without_count = false");
        $this->db->executeStatement('UPDATE caisse SET is_open = false, closed_at = NOW() WHERE is_open = true');
        $this->db->executeStatement('UPDATE cash_register SET is_active = (id = (SELECT MIN(id) FROM cash_register)), terminal_enabled = false');
        $this->register = (int) $this->db->fetchOne('SELECT MIN(id) FROM cash_register');
        $this->variant = (int) $this->db->fetchOne("SELECT v.id FROM product_variant v JOIN product p ON p.id = v.product_id WHERE p.code = 'DEMO-CASQUETTE' ORDER BY v.id LIMIT 1");
        $this->db->executeStatement('UPDATE product_variant SET stock_quantity = 500, price = NULL WHERE id = ?', [$this->variant]);
        $this->db->executeStatement("UPDATE product SET price = 2900 WHERE code = 'DEMO-CASQUETTE'");
    }

    public function testPrintModeDefaultsByStoreCountryAndCanBeSet(): void
    {
        $this->loginAs(['ROLE_USER_POS']);
        $this->assertSame('auto', $this->settings()['receipt']['printMode'], 'Québec : automatique (rien ne change)');
        foreach (['FR', 'MQ', 'GP', 'GF', 'RE', 'YT'] as $country) {
            $this->db->executeStatement('UPDATE address_entreprise SET country = ?, state = ?', [$country, '']);
            $this->assertSame('on_demand', $this->settings()['receipt']['printMode'], "$country : sur demande (loi AGEC)");
        }
        $this->db->executeStatement("UPDATE address_entreprise SET country = 'US', state = 'NY'");
        $this->assertSame('auto', $this->settings()['receipt']['printMode']);
        $this->db->executeStatement("UPDATE address_entreprise SET country = 'FR', state = ''");
        $this->db->executeStatement("UPDATE entreprise SET pos_receipt_print_mode = 'auto'");
        $this->assertSame('auto', $this->settings()['receipt']['printMode'], 'réglage du site : prime sur le pays');
        $this->db->executeStatement("UPDATE entreprise SET pos_receipt_print_mode = 'on_demand'");
        $this->db->executeStatement("UPDATE address_entreprise SET country = 'CA', state = 'QC'");
        $this->assertSame('on_demand', $this->settings()['receipt']['printMode']);
        $pack = $this->json($this->request('GET', '/api/pos/offline-pack?cashRegisterId=' . $this->register));
        $this->assertSame('on_demand', $pack['settings']['receipt']['printMode'], 'paquet hors ligne : même réglage');
        $this->db->executeStatement('UPDATE entreprise SET pos_receipt_print_mode = NULL');
        // EasyAdmin : champ présent avec ses trois choix
        $this->client->loginUser($this->user(['ROLE_ADMIN']), 'main');
        $id = (int) $this->db->fetchOne('SELECT MIN(id) FROM entreprise');
        $crawler = $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin?' . http_build_query(['crudAction' => 'edit', 'crudControllerFqcn' => EntrepriseCrudController::class, 'entityId' => $id]), [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['', 'auto', 'on_demand'], $crawler->filter('select[name="Entreprise[posReceiptPrintMode]"] option')->each(fn ($o) => $o->attr('value')));
        $this->assertStringContainsString('loi AGEC', $this->client->getResponse()->getContent());
    }

    public function testAppVersionHeaderIsKeptInLoginAndSaleJournalEntries(): void
    {
        $before = (int) $this->db->fetchOne('SELECT COALESCE(MAX(id), 0) FROM pos_audit_log');
        $this->appVersion = '1.4.0+12';
        $this->loginAs(['ROLE_USER_POS']);
        $login = $this->db->fetchOne("SELECT payload FROM pos_audit_log WHERE id > ? AND type = 'LOGIN' ORDER BY id DESC LIMIT 1", [$before]);
        $this->assertNotFalse($login, 'entrée LOGIN de la connexion');
        $this->assertSame('1.4.0+12', json_decode($login, true)['data']['appVersion'] ?? null, 'LOGIN : data.appVersion = X-App-Version');
        $this->request('POST', '/api/caisse/open', ['cashRegisterId' => $this->register, 'cashFund' => 10000]);
        $sale = $this->json($this->request('POST', '/api/order/create-multi-payment', ['items' => [['productVariantId' => $this->variant, 'quantity' => 1]], 'paymentMethods' => [['type' => 2, 'amount' => 33.35]], 'cashRegisterId' => $this->register]), 201);
        $entry = json_decode($this->db->fetchOne("SELECT payload FROM pos_audit_log WHERE type = 'SALE' AND (payload::json->'subject'->>'id')::int = ? ORDER BY id DESC LIMIT 1", [(int) $sale['orderId']]), true); // la vente est le sujet de l'entrée (payload : texte JSON)
        $this->assertSame('1.4.0+12', $entry['data']['appVersion'] ?? null, 'SALE : data.appVersion gardé');
        // Sans en-tête : rien d'ajouté ; en-tête mal formé : ignoré
        $this->appVersion = null;
        $before = (int) $this->db->fetchOne('SELECT COALESCE(MAX(id), 0) FROM pos_audit_log');
        $this->loginAs(['ROLE_USER_POS']);
        $login = json_decode($this->db->fetchOne("SELECT payload FROM pos_audit_log WHERE id > ? AND type = 'LOGIN' ORDER BY id DESC LIMIT 1", [$before]), true);
        $this->assertArrayNotHasKey('appVersion', $login['data']);
        $this->appVersion = 'version <script>';
        $before = (int) $this->db->fetchOne('SELECT COALESCE(MAX(id), 0) FROM pos_audit_log');
        $this->loginAs(['ROLE_USER_POS']);
        $login = json_decode($this->db->fetchOne("SELECT payload FROM pos_audit_log WHERE id > ? AND type = 'LOGIN' ORDER BY id DESC LIMIT 1", [$before]), true);
        $this->assertArrayNotHasKey('appVersion', $login['data'], 'en-tête hors format : ignoré');
    }

    private function settings(): array
    {
        return $this->json($this->request('GET', '/api/pos/settings'));
    }

    private function json(Response $response, int $expected = 200): array
    {
        $this->assertSame($expected, $response->getStatusCode(), $response->getContent());

        return json_decode($response->getContent(), true);
    }

    private function request(string $method, string $path, ?array $body = null): Response
    {
        $this->client->getCookieJar()->clear();
        $this->client->request($method, 'https://' . MV_TEST_TENANT_HOST . $path, [], [], array_filter([
            'HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => $this->token ? 'Bearer ' . $this->token : null, 'HTTP_X_APP_VERSION' => $this->appVersion,
        ]), $body !== null ? json_encode($body) : null);

        return $this->client->getResponse();
    }

    private function loginAs(array $roles): void
    {
        $user = $this->user($roles);
        $this->token = null;
        $login = $this->request('POST', '/api/login', ['username' => $user->getEmail(), 'password' => self::PASSWORD, 'platform' => 'pos']);
        $this->assertSame(200, $login->getStatusCode(), $login->getContent());
        $this->token = json_decode($login->getContent(), true)['token'];
    }

    private function user(array $roles): User
    {
        $email = 'agec-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $container = static::getContainer();
        $provider = $container->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Agec')->setLastname('Test')->setRoles($roles)->setIsVerified(true);
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD));
        $provider->getEntityManager()->persist($user);
        $provider->getEntityManager()->flush();

        return $user;
    }
}
