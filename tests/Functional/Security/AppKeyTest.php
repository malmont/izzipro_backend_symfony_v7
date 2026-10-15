<?php

namespace App\Tests\Functional\Security;

use App\Entity\User;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Clés d'activation des applications mobiles (15/10/2026) : un administrateur génère une clé (affichée une fois,
 * envoyée par e-mail), l'application s'active avec (POST /api/app/activate, sans X-Tenant-Host) et reçoit le site ;
 * révocation, expiration, application, isolation entre sites.
 */
class AppKeyTest extends WebTestCase
{
    private const PASSWORD = 'Mot-de-passe-de-test-1!';
    private KernelBrowser $client;
    private ?string $token = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $provider->getEntityManager()->getConnection()->executeStatement("UPDATE entreprise SET email = 'contact@mvtest.test'");
    }

    public function testAnAdminGeneratesAKeyAndTheAppActivatesWithIt(): void
    {
        $this->loginAs(['ROLE_ADMIN'], 'admin');
        $created = $this->request('POST', '/api/app-keys', json_encode(['app' => 'pos', 'label' => 'Tablette comptoir 1']));
        $this->assertSame(201, $created->getStatusCode(), $created->getContent());
        $body = json_decode($created->getContent(), true);
        $this->assertMatchesRegularExpression('/^IZ-POS-[A-Z2-9]{4}-[A-Z2-9]{4}-[A-Z2-9]{4}$/', $body['key']);
        $this->assertStringStartsWith('IZ-POS-', $body['hint']);
        $this->assertSame('contact@mvtest.test', $body['emailSentTo']);
        $this->assertEmailCount(1);
        $this->assertStringContainsString($body['key'], $this->getMailerMessage()->getHtmlBody());
        $this->assertContains('cle-activation-pos.png', array_map(fn ($a) => $a->getFilename(), $this->getMailerMessage()->getAttachments()), 'code QR joint en PNG');
        $key = $body['key'];
        $id = $body['id'];

        $list = array_column(json_decode($this->request('GET', '/api/app-keys')->getContent(), true), null, 'id');
        $this->assertArrayHasKey($id, $list);
        $this->assertArrayNotHasKey('key', $list[$id], 'la clé en clair n\'est jamais relue');
        $this->assertTrue($list[$id]['isActive']);

        // Activation : sans X-Tenant-Host ni jeton, saisie tolérante (minuscules, sans tirets)
        $activated = $this->activate(['key' => strtolower(str_replace('-', ' ', $key)), 'app' => 'pos', 'deviceName' => 'Tablette Android']);
        $this->assertSame(200, $activated->getStatusCode(), $activated->getContent());
        $this->assertSame([
            'tenant' => ['code' => MV_TEST_TENANT_CODE, 'name' => 'Tenant de test', 'host' => MV_TEST_TENANT_HOST],
            'app' => 'pos', 'label' => 'Tablette comptoir 1', 'expiresAt' => null,
        ], json_decode($activated->getContent(), true));
        $this->assertSame(MV_TEST_TENANT_CODE, static::getContainer()->get(TenantConnectionManager::class)->findTenantConfigByHost(MV_TEST_TENANT_HOST)?->getCode(), 'l\'hôte rendu résout bien le site');

        $wrongApp = $this->activate(['key' => $key, 'app' => 'mobile']);
        $this->assertSame(403, $wrongApp->getStatusCode());
        $this->assertSame('APP_KEY_WRONG_APP', json_decode($wrongApp->getContent(), true)['error']);
        $unknown = $this->activate(['key' => 'IZ-POS-AAAA-BBBB-CCCC', 'app' => 'pos']);
        $this->assertSame(404, $unknown->getStatusCode());
        $this->assertSame('APP_KEY_INVALID', json_decode($unknown->getContent(), true)['error']);
        $this->assertSame('APP_KEY_MISSING', json_decode($this->activate(['key' => '   '])->getContent(), true)['error']);
        $this->assertSame(400, $this->activate(null)->getStatusCode());

        $list = array_column(json_decode($this->request('GET', '/api/app-keys')->getContent(), true), null, 'id');
        $this->assertSame([1, 'Tablette Android'], [$list[$id]['useCount'], $list[$id]['lastDevice']]);
        $this->assertNotNull($list[$id]['lastUsedAt']);

        $revoked = $this->request('DELETE', "/api/app-keys/$id");
        $this->assertSame(200, $revoked->getStatusCode(), $revoked->getContent());
        $this->assertNotNull(json_decode($revoked->getContent(), true)['revokedAt']);
        $this->assertSame(404, $this->activate(['key' => $key, 'app' => 'pos'])->getStatusCode(), 'clé révoquée');
        $this->assertSame(404, $this->request('DELETE', '/api/app-keys/999999')->getStatusCode());
    }

    public function testExpiryValidationAndRoles(): void
    {
        $this->loginAs(['ROLE_ADMIN'], 'admin');
        $invalid = $this->request('POST', '/api/app-keys', json_encode(['app' => 'toto', 'label' => '']));
        $this->assertSame(422, $invalid->getStatusCode());
        $this->assertSame(['app', 'label'], array_column(json_decode($invalid->getContent(), true)['errors'], 'path'));

        $living = json_decode($this->request('POST', '/api/app-keys', json_encode(['app' => 'livingmemory', 'label' => 'Téléphone LivingMemory', 'sendEmail' => false]))->getContent(), true);
        $this->assertStringStartsWith('IZ-LMV-', $living['key'], 'quatrième application : LivingMemory');
        $this->assertSame('livingmemory', json_decode($this->activate(['key' => $living['key'], 'app' => 'livingmemory'])->getContent(), true)['app']);

        $created = json_decode($this->request('POST', '/api/app-keys', json_encode(['app' => 'mobile', 'label' => 'Téléphone test', 'expiresInDays' => 30, 'sendEmail' => false]))->getContent(), true);
        $this->assertNotNull($created['expiresAt']);
        $this->assertNull($created['emailSentTo']);
        $this->assertEmailCount(0);
        $this->assertSame(200, $this->activate(['key' => $created['key'], 'app' => 'mobile'])->getStatusCode());
        static::getContainer()->get(TenantConnectionManager::class)->getPdoMaster()
            ->prepare("UPDATE tenant_app_keys SET expires_at = NOW() - INTERVAL '1 day' WHERE id = ?")->execute([$created['id']]);
        $expired = $this->activate(['key' => $created['key'], 'app' => 'mobile']);
        $this->assertSame(404, $expired->getStatusCode(), 'clé expirée');
        $this->assertFalse(array_column(json_decode($this->request('GET', '/api/app-keys')->getContent(), true), null, 'id')[$created['id']]['isActive']);

        $this->loginAs(['ROLE_USER_POS'], 'pos');
        $this->assertSame(403, $this->request('POST', '/api/app-keys', json_encode(['app' => 'pos', 'label' => 'x']))->getStatusCode(), 'caissier : pas de génération');
        $this->assertSame(403, $this->request('GET', '/api/app-keys')->getStatusCode());
    }

    public function testKeysAreIsolatedPerSite(): void
    {
        $this->loginAs(['ROLE_ADMIN'], 'admin');
        $created = json_decode($this->request('POST', '/api/app-keys', json_encode(['app' => 'admin', 'label' => 'Gestion', 'sendEmail' => false]))->getContent(), true);
        $this->assertContains($created['id'], array_column(json_decode($this->request('GET', '/api/app-keys')->getContent(), true), 'id'));

        $this->loginAs(['ROLE_ADMIN'], 'admin', MV_TEST_TENANT2_DB, MV_TEST_TENANT2_CODE, MV_TEST_TENANT2_HOST);
        $other = json_decode($this->request('GET', '/api/app-keys', null, MV_TEST_TENANT2_HOST)->getContent(), true);
        $this->assertNotContains($created['id'], array_column($other, 'id'), 'les clés d\'un site sont invisibles depuis un autre');
        $this->assertSame(404, $this->request('DELETE', '/api/app-keys/' . $created['id'], null, MV_TEST_TENANT2_HOST)->getStatusCode(), 'ni révocables');
        $this->assertSame(MV_TEST_TENANT_CODE, json_decode($this->activate(['key' => $created['key'], 'app' => 'admin'])->getContent(), true)['tenant']['code'], 'la clé mène à son site');
    }

    private function activate(?array $body): Response
    {
        $this->client->getCookieJar()->clear();
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . '/api/app/activate', [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], $body !== null ? json_encode($body) : '{');

        return $this->client->getResponse();
    }

    private function request(string $method, string $path, ?string $body = null, string $host = MV_TEST_TENANT_HOST): Response
    {
        $this->client->getCookieJar()->clear();
        $this->client->request($method, 'https://' . $host . $path, [], [], array_filter([
            'HTTP_X_TENANT_HOST' => $host, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => $this->token ? 'Bearer ' . $this->token : null,
        ]), $body);

        return $this->client->getResponse();
    }

    private function loginAs(array $roles, string $platform, string $db = MV_TEST_TENANT_DB, string $code = MV_TEST_TENANT_CODE, string $host = MV_TEST_TENANT_HOST): void
    {
        $email = 'cles-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $container = static::getContainer();
        $provider = $container->get(TenantEntityManagerProvider::class);
        $provider->switchTenant($db, $code);
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Cles')->setLastname('Test')->setRoles($roles)->setIsVerified(true);
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD));
        $provider->getEntityManager()->persist($user);
        $provider->getEntityManager()->flush();
        $this->token = null;
        $login = $this->request('POST', '/api/login', json_encode(['username' => $email, 'password' => self::PASSWORD, 'platform' => $platform]), $host);
        $this->assertSame(200, $login->getStatusCode(), $login->getContent());
        $this->token = json_decode($login->getContent(), true)['token'];
    }
}
