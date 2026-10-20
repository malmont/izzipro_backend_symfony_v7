<?php

namespace App\Tests\Functional\AppProfile;

use App\Controller\Admin\AppProfileCrudController;
use App\Entity\User;
use App\Services\AppKeyService\AppKeyService;
use App\Services\TenantEntityManagerProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Profils des applications (20/10/2026) : GET /api/app-profile/{app} rend le profil fusionné avec la fiche
 * entreprise (table vide = tout vient de l'entreprise), code inconnu 404, isolation entre sites, profil inclus dans
 * la réponse d'activation, écran EasyAdmin.
 */
class AppProfileTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        foreach ([MV_TEST_TENANT_DB, MV_TEST_TENANT2_DB] as $db) {
            $this->db($db)->executeStatement('DELETE FROM app_profile_translation');
            $this->db($db)->executeStatement('DELETE FROM app_profile');
        }
        $this->db(MV_TEST_TENANT_DB)->executeStatement("UPDATE entreprise SET email = 'contact@mvtest.test'");
    }

    public function testAnEmptyTableFallsBackToTheCompanyAndAProfileOverridesFieldByField(): void
    {
        $company = $this->db(MV_TEST_TENANT_DB)->fetchAssociative('SELECT name, email FROM entreprise ORDER BY id LIMIT 1');

        $empty = $this->profile('livingmemory');
        $this->assertSame(['livingmemory', false, $company['name'], $company['email']], [$empty['app'], $empty['hasProfile'], $empty['name'], $empty['email']]);
        $this->assertSame('entreprise', $empty['sources']['name']);
        $this->assertNull($empty['tagline']);
        $this->assertNull($empty['sources']['tagline'], 'sans équivalent dans la fiche entreprise : null');
        $this->assertNull($empty['updatedAt']);
        foreach (['memoires', 'boussole', 'pos', 'mobile', 'admin'] as $code) {
            $this->assertSame([$code, false], [$this->profile($code)['app'], $this->profile($code)['hasProfile']], "code connu : $code");
        }

        $this->db(MV_TEST_TENANT_DB)->executeStatement("INSERT INTO app_profile (app, app_name, tagline, logo, primary_color, phone, city, country, privacy_policy_url) VALUES ('livingmemory', 'Living Memory', 'Vos souvenirs, votre livre', 'https://cdn.example.test/logo-lm.png', '#123abc', '+596 696 00 00 00', 'Fort-de-France', 'FR', 'https://example.test/confidentialite')");
        $profile = $this->profile('livingmemory');
        $this->assertSame([true, 'Living Memory', 'Vos souvenirs, votre livre', 'https://cdn.example.test/logo-lm.png', '#123abc', '+596 696 00 00 00'], [$profile['hasProfile'], $profile['name'], $profile['tagline'], $profile['logo'], $profile['primaryColor'], $profile['phone']]);
        $this->assertSame($company['email'], $profile['email'], 'champ vide : repris de l\'entreprise');
        $this->assertSame(['app', 'app', 'app', 'entreprise', 'app'], [$profile['sources']['name'], $profile['sources']['logo'], $profile['sources']['phone'], $profile['sources']['email'], $profile['sources']['address']]);
        $this->assertSame(['street1' => null, 'street2' => null, 'city' => 'Fort-de-France', 'zip' => null, 'province' => null, 'country' => 'FR', 'oneLine' => 'Fort-de-France, FR'], $profile['address'], 'adresse de l\'application en bloc, sans mélange');
        $this->assertSame('https://example.test/confidentialite', $profile['privacyPolicyUrl']);
        $this->assertNotNull($profile['updatedAt']);
        $this->assertSame(false, $this->profile('boussole')['hasProfile'], 'les autres applications ne sont pas touchées');

        // Isolation : le second site n'a pas ce profil
        $other = $this->profile('livingmemory', MV_TEST_TENANT2_HOST);
        $this->assertFalse($other['hasProfile']);

        // Code inconnu
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/api/app-profile/boutique', [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
        $body = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('APP_UNKNOWN', $body['error']);
        $this->assertSame(['livingmemory', 'memoires', 'boussole', 'pos', 'mobile', 'admin'], $body['apps']);
    }

    public function testTranslatedTextsFollowTheRequestedLanguage(): void
    {
        $db = $this->db(MV_TEST_TENANT_DB);
        $db->executeStatement("INSERT INTO app_profile (app, app_name, tagline, apropos) VALUES ('livingmemory', 'Living Memory', 'Accroche par défaut', 'À propos par défaut')");
        $id = (int) $db->fetchOne("SELECT id FROM app_profile WHERE app = 'livingmemory'");
        $db->executeStatement("INSERT INTO app_profile_translation (app_profile_id, language, tagline, apropos) VALUES ($id, 'en', 'Your memories, your book', 'About Living Memory')");
        $db->executeStatement("INSERT INTO app_profile_translation (app_profile_id, language, apropos) VALUES ($id, 'fr', 'À propos en français')");

        $en = $this->profile('livingmemory', MV_TEST_TENANT_HOST, 'en');
        $this->assertSame(['en', 'en', 'Living Memory', 'Your memories, your book', 'About Living Memory'], [$en['locale'], $en['translation'], $en['name'], $en['tagline'], $en['apropos']], 'champ traduit, sinon celui du profil');
        $fr = $this->profile('livingmemory', MV_TEST_TENANT_HOST, 'fr');
        $this->assertSame(['fr', 'Accroche par défaut', 'À propos en français'], [$fr['translation'], $fr['tagline'], $fr['apropos']]);
        $es = $this->profile('livingmemory', MV_TEST_TENANT_HOST, 'es');
        $this->assertSame(['fr', 'À propos en français'], [$es['translation'], $es['apropos']], 'langue absente : traduction française');
        $this->assertSame('app', $en['sources']['apropos']);

        // Suppression du profil : ses traductions partent avec lui
        $db->executeStatement("DELETE FROM app_profile WHERE id = $id");
        $this->assertSame(0, (int) $db->fetchOne("SELECT COUNT(*) FROM app_profile_translation WHERE app_profile_id = $id"));
    }

    public function testTheActivationResponseCarriesTheAppProfile(): void
    {
        $this->db(MV_TEST_TENANT_DB)->executeStatement("INSERT INTO app_profile (app, app_name) VALUES ('livingmemory', 'Living Memory')");
        $key = static::getContainer()->get(AppKeyService::class)->generate(MV_TEST_TENANT_CODE, 'livingmemory', 'Essai profil', 'test', null)['key'];
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . '/api/app/activate', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['key' => $key, 'app' => 'livingmemory']));
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());
        $body = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame(MV_TEST_TENANT_CODE, $body['tenant']['code']);
        $this->assertSame(['livingmemory', true, 'Living Memory'], [$body['profile']['app'], $body['profile']['hasProfile'], $body['profile']['name']]);
    }

    public function testAdminsManageProfilesInEasyAdmin(): void
    {
        $this->loginAs(['ROLE_ADMIN']);
        $crawler = $this->admin('new');
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $form = $crawler->filter('form[name="AppProfile"]')->form();
        $values = $form->getPhpValues();
        $values['AppProfile']['app'] = 'boussole';
        $values['AppProfile']['appName'] = 'Boussole ESG';
        $values['AppProfile']['primaryColor'] = '#0a7f5a';
        // Traduction ajoutée comme dans l'écran (collection « Contenus traduits »)
        $values['AppProfile']['translations'] = [['language' => 'en', 'appName' => 'ESG Compass', 'tagline' => 'Measure your impact']];
        $this->client->request($form->getMethod(), $form->getUri(), $values, $form->getPhpFiles());
        $this->assertContains($this->client->getResponse()->getStatusCode(), [302, 303], (string) $this->client->getResponse()->getContent());
        $this->assertSame(['en', 'ESG Compass', 'Measure your impact'], array_values($this->db(MV_TEST_TENANT_DB)->fetchAssociative("SELECT t.language, t.app_name, t.tagline FROM app_profile_translation t JOIN app_profile p ON p.id = t.app_profile_id WHERE p.app = 'boussole'")));
        $this->assertSame('ESG Compass', $this->profile('boussole', MV_TEST_TENANT_HOST, 'en')['name']);
        $this->assertSame(['Boussole ESG', '#0a7f5a'], array_values($this->db(MV_TEST_TENANT_DB)->fetchAssociative("SELECT app_name, primary_color FROM app_profile WHERE app = 'boussole'")));
        $this->assertSame('Boussole ESG', $this->profile('boussole')['name']);

        // Doublon refusé
        $crawler = $this->admin('new');
        $this->client->submit($crawler->filter('form[name="AppProfile"]')->form(), ['AppProfile[app]' => 'boussole', 'AppProfile[appName]' => 'Doublon']);
        $this->assertSame(1, (int) $this->db(MV_TEST_TENANT_DB)->fetchOne("SELECT COUNT(*) FROM app_profile WHERE app = 'boussole'"));

        $this->admin('index');
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertStringContainsString('Boussole ESG', $this->client->getResponse()->getContent());

        $this->loginAs(['ROLE_USER_INTERNET']);
        $this->admin('index');
        $this->assertContains($this->client->getResponse()->getStatusCode(), [302, 403], 'non-admin refusé');
    }

    private function profile(string $app, string $host = MV_TEST_TENANT_HOST, string $locale = 'fr'): array
    {
        $this->client->request('GET', 'https://' . $host . "/api/app-profile/$app?locale=$locale", [], [], ['HTTP_X_TENANT_HOST' => $host]);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());

        return json_decode($this->client->getResponse()->getContent(), true);
    }

    private function admin(string $action): \Symfony\Component\DomCrawler\Crawler
    {
        return $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin?' . http_build_query(['crudAction' => $action, 'crudControllerFqcn' => AppProfileCrudController::class]), [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
    }

    private function loginAs(array $roles): void
    {
        $email = 'profil-app-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Admin')->setLastname('Profils')->setRoles($roles)->setIsVerified(true)->setPassword('x');
        $provider->getEntityManager()->persist($user);
        $provider->getEntityManager()->flush();
        $this->client->loginUser($user, 'main');
    }

    private function db(string $dbname): \Doctrine\DBAL\Connection
    {
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant($dbname, $dbname === MV_TEST_TENANT_DB ? MV_TEST_TENANT_CODE : MV_TEST_TENANT2_CODE);

        return $provider->getEntityManager()->getConnection();
    }
}
