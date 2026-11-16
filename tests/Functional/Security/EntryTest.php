<?php

namespace App\Tests\Functional\Security;

use App\Entity\User;
use App\Security\Entry\EntryModeGuardSubscriber;
use App\Services\Agency\AgencyService;
use App\Services\FeatureAccess\FeatureCatalog;
use App\Services\Platform\TenantDatabaseConnector;
use App\Services\Screens\ScreenRegistry;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lot AG-3b (16/11/2026) : L'ENTRÉE DANS LE SITE D'UN AUTRE. Un membre d'agence, connecté sur son site d'attache, entre dans le site
 * d'un client par un jeton d'entrée de 15 minutes, signé pour ce site seulement ; il y est un OPÉRATEUR EXTÉRIEUR, borné aux écrans
 * de construction par une garde fermée par défaut. Le compte de la plateforme entre de même dans tout site, avec tous ses écrans.
 *
 * Le premier site de test est l'attache de l'agence ; elle gère le second.
 */
class EntryTest extends WebTestCase
{
    private KernelBrowser $client;
    private \PDO $master;
    private string $tag;
    /** @var array<string, array<string, mixed>> fiche d'origine de chaque site de test, remise à la fin */
    private array $sheets = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->master = static::getContainer()->get(TenantConnectionManager::class)->getPdoMaster();
        $this->tag = bin2hex(random_bytes(3));
        $this->clean();
        foreach ([MV_TEST_TENANT_DB, MV_TEST_TENANT2_DB] as $db) {
            $this->sheets[$db] = $this->pdo($db)->query('SELECT id, meta_title, ' . implode(', ', FeatureCatalog::GOVERNED) . ' FROM entreprise ORDER BY id LIMIT 1')->fetch(\PDO::FETCH_ASSOC) ?: [];
            $this->assertNotSame([], $this->sheets[$db], "le site de test $db a une fiche");
            $this->modules($db, array_fill_keys(array_keys(FeatureCatalog::GOVERNED), true));
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->sheets as $db => $sheet) {
            if ($sheet !== []) {
                $stmt = $this->pdo($db)->prepare('UPDATE entreprise SET meta_title = :meta_title, ' . implode(', ', array_map(static fn (string $c): string => "$c = :$c", FeatureCatalog::GOVERNED)) . ' WHERE id = :id');
                foreach ($sheet as $column => $value) {
                    if ($column === 'id') {
                        $stmt->bindValue(':id', (int) $value, \PDO::PARAM_INT);
                    } elseif ($column === 'meta_title') {
                        $stmt->bindValue(':meta_title', $value);
                    } else {
                        $stmt->bindValue(":$column", in_array($value, [true, 't', 1, '1'], true), \PDO::PARAM_BOOL);
                    }
                }
                $stmt->execute();
            }
        }
        $this->clean();
        parent::tearDown();
    }

    public function testAnAgencyMemberEntersTheSiteOfItsClientAsAnExternalOperatorBoundToConstruction(): void
    {
        $member = $this->account(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE, 'membre', ['ROLE_AGENCY_MEMBER'], 'Julie');
        // SANS AGENCE, ni le membre ni un administrateur ni un client n'entrent
        $this->assertSame([403, 'ENTRY_FORBIDDEN'], $this->enterRefusal($member, MV_TEST_TENANT2_CODE));
        $agencies = static::getContainer()->get(AgencyService::class);
        $agencyId = (int) $agencies->create(['code' => 'agence-entree-' . $this->tag, 'name' => 'Atelier Web', 'homeTenantCode' => MV_TEST_TENANT_CODE, 'test' => true], 'essai')['id'];
        $this->assertSame([403, 'ENTRY_FORBIDDEN'], $this->enterRefusal($member, MV_TEST_TENANT2_CODE), 'le site n\'est pas encore géré par l\'agence');
        $agencies->attach($agencyId, MV_TEST_TENANT2_CODE, 'essai');
        $this->assertSame([403, 'ENTRY_FORBIDDEN'], $this->enterRefusal($this->account(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE, 'admin', ['ROLE_ADMIN'], 'Admin'), MV_TEST_TENANT2_CODE), 'un administrateur sans rôle d\'agence');
        $this->assertSame([403, 'ENTRY_FORBIDDEN'], $this->enterRefusal($this->account(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE, 'client', ['ROLE_USER_INTERNET'], 'Client'), MV_TEST_TENANT2_CODE));
        $this->assertSame([422, 'ENTRY_SAME_SITE'], $this->enterRefusal($member, MV_TEST_TENANT_CODE));
        $this->assertSame([403, 'ENTRY_FORBIDDEN'], $this->enterRefusal($member, 'site-inconnu-' . $this->tag), 'un site inconnu se refuse comme un site non géré');
        $this->assertSame(401, $this->request('POST', '/api/me/contexts/enter', null, MV_TEST_TENANT_HOST, ['site' => MV_TEST_TENANT2_CODE])->getStatusCode(), 'pas de session sur le site d\'attache : pas d\'entrée');

        // L'ENTRÉE
        $entered = $this->enter($member, MV_TEST_TENANT2_CODE);
        $this->assertSame(['agency', MV_TEST_TENANT2_CODE, 'Agence Atelier Web, Julie'], [$entered['mode'], $entered['site'], $entered['operator']]);
        $expires = new \DateTimeImmutable($entered['expiresAt']);
        $this->assertEqualsWithDelta(time() + 900, $expires->getTimestamp(), 30, 'un quart d\'heure');
        $token = $entered['token'];
        $row = $this->entryRow($entered['entry']);
        $this->assertSame(['agency', MV_TEST_TENANT_CODE, MV_TEST_TENANT2_CODE, $agencyId, 'Atelier Web', 'Julie', null], [$row['mode'], $row['home_tenant_code'], $row['target_tenant_code'], (int) $row['agency_id'], $row['agency_name'], $row['first_name'], $row['ended_at']]);
        // aucun compte n'est créé chez le client ; l'entrée est notée à son journal, au nom de l'agence et du prénom, sans adresse
        $this->assertSame(0, (int) $this->pdo(MV_TEST_TENANT2_DB)->query("SELECT COUNT(*) FROM \"user\" WHERE email LIKE 'entree-%'")->fetchColumn());
        $trace = $this->pdo(MV_TEST_TENANT2_DB)->query("SELECT user_identifier, resource_id FROM content_audit_log WHERE resource = 'external_access' ORDER BY id DESC LIMIT 1")->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame(['Agence Atelier Web, Julie', (string) $entered['entry']], [$trace['user_identifier'], $trace['resource_id']]);

        // LA CONSTRUCTION est ouverte ; le reste est fermé, la route se classant par le registre
        $this->assertSame(200, $this->request('GET', '/api/media', $token)->getStatusCode());
        // (une adresse qu'aucune route ne sert répond 404 avant toute garde : le routeur passe d'abord)
        foreach ([['GET', '/api/gestion/journee'], ['GET', '/api/orders'], ['GET', '/api/gestion/statistiques'], ['GET', '/api/support/tickets'], ['GET', '/api/gestion/pertes/constats'],
            ['GET', '/api/landingpage-audit'], ['GET', '/api/site/assistant/credits'], ['GET', '/api/contacts'], ['GET', '/api/profile'], ['GET', '/api/me/contexts']] as [$method, $path]) {
            $response = $this->request($method, $path, $token);
            $this->assertSame([403, 'MODE_FORBIDDEN'], [$response->getStatusCode(), json_decode((string) $response->getContent(), true)['code'] ?? null], "$method $path");
        }
        // CE QUI RESTE FERMÉ MÊME DANS LA CONSTRUCTION (un essai sur chacun) : comptes et rôles, clés, paiement, exports, pertes, modules
        // (les routes de paiement d'un visiteur, hors du pare-feu de l'API, ne voient pas le jeton : servies comme à un visiteur)
        foreach ([['POST', '/api/memoires/admin/users', 'family'], ['PUT', '/api/memoires/admin/users/1/team', 'family'], ['POST', '/api/support/agents', 'family'],
            ['POST', '/api/app-keys', 'keys'], ['GET', '/api/app-keys', 'keys'], ['GET', '/api/payments', 'family'],
            ['GET', '/api/gestion/exports/achats.csv', 'family'], ['PUT', '/api/gestion/pertes/reglages', 'family'], ['POST', '/api/site/features/module.pos/request', 'modules'],
            ['PUT', '/api/site/features', 'modules']] as [$method, $path, $reason]) {
            $response = $this->request($method, $path, $token, MV_TEST_TENANT2_HOST, []);
            $body = json_decode((string) $response->getContent(), true);
            $this->assertSame([403, 'MODE_FORBIDDEN', $reason], [$response->getStatusCode(), $body['code'] ?? null, $body['reason'] ?? null], "$method $path");
        }
        // le coût d'un article ne s'écrit pas ; un import de clients ne se dépose pas
        $this->assertSame([403, 'cost'], $this->reason('POST', '/api/products', $token, ['name' => 'Article', 'purchasePrice' => 12]));
        $this->assertSame([403, 'cost'], $this->reason('PATCH', '/api/products/1', $token, ['variants' => [['coefficientMultiplier' => 2]]]));
        foreach (['commandeCostCents', 'commandeQuantity', 'commandeAttachedAt', 'commandeCostEstimated', 'commande'] as $field) {
            $this->assertSame([403, 'cost'], $this->reason('PUT', '/api/products/1', $token, ['name' => 'Article', $field => 1]), $field);
        }
        $this->client->getCookieJar()->clear();
        $this->client->request('POST', 'https://' . MV_TEST_TENANT2_HOST . '/api/site/imports', ['kind' => 'customers', 'source' => 'shopify'], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT2_HOST, 'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        $this->assertSame([403, 'kind'], [$this->client->getResponse()->getStatusCode(), json_decode((string) $this->client->getResponse()->getContent(), true)['reason'] ?? null]);

        // UN MODULE ÉTEINT chez le client est fermé en entrée
        $this->modules(MV_TEST_TENANT2_DB, ['module.landing' => false]);
        $this->assertSame([403, 'module'], $this->reason('GET', '/api/site-content/demo', $token));
        $this->modules(MV_TEST_TENANT2_DB, ['module.landing' => true]);

        // LES CHAMPS SENSIBLES sont retirés : la liste de contrôle du site perd les chiffres de la gestion et leur description
        $readiness = json_decode((string) $this->request('GET', '/api/site/readiness', $token)->getContent(), true);
        $this->assertNotSame([], $readiness['steps'] ?? []);
        foreach ($readiness['steps'] as $step) {
            $this->assertArrayNotHasKey('description', $step);
            $this->assertArrayNotHasKey('figures', $step);
        }
        $admin2 = $this->account(MV_TEST_TENANT2_DB, MV_TEST_TENANT2_CODE, 'admin-client', ['ROLE_ADMIN'], 'Client');
        $own = json_decode((string) $this->request('GET', '/api/site/readiness', $admin2)->getContent(), true);
        $this->assertArrayHasKey('description', $own['steps'][0], 'l\'administrateur du site, lui, lit tout');

        // UNE ÉCRITURE porte son auteur au journal du site : « Agence X, prénom »
        $sheetId = (int) $this->sheets[MV_TEST_TENANT2_DB]['id'];
        $response = $this->request('PUT', "/api/entreprise/$sheetId", $token, MV_TEST_TENANT2_HOST, ['metaTitle' => 'Titre posé par l\'agence ' . $this->tag]);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $this->assertSame('Agence Atelier Web, Julie', $this->pdo(MV_TEST_TENANT2_DB)->query("SELECT user_identifier FROM content_audit_log WHERE resource = 'entreprise' ORDER BY id DESC LIMIT 1")->fetchColumn());
        $this->assertSame(1, (int) $this->pdo(MV_TEST_TENANT2_DB)->query("SELECT COUNT(*) FROM content_audit_log WHERE resource = 'external_access' AND action = 'write' AND user_identifier = 'Agence Atelier Web, Julie' AND after_state LIKE '%/api/entreprise/%'")->fetchColumn(),
            'chaque écriture réussie de l\'opérateur est notée à son nom, même là où la route n\'écrit pas au journal');
        // … l'adresse qui reçoit les commandes et les demandes des clients, la devise, les taxes, le fuseau, les périodes : fermés
        foreach (['email' => 'agence@example.invalid', 'currency' => 'EUR', 'pricesIncludeTax' => true, 'posTimezone' => 'Europe/Paris', 'purchasePeriodFrequency' => 'monthly'] as $field => $value) {
            $this->assertSame([403, 'field'], $this->reason('PUT', "/api/entreprise/$sheetId", $token, [$field => $value]), $field);
        }
        // … ouvrir ou fermer la boutique : seulement l'agence qui a CRÉÉ le site (celui-ci lui est rattaché)
        $this->assertSame([403, 'storefront'], $this->reason('POST', '/api/site/storefront/close', $token, []));
        $this->assertSame([403, 'storefront'], $this->reason('POST', '/api/site/storefront/open', $token, []));
        // … et les drapeaux de modules de la fiche lui restent fermés (réservés au super administrateur)
        $this->assertSame(403, $this->request('PUT', "/api/entreprise/$sheetId", $token, MV_TEST_TENANT2_HOST, ['isBoutiqueActive' => false])->getStatusCode());

        // UNE LECTURE PUBLIQUE est servie comme à un visiteur
        $this->assertSame(200, $this->request('GET', "/api/entreprise/$sheetId", $token)->getStatusCode());
        // … sans son identité : la lecture d'administrateur d'API Platform (rangée publique en lecture) lui reste fermée
        $this->assertNotSame(200, $this->request('GET', '/api/categories', $token)->getStatusCode(), 'servie comme à un visiteur, jamais sous les rôles de l\'opérateur');

        // LES IMPORTS DE CLIENTS du site ne se voient pas et ne se touchent pas, même déposés par le commerçant
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT2_DB, MV_TEST_TENANT2_CODE);
        $customersJob = static::getContainer()->get(\App\Import\ImportJobStore::class)->create('shopify', 'customers', 'clients.csv', 'entree-' . $this->tag . '.csv', str_repeat('a', 64), 3, 'entree-essai');
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $kinds = static fn (array $list): array => array_values(array_unique(array_column($list['imports'] ?? [], 'kind')));
        $this->assertContains('customers', $kinds(json_decode((string) $this->request('GET', '/api/site/imports', $admin2)->getContent(), true)), 'le commerçant le voit');
        $this->assertNotContains('customers', $kinds(json_decode((string) $this->request('GET', '/api/site/imports', $token)->getContent(), true)), 'l\'agence ne le voit pas');
        $this->assertSame([403, 'kind'], $this->reason('GET', "/api/site/imports/$customersJob", $token));
        $this->assertSame([403, 'kind'], $this->reason('POST', "/api/site/imports/$customersJob/confirm", $token, []));

        // LE JETON vaut sur CE site seulement : ni sur le site d'attache, ni sous l'en-tête d'un autre site
        $this->assertSame(401, $this->request('GET', '/api/media', $token, MV_TEST_TENANT_HOST)->getStatusCode());
        // un jeton d'entrée n'en obtient JAMAIS un autre, ni vers un troisième site (ni rien d'écrit au journal des entrées)
        $count = $this->entryCount();
        $this->assertSame(403, $this->request('POST', '/api/me/contexts/enter', $token, MV_TEST_TENANT2_HOST, ['site' => MV_TEST_TENANT_CODE])->getStatusCode());
        $this->assertSame(403, $this->request('POST', '/api/me/contexts/enter', $token, MV_TEST_TENANT2_HOST, ['site' => 'un-troisieme-site'])->getStatusCode());
        $this->assertSame($count, $this->entryCount());

        // LE RENOUVELLEMENT : depuis le site d'attache, une nouvelle entrée
        $renewed = $this->enter($member, MV_TEST_TENANT2_CODE);
        $this->assertNotSame($entered['entry'], $renewed['entry']);

        // FIN D'UNE ENTRÉE : site détaché → refus à la requête suivante (le cache d'une minute passé), entrée fermée au journal
        $agencies->detach($agencyId, MV_TEST_TENANT2_CODE, 'essai');
        $this->forget($renewed['entry']);
        $response = $this->request('GET', '/api/media', $renewed['token']);
        $this->assertSame([401, 'ENTRY_ENDED'], [$response->getStatusCode(), json_decode((string) $response->getContent(), true)['error'] ?? null]);
        $this->assertSame('detached', $this->entryRow($renewed['entry'])['ended_reason']);
        $this->assertSame([403, 'ENTRY_FORBIDDEN'], $this->enterRefusal($member, MV_TEST_TENANT2_CODE));
        $agencies->attach($agencyId, MV_TEST_TENANT2_CODE, 'essai');

        // … agence suspendue
        $again = $this->enter($member, MV_TEST_TENANT2_CODE);
        $this->master->prepare("UPDATE agencies SET status = 'suspended' WHERE id = ?")->execute([$agencyId]);
        $this->forget($again['entry']);
        $this->assertSame(401, $this->request('GET', '/api/media', $again['token'])->getStatusCode());
        $this->assertSame('agency', $this->entryRow($again['entry'])['ended_reason']);
        $this->assertSame([403, 'ENTRY_FORBIDDEN'], $this->enterRefusal($member, MV_TEST_TENANT2_CODE));
        $this->master->prepare("UPDATE agencies SET status = 'active' WHERE id = ?")->execute([$agencyId]);

        // … rôle d'agence retiré sur le site d'attache
        $again = $this->enter($member, MV_TEST_TENANT2_CODE);
        $this->pdo(MV_TEST_TENANT_DB)->prepare('UPDATE "user" SET roles = ? WHERE email = ?')->execute(['["ROLE_USER_INTERNET"]', "entree-membre-{$this->tag}@example.invalid"]);
        $this->forget($again['entry']);
        $this->assertSame(401, $this->request('GET', '/api/media', $again['token'])->getStatusCode());
        $this->assertSame('role', $this->entryRow($again['entry'])['ended_reason']);
        $this->pdo(MV_TEST_TENANT_DB)->prepare('UPDATE "user" SET roles = ? WHERE email = ?')->execute(['["ROLE_AGENCY_MEMBER"]', "entree-membre-{$this->tag}@example.invalid"]);

        // … échéance passée
        $again = $this->enter($member, MV_TEST_TENANT2_CODE);
        $this->master->prepare("UPDATE site_entries SET expires_at = NOW() - INTERVAL '1 minute' WHERE id = ?")->execute([$again['entry']]);
        $this->forget($again['entry']);
        $this->assertSame(401, $this->request('GET', '/api/media', $again['token'])->getStatusCode());

        // … la ligne de l'entrée relue à chaque requête : un autre site visé, même avec le lien gardé en cache, ferme la porte
        $again = $this->enter($member, MV_TEST_TENANT2_CODE);
        $this->assertSame(200, $this->request('GET', '/api/media', $again['token'])->getStatusCode());
        $this->master->prepare('UPDATE site_entries SET target_tenant_code = ? WHERE id = ?')->execute(['ailleurs-' . $this->tag, $again['entry']]);
        $this->assertSame(401, $this->request('GET', '/api/media', $again['token'])->getStatusCode());

        // … DÉCONNEXION du site d'attache : toutes ses entrées ferment aussitôt (sans attendre la minute)
        $again = $this->enter($member, MV_TEST_TENANT2_CODE);
        $this->assertSame(200, $this->request('GET', '/api/media', $again['token'])->getStatusCode());
        $this->request('POST', '/api/logout', $member, MV_TEST_TENANT_HOST, []);
        $this->assertSame('logout', $this->entryRow($again['entry'])['ended_reason']);
        $this->assertSame(401, $this->request('GET', '/api/media', $again['token'])->getStatusCode());
        // … par le seul jeton de renouvellement (le jeton d'accès du web a échu) : les entrées ferment aussi, et sans session vivante
        // sur le site d'attache, plus d'entrée, même avec un jeton d'accès encore lisible
        $again = $this->enter($member, MV_TEST_TENANT2_CODE);
        $refresh = (string) $this->pdo(MV_TEST_TENANT_DB)->query("SELECT refresh_token FROM refresh_tokens WHERE username = 'entree-membre-{$this->tag}@example.invalid' ORDER BY id DESC LIMIT 1")->fetchColumn();
        $this->client->getCookieJar()->clear();
        $this->client->getCookieJar()->set(new \Symfony\Component\BrowserKit\Cookie('refresh_token_' . MV_TEST_TENANT_CODE, $refresh, null, '/', MV_TEST_TENANT_HOST));
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . '/api/logout', [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'], '{}');
        $this->assertSame('logout', $this->entryRow($again['entry'])['ended_reason']);
        $this->assertSame([403, 'ENTRY_FORBIDDEN'], $this->enterRefusal($member, MV_TEST_TENANT2_CODE), 'déconnecté du site d\'attache : plus d\'entrée');

        // la page « Accès extérieurs » du client liste les entrées de l'agence
        $this->assertNotSame([], $this->entriesOn(MV_TEST_TENANT2_CODE, MV_TEST_TENANT2_DB));
    }

    public function testThePlatformAccountEntersAnySiteWithAllItsScreensAndIsTraced(): void
    {
        $email = "entree-plateforme-{$this->tag}@example.invalid";
        $home = $this->account(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE, 'plateforme', ['ROLE_ADMIN', 'ROLE_SUPER_ADMIN'], 'Plateforme');
        // sans son compte sur le site visé : indisponible
        $this->assertSame([409, 'ENTRY_UNAVAILABLE'], $this->enterRefusal($home, MV_TEST_TENANT2_CODE));
        $this->account(MV_TEST_TENANT2_DB, MV_TEST_TENANT2_CODE, 'plateforme', ['ROLE_ADMIN', 'ROLE_SUPER_ADMIN'], 'Plateforme');
        $entered = $this->enter($home, MV_TEST_TENANT2_CODE);
        $this->assertSame(['super_admin', $email], [$entered['mode'], $entered['operator']]);
        // tous ses écrans de site, sans la garde du mode agence
        $response = $this->request('GET', '/api/gestion/journee', $entered['token']);
        $this->assertNotSame(403, $response->getStatusCode(), (string) $response->getContent());
        $this->assertNotSame(401, $response->getStatusCode());
        $this->assertSame($email, $this->pdo(MV_TEST_TENANT2_DB)->query("SELECT user_identifier FROM content_audit_log WHERE resource = 'external_access' ORDER BY id DESC LIMIT 1")->fetchColumn());
        // … il n'écrit rien sur un compte (profil, adresse, mot de passe : une telle écriture rendrait un jeton ordinaire)
        $this->assertSame([403, 'account'], $this->reason('PATCH', '/api/profile', $entered['token'], ['firstname' => 'Autre']));
        // … et lui non plus n'obtient pas d'entrée depuis une entrée
        // (refusé par la garde avant le contrôleur, qui le refuserait aussi : ENTRY_FROM_ENTRY)
        $count = $this->entryCount();
        $this->assertSame([403, 'MODE_FORBIDDEN'], $this->reason('POST', '/api/me/contexts/enter', $entered['token'], ['site' => MV_TEST_TENANT_CODE], 'code'));
        $this->assertSame($count, $this->entryCount());
        // le rôle retiré sur le site d'attache ferme l'entrée
        $this->pdo(MV_TEST_TENANT_DB)->prepare('UPDATE "user" SET roles = ? WHERE email = ?')->execute(['["ROLE_ADMIN"]', $email]);
        $this->forget($entered['entry']);
        $this->assertSame(401, $this->request('GET', '/api/gestion/journee', $entered['token'])->getStatusCode());
    }

    /**
     * LA GARDE SUR TOUTES LES ROUTES : chaque route du routeur reçoit une décision ; seules les routes d'un écran de construction sont
     * ouvertes à l'opérateur, et leur liste est écrite ici en toutes lettres (une route nouvelle de la construction s'y ajoute en la
     * relisant). Toute autre route est fermée, sauf les lectures du site public, servies comme à un visiteur.
     */
    public function testEveryRouteIsClosedToTheOperatorExceptTheConstructionScreensWrittenOutHere(): void
    {
        $open = [];
        foreach (static::getContainer()->get('router')->getRouteCollection() as $name => $route) {
            $path = preg_replace('/\.1$/', '', (string) preg_replace('/\{[^}]+\}/', '1', $route->getPath()));
            foreach ($route->getMethods() ?: ['GET', 'POST'] as $method) {
                [$decision, $target] = EntryModeGuardSubscriber::decision($method, $path);
                if ($decision === EntryModeGuardSubscriber::OPEN) {
                    $this->assertSame(ScreenRegistry::CONSTRUCTION, ScreenRegistry::familyOf((string) $target), "$method $path");
                    $open[] = "$method $path";
                } elseif ($decision === EntryModeGuardSubscriber::PUBLIC) {
                    $this->assertSame(['@public', true], [$target, in_array($method, ['GET', 'HEAD'], true)], "$method $path");
                } else {
                    $this->assertTrue($target === null || str_starts_with((string) $target, '@') || ScreenRegistry::familyOf((string) $target) !== ScreenRegistry::CONSTRUCTION
                        || preg_match('#app-keys|exports?|stripe|/api/site/features#i', $path) === 1 || !str_starts_with($path, '/api/'), "$method $path : fermée sans raison");
                }
            }
        }
        $open = array_values(array_unique($open));
        sort($open);
        $this->assertSame(json_decode((string) file_get_contents(__DIR__ . '/../../Fixtures/ecrans/entree-agence.json'), true), $open,
            'routes ouvertes à une agence dans le site de son client : tests/Fixtures/ecrans/entree-agence.json, à régénérer et RELIRE');
        // la décision ne dépend que du chemin décodé : un encodage ou une casse différente ne rouvre rien
        $this->assertSame(EntryModeGuardSubscriber::CLOSED, EntryModeGuardSubscriber::decision('GET', '/api/Gestion/journee')[0]);
        $this->assertSame(EntryModeGuardSubscriber::CLOSED, EntryModeGuardSubscriber::decision('POST', '/api/products/1/reviews')[0], 'un avis s\'écrit par un compte client');
        $this->assertSame(EntryModeGuardSubscriber::PUBLIC, EntryModeGuardSubscriber::decision('GET', '/api/products/1/reviews')[0]);
        $this->assertSame(EntryModeGuardSubscriber::CLOSED, EntryModeGuardSubscriber::decision('POST', '/api/cart')[0], 'une écriture publique n\'est pas une lecture');
        // HEAD est une lecture : la règle de GET, jamais celle des écritures
        $this->assertSame([EntryModeGuardSubscriber::PUBLIC, '@public'], array_slice(EntryModeGuardSubscriber::decision('HEAD', '/api/entreprise/1'), 0, 2));
        $this->assertSame([EntryModeGuardSubscriber::OPEN, 'site.reglages'], array_slice(EntryModeGuardSubscriber::decision('PUT', '/api/entreprise/1'), 0, 2));
    }

    /** Le retrait d'un champ par suffixe de son chemin, à toute profondeur, sans toucher au reste ni à la forme de la réponse */
    public function testSensitiveFieldsAreStrippedByThePathSuffix(): void
    {
        $specs = [['purchasePrice'], ['steps', '[]', 'description'], ['uses', 'sales']];
        $data = json_decode('{"purchasePrice":1,"name":"a","items":[{"purchasePrice":2,"price":3}],"steps":[{"description":"x","title":"t"}],"description":"gardée","uses":{"sales":4,"stock":5},"empty":{}}');
        $this->assertSame('{"name":"a","items":[{"price":3}],"steps":[{"title":"t"}],"description":"gardée","uses":{"stock":5},"empty":{}}', json_encode(EntryModeGuardSubscriber::strip($data, $specs), \JSON_UNESCAPED_UNICODE));
    }

    /** Les écritures d'API Platform rendent toute l'entité : pour l'opérateur seulement, la sortie reçoit le groupe de lecture borné */
    public function testTheOutputOfApiPlatformWritesIsBoundedForTheOperatorOnly(): void
    {
        $inner = new class () implements \ApiPlatform\Serializer\SerializerContextBuilderInterface {
            public array $context = [];

            public function createFromRequest(\Symfony\Component\HttpFoundation\Request $request, bool $normalization, ?array $extractedAttributes = null): array
            {
                return $this->context;
            }
        };
        $builder = new \App\Security\Entry\EntrySerializerContextBuilder($inner);
        $operator = new \Symfony\Component\HttpFoundation\Request();
        $operator->attributes->set(EntryModeGuardSubscriber::ATTRIBUTE, 'catalogue');
        $cases = [[\App\Entity\Product::class, ['product:list']], [\App\Entity\VehicleProduct::class, ['product:list']], [\App\Entity\Categories::class, ['category:list']],
            [\App\Entity\ProductVariant::class, [\App\Security\Entry\EntrySerializerContextBuilder::NONE]]];
        foreach ($cases as [$class, $groups]) {
            $inner->context = ['resource_class' => $class];
            $this->assertSame($groups, $builder->createFromRequest($operator, true)['groups'], $class);
            $this->assertArrayNotHasKey('groups', $builder->createFromRequest($operator, false), 'l\'entrée (dénormalisation) ne change pas');
            $this->assertArrayNotHasKey('groups', $builder->createFromRequest(new \Symfony\Component\HttpFoundation\Request(), true), 'un compte du site garde la réponse d\'avant');
        }
        $inner->context = ['resource_class' => \App\Entity\Product::class, 'groups' => ['vehicle_product:read']];
        $this->assertSame(['vehicle_product:read'], $builder->createFromRequest($operator, true)['groups'], 'un groupe déjà posé reste');
    }

    /** @return array<string, mixed> */
    private function enter(string $token, string $site): array
    {
        $response = $this->request('POST', '/api/me/contexts/enter', $token, MV_TEST_TENANT_HOST, ['site' => $site]);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        return json_decode((string) $response->getContent(), true);
    }

    /** @return array{0: int, 1: ?string} */
    private function enterRefusal(string $token, string $site): array
    {
        $response = $this->request('POST', '/api/me/contexts/enter', $token, MV_TEST_TENANT_HOST, ['site' => $site]);

        return [$response->getStatusCode(), json_decode((string) $response->getContent(), true)['code'] ?? null];
    }

    /** @return array{0: int, 1: ?string} statut et raison (ou code) d'un refus sur le site du client */
    private function reason(string $method, string $path, string $token, ?array $body = null, string $key = 'reason'): array
    {
        $response = $this->request($method, $path, $token, MV_TEST_TENANT2_HOST, $body);

        return [$response->getStatusCode(), json_decode((string) $response->getContent(), true)[$key] ?? null];
    }

    private function request(string $method, string $path, ?string $token, string $host = MV_TEST_TENANT2_HOST, ?array $body = null): Response
    {
        $this->client->getCookieJar()->clear();
        $server = ['HTTP_X_TENANT_HOST' => $host, 'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => '10.86.' . random_int(1, 254) . '.' . random_int(1, 254)]
            + ($token !== null ? ['HTTP_AUTHORIZATION' => 'Bearer ' . $token] : []);
        $this->client->request($method, 'https://' . $host . $path, [], [], $server, $body !== null ? (string) json_encode($body) : null);

        return $this->client->getResponse();
    }

    /** Un compte d'un site de test, et son jeton sur ce site */
    private function account(string $db, string $code, string $name, array $roles, string $firstName): string
    {
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant($db, $code);
        $em = $provider->getEntityManager();
        $email = "entree-$name-{$this->tag}@example.invalid";
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname($firstName)->setLastname('Essai')->setRoles($roles)->setPassword('inutilisable')->setIsVerified(true);
        $em->persist($user);
        $em->flush();
        // une session vivante : un jeton de renouvellement valable (l'entrée l'exige)
        $em->getConnection()->executeStatement("INSERT INTO refresh_tokens (id, refresh_token, username, valid) VALUES (nextval('refresh_tokens_id_seq'), ?, ?, NOW() + INTERVAL '1 day')", ['entree-' . bin2hex(random_bytes(30)), $email]);
        $token = static::getContainer()->get('lexik_jwt_authentication.jwt_manager')->create($user);
        $em->clear();
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);

        return $token;
    }

    /** La minute de cache passée : la garde relit le lien */
    private function forget(int $entry): void
    {
        static::getContainer()->get('cache.app')->delete('site_entry_' . $entry);
    }

    /** @return array<string, mixed> */
    private function entryRow(int $id): array
    {
        $stmt = $this->master->prepare('SELECT * FROM site_entries WHERE id = ?');
        $stmt->execute([$id]);

        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
    }

    private function entryCount(): int
    {
        return (int) $this->master->query('SELECT COUNT(*) FROM site_entries')->fetchColumn();
    }

    /** Les entrées lues par la page « Accès extérieurs » du site donné */
    private function entriesOn(string $code, string $db): array
    {
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant($db, $code);
        $lines = static::getContainer()->get(\App\Services\Platform\PlatformAccessJournal::class)->entries();
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);

        return array_values(array_filter($lines, static fn (array $l): bool => $l['account'] === 'Agence Atelier Web, Julie' && $l['channel'] === 'entry_agency'));
    }

    /** @param array<string, bool> $flags clé de module => actif */
    private function modules(string $db, array $flags): void
    {
        foreach ($flags as $key => $active) {
            $this->pdo($db)->exec('UPDATE entreprise SET ' . FeatureCatalog::GOVERNED[$key] . ' = ' . ($active ? 'true' : 'false'));
        }
    }

    private function clean(): void
    {
        $this->master->exec('DELETE FROM site_entries');
        foreach (['agency_events', 'agency_sites'] as $table) {
            $this->master->exec("DELETE FROM $table");
        }
        $this->master->exec('DELETE FROM agency_plans WHERE agency_id IS NOT NULL');
        $this->master->exec('DELETE FROM agencies');
        foreach ([MV_TEST_TENANT_DB, MV_TEST_TENANT2_DB] as $db) {
            $this->pdo($db)->exec("DELETE FROM refresh_tokens WHERE username LIKE 'entree-%@example.invalid'");
            $this->pdo($db)->exec("DELETE FROM \"user\" WHERE email LIKE 'entree-%@example.invalid'");
            $this->pdo($db)->exec("DELETE FROM content_audit_log WHERE resource = 'external_access'");
            $this->pdo($db)->exec("DELETE FROM import_job WHERE created_by = 'entree-essai'");
        }
    }

    private function pdo(string $db): \PDO
    {
        return static::getContainer()->get(TenantDatabaseConnector::class)->pdo($db);
    }
}
