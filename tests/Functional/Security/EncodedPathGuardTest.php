<?php

namespace App\Tests\Functional\Security;

use App\Entity\User;
use App\Services\TenantEntityManagerProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Un chemin dont une lettre est encodée (`/api/%6cogin`, `/%61pi/profile`) est décodé par le routeur et par les règles d'accès : il atteint
 * la même route. Les gardes de l'application doivent donc le reconnaître aussi (lot CHEMIN, 14/11/2026) : la limite de débit de la
 * connexion et la vérification anti-CSRF ne se contournent pas en encodant le chemin.
 */
class EncodedPathGuardTest extends WebTestCase
{
    private const PASSWORD = 'Mot-de-passe-de-test-1!';
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testTheLoginRateLimitAlsoCountsAnEncodedPath(): void
    {
        $email = 'chemin-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $ip = '10.84.' . random_int(1, 254) . '.' . random_int(1, 254);
        // api_login_account : 10 essais par 15 minutes et par compte ; le chemin encodé atteint bien la connexion (401)
        $statuses = [];
        for ($i = 0; $i < 11; $i++) {
            $statuses[] = $this->send('POST', '/api/%6cogin', [], ['REMOTE_ADDR' => $ip], ['username' => $email, 'password' => 'faux-mot-de-passe', 'platform' => 'mobile'])->getStatusCode();
        }
        $this->assertSame(array_fill(0, 10, 401), array_slice($statuses, 0, 10), 'le chemin encodé est servi par la route de connexion');
        $this->assertSame(429, $statuses[10], 'onzième essai par le chemin encodé : bloqué comme par le chemin normal');
    }

