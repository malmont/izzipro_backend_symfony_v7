<?php

namespace App\Tests\Functional\AppProfile;

use App\Controller\Admin\AppProfileCrudController;
use App\Entity\AppProfile;
use App\Entity\User;
use App\Services\AppKeyService\AppKeyService;
use App\Services\TenantEntityManagerProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Thème de l'application e-commerce mobile (31/10/2026), GET /api/mobile/app-theme : validation de chaque réglage
 * (validateur et formulaire EasyAdmin), repli profil → charte de la boutique → null, site sans réglage, isolation des
 * sites, et non-régression des réponses du profil et de l'activation (pos, admin, mobile), champ par champ.
 */
class AppThemeTest extends WebTestCase
{
    /** Forme exacte de `theme` */
    private const THEME_KEYS = ['primaryColor', 'accentColor', 'mode', 'font', 'radius', 'buttonStyle', 'productCard', 'homeHero', 'updatedAt'];
    /** Forme publique du profil (20/10/2026, docs/architecture.md) : elle ne bouge pas */
    private const PROFILE_KEYS = ['app', 'appLabel', 'hasProfile', 'name', 'tagline', 'logo', 'icon', 'primaryColor', 'email', 'phone', 'website', 'supportUrl', 'privacyPolicyUrl', 'address', 'apropos', 'privacyPolicy', 'conditionOfUse', 'legalNotice', 'sources', 'entreprise', 'locale', 'translation', 'updatedAt'];
    private const NULL_THEME = ['primaryColor' => null, 'accentColor' => null, 'mode' => null, 'font' => null, 'radius' => null, 'buttonStyle' => null, 'productCard' => null, 'homeHero' => null, 'updatedAt' => null];

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->clean();
    }

    /** Rien ne reste pour les tests suivants : AppKeyTest attend un site sans profil « pos » */
    protected function tearDown(): void
    {
        $this->clean();
        parent::tearDown();
    }

    private function clean(): void
    {
        foreach ([MV_TEST_TENANT_DB, MV_TEST_TENANT2_DB] as $db) {
            $this->db($db)->executeStatement('DELETE FROM app_profile_translation');
            $this->db($db)->executeStatement('DELETE FROM app_profile');
            $this->db($db)->executeStatement('DELETE FROM boutique_setting');
            $this->db($db)->executeStatement("DELETE FROM content_audit_log WHERE resource = 'boutique-settings'");
        }
    }

    public function testEveryThemeFieldIsValidatedValidAndInvalid(): void
    {
        $validator = static::getContainer()->get(ValidatorInterface::class);
        $cases = [
            // propriété => [setter, valeurs valides, valeurs refusées]
            'primaryColor' => ['setPrimaryColor', ['#1d4ed8', '#1D4ED8', '1d4ed8', null, ''], ['#12', 'bleu', 'rgb(1,2,3)', '#1d4ed8ff', '#ggg000']],
            'accentColor' => ['setAccentColor', ['#ffc800', 'FFC800', null], ['#fc8', 'orange', '#ffc80']],
            'themeMode' => ['setThemeMode', ['light', 'dark', 'system', null], ['auto', 'Light', 'ghost']],
            'themeFont' => ['setThemeFont', ['Inter', 'Poppins', 'Montserrat', 'Playfair Display', 'Lora', 'Outfit', 'System', null], ['Comic Sans', 'inter', 'Roboto']],
            'themeRadius' => ['setThemeRadius', [0, 12, 28, null], [-1, 29, 100]],
            'buttonStyle' => ['setButtonStyle', ['solid', 'outline', 'pill', null], ['ghost', 'Solid', 'rounded']],
            'productCard' => ['setProductCard', ['classic', 'overlay', 'compact', null], ['grid', 'Classic', 'card']],
            'homeHero' => ['setHomeHero', ['carousel', 'banner', 'none', null], ['video', 'Banner', 'slider']],
        ];
        foreach ($cases as $property => [$setter, $valid, $invalid]) {
            foreach ($valid as $value) {
                $profile = (new AppProfile())->setApp('mobile');
                $profile->$setter($value);
                $this->assertCount(0, $validator->validate($profile), sprintf('%s = %s doit être accepté', $property, var_export($value, true)));
            }
            foreach ($invalid as $value) {
                $profile = (new AppProfile())->setApp('mobile');
                $profile->$setter($value);
                $violations = $validator->validate($profile);
                $this->assertGreaterThan(0, count($violations), sprintf('%s = %s doit être refusé', $property, var_export($value, true)));
                $this->assertSame($property, $violations[0]->getPropertyPath());
            }
        }
        // Normalisation des couleurs valides : minuscules, « # » ajouté
        $this->assertSame('#1d4ed8', (new AppProfile())->setPrimaryColor('1D4ED8')->getPrimaryColor());
        $this->assertNull((new AppProfile())->setAccentColor('')->getAccentColor());

        // Formulaire EasyAdmin : valeurs hors liste ou hors borne → erreur affichée, rien n'est enregistré
        $this->loginAs(['ROLE_ADMIN']);
        $crawler = $this->admin('new');
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $form = $crawler->filter('form[name="AppProfile"]')->form();
        foreach (['primaryColor', 'accentColor', 'themeMode', 'themeFont', 'themeRadius', 'buttonStyle', 'productCard', 'homeHero'] as $field) {
            $this->assertTrue($form->has("AppProfile[$field]"), "champ $field présent dans le panneau Apparence");
        }
        $html = $this->client->getResponse()->getContent();
        $this->assertStringContainsString('Apparence de l\'application', $html);
        // Couleurs facultatives : sélecteur désactivé et case « Aucune » cochée tant que rien n'est réglé
        $this->assertSame(2, substr_count($html, 'class="d-flex align-items-center gap-3 optional-color"'), 'primaryColor et accentColor');
        $this->assertMatchesRegularExpression('/<input type="hidden" id="AppProfile_accentColor" name="AppProfile\[accentColor\]"[^>]*value=""/', $html, 'champ vide tant que « Aucune » est cochée');
        $this->assertMatchesRegularExpression('/<input type="color" class="form-control form-control-color optional-color-picker" value="#000000" disabled/', $html, 'sélecteur désactivé, hors formulaire');
        $this->assertStringContainsString('Aucune (réglage vide)', $html);
        $this->assertStringContainsString('Couleur des boutons et des éléments mis en avant', $html, 'texte d\'aide');
        $this->assertMatchesRegularExpression('/name="AppProfile\[themeRadius\]"[^>]*max="28"/', $html);
        $invalid = $form->getPhpValues();
        $invalid['AppProfile'] = ['app' => 'mobile', 'appName' => 'Boutique mobile', 'primaryColor' => '#12', 'accentColor' => 'orange', 'themeMode' => 'auto', 'themeFont' => 'Comic Sans', 'themeRadius' => '40', 'buttonStyle' => 'ghost', 'productCard' => 'grid', 'homeHero' => 'video'] + $invalid['AppProfile'];
        $this->client->request($form->getMethod(), $form->getUri(), $invalid, $form->getPhpFiles());
        $this->assertContains($this->client->getResponse()->getStatusCode(), [200, 422], 'formulaire réaffiché avec ses erreurs (EasyAdmin répond 422)');
        $html = $this->client->getResponse()->getContent();
        $this->assertStringContainsString('Couleur au format #RRGGBB attendue.', $html);
        $this->assertStringContainsString('Arrondi de 0 à 28 attendu.', $html);
        $this->assertSame(0, (int) $this->db(MV_TEST_TENANT_DB)->fetchOne('SELECT COUNT(*) FROM app_profile'), 'rien enregistré');

        // Valeurs valides → enregistrées et visibles dans le thème
        $crawler = $this->admin('new');
        $form = $crawler->filter('form[name="AppProfile"]')->form();
        $valid = $form->getPhpValues();
        $valid['AppProfile'] = ['app' => 'mobile', 'appName' => 'Boutique mobile', 'primaryColor' => '#1d4ed8', 'accentColor' => '#ffc800', 'themeMode' => 'dark', 'themeFont' => 'Poppins', 'themeRadius' => '12', 'buttonStyle' => 'pill', 'productCard' => 'compact', 'homeHero' => 'banner'] + $valid['AppProfile'];
        $this->client->request($form->getMethod(), $form->getUri(), $valid, $form->getPhpFiles());
        $this->assertContains($this->client->getResponse()->getStatusCode(), [302, 303], (string) $this->client->getResponse()->getContent());
        $this->assertSame(['#1d4ed8', '#ffc800', 'dark', 'Poppins', 12, 'pill', 'compact', 'banner'], array_values($this->db(MV_TEST_TENANT_DB)->fetchAssociative("SELECT primary_color, accent_color, theme_mode, theme_font, theme_radius, button_style, product_card, home_hero FROM app_profile WHERE app = 'mobile'")));
        $theme = $this->theme();
        $this->assertSame(['#1d4ed8', '#ffc800', 'dark', 'Poppins', 12, 'pill', 'compact', 'banner'], [$theme['theme']['primaryColor'], $theme['theme']['accentColor'], $theme['theme']['mode'], $theme['theme']['font'], $theme['theme']['radius'], $theme['theme']['buttonStyle'], $theme['theme']['productCard'], $theme['theme']['homeHero']]);
        $this->assertNotNull($theme['theme']['updatedAt']);
        $this->assertSame(array_fill_keys(['primaryColor', 'accentColor', 'mode', 'font', 'radius', 'buttonStyle', 'productCard', 'homeHero'], 'app'), $theme['sources']);

        // Sans couleur cochée (sélecteur désactivé, champ absent de l'envoi) : la couleur reste vide
        $crawler = $this->admin('edit', (int) $this->db(MV_TEST_TENANT_DB)->fetchOne("SELECT id FROM app_profile WHERE app = 'mobile'"));
        $editHtml = $this->client->getResponse()->getContent();
        $this->assertMatchesRegularExpression('/<input type="hidden" id="AppProfile_accentColor" name="AppProfile\[accentColor\]"[^>]*value="#ffc800"/', $editHtml, 'couleur réglée : sélecteur actif avec sa valeur');
        $form = $crawler->filter('form[name="AppProfile"]')->form();
        $edit = $form->getPhpValues();
        unset($edit['AppProfile']['accentColor']);
        $this->client->request($form->getMethod(), $form->getUri(), $edit, $form->getPhpFiles());
        $this->assertContains($this->client->getResponse()->getStatusCode(), [302, 303], (string) $this->client->getResponse()->getContent());
        $this->assertNull($this->db(MV_TEST_TENANT_DB)->fetchOne("SELECT accent_color FROM app_profile WHERE app = 'mobile'"));
    }

    public function testTheThemeFallsBackFromTheProfileToTheCharterThenToNull(): void
    {
        // 1. Rien : tout null
        $empty = $this->theme();
        $this->assertSame(['app' => 'mobile', 'theme' => self::NULL_THEME, 'sources' => array_fill_keys(['primaryColor', 'accentColor', 'mode', 'font', 'radius', 'buttonStyle', 'productCard', 'homeHero'], null)], $empty);

        // 2. Charte de la boutique seule : couleurs ramenées à #rrggbb, headingFont si dans la liste, radius borné à 28, buttonStyle
        $this->putCharter(['primaryColor' => '#1D4ED8', 'accentColor' => 'rgb(255, 200, 0)', 'textColor' => '#111', 'backgroundColor' => 'transparent', 'headingFont' => 'lora', 'bodyFont' => 'Inter', 'radius' => 40, 'buttonStyle' => 'outline']);
        $charter = $this->theme();
        $this->assertSame(['#1d4ed8', '#ffc800', null, 'Lora', 28, 'outline', null, null], array_values(array_slice($charter['theme'], 0, 8)));
        $this->assertSame(['primaryColor' => 'charter', 'accentColor' => 'charter', 'mode' => null, 'font' => 'charter', 'radius' => 'charter', 'buttonStyle' => 'charter', 'productCard' => null, 'homeHero' => null], $charter['sources']);
        $this->assertNotNull($charter['theme']['updatedAt'], 'date du dernier enregistrement des réglages de la boutique');
        $charterAt = $charter['theme']['updatedAt'];

        // Formes de la charte hors du format mobile : #rgb étendu, alpha ignoré, police inconnue, taille CSS, pourcentage
        $this->putCharter(['primaryColor' => '#abc', 'accentColor' => '#aabbcc80', 'headingFont' => 'Roboto', 'radius' => '12px', 'buttonStyle' => 'pill']);
        $this->assertSame(['#aabbcc', '#aabbcc', null, null, 12, 'pill', null, null], array_values(array_slice($this->theme()['theme'], 0, 8)));
        $this->putCharter(['headingFont' => 'outfit', 'accentColor' => 'transparent', 'radius' => '1.5rem']);
        $this->assertSame('Outfit', $this->theme()['theme']['font'], 'Outfit (ajoutée le 31/10/2026) est renvoyée sous son nom canonique');
        $this->putCharter(['accentColor' => 'transparent', 'radius' => '1.5rem']);
        $this->assertSame([null, null, null, null, 24, null, null, null], array_values(array_slice($this->theme()['theme'], 0, 8)));
        $this->putCharter(['radius' => '50%']);
        $this->assertNull($this->theme()['theme']['radius'], 'pourcentage : illisible pour l\'application');

        // 3. Profil : champ par champ, il prime ; un champ vide du profil garde la charte
        $this->putCharter(['primaryColor' => '#1d4ed8', 'accentColor' => '#ffc800', 'headingFont' => 'Lora', 'radius' => 40, 'buttonStyle' => 'outline']);
        $this->db(MV_TEST_TENANT_DB)->executeStatement("INSERT INTO app_profile (app, accent_color, theme_mode, theme_font, theme_radius, product_card, home_hero) VALUES ('mobile', '#ff0000', 'dark', 'Poppins', 8, 'compact', 'none')");
        $mixed = $this->theme();
        $this->assertSame(['#1d4ed8', '#ff0000', 'dark', 'Poppins', 8, 'outline', 'compact', 'none'], array_values(array_slice($mixed['theme'], 0, 8)));
        $this->assertSame(['primaryColor' => 'charter', 'accentColor' => 'app', 'mode' => 'app', 'font' => 'app', 'radius' => 'app', 'buttonStyle' => 'charter', 'productCard' => 'app', 'homeHero' => 'app'], $mixed['sources']);
        $this->assertGreaterThanOrEqual($charterAt, $mixed['theme']['updatedAt'], 'la plus récente des deux dates');

        // Valeur enregistrée hors des listes (écriture hors formulaire) : ignorée, repli
        $this->db(MV_TEST_TENANT_DB)->executeStatement("UPDATE app_profile SET theme_mode = 'auto', theme_radius = 90, accent_color = 'rouge' WHERE app = 'mobile'");
        $guarded = $this->theme();
        $this->assertSame([null, '#ffc800', 28], [$guarded['theme']['mode'], $guarded['theme']['accentColor'], $guarded['theme']['radius']]);

        // Autre code d'application : ses propres réglages, même charte
        $pos = $this->theme('pos');
        $this->assertSame(['pos', null, null], [$pos['app'], $pos['sources']['accentColor'], $pos['theme']['mode']], 'depuis le 31/10/2026, la charte ne comble que le code mobile');

        // Code inconnu
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/api/mobile/app-theme?app=boutique', [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
        $this->assertSame('APP_UNKNOWN', json_decode($this->client->getResponse()->getContent(), true)['error']);
    }

    /** Repli sur la charte réservé au code mobile (31/10/2026) : pour les autres applications, non réglé = null, sources null */
    public function testOnlyTheMobileAppFallsBackOnTheCharter(): void
    {
        $this->putCharter(['primaryColor' => '#1d4ed8', 'accentColor' => '#ffc800', 'headingFont' => 'Lora', 'radius' => 40, 'buttonStyle' => 'outline']);
        $mobile = $this->theme();
        $this->assertSame(['#1d4ed8', '#ffc800', null, 'Lora', 28, 'outline', null, null], array_values(array_slice($mobile['theme'], 0, 8)), 'mobile : la charte comble les champs vides');
        $nulls = array_fill_keys(['primaryColor', 'accentColor', 'mode', 'font', 'radius', 'buttonStyle', 'productCard', 'homeHero'], null);
        foreach (['livingmemory', 'memoires', 'boussole', 'pos', 'admin'] as $app) {
            $other = $this->theme($app);
            $this->assertSame($app, $other['app']);
            $this->assertSame($nulls + ['updatedAt' => null], $other['theme'], "$app : aucune valeur de la charte");
            $this->assertSame($nulls, $other['sources'], "$app : sources null");
            // Un réglage propre à l'application reste servi, source « app », sans que la charte comble le reste
            $this->db(MV_TEST_TENANT_DB)->executeStatement("INSERT INTO app_profile (app, accent_color, theme_mode) VALUES ('$app', '#ff0000', 'dark')");
            $own = $this->theme($app);
            $this->assertSame(['primaryColor' => null, 'accentColor' => '#ff0000', 'mode' => 'dark', 'font' => null, 'radius' => null, 'buttonStyle' => null, 'productCard' => null, 'homeHero' => null], array_slice($own['theme'], 0, 8), $app);
            $this->assertSame(['primaryColor' => null, 'accentColor' => 'app', 'mode' => 'app'] + $nulls, $own['sources'], $app);
            $this->assertNotNull($own['theme']['updatedAt']);
            $this->db(MV_TEST_TENANT_DB)->executeStatement("DELETE FROM app_profile WHERE app = '$app'");
        }
    }

    public function testASiteWithoutAnySettingHasAnEntirelyNullTheme(): void
    {
        $this->client->request('GET', 'https://' . MV_TEST_TENANT2_HOST . '/api/mobile/app-theme', [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT2_HOST]);
        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode(), $response->getContent());
        $body = json_decode($response->getContent(), true);
        $this->assertSame(['app', 'theme', 'sources'], array_keys($body));
        $this->assertSame(self::THEME_KEYS, array_keys($body['theme']), 'les sept champs, primaryColor et updatedAt, toujours présents');
        $this->assertSame(self::NULL_THEME, $body['theme']);
        // Lecture publique, cache HTTP court comme les autres lectures publiques de la boutique
        $this->assertStringContainsString('max-age=60', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('public', (string) $response->headers->get('Cache-Control'));
        $this->assertNotNull($response->headers->get('ETag'));
        $this->assertStringContainsString('X-Tenant-Host', (string) $response->headers->get('Vary'));
    }

    public function testThemesNeverLeakBetweenSites(): void
    {
        $this->putCharter(['primaryColor' => '#1d4ed8', 'accentColor' => '#ffc800']);
        $this->db(MV_TEST_TENANT_DB)->executeStatement("INSERT INTO app_profile (app, theme_mode, home_hero) VALUES ('mobile', 'dark', 'none')");
        $this->db(MV_TEST_TENANT2_DB)->executeStatement("INSERT INTO app_profile (app, theme_mode, product_card) VALUES ('mobile', 'light', 'overlay')");

        $one = $this->theme('mobile', MV_TEST_TENANT_HOST)['theme'];
        $two = $this->theme('mobile', MV_TEST_TENANT2_HOST)['theme'];
        $this->assertSame(['#1d4ed8', '#ffc800', 'dark', null, 'none'], [$one['primaryColor'], $one['accentColor'], $one['mode'], $one['productCard'], $one['homeHero']]);
        $this->assertSame([null, null, 'light', 'overlay', null], [$two['primaryColor'], $two['accentColor'], $two['mode'], $two['productCard'], $two['homeHero']], 'ni la charte ni le profil du premier site');
        // Même ordre d'appel inversé : aucun reste du site précédent
        $this->assertSame('dark', $this->theme('mobile', MV_TEST_TENANT_HOST)['theme']['mode']);
    }

    public function testExistingProfileAndActivationResponsesAreUnchangedForPosAdminAndMobile(): void
    {
        $this->putCharter(['primaryColor' => '#1d4ed8', 'accentColor' => '#ffc800', 'headingFont' => 'Lora', 'radius' => 40, 'buttonStyle' => 'outline']);
        $keys = static::getContainer()->get(AppKeyService::class);
        foreach (['pos', 'admin', 'mobile'] as $app) {
            // Sans profil : forme de la réponse documentée le 20/10/2026, sans « theme », et la charte n'y entre pas
            $none = $this->profile($app);
            $this->assertSame(self::PROFILE_KEYS, array_keys($none), "$app : forme du profil inchangée");
            $this->assertSame([false, null, null], [$none['hasProfile'], $none['primaryColor'], $none['sources']['primaryColor']], "$app : la charte n'entre pas dans le profil");

            // Avec un profil : réponse identique, champ par champ, avant et après le réglage de l'apparence
            $this->db(MV_TEST_TENANT_DB)->executeStatement("INSERT INTO app_profile (app, app_name, primary_color) VALUES ('$app', 'Application $app', '#123abc')");
            $before = $this->profile($app);
            $key = $keys->generate(MV_TEST_TENANT_CODE, $app, "Essai $app", 'test', null)['key'];
            $activationBefore = $this->activate($key, $app);
            $this->db(MV_TEST_TENANT_DB)->executeStatement("UPDATE app_profile SET accent_color = '#ffc800', theme_mode = 'dark', theme_font = 'Poppins', theme_radius = 12, button_style = 'pill', product_card = 'compact', home_hero = 'banner' WHERE app = '$app'");
            $after = $this->profile($app);
            $this->assertSame($before, $after, "$app : GET /api/app-profile identique champ par champ");
            $this->assertSame(self::PROFILE_KEYS, array_keys($after));
            $this->assertSame([true, '#123abc', 'app'], [$after['hasProfile'], $after['primaryColor'], $after['sources']['primaryColor']]);
            $key = $keys->generate(MV_TEST_TENANT_CODE, $app, "Essai $app bis", 'test', null)['key'];
            $activationAfter = $this->activate($key, $app);
            $this->assertSame($activationBefore['profile'], $activationAfter['profile'], "$app : profil de l'activation identique");
            $this->assertSame(self::PROFILE_KEYS, array_keys($activationAfter['profile']));
            $this->assertSame(array_keys($activationBefore), array_keys($activationAfter), "$app : forme de l'activation inchangée");
            $this->assertArrayNotHasKey('theme', $activationAfter);
        }
    }

    private function theme(string $app = 'mobile', string $host = MV_TEST_TENANT_HOST): array
    {
        $this->client->request('GET', 'https://' . $host . '/api/mobile/app-theme' . ($app === 'mobile' ? '' : "?app=$app"), [], [], ['HTTP_X_TENANT_HOST' => $host]);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());

        return json_decode($this->client->getResponse()->getContent(), true);
    }

    private function profile(string $app): array
    {
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . "/api/app-profile/$app?locale=fr", [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());

        return json_decode($this->client->getResponse()->getContent(), true);
    }

    private function activate(string $key, string $app): array
    {
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . '/api/app/activate', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['key' => $key, 'app' => $app]));
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());

        return json_decode($this->client->getResponse()->getContent(), true);
    }

    /** Charte enregistrée comme l'éditeur le fait (PUT, administrateur, jeton Bearer) : elle passe par le journal, d'où la date du thème */
    private function putCharter(array $charter): void
    {
        $this->client->request('PUT', 'https://' . MV_TEST_TENANT_HOST . '/api/boutique-settings', [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $this->adminToken()], json_encode(['configuration' => ['charter' => $charter]]));
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());
    }

    private ?string $token = null;

    /** Jeton d'un administrateur du site, obtenu par /api/login (platform mobile : jeton dans le corps, sans cookie ni CSRF) */
    private function adminToken(): string
    {
        if ($this->token !== null) {
            return $this->token;
        }
        $email = 'theme-admin-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $container = static::getContainer();
        $provider = $container->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Admin')->setLastname('Thème')->setRoles(['ROLE_ADMIN', 'ROLE_USER_INTERNET'])->setIsVerified(true);
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, 'Theme-Test-2026!'));
        $provider->getEntityManager()->persist($user);
        $provider->getEntityManager()->flush();
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . '/api/login', [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json'], json_encode(['username' => $email, 'password' => 'Theme-Test-2026!', 'platform' => 'mobile']));
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());
        $this->client->getCookieJar()->clear();

        return $this->token = json_decode($this->client->getResponse()->getContent(), true)['token'];
    }

    private function admin(string $action, ?int $id = null): \Symfony\Component\DomCrawler\Crawler
    {
        return $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin?' . http_build_query(array_filter(['crudAction' => $action, 'crudControllerFqcn' => AppProfileCrudController::class, 'entityId' => $id])), [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
    }

    private function loginAs(array $roles): void
    {
        $email = 'theme-app-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Admin')->setLastname('Thème')->setRoles($roles)->setIsVerified(true)->setPassword('x');
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
