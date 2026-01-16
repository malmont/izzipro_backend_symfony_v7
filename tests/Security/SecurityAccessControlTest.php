<?php

namespace App\Tests\Security;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

class SecurityAccessControlTest extends WebTestCase
{
    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite') && !in_array('sqlite', \PDO::getAvailableDrivers())) {
            $this->markTestSkipped('This test requires the pdo_sqlite extension (DATABASE_URL=sqlite:///:memory:).');
        }
    }
    /**
     * Vérifie que les routes publiques définies dans security.yaml sont bien accessibles sans authentification.
     * 
     * @dataProvider getPublicUrls
     */
    public function testPublicUrlsAreAccessible(string $method, string $url): void
    {
        $client = static::createClient();

        // On effectue la requête
        $client->request($method, $url);

        // Pour une route publique, on ne doit JAMAIS recevoir une 401 (Unauthorized) ou 403 (Forbidden).
        // On accepte 200 (OK), 404 (Not Found - si la route n'est pas encore codée), 400 (Bad Request), etc.
        // L'important est que le "garde" de sécurité ne nous bloque pas à l'entrée.
        $this->assertNotContains(
            $client->getResponse()->getStatusCode(),
            [Response::HTTP_UNAUTHORIZED, Response::HTTP_FORBIDDEN],
            sprintf('La route publique "%s" (%s) est bloquée par la sécurité (Code %d).', $url, $method, $client->getResponse()->getStatusCode())
        );
    }

    /**
     * Vérifie que les routes admin protégées renvoient bien une 401 pour un utilisateur anonyme.
     * 
     * @dataProvider getAdminUrls
     */
    public function testAdminUrlsAreProtected(string $method, string $url): void
    {
        $client = static::createClient();

        // On effectue la requête en tant qu'anonyme
        $client->request($method, $url);

        // On s'attend à être bloqué immédiatement
        $this->assertResponseStatusCodeSame(
            Response::HTTP_UNAUTHORIZED,
            sprintf('La route admin "%s" (%s) devrait être protégée mais a laissé passer l\'anonyme (Code %d).', $url, $method, $client->getResponse()->getStatusCode())
        );
    }

    /**
     * Vérifie la règle spécifique des admin-settings : GET public, mais POST protégé.
     */
    public function testAdminSettingsMixedAccess(): void
    {
        $client = static::createClient();

        // 1. GET doit passer (PUBLIC_ACCESS)
        $client->request('GET', '/api/admin-settings');
        $this->assertNotContains(
            $client->getResponse()->getStatusCode(),
            [Response::HTTP_UNAUTHORIZED, Response::HTTP_FORBIDDEN],
            'Le GET sur /api/admin-settings devrait être public.'
        );

        // 2. POST doit bloquer (ROLE_ADMIN)
        $client->request('POST', '/api/admin-settings');
        $this->assertResponseStatusCodeSame(
            Response::HTTP_UNAUTHORIZED,
            'Le POST sur /api/admin-settings devrait être protégé.'
        );
    }

    // --- Data Providers basés sur ton security.yaml ---

    public function getPublicUrls(): \Generator
    {
        // Liste extraite de ton access_control "PUBLIC_ACCESS"
        yield 'Login' => ['POST', '/api/login'];
        yield 'Register' => ['POST', '/api/register'];
        yield 'Products' => ['GET', '/api/products'];
        yield 'Categories' => ['GET', '/api/category'];
        yield 'Home Slider' => ['GET', '/api/homeslider'];
        yield 'Webhooks' => ['POST', '/api/webhooks/gemsuite']; // Souvent POST pour les webhooks
        yield 'Newsletter' => ['POST', '/api/newsletter/subscribe'];
    }

    public function getAdminUrls(): \Generator
    {
        // Liste extraite de ton access_control "ROLE_ADMIN"
        yield 'Dashboard' => ['GET', '/api/dashboard'];
        yield 'Collections' => ['GET', '/api/collections'];
        yield 'Commandes' => ['GET', '/api/commandes'];
        yield 'Statistiques' => ['GET', '/api/statistiques'];
        yield 'Stock Evolution' => ['GET', '/api/stock-evolution'];
        yield 'Caisse' => ['GET', '/api/caisse'];
        yield 'Taxes Monthly' => ['GET', '/api/taxes/monthly'];
    }
}
