<?php

namespace App\Tests\Functional\Security;

use App\Entity\User;
use App\Security\TeamRoles;
use App\Services\TenantEntityManagerProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Équipe de Mémoires Vivantes (13/11/2026) : un compte biographe (ROLE_MEMOIRES_BIOGRAPHE + ROLE_USER_INTERNET) n'obtient rien de plus
 * qu'un client en ligne (ROLE_USER_INTERNET) hors de Mémoires Vivantes et des rendez-vous. Toutes les routes de l'API du routeur sont
 * appelées avec les deux jetons : hors des préfixes autorisés, même réponse. Dans Mémoires Vivantes, le biographe est servi par la liste
 * de l'équipe et la console des utilisateurs, et refusé sur les écrans réservés à l'administrateur (tarifs). Modèle : SupportRoleMatrixTest.
 */
class MemoiresBiographeRoleMatrixTest extends WebTestCase
{
    /** Préfixes où le biographe a, par contrat, plus de droits qu'un client */
    private const TEAM_PREFIXES = ['/api/memoires/', '/api/reservations'];

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        static::getContainer()->get(TenantEntityManagerProvider::class)->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
    }

    public function testABiographerGetsNothingMoreThanAClientOutsideMemoiresVivantes(): void
    {
        $biographer = $this->account([TeamRoles::MEMOIRES_BIOGRAPHE, 'ROLE_USER_INTERNET'], 'biographe');
        $client = $this->account(['ROLE_USER_INTERNET'], 'client');
        $routes = [];
        foreach (static::getContainer()->get('router')->getRouteCollection() as $name => $route) {
            if (!str_starts_with($route->getPath(), '/api/')) {
                continue;
            }
            foreach ($route->getMethods() ?: ['GET'] as $method) {
                if (in_array($method, ['HEAD', 'OPTIONS'], true)) {
                    continue;
                }
                $path = preg_replace('#\{[^}]+\}#', '1', $route->getPath());
                $routes[$method . ' ' . $path] = [$method, $path, $name];
            }
        }
        $this->assertGreaterThan(500, count($routes));
        $violations = [];
        foreach ($routes as [$method, $path, $name]) {
            if ($this->underTeamPrefix($path) || in_array($method, ['DELETE'], true)) {
                continue; // les suppressions ne sont pas rejouées à l'aveugle : leur garde est celle de ROLE_ADMIN, vérifiée par les autres tests
            }
            $asBiographer = $this->status($method, $path, $biographer);
            $asClient = $this->status($method, $path, $client);
            if ($asBiographer !== $asClient) {
                $violations[] = "$method $path ($name) : biographe $asBiographer, client $asClient";
            }
        }
        $this->assertSame([], $violations, 'hors de Mémoires Vivantes et des rendez-vous, le biographe n\'a rien de plus qu\'un client');

        $this->assertSame(200, $this->status('GET', '/api/memoires/team', $biographer), 'liste de l\'équipe');
        $this->assertSame(403, $this->status('GET', '/api/memoires/team', $client));
        $this->assertSame(200, $this->status('GET', '/api/memoires/admin/users', $biographer), 'console des utilisateurs');
        $this->assertSame(403, $this->status('GET', '/api/memoires/admin/users', $client));
        $this->assertSame(403, $this->status('GET', '/api/memoires/admin/pricing', $biographer), 'les tarifs restent à l\'administrateur');
        $this->assertSame(200, $this->status('GET', '/api/reservations', $biographer), 'les rendez-vous de l\'entreprise');
    }

    private function underTeamPrefix(string $path): bool
    {
        foreach (self::TEAM_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function account(array $roles, string $prefix): string
    {
        $em = static::getContainer()->get(TenantEntityManagerProvider::class)->getEntityManager();
        $email = 'matrice-' . $prefix . '-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Matrice')->setLastname(ucfirst($prefix))->setRoles($roles)->setIsVerified(true)->setPassword('x');
        $em->persist($user);
        $em->flush();

        return static::getContainer()->get('lexik_jwt_authentication.jwt_manager')->create($user);
    }

    private function status(string $method, string $path, string $token): int
    {
        $this->client->getCookieJar()->clear();
        try {
            $this->client->request($method, 'https://' . MV_TEST_TENANT_HOST . $path, [], [], [
                'HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'HTTP_X_XSRF_TOKEN' => 'jeton-xsrf-de-test', 'REMOTE_ADDR' => '10.78.' . random_int(1, 254) . '.' . random_int(1, 254),
            ], in_array($method, ['POST', 'PUT', 'PATCH'], true) ? '{}' : null);

            return $this->client->getResponse()->getStatusCode();
        } catch (\Throwable) {
            return 500;
        }
    }
}
