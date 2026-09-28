<?php

namespace App\Tests\Functional\Security;

use App\Entity\User;
use App\Services\TenantEntityManagerProvider;
use Gesdinet\JWTRefreshTokenBundle\Entity\RefreshToken;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Cycle de connexion web par cookies (front Next.js) : connexion, session, rafraîchissement, déconnexion.
 * Régression visée : cookies créés sans domaine mais effacés avec domaine → déconnexion sans effet.
 */
class AuthCookieFlowTest extends WebTestCase
{
    private const PASSWORD = 'Mot-de-passe-de-test-1!';

    public function testLoginRefreshLogoutCycle(): void
    {
        $client = static::createClient();
        $email = 'auth-flow-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $this->createUser($email);
        $tenant = MV_TEST_TENANT_CODE;
        $server = ['HTTP_HOST' => MV_TEST_TENANT_HOST, 'HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'HTTPS' => 'on', 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];

        // 1. Connexion web : jetons dans le corps + cookies sans domaine (host-only)
        $login = $this->send($client, 'POST', '/api/login', [], [], ['username' => $email, 'password' => self::PASSWORD, 'platform' => 'web']);
        $this->assertSame(200, $login->getStatusCode(), $login->getContent());
        $body = json_decode($login->getContent(), true);
        $this->assertNotEmpty($body['refresh_token']);
        $cookies = $this->cookies($login);
        foreach (["auth_token_$tenant", "refresh_token_$tenant", "XSRF-TOKEN_$tenant"] as $name) {
            $this->assertArrayHasKey($name, $cookies, "cookie $name posé");
            $this->assertNull($cookies[$name]->getDomain(), "cookie $name sans domaine (host-only)");
        }
        $jwt = $cookies["auth_token_$tenant"]->getValue();
        $refresh = $cookies["refresh_token_$tenant"]->getValue();
        $xsrf = $cookies["XSRF-TOKEN_$tenant"]->getValue();

        // 2. Session active via le cookie
        $r = $this->send($client, 'GET', '/api/validate-token', ["auth_token_$tenant" => $jwt]);
        $this->assertSame(200, $r->getStatusCode(), $r->getContent());

        // 3. Rafraîchissement par cookie (avec le jeton XSRF, comme le front)
        $r = $this->send($client, 'POST', '/api/token/refresh', ["auth_token_$tenant" => $jwt, "refresh_token_$tenant" => $refresh, "XSRF-TOKEN_$tenant" => $xsrf], ['HTTP_X_XSRF_TOKEN' => $xsrf]);
        $this->assertSame(200, $r->getStatusCode(), $r->getContent());
        $this->assertNull($this->cookies($r)["auth_token_$tenant"]->getDomain(), 'nouveau cookie sans domaine');

        // 4. Déconnexion : chaque cookie effacé sans domaine ET avec l'ancien domaine explicite
        $logout = $this->send($client, 'POST', '/api/logout', ["auth_token_$tenant" => $jwt, "refresh_token_$tenant" => $refresh, "XSRF-TOKEN_$tenant" => $xsrf], ['HTTP_X_XSRF_TOKEN' => $xsrf]);
        $this->assertSame(200, $logout->getStatusCode(), $logout->getContent());
        $cleared = [];
        foreach ($logout->headers->getCookies() as $cookie) {
            if ($cookie->isCleared()) {
                $cleared[$cookie->getName()][] = $cookie->getDomain();
            }
        }
        foreach (["auth_token_$tenant", "refresh_token_$tenant", "XSRF-TOKEN_$tenant"] as $name) {
            $this->assertContains(null, $cleared[$name] ?? [], "$name effacé sans domaine");
            $this->assertContains(MV_TEST_TENANT_HOST, $cleared[$name] ?? [], "$name effacé avec l'ancien domaine explicite");
        }

        // 5. Le jeton de rafraîchissement est révoqué : impossible de se reconnecter avec
        $r = $this->send($client, 'POST', '/api/token/refresh', [], [], ['refresh_token' => $refresh]);
        $this->assertSame(401, $r->getStatusCode(), 'jeton révoqué à la déconnexion : ' . $r->getContent());
        $r = $this->send($client, 'POST', '/api/token/refresh', ["refresh_token_$tenant" => $refresh]);
        $this->assertSame(401, $r->getStatusCode(), 'même via le cookie');
    }

    public function testLogoutWorksWithExpiredToken(): void
    {
        // Jeton d'accès expiré (au bout d'une heure) : la déconnexion doit quand même effacer les cookies,
        // sinon le cookie de rafraîchissement (7 jours) reconnecte l'utilisateur au rechargement
        $client = static::createClient();
        $tenant = MV_TEST_TENANT_CODE;
        $logout = $this->send($client, 'POST', '/api/logout', ["auth_token_$tenant" => 'jeton.expire.invalide', "refresh_token_$tenant" => 'inconnu']);
        $this->assertSame(200, $logout->getStatusCode(), $logout->getContent());
        $cleared = array_map(fn ($c) => $c->getName(), array_filter($logout->headers->getCookies(), fn ($c) => $c->isCleared()));
        $this->assertContains("auth_token_$tenant", $cleared);
        $this->assertContains("refresh_token_$tenant", $cleared);
    }

    public function testWebSessionExpiresAfterInactivity(): void
    {
        // Session web : jeton d'accès 15 min, déconnexion après 1 h sans activité, chaque rafraîchissement repousse l'échéance
        $client = static::createClient();
        $email = 'auth-idle-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $this->createUser($email);
        $tenant = MV_TEST_TENANT_CODE;

        $login = $this->send($client, 'POST', '/api/login', [], [], ['username' => $email, 'password' => self::PASSWORD, 'platform' => 'web']);
        $cookies = $this->cookies($login);
        $this->assertEqualsWithDelta(time() + 900, $cookies["auth_token_$tenant"]->getExpiresTime(), 5, 'cookie d\'accès : 15 min');
        $this->assertEqualsWithDelta(time() + 3600, $cookies["refresh_token_$tenant"]->getExpiresTime(), 5, 'cookie de session : 1 h');
        $jwtPayload = json_decode(base64_decode(strtr(explode('.', $cookies["auth_token_$tenant"]->getValue())[1], '-_', '+/')), true);
        $this->assertEqualsWithDelta(time() + 900, $jwtPayload['exp'], 5, 'le JWT lui-même expire avec son cookie');
        $refresh = $cookies["refresh_token_$tenant"]->getValue();
        $this->assertEqualsWithDelta(time() + 3600, $this->storedRefreshToken($refresh)->getValid()->getTimestamp(), 5);

        // Activité (rafraîchissement) peu avant l'échéance : la session est prolongée d'1 h
        $this->setRefreshTokenValidity($refresh, '+2 minutes');
        $r = $this->send($client, 'POST', '/api/token/refresh', ["refresh_token_$tenant" => $refresh]);
        $this->assertSame(200, $r->getStatusCode(), $r->getContent());
        $this->assertEqualsWithDelta(time() + 3600, $this->storedRefreshToken($refresh)->getValid()->getTimestamp(), 5, 'échéance repoussée');
        $this->assertEqualsWithDelta(time() + 3600, $this->cookies($r)["refresh_token_$tenant"]->getExpiresTime(), 5, 'cookie de session prolongé');
        $this->assertEqualsWithDelta(time() + 900, $this->cookies($r)["auth_token_$tenant"]->getExpiresTime(), 5);

        // Inactivité au-delà de la fenêtre : plus de rafraîchissement possible → déconnecté
        $this->setRefreshTokenValidity($refresh, '-1 minute');
        $r = $this->send($client, 'POST', '/api/token/refresh', ["refresh_token_$tenant" => $refresh]);
        $this->assertSame(401, $r->getStatusCode(), $r->getContent());

        // Mobile : pas de cookies, session de 7 jours inchangée
        $mobile = $this->send($client, 'POST', '/api/login', [], [], ['username' => $email, 'password' => self::PASSWORD, 'platform' => 'mobile']);
        $this->assertSame(200, $mobile->getStatusCode(), $mobile->getContent());
        $this->assertEmpty($mobile->headers->getCookies());
        $mobileRefresh = json_decode($mobile->getContent(), true)['refresh_token'];
        $this->assertEqualsWithDelta((new \DateTime('+7 days'))->getTimestamp(), $this->storedRefreshToken($mobileRefresh)->getValid()->getTimestamp(), 5);
    }

    private function storedRefreshToken(string $value): RefreshToken
    {
        $em = $this->em();
        $em->clear();
        return $em->getRepository(RefreshToken::class)->findOneBy(['refreshToken' => $value]);
    }

    private function setRefreshTokenValidity(string $value, string $modifier): void
    {
        $em = $this->em();
        $em->clear();
        $token = $em->getRepository(RefreshToken::class)->findOneBy(['refreshToken' => $value]);
        $token->setValid(new \DateTime($modifier));
        $em->flush();
    }

    private function em()
    {
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        return $provider->getEntityManager();
    }

    /** Requête vers https://mvtest.test avec les cookies donnés (via le bocal à cookies du client de test) */
    private function send($client, string $method, string $path, array $cookies = [], array $server = [], ?array $body = null): Response
    {
        $client->getCookieJar()->clear();
        foreach ($cookies as $name => $value) {
            $client->getCookieJar()->set(new \Symfony\Component\BrowserKit\Cookie($name, $value, null, '/', MV_TEST_TENANT_HOST, true));
        }
        $client->request($method, 'https://' . MV_TEST_TENANT_HOST . $path, [], [], $server + [
            'HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
        ], $body !== null ? json_encode($body) : null);

        return $client->getResponse();
    }

    /** @return array<string, Cookie> */
    private function cookies(Response $response): array
    {
        $cookies = [];
        foreach ($response->headers->getCookies() as $cookie) {
            $cookies[$cookie->getName()] = $cookie;
        }
        return $cookies;
    }

    private function createUser(string $email): void
    {
        $container = static::getContainer();
        $provider = $container->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $user = (new User())
            ->setEmail($email)
            ->setUsername($email)
            ->setFirstname('Auth')
            ->setLastname('Test')
            ->setRoles(['ROLE_USER_INTERNET'])
            ->setIsVerified(true);
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD));
        $provider->getEntityManager()->persist($user);
        $provider->getEntityManager()->flush();
    }
}
