<?php

namespace App\Tests\Functional\Boutique;

use App\Entity\User;
use App\Services\BoutiqueDemoService\BoutiqueDemoSeeder;
use App\Services\TenantEntityManagerProvider;
use App\Tests\Fake\FakeCheckoutPaymentGateway;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Suite de S7 : le jeton JWT et le jeton de renouvellement identifient l'utilisateur par son courriel. Après un changement d'adresse,
 * PATCH /profile rend de nouveaux jetons (cookies + CSRF pour le site, token et refresh_token pour les applications) et transfère les
 * jetons de renouvellement ; réponse identique sans changement. GET /profile : otpEnabled, passwordSet ; compte d'achat invité : mot de
 * passe haché inutilisable, passwordSet faux (changement de courriel par code) jusqu'à « mot de passe oublié ».
 */
class EmailChangeSessionApiTest extends WebTestCase
{
    private const PASSWORD = 'Mot-de-passe-de-test-1!';
    private KernelBrowser $client;
    private array $session = [];
    private ?string $bearer = null;
    private \Doctrine\DBAL\Connection $db;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $this->db = $provider->getEntityManager()->getConnection();
        static::getContainer()->get(BoutiqueDemoSeeder::class)->seed('client-session@example.invalid', false, false);
        FakeCheckoutPaymentGateway::reset();
    }

    public function testTheWebSessionSurvivesAnEmailChangeAndTheResponseIsUnchangedOtherwise(): void
    {
        $this->loginAs(['ROLE_USER_INTERNET']);
        $old = $this->session;
        $profile = $this->json($this->request('GET', '/api/profile'));
        $this->assertSame([false, true], [$profile['otpEnabled'], $profile['passwordSet']], 'compte avec mot de passe choisi');

        // Sans changement d'adresse : réponse d'avant, aucun cookie
        $same = $this->request('PATCH', '/api/profile', json_encode(['email' => $old['email'], 'firstName' => 'Paiement']));
        $this->assertSame(['message', 'user'], array_keys($this->json($same)));
        $this->assertSame([], $same->headers->getCookies(), 'aucun nouveau jeton');

        // Changement réel : nouveaux cookies (auth_token, refresh_token, XSRF-TOKEN) et jetons dans le corps
        $new = 'nouvelle-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $changed = $this->request('PATCH', '/api/profile', json_encode(['email' => $new, 'currentPassword' => self::PASSWORD]));
        $body = $this->json($changed);
        $this->assertSame(['message', 'user', 'token', 'refresh_token', 'csrf_token'], array_keys($body));
        $cookies = [];
        foreach ($changed->headers->getCookies() as $c) {
            $cookies[$c->getName()] = $c->getValue();
        }
        $code = MV_TEST_TENANT_CODE;
        $this->assertSame(["auth_token_$code", "refresh_token_$code", "XSRF-TOKEN_$code"], array_keys($cookies));
        $this->assertSame($body['csrf_token'], $cookies["XSRF-TOKEN_$code"]);

        // Les nouveaux cookies ouvrent la session ; l'ancien jeton de renouvellement a suivi l'adresse. (L'ancien jeton d'accès reste lu
        // par le repli sur le nom d'utilisateur, qui garde l'ancienne adresse : comportement d'avant, non modifié ici.)
        $this->session = ['email' => $new] + $cookies;
        $this->assertSame($new, $this->json($this->request('GET', '/api/profile'))['email']);
        $this->assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM refresh_tokens WHERE username = ? AND refresh_token = ?', [$new, $old["refresh_token_$code"]]), 'jeton de la session d\'avant transféré');
        $this->assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM refresh_tokens WHERE username = ?', [$old['email']]));

        // Changement de casse seulement : l'adresse enregistrée change, la session continue aussi
        $upper = strtoupper($new);
        $case = $this->request('PATCH', '/api/profile', json_encode(['email' => $upper]));
        $this->assertSame(['message', 'user', 'token', 'refresh_token', 'csrf_token'], array_keys($this->json($case)), 'casse : nouveaux jetons');
        foreach ($case->headers->getCookies() as $c) {
            $this->session[$c->getName()] = $c->getValue();
        }
        $this->assertSame($upper, $this->json($this->request('GET', '/api/profile'))['email']);
    }

    public function testAnAppSessionGetsNewTokensAndItsRefreshTokenStillWorks(): void
    {
        $email = $this->user(['ROLE_USER_INTERNET']);
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . '/api/login', [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json'],
            json_encode(['username' => $email, 'password' => self::PASSWORD, 'platform' => 'mobile']));
        $login = json_decode($this->client->getResponse()->getContent(), true);
        $this->session = [];
        $this->bearer = $login['token'];
        $new = 'appli-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $changed = $this->request('PATCH', '/api/profile', json_encode(['email' => $new, 'currentPassword' => self::PASSWORD]));
        $body = $this->json($changed);
        $this->assertSame(['message', 'user', 'token', 'refresh_token'], array_keys($body), 'application : token et refresh_token, sans cookie');
        $this->assertSame([], $changed->headers->getCookies());
        $this->bearer = $body['token'];
        $this->assertSame($new, $this->json($this->request('GET', '/api/profile'))['email']);
        // Jeton de renouvellement d'avant (autre appareil) : transféré, il renouvelle la session
        if (!empty($login['refresh_token'])) {
            $this->bearer = null;
            $refreshed = $this->request('POST', '/api/token/refresh', json_encode(['refresh_token' => $login['refresh_token']]));
            $this->assertSame(200, $refreshed->getStatusCode(), $refreshed->getContent());
        }
    }

    public function testAnAdminEmailChangeInTheMemoriesConsoleTransfersRefreshTokens(): void
    {
        $email = $this->user(['ROLE_USER_INTERNET']);
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . '/api/login', [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json'],
            json_encode(['username' => $email, 'password' => self::PASSWORD, 'platform' => 'mobile']));
        $refresh = json_decode($this->client->getResponse()->getContent(), true)['refresh_token'] ?? null;
        $id = (int) $this->db->fetchOne('SELECT id FROM "user" WHERE email = ?', [$email]);
        $adminEmail = $this->user(['ROLE_ADMIN']);
        $em = static::getContainer()->get(TenantEntityManagerProvider::class)->getEntityManager();
        $this->session = [];
        $this->bearer = static::getContainer()->get('lexik_jwt_authentication.jwt_manager')->create($em->getRepository(User::class)->findOneBy(['email' => $adminEmail]));
        $new = 'console-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $put = $this->request('PUT', "/api/memoires/admin/users/$id?locale=fr", json_encode(['email' => $new]));
        $this->assertSame(200, $put->getStatusCode(), $put->getContent());
        $this->assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM refresh_tokens WHERE username = ?', [$email]));
        if ($refresh !== null) {
            $this->bearer = null;
            $this->assertSame(200, $this->request('POST', '/api/token/refresh', json_encode(['refresh_token' => $refresh]))->getStatusCode(), 'renouvellement après le changement');
        }
    }

    public function testAnEmailChangeInEasyAdminTransfersRefreshTokens(): void
    {
        $email = $this->user(['ROLE_USER_INTERNET']);
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . '/api/login', [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json'],
            json_encode(['username' => $email, 'password' => self::PASSWORD, 'platform' => 'mobile']));
        $this->assertGreaterThan(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM refresh_tokens WHERE username = ?', [$email]));
        $id = (int) $this->db->fetchOne('SELECT id FROM "user" WHERE email = ?', [$email]);
        $em = static::getContainer()->get(TenantEntityManagerProvider::class)->getEntityManager();
        $this->client->loginUser($em->getRepository(User::class)->findOneBy(['email' => $this->user(['ROLE_ADMIN'])]), 'main');
        $url = 'https://' . MV_TEST_TENANT_HOST . '/admin?' . http_build_query(['crudAction' => 'edit', 'crudControllerFqcn' => \App\Controller\Admin\UserCrudController::class, 'entityId' => $id]);
        $crawler = $this->client->request('GET', $url, [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $new = 'easyadmin-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $this->client->submit($crawler->filter('form[name="User"]')->form(), ['User[email]' => $new]);
        $this->assertContains($this->client->getResponse()->getStatusCode(), [302, 303], 'enregistré');
        $this->assertSame($new, $this->db->fetchOne('SELECT email FROM "user" WHERE id = ?', [$id]));
        $this->assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM refresh_tokens WHERE username = ?', [$email]));
        $this->assertGreaterThan(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM refresh_tokens WHERE username = ?', [$new]), 'jetons transférés');
    }

    public function testAGuestPurchaseAccountHasAnUnusablePasswordAndConfirmsByCodeUntilItChoosesOne(): void
    {
        $this->session = [];
        $variant = (int) $this->db->fetchOne("SELECT v.id FROM product_variant v JOIN product p ON p.id = v.product_id WHERE p.code = 'DEMO-CASQUETTE' ORDER BY v.id LIMIT 1");
        $email = 'invite-session-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $body = $this->guestBody($variant, $email);
        $pi = $this->json($this->request('POST', '/api/stripe/create-intent', json_encode(['items' => $body['items'], 'carrierId' => 6, 'shippingAddress' => ['province' => 'QC']])))['paymentIntentId'];
        FakeCheckoutPaymentGateway::confirm($pi);
        $this->assertSame(201, $this->request('POST', '/api/order/create-guest', json_encode($body + ['paymentIntentId' => $pi]))->getStatusCode());
        $row = $this->db->fetchAssociative('SELECT password, password_set FROM "user" WHERE email = ?', [$email]);
        $this->assertFalse((bool) $row['password_set'], 'mot de passe jamais choisi');
        $this->assertStringStartsWith('$2y$', $row['password'], 'hachage, plus de valeur en clair');
        $this->assertDoesNotMatchRegularExpression('/^[0-9a-f]{20}$/', $row['password']);
        $this->assertSame(401, $this->request('POST', '/api/login', json_encode(['username' => $email, 'password' => '', 'platform' => 'web']))->getStatusCode(), 'aucune connexion possible');

        // Mot de passe oublié : le client en choisit un → passwordSet vrai, confirmation par mot de passe
        $this->assertSame(200, $this->request('POST', '/api/password-reset/request', json_encode(['email' => $email]))->getStatusCode());
        $token = $this->db->fetchOne('SELECT reset_token FROM "user" WHERE email = ?', [$email]);
        $this->assertSame(200, $this->request('POST', '/api/password-reset/confirm', json_encode(['token' => $token, 'password' => self::PASSWORD]))->getStatusCode());
        $this->assertTrue((bool) $this->db->fetchOne('SELECT password_set FROM "user" WHERE email = ?', [$email]));
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . '/api/login', [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json'],
            json_encode(['username' => $email, 'password' => self::PASSWORD, 'platform' => 'web']));
        $this->session = ['email' => $email];
        foreach ($this->client->getResponse()->headers->getCookies() as $c) {
            $this->session[$c->getName()] = $c->getValue();
        }
        $this->assertSame([false, true], array_values(array_intersect_key($this->json($this->request('GET', '/api/profile')), array_flip(['otpEnabled', 'passwordSet']))));

        // Avant le choix (compte d'achat invité) : otpEnabled vrai
        $this->db->executeStatement('UPDATE "user" SET password_set = false WHERE email = ?', [$email]);
        static::getContainer()->get(TenantEntityManagerProvider::class)->getEntityManager()->clear();
        $p = $this->json($this->request('GET', '/api/profile'));
        $this->assertSame([true, false], [$p['otpEnabled'], $p['passwordSet']], 'confirmation par code');
    }

    private function user(array $roles): string
    {
        $email = 'session-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $container = static::getContainer();
        $provider = $container->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Session')->setLastname('Client')->setRoles($roles)->setIsVerified(true);
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD));
        $provider->getEntityManager()->persist($user);
        $provider->getEntityManager()->flush();

        return $email;
    }

    private function guestBody(int $variant, string $email): array
    {
        return [
            'guestInfo' => ['email' => $email, 'firstName' => 'Invité', 'lastName' => 'Test', 'phone' => '5145550100'],
            'items' => [['productVariantId' => $variant, 'quantity' => 1]], 'carrierId' => 6,
            // Sans pays : le Canada est déduit de la province
            'shippingAddress' => ['firstname' => 'Invité', 'lastname' => 'Test', 'address' => '1 rue du Test', 'city' => 'Québec', 'province' => 'QC', 'postalCode' => 'G1R 4P5'],
        ];
    }

    private function json(Response $response): array
    {
        $this->assertSame(200, $response->getStatusCode(), $response->getContent());

        return json_decode($response->getContent(), true);
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
            'HTTP_AUTHORIZATION' => $this->bearer ? 'Bearer ' . $this->bearer : null,
        ]), $body);

        return $this->client->getResponse();
    }

    private function loginAs(array $roles): void
    {
        $email = 'session-web-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $container = static::getContainer();
        $provider = $container->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Compte')->setLastname('Client')->setRoles($roles)->setIsVerified(true);
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD));
        $provider->getEntityManager()->persist($user);
        $provider->getEntityManager()->flush();

        $this->client->getCookieJar()->clear();
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . '/api/login', [], [], [
            'HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
        ], json_encode(['username' => $email, 'password' => self::PASSWORD, 'platform' => 'web']));
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());
        $this->session = ['email' => $email];
        foreach ($this->client->getResponse()->headers->getCookies() as $cookie) {
            $this->session[$cookie->getName()] = $cookie->getValue();
        }
    }
}
