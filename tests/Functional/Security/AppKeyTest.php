<?php

namespace App\Tests\Functional\Security;

use App\Entity\User;
use App\Services\AppKeyService\AppKeyService;
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
            'app' => 'pos', 'label' => 'Tablette comptoir 1', 'expiresAt' => null, 'cashRegister' => null,
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

        // Clé POS rattachée à un poste de caisse (16/10/2026) : copié sur la clé, rendu à l'activation
        $register = (int) static::getContainer()->get(TenantEntityManagerProvider::class)->getEntityManager()->getConnection()->fetchOne('SELECT MIN(id) FROM cash_register WHERE is_active = true');
        $registerName = (string) static::getContainer()->get(TenantEntityManagerProvider::class)->getEntityManager()->getConnection()->fetchOne('SELECT name FROM cash_register WHERE id = ?', [$register]);
        $bound = json_decode($this->request('POST', '/api/app-keys', json_encode(['app' => 'pos', 'label' => 'Tablette poste', 'sendEmail' => false, 'cashRegisterId' => $register]))->getContent(), true);
        $this->assertSame(['id' => $register, 'name' => $registerName], $bound['cashRegister'], json_encode($bound));
        $activated = json_decode($this->activate(['key' => $bound['key'], 'app' => 'pos'])->getContent(), true);
        $this->assertSame(['id' => $register, 'name' => $registerName], $activated['cashRegister'], 'le POS présélectionne ce poste');
        $this->assertNull(json_decode($this->activate(['key' => $created['key'] ?? 'x', 'app' => 'mobile'])->getContent(), true)['cashRegister'] ?? null, 'clé sans poste : null');
        $unknownRegister = $this->request('POST', '/api/app-keys', json_encode(['app' => 'pos', 'label' => 'x', 'sendEmail' => false, 'cashRegisterId' => 999999]));
        $this->assertSame(422, $unknownRegister->getStatusCode());
        $this->assertSame('cashRegisterId', json_decode($unknownRegister->getContent(), true)['errors'][0]['path']);
        $notPos = $this->request('POST', '/api/app-keys', json_encode(['app' => 'admin', 'label' => 'x', 'sendEmail' => false, 'cashRegisterId' => $register]));
        $this->assertSame(422, $notPos->getStatusCode(), 'seule une clé pos a un poste');
        $updated = $this->request('PUT', '/api/app-keys/' . $bound['id'], json_encode(['label' => 'Tablette poste bis', 'cashRegisterId' => null]));
        $this->assertSame(200, $updated->getStatusCode(), $updated->getContent());
        $this->assertSame(['Tablette poste bis', null], [json_decode($updated->getContent(), true)['label'], json_decode($updated->getContent(), true)['cashRegister']]);
        $this->assertSame($register, json_decode($this->request('PUT', '/api/app-keys/' . $bound['id'], json_encode(['cashRegisterId' => $register]))->getContent(), true)['cashRegister']['id']);
        $this->assertSame(404, $this->request('PUT', '/api/app-keys/999999', json_encode(['label' => 'x']))->getStatusCode());

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

    public function testALivingMemoryKeyServesAnyNumberOfDevices(): void
    {
        // Application grand public Living Memory (15/10/2026) : une seule clé livingmemory embarquée dans l'application,
        // donc la même clé sur tous les téléphones : aucune limite d'appareils, deviceName = dernier appareil vu
        $this->loginAs(['ROLE_ADMIN'], 'admin');
        $created = json_decode($this->request('POST', '/api/app-keys', json_encode(['app' => 'livingmemory', 'label' => 'Living Memory — essais', 'sendEmail' => false]))->getContent(), true);
        $this->assertStringStartsWith('IZ-LMV-', $created['key']);
        foreach (['Pixel 8 de Jeanne', 'iPhone 15 de Marc'] as $device) {
            $activated = $this->activate(['key' => $created['key'], 'app' => 'livingmemory', 'deviceName' => $device]);
            $this->assertSame(200, $activated->getStatusCode(), $activated->getContent());
            $body = json_decode($activated->getContent(), true);
            $this->assertSame(['code' => MV_TEST_TENANT_CODE, 'name' => 'Tenant de test', 'host' => MV_TEST_TENANT_HOST], $body['tenant']);
            $this->assertSame(['livingmemory', 'Living Memory — essais', null, null], [$body['app'], $body['label'], $body['expiresAt'], $body['cashRegister']]);
        }
        $row = array_column(json_decode($this->request('GET', '/api/app-keys')->getContent(), true), null, 'id')[$created['id']];
        $this->assertSame([2, 'iPhone 15 de Marc'], [$row['useCount'], $row['lastDevice']], 'compteur d\'activations et dernier appareil');
        $this->assertSame('Living Memory — essais', $row['label'], 'le libellé reste celui de la clé');

        $wrongApp = $this->activate(['key' => $created['key'], 'app' => 'mobile']);
        $this->assertSame(403, $wrongApp->getStatusCode());
        $this->assertSame('APP_KEY_WRONG_APP', json_decode($wrongApp->getContent(), true)['error']);
        $this->assertSame(403, $this->activate(['key' => $created['key'], 'app' => 'pos'])->getStatusCode());
        $this->assertSame(403, $this->activate(['key' => $created['key'], 'app' => 'admin'])->getStatusCode());
        $pos = json_decode($this->request('POST', '/api/app-keys', json_encode(['app' => 'pos', 'label' => 'Caisse', 'sendEmail' => false]))->getContent(), true);
        $this->assertSame(403, $this->activate(['key' => $pos['key'], 'app' => 'livingmemory'])->getStatusCode(), 'et inversement : une clé pos ne sert pas à Living Memory');

        // Site sans domaine propre (cas de demo) : l'hôte rendu est <code>.<FRONTEND_BASE_DOMAIN>
        $master = static::getContainer()->get(TenantConnectionManager::class)->getPdoMaster();
        $master->prepare('INSERT INTO tenants (code, name, dbname, custom_domain) VALUES (?, ?, ?, NULL)')->execute(['mvtestpublic', 'Site sans domaine propre', MV_TEST_TENANT_DB]);
        try {
            $public = static::getContainer()->get(AppKeyService::class)->generate('mvtestpublic', 'livingmemory', 'Living Memory — application publique', 'test', null);
            $activated = $this->activate(['key' => $public['key'], 'app' => 'livingmemory', 'deviceName' => 'Téléphone']);
            $this->assertSame(200, $activated->getStatusCode(), $activated->getContent());
            $this->assertSame(['code' => 'mvtestpublic', 'name' => 'Site sans domaine propre', 'host' => 'mvtestpublic.' . ($_SERVER['FRONTEND_BASE_DOMAIN'] ?? getenv('FRONTEND_BASE_DOMAIN'))], json_decode($activated->getContent(), true)['tenant']);
        } finally {
            $master->prepare('DELETE FROM tenant_app_keys WHERE tenant_code = ?')->execute(['mvtestpublic']);
            $master->prepare('DELETE FROM tenants WHERE code = ?')->execute(['mvtestpublic']);
        }
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
