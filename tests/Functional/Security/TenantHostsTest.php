<?php

namespace App\Tests\Functional\Security;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Liste publique des hôtes des sites (GET /api/tenant/hosts) et règle resserrée de reconnaissance d'un site par son
 * code (17/10/2026) : code nu, <code>.<frontend>, <code>.<…>.<backend>, <code>.localhost, domaines personnalisés.
 */
class TenantHostsTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    private static function env(string $name): string
    {
        return strtolower(trim((string) ($_SERVER[$name] ?? $_ENV[$name] ?? getenv($name)), " .\"'"));
    }

    public function testTheHostListIsPublicAndHoldsHostsOnly(): void
    {
        $this->client->request('GET', '/api/tenant/hosts');
        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $body = json_decode((string) $response->getContent(), true);
        $this->assertSame(['hosts'], array_keys($body), 'hôtes seulement');
        $this->assertNotEmpty($body['hosts']);
        $this->assertContains(MV_TEST_TENANT_HOST, $body['hosts'], 'domaine personnalisé');
        $this->assertContains(MV_TEST_TENANT_CODE . '.' . self::env('FRONTEND_BASE_DOMAIN'), $body['hosts'], 'sous-domaine du frontend');
        foreach ($body['hosts'] as $host) {
            $this->assertMatchesRegularExpression('/^([a-z0-9-]+\.)+[a-z]{2,}$/', $host);
            $this->assertStringNotContainsString('localhost', $host);
            $this->assertStringStartsNotWith('www.', $host);
        }
        $this->assertSame(array_values(array_unique($body['hosts'])), $body['hosts'], 'sans doublon');
        $this->assertStringContainsString('max-age=60', (string) $response->headers->get('Cache-Control'));
    }

    public function testEveryListedHostResolvesAndTheRuleNoLongerAcceptsAnyDomain(): void
    {
        $this->client->request('GET', '/api/tenant/hosts');
        foreach (json_decode((string) $this->client->getResponse()->getContent(), true)['hosts'] as $host) {
            if (!str_starts_with($host, MV_TEST_TENANT_CODE . '.') && $host !== MV_TEST_TENANT_HOST) {
                continue; // autres sites de la base maître de test : seule la base du tenant de test existe
            }
            $this->client->request('GET', '/api/category', [], [], ['HTTP_X_TENANT_HOST' => $host]);
            $this->assertSame(200, $this->client->getResponse()->getStatusCode(), $host);
        }

        $frontend = self::env('FRONTEND_BASE_DOMAIN');
        $backend = self::env('BACKEND_BASE_DOMAIN');
        foreach ([MV_TEST_TENANT_CODE, MV_TEST_TENANT_CODE . '.localhost:3000', 'www.' . MV_TEST_TENANT_CODE . '.' . $frontend, MV_TEST_TENANT_CODE . '.v2.' . $backend, 'WWW.' . strtoupper(MV_TEST_TENANT_HOST)] as $host) {
            $this->client->request('GET', '/api/category', [], [], ['HTTP_X_TENANT_HOST' => $host]);
            $this->assertSame(200, $this->client->getResponse()->getStatusCode(), $host);
        }

        foreach ([MV_TEST_TENANT_CODE . '.nimportequoi.com', 'www.' . MV_TEST_TENANT_CODE . '.exemple.ca'] as $host) {
            $this->client->request('GET', '/api/category', [], [], ['HTTP_X_TENANT_HOST' => $host]);
            $this->assertSame(404, $this->client->getResponse()->getStatusCode(), $host . ' : le premier segment d\'un domaine quelconque ne vaut plus code');
        }
    }

    public function testTheTenantCheckRouteAgreesWithTheSharedRule(): void
    {
        $frontend = self::env('FRONTEND_BASE_DOMAIN');
        foreach ([MV_TEST_TENANT_HOST, 'www.' . MV_TEST_TENANT_HOST, MV_TEST_TENANT_CODE . '.' . $frontend, MV_TEST_TENANT_CODE . '.localhost:3000'] as $host) {
            $this->client->request('GET', '/api/tenant/check', [], [], ['HTTP_X_TENANT_HOST' => $host]);
            $body = json_decode((string) $this->client->getResponse()->getContent(), true);
            $this->assertSame([200, true, MV_TEST_TENANT_CODE], [$this->client->getResponse()->getStatusCode(), $body['exists'], $body['tenant']['code']], $host);
        }

        $this->client->request('GET', '/api/tenant/check', [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_CODE . '.nimportequoi.com']);
        $this->assertFalse(json_decode((string) $this->client->getResponse()->getContent(), true)['exists'], 'domaine quelconque : pas un site');

        $this->client->request('GET', '/api/tenant/check', [], [], ['HTTP_X_TENANT_HOST' => 'site-a-creer.' . $frontend]);
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
        $this->assertArrayNotHasKey('setup_url', $body, 'sous-domaine libre : plus de renvoi vers la création de site');
    }
}
