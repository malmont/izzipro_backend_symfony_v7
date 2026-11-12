<?php

namespace App\Tests\Functional\Support;

use App\Entity\User;
use App\Services\TenantEntityManagerProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Assistance, lot 1 (décision du propriétaire du 11/11/2026) : un compte ROLE_SUPPORT seul n'ouvre QUE le module. Toutes les routes de l'API du
 * routeur sont appelées sans jeton puis avec un jeton ROLE_SUPPORT : hors /api/support, le compte Assistance n'obtient jamais plus qu'un visiteur
 * anonyme (même réponse, ou refus 401 / 403 / 429) ; sur /api/support, il est servi. Rien de la gestion, des prix, de la trésorerie, des réglages,
 * des commandes ni de la caisse.
 */
class SupportRoleMatrixTest extends WebTestCase
{
    /** Routes de son propre compte, ouvertes à tout utilisateur connecté (lecture et suppression de soi-même) */
    private const OWN_ACCOUNT = [];

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        static::getContainer()->get(TenantEntityManagerProvider::class)->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
    }

    public function testASupportAccountGetsNothingOutsideTheSupportModule(): void
    {
        $em = static::getContainer()->get(TenantEntityManagerProvider::class)->getEntityManager();
        $email = 'matrice-assistance-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $agent = (new User())->setEmail($email)->setUsername($email)->setFirstname('Matrice')->setLastname('Assistance')->setRoles(['ROLE_SUPPORT'])->setIsVerified(true)->setPassword('x');
        $em->persist($agent);
        $em->flush();
        $token = static::getContainer()->get('lexik_jwt_authentication.jwt_manager')->create($agent);
        $routes = [];
        foreach (static::getContainer()->get('router')->getRouteCollection() as $name => $route) {
            if (!str_starts_with($route->getPath(), '/api/')) {
                continue;
            }
            foreach ($route->getMethods() ?: ['GET'] as $method) {
                if (in_array($method, ['HEAD', 'OPTIONS'], true)) {
                    continue;
                }
                $routes[$method . ' ' . preg_replace('#\{[^}]+\}#', '1', $route->getPath())] = [$method, preg_replace('#\{[^}]+\}#', '1', $route->getPath()), $name];
            }
        }
        $this->assertGreaterThan(500, count($routes));
        $violations = [];
        $served = 0;
        foreach ($routes as [$method, $path, $name]) {
            $support = $this->status($method, $path, $token);
            if (str_starts_with($path, '/api/support')) {
                if (in_array($support, [401, 403], true) && !($method === 'PUT' && $path === '/api/support/settings')) {
                    $violations[] = "$method $path ($name) : module refusé ($support)";
                }
                ++$served;
                continue;
            }
            if (in_array($support, [401, 403, 429], true) || in_array("$method $path", self::OWN_ACCOUNT, true)) {
                continue;
            }
            $anonymous = $this->status($method, $path, null);
            if ($support !== $anonymous) {
                $violations[] = "$method $path ($name) : Assistance $support, anonyme $anonymous";
            }
        }
        $this->assertSame([], $violations, 'ROLE_SUPPORT n\'obtient rien de plus qu\'un anonyme hors du module');
        $this->assertSame(8, $served, 'les 8 routes du module');
    }

    private function status(string $method, string $path, ?string $token): int
    {
        $this->client->getCookieJar()->clear();
        try {
            $this->client->request($method, 'https://' . MV_TEST_TENANT_HOST . $path, [], [], array_filter([
                'HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
                'HTTP_AUTHORIZATION' => $token ? 'Bearer ' . $token : null, 'REMOTE_ADDR' => '10.77.' . random_int(1, 254) . '.' . random_int(1, 254),
            ]), in_array($method, ['POST', 'PUT', 'PATCH'], true) ? '{}' : null);
        } catch (\Throwable) {
            // une route qui plante (données laissées par d'autres tests) compte comme une 500, pour l'Assistance comme pour l'anonyme
            return 500;
        }

        return $this->client->getResponse()->getStatusCode();
    }
}
