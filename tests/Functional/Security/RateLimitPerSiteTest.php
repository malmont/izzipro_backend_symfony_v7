<?php

namespace App\Tests\Functional\Security;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Limites de débit par compte comptées par site (01/11/2026) : un même courriel sur deux boutiques du même backend n'a plus
 * un compteur commun (avant, la clé était l'hôte du backend). Les limites par IP restent globales.
 */
class RateLimitPerSiteTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testTheLoginCounterOfAnAccountIsSeparateBetweenTwoSites(): void
    {
        $email = 'partage-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $ip = '10.80.' . random_int(1, 254) . '.' . random_int(1, 254);
        // api_login_account : 10 essais par 15 minutes et par compte
        for ($i = 0; $i < 10; $i++) {
            $this->assertSame(401, $this->login(MV_TEST_TENANT_HOST, $email, $ip), "essai $i sur le premier site");
        }
        $this->assertSame(429, $this->login(MV_TEST_TENANT_HOST, $email, $ip), 'onzième essai sur le premier site : bloqué');
        $this->assertSame(401, $this->login(MV_TEST_TENANT2_HOST, $email, $ip), 'même courriel sur le second site : compteur distinct');
    }

    private function login(string $host, string $email, string $ip): int
    {
        $this->client->getCookieJar()->clear();
        $this->client->request('POST', 'https://' . $host . '/api/login', [], [], [
            'HTTP_X_TENANT_HOST' => $host, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'REMOTE_ADDR' => $ip,
        ], json_encode(['username' => $email, 'password' => 'faux-mot-de-passe', 'platform' => 'mobile']));

        return $this->client->getResponse()->getStatusCode();
    }
}
