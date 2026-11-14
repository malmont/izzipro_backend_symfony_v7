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
 * appelées avec les jetons du biographe, du responsable des biographes (14/11/2026) et d'un client : hors des préfixes autorisés, même
 * réponse. Dans Mémoires Vivantes, le responsable est servi par la liste de l'équipe et la console Équipe, le biographe non ; les deux
 * sont refusés sur les écrans réservés à l'administrateur (tarifs, quotas, revenus). Modèle : SupportRoleMatrixTest.
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

    public function testTheTeamRolesGiveNothingMoreThanAClientOutsideMemoiresVivantes(): void
    {
        $biographer = $this->account([TeamRoles::MEMOIRES_BIOGRAPHE, 'ROLE_USER_INTERNET'], 'biographe');
        $manager = $this->account([TeamRoles::MEMOIRES_RESPONSABLE, 'ROLE_USER_INTERNET'], 'responsable');
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
            $asClient = $this->status($method, $path, $client);
            foreach (['biographe' => $biographer, 'responsable' => $manager] as $who => $token) {
                $asTeam = $this->status($method, $path, $token);
                if ($asTeam !== $asClient) {
                    $violations[] = "$method $path ($name) : $who $asTeam, client $asClient";
                }
            }
        }
        $this->assertSame([], $violations, 'hors de Mémoires Vivantes et des rendez-vous, ni le biographe ni le responsable des biographes n\'ont rien de plus qu\'un client');

        // Dans Mémoires Vivantes (14/11/2026) : le responsable des biographes a l'équipe et la console Équipe ; le biographe, non ; les écrans
        // de l'administrateur (tarifs, quotas, revenus) restent à l'administrateur ; EasyAdmin est fermé aux deux
        $expected = ['/api/memoires/team' => [403, 200], '/api/memoires/admin/users' => [403, 200], '/api/memoires/admin/pricing' => [403, 403], '/api/memoires/admin/ai-quotas' => [403, 403],
            '/api/memoires/admin/revenue' => [403, 403], '/api/reservations' => [200, 200], '/api/memoires/books' => [200, 200]];
        foreach ($expected as $path => [$forBiographer, $forManager]) {
            $this->assertSame([$forBiographer, $forManager], [$this->status('GET', $path, $biographer), $this->status('GET', $path, $manager)], $path);
        }
        $this->assertSame([403, 403], [$this->status('GET', '/api/memoires/team', $client), $this->status('GET', '/api/memoires/admin/users', $client)]);
        foreach ([$biographer, $manager] as $token) {
            $this->assertContains($this->status('GET', '/admin', $token), [302, 401, 403], 'EasyAdmin exige ROLE_ADMIN');
        }
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
