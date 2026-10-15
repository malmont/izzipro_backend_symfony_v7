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
 * Droits du rôle caisse (ROLE_USER_POS sans ROLE_ADMIN), 15/10/2026 : caisse, statistiques de la source « pos » et
 * liste des commandes de la caisse. Les administrateurs gardent les mêmes accès ; les clients restent refusés.
 */
class PosRoleAccessTest extends WebTestCase
{
    private const PASSWORD = 'Mot-de-passe-de-test-1!';
    private const POS_STATS = ['/api/statistiques/chiffre-affaires/pos', '/api/statistiques/nombre-commandes/pos', '/api/statistiques/panier-moyen/pos', '/api/payments/statistics/pos'];
    private KernelBrowser $client;
    private ?string $token = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        static::getContainer()->get(BoutiqueDemoSeeder::class)->seed('client-caisse@example.invalid', false, false); // référentiels de caisse
        $provider->getEntityManager()->getConnection()->executeStatement('UPDATE caisse SET is_open = false, closed_at = NOW() WHERE is_open = true');
        $provider->getEntityManager()->getConnection()->executeStatement('UPDATE cash_register SET is_active = (id = (SELECT MIN(id) FROM cash_register))'); // un seul poste actif
    }

    public function testACashierUsesTheCashRegisterAndReadsPosStatistics(): void
    {
        $this->loginAs(['ROLE_USER_POS']);
        $open = $this->request('POST', '/api/caisse/open', '{}');
        $this->assertSame(201, $open->getStatusCode(), $open->getContent());
        $this->assertSame(201, $this->request('POST', '/api/caisse/deposit', json_encode(['amount' => 10, 'cashDetails' => []]))->getStatusCode());
        $this->assertSame(200, $this->request('GET', '/api/caisse?days=7')->getStatusCode());
        $this->assertSame(200, $this->request('GET', '/api/caisse/transactions')->getStatusCode());
        $close = $this->request('POST', '/api/caisse/close', '{}');
        $this->assertSame(200, $close->getStatusCode(), 'fermeture : 200, comme avant : ' . substr($close->getContent(), 0, 300));
        foreach (self::POS_STATS as $path) {
            $this->assertSame(200, $this->request('GET', $path)->getStatusCode(), "caissier : $path");
            $this->assertSame(403, $this->request('GET', str_replace('/pos', '/ecommerce', $path))->getStatusCode(), 'autre source : refusée');
        }
        $this->assertSame(200, $this->request('GET', '/api/orders?orderSource=2')->getStatusCode(), 'commandes de la caisse');
        $this->assertSame(403, $this->request('GET', '/api/orders?orderSource=1')->getStatusCode(), 'commandes en ligne : refusées');
        $this->assertSame(403, $this->request('GET', '/api/orders')->getStatusCode(), 'sans source : refusé (pas de fuite)');
    }

    public function testAdminsKeepTheirAccessAndCustomersStayOut(): void
    {
        $this->loginAs(['ROLE_ADMIN', 'ROLE_USER_POS']);
        foreach (self::POS_STATS as $path) {
            $this->assertSame(200, $this->request('GET', $path)->getStatusCode());
            $this->assertSame(200, $this->request('GET', str_replace('/pos', '/ecommerce', $path))->getStatusCode(), "admin : $path (e-commerce)");
        }
        $this->assertSame(200, $this->request('GET', '/api/orders?orderSource=1')->getStatusCode());
        $this->assertSame(400, $this->request('GET', '/api/orders')->getStatusCode(), 'admin sans source : 400 comme avant');
        $this->assertSame(200, $this->request('GET', '/api/caisse?days=7')->getStatusCode());

        $this->loginAs(['ROLE_USER_INTERNET'], 'mobile');
        foreach ([...self::POS_STATS, '/api/caisse?days=7', '/api/caisse/transactions', '/api/orders?orderSource=2'] as $path) {
            $this->assertSame(403, $this->request('GET', $path)->getStatusCode(), "client : $path");
        }
        $this->assertSame(403, $this->request('POST', '/api/caisse/open', '{}')->getStatusCode());
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
        $email = 'droits-pos-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $container = static::getContainer();
        $provider = $container->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Droits')->setLastname('Pos')->setRoles($roles)->setIsVerified(true);
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD));
        $provider->getEntityManager()->persist($user);
        $provider->getEntityManager()->flush();
        $this->token = null;
        $login = $this->request('POST', '/api/login', json_encode(['username' => $email, 'password' => self::PASSWORD, 'platform' => $platform]));
        $this->assertSame(200, $login->getStatusCode(), $login->getContent());
        $this->token = json_decode($login->getContent(), true)['token'];
    }
}
