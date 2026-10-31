<?php

namespace App\Tests\Functional\Admin;

use App\Entity\User;
use App\Services\AppProfileService\AppThemePreviewService;
use App\Services\TenantEntityManagerProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Aperçu en direct de l'apparence dans EasyAdmin (B3, 31/10/2026) : gabarits servis par /admin/apercu/{code} aux seuls
 * administrateurs, en-têtes de cadre de même origine, cadre et repli dans le formulaire du profil, service de fusion.
 */
class ApercuAdminTest extends WebTestCase
{
    private const CHARTER = '{"charter": {"primaryColor": "#1D4ED8", "accentColor": "rgb(255, 200, 0)", "headingFont": "lora", "radius": 40, "buttonStyle": "outline"}}';
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->setServerParameter('HTTP_X_TENANT_HOST', MV_TEST_TENANT_HOST);
        $this->clean();
    }

    protected function tearDown(): void
    {
        $this->clean();
        parent::tearDown();
    }

    private function clean(): void
    {
        $db = static::getContainer()->get('doctrine')->getConnection();
        $db->executeStatement('DELETE FROM app_profile_translation');
        $db->executeStatement('DELETE FROM app_profile');
        $db->executeStatement('DELETE FROM boutique_setting');
    }

    private function login(array $roles = ['ROLE_ADMIN']): void
    {
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $email = 'apercu-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Aperçu')->setLastname('Test')->setRoles($roles)->setIsVerified(true)->setPassword('x');
        $provider->getEntityManager()->persist($user);
        $provider->getEntityManager()->flush();
        $this->client->loginUser($user, 'main');
    }

    private function get(string $path): string
    {
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . $path);

        return (string) $this->client->getResponse()->getContent();
    }

    public function testTemplatesAreServedWithSameOriginFramingHeadersAndNoCdn(): void
    {
        $this->login();
        $this->assertFileExists(static::getContainer()->getParameter('kernel.project_dir') . '/public/assets/vendor/material-color-utilities-0.3.0.mjs', 'module de couleurs servi en local');
        foreach (['mobile', 'livingmemory'] as $code) {
            $html = $this->get("/admin/apercu/$code?embed=1");
            $response = $this->client->getResponse();
            $this->assertSame(200, $response->getStatusCode(), $code);
            $this->assertStringContainsString('text/html', (string) $response->headers->get('Content-Type'));
            $this->assertStringContainsString('window.ApercuApparence', $html, "$code : contrat du gabarit");
            $this->assertStringContainsString("Source : dépôt de l'application", $html, "$code : commentaire de tête");
            $this->assertStringNotContainsString('jsdelivr', $html, "$code : aucun CDN pour le module");
            $this->assertStringNotContainsString('unpkg.com', $html);
            $this->assertSame('SAMEORIGIN', $response->headers->get('X-Frame-Options'), "$code : cadre de même origine autorisé");
            $this->assertSame("frame-ancestors 'self'", $response->headers->get('Content-Security-Policy'), "$code : pas de CSP qui bloque le module local ou Google Fonts");
        }
        $this->assertStringContainsString('/assets/vendor/material-color-utilities-0.3.0.mjs', $this->get('/admin/apercu/mobile'), 'le gabarit mobile charge la copie locale');
        foreach (['boussole', 'pos', 'inconnu'] as $code) {
            $this->get("/admin/apercu/$code");
            $this->assertSame(404, $this->client->getResponse()->getStatusCode(), "$code : pas de gabarit");
        }
        $this->get('/admin/apercu/Mobile');
        $this->assertSame(404, $this->client->getResponse()->getStatusCode(), 'code en majuscules refusé par la route');
    }

    public function testTemplatesAreReservedToAdministrators(): void
    {
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin/apercu/mobile');
        $this->assertSame(302, $this->client->getResponse()->getStatusCode(), 'anonyme : renvoyé vers la connexion');
        $this->login(['ROLE_USER_POS']);
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin/apercu/mobile');
        $this->assertSame(403, $this->client->getResponse()->getStatusCode(), 'un caissier n\'a pas le rôle du panneau');
    }

    public function testTheProfileFormCarriesTheFrameAndTheFallbackOnlyForMobile(): void
    {
        $db = static::getContainer()->get('doctrine')->getConnection();
        $db->executeStatement('INSERT INTO boutique_setting (configuration) VALUES (:c)', ['c' => self::CHARTER]);
        $this->login();
        $this->assertStringContainsString('assets/js/admin-apercu.js', $this->get('/admin?crudAction=new&crudControllerFqcn=App%5CController%5CAdmin%5CAppProfileCrudController'), 'script du formulaire');
        $new = $this->payloadOf($this->client->getResponse()->getContent());
        $this->assertNull($new['app']);
        $this->assertNull($new['url'], 'création : application pas encore choisie');
        $this->assertSame(['livingmemory', 'mobile'], $new['templates']);
        $this->assertSame('#1d4ed8', $new['fallbackMobile']['primaryColor'], 'le repli du code mobile est fourni pour le choix de l\'application');

        foreach (['mobile' => '/admin/apercu/mobile?embed=1', 'livingmemory' => '/admin/apercu/livingmemory?embed=1', 'boussole' => null] as $app => $url) {
            $db->executeStatement("INSERT INTO app_profile (app, app_name, theme_mode) VALUES ('$app', 'Mon appli', 'dark')");
            $id = (int) $db->fetchOne("SELECT id FROM app_profile WHERE app = '$app'");
            $html = $this->get('/admin?' . http_build_query(['crudAction' => 'edit', 'crudControllerFqcn' => 'App\Controller\Admin\AppProfileCrudController', 'entityId' => $id]));
            $this->assertSame(200, $this->client->getResponse()->getStatusCode(), $app);
            $payload = $this->payloadOf($html);
            $this->assertSame($app, $payload['app']);
            $this->assertSame($url, $payload['url'], $app);
            $this->assertSame('Mon appli', $payload['profil']['name'], "$app : nom du profil tel que l'application le reçoit");
            $this->assertArrayHasKey('logo', $payload['profil']);
            if ($app === 'mobile') {
                $this->assertSame(['primaryColor' => '#1d4ed8', 'accentColor' => '#ffc800', 'mode' => null, 'font' => 'Lora', 'radius' => 28, 'buttonStyle' => 'outline', 'productCard' => null, 'homeHero' => null], $payload['fallback'], 'mobile : repli de la charte, mêmes conversions que app-theme');
                $this->assertStringNotContainsString('class="apercu-unavailable"', $html, 'mobile : pas de message « non disponible »');
            } else {
                $this->assertSame(array_fill_keys(['primaryColor', 'accentColor', 'mode', 'font', 'radius', 'buttonStyle', 'productCard', 'homeHero'], null), $payload['fallback'], "$app : aucun repli, le gabarit applique ses défauts");
            }
            if ($url === null) {
                $this->assertMatchesRegularExpression('/class="apercu-unavailable">' . preg_quote(AppThemePreviewService::UNAVAILABLE, '/') . '/', html_entity_decode($html), "$app : message sans gabarit");
            }
        }
    }

    public function testThePreviewServiceMergesTheCharterOnlyForMobile(): void
    {
        $db = static::getContainer()->get('doctrine')->getConnection();
        $db->executeStatement('INSERT INTO boutique_setting (configuration) VALUES (:c)', ['c' => self::CHARTER]);
        static::getContainer()->get(TenantEntityManagerProvider::class)->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $service = static::getContainer()->get(AppThemePreviewService::class);
        $this->assertSame(['livingmemory', 'mobile'], $service->templates());
        $this->assertNull($service->templatePath('boussole'));
        $this->assertNull($service->embedUrl('admin'));
        $this->assertSame('/admin/apercu/mobile?embed=1', $service->embedUrl('mobile'));

        $mobile = $service->payload('mobile');
        $this->assertSame('#1d4ed8', $mobile['fallback']['primaryColor']);
        $this->assertSame('Lora', $mobile['fallback']['font']);
        $this->assertSame(28, $mobile['fallback']['radius']);
        $this->assertNull($mobile['fallback']['mode'], 'la charte n\'a pas de mode');
        $other = $service->payload('livingmemory');
        $this->assertSame(array_fill_keys(['primaryColor', 'accentColor', 'mode', 'font', 'radius', 'buttonStyle', 'productCard', 'homeHero'], null), $other['fallback']);
        $this->assertSame($mobile['fallback'], $other['fallbackMobile']);
        $none = $service->payload(null);
        $this->assertSame([null, null, ['name' => null, 'logo' => null]], [$none['app'], $none['url'], $none['profil']]);

        $db->executeStatement('DELETE FROM boutique_setting');
        $empty = $service->payload('mobile');
        $this->assertSame(array_fill_keys(['primaryColor', 'accentColor', 'mode', 'font', 'radius', 'buttonStyle', 'productCard', 'homeHero'], null), $empty['fallback'], 'site sans charte : null partout');
    }

    private function payloadOf(string $html): array
    {
        $this->assertSame(1, preg_match('/data-apercu="([^"]+)"/', $html, $m), 'données de l\'aperçu dans le panneau');

        return json_decode(html_entity_decode($m[1], ENT_QUOTES), true) ?? [];
    }
}