    public function testTheCsrfCheckAlsoAppliesToAnEncodedPath(): void
    {
        $email = 'chemin-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Chemin')->setLastname('Encodé')->setRoles(['ROLE_USER_INTERNET'])->setIsVerified(true);
        $user->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD));
        $provider->getEntityManager()->persist($user);
        $provider->getEntityManager()->flush();

        $login = $this->send('POST', '/api/login', [], [], ['username' => $email, 'password' => self::PASSWORD, 'platform' => 'web']);
        $this->assertSame(200, $login->getStatusCode(), (string) $login->getContent());
        $cookies = [];
        foreach ($login->headers->getCookies() as $cookie) {
            $cookies[$cookie->getName()] = (string) $cookie->getValue();
        }
        // Session par cookies, SANS l'en-tête X-XSRF-TOKEN (ce que ferait un autre site) : l'écriture est refusée
        $normal = $this->send('PUT', '/api/profile', $cookies, [], ['firstName' => 'Par le chemin normal']);
        $this->assertSame(403, $normal->getStatusCode(), (string) $normal->getContent());
        // … et par le chemin encodé aussi
        $encoded = $this->send('PUT', '/%61pi/profile', $cookies, [], ['firstName' => 'Par le chemin encodé']);
        $this->assertSame(403, $encoded->getStatusCode(), 'chemin encodé sans jeton anti-CSRF : ' . substr((string) $encoded->getContent(), 0, 200));
        $this->assertSame('Chemin', $provider->getEntityManager()->getConnection()->fetchOne('SELECT firstname FROM "user" WHERE email = ?', [$email]), 'le profil n\'a pas été modifié');
    }

    public function testEveryGuardSeesThePathTheRouterSees(): void
    {
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $token = function (array $roles) use ($provider): string {
            $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
            $email = 'chemin-' . bin2hex(random_bytes(3)) . '@example.invalid';
            $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Chemin')->setLastname('Encodé')->setRoles($roles)->setIsVerified(true)->setPassword('inutilisable');
            $provider->getEntityManager()->persist($user);
            $provider->getEntityManager()->flush();

            return 'Bearer ' . static::getContainer()->get('lexik_jwt_authentication.jwt_manager')->create($user);
        };
        [$admin, $support] = [$token(['ROLE_ADMIN']), $token(['ROLE_SUPPORT'])];
        // [ce qui est gardé ou mis en forme, méthode, chemin normal, chemin encodé, en-têtes, statut attendu, code attendu ou null]
        $cases = [
            ['erreur de l\'API en JSON (route inconnue)', 'GET', '/api/route-inconnue-chemin', '/%61pi/route-inconnue-chemin', [], 404, 'NOT_FOUND'],
            ['erreur de validation de la gestion', 'GET', '/api/gestion/statistiques?range=pigeon', '/%61pi/%67estion/statistiques?range=pigeon', ['HTTP_AUTHORIZATION' => $admin], 422, 'VALIDATION_FAILED'],
            // (route que les règles d'accès ouvrent à tout compte connecté : seule la garde de l'Assistance arrête ce compte)
            ['compte Assistance limité à l\'assistance', 'GET', '/api/boussole/company', '/%61pi/boussole/company', ['HTTP_AUTHORIZATION' => $support], 403, 'SUPPORT_SCOPE_ONLY'],
            ['identifiant illisible de Mémoires Vivantes', 'GET', '/api/memoires/books/pas-un-identifiant', '/api/%6demoires/books/pas-un-identifiant', ['HTTP_AUTHORIZATION' => $admin], null, null],
            ['site inconnu sur une route qui répond elle-même', 'GET', '/api/tenant/hosts', '/api/tenant/%68osts', ['HTTP_X_TENANT_HOST' => 'site-inconnu-chemin.example'], null, null],
            ['barre oblique encodée', 'GET', '/api/gestion/statistiques?range=pigeon', '/api%2Fgestion%2Fstatistiques?range=pigeon', ['HTTP_AUTHORIZATION' => $admin], 422, 'VALIDATION_FAILED'],
        ];
        foreach ($cases as [$what, $method, $normal, $encoded, $server, $status, $code]) {
            $a = $this->send($method, $normal, [], $server, null);
            $b = $this->send($method, $encoded, [], $server, null);
            $this->assertSame([$a->getStatusCode(), (string) $a->getContent()], [$b->getStatusCode(), (string) $b->getContent()], "$what : le chemin encodé reçoit la réponse du chemin normal");
            $this->assertNotSame(500, $a->getStatusCode(), $what);
            $this->assertStringStartsWith('application/json', (string) $b->headers->get('Content-Type'), "$what : réponse en JSON");
            if ($status !== null) {
                $this->assertSame([$status, $code], [$b->getStatusCode(), json_decode((string) $b->getContent(), true)['code'] ?? null], $what);
            }
        }

        // Un chemin légitimement encodé (accent, espace dans un slug) atteint sa route comme avant : la garde ne casse rien
        foreach (['/api/products/by-slug/caf%C3%A9-inconnu-chemin', '/api/products/by-slug/mon%20article%20inconnu'] as $path) {
            $r = $this->send('GET', $path, [], [], null);
            $this->assertSame([404, 'Produit introuvable'], [$r->getStatusCode(), json_decode((string) $r->getContent(), true)['error'] ?? null], $path);
        }
        // Un encodage double n'est décodé qu'une fois, comme par le routeur : ce n'est pas /api
        $this->assertSame(404, $this->send('GET', '/%2561pi/profile', [], ['HTTP_AUTHORIZATION' => $admin], null)->getStatusCode());
    }

    public function testAnExcludedPathOfTheCsrfCheckIsRecognisedWhenEncoded(): void
    {
        $email = 'chemin-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Chemin')->setLastname('Encodé')->setRoles(['ROLE_USER_INTERNET'])->setIsVerified(true);
        $user->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD));
        $provider->getEntityManager()->persist($user);
        $provider->getEntityManager()->flush();
        $cookies = [];
        foreach ($this->send('POST', '/api/login', [], [], ['username' => $email, 'password' => self::PASSWORD, 'platform' => 'web'])->headers->getCookies() as $cookie) {
            $cookies[$cookie->getName()] = (string) $cookie->getValue();
        }
        // La déconnexion est exemptée du jeton anti-CSRF (elle doit toujours pouvoir nettoyer les cookies) : encodée ou non, même réponse
        $this->assertSame(200, $this->send('POST', '/api/%6cogout', $cookies, [], [])->getStatusCode(), 'chemin encodé de la déconnexion : exempté comme le chemin normal');
    }

    private function send(string $method, string $path, array $cookies, array $server, ?array $body): Response
    {
        $this->client->getCookieJar()->clear();
        foreach ($cookies as $name => $value) {
            $this->client->getCookieJar()->set(new \Symfony\Component\BrowserKit\Cookie($name, $value, null, '/', MV_TEST_TENANT_HOST, true));
        }
        $this->client->request($method, 'https://' . MV_TEST_TENANT_HOST . $path, [], [], $server + [
            'HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
        ], $body !== null ? json_encode($body) : null);

        return $this->client->getResponse();
    }
}
