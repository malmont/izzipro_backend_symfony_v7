<?php

namespace App\Tests\Functional\Security;

use App\Entity\User;
use App\Services\BoutiqueDemoService\BoutiqueDemoSeeder;
use App\Services\TenantEntityManagerProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Responsable de caisse (ROLE_POS_MANAGER, 16/10/2026) : gère les postes et rembourse les ventes des caissiers, sans
 * accès au back-office, aux clés d'activation ni aux autres sources que la caisse. Un caissier (ROLE_USER_POS) ne
 * gagne rien ; un administrateur garde tout.
 */
class PosManagerRoleTest extends WebTestCase
{
    private const PASSWORD = 'Mot-de-passe-de-test-1!';
    private KernelBrowser $client;
    private \Doctrine\DBAL\Connection $db;
    private ?string $token = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $this->db = $provider->getEntityManager()->getConnection();
        static::getContainer()->get(BoutiqueDemoSeeder::class)->seed('client-caisse@example.invalid', false, false);
        $this->db->executeStatement('DELETE FROM boutique_setting');
        $this->db->executeStatement("UPDATE address_entreprise SET country = 'CA', state = 'QC'");
        $this->db->executeStatement('UPDATE caisse SET is_open = false, closed_at = NOW() WHERE is_open = true');
        $this->db->executeStatement('UPDATE cash_register SET is_active = (id = (SELECT MIN(id) FROM cash_register))');
    }

    public function testAManagerRunsTheRegistersButNotTheBackOffice(): void
    {
        $this->loginAs(['ROLE_USER_POS', 'ROLE_POS_MANAGER']);
        $created = $this->request('POST', '/api/cash-registers', json_encode(['name' => 'Poste responsable ' . bin2hex(random_bytes(2))]));
        $this->assertSame(201, $created->getStatusCode(), $created->getContent());
        $id = json_decode($created->getContent(), true)['id'];
        $this->assertSame(200, $this->request('PUT', "/api/cash-registers/$id", json_encode(['isActive' => false]))->getStatusCode(), 'désactivation');
        $this->assertContains($id, array_column(json_decode($this->request('GET', '/api/cash-registers?all=1')->getContent(), true), 'id'), '?all=1 pour le responsable');
        $this->assertSame(200, $this->request('GET', '/api/statistiques/chiffre-affaires/pos')->getStatusCode());
        $this->assertSame(200, $this->request('GET', '/api/orders?orderSource=2')->getStatusCode());
        $this->assertSame(200, $this->request('GET', '/api/payments?orderSource=2')->getStatusCode());

        $this->assertSame(403, $this->request('GET', '/api/statistiques/chiffre-affaires/ecommerce')->getStatusCode(), 'autre source : administrateur seulement');
        $this->assertSame(403, $this->request('GET', '/api/orders?orderSource=1')->getStatusCode());
        $this->assertSame(403, $this->request('GET', '/api/app-keys')->getStatusCode(), 'clés d\'activation : administrateur seulement');
        $this->assertSame(403, $this->request('POST', '/api/app-keys', json_encode(['app' => 'pos', 'label' => 'x']))->getStatusCode());
        $this->assertSame(403, $this->request('GET', '/api/landingpage-ai/usage')->getStatusCode(), 'autres modules fermés');
        $this->assertSame(403, $this->request('PUT', '/api/boutique-settings', '{}')->getStatusCode());

        // Connexion : plateforme pos (même sans ROLE_USER_POS), jamais admin
        $email = $this->user(['ROLE_POS_MANAGER']);
        $this->assertSame(200, $this->login($email, 'pos')->getStatusCode(), 'responsable seul : plateforme pos acceptée');
        $this->assertSame(403, $this->login($email, 'admin')->getStatusCode(), 'plateforme admin refusée');

        // Un caissier ne gagne rien
        $this->loginAs(['ROLE_USER_POS']);
        $this->assertSame(403, $this->request('POST', '/api/cash-registers', json_encode(['name' => 'Interdit']))->getStatusCode());
        $this->assertSame(403, $this->request('PUT', "/api/cash-registers/$id", json_encode(['isActive' => true]))->getStatusCode());
        $this->assertNotContains($id, array_column(json_decode($this->request('GET', '/api/cash-registers?all=1')->getContent(), true), 'id'), 'caissier : ?all ignoré');
    }

    public function testAManagerRefundsAnotherCashiersSaleAndACashierCannot(): void
    {
        $register = (int) $this->db->fetchOne('SELECT MIN(id) FROM cash_register');
        $this->loginAs(['ROLE_USER_POS']);
        $this->assertSame(201, $this->request('POST', '/api/caisse/open', json_encode(['cashRegisterId' => $register]))->getStatusCode());
        $variant = (int) $this->db->fetchOne("SELECT v.id FROM product_variant v JOIN product p ON p.id = v.product_id WHERE p.code = 'DEMO-CASQUETTE' ORDER BY v.id LIMIT 1");
        $total = json_decode($this->request('POST', '/api/cart/quote', json_encode(['items' => [['productVariantId' => $variant, 'quantity' => 1]], 'shippingAddress' => ['country' => 'CA', 'province' => 'QC']]))->getContent(), true)['total'];
        $sale = fn () => json_decode($this->request('POST', '/api/order/create-multi-payment', json_encode(['items' => [['productVariantId' => $variant, 'quantity' => 1]], 'paymentMethods' => [['type' => 2, 'amount' => $total / 100]], 'cashRegisterId' => $register]))->getContent(), true)['orderId'];
        $first = $sale();
        $second = $sale();

        $this->loginAs(['ROLE_USER_POS']); // un autre caissier
        $refused = $this->request('POST', "/api/order/cancel/$first", json_encode(['paymentMethod' => 2]));
        $this->assertSame(403, $refused->getStatusCode(), 'un caissier ne rembourse que ses ventes');

        $this->loginAs(['ROLE_USER_POS', 'ROLE_POS_MANAGER']);
        $before = (float) $this->db->fetchOne('SELECT amount_total FROM caisse WHERE cash_register_id = ? AND is_open', [$register]);
        $ok = $this->request('POST', "/api/order/cancel/$first", json_encode(['paymentMethod' => 2]));
        $this->assertSame(200, $ok->getStatusCode(), $ok->getContent());
        $this->assertEqualsWithDelta($before - $total, (float) $this->db->fetchOne('SELECT amount_total FROM caisse WHERE cash_register_id = ? AND is_open', [$register]), 0.01, 'remboursé sur la session du poste');
        $this->assertSame(7, (int) $this->db->fetchOne('SELECT status_id FROM "order" WHERE id = ?', [$first]), 'commande annulée');

        $this->loginAs(['ROLE_ADMIN', 'ROLE_USER_POS']);
        $this->assertSame(200, $this->request('POST', "/api/order/cancel/$second", json_encode(['paymentMethod' => 2]))->getStatusCode(), 'un administrateur aussi');

        // Commande web : toujours réservée à son auteur (le responsable n'y touche pas)
        $this->loginAs(['ROLE_USER_INTERNET'], 'mobile');
        $webOrder = (int) $this->db->fetchOne('SELECT MAX(id) FROM "order" WHERE order_source_id = 1');
        if ($webOrder) {
            $this->loginAs(['ROLE_USER_POS', 'ROLE_POS_MANAGER']);
            $this->assertSame(403, $this->request('POST', "/api/order/cancel/$webOrder", json_encode(['paymentMethod' => 2]))->getStatusCode(), 'commande web : auteur seulement');
        }
    }

    private function login(string $email, string $platform): Response
    {
        $this->token = null;

        return $this->request('POST', '/api/login', json_encode(['username' => $email, 'password' => self::PASSWORD, 'platform' => $platform]));
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
        $login = $this->login($this->user($roles), $platform);
        $this->assertSame(200, $login->getStatusCode(), $login->getContent());
        $this->token = json_decode($login->getContent(), true)['token'];
    }

    private function user(array $roles): string
    {
        $email = 'responsable-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $container = static::getContainer();
        $provider = $container->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Responsable')->setLastname('Caisse')->setRoles($roles)->setIsVerified(true);
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD));
        $provider->getEntityManager()->persist($user);
        $provider->getEntityManager()->flush();

        return $email;
    }
}
