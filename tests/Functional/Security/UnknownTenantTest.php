<?php

namespace App\Tests\Functional\Security;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Site demandé mais inconnu (17/10/2026) : 404 TENANT_NOT_FOUND avant toute lecture, au lieu de servir la base maître
 * (robots sur des sous-domaines au hasard). Les routes de vérification gardent leur propre réponse ; le domaine du
 * backend et les requêtes sans site ne changent pas.
 */
class UnknownTenantTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testAnUnknownTenantHeaderIsRejectedBeforeAnyRead(): void
    {
        foreach (['idp.arkanoa-media.com', 'notexists987.inconnu.test', 'inconnu.localhost:3000'] as $host) {
            $this->client->request('GET', '/api/category', [], [], ['HTTP_X_TENANT_HOST' => $host]);
            $this->assertSame(404, $this->client->getResponse()->getStatusCode(), $host);
            $this->assertSame(['error' => 'TENANT_NOT_FOUND', 'message' => 'Site inconnu'], json_decode($this->client->getResponse()->getContent(), true), $host);
        }
    }

    public function testAnUnknownTenantParameterIsRejected(): void
    {
        $this->client->request('GET', '/api/category?tenant=site-inconnu');
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
        $this->assertSame('TENANT_NOT_FOUND', json_decode($this->client->getResponse()->getContent(), true)['error']);
    }

    public function testAKnownTenantIsServed(): void
    {
        $this->client->request('GET', '/api/category', [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
    }

    public function testCheckRoutesKeepTheirOwnAnswer(): void
    {
        $this->client->request('GET', '/api/tenant/check-mobile', [], [], ['HTTP_X_TENANT_HOST' => 'site-inconnu']);
        $this->assertSame(['exists' => false, 'error' => 'TENANT_NOT_FOUND', 'message' => 'Boutique introuvable'], json_decode($this->client->getResponse()->getContent(), true));

        $this->client->request('GET', '/api/tenant/check', [], [], ['HTTP_X_TENANT_HOST' => 'site-inconnu.inconnu.test']);
        $this->assertNotSame(['error' => 'TENANT_NOT_FOUND', 'message' => 'Site inconnu'], json_decode($this->client->getResponse()->getContent(), true));
    }

    public function testTheBackendDomainAndRequestsWithoutTenantAreUnchanged(): void
    {
        $backend = (string) ($_SERVER['BACKEND_BASE_DOMAIN'] ?? getenv('BACKEND_BASE_DOMAIN'));
        $this->assertNotSame('', $backend);
        foreach ([$backend, 'v2.' . $backend] as $host) {
            $this->client->request('GET', '/api/category', [], [], ['HTTP_X_TENANT_HOST' => $host]);
            $this->assertNotSame('TENANT_NOT_FOUND', json_decode((string) $this->client->getResponse()->getContent(), true)['error'] ?? null, $host);
        }

        $this->client->request('GET', '/api/category');
        $this->assertNotSame('TENANT_NOT_FOUND', json_decode((string) $this->client->getResponse()->getContent(), true)['error'] ?? null, 'sans site');
    }
}
