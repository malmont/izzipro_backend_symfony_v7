<?php

namespace App\Tests\Functional\Security;

use App\Entity\User;
use App\Services\BoutiqueDemoService\BoutiqueDemoSeeder;
use App\Services\TenantEntityManagerProvider;
use Lexik\Bundle\JWTAuthenticationBundle\Encoder\JWTEncoderInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Codes d'erreur stables (02/11/2026) : toute réponse JSON 4xx porte `code` (MAJUSCULES_SOULIGNÉES) ; les exceptions déjà codées
 * donnent code = error ; les 401 de Lexik gardent `code: 401` (entier) et reçoivent `error` (chaîne) ; `error`, `message` et le statut
 * ne changent pas ; un devis refusé à la vente garde son 400 et gagne `code` + `errors[{path, message}]` ; 429 : RATE_LIMITED + retryAfterSeconds.
 */
class ErrorCodesApiTest extends WebTestCase
{
    private const PASSWORD = 'Mot-de-passe-de-test-1!';
    private KernelBrowser $client;
    private \Doctrine\DBAL\Connection $db;
    private ?string $token = null;
    private int $register;
    private int $variant;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $this->db = $provider->getEntityManager()->getConnection();
        static::getContainer()->get(BoutiqueDemoSeeder::class)->seed('client-codes@example.invalid', false, false);
        $this->db->executeStatement("UPDATE address_entreprise SET country = 'CA', state = 'QC', city = 'Montréal', zip = 'H1M 0A9'");
        $this->db->executeStatement("UPDATE entreprise SET currency = 'CAD', prices_include_tax = false, pos_cash_rounding_step = NULL, pos_terminal_header_required = false");
        $this->db->executeStatement('UPDATE caisse SET is_open = false, closed_at = NOW() WHERE is_open = true');
        $this->db->executeStatement('UPDATE cash_register SET is_active = (id = (SELECT MIN(id) FROM cash_register)), terminal_enabled = false');
        $this->register = (int) $this->db->fetchOne('SELECT MIN(id) FROM cash_register');
        $this->variant = (int) $this->db->fetchOne("SELECT v.id FROM product_variant v JOIN product p ON p.id = v.product_id WHERE p.code = 'DEMO-CASQUETTE' ORDER BY v.id LIMIT 1");
        $this->db->executeStatement('UPDATE product_variant SET stock_quantity = 2 WHERE id = ?', [$this->variant]);
    }

    public function testGenericHttpErrorsAndLexikResponsesCarryAStableCode(): void
    {
        $this->loginAs(['ROLE_USER_POS']);
        // 403 : caissier sur une route réservée au responsable (access_control) ; 404 : route inconnue
        $forbidden = $this->json($this->request('GET', '/api/pos/audit'), 403);
        $this->assertSame(['Accès refusé.', 'FORBIDDEN', 'Accès refusé.', 403], [$forbidden['error'], $forbidden['code'], $forbidden['message'], $forbidden['status']], 'error, message, status inchangés ; code ajouté');
        $notFound = $this->json($this->request('GET', '/api/pos/route-inconnue'), 404);
        $this->assertSame(['Ressource introuvable.', 'NOT_FOUND'], [$notFound['error'], $notFound['code']]);
        // 401 Lexik : jeton absent, expiré, invalide → code entier 401 conservé, error (chaîne) ajouté
        $this->token = null;
        $none = $this->json($this->request('GET', '/api/pos/settings'), 401);
        $this->assertSame([401, 'JWT_NOT_FOUND'], [$none['code'], $none['error']]);
        $this->assertArrayHasKey('message', $none);
        $this->token = 'ceci.nest.pas.un.jeton';
        $invalid = $this->json($this->request('GET', '/api/pos/settings'), 401);
        $this->assertSame([401, 'JWT_INVALID'], [$invalid['code'], $invalid['error']]);
        $user = $this->user(['ROLE_USER_POS']);
        $this->token = static::getContainer()->get(JWTEncoderInterface::class)->encode(['username' => $user->getUserIdentifier(), 'email' => $user->getEmail(), 'roles' => $user->getRoles(), 'id' => $user->getId(), 'tenant_code' => MV_TEST_TENANT_CODE, 'exp' => time() - 60, 'iat' => time() - 120]);
        $expired = $this->json($this->request('GET', '/api/pos/settings'), 401);
        $this->assertSame([401, 'JWT_EXPIRED'], [$expired['code'], $expired['error']]);
    }

    public function testLoginRefreshAndRateLimitResponses(): void
    {
        $user = $this->user(['ROLE_USER_POS']);
        $this->token = null;
        $wrong = $this->json($this->request('POST', '/api/login', ['username' => $user->getEmail(), 'password' => 'faux', 'platform' => 'pos']), 401);
        $this->assertSame('LOGIN_INVALID', $wrong['code'], 'connexion : code chaîne inchangé');
        $web = $this->user(['ROLE_USER_INTERNET']);
        $platform = $this->json($this->request('POST', '/api/login', ['username' => $web->getEmail(), 'password' => self::PASSWORD, 'platform' => 'pos']), 403);
        $this->assertSame(['This account is not allowed to access the POS platform.', 'PLATFORM_NOT_ALLOWED', 'This account is not allowed to access the POS platform.'], [$platform['error'], $platform['code'], $platform['message']]);
        $refresh = $this->json($this->request('POST', '/api/token/refresh', ['refresh_token' => 'inconnu']), 401);
        $this->assertSame(['Invalid refresh token', 'REFRESH_TOKEN_INVALID', 'Invalid refresh token'], [$refresh['error'], $refresh['code'], $refresh['message']]);
        $missing = $this->json($this->request('POST', '/api/token/refresh', []), 401);
        $this->assertSame('REFRESH_TOKEN_MISSING', $missing['code']);
        // 429 sur la connexion (10 essais / 15 min par compte) : RATE_LIMITED, message et retryAfterSeconds ajoutés, Retry-After gardé
        $last = null;
        for ($i = 0; $i < 20; ++$i) {
            $last = $this->request('POST', '/api/login', ['username' => $user->getEmail(), 'password' => 'faux-' . $i, 'platform' => 'pos']);
            if ($last->getStatusCode() === 429) {
                break;
            }
        }
        $this->assertSame(429, $last->getStatusCode(), $last->getContent());
        $body = json_decode($last->getContent(), true);
        $this->assertSame(['RATE_LIMITED', true], [$body['code'], str_starts_with($body['error'], 'Trop de tentatives')]);
        $this->assertSame($body['error'], $body['message']);
        $this->assertSame((int) $last->headers->get('Retry-After'), $body['retryAfterSeconds']);
    }

    public function testCodedExceptionsAndSaleQuoteErrorsCarryCodeAndDetails(): void
    {
        $this->loginAs(['ROLE_USER_POS']);
        // Exception déjà codée : code = error, message inchangé
        $closed = $this->json($this->request('GET', '/api/caisse/report/x?cashRegisterId=' . $this->register), 409);
        $this->assertSame(['CASH_REGISTER_CLOSED', 'CASH_REGISTER_CLOSED'], [$closed['error'], $closed['code']]);
        $this->assertNotEmpty($closed['message']);
        $this->request('POST', '/api/caisse/open', ['cashRegisterId' => $this->register, 'cashFund' => 10000]);
        // Vente : article inconnu (404 du devis enveloppé en 400) et stock insuffisant → 400 inchangé, code + errors ajoutés, message inchangé
        $unknown = $this->json($this->request('POST', '/api/order/create-multi-payment', ['items' => [['productVariantId' => 999999, 'quantity' => 1]], 'paymentMethods' => [['type' => 2, 'amount' => 1]], 'cashRegisterId' => $this->register]), 400);
        $this->assertSame(['items[0].productVariantId : variante introuvable', 'VARIANT_NOT_FOUND', 'items[0].productVariantId'], [$unknown['error'], $unknown['code'], $unknown['errors'][0]['path']]);
        $this->assertSame($unknown['error'], $unknown['message']);
        $stock = $this->json($this->request('POST', '/api/order/create-multi-payment', ['items' => [['productVariantId' => $this->variant, 'quantity' => 5]], 'paymentMethods' => [['type' => 2, 'amount' => 1]], 'cashRegisterId' => $this->register]), 400);
        $this->assertSame(['QUOTE_INVALID', 'items[0].quantity'], [$stock['code'], $stock['errors'][0]['path']]);
        $this->assertStringContainsString('stock insuffisant', $stock['error']);
        // Retours manuels des contrôleurs : code + message, texte de error inchangé (annulation : route du responsable/administrateur)
        $this->loginAs(['ROLE_ADMIN', 'ROLE_USER_POS']);
        $cancel = $this->json($this->request('POST', '/api/order/cancel/999999', []), 404);
        $this->assertSame(['Order not found', 'ORDER_NOT_FOUND', 'Order not found'], [$cancel['error'], $cancel['code'], $cancel['message']]);
        $source = $this->json($this->request('GET', '/api/orders'), 400);
        $this->assertSame(['orderSource parameter is required', 'ORDER_SOURCE_REQUIRED'], [$source['error'], $source['code']]);
        $payments = $this->json($this->request('GET', '/api/payments'), 400);
        $this->assertSame('ORDER_SOURCE_REQUIRED', $payments['code']);
        // Validation champ par champ : forme gardée (VALIDATION_FAILED + errors)
        $validation = $this->json($this->request('POST', '/api/pos/quote', ['items' => [['productVariantId' => $this->variant, 'quantity' => 1, 'discounts' => [['type' => 'percent', 'value' => 500]]]], 'cashRegisterId' => $this->register]), 422);
        $this->assertSame($validation['error'], $validation['code'] ?? null, json_encode($validation));
        $this->assertNotEmpty($validation['errors']);
    }

    /** En production (debug désactivé) c'est ApiExceptionListener qui répond, avec le texte anglais du statut : code et message ajoutés, error inchangé */
    public function testProductionListenerCarriesTheCodeToo(): void
    {
        $listener = new \App\EventListener\ApiExceptionListener(false);
        $kernel = static::getContainer()->get('kernel');
        $make = function (\Throwable $e) use ($listener, $kernel): array {
            $event = new \Symfony\Component\HttpKernel\Event\ExceptionEvent($kernel, \Symfony\Component\HttpFoundation\Request::create('/api/pos/x'), \Symfony\Component\HttpKernel\HttpKernelInterface::MAIN_REQUEST, $e);
            $listener($event);

            return [$event->getResponse()->getStatusCode(), json_decode($event->getResponse()->getContent(), true)];
        };
        [$status, $body] = $make(new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException());
        $this->assertSame([403, ['error' => 'Forbidden', 'code' => 'FORBIDDEN', 'message' => 'Forbidden']], [$status, $body]);
        [$status, $body] = $make(new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException('No route found'));
        $this->assertSame([404, 'NOT_FOUND', 'Not Found'], [$status, $body['code'], $body['error']]);
        [$status, $body] = $make(new \Symfony\Component\HttpKernel\Exception\BadRequestHttpException('x', new \App\Services\OrderService\CartQuoteException(422, 'items[0].quantity : stock insuffisant', [['path' => 'items[0].quantity', 'message' => 'stock insuffisant']])));
        $this->assertSame([400, 'Bad Request', 'QUOTE_INVALID', 'items[0].quantity'], [$status, $body['error'], $body['code'], $body['errors'][0]['path']]);
        [$status, $body] = $make(new \Symfony\Component\HttpKernel\Exception\HttpException(500, 'boom'));
        $this->assertSame([500, ['error' => 'Internal Server Error']], [$status, $body], '5xx : inchangées');
    }

    private function json(Response $response, int $expected): array
    {
        $this->assertSame($expected, $response->getStatusCode(), $response->getContent());

        return json_decode($response->getContent(), true);
    }

    private function request(string $method, string $path, ?array $body = null): Response
    {
        $this->client->getCookieJar()->clear();
        $this->client->request($method, 'https://' . MV_TEST_TENANT_HOST . $path, [], [], array_filter([
            'HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => $this->token ? 'Bearer ' . $this->token : null,
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
        $email = 'codes-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $container = static::getContainer();
        $provider = $container->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Codes')->setLastname('Test')->setRoles($roles)->setIsVerified(true);
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD));
        $provider->getEntityManager()->persist($user);
        $provider->getEntityManager()->flush();

        return $user;
    }
}
