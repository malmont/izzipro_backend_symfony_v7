<?php

namespace App\Tests\Functional\Admin;

use App\Entity\User;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Code court dans EasyAdmin (31/10/2026) : lecture seule pour le marchand, régénération par le super-administrateur seulement */
class ShortCodeAdminTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->setServerParameter('HTTP_X_TENANT_HOST', MV_TEST_TENANT_HOST);
    }

    protected function tearDown(): void
    {
        // Le site de test retrouve son code fixe
        $manager = static::getContainer()->get(TenantConnectionManager::class);
        $manager->getPdoMaster()->prepare('UPDATE tenants SET short_code = ? WHERE code = ?')->execute(['MVT001', MV_TEST_TENANT_CODE]);
        static::getContainer()->get('cache.app')->invalidateTags(['tenants']);
        parent::tearDown();
    }

    private function login(array $roles): void
    {
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $email = 'short-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Code')->setLastname('Court')->setRoles($roles)->setIsVerified(true)->setPassword('x');
        $provider->getEntityManager()->persist($user);
        $provider->getEntityManager()->flush();
        $this->client->loginUser($user, 'main');
    }

    public function testTheMerchantSeesTheCodeReadOnlyAndOnlyTheOwnerRegeneratesIt(): void
    {
        $this->login(['ROLE_ADMIN']);
        $id = (int) static::getContainer()->get('doctrine')->getConnection()->fetchOne('SELECT MIN(id) FROM entreprise');
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin?' . http_build_query(['crudAction' => 'edit', 'crudControllerFqcn' => 'App\Controller\Admin\EntrepriseCrudController', 'entityId' => $id]));
        $html = html_entity_decode((string) $this->client->getResponse()->getContent());
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertStringContainsString('MVT-001', $html, 'code affiché dans la fiche Entreprise');
        $this->assertStringContainsString('À imprimer sur vos reçus et vos affiches : vos clients le tapent dans l\'application', $html);

        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin/short-code');
        $html = (string) $this->client->getResponse()->getContent();
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertStringContainsString('MVT-001', $html);
        $this->assertStringNotContainsString('Régénérer le code', $html, 'pas de bouton pour un administrateur de site');
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . '/admin/short-code', ['_token' => 'x']);
        $this->assertSame(403, $this->client->getResponse()->getStatusCode(), 'régénération refusée à un administrateur de site');

        $this->login(['ROLE_ADMIN', 'ROLE_SUPER_ADMIN']);
        $crawler = $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin/short-code');
        $this->assertStringContainsString('ne fonctionnera plus', (string) $this->client->getResponse()->getContent(), 'confirmation qui prévient');
        $token = $crawler->filter('input[name="_token"]')->attr('value');
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . '/admin/short-code', ['_token' => 'faux']);
        $this->assertStringContainsString('Jeton de sécurité invalide', (string) $this->client->getResponse()->getContent());
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . '/admin/short-code', ['_token' => $token]);
        $this->assertSame(302, $this->client->getResponse()->getStatusCode());
        $manager = static::getContainer()->get(TenantConnectionManager::class);
        $new = $manager->shortCodeOf(MV_TEST_TENANT_CODE);
        $this->assertNotSame('MVT001', $new);
        $this->client->followRedirect();
        $this->assertStringContainsString(substr($new, 0, 3) . '-' . substr($new, 3), (string) $this->client->getResponse()->getContent());
        $this->assertStringContainsString('MVT-001 ne fonctionne plus', (string) $this->client->getResponse()->getContent());
        // L'ancien code ne résout plus, le nouveau si
        $this->client->getCookieJar()->clear();
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/api/tenant/check-mobile', [], [], ['HTTP_X_TENANT_HOST' => 'MVT-001', 'REMOTE_ADDR' => '10.201.1.1']);
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/api/tenant/check-mobile', [], [], ['HTTP_X_TENANT_HOST' => $new, 'REMOTE_ADDR' => '10.201.1.1']);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertSame($new, json_decode($this->client->getResponse()->getContent(), true)['tenant']['shortCode']);
    }
}
