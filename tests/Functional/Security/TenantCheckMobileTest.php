<?php

namespace App\Tests\Functional\Security;

use App\Services\TenantConnectionManager;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /api/tenant/check-mobile (15/10/2026) : choix du site par les applications mobiles. Saisie (code nu ou domaine)
 * dans X-Tenant-Host, réponse avec l'hôte complet à envoyer ensuite (domaine propre, sinon code.domaine de base).
 * Non-régression : GET /api/tenant/check (frontend web) répond exactement comme avant.
 */
class TenantCheckMobileTest extends WebTestCase
{
    private KernelBrowser $client;
    private string $baseDomain;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->baseDomain = static::getContainer()->getParameter('kernel.environment') ? (string) ($_SERVER['FRONTEND_BASE_DOMAIN'] ?? $_ENV['FRONTEND_BASE_DOMAIN'] ?? getenv('FRONTEND_BASE_DOMAIN')) : '';
        $this->assertNotEmpty($this->baseDomain, 'FRONTEND_BASE_DOMAIN défini');
    }

    public function testKnownCodeSubdomainAndCustomDomainResolveToTheFullHost(): void
    {
        $manager = static::getContainer()->get(TenantConnectionManager::class);
        // Site avec domaine propre : l'hôte renvoyé est ce domaine, quelle que soit la saisie
        foreach ([MV_TEST_TENANT_CODE, MV_TEST_TENANT_CODE . '.' . $this->baseDomain, MV_TEST_TENANT_HOST] as $input) {
            $response = $this->check($input);
            $this->assertSame(200, $response->getStatusCode(), "$input : " . $response->getContent());
            $body = json_decode($response->getContent(), true);
            $this->assertTrue($body['exists']);
            $this->assertSame(['code' => MV_TEST_TENANT_CODE, 'name' => 'Tenant de test', 'host' => MV_TEST_TENANT_HOST], $body['tenant'], "$input");
            $this->assertSame(MV_TEST_TENANT_CODE, $manager->findTenantConfigByHost($body['tenant']['host'])?->getCode(), 'le même hôte résout bien le même site pour les appels suivants');
            $this->assertStringNotContainsString('max-age=300', (string) $response->headers->get('Cache-Control'), 'pas le cache public de tenant/check');
        }

        // Site sans domaine propre : hôte = code.domaine de base du frontend
        $code = 'mvtest-mobile-' . bin2hex(random_bytes(2));
        $pdo = $manager->getPdoMaster();
        $pdo->prepare('INSERT INTO tenants (code, name, dbname, custom_domain) VALUES (?, ?, ?, NULL)')->execute([$code, 'Site mobile', MV_TEST_TENANT_DB]);
        try {
            $body = json_decode($this->check($code)->getContent(), true);
            $this->assertSame(['code' => $code, 'name' => 'Site mobile', 'host' => $code . '.' . $this->baseDomain], $body['tenant'] ?? null, json_encode($body));
            $this->assertSame($code, $manager->findTenantConfigByHost($body['tenant']['host'])?->getCode());
        } finally {
            $pdo->prepare('DELETE FROM tenants WHERE code = ?')->execute([$code]);
        }
    }

    public function testUnknownCodeAndMissingHeader(): void
    {
        $notFound = $this->check('site-inconnu-' . bin2hex(random_bytes(2)));
        $this->assertSame(404, $notFound->getStatusCode());
        $this->assertSame(['exists' => false, 'error' => 'TENANT_NOT_FOUND', 'message' => 'Boutique introuvable'], json_decode($notFound->getContent(), true));

        foreach ([null, '', '   '] as $missing) {
            $response = $this->check($missing);
            $this->assertSame(400, $response->getStatusCode());
            $this->assertSame('TENANT_HOST_MISSING', json_decode($response->getContent(), true)['error']);
        }
    }

    public function testTheWebCheckRouteIsUnchanged(): void
    {
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/api/tenant/check', [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'HTTP_ACCEPT' => 'application/json']);
        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode(), $response->getContent());
        $this->assertSame(['exists' => true, 'status' => 'found', 'action' => 'login', 'tenant' => ['code' => MV_TEST_TENANT_CODE, 'name' => 'Tenant de test']], json_decode($response->getContent(), true));

        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/api/tenant/check', [], [], ['HTTP_X_TENANT_HOST' => 'inconnu-' . bin2hex(random_bytes(2)) . '.' . $this->baseDomain, 'HTTP_ACCEPT' => 'application/json']);
        $body = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['exists' => false, 'status' => 'not_found', 'message' => "Ce site n'existe pas."], $body, 'plus de renvoi vers la création de site (17/10/2026)');
    }

    private function check(?string $host): Response
    {
        $this->client->getCookieJar()->clear();
        $server = ['HTTP_ACCEPT' => 'application/json'];
        if ($host !== null) {
            $server['HTTP_X_TENANT_HOST'] = $host;
        }
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/api/tenant/check-mobile', [], [], $server);

        return $this->client->getResponse();
    }
}
