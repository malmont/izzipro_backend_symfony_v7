<?php

namespace App\Tests\Functional\Security;

use App\Entity\User;
use App\Services\TenantEntityManagerProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Lot COST-1 (16/11/2026) : un caissier lit les statistiques de la CAISSE, jamais celles du site entier. Avant ce lot, un saut de ligne
 * collé à « pos » (`…/pos%0A`) passait la règle d'accès de la caisse (un `$` accepte un saut de ligne final), puis arrivait au
 * contrôleur comme une source inconnue, lue comme « toutes les sources ».
 */
class PosStatisticsScopeTest extends WebTestCase
{
    private const ROUTES = ['/api/statistiques/chiffre-affaires/', '/api/statistiques/nombre-commandes/', '/api/statistiques/panier-moyen/', '/api/payments/statistics/'];

    private KernelBrowser $client;
    private string $tag;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->tag = bin2hex(random_bytes(3));
    }

    protected function tearDown(): void
    {
        $this->em()->getConnection()->executeStatement("DELETE FROM \"user\" WHERE email LIKE 'stats-caisse-%@example.invalid'");
        parent::tearDown();
    }

    public function testACashierReadsTheTillStatisticsAndNeverThoseOfTheWholeSite(): void
    {
        $cashier = $this->token('caissier', ['ROLE_USER_POS']);
        $admin = $this->token('admin', ['ROLE_ADMIN']);
        foreach (self::ROUTES as $route) {
            // sa source, la caisse : lue
            $this->assertSame(200, $this->status($route . 'pos', $cashier), $route . 'pos');
            // toute autre source, ou aucune : refusée au caissier, lue par l'administrateur
            foreach (['ecommerce', 'mobile_app', 'toutes'] as $source) {
                $this->assertSame(403, $this->status($route . $source, $cashier), "$route$source pour un caissier");
                $this->assertSame(200, $this->status($route . $source, $admin), "$route$source pour l'administrateur");
            }
            // LE CONTOURNEMENT : « pos » suivi d'un saut de ligne, d'un retour chariot, d'une espace ou d'un octet nul, n'est une source pour personne
            foreach (['pos%0A', 'pos%0D%0A', 'pos%20', 'pos%00', 'pos%0Aecommerce', '%0Apos'] as $forged) {
                $this->assertContains($this->status($route . $forged, $cashier), [403, 404], "$route$forged pour un caissier : jamais lu");
                $this->assertNotSame(200, $this->status($route . $forged, $admin), "$route$forged : pas une source");
            }
        }
        $this->assertSame(403, $this->status('/api/statistiques/nombre-commandes', $cashier), 'sans source : toutes les sources, donc l\'administrateur seul');
        $this->assertSame(200, $this->status('/api/statistiques/nombre-commandes', $admin));
    }

    private function status(string $path, string $token): int
    {
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . $path, [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $token]);

        return $this->client->getResponse()->getStatusCode();
    }

    private function token(string $name, array $roles): string
    {
        $em = $this->em();
        $email = "stats-caisse-$name-{$this->tag}@example.invalid";
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Stats')->setLastname($name)->setRoles($roles)->setPassword('inutilisable')->setIsVerified(true);
        $em->persist($user);
        $em->flush();

        return static::getContainer()->get('lexik_jwt_authentication.jwt_manager')->create($user);
    }

    private function em(): \Doctrine\ORM\EntityManagerInterface
    {
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);

        return $provider->getEntityManager();
    }
}
