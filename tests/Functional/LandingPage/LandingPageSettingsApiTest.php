<?php

namespace App\Tests\Functional\LandingPage;

use App\Entity\User;
use App\Services\TenantEntityManagerProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * PUT / GET /api/landingpage-settings : validation à l'écriture, restitution fidèle à la lecture.
 */
class LandingPageSettingsApiTest extends WebTestCase
{
    private const PASSWORD = 'Mot-de-passe-de-test-1!';

    private KernelBrowser $client;
    private array $session = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testValidConfigurationIsStoredAndReturnedUnchanged(): void
    {
        $this->loginAsAdmin();
        $body = $this->configurationJson('{"id": "titre", "type": "title", "parentId": null, "text": "Bienvenue", "lineHeight": 1.0, "bindings": {}, "translations": {}}');

        $put = $this->request('PUT', $body);
        $this->assertSame(200, $put->getStatusCode(), $put->getContent());

        $get = $this->request('GET');
        $this->assertSame(200, $get->getStatusCode());
        // Même document JSON : ordre des clés, objets vides {}, 1.0, sections et champs hors compositions
        $this->assertSame(
            json_encode(json_decode($body, false)->configuration, JSON_PRESERVE_ZERO_FRACTION),
            json_encode(json_decode($get->getContent(), false), JSON_PRESERVE_ZERO_FRACTION)
        );
    }

