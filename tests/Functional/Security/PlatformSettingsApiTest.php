<?php

namespace App\Tests\Functional\Security;

use App\Entity\User;
use App\Services\Platform\PlatformModeService;
use App\Services\TenantEntityManagerProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Mode de la plateforme (console de pilotage, 04/11/2026) : demo par défaut, réglé par le seul super administrateur, validé */
class PlatformSettingsApiTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        static::getContainer()->get(TenantEntityManagerProvider::class)->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
    }

    protected function tearDown(): void
    {
        static::getContainer()->get(PlatformModeService::class)->setMode('demo', 'test');
        parent::tearDown();
    }

    public function testDemoByDefaultAndSuperAdminOnly(): void
    {
        $this->assertSame('demo', static::getContainer()->get(PlatformModeService::class)->mode());
        $this->assertSame(401, $this->call('GET', null, null)->getStatusCode());
        $this->assertSame(403, $this->call('GET', null, 'admin')->getStatusCode(), 'administrateur de site : refusé');
        $r = $this->call('GET', null, 'super');
        $this->assertSame(200, $r->getStatusCode(), $r->getContent());
        $this->assertSame('demo', json_decode((string) $r->getContent(), true)['mode']);
        $this->assertSame(422, $this->call('PUT', ['mode' => 'prod'], 'super')->getStatusCode());
        $r = $this->call('PUT', ['mode' => 'live'], 'super');
        $this->assertSame(200, $r->getStatusCode(), $r->getContent());
        $this->assertSame('live', json_decode((string) $r->getContent(), true)['mode']);
        $this->assertSame('live', static::getContainer()->get(PlatformModeService::class)->mode(), 'cache vidé à l\'écriture');
        $this->assertSame(403, $this->call('PUT', ['mode' => 'demo'], 'admin')->getStatusCode());
    }

    private function call(string $method, ?array $body, ?string $as): \Symfony\Component\HttpFoundation\Response
    {
        $server = ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json', 'HTTP_X_XSRF_TOKEN' => 'jeton-xsrf-de-test'];
        if ($as !== null) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer ' . $this->token($as);
        }
        $this->client->request($method, 'https://' . MV_TEST_TENANT_HOST . '/api/platform/settings', [], [], $server, $body !== null ? json_encode($body) : null);

        return $this->client->getResponse();
    }

    private function token(string $as): string
    {
        $container = static::getContainer();
        $container->get(TenantEntityManagerProvider::class)->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $em = $container->get(TenantEntityManagerProvider::class)->getEntityManager();
        $email = $as === 'super' ? 'super-admin-platform@example.invalid' : 'admin@arkanoa-media.com';
        $user = $em->getRepository(User::class)->findOneBy(['email' => $email]);
        if (!$user) {
            $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Super')->setLastname('Test')->setRoles(['ROLE_ADMIN', 'ROLE_SUPER_ADMIN'])->setPassword('inutilisable')->setIsVerified(true);
            $em->persist($user);
            $em->flush();
        }

        return $container->get('lexik_jwt_authentication.jwt_manager')->create($user);
    }
}
