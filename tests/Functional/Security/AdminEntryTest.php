<?php

namespace App\Tests\Functional\Security;

use App\Entity\User;
use App\Services\Agency\AgencyService;
use App\Services\Entry\AdminPassService;
use App\Services\FeatureAccess\FeatureCatalog;
use App\Services\Platform\TenantDatabaseConnector;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use App\Tests\Functional\Support\OtpTestHelper;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lot AG-3c (17/11/2026) : l'entrée dans le site d'un client DEPUIS EASYADMIN. Le compte d'agence, code validé depuis moins de 12 heures,
 * choisit un site dans le sélecteur ; l'entrée est ouverte et tracée des deux côtés ; un laissez-passer d'une minute, signé, pour ce site,
 * à usage unique, part dans le CORPS d'un formulaire POST (jamais dans une adresse) ; la session du site visé porte l'opérateur extérieur,
 * borné aux écrans de construction (accueil de construction, ventes fermées, champs sensibles retirés, menu piloté par le registre) ;
 * « Quitter » ferme l'entrée. Le commerçant demande l'assistance de son agence depuis EasyAdmin.
 */
class AdminEntryTest extends WebTestCase
{
    private KernelBrowser $client;
    private \PDO $master;
    private string $tag;
    /** @var array<string, array<string, mixed>> */
    private array $sheets = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->master = static::getContainer()->get(TenantConnectionManager::class)->getPdoMaster();
        $this->tag = bin2hex(random_bytes(3));
        $this->clean();
        foreach ([MV_TEST_TENANT_DB, MV_TEST_TENANT2_DB] as $db) {
            $this->sheets[$db] = $this->pdo($db)->query('SELECT id, ' . implode(', ', FeatureCatalog::GOVERNED) . ' FROM entreprise ORDER BY id LIMIT 1')->fetch(\PDO::FETCH_ASSOC) ?: [];
            foreach (FeatureCatalog::GOVERNED as $column) {
                $this->pdo($db)->exec("UPDATE entreprise SET $column = true");
            }
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->sheets as $db => $sheet) {
            if ($sheet === []) {
                continue;
            }
            $stmt = $this->pdo($db)->prepare('UPDATE entreprise SET ' . implode(', ', array_map(static fn (string $c): string => "$c = :$c", FeatureCatalog::GOVERNED)) . ' WHERE id = :id');
            foreach ($sheet as $column => $value) {
                $column === 'id' ? $stmt->bindValue(':id', (int) $value, \PDO::PARAM_INT) : $stmt->bindValue(":$column", in_array($value, [true, 't', 1, '1'], true), \PDO::PARAM_BOOL);
            }
            $stmt->execute();
        }
        $this->clean();
        parent::tearDown();
    }

    public function testAnAgencyEntersTheSiteOfItsClientFromEasyAdminAndSeesOnlyConstruction(): void
    {
        $agency = $this->agencyManaging();
        $this->login($this->user(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE, 'agence', ['ROLE_ADMIN', 'ROLE_AGENCY'], 'Julie', true), MV_TEST_TENANT_HOST, MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);

        // le sélecteur : le site géré, avec « Entrer » ; le menu du site d'attache y mène
        $home = $this->page('GET', '/admin', MV_TEST_TENANT_HOST);
        $this->assertStringContainsString('Les sites de mes clients', $home);
        $selector = $this->page('GET', '/admin/contextes', MV_TEST_TENANT_HOST);
        $this->assertStringContainsString(MV_TEST_TENANT2_CODE, $selector);
        $token = $this->csrf($selector, 'form[action="/admin/contextes/entrer"] input[name="_token"]');

        // « Entrer » : l'entrée est ouverte et tracée ; le laissez-passer part dans le CORPS d'un formulaire POST, jamais dans une adresse
        $passage = $this->page('POST', '/admin/contextes/entrer', MV_TEST_TENANT_HOST, ['_token' => $token, 'site' => MV_TEST_TENANT2_CODE]);
        $this->assertSame('no-store', explode(',', (string) $this->client->getResponse()->headers->get('Cache-Control'))[0]);
        $crawler = $this->client->getCrawler();
        $this->assertSame('/admin/entree?tenant=' . MV_TEST_TENANT2_CODE, $crawler->filter('form#entree')->attr('action'));
        $this->assertSame('post', strtolower((string) $crawler->filter('form#entree')->attr('method')));
        $pass = (string) $crawler->filter('form#entree input[name="pass"]')->attr('value');
        $this->assertNotSame('', $pass);
        $this->assertStringNotContainsString($pass, (string) $crawler->filter('form#entree')->attr('action'));
        $entry = $this->master->query("SELECT * FROM site_entries WHERE mode = 'agency'")->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame([MV_TEST_TENANT_CODE, MV_TEST_TENANT2_CODE, null], [$entry['home_tenant_code'], $entry['target_tenant_code'], $entry['ended_at']]);
        $this->assertGreaterThan(3000, strtotime($entry['expires_at'] . ' UTC') - time(), 'une entrée d\'EasyAdmin vaut une heure');
        $this->assertSame(1, (int) $this->pdo(MV_TEST_TENANT2_DB)->query("SELECT COUNT(*) FROM content_audit_log WHERE resource = 'external_access' AND action = 'entry'")->fetchColumn(), 'tracée au journal du site visé');
        unset($passage);

        // le passage : la session du site visé porte l'opérateur extérieur ; l'accueil est une page de construction, jamais les ventes
        $this->client->request('POST', 'https://' . MV_TEST_TENANT2_HOST . '/admin/entree?tenant=' . MV_TEST_TENANT2_CODE, ['pass' => $pass], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT2_HOST]);
        $this->assertSame(302, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
        $welcome = $this->page('GET', '/admin', MV_TEST_TENANT2_HOST);
        $this->assertStringContainsString('Agence Atelier Web, Julie', $welcome);
        $this->assertStringNotContainsString('Tableau de Bord E-Commerce', $welcome);
        $this->assertStringNotContainsString('Les sites de mes clients', $welcome, 'pas de sélecteur pendant une entrée');
        $this->assertStringNotContainsString('Commandes', $this->menu($welcome), 'le menu suit le registre : pas de ventes');

        // écrans : construction ouverte (sans les champs sensibles), ventes et réglages sensibles fermés en page HTML
        $products = $this->page('GET', '/admin?crudAction=index&crudControllerFqcn=' . rawurlencode('App\\Controller\\Admin\\ProductCrudController'), MV_TEST_TENANT2_HOST);
        $this->assertStringContainsString('data-column="name"', $products);
        $this->assertStringNotContainsString('data-column="purchasePrice"', $products, 'le coût d\'achat est retiré');
        $this->assertStringNotContainsString('data-column="coefficientMultiplier"', $products);
        foreach (['OrderCrudController', 'UserCrudController', 'EmailConfigurationCrudController', 'CurrencyCrudController', 'AiCreditSettingCrudController', 'InventoryMovementsCrudController'] as $closed) {
            $this->client->request('GET', 'https://' . MV_TEST_TENANT2_HOST . '/admin?crudAction=index&crudControllerFqcn=' . rawurlencode('App\\Controller\\Admin\\' . $closed), [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT2_HOST]);
            $this->assertSame(403, $this->client->getResponse()->getStatusCode(), $closed);
            $this->assertStringContainsString('text/html', (string) $this->client->getResponse()->headers->get('Content-Type'));
        }
        $this->client->request('GET', 'https://' . MV_TEST_TENANT2_HOST . '/admin/contextes', [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT2_HOST]);
        $this->assertSame(403, $this->client->getResponse()->getStatusCode(), 'le sélecteur n\'est pas ouvert à l\'opérateur');
        $this->client->request('GET', 'https://' . MV_TEST_TENANT2_HOST . '/admin?crudAction=index&crudControllerFqcn=' . rawurlencode('App\\Controller\\Admin\\Inconnu'), [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT2_HOST]);
        $this->assertSame(403, $this->client->getResponse()->getStatusCode(), 'un écran inconnu du registre est fermé');

        // le même laissez-passer ne ressert jamais
        $this->client->request('POST', 'https://' . MV_TEST_TENANT2_HOST . '/admin/entree?tenant=' . MV_TEST_TENANT2_CODE, ['pass' => $pass], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT2_HOST]);
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->assertStringContainsString('pass_replayed', (string) $this->client->getResponse()->getContent());

        // agence suspendue : la requête suivante de la session est refusée, la session vidée
        $this->login($this->user(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE, 'agence2', ['ROLE_ADMIN', 'ROLE_AGENCY'], 'Paul', true), MV_TEST_TENANT_HOST, MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $pass2 = $this->passFor(MV_TEST_TENANT2_CODE);
        $this->client->request('POST', 'https://' . MV_TEST_TENANT2_HOST . '/admin/entree?tenant=' . MV_TEST_TENANT2_CODE, ['pass' => $pass2], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT2_HOST]);
        $this->assertSame(302, $this->client->getResponse()->getStatusCode());
        $this->master->exec("UPDATE agencies SET status = 'suspended' WHERE id = $agency");
        $this->forgetAll();
        $this->client->request('GET', 'https://' . MV_TEST_TENANT2_HOST . '/admin', [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT2_HOST]);
        $this->assertSame(302, $this->client->getResponse()->getStatusCode(), 'agence suspendue : plus d\'écran');
        $this->assertStringNotContainsString('Construction du site', (string) $this->client->getResponse()->getContent());
        $this->master->exec("UPDATE agencies SET status = 'trial' WHERE id = $agency");
    }

    public function testQuittingClosesTheEntryAndAPassIsRefusedOnAnotherSiteForgedOrExpired(): void
    {
        $this->agencyManaging();
        $this->login($this->user(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE, 'agence', ['ROLE_ADMIN', 'ROLE_AGENCY'], 'Julie', true), MV_TEST_TENANT_HOST, MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $pass = $this->passFor(MV_TEST_TENANT2_CODE);
        $service = static::getContainer()->get(AdminPassService::class);
        [$body] = explode('.', $pass);
        foreach ([
            'pass_signature' => $body . '.' . str_repeat('A', 43),
            'pass_site' => $service->issue(['entry' => 1, 'mode' => 'agency', 'home' => MV_TEST_TENANT_CODE, 'target' => 'autre-site', 'otpAt' => time()]),
            'pass_expired' => $service->issue(['entry' => 1, 'mode' => 'agency', 'home' => MV_TEST_TENANT_CODE, 'target' => MV_TEST_TENANT2_CODE, 'otpAt' => time(), 'exp' => time() - 1]),
            'pass_malformed' => 'pas-un-laissez-passer',
        ] as $reason => $forged) {
            $this->client->request('POST', 'https://' . MV_TEST_TENANT2_HOST . '/admin/entree?tenant=' . MV_TEST_TENANT2_CODE, ['pass' => $forged], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT2_HOST]);
            $this->assertSame(403, $this->client->getResponse()->getStatusCode(), $reason);
            $this->assertStringContainsString($reason, (string) $this->client->getResponse()->getContent());
        }
        // un code vieux de plus de 12 heures : le laissez-passer est refusé au passage
        $old = $service->issue(['entry' => 1, 'mode' => 'agency', 'home' => MV_TEST_TENANT_CODE, 'target' => MV_TEST_TENANT2_CODE, 'otpAt' => time() - 43201]);
        $this->client->request('POST', 'https://' . MV_TEST_TENANT2_HOST . '/admin/entree?tenant=' . MV_TEST_TENANT2_CODE, ['pass' => $old], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT2_HOST]);
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());

        // le vrai passage, puis « Quitter » : entrée fermée, session vidée, retour au site d'attache
        $this->client->request('POST', 'https://' . MV_TEST_TENANT2_HOST . '/admin/entree?tenant=' . MV_TEST_TENANT2_CODE, ['pass' => $pass], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT2_HOST]);
        $this->assertSame(302, $this->client->getResponse()->getStatusCode());
        $welcome = $this->page('GET', '/admin', MV_TEST_TENANT2_HOST);
        $token = $this->csrf($welcome, 'form[action="/admin/entree/quitter"] input[name="_token"]');
        $this->client->request('POST', 'https://' . MV_TEST_TENANT2_HOST . '/admin/entree/quitter', ['_token' => $token], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT2_HOST]);
        $this->assertSame('/?tenant=' . MV_TEST_TENANT_CODE, $this->client->getResponse()->headers->get('Location'));
        $this->assertSame('left', $this->master->query("SELECT ended_reason FROM site_entries WHERE mode = 'agency' ORDER BY id DESC LIMIT 1")->fetchColumn());
        $this->client->request('GET', 'https://' . MV_TEST_TENANT2_HOST . '/admin', [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT2_HOST]);
        $this->assertSame(302, $this->client->getResponse()->getStatusCode(), 'plus de session sur le site visé');
    }

    public function testWithoutARecentCodeNoEntryOpensAndAnOrdinaryAdminHasNoSelector(): void
    {
        $this->agencyManaging();
        // un compte d'agence sans code (ni activé sur le compte ni imposé) : pas d'entrée depuis EasyAdmin
        $this->login($this->user(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE, 'sanscode', ['ROLE_ADMIN', 'ROLE_AGENCY'], 'Lou', false), MV_TEST_TENANT_HOST, MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $selector = $this->page('GET', '/admin/contextes', MV_TEST_TENANT_HOST);
        $token = $this->csrf($selector, 'form[action="/admin/contextes/entrer"] input[name="_token"]');
        $before = (int) $this->master->query('SELECT COUNT(*) FROM site_entries')->fetchColumn();
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . '/admin/contextes/entrer', ['_token' => $token, 'site' => MV_TEST_TENANT2_CODE], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->assertStringContainsString('otp', (string) $this->client->getResponse()->getContent());
        $this->assertSame($before, (int) $this->master->query('SELECT COUNT(*) FROM site_entries')->fetchColumn(), 'aucune entrée ouverte');

        // un administrateur ordinaire : ni sélecteur ni entrée ; son menu montre « Assistance de mon agence »
        $this->login($this->user(MV_TEST_TENANT2_DB, MV_TEST_TENANT2_CODE, 'commercant', ['ROLE_ADMIN'], 'Claire', false), MV_TEST_TENANT2_HOST, MV_TEST_TENANT2_DB, MV_TEST_TENANT2_CODE);
        $home = $this->page('GET', '/admin', MV_TEST_TENANT2_HOST);
        $this->assertStringNotContainsString('Les sites de mes clients', $home);
        $this->assertStringContainsString('Modules disponibles', $home);
        $this->assertStringContainsString('Assistance de mon agence', $home);
        $this->client->request('GET', 'https://' . MV_TEST_TENANT2_HOST . '/admin/contextes', [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT2_HOST]);
        $this->assertSame(302, $this->client->getResponse()->getStatusCode());

        // le commerçant demande l'assistance « caisse » depuis EasyAdmin (section sans écran dans le web)
        $page = $this->page('GET', '/admin/assistance', MV_TEST_TENANT2_HOST);
        $token = $this->csrf($page, 'form[action="/admin/assistance/demander"] input[name="_token"]');
        $this->client->request('POST', 'https://' . MV_TEST_TENANT2_HOST . '/admin/assistance/demander', ['_token' => $token, 'section' => 'caisse', 'mode' => 'read'], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT2_HOST]);
        $this->assertSame(302, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
        $this->assertSame(['caisse', 'read'], $this->master->query("SELECT section, mode FROM assistance_grants WHERE tenant_code = '" . MV_TEST_TENANT2_CODE . "'")->fetch(\PDO::FETCH_NUM));

        // un module éteint : ses entrées quittent le menu du commerçant
        $this->pdo(MV_TEST_TENANT2_DB)->exec('UPDATE entreprise SET ' . FeatureCatalog::GOVERNED['module.memoires'] . ' = false');
        $this->assertStringNotContainsString('Mémoires Vivantes', $this->menu($this->page('GET', '/admin', MV_TEST_TENANT2_HOST)));
    }

    private function agencyManaging(): int
    {
        $agencies = static::getContainer()->get(AgencyService::class);
        $id = (int) $agencies->create(['code' => 'agence-ea-' . $this->tag, 'name' => 'Atelier Web', 'homeTenantCode' => MV_TEST_TENANT_CODE, 'test' => true], 'essai')['id'];
        $agencies->attach($id, MV_TEST_TENANT2_CODE, 'essai');

        return $id;
    }

    /** Le passage d'un compte d'agence déjà connecté sur le site d'attache : sélecteur, « Entrer », laissez-passer */
    private function passFor(string $site): string
    {
        $selector = $this->page('GET', '/admin/contextes', MV_TEST_TENANT_HOST);
        $token = $this->csrf($selector, 'form[action="/admin/contextes/entrer"] input[name="_token"]');
        $this->page('POST', '/admin/contextes/entrer', MV_TEST_TENANT_HOST, ['_token' => $token, 'site' => $site]);

        return (string) $this->client->getCrawler()->filter('form#entree input[name="pass"]')->attr('value');
    }

    private function user(string $db, string $code, string $name, array $roles, string $firstName, bool $otp): User
    {
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant($db, $code);
        $em = $provider->getEntityManager();
        $email = "entree-ea-$name-{$this->tag}@example.invalid";
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname($firstName)->setLastname('Essai')->setRoles($roles)->setPassword('inutilisable')->setIsVerified(true)->setOtpEnabled($otp);
        $em->persist($user);
        $em->flush();

        return $user;
    }

    private function login(User $user, string $host, string $db, string $code): void
    {
        $this->client->getCookieJar()->clear();
        $this->client->loginUser($user, 'main');
        OtpTestHelper::validateIfRequired($this->client, $user, $host, $db, $code);
    }

    private function page(string $method, string $path, string $host, array $body = []): string
    {
        $this->client->request($method, 'https://' . $host . $path, $body, [], ['HTTP_X_TENANT_HOST' => $host]);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), $method . ' ' . $path . ' : ' . mb_substr((string) $this->client->getResponse()->getContent(), 0, 400));

        return (string) $this->client->getResponse()->getContent();
    }

    private function csrf(string $html, string $selector): string
    {
        $value = (new \Symfony\Component\DomCrawler\Crawler($html))->filter($selector)->attr('value');
        $this->assertNotEmpty($value, $selector);

        return (string) $value;
    }

    /** Le menu de gauche seul (les libellés du contenu de la page n'y comptent pas) */
    private function menu(string $html): string
    {
        $menu = (new \Symfony\Component\DomCrawler\Crawler($html))->filter('#main-menu, .main-sidebar, nav');

        return $menu->count() > 0 ? $menu->first()->text() : '';
    }

    private function forgetAll(): void
    {
        foreach ($this->master->query('SELECT id FROM site_entries')->fetchAll(\PDO::FETCH_COLUMN) as $id) {
            static::getContainer()->get('cache.app')->delete('site_entry_' . $id);
        }
    }

    private function clean(): void
    {
        $this->master->exec('DELETE FROM assistance_grants');
        $this->master->exec('DELETE FROM site_entries');
        foreach (['agency_events', 'agency_sites'] as $table) {
            $this->master->exec("DELETE FROM $table");
        }
        $this->master->exec('DELETE FROM agency_plans WHERE agency_id IS NOT NULL');
        $this->master->exec('DELETE FROM agencies');
        foreach ([MV_TEST_TENANT_DB, MV_TEST_TENANT2_DB] as $db) {
            $this->pdo($db)->exec("DELETE FROM otp_code WHERE user_otp_id IN (SELECT id FROM \"user\" WHERE email LIKE 'entree-ea-%@example.invalid')");
            $this->pdo($db)->exec("DELETE FROM \"user\" WHERE email LIKE 'entree-ea-%@example.invalid'");
            $this->pdo($db)->exec("DELETE FROM content_audit_log WHERE resource = 'external_access'");
        }
    }

    private function pdo(string $db): \PDO
    {
        return static::getContainer()->get(TenantDatabaseConnector::class)->pdo($db);
    }
}