    public function testInvalidConfigurationIsRefusedWithPathsAndNotStored(): void
    {
        $this->loginAsAdmin();
        $this->assertSame(200, $this->request('PUT', $this->configurationJson('{"id": "titre", "type": "title", "text": "Avant"}'))->getStatusCode());
        $before = $this->request('GET')->getContent();

        $put = $this->request('PUT', $this->configurationJson('{"id": "titre", "type": "title", "text": "Après", "fontColor": "#ffffff"}'));

        $this->assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $put->getStatusCode(), $put->getContent());
        $payload = json_decode($put->getContent(), true);
        $this->assertContains(
            ['path' => 'tabs[0].sections[1].reglableConfig.blocks[0].fontColor', 'message' => 'propriété inconnue'],
            $payload['errors']
        );
        $this->assertStringContainsString('tabs[0].sections[1].reglableConfig.blocks[0].fontColor', $payload['message']);
        $this->assertSame($before, $this->request('GET')->getContent(), 'rien n\'est enregistré');
    }

    public function testSectionNameIsStoredAndReturnedUnchanged(): void
    {
        $this->loginAsAdmin();
        $body = $this->withSectionNames('Héros d\'accueil « été »', str_repeat('é', 60));

        $this->assertSame(200, $this->request('PUT', $body)->getStatusCode());

        $sections = json_decode($this->request('GET')->getContent())->tabs[0]->sections;
        $this->assertSame('Héros d\'accueil « été »', $sections[0]->name);
        $this->assertSame(str_repeat('é', 60), $sections[1]->name, '60 caractères (et non 60 octets)');
    }

    public function testEmptyOrAbsentSectionNameIsAccepted(): void
    {
        $this->loginAsAdmin();

        $this->assertSame(200, $this->request('PUT', $this->withSectionNames('', null))->getStatusCode(), 'vide ou null : pas de nom');
        $this->assertSame(200, $this->request('PUT', $this->configurationJson('{"id": "titre", "type": "title"}'))->getStatusCode(), 'champ absent');
    }

    /** @dataProvider refusedSectionNames */
    public function testInvalidSectionNameIsRefusedAndNotStored(mixed $name, string $expectedMessage): void
    {
        $this->loginAsAdmin();
        $this->assertSame(200, $this->request('PUT', $this->configurationJson('{"id": "titre", "type": "title"}'))->getStatusCode());
        $before = $this->request('GET')->getContent();

        $put = $this->request('PUT', $this->withSectionNames('Nom valide', $name));

        $this->assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $put->getStatusCode(), $put->getContent());
        $errors = json_decode($put->getContent(), true)['errors'];
        $this->assertSame('tabs[0].sections[1].name', $errors[0]['path']);
        $this->assertStringContainsString($expectedMessage, $errors[0]['message']);
        $this->assertSame($before, $this->request('GET')->getContent(), 'rien n\'est enregistré');
    }

    public static function refusedSectionNames(): iterable
    {
        yield '61 caractères' => [str_repeat('a', 61), '60 caractères au plus'];
        yield 'balise' => ['Héros <b>important</b>', 'balises non autorisées'];
        yield 'script' => ['<script>alert(1)</script>', 'balises non autorisées'];
        yield 'nombre' => [42, 'texte attendu'];
        yield 'objet' => [['fr' => 'Héros'], 'texte attendu'];
    }

    /** Configuration de test avec un nom sur chacune des deux sections */
    private function withSectionNames(mixed $first, mixed $second): string
    {
        $body = json_decode($this->configurationJson('{"id": "titre", "type": "title"}'), false);
        $body->configuration->tabs[0]->sections[0]->name = $first;
        $body->configuration->tabs[0]->sections[1]->name = $second;

        return json_encode($body, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }

    public function testWritingRequiresAnAdministrator(): void
    {
        $response = $this->request('PUT', $this->configurationJson('{"id": "titre", "type": "title"}'));
        $this->assertContains($response->getStatusCode(), [401, 403], $response->getContent());
    }

    /** Configuration complète : navbar, une section classique, une section réglable contenant le bloc donné */
    private function configurationJson(string $block): string
    {
        return <<<JSON
            {"configuration": {
              "navbar": {"componentKey": "Navbar", "componentTypeKey": "typeA", "dataType": null},
              "tabs": [{"id": 1, "title": "Accueil", "sections": [
                {"id": 10, "componentKey": "Presentation", "componentTypeKey": "typeA", "dataType": 4, "reglableConfig": {"schemaVersion": 2, "blocks": []}},
                {"id": 12, "componentKey": "Presentation", "componentTypeKey": "typeReglable", "dataType": null,
                 "reglableConfig": {"schemaVersion": 2, "layout": "free", "height": 700, "blocks": [$block]}}
              ]}],
              "reglablePresets": []
            }}
            JSON;
    }

    private function request(string $method, ?string $body = null): Response
    {
        $jar = $this->client->getCookieJar();
        $jar->clear();
        foreach ($this->session as $name => $value) {
            $jar->set(new \Symfony\Component\BrowserKit\Cookie($name, $value, null, '/', MV_TEST_TENANT_HOST, true));
        }
        $xsrf = $this->session['XSRF-TOKEN_' . MV_TEST_TENANT_CODE] ?? null;
        $this->client->request($method, 'https://' . MV_TEST_TENANT_HOST . '/api/landingpage-settings', [], [], array_filter([
            'HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST,
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_XSRF_TOKEN' => $xsrf,
        ]), $body);

        return $this->client->getResponse();
    }

    private function loginAsAdmin(): void
    {
        $email = 'landing-admin-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $container = static::getContainer();
        $provider = $container->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $user = (new User())
            ->setEmail($email)
            ->setUsername($email)
            ->setFirstname('Landing')
            ->setLastname('Admin')
            ->setRoles(['ROLE_ADMIN', 'ROLE_USER_INTERNET'])
            ->setIsVerified(true);
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD));
        $provider->getEntityManager()->persist($user);
        $provider->getEntityManager()->flush();

        $this->client->getCookieJar()->clear();
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . '/api/login', [], [], [
            'HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
        ], json_encode(['username' => $email, 'password' => self::PASSWORD, 'platform' => 'web']));
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());
        foreach ($this->client->getResponse()->headers->getCookies() as $cookie) {
            $this->session[$cookie->getName()] = $cookie->getValue();
        }
    }
}
