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
        $this->assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
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
        $this->page('GET', '/admin?crudAction=index&crudControllerFqcn=' . rawurlencode('App\\Controller\\Admin\\ProductCrudController'), MV_TEST_TENANT2_HOST);
        // le formulaire de création montre tous les champs, même sans article : le coût d'achat et le coefficient n'y sont plus
        $form = $this->page('GET', '/admin?crudAction=new&crudControllerFqcn=' . rawurlencode('App\\Controller\\Admin\\ProductCrudController'), MV_TEST_TENANT2_HOST);
        $this->assertStringContainsString('Product[name]', $form);
        $this->assertStringNotContainsString('Product[purchasePrice]', $form, 'le coût d\'achat est retiré');
        $this->assertStringNotContainsString('Product[coefficientMultiplier]', $form);
        foreach (['OrderCrudController', 'UserCrudController', 'EmailConfigurationCrudController', 'CurrencyCrudController', 'AiCreditSettingCrudController', 'InventoryMovementsCrudController'] as $closed) {
            $this->client->request('GET', 'https://' . MV_TEST_TENANT2_HOST . '/admin?crudAction=index&crudControllerFqcn=' . rawurlencode('App\\Controller\\Admin\\' . $closed), [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT2_HOST]);
            $this->assertSame(403, $this->client->getResponse()->getStatusCode(), $closed);
            $this->assertStringContainsString('text/html', (string) $this->client->getResponse()->headers->get('Content-Type'));
        }
        $this->client->request('GET', 'https://' . MV_TEST_TENANT2_HOST . '/admin/contextes', [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT2_HOST]);
        $this->assertSame(403, $this->client->getResponse()->getStatusCode(), 'le sélecteur n\'est pas ouvert à l\'opérateur');
        // pages dont l'équivalent de l'API est fermé : chiffres et gestes de module, paiements comptés, journal des accès, ouvrir la boutique d'un
        // site rattaché (seule l'agence qui l'a créé l'ouvre)
        foreach (['GET /admin/mon-site', 'POST /admin/mon-site/essayer/module.pos', 'GET /admin/configuration', 'POST /admin/configuration/courriel-essai', 'GET /admin/acces-plateforme', 'POST /admin/ouverture-du-site/ouvrir'] as $closed) {
            [$method, $path] = explode(' ', $closed);
            $this->client->request($method, 'https://' . MV_TEST_TENANT2_HOST . $path, [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT2_HOST]);
            $this->assertSame(403, $this->client->getResponse()->getStatusCode(), $closed);
        }
        $this->assertStringNotContainsString('Mon site : pilotage', $this->menu($welcome), 'une page fermée quitte aussi le menu');
        $this->page('GET', '/admin/ouverture-du-site', MV_TEST_TENANT2_HOST); // la liste d'ouverture reste ouverte
        $this->client->request('GET', 'https://' . MV_TEST_TENANT2_HOST . '/admin?crudAction=index&crudControllerFqcn=' . rawurlencode('App\\Controller\\Admin\\Inconnu'), [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT2_HOST]);
        $this->assertSame(403, $this->client->getResponse()->getStatusCode(), 'un écran inconnu du registre est fermé');

        // le même laissez-passer ne ressert jamais
        $this->client->request('POST', 'https://' . MV_TEST_TENANT2_HOST . '/admin/entree?tenant=' . MV_TEST_TENANT2_CODE, ['pass' => $pass], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT2_HOST]);
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
        // la connexion réussie a renouvelé la session : le lien à la session tombe avant la marque d'usage unique
        $this->assertMatchesRegularExpression('/pass_(replayed|session)/', (string) $this->client->getResponse()->getContent());

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
            'pass_site' => $service->issue(['entry' => 1, 'mode' => 'agency', 'home' => MV_TEST_TENANT_CODE, 'target' => 'autre-site', 'otpAt' => time()], 'une-session'),
            'pass_expired' => $service->issue(['entry' => 1, 'mode' => 'agency', 'home' => MV_TEST_TENANT_CODE, 'target' => MV_TEST_TENANT2_CODE, 'otpAt' => time(), 'exp' => time() - 1], 'une-session'),
            'pass_session' => $service->issue(['entry' => 1, 'mode' => 'agency', 'home' => MV_TEST_TENANT_CODE, 'target' => MV_TEST_TENANT2_CODE, 'otpAt' => time()], 'la-session-d-un-autre'),
            'pass_malformed' => 'pas-un-laissez-passer',
        ] as $reason => $forged) {
            $this->client->request('POST', 'https://' . MV_TEST_TENANT2_HOST . '/admin/entree?tenant=' . MV_TEST_TENANT2_CODE, ['pass' => $forged], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT2_HOST]);
            $this->assertSame(403, $this->client->getResponse()->getStatusCode(), $reason);
            $this->assertStringContainsString($reason, (string) $this->client->getResponse()->getContent());
        }
        // usage unique, à session égale : la seconde présentation est refusée
        $once = $service->issue(['entry' => 1, 'mode' => 'agency', 'home' => MV_TEST_TENANT_CODE, 'target' => MV_TEST_TENANT2_CODE, 'otpAt' => time()], 'meme-session');
        $this->assertSame(1, $service->consume($once, MV_TEST_TENANT2_CODE, 'meme-session')['entry']);
        try {
            $service->consume($once, MV_TEST_TENANT2_CODE, 'meme-session');
            $this->fail('un laissez-passer ne sert qu\'une fois');
        } catch (\App\Services\Entry\EntryException $e) {
            $this->assertSame('pass_replayed', $e->reason);
        }
        // un code vieux de plus de 12 heures : le laissez-passer est refusé au passage
        $old = $service->issue(['entry' => 1, 'mode' => 'agency', 'home' => MV_TEST_TENANT_CODE, 'target' => MV_TEST_TENANT2_CODE, 'otpAt' => time() - 43201], 'une-session');
        $this->client->request('POST', 'https://' . MV_TEST_TENANT2_HOST . '/admin/entree?tenant=' . MV_TEST_TENANT2_CODE, ['pass' => $old], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT2_HOST]);
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());

        // le vrai passage, puis « Revenir » (lot RETOUR) : entrée fermée, la session revient au compte d'attache sur son site
        $this->client->request('POST', 'https://' . MV_TEST_TENANT2_HOST . '/admin/entree?tenant=' . MV_TEST_TENANT2_CODE, ['pass' => $pass], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT2_HOST]);
        $this->assertSame(302, $this->client->getResponse()->getStatusCode());
        $welcome = $this->page('GET', '/admin', MV_TEST_TENANT2_HOST);
        $token = $this->csrf($welcome, 'form[action="/admin/entree/quitter"] input[name="_token"]');
        $this->client->request('POST', 'https://' . MV_TEST_TENANT2_HOST . '/admin/entree/quitter', ['_token' => $token], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT2_HOST]);
        $this->assertSame('/admin?tenant=' . MV_TEST_TENANT_CODE, $this->client->getResponse()->headers->get('Location'));
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

    public function testTheOperatorIsJudgedOnWhatEasyAdminRunsAndReadsOnlyDuringAReadAssistance(): void
    {
        $this->agencyManaging();
        $merchant = $this->user(MV_TEST_TENANT2_DB, MV_TEST_TENANT2_CODE, 'commercant', ['ROLE_ADMIN'], 'Claire', false);
        $hidden = $this->hiddenCostProduct();
        $this->login($this->user(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE, 'agence', ['ROLE_ADMIN', 'ROLE_AGENCY'], 'Julie', true), MV_TEST_TENANT_HOST, MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $this->client->request('POST', 'https://' . MV_TEST_TENANT2_HOST . '/admin/entree?tenant=' . MV_TEST_TENANT2_CODE, ['pass' => $this->passFor(MV_TEST_TENANT2_CODE)], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT2_HOST]);
        $this->assertSame(302, $this->client->getResponse()->getStatusCode());
        // le site a l'assistant (sinon la console n'est servie à personne) : l'opérateur ne la reçoit pas pour autant
        // (le droit posé à la main : le service resynchroniserait toutes les colonnes de modules du site, que l'essai a allumées)
        $this->master->prepare("INSERT INTO tenant_entitlements (tenant_code, feature_key, source, status, granted_by, note) VALUES (?, 'ai.full', 'manual', 'active', 'essai', 'AG-3c')")->execute([MV_TEST_TENANT2_CODE]);
        $product = rawurlencode('App\\Controller\\Admin\\ProductCrudController');
        $this->assertStringNotContainsString('id="arkanoa-assistant"', $this->page('GET', '/admin?crudAction=index&crudControllerFqcn=' . $product, MV_TEST_TENANT2_HOST), 'pas de console de l\'assistant pour l\'opérateur');

        // une page nommée se juge sur SON chemin ; jamais avec un écran CRUD ; jamais une route hors d'EasyAdmin
        foreach ([
            'GET /admin?routeName=admin_mon_site' => 'figures',
            'GET /admin?crudAction=index&crudControllerFqcn=' . $product . '&routeName=admin_orders_todo' => 'ambiguous',
            'GET /admin?routeName=api_site_features_get' => 'surface',
            'POST /admin?routeName=admin_ouverture_site_action&routeParams%5Baction%5D=refermer' => 'storefront',
            'POST /admin?crudAction=batchDelete&crudControllerFqcn=' . rawurlencode('App\\Controller\\Admin\\ColorCrudController') => 'batch',
        ] as $request => $reason) {
            [$method, $path] = explode(' ', $request);
            $this->client->request($method, 'https://' . MV_TEST_TENANT2_HOST . $path, $method === 'POST' ? ['entityFqcn' => 'App\\Entity\\Reservation', 'batchActionEntityIds' => [1]] : [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT2_HOST]);
            $this->assertSame(403, $this->client->getResponse()->getStatusCode(), $request);
            $this->assertStringContainsString($reason, (string) $this->client->getResponse()->getContent(), $request);
        }
        // une action par lot, même sans les champs de lot dans le corps
        $this->client->request('POST', 'https://' . MV_TEST_TENANT2_HOST . '/admin?crudAction=batchDelete&crudControllerFqcn=' . rawurlencode('App\\Controller\\Admin\\ColorCrudController'), ['x' => '1'], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT2_HOST]);
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->assertStringContainsString('batch', (string) $this->client->getResponse()->getContent());
        $this->page('GET', '/admin?routeName=admin_aide', MV_TEST_TENANT2_HOST); // une page de construction par son nom : ouverte

        // un champ fermé ne se cherche ni ne se trie
        $this->assertStringNotContainsString($hidden['name'], $this->page('GET', '/admin?crudAction=index&crudControllerFqcn=' . $product . '&query=' . $hidden['cost'], MV_TEST_TENANT2_HOST), 'le coût d\'achat ne se cherche pas');
        $this->assertStringContainsString($hidden['name'], $this->page('GET', '/admin?crudAction=index&crudControllerFqcn=' . $product . '&query=' . rawurlencode($hidden['name']), MV_TEST_TENANT2_HOST));
        $this->page('GET', '/admin?crudAction=index&crudControllerFqcn=' . $product . '&sort%5BpurchasePrice%5D=DESC', MV_TEST_TENANT2_HOST); // tri écarté, page servie

        // assistance « clients » en CONSULTATION : la liste se lit, une action GET qui écrit ou exporte est refusée
        static::getContainer()->get(\App\Services\Agency\Assistance\AssistanceService::class)->request(MV_TEST_TENANT2_CODE, 'clients', 'read', $merchant);
        $this->page('GET', '/admin?crudAction=index&crudControllerFqcn=' . rawurlencode('App\\Controller\\Admin\\ReservationCrudController'), MV_TEST_TENANT2_HOST);
        $this->client->request('GET', 'https://' . MV_TEST_TENANT2_HOST . '/admin?crudAction=exportCsv&crudControllerFqcn=' . rawurlencode('App\\Controller\\Admin\\FormSubmissionCrudController'), [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT2_HOST]);
        $this->assertSame(403, $this->client->getResponse()->getStatusCode(), 'une action d\'EasyAdmin autre qu\'une liste ou une fiche est une écriture');
    }

    public function testThePlatformAccountEntersFromTheSelectorSeesItsEntryAndQuitsIt(): void
    {
        $platform = $this->user(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE, 'plateforme', ['ROLE_ADMIN', 'ROLE_SUPER_ADMIN'], 'Plateforme', true);
        $this->user(MV_TEST_TENANT2_DB, MV_TEST_TENANT2_CODE, 'plateforme', ['ROLE_ADMIN', 'ROLE_SUPER_ADMIN'], 'Plateforme', true);
        $this->login($platform, MV_TEST_TENANT_HOST, MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $this->assertStringContainsString('Tous les sites', $this->page('GET', '/admin', MV_TEST_TENANT_HOST));
        $pass = $this->passFor(MV_TEST_TENANT2_CODE);
        $this->client->request('POST', 'https://' . MV_TEST_TENANT2_HOST . '/admin/entree?tenant=' . MV_TEST_TENANT2_CODE, ['pass' => $pass], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT2_HOST]);
        $this->assertSame(302, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
        $home = $this->page('GET', '/admin', MV_TEST_TENANT2_HOST);
        $this->assertStringContainsString('data-entry-banner', $home, 'l\'entrée se voit');
        $this->assertSame('super_admin', $this->master->query('SELECT mode FROM site_entries ORDER BY id DESC LIMIT 1')->fetchColumn());
        $token = $this->csrf($home, 'form[action="/admin/entree/quitter"] input[name="_token"]');
        $this->client->request('POST', 'https://' . MV_TEST_TENANT2_HOST . '/admin/entree/quitter', ['_token' => $token], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT2_HOST]);
        $this->assertSame(302, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
        $this->assertSame('left', $this->master->query('SELECT ended_reason FROM site_entries ORDER BY id DESC LIMIT 1')->fetchColumn());
    }

    /** Lot BANDEAU (18/11/2026) : pendant une entrée, chaque page d'EasyAdmin (liste, fiche, page nommée) dit où l'on est et en tant que qui */
    public function testTheBannerNamesTheSiteAndTheOperatorOnAListAndADetailPage(): void
    {
        $platform = $this->user(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE, 'plateforme', ['ROLE_ADMIN', 'ROLE_SUPER_ADMIN'], 'Paul', true);
        $this->user(MV_TEST_TENANT2_DB, MV_TEST_TENANT2_CODE, 'plateforme', ['ROLE_ADMIN', 'ROLE_SUPER_ADMIN'], 'Paul', true);
        $this->login($platform, MV_TEST_TENANT_HOST, MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $this->assertStringNotContainsString('data-entry-banner', $this->page('GET', '/admin', MV_TEST_TENANT_HOST), 'hors entrée : aucun bandeau');
        $pass = $this->passFor(MV_TEST_TENANT2_CODE);
        $this->client->request('POST', 'https://' . MV_TEST_TENANT2_HOST . '/admin/entree?tenant=' . MV_TEST_TENANT2_CODE, ['pass' => $pass], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT2_HOST]);
        $this->assertSame(302, $this->client->getResponse()->getStatusCode());

        $described = static::getContainer()->get(\App\Services\TenantPublicHostResolver::class)->describe(MV_TEST_TENANT2_CODE);
        $made = $this->hiddenCostProduct();
        $stmt = $this->pdo(MV_TEST_TENANT2_DB)->prepare('SELECT id FROM product WHERE name = ?');
        $stmt->execute([$made['name']]);
        $product = (int) $stmt->fetchColumn();
        $this->assertGreaterThan(0, $product, 'un article du second site');
        $crud = rawurlencode('App\\Controller\\Admin\\ProductCrudController');
        foreach (['liste' => '/admin?crudAction=index&crudControllerFqcn=' . $crud, 'fiche' => '/admin?crudAction=detail&entityId=' . $product . '&crudControllerFqcn=' . $crud,
            'page nommée' => '/admin/acces-plateforme'] as $kind => $path) {
            $html = $this->page('GET', $path, MV_TEST_TENANT2_HOST);
            $banner = (new \Symfony\Component\DomCrawler\Crawler($html))->filter('[data-entry-banner]');
            $this->assertSame(1, $banner->count(), "bandeau sur la $kind");
            $this->assertSame([(string) $described['name'], 'Plateforme, Paul'], [trim($banner->filter('[data-entry-site]')->text()), trim($banner->filter('[data-entry-as]')->text())], "$kind : le site et qui");
            $this->assertStringContainsString((string) $described['host'], $banner->text(), "$kind : l'adresse du site");
            $this->assertSame(1, $banner->filter('form[action="/admin/entree/quitter"]')->count(), "$kind : le bouton pour quitter");
            $this->assertStringNotContainsString('@', $banner->text(), "$kind : jamais une adresse de courriel");
        }
    }

    /**
     * Lot RETOUR (18/11/2026, règles du chef) : « Revenir à <site d'attache> » ferme l'entrée et rend la session au compte d'attache sans
     * nouvelle connexion ni nouveau code, sous un nouvel identifiant de session, tracé aux deux journaux ; refusé (retour à la connexion) si
     * le compte a été désactivé ou son mot de passe changé entre-temps ; POST seulement ; un second onglet ne rouvre rien.
     */
    public function testReturningHomeKeepsTheSessionOnlyForTheSameUnchangedAccount(): void
    {
        foreach (['normal', 'desactive', 'mot_de_passe'] as $case) {
            $this->clean();
            $platform = $this->user(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE, 'plateforme', ['ROLE_ADMIN', 'ROLE_SUPER_ADMIN'], 'Paul', true);
            $this->user(MV_TEST_TENANT2_DB, MV_TEST_TENANT2_CODE, 'plateforme', ['ROLE_ADMIN', 'ROLE_SUPER_ADMIN'], 'Paul', true);
            $this->login($platform, MV_TEST_TENANT_HOST, MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
            $this->page('GET', '/admin', MV_TEST_TENANT_HOST);
            $pass = $this->passFor(MV_TEST_TENANT2_CODE);
            $this->client->request('POST', 'https://' . MV_TEST_TENANT2_HOST . '/admin/entree?tenant=' . MV_TEST_TENANT2_CODE, ['pass' => $pass], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT2_HOST]);
            $this->assertSame(302, $this->client->getResponse()->getStatusCode(), $case);
            $inside = $this->page('GET', '/admin', MV_TEST_TENANT2_HOST);
            $token = $this->csrf($inside, 'form[action="/admin/entree/quitter"] input[name="_token"]');
            $this->assertStringContainsString('Revenir à', $inside);
            // GET refusé : seule une requête POST avec son jeton revient
            $this->client->request('GET', 'https://' . MV_TEST_TENANT2_HOST . '/admin/entree/quitter', [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT2_HOST]);
            $this->assertSame(405, $this->client->getResponse()->getStatusCode(), $case . ' : GET');
            $this->assertNull($this->master->query('SELECT ended_at FROM site_entries ORDER BY id DESC LIMIT 1')->fetchColumn(), $case . ' : un GET ne ferme rien');
            if ($case === 'desactive') {
                $this->pdo(MV_TEST_TENANT_DB)->exec("UPDATE \"user\" SET is_verified = FALSE WHERE id = " . (int) $platform->getId());
            } elseif ($case === 'mot_de_passe') {
                $this->pdo(MV_TEST_TENANT_DB)->exec("UPDATE \"user\" SET password = 'change-entre-temps' WHERE id = " . (int) $platform->getId());
            }
            $sessionBefore = $this->client->getRequest()->getSession()->getId();
            $codesBefore = (int) $this->pdo(MV_TEST_TENANT_DB)->query('SELECT COUNT(*) FROM otp_code WHERE user_otp_id = ' . (int) $platform->getId())->fetchColumn();
            $this->client->request('POST', 'https://' . MV_TEST_TENANT2_HOST . '/admin/entree/quitter', ['_token' => $token], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT2_HOST]);
            $this->assertSame(302, $this->client->getResponse()->getStatusCode(), $case);
            $this->assertSame('left', $this->master->query('SELECT ended_reason FROM site_entries ORDER BY id DESC LIMIT 1')->fetchColumn(), $case . ' : l\'entrée est fermée');
            if ($case !== 'normal') {
                $this->assertSame('/?tenant=' . MV_TEST_TENANT_CODE, $this->client->getResponse()->headers->get('Location'), $case . ' : retour à la connexion');
                $this->sameDomain('/admin?tenant=' . MV_TEST_TENANT_CODE, 302);
                continue;
            }
            // retour normal : le compte d'attache, sans nouvelle connexion ni nouveau code, sous un nouvel identifiant de session
            $this->assertSame('/admin?tenant=' . MV_TEST_TENANT_CODE, $this->client->getResponse()->headers->get('Location'));
            $this->assertNotSame($sessionBefore, $this->client->getRequest()->getSession()->getId(), 'nouvel identifiant de session');
            $back = $this->sameDomain('/admin?tenant=' . MV_TEST_TENANT_CODE, 200);
            $this->assertStringContainsString('Tous les sites', $back, 'la session est celle du compte d\'attache');
            $this->assertStringNotContainsString('data-entry-banner', $back);
            $this->assertSame($codesBefore, (int) $this->pdo(MV_TEST_TENANT_DB)->query('SELECT COUNT(*) FROM otp_code WHERE user_otp_id = ' . (int) $platform->getId())->fetchColumn(), 'aucun nouveau code envoyé');
            foreach ([MV_TEST_TENANT_DB, MV_TEST_TENANT2_DB] as $db) {
                $this->assertSame(1, (int) $this->pdo($db)->query("SELECT COUNT(*) FROM content_audit_log WHERE resource = 'external_access' AND action = 'return'")->fetchColumn(), "$db : le retour au journal");
            }
            // un second onglet (même session) renvoie l'ancien formulaire : rien ne se rouvre, la session reste au site d'attache
            $this->client->request('POST', 'https://' . MV_TEST_TENANT2_HOST . '/admin/entree/quitter', ['_token' => $token], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT2_HOST]);
            $this->assertSame([302, '/admin'], [$this->client->getResponse()->getStatusCode(), $this->client->getResponse()->headers->get('Location')]);
            $this->assertStringContainsString('Tous les sites', $this->sameDomain('/admin?tenant=' . MV_TEST_TENANT_CODE, 200));
            $this->assertSame(1, (int) $this->master->query('SELECT COUNT(*) FROM site_entries')->fetchColumn());
        }
    }

    /**
     * Lot RETOUR : une requête d'EasyAdmin comme en production, sur le MÊME domaine que la session (un seul domaine pour tous les sites, le site
     * choisi par `?tenant=`), sans l'en-tête de site des essais d'API ; en essai, chaque domaine a son propre cookie de session.
     */
    private function sameDomain(string $path, int $status): string
    {
        $this->client->request('GET', 'https://' . MV_TEST_TENANT2_HOST . $path);
        $this->assertSame($status, $this->client->getResponse()->getStatusCode(), $path . ' : ' . mb_substr((string) $this->client->getResponse()->getContent(), 0, 300));

        return (string) $this->client->getResponse()->getContent();
    }

    /** @return array{name: string, cost: string} un article du second site au coût d'achat reconnaissable */
    private function hiddenCostProduct(): array
    {
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT2_DB, MV_TEST_TENANT2_CODE);
        $em = $provider->getEntityManager();
        $name = 'Cout-cache-' . $this->tag;
        $em->persist((new \App\Entity\Product())->setName($name)->setSlug('entree-ea-' . $this->tag)->setPrice(1990)->setPurchasePrice(987654)->setIsWeb(true)->setSaleEnabled(true));
        $em->flush();
        $em->clear();
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);

        return ['name' => $name, 'cost' => '987654'];
    }

    public function testTheSiteSheetClosesTheSameFieldsOnBothPathsAndAMerchantStillDeletesByBatch(): void
    {
        // UNE liste pour l'API et EasyAdmin : chaque propriété de la fiche du site est fermée ou ouverte de la même façon par les deux chemins
        $meta = static::getContainer()->get(TenantEntityManagerProvider::class)->getEntityManager()->getClassMetadata(\App\Entity\Entreprise::class);
        $closed = 0;
        foreach ([...$meta->getFieldNames(), ...$meta->getAssociationNames()] as $property) {
            $api = \App\Security\Entry\SiteSheetClosedFields::closed($property);
            $this->assertSame($api, \App\Security\Entry\AdminFieldGuard::hidden('admin.entreprise', $property), $property);
            $closed += $api ? 1 : 0;
        }
        $this->assertGreaterThan(20, $closed, 'l\'onglet Caisse entier est fermé');
        foreach (['name', 'metaTitle', 'pricesIncludeTax', 'LegalNotice'] as $open) {
            $this->assertFalse(\App\Security\Entry\SiteSheetClosedFields::closed($open), $open);
        }

        // le formulaire de la fiche de l'opérateur suit la liste
        $this->agencyManaging();
        $this->login($this->user(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE, 'agence', ['ROLE_ADMIN', 'ROLE_AGENCY'], 'Julie', true), MV_TEST_TENANT_HOST, MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $this->client->request('POST', 'https://' . MV_TEST_TENANT2_HOST . '/admin/entree?tenant=' . MV_TEST_TENANT2_CODE, ['pass' => $this->passFor(MV_TEST_TENANT2_CODE)], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT2_HOST]);
        $sheet = (int) $this->sheets[MV_TEST_TENANT2_DB]['id'];
        $form = $this->page('GET', '/admin?crudAction=edit&entityId=' . $sheet . '&crudControllerFqcn=' . rawurlencode('App\\Controller\\Admin\\EntrepriseCrudController'), MV_TEST_TENANT2_HOST);
        $this->assertStringContainsString('Entreprise[metaTitle]', $form);
        foreach (['posPinMaxAttempts', 'posApprovalRefund', 'dataRetentionYears', 'notificationLevel', 'currency', 'email'] as $field) {
            $this->assertStringNotContainsString('Entreprise[' . $field . ']', $form, $field);
        }

        // un commerçant supprime par lot ses couleurs ; une autre entité glissée dans le corps est refusée
        $this->login($this->user(MV_TEST_TENANT2_DB, MV_TEST_TENANT2_CODE, 'commercant', ['ROLE_ADMIN'], 'Claire', false), MV_TEST_TENANT2_HOST, MV_TEST_TENANT2_DB, MV_TEST_TENANT2_CODE);
        $colors = [];
        foreach (['Lot A', 'Lot B'] as $label) {
            $colors[] = (int) $this->pdo(MV_TEST_TENANT2_DB)->query("INSERT INTO color (id, name) VALUES (nextval('color_id_seq'), 'entree-ea-$label-{$this->tag}') RETURNING id")->fetchColumn();
        }
        $color = rawurlencode('App\\Controller\\Admin\\ColorCrudController');
        $list = $this->page('GET', '/admin?crudAction=index&crudControllerFqcn=' . $color, MV_TEST_TENANT2_HOST);
        $token = (string) (new \Symfony\Component\DomCrawler\Crawler($list))->filter('[data-action-name="batchDelete"]')->attr('data-action-csrf-token');
        $this->assertNotSame('', $token);
        $batch = fn (string $entity, array $ids): Response => $this->batch($color, $entity, $ids, $token);
        $this->assertSame(403, $batch('App\\Entity\\Size', $colors)->getStatusCode(), 'une autre entité que celle de l\'écran');
        $this->assertSame(2, (int) $this->pdo(MV_TEST_TENANT2_DB)->query('SELECT COUNT(*) FROM color WHERE id IN (' . implode(',', $colors) . ')')->fetchColumn());
        $this->assertSame(302, $batch('App\\Entity\\Color', $colors)->getStatusCode());
        $this->assertSame(0, (int) $this->pdo(MV_TEST_TENANT2_DB)->query('SELECT COUNT(*) FROM color WHERE id IN (' . implode(',', $colors) . ')')->fetchColumn(), 'la suppression légitime marche toujours');
    }

    /** @param list<int> $ids */
    private function batch(string $controller, string $entity, array $ids, string $token): Response
    {
        $this->client->request('POST', 'https://' . MV_TEST_TENANT2_HOST . '/admin?crudAction=batchDelete&crudControllerFqcn=' . $controller,
            ['batchActionName' => 'batchDelete', 'entityFqcn' => $entity, 'batchActionEntityIds' => $ids, 'batchActionCsrfToken' => $token, 'batchActionUrl' => '/admin'], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT2_HOST]);

        return $this->client->getResponse();
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
        $this->master->prepare("DELETE FROM tenant_entitlements WHERE tenant_code = ? AND feature_key = 'ai.full' AND granted_by = 'essai'")->execute([MV_TEST_TENANT2_CODE]);
        $this->master->exec('DELETE FROM site_entries');
        foreach (['agency_events', 'agency_sites'] as $table) {
            $this->master->exec("DELETE FROM $table");
        }
        $this->master->exec('DELETE FROM agency_plans WHERE agency_id IS NOT NULL');
        $this->master->exec('DELETE FROM agencies');
        foreach ([MV_TEST_TENANT_DB, MV_TEST_TENANT2_DB] as $db) {
            $this->pdo($db)->exec("DELETE FROM otp_code WHERE user_otp_id IN (SELECT id FROM \"user\" WHERE email LIKE 'entree-ea-%@example.invalid')");
            $this->pdo($db)->exec("DELETE FROM product WHERE slug LIKE 'entree-ea-%'");
            $this->pdo($db)->exec("DELETE FROM color WHERE name LIKE 'entree-ea-%'");
            $this->pdo($db)->exec("DELETE FROM \"user\" WHERE email LIKE 'entree-ea-%@example.invalid'");
            $this->pdo($db)->exec("DELETE FROM content_audit_log WHERE resource = 'external_access'");
        }
    }

    private function pdo(string $db): \PDO
    {
        return static::getContainer()->get(TenantDatabaseConnector::class)->pdo($db);
    }
}
