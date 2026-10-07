<?php

namespace App\Tests\Functional\LandingPage;

use App\Entity\User;
use App\Services\LandingAiService\LandingAiCatalogue;
use App\Services\LandingSiteModelService\LandingSiteModelService;
use App\Services\TenantEntityManagerProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/** Bibliothèque de modèles de site de l'éditeur des landing pages (/api/landingpage-site-models) */
class LandingSiteModelApiTest extends WebTestCase
{
    private const PASSWORD = 'Mot-de-passe-de-test-1!';

    private KernelBrowser $client;
    private array $session = [];
    /** Tenant des requêtes : [hôte, base, code] */
    private array $tenant = [MV_TEST_TENANT_HOST, MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        foreach ([[MV_TEST_TENANT_HOST, MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE], [MV_TEST_TENANT2_HOST, MV_TEST_TENANT2_DB, MV_TEST_TENANT2_CODE]] as $tenant) {
            $this->tenant = $tenant;
            $this->db()->executeStatement('DELETE FROM landing_site_model');
        }
        $this->tenant = [MV_TEST_TENANT_HOST, MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE];
        $this->session = $this->login(['ROLE_ADMIN', 'ROLE_USER_INTERNET']);
    }

    public function testModelIsSavedListedReadUpdatedAndDeletedWithoutTouchingThePublishedSettings(): void
    {
        $settingsBefore = $this->db()->fetchAllAssociative('SELECT * FROM landing_page_setting ORDER BY id');

        $created = $this->request('POST', '/api/landingpage-site-models', json_encode(['name' => 'Accueil printemps', 'description' => 'Deux onglets', 'configuration' => $this->configuration()], JSON_PRESERVE_ZERO_FRACTION));
        $this->assertSame(201, $created->getStatusCode(), $created->getContent());
        $summary = json_decode($created->getContent(), true);
        $this->assertSame(['id', 'name', 'description', 'createdAt', 'updatedAt', 'tabs', 'sections'], array_keys($summary));
        $this->assertSame(['Accueil printemps', 'Deux onglets', 2, 3], [$summary['name'], $summary['description'], $summary['tabs'], $summary['sections']]);

        $list = json_decode($this->request('GET', '/api/landingpage-site-models')->getContent(), true);
        $this->assertSame([$summary['id']], array_column($list, 'id'));
        $this->assertArrayNotHasKey('configuration', $list[0], 'liste sans configuration');

        $full = $this->request('GET', '/api/landingpage-site-models/' . $summary['id']);
        $this->assertSame(200, $full->getStatusCode());
        $this->assertStringContainsString('"productPageStyle":{}', $full->getContent(), 'un {} reste {}');
        $this->assertStringContainsString('"lineHeight":1.0', $full->getContent(), '1.0 reste un décimal');
        $this->assertEquals(json_decode(json_encode($this->configuration())), json_decode($full->getContent())->configuration);

        $updated = $this->request('PUT', '/api/landingpage-site-models/' . $summary['id'], json_encode(['name' => 'Accueil été', 'description' => null]));
        $this->assertSame(200, $updated->getStatusCode(), $updated->getContent());
        $this->assertSame(['Accueil été', null, 3], array_values(array_intersect_key(json_decode($updated->getContent(), true), array_flip(['name', 'description', 'sections']))));

        $this->assertSame(204, $this->request('DELETE', '/api/landingpage-site-models/' . $summary['id'])->getStatusCode());
        $this->assertSame([], json_decode($this->request('GET', '/api/landingpage-site-models')->getContent(), true));
        $this->assertSame($settingsBefore, $this->db()->fetchAllAssociative('SELECT * FROM landing_page_setting ORDER BY id'), 'réglages publiés intacts');
    }

    public function testInvalidModelIsRefusedWithPathsPrefixedByConfiguration(): void
    {
        $configuration = $this->configuration();
        $text = array_key_first(array_filter($configuration['tabs'][0]['sections'][0]['reglableConfig']['blocks'], fn ($b) => $b['type'] === 'text'));
        $configuration['tabs'][0]['sections'][0]['reglableConfig']['blocks'][$text]['text'] = '<a href="javascript:alert(1)">x</a>';

        $response = $this->request('POST', '/api/landingpage-site-models', json_encode(['name' => 'Piège <b>', 'description' => str_repeat('d', 301), 'configuration' => $configuration, 'couleur' => 'rouge']));

        $this->assertSame(422, $response->getStatusCode(), $response->getContent());
        $paths = array_column(json_decode($response->getContent(), true)['errors'], 'path');
        $this->assertContains('name', $paths);
        $this->assertContains('description', $paths);
        $this->assertContains('couleur', $paths);
        $this->assertContains("configuration.tabs[0].sections[0].reglableConfig.blocks[$text].text", $paths);
        $this->assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM landing_site_model'));

        $this->assertSame(422, $this->request('POST', '/api/landingpage-site-models', json_encode(['name' => 'Sans configuration']))->getStatusCode());
        $this->assertSame(422, $this->request('POST', '/api/landingpage-site-models', json_encode(['name' => 'Liste', 'configuration' => []]))->getStatusCode(), 'objet attendu');
        $this->assertSame(422, $this->request('POST', '/api/landingpage-site-models', json_encode(['name' => str_repeat('n', 81), 'configuration' => $this->configuration()]))->getStatusCode());
    }

