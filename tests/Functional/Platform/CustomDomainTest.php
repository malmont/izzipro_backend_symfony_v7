<?php

namespace App\Tests\Functional\Platform;

use App\Entity\User;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Domaine propre du site (05/11/2026) : enregistrement dans la base maître (annuaire des hôtes), validation, unicité, procédure DNS,
 * vérifications (un domaine .test ne résout jamais : DNS en attente), étape du cheminement et entrée du registre.
 */
class CustomDomainTest extends WebTestCase
{
    private KernelBrowser $client;
    /** hôte envoyé en X-Tenant-Host : le site de test n'est joignable que par son domaine propre, qui change pendant le test */
    private string $host = MV_TEST_TENANT_HOST;
    private \PDO $master;
    private ?string $domainBefore;
    private ?string $domain2Before;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        static::getContainer()->get(TenantEntityManagerProvider::class)->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $this->master = static::getContainer()->get(TenantConnectionManager::class)->getPdoMaster();
        $this->domainBefore = $this->current(MV_TEST_TENANT_CODE);
        $this->domain2Before = $this->current(MV_TEST_TENANT2_CODE);
    }

    protected function tearDown(): void
    {
        $tenants = static::getContainer()->get(TenantConnectionManager::class);
        $tenants->setCustomDomain(MV_TEST_TENANT_CODE, $this->domainBefore);
        $tenants->setCustomDomain(MV_TEST_TENANT2_CODE, $this->domain2Before);
        parent::tearDown();
    }

    public function testRegisterVerifyAndRemoveACustomDomain(): void
    {
        $this->assertSame(401, $this->api('GET', '/api/site/domain', null, null)->getStatusCode());
        $this->assertSame(403, $this->api('GET', '/api/site/domain', null, 'client')->getStatusCode());
        // le site de test est joignable par son domaine propre mvtest.test (bootstrap) : il ne résout jamais, donc « DNS en attente »
        $body = json_decode((string) $this->api('GET', '/api/site/domain', null, 'admin')->getContent(), true);
        $this->assertSame([MV_TEST_TENANT_HOST, 'dns_pending', MV_TEST_TENANT_CODE . '.'], [$body['domain'], $body['state'], substr($body['platformHost'], 0, strlen(MV_TEST_TENANT_CODE) + 1)]);
        $this->assertSame(5, count($body['instructions']), 'procédure en cinq étapes');
        $this->assertStringContainsString('registraire', $body['instructions'][0]['detail']);

        foreach (['pas un domaine', 'http://', 'demo.' . $body['platformHost'], 'sans-tld', 'a..b.com'] as $bad) {
            $r = $this->api('PUT', '/api/site/domain', ['domain' => $bad], 'admin');
            $this->assertSame(422, $r->getStatusCode(), $bad . ' : ' . $r->getContent());
            $this->assertSame('DOMAIN_INVALID', json_decode((string) $r->getContent(), true)['code']);
        }
        $r = $this->api('PUT', '/api/site/domain', ['domain' => 'https://WWW.Ma-Boutique-Test.test/chemin'], 'admin');
        $this->assertSame(200, $r->getStatusCode(), $r->getContent());
        $body = json_decode((string) $r->getContent(), true);
        $this->assertSame(['ma-boutique-test.test', 'dns_pending', false], [$body['domain'], $body['state'], $body['checks']['dns']['ok']], 'normalisé, enregistré, DNS pas encore bon (.test ne résout jamais)');
        $this->assertSame('ma-boutique-test.test', $this->current(MV_TEST_TENANT_CODE), 'écrit dans la base maître : l\'annuaire des hôtes le sert');
        $this->host = 'ma-boutique-test.test'; // la résolution hôte → site suit aussitôt le nouveau domaine (cache des sites invalidé)
        $this->assertFalse($body['checks']['https']['checked'], 'pas de test HTTPS tant que le DNS ne pointe pas');
        $this->assertNotEmpty($body['expected']['ips'], 'adresses du serveur des sites');
        $this->assertStringContainsString($body['expected']['ips'][0], $body['instructions'][2]['detail']);

        // unicité entre sites
        static::getContainer()->get(TenantEntityManagerProvider::class)->switchTenant(MV_TEST_TENANT2_DB, MV_TEST_TENANT2_CODE);
        static::getContainer()->get(TenantConnectionManager::class)->setCustomDomain(MV_TEST_TENANT2_CODE, 'autre-site.test');
        static::getContainer()->get(TenantEntityManagerProvider::class)->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $r = $this->api('PUT', '/api/site/domain', ['domain' => 'www.autre-site.test'], 'admin');
        $this->assertSame(409, $r->getStatusCode(), $r->getContent());
        $this->assertSame('DOMAIN_TAKEN', json_decode((string) $r->getContent(), true)['code']);
        $this->assertSame(200, $this->api('PUT', '/api/site/domain', ['domain' => 'ma-boutique-test.test'], 'admin')->getStatusCode(), 'son propre domaine : accepté');

        // cheminement et registre
        $steps = array_column(json_decode((string) $this->api('GET', '/api/site/readiness', null, 'admin')->getContent(), true)['steps'], null, 'key');
        $this->assertSame(['todo', 'domain'], [$steps['site.domain']['status'], $steps['site.domain']['action']['type']]);
        $features = array_column(json_decode((string) $this->api('GET', '/api/site/features', null, 'admin')->getContent(), true)['features'], null, 'key');
        $this->assertSame(['ma-boutique-test.test', 'incomplete', 'dns_pending'], [$features['site.domain']['value'], $features['site.domain']['status'], $features['site.domain']['details']['state']]);

        $this->assertSame('dns_pending', json_decode((string) $this->api('POST', '/api/site/domain/check', null, 'admin')->getContent(), true)['state']);
        $r = $this->api('DELETE', '/api/site/domain', null, 'admin');
        $this->assertSame(['none', null], [json_decode((string) $r->getContent(), true)['state'], $this->current(MV_TEST_TENANT_CODE)]);
        $this->host = $body['platformHost']; // sans domaine propre, le site répond sur <code>.<FRONTEND_BASE_DOMAIN>
        $steps = array_column(json_decode((string) $this->api('GET', '/api/site/readiness', null, 'admin')->getContent(), true)['steps'], null, 'key');
        $this->assertSame('optional', $steps['site.domain']['status'], 'sans domaine : facultatif');
    }

    private function current(string $code): ?string
    {
        $stmt = $this->master->prepare('SELECT custom_domain FROM tenants WHERE code = ?');
        $stmt->execute([$code]);
        $v = $stmt->fetchColumn();

        return is_string($v) && $v !== '' ? $v : null;
    }

    private function api(string $method, string $path, ?array $body, ?string $as): \Symfony\Component\HttpFoundation\Response
    {
        $server = ['HTTP_X_TENANT_HOST' => $this->host, 'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json', 'HTTP_X_XSRF_TOKEN' => 'jeton-xsrf-de-test'];
        if ($as !== null) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer ' . $this->token($as);
        }
        $this->client->request($method, 'https://' . $this->host . $path, [], [], $server, $body !== null ? json_encode($body) : null);

        return $this->client->getResponse();
    }

    private function token(string $as): string
    {
        $container = static::getContainer();
        $container->get(TenantEntityManagerProvider::class)->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $em = $container->get(TenantEntityManagerProvider::class)->getEntityManager();
        $email = $as === 'admin' ? 'admin@arkanoa-media.com' : 'client-domaine@example.invalid';
        $user = $em->getRepository(User::class)->findOneBy(['email' => $email]);
        if (!$user) {
            $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Client')->setLastname('Test')->setRoles(['ROLE_USER_INTERNET'])->setPassword('inutilisable')->setIsVerified(true);
            $em->persist($user);
            $em->flush();
        }

        return $container->get('lexik_jwt_authentication.jwt_manager')->create($user);
    }
}
