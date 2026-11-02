<?php

namespace App\Tests\Functional\Security;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Limites de débit comptées par site (01/11/2026) : par compte (un même courriel sur deux boutiques n'a plus un compteur
 * commun), par site et IP (connexion, réinitialisation, formulaires publics), avec un plafond global par IP seule que
 * personne ne contourne (ni en répartissant sur plusieurs sites, ni en omettant X-Tenant-Host).
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

    public function testPasswordResetCountsPerSiteAndIpWithAGlobalCapThatNoSiteCanBypass(): void
    {
        $ip = '10.81.' . random_int(1, 254) . '.' . random_int(1, 254);
        // password_reset_ip : 20 par heure, par site et IP (courriels distincts : la limite par compte est de 3)
        for ($i = 0; $i < 20; $i++) {
            $this->assertNotSame(429, $this->reset(MV_TEST_TENANT_HOST, $ip, $i), "demande $i sur le premier site");
        }
        $this->assertSame(429, $this->reset(MV_TEST_TENANT_HOST, $ip, 20), 'vingt-et-unième sur le premier site : bloquée (site + IP)');
        // Second site, même IP : compteur propre. Sans X-Tenant-Host, le site vient de l'hôte appelé ; le plafond global, par IP seule,
        // compte ces demandes aussi : 30 par heure en test (60 en production), atteint après 20 + 10
        for ($i = 0; $i < 10; $i++) {
            $this->assertNotSame(429, $this->reset(MV_TEST_TENANT2_HOST, $ip, 100 + $i, withTenantHeader: $i % 2 === 0), "demande $i sur le second site");
        }
        $this->client->getCookieJar()->clear();
        $this->client->request('POST', 'https://' . MV_TEST_TENANT2_HOST . '/api/password-reset/request', [], [], [
            'HTTP_X_TENANT_HOST' => MV_TEST_TENANT2_HOST, 'CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $ip,
        ], json_encode(['email' => 'plafond-' . bin2hex(random_bytes(3)) . '@example.invalid']));
        $response = $this->client->getResponse();
        $this->assertSame(429, $response->getStatusCode(), 'plafond global par IP atteint, réparti sur deux sites (second site à 10 sur 20)');
        $this->assertGreaterThan(0, (int) $response->headers->get('Retry-After'), 'même corps et même en-tête qu\'avant');
        $this->assertStringContainsString('Trop de tentatives', json_decode($response->getContent(), true)['error']);
    }

    public function testAPublicFormCounterIsSeparatePerSite(): void
    {
        $ip = '10.82.' . random_int(1, 254) . '.' . random_int(1, 254);
        // public_form_ip : 10 envois acceptés par 10 minutes, par site et IP
        for ($i = 0; $i < 10; $i++) {
            $this->assertSame(201, $this->contact(MV_TEST_TENANT_HOST, $ip, $i), "envoi $i sur le premier site");
        }
        $this->assertSame(429, $this->contact(MV_TEST_TENANT_HOST, $ip, 10), 'onzième sur le premier site : bloqué');
        $this->assertSame(201, $this->contact(MV_TEST_TENANT2_HOST, $ip, 11), 'même IP sur le second site : accepté');
    }

    private function reset(string $host, string $ip, int $n, bool $withTenantHeader = true): int
    {
        $this->client->getCookieJar()->clear();
        $this->client->request('POST', 'https://' . $host . '/api/password-reset/request', [], [], array_filter([
            'HTTP_X_TENANT_HOST' => $withTenantHeader ? $host : null, 'CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $ip,
        ]), json_encode(['email' => "inconnu-$n-" . bin2hex(random_bytes(2)) . '@example.invalid']));

        return $this->client->getResponse()->getStatusCode();
    }

    private function contact(string $host, string $ip, int $n): int
    {
        $this->client->getCookieJar()->clear();
        $this->client->request('POST', 'https://' . $host . '/api/contacts/create', [], [], [
            'HTTP_X_TENANT_HOST' => $host, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'REMOTE_ADDR' => $ip,
        ], json_encode(['name' => 'Jo Test', 'email' => 'jo@example.com', 'message' => "Message $n"]));

        return $this->client->getResponse()->getStatusCode();
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