    public function testLimitsOnSizeAndNumberOfModels(): void
    {
        $big = $this->configuration();
        $big['footer'] = ['note' => str_repeat('x', LandingSiteModelService::MAX_CONFIGURATION_BYTES)];
        $this->assertSame(413, $this->request('POST', '/api/landingpage-site-models', json_encode(['name' => 'Trop gros', 'configuration' => $big]))->getStatusCode());

        for ($i = 0; $i < LandingSiteModelService::MAX_MODELS; $i++) {
            $this->db()->executeStatement("INSERT INTO landing_site_model (name, configuration, created_at, updated_at) VALUES ('M$i', '{}', NOW(), NOW())");
        }
        $response = $this->request('POST', '/api/landingpage-site-models', json_encode(['name' => 'Un de trop', 'configuration' => $this->configuration()]));
        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('30 modèles au plus', json_decode($response->getContent())->error);
    }

    public function testModelOfAnotherSiteIsNotFoundAndNonAdminsAreRefused(): void
    {
        $id = json_decode($this->request('POST', '/api/landingpage-site-models', json_encode(['name' => 'Site 1', 'configuration' => $this->configuration()]))->getContent())->id;

        $this->tenant = [MV_TEST_TENANT2_HOST, MV_TEST_TENANT2_DB, MV_TEST_TENANT2_CODE];
        $this->session = $this->login(['ROLE_ADMIN', 'ROLE_USER_INTERNET']);
        $this->assertSame(404, $this->request('GET', "/api/landingpage-site-models/$id")->getStatusCode());
        $this->assertSame(404, $this->request('PUT', "/api/landingpage-site-models/$id", json_encode(['name' => 'Volé']))->getStatusCode());
        $this->assertSame(404, $this->request('DELETE', "/api/landingpage-site-models/$id")->getStatusCode());
        $this->assertSame([], json_decode($this->request('GET', '/api/landingpage-site-models')->getContent(), true));

        $this->tenant = [MV_TEST_TENANT_HOST, MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE];
        $this->session = $this->login(['ROLE_USER_INTERNET']);
        $this->assertSame(403, $this->request('GET', '/api/landingpage-site-models')->getStatusCode());
        $this->session = [];
        $this->assertSame(401, $this->request('GET', '/api/landingpage-site-models')->getStatusCode());
        $this->assertSame(401, $this->request('POST', '/api/landingpage-site-models', json_encode(['name' => 'Anonyme', 'configuration' => $this->configuration()]))->getStatusCode());
        $this->assertSame('Site 1', $this->db()->fetchOne('SELECT name FROM landing_site_model WHERE id = ?', [$id]));
    }

    /** Configuration de 2 onglets et 3 sections, dont une réglable valide (modèle du catalogue) */
    private function configuration(): array
    {
        [, $preset] = static::getContainer()->get(LandingAiCatalogue::class)->preset('presentation-type-f');
        $composition = json_decode(json_encode($preset['composition']), true);
        $text = array_key_first(array_filter($composition['blocks'], fn ($b) => in_array($b['type'], ['title', 'text'], true)));
        $composition['blocks'][$text]['lineHeight'] = 1.0;

        return [
            'navbar' => ['componentKey' => 'Navbar'],
            'productPageStyle' => new \stdClass(),
            'tabs' => [
                ['name' => 'Accueil', 'isVisible' => true, 'sections' => [
                    ['id' => 's1', 'componentKey' => 'Presentation', 'componentTypeKey' => 'typeReglable', 'reglableConfig' => $composition],
                    ['id' => 's2', 'componentKey' => 'Contact', 'componentTypeKey' => 'typeA'],
                ]],
                ['name' => 'Services', 'isVisible' => false, 'sections' => [['id' => 's3', 'componentKey' => 'Service', 'componentTypeKey' => 'typeA']]],
            ],
        ];
    }

    private function request(string $method, string $path, ?string $body = null): Response
    {
        $jar = $this->client->getCookieJar();
        $jar->clear();
        foreach ($this->session as $name => $value) {
            $jar->set(new \Symfony\Component\BrowserKit\Cookie($name, $value, null, '/', $this->tenant[0], true));
        }
        $this->client->request($method, 'https://' . $this->tenant[0] . $path, [], [], array_filter([
            'HTTP_X_TENANT_HOST' => $this->tenant[0],
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_XSRF_TOKEN' => $this->session['XSRF-TOKEN_' . $this->tenant[2]] ?? null,
        ]), $body);

        return $this->client->getResponse();
    }

    private function db(): \Doctrine\DBAL\Connection
    {
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant($this->tenant[1], $this->tenant[2]);

        return $provider->getEntityManager()->getConnection();
    }

    /** @return array<string, string> cookies de session */
    private function login(array $roles): array
    {
        $email = 'site-models-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $container = static::getContainer();
        $provider = $container->get(TenantEntityManagerProvider::class);
        $provider->switchTenant($this->tenant[1], $this->tenant[2]);
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Modèles')->setLastname('Site')->setRoles($roles)->setIsVerified(true);
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD));
        $provider->getEntityManager()->persist($user);
        $provider->getEntityManager()->flush();

        $this->client->getCookieJar()->clear();
        $this->client->request('POST', 'https://' . $this->tenant[0] . '/api/login', [], [], [
            'HTTP_X_TENANT_HOST' => $this->tenant[0], 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
        ], json_encode(['username' => $email, 'password' => self::PASSWORD, 'platform' => 'web']));
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());
        $session = [];
        foreach ($this->client->getResponse()->headers->getCookies() as $cookie) {
            $session[$cookie->getName()] = $cookie->getValue();
        }

        return $session;
    }
}
