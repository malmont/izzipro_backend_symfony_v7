<?php

namespace App\Tests\Functional\Boutique;

use App\Services\BoutiqueDemoService\BoutiqueDemoSeeder;
use App\Services\TenantEntityManagerProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Cache HTTP des lectures publiques (09/10/2026) : public + ETag + 304, privé si connecté, jamais sur le panier */
class PublicCacheHeadersTest extends WebTestCase
{
    public function testPublicReadsAreCacheableAndRevalidated(): void
    {
        $client = static::createClient();
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        static::getContainer()->get(BoutiqueDemoSeeder::class)->seed('client-cache@example.invalid', false, false);
        $server = ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST];

        foreach (['/api/products/bestsellers?locale=fr' => 60, '/api/subscription-plans?locale=fr' => 60, '/api/tenant/check' => 300] as $path => $maxAge) {
            $client->getCookieJar()->clear();
            $client->request('GET', 'https://' . MV_TEST_TENANT_HOST . $path, [], [], $server);
            $response = $client->getResponse();
            $this->assertSame(200, $response->getStatusCode(), $path . ' ' . $response->getContent());
            $this->assertTrue($response->headers->hasCacheControlDirective('public'), $path . ' : ' . $response->headers->get('Cache-Control'));
            $this->assertSame((string) $maxAge, (string) $response->headers->getCacheControlDirective('max-age'), $path);
            $this->assertSame('600', (string) $response->headers->getCacheControlDirective('stale-while-revalidate'));
            $this->assertContains('X-Tenant-Host', $response->getVary(), 'jamais la boutique d\'un site servie à un autre');
            $this->assertSame([], $response->headers->getCookies(), 'aucun cookie dans une réponse publique');
            $etag = (string) $response->getEtag();
            $this->assertNotSame('', $etag);

            $client->request('GET', 'https://' . MV_TEST_TENANT_HOST . $path, [], [], $server + ['HTTP_IF_NONE_MATCH' => $etag]);
            $this->assertSame(304, $client->getResponse()->getStatusCode(), $path . ' : revalidation');
            $this->assertSame('', (string) $client->getResponse()->getContent());
        }

        // Connecté (éditeur, administrateur) : revalidé à chaque appel
        $client->getCookieJar()->clear();
        $client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/api/products/bestsellers?locale=fr', [], [], $server + ['HTTP_AUTHORIZATION' => 'Bearer x']);
        $headers = $client->getResponse()->headers;
        $this->assertTrue($headers->hasCacheControlDirective('private') && $headers->hasCacheControlDirective('no-cache'), (string) $headers->get('Cache-Control'));

        // Panier : jamais mis en cache ni marqué d'un ETag
        $client->request('POST', 'https://' . MV_TEST_TENANT_HOST . '/api/cart/quote', [], [], $server + ['CONTENT_TYPE' => 'application/json'], '{"items": []}');
        $this->assertFalse($client->getResponse()->headers->hasCacheControlDirective('public'));
        $this->assertNull($client->getResponse()->getEtag());
    }
}
