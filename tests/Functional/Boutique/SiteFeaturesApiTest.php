<?php

namespace App\Tests\Functional\Boutique;

use App\Entity\User;
use App\Services\TenantEntityManagerProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Registre des fonctionnalités du site (console de pilotage, lot 2, 04/11/2026) : GET/PUT /api/site/features pour l'administrateur
 * du site ; les clés `site` s'écrivent là où elles vivent (fiche Entreprise, réglages de la boutique) et se relisent par les
 * routes existantes ; les clés `platform` sont réservées au super administrateur ; erreurs 422 dans le vocabulaire du registre.
 */
class SiteFeaturesApiTest extends WebTestCase
{
    private const ADMIN_EMAIL = 'admin@arkanoa-media.com';
    private const SUPER_EMAIL = 'super-admin-features@example.invalid';
    private const CLIENT_EMAIL = 'client-features@example.invalid';
    private KernelBrowser $client;
    private \Doctrine\DBAL\Connection $db;
    private int $entrepriseId;
    private array $entrepriseBefore;
    private ?string $boutiqueBefore;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $this->db = $provider->getEntityManager()->getConnection();
        $row = $this->db->fetchAssociative('SELECT id, locale, pos_timezone, currency, prices_include_tax, is_boutique_active FROM entreprise ORDER BY id LIMIT 1');
        $this->assertNotFalse($row);
        $this->entrepriseId = (int) $row['id'];
        $this->entrepriseBefore = $row;
        $this->boutiqueBefore = $this->db->fetchOne('SELECT configuration::text FROM boutique_setting ORDER BY id LIMIT 1') ?: null;
    }

    protected function tearDown(): void
    {
        $b = $this->entrepriseBefore;
        $this->db->executeStatement('UPDATE entreprise SET locale = ?, pos_timezone = ?, currency = ?, prices_include_tax = ?, is_boutique_active = ? WHERE id = ?',
            [$b['locale'], $b['pos_timezone'], $b['currency'], (bool) $b['prices_include_tax'], (bool) $b['is_boutique_active'], $this->entrepriseId],
            [\Doctrine\DBAL\ParameterType::STRING, \Doctrine\DBAL\ParameterType::STRING, \Doctrine\DBAL\ParameterType::STRING, \Doctrine\DBAL\ParameterType::BOOLEAN, \Doctrine\DBAL\ParameterType::BOOLEAN, \Doctrine\DBAL\ParameterType::INTEGER]);
        if ($this->boutiqueBefore !== null) {
            $this->db->executeStatement('UPDATE boutique_setting SET configuration = ?::json WHERE id = (SELECT id FROM boutique_setting ORDER BY id LIMIT 1)', [$this->boutiqueBefore]);
        }
        static::getContainer()->get(\App\EventSubscriber\CacheInvalidationSubscriber::class)->invalidateWrittenTenants();
        static::getContainer()->get(\App\Services\TenantCacheService::class)->invalidateTenant();
        parent::tearDown();
    }

    public function testTheRegistryIsReservedToAdministratorsAndListsEveryFeature(): void
    {
        $this->assertSame(401, $this->call('GET', null, null)->getStatusCode());
        $this->assertSame(403, $this->call('GET', null, 'client')->getStatusCode());
        $r = $this->call('GET', null, 'admin');
        $this->assertSame(200, $r->getStatusCode(), $r->getContent());
        $body = json_decode((string) $r->getContent(), true);
        $this->assertSame(['localisation', 'modules', 'apps', 'commerce', 'services'], $body['groups']);
        $keys = array_column($body['features'], 'key');
        foreach (['site.locale', 'site.timezone', 'site.country', 'site.currency', 'module.boutique', 'app.living_memory', 'commerce.sales_modes', 'commerce.subscriptions', 'stripe.online', 'ai.memoires', 'reviews.enabled'] as $key) {
            $this->assertContains($key, $keys);
        }
        $byKey = array_column($body['features'], null, 'key');
        foreach (['key', 'label', 'group', 'scope', 'type', 'value', 'effective', 'status', 'prerequisites', 'href'] as $field) {
            $this->assertArrayHasKey($field, $byKey['site.locale']);
        }
        $this->assertSame('site', $byKey['site.locale']['scope']);
        $this->assertContains($byKey['site.locale']['effective'], ['fr', 'en'], 'langue effective toujours connue, même déduite');
        $this->assertSame('platform', $byKey['module.boutique']['scope']);
        $this->assertSame(['sale', 'rental', 'subscription'], $byKey['commerce.sales_modes']['value'], 'sans réglage : les trois modes');
        $this->assertContains($byKey['commerce.subscriptions']['status'], ['off', 'incomplete', 'ready']);
        $this->assertSame(['module.boutique', 'commerce.sales_modes', 'stripe.online', 'subscription_plan.active', 'carrier.fixed_price'], array_column($byKey['commerce.subscriptions']['prerequisites'], 'key'));
        $this->assertSame('info', $byKey['stripe.online']['type']);
        $this->assertStringNotContainsString('sk_', (string) $r->getContent(), 'aucun secret dans la réponse');
    }

    public function testSiteKeysAreWrittenWhereTheyLiveAndReadBackByTheExistingRoutes(): void
    {
        $r = $this->call('PUT', ['values' => ['site.locale' => 'en', 'site.timezone' => 'America/Vancouver', 'site.currency' => 'usd', 'commerce.sales_modes' => ['sale', 'rental'], 'commerce.guest_checkout' => false]], 'admin');
        $this->assertSame(200, $r->getStatusCode(), $r->getContent());
        $byKey = array_column(json_decode((string) $r->getContent(), true)['features'], null, 'key');
        $this->assertSame(['en', 'en', 'America/Vancouver', 'America/Vancouver', 'USD', ['sale', 'rental'], false], [$byKey['site.locale']['value'], $byKey['site.locale']['effective'], $byKey['site.timezone']['value'], $byKey['site.timezone']['effective'], $byKey['site.currency']['value'], $byKey['commerce.sales_modes']['value'], $byKey['commerce.guest_checkout']['value']]);
        $this->assertSame('off', $byKey['commerce.guest_checkout']['status']);

        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/api/entreprise/' . $this->entrepriseId . '?locale=fr', [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'HTTP_ACCEPT' => 'application/json']);
        $e = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame(['en', 'USD'], [$e['locale'], $e['currency']], 'la fiche publique reflète la langue choisie et la devise');
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/api/boutique-settings', [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'HTTP_ACCEPT' => 'application/json']);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $commerce = $this->commerceOf(json_decode((string) $this->client->getResponse()->getContent(), true));
        $this->assertSame([['sale', 'rental'], false, 'en', 'America/Vancouver'], [$commerce['salesModes'], $commerce['guestCheckout'], $commerce['locale'], $commerce['timezone']], 'le panneau Commerce de l\'éditeur lit les mêmes valeurs');

        // retour à la langue déduite (null) : la valeur disparaît, l'effective reste connue
        $r = $this->call('PUT', ['values' => ['site.locale' => null, 'commerce.sales_modes' => null]], 'admin');
        $this->assertSame(200, $r->getStatusCode(), $r->getContent());
        $byKey = array_column(json_decode((string) $r->getContent(), true)['features'], null, 'key');
        $this->assertNull($byKey['site.locale']['value']);
        $this->assertContains($byKey['site.locale']['effective'], ['fr', 'en']);
        $this->assertSame(['sale', 'rental', 'subscription'], $byKey['commerce.sales_modes']['value'], 'réglage retiré : les trois modes');
    }

    public function testInvalidValuesUnknownKeysAndReadOnlyKeysAnswer422InTheRegistryVocabulary(): void
    {
        $this->assertSame(422, $this->call('PUT', ['values' => []], 'admin')->getStatusCode());
        $this->assertSame(422, $this->call('PUT', ['nope' => 1], 'admin')->getStatusCode());
        $r = $this->call('PUT', ['values' => ['site.timezone' => 'Mars/Olympus']], 'admin');
        $this->assertSame(422, $r->getStatusCode());
        $body = json_decode((string) $r->getContent(), true);
        $this->assertSame(['VALIDATION_FAILED', 'site.timezone'], [$body['code'], $body['errors'][0]['path']], 'erreur de la fiche Entreprise traduite en clé du registre');
        $r = $this->call('PUT', ['values' => ['commerce.sales_modes' => ['sale', 'troc']]], 'admin');
        $this->assertSame(422, $r->getStatusCode());
        $this->assertSame('commerce.sales_modes', json_decode((string) $r->getContent(), true)['errors'][0]['path'], 'erreur des réglages de la boutique traduite en clé du registre');
        $r = $this->call('PUT', ['values' => ['site.locale' => 'de']], 'admin');
        $this->assertSame(422, $r->getStatusCode());
        $r = $this->call('PUT', ['values' => ['inconnue.cle' => true, 'stripe.online' => 'live', 'app.management' => true, 'module.pos' => true]], 'admin');
        $this->assertSame(422, $r->getStatusCode());
        $this->assertSame(['inconnue.cle', 'stripe.online', 'app.management', 'module.pos'], array_column(json_decode((string) $r->getContent(), true)['errors'], 'path'), 'toutes les erreurs, pas seulement la première');
        $this->assertSame($this->entrepriseBefore['locale'], $this->db->fetchOne('SELECT locale FROM entreprise WHERE id = ?', [$this->entrepriseId]), 'rien n\'est écrit');
    }

    public function testPlatformKeysAreReservedToTheSuperAdministrator(): void
    {
        $current = (bool) $this->entrepriseBefore['is_boutique_active'];
        $r = $this->call('PUT', ['values' => ['module.boutique' => !$current, 'site.currency' => 'EUR']], 'admin');
        $this->assertSame(403, $r->getStatusCode(), $r->getContent());
        $body = json_decode((string) $r->getContent(), true);
        $this->assertSame(['SUPER_ADMIN_REQUIRED', ['module.boutique']], [$body['code'], $body['fields']]);
        $this->assertSame($this->entrepriseBefore['currency'], $this->db->fetchOne('SELECT currency FROM entreprise WHERE id = ?', [$this->entrepriseId]), 'tout ou rien : la devise n\'est pas écrite non plus');
        $this->assertSame(200, $this->call('PUT', ['values' => ['module.boutique' => $current]], 'admin')->getStatusCode(), 'valeur inchangée : ignorée');
        $r = $this->call('PUT', ['values' => ['module.boutique' => !$current]], 'super');
        $this->assertSame(200, $r->getStatusCode(), $r->getContent());
        $byKey = array_column(json_decode((string) $r->getContent(), true)['features'], null, 'key');
        $this->assertSame(!$current, $byKey['module.boutique']['value']);
        $this->assertSame(!$current ? 'ready' : 'off', $byKey['module.boutique']['status']);
    }

    private function commerceOf(array $body): array
    {
        $conf = $body['configuration'] ?? $body;
        if (is_string($conf)) {
            $conf = json_decode($conf, true);
        }

        return $conf['commerce'] ?? [];
    }

    private function call(string $method, ?array $body, ?string $as): \Symfony\Component\HttpFoundation\Response
    {
        $server = ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json', 'HTTP_X_XSRF_TOKEN' => 'jeton-xsrf-de-test'];
        if ($as !== null) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer ' . $this->token($as);
        }
        $this->client->request($method, 'https://' . MV_TEST_TENANT_HOST . '/api/site/features?locale=fr', [], [], $server, $body !== null ? json_encode($body) : null);

        return $this->client->getResponse();
    }

    private function token(string $as): string
    {
        $container = static::getContainer();
        $container->get(TenantEntityManagerProvider::class)->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $em = $container->get(TenantEntityManagerProvider::class)->getEntityManager();
        $email = match ($as) { 'admin' => self::ADMIN_EMAIL, 'super' => self::SUPER_EMAIL, default => self::CLIENT_EMAIL };
        $user = $em->getRepository(User::class)->findOneBy(['email' => $email]);
        if (!$user) {
            $this->assertNotSame('admin', $as, 'l\'administrateur de test existe dans la copie de base');
            $user = (new User())->setEmail($email)->setUsername($email)->setFirstname(ucfirst($as))->setLastname('Test')
                ->setRoles($as === 'super' ? ['ROLE_ADMIN', 'ROLE_SUPER_ADMIN'] : ['ROLE_USER_INTERNET'])->setPassword('inutilisable')->setIsVerified(true);
            $em->persist($user);
            $em->flush();
        }

        return $container->get('lexik_jwt_authentication.jwt_manager')->create($user);
    }
}
