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
    /**
     * Routes de son propre compte, ouvertes à tout utilisateur connecté (lecture et suppression de soi-même). Lot AG-3a (16/11/2026) :
     * ses CONTEXTES (son mode, son site, ses deux écrans d'assistance) : la seule route que la garde lui ouvre hors de son module, par
     * son chemin exact ; ce qu'elle lui rend est vérifié par `tests/Functional/Screens/MeContextsApiTest.php`.
     */
    private const OWN_ACCOUNT = ['GET /api/me/contexts'];

    private KernelBrowser $client;

    /** Code d'erreur du corps de la dernière réponse lue par `status()`, ou null */
    private ?string $lastCode = null;

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
        $sameAsAnonymous = 0;
        foreach ($routes as [$method, $path, $name]) {
            $support = $this->status($method, $path, $token);
            if (str_starts_with($path, '/api/support')) {
                // Réservé à l'administrateur du site : les réglages en écriture, et les agents (inviter, relancer, retirer : lot SUPPORT-WEB)
                $adminOnly = ($method === 'PUT' && $path === '/api/support/settings') || in_array($name, ['support_agent_invite', 'support_agent_invitation', 'support_agent_revoke'], true);
                if (in_array($support, [401, 403], true) && !$adminOnly) {
                    $violations[] = "$method $path ($name) : module refusé ($support)";
                }
                if ($adminOnly && $support !== 403) {
                    $violations[] = "$method $path ($name) : réservé à l'administrateur, servi au compte Assistance ($support)";
                }
                ++$served;
                continue;
            }
            if ($support === 403 && $this->lastCode !== 'SUPPORT_SCOPE_ONLY') {
                // Un refus hors du module porte TOUJOURS le code de la garde, que ce soit elle ou la règle d'accès du pare-feu qui refuse.
                // Seule exception : une route publique qui refuse aussi un anonyme, avec le même code (caisse sans poste : TERMINAL_REQUIRED)
                $code = $this->lastCode;
                if ([$this->status($method, $path, null), $this->lastCode] !== [403, $code] || $code === null) {
                    $violations[] = "$method $path ($name) : refus sans le code SUPPORT_SCOPE_ONLY (" . ($code ?? 'aucun code') . ')';
                }
                ++$sameAsAnonymous;
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
        $this->assertLessThanOrEqual(4, $sameAsAnonymous, 'les refus sans le code de la garde restent une poignée de routes publiques');
        $this->assertSame(15, $served, 'les 15 routes du module (8 du lot 1 ; lot SUPPORT-WEB : moi, agents, commande par référence, inviter, relancer, retirer ; lot INC-3 : transmettre à l\'équipe technique)');
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
            $this->lastCode = null;

            return 500;
        }
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->lastCode = is_array($body) && is_string($body['code'] ?? null) ? $body['code'] : null;

        return $this->client->getResponse()->getStatusCode();
    }
}
