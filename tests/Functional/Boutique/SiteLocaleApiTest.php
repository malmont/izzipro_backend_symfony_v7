<?php

namespace App\Tests\Functional\Boutique;

use App\Entity\Entreprise;
use App\Services\BoutiqueSettingsService\BoutiqueSettingsService;
use App\Services\TenantEntityManagerProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Langue du site exposée au site Next.js (04/11/2026) : `locale` (fr | en) sur `GET /api/entreprise/{id}`, règle de
 * SiteLocaleResolver d'après l'adresse du site ; indépendante de `?locale=` de la requête ; depuis la console de pilotage (lot 2,
 * 04/11/2026) un administrateur peut la choisir explicitement (`locale` du PUT /api/entreprise, null = déduite). Aussi `commerce.locale`
 * dans `GET /api/boutique-settings`, à côté de `commerce.timezone` et `commerce.siteCountry`.
 */
class SiteLocaleApiTest extends WebTestCase
{
    private KernelBrowser $client;
    private int $id;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->id = (int) $this->entreprise()->getId();
        $this->db()->executeStatement('UPDATE entreprise SET pos_timezone = NULL');
        $this->db()->executeStatement('DELETE FROM boutique_setting');
    }

    protected function tearDown(): void
    {
        $this->setAddress('Canada', 'QC');
        parent::tearDown();
    }

    public function testTheSiteLanguageFollowsTheAddressAndNotTheVisitor(): void
    {
        foreach ([
            ['Canada', 'QC', 'fr', 'Québec'],
            ['CA', 'ON', 'en', 'Ontario'],
            ['US', 'NY', 'en', 'États-Unis'],
            ['FR', '', 'fr', 'France'],
            ['', '', 'fr', 'sans adresse'],
            ['Canada', '', 'fr', 'Canada sans province'],
        ] as [$country, $state, $expected, $label]) {
            $this->setAddress($country, $state);
            $sheet = $this->sheet('fr');
            $this->assertSame($expected, $sheet['locale'], $label);
            $this->assertSame([$country, $state], [$sheet['country'], $sheet['province']], "$label : country et province inchangés");
            $this->assertSame($expected, $this->sheet('en')['locale'], "$label : ?locale=en ne change pas la langue du site");
        }
    }

    public function testTheSiteLanguageIsReadOnlyAndAlsoServedWithTheShopSettings(): void
    {
        $this->setAddress('CA', 'ON');
        $this->assertSame('en', $this->sheet('fr')['locale']);
        $this->assertContains('locale', BoutiqueSettingsService::READ_ONLY_COMMERCE, 'jamais écrit par un PUT des réglages');

        // Un administrateur choisit la langue explicitement (lot 2 de la console, 04/11/2026) : elle l'emporte sur la déduction ; null → déduite à nouveau
        $this->putAsAdmin(['siteLocale' => 'fr', 'tel' => '+1 514 555 0199']);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());
        $this->assertSame('fr', $this->sheet('fr')['locale'], 'langue choisie explicitement');
        $this->assertSame('fr', $this->sheet('en')['locale'], '?locale=en ne change toujours pas la langue du site');
        $this->putAsAdmin(['siteLocale' => 'de']);
        $this->assertSame(422, $this->client->getResponse()->getStatusCode(), 'langue hors liste refusée');
        $this->putAsAdmin(['siteLocale' => null]);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());
        $this->assertSame('en', $this->sheet('fr')['locale'], 'langue déduite à nouveau');

        $this->db()->executeStatement("INSERT INTO boutique_setting (configuration) VALUES ('{\"commerce\": {\"guestCheckout\": true}}')");
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/api/boutique-settings', [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'HTTP_ACCEPT' => 'application/json']);
        $settings = json_decode($this->client->getResponse()->getContent(), true);
        $commerce = $settings['configuration']['commerce'] ?? $settings['commerce'];
        $this->assertSame(['en', 'CA', 'America/Toronto'], [$commerce['locale'], $commerce['siteCountry'], $commerce['timezone']]);
    }

    /** PUT /api/entreprise connecté en administrateur (cookies + jeton XSRF frais à chaque appel) */
    private function putAsAdmin(array $body): void
    {
        $session = $this->loginAsAdmin();
        $jar = $this->client->getCookieJar();
        $jar->clear();
        foreach ($session as $name => $value) {
            $jar->set(new \Symfony\Component\BrowserKit\Cookie($name, $value, null, '/', MV_TEST_TENANT_HOST, true));
        }
        $this->client->request('PUT', 'https://' . MV_TEST_TENANT_HOST . "/api/entreprise/{$this->id}?locale=fr", [], [], [
            'HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_XSRF_TOKEN' => $session['XSRF-TOKEN_' . MV_TEST_TENANT_CODE] ?? '',
        ], json_encode($body));
    }

    /** @return array<string, mixed> fiche publique */
    private function sheet(string $locale): array
    {
        $this->client->getCookieJar()->clear();
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . "/api/entreprise/{$this->id}?locale=$locale", [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'HTTP_ACCEPT' => 'application/json']);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());

        return json_decode($this->client->getResponse()->getContent(), true);
    }

    /** Adresse du site écrite par Doctrine : le cache de la fiche (entreprise_{id}_{locale}) est vidé par l'écriture */
    private function setAddress(string $country, string $state): void
    {
        $em = $this->em();
        $address = $em->find(Entreprise::class, $this->id)->getAddressEntreprise();
        $this->assertNotNull($address, 'base de test : une adresse d\'entreprise');
        $address->setCountry($country)->setState($state);
        $em->flush();
    }

    private function entreprise(): Entreprise
    {
        $entreprise = $this->em()->getRepository(Entreprise::class)->findOneBy([]);
        $this->assertNotNull($entreprise, 'base de test : une fiche entreprise');

        return $entreprise;
    }

    private function em(): \Doctrine\ORM\EntityManagerInterface
    {
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);

        return $provider->getEntityManager();
    }

    private function db(): \Doctrine\DBAL\Connection
    {
        return $this->em()->getConnection();
    }

    /** @return array<string, string> cookies de session (connexion web d'un administrateur) */
    private function loginAsAdmin(): array
    {
        $email = 'admin-langue-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $container = static::getContainer();
        $em = $this->em();
        $user = (new \App\Entity\User())->setEmail($email)->setUsername($email)->setFirstname('Admin')->setLastname('Langue')->setRoles(['ROLE_ADMIN', 'ROLE_USER_INTERNET'])->setIsVerified(true);
        $user->setPassword($container->get(\Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface::class)->hashPassword($user, 'Mot-de-passe-de-test-1!'));
        $em->persist($user);
        $em->flush();
        $this->client->getCookieJar()->clear();
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . '/api/login', [], [], [
            'HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
        ], json_encode(['username' => $email, 'password' => 'Mot-de-passe-de-test-1!', 'platform' => 'web']));
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());
        $session = [];
        foreach ($this->client->getResponse()->headers->getCookies() as $cookie) {
            $session[$cookie->getName()] = $cookie->getValue();
        }

        return $session;
    }
}
