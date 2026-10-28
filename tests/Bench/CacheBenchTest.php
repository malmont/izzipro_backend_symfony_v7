<?php

namespace App\Tests\Bench;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Banc de mesure (28/10/2026, lot 6) : temps de réponse médian de lectures publiques servies par le cache applicatif,
 * sur la base de test uniquement. Lancé à la main (`phpunit tests/Bench/CacheBenchTest.php`), hors de la suite (dossier tests/Bench).
 *
 * @group bench
 */
class CacheBenchTest extends WebTestCase
{
    public function testMedianResponseTimes(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $server = ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST];
        $routes = ['/api/presentations?locale=fr', '/api/presentation-groups?locale=fr', '/api/products/bestsellers?locale=fr',
            '/api/products/by-category?locale=fr', '/api/boutique-settings', '/api/landingpage-settings', '/api/entreprise/1?locale=fr'];
        $lines = [];
        foreach ($routes as $route) {
            $client->request('GET', $route, [], [], $server);
            $this->assertSame(200, $client->getResponse()->getStatusCode(), $route);
            $cold = $this->time($client, $route, $server, 1)[0];
            $warm = $this->time($client, $route, $server, 20);
            sort($warm);
            $lines[] = sprintf('%-45s froid %6.1f ms   chaud médiane %6.1f ms   min %6.1f ms', $route, $cold, $warm[intdiv(count($warm), 2)], $warm[0]);
        }
        fwrite(STDERR, "\n" . implode("\n", $lines) . "\n");
        $this->assertNotEmpty($lines);
    }

    /** @return list<float> millisecondes par requête */
    private function time($client, string $route, array $server, int $count): array
    {
        $times = [];
        for ($i = 0; $i < $count; $i++) {
            $start = microtime(true);
            $client->request('GET', $route, [], [], $server);
            $times[] = (microtime(true) - $start) * 1000;
        }

        return $times;
    }
}
