<?php

namespace App\Tests\Functional\Boutique;

use App\Entity\User;
use App\Services\TenantEntityManagerProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Tableau de bord d'une commande d'achat (application de gestion) : une commande sans produit, donc sans budget utilisé, répondait
 * 500 (division par zéro dans le taux de marque, constatée le 03/11/2026). Elle répond 200 avec des taux à 0 ; une commande avec
 * produits garde ses calculs.
 */
class CommandeDashboardApiTest extends WebTestCase
{
    private const PASSWORD = 'Mot-de-passe-de-test-1!';
    private KernelBrowser $client;
    private \Doctrine\DBAL\Connection $db;
    private ?string $token = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $this->db = $provider->getEntityManager()->getConnection();
    }

    public function testACommandeWithoutProductsAnswers200WithZeroRates(): void
    {
        $id = $this->insertCommande(1200, 'Achat sans produit');
        $this->loginAs(['ROLE_ADMIN', 'ROLE_USER_INTERNET']);

        $response = $this->request('GET', "/api/dashboard/commande/$id");
        $this->assertSame(200, $response->getStatusCode(), $response->getContent());
        $body = json_decode((string) $response->getContent(), true);
        $this->assertSame(0, (int) $body['Statistics']['itemCount']);
        $this->assertSame(0.0, (float) $body['TauxMarge']['tauxMarque'], 'taux de marque à 0 sans budget utilisé');
        $this->assertSame(0.0, (float) $body['TauxMarge']['tauxMarge']);
        $this->assertSame(1200.0, (float) $body['BudgetGeneral']['generalBudget']);
        $this->assertSame(0.0, (float) $body['averageMultiplier']);
    }

    public function testTheDashboardStaysReservedToAdministrators(): void
    {
        $id = $this->insertCommande(50, 'Achat');
        $this->token = null;
        $this->assertSame(401, $this->request('GET', "/api/dashboard/commande/$id")->getStatusCode());
        $this->loginAs(['ROLE_USER_POS'], 'pos');
        $this->assertSame(403, $this->request('GET', "/api/dashboard/commande/$id")->getStatusCode());
    }

    /** Identifiant posé à la main (pas de valeur par défaut sur toutes les bases) et séquence réalignée : les autres tests créent des commandes par Doctrine */
    private function insertCommande(float $budget, string $name): int
    {
        $id = (int) $this->db->fetchOne("INSERT INTO commande (id, budget, date, name, is_closed) VALUES ((SELECT COALESCE(MAX(id), 0) + 1 FROM commande), ?, NOW(), ?, false) RETURNING id", [$budget, $name]);
        $this->db->executeStatement("SELECT setval('commande_id_seq', (SELECT MAX(id) FROM commande))");

        return $id;
    }

    private function request(string $method, string $path): \Symfony\Component\HttpFoundation\Response
    {
        $this->client->request($method, 'https://' . MV_TEST_TENANT_HOST . $path, [], [], array_filter(['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => $this->token ? 'Bearer ' . $this->token : null]));

        return $this->client->getResponse();
    }

    private function loginAs(array $roles, string $platform = 'admin'): void
    {
        $container = static::getContainer();
        $provider = $container->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $email = 'dashboard-achat-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Achat')->setLastname('Test')->setRoles($roles)->setIsVerified(true);
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD));
        $provider->getEntityManager()->persist($user);
        $provider->getEntityManager()->flush();
        $this->token = null;
        $login = $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . '/api/login', [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], json_encode(['username' => $email, 'password' => self::PASSWORD, 'platform' => $platform]));
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());
        $this->token = json_decode((string) $this->client->getResponse()->getContent(), true)['token'];
    }
}
