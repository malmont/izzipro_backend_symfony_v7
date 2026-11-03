<?php

namespace App\Tests\Functional\Admin;

use App\Entity\User;
use App\Services\TenantEntityManagerProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Ergonomie de l'EasyAdmin (31/10/2026) : fiche Entreprise en onglets avec panneaux repliables,
 * tableau de bord aux statistiques réelles (commandes validées, répartitions lues en base, vue réseau réservée au propriétaire).
 */
class AdminErgonomicsTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->setServerParameter('HTTP_X_TENANT_HOST', MV_TEST_TENANT_HOST);
    }

    private function login(array $roles = ['ROLE_ADMIN']): void
    {
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $email = 'ergo-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Ergo')->setLastname('Test')->setRoles($roles)->setIsVerified(true)->setPassword('x');
        $provider->getEntityManager()->persist($user);
        $provider->getEntityManager()->flush();
        $this->client->loginUser($user, 'main');
        \App\Tests\Functional\Support\OtpTestHelper::validateIfRequired($this->client, $user); // super admin : code OTP toujours exigé (03/11/2026)
    }

    private function admin(array $query): string
    {
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin?' . http_build_query($query));
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), json_encode($query));

        return (string) $this->client->getResponse()->getContent();
    }

    public function testTheCompanyFormHasTabsAndCollapsiblePanels(): void
    {
        $this->login();
        $id = (int) static::getContainer()->get('doctrine')->getConnection()->fetchOne('SELECT MIN(id) FROM entreprise');
        $this->assertGreaterThan(0, $id, 'une fiche entreprise existe sur le site de test');
        $html = $this->admin(['crudAction' => 'edit', 'crudControllerFqcn' => 'App\Controller\Admin\EntrepriseCrudController', 'entityId' => $id]);

        foreach (['entreprise', 'applications', 'caisse', 'site-web-et-seo', 'traductions'] as $tab) {
            $this->assertStringContainsString('href="#tab-' . $tab . '"', $html, "onglet $tab");
        }
        $this->assertStringContainsString('form-fieldset-collapse-marker', $html, 'flèche de repli sur les panneaux');
        $this->assertGreaterThanOrEqual(20, substr_count($html, 'form-fieldset-collapse-marker'), 'tous les panneaux sont repliables');
        $this->assertSame(12, preg_match_all('/class="form-fieldset-body\s+collapse\s*"/', $html), 'les douze sections secondaires de la caisse sont repliées par défaut');
        $this->assertGreaterThanOrEqual(9, preg_match_all('/class="form-fieldset-body\s+collapse\s+show"/', $html), 'les autres sections restent ouvertes');
        $this->assertStringContainsString('assets/js/admin-arkanoa.js', $html, 'script qui déplie un panneau en erreur et mémorise l\'état');
        // Champs clés toujours présents dans le formulaire
        foreach (['Entreprise[name]', 'Entreprise[isPosActive]', 'Entreprise[posTimezone]', 'Entreprise[metaTitle]', 'Entreprise[facebookPixelId]', 'Entreprise[formSubmissionsEmail]'] as $field) {
            $this->assertStringContainsString('name="' . $field . '"', $html, $field);
        }
    }

    public function testOtherFormsStillRenderWithCollapsiblePanels(): void
    {
        $this->login();
        foreach (['AppProfileCrudController', 'ProductCrudController', 'EmailConfigurationCrudController', 'PackagingTypeCrudController', 'CashRegisterCrudController', 'BookingConfigurationCrudController'] as $controller) {
            $html = $this->admin(['crudAction' => 'new', 'crudControllerFqcn' => 'App\Controller\Admin\\' . $controller]);
            $this->assertStringContainsString('form-fieldset-collapse-marker', $html, $controller);
        }
    }

    /** Formulaires à champ JSON (JsonTextType) : ils plantaient tous (champ déclaré avec Field::new, transformateur absent) */
    public function testJsonFormsOpen(): void
    {
        $this->login(['ROLE_ADMIN', 'ROLE_SUPER_ADMIN']);
        foreach (['BoutiqueSettingCrudController', 'LandingPageSettingCrudController', 'SubscriptionPlanCrudController', 'ReviewSettingCrudController', 'BookingConfigurationCrudController'] as $controller) {
            $html = $this->admin(['crudAction' => 'new', 'crudControllerFqcn' => 'App\Controller\Admin\\' . $controller]);
            $this->assertStringContainsString('<textarea', $html, $controller);
        }
    }

    /** Raccourci « Apparence de l'app mobile » : fiche du profil mobile, ou sa création s'il n'existe pas */
    public function testTheMobileThemeShortcutOpensTheMobileProfile(): void
    {
        $db = static::getContainer()->get('doctrine')->getConnection();
        $db->executeStatement("DELETE FROM app_profile_translation WHERE app_profile_id IN (SELECT id FROM app_profile WHERE app = 'mobile')");
        $db->executeStatement("DELETE FROM app_profile WHERE app = 'mobile'");
        $this->login();
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin/mobile-theme');
        $this->assertSame(302, $this->client->getResponse()->getStatusCode());
        $target = (string) $this->client->getResponse()->headers->get('Location');
        $this->assertStringContainsString('crudAction=new', $target, 'sans profil : création');
        $this->assertStringContainsString('AppProfileCrudController', $target);

        $db->executeStatement("INSERT INTO app_profile (app, theme_mode) VALUES ('mobile', 'dark')");
        $id = (int) $db->fetchOne("SELECT id FROM app_profile WHERE app = 'mobile'");
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin/mobile-theme');
        $target = (string) $this->client->getResponse()->headers->get('Location');
        $this->assertStringContainsString('crudAction=edit', $target, 'avec profil : modification');
        $this->assertStringContainsString('entityId=' . $id, $target);
        $html = $this->admin(['crudAction' => 'edit', 'crudControllerFqcn' => 'App\\Controller\\Admin\\AppProfileCrudController', 'entityId' => $id]);
        $this->assertStringContainsString("Apparence de l'application", html_entity_decode($html), 'panneau du thème');
        $db->executeStatement("DELETE FROM app_profile WHERE app = 'mobile'");
        $this->assertMatchesRegularExpression('/Apparence de l.{1,6}app mobile/', $this->admin([]), 'entrée du menu');
    }

    public function testTheJsonTransformerRoundTripsAndRefusesInvalidJson(): void
    {
        $t = new \App\Form\DataTransformer\JsonToStringTransformer();
        $this->assertSame('', $t->transform(null));
        $this->assertSame('', $t->transform([]));
        $this->assertSame("{\n    \"fr\": \"Été\"\n}", $t->transform(['fr' => 'Été']));
        $this->assertNull($t->reverseTransform(''));
        $this->assertNull($t->reverseTransform('   '));
        $this->assertSame(['fr' => 'Été', 'n' => [1, 2]], $t->reverseTransform('{"fr": "Été", "n": [1, 2]}'));
        foreach (['{bad', '"texte"', '12'] as $invalid) {
            try {
                $t->reverseTransform($invalid);
                $this->fail("$invalid accepté");
            } catch (\Symfony\Component\Form\Exception\TransformationFailedException $e) {
                $this->assertNotEmpty($e->getInvalidMessage(), 'message affiché dans le formulaire');
            }
        }
    }

    public function testTheDashboardCountsValidatedOrdersAndHidesTheNetworkViewFromSiteAdmins(): void
    {
        $db = static::getContainer()->get('doctrine')->getConnection();
        $cancelled = (int) $db->fetchOne("SELECT id FROM status_commande WHERE name = 'Annulation'");
        $done = (int) $db->fetchOne("SELECT id FROM status_commande WHERE name = 'Complétée'");
        $this->assertGreaterThan(0, $cancelled * $done);
        $db->executeStatement('DELETE FROM "order" WHERE reference LIKE :r', ['r' => 'ERGO-%']);
        $db->executeStatement('INSERT INTO "order" (id, reference, order_date, total_amount, status_id, order_source_id, cash_rounding, discount_amount) VALUES
            (nextval(\'order_id_seq\'), \'ERGO-OK\', NOW(), 12345, :done, 1, 0, 0), (nextval(\'order_id_seq\'), \'ERGO-CANCELLED\', NOW(), 99999, :cancelled, 1, 0, 0)', ['done' => $done, 'cancelled' => $cancelled]);
        $expected = ((float) $db->fetchOne('SELECT COALESCE(SUM(total_amount), 0) FROM "order" WHERE status_id IS NULL OR status_id NOT IN (SELECT id FROM status_commande WHERE name IN (\'Incomplete\', \'Annulation\'))')) / 100.0;

        $this->login();
        $html = $this->admin([]);
        $this->assertStringContainsString(number_format($expected, 2, ',', ' ') . ' $', $html, 'chiffre d\'affaires des commandes validées');
        $this->assertStringNotContainsString(number_format($expected + 999.99, 2, ',', ' ') . ' $', $html, 'la commande annulée n\'est pas comptée');
        $this->assertStringContainsString('hors annulées et incomplètes', $html);
        // Menu de gauche : le tableau de bord à plat, puis 16 groupes repliables (flèche) à la place des sections ; le groupe
        // « Maintenance » (cache Redis, workers, tableau de bord technique, compte super administrateur) n'existe que pour le propriétaire (03/11/2026)
        $this->assertSame(16, substr_count($html, 'has-submenu'), 'chaque section du menu est un groupe repliable');
        $this->assertSame(16, substr_count($html, 'submenu-toggle-icon'), 'une flèche par groupe');
        $this->assertStringNotContainsString('Maintenance', $html, 'groupe Maintenance absent pour un administrateur de site');
        $this->assertStringNotContainsString('class="menu-header', $html, 'plus de section non repliable');
        $this->assertStringContainsString('>Caisse<', preg_replace('/\s+/', '', $html), 'groupe Caisse');
        $this->assertStringNotContainsString('Comparer tous les sites', $html, 'vue réseau cachée à un administrateur de site');
        $this->assertStringNotContainsString("'Transporteur Default'", $html, 'plus de répartition inventée');
        $this->assertStringNotContainsString("series: [100, 50, 20]", $html);
        $this->assertMatchesRegularExpression('/labels: \[("[^"]+"(,\s*)?)+\]/', $html, 'répartition des transporteurs lue en base');

        $this->login(['ROLE_ADMIN', 'ROLE_SUPER_ADMIN']);
        $html = $this->admin([]);
        $this->assertStringContainsString('Comparer tous les sites', $html, 'vue réseau visible au propriétaire');
        $db->executeStatement('DELETE FROM "order" WHERE reference LIKE :r', ['r' => 'ERGO-%']);
    }
}
