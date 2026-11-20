<?php

namespace App\Tests\Functional\Incident;

use App\Entity\User;
use App\EventListener\ApiExceptionListener;
use App\Incident\Services\IncidentLimits;
use App\Incident\Services\PlatformIncidentService;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use App\Tests\Fake\FakeControllerErrorListener;
use App\Tests\Functional\Support\OtpTestHelper;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Incidents de la plateforme, lot INC-1a (18/11/2026, `docs/incidents-et-signalements.md`) : rapport des applications masqué, regroupé
 * et rangé au site de la requête ; plafond des incidents nouveaux sans jeton ; pannes du serveur (une \Error comprise) écrites une fois
 * par minute et par empreinte ; lecture et suivi par le super administrateur seul ; courriel du jour et purge.
 */
class PlatformIncidentTest extends WebTestCase
{
    private const PASSWORD = 'Un-mot-de-passe-solide-42';
    private KernelBrowser $client;
    private \PDO $master;
    private string $tag;
    /** @var array<string, string> */
    private array $tokens = [];

    /** @var array{0: mixed, 1: mixed} la variable d'envoi d'avant l'essai (tests/bootstrap.php), remise après */
    private array $mailer = [null, null];

    protected function setUp(): void
    {
        $this->mailer = [$_ENV['MAILER_DSN'] ?? null, $_SERVER['MAILER_DSN'] ?? null];
        $_ENV['MAILER_DSN'] = $_SERVER['MAILER_DSN'] = 'null://plateforme-envoi%40example.invalid:x@default'; // l'expéditeur de la plateforme des essais
        $this->client = static::createClient();
        $this->master = static::getContainer()->get(TenantConnectionManager::class)->getPdoMaster();
        $this->tag = bin2hex(random_bytes(3));
        $this->master->exec('DELETE FROM platform_incident');
    }

    protected function tearDown(): void
    {
        $this->master->exec('DELETE FROM platform_incident');
        $this->master->exec("DELETE FROM platform_settings WHERE key = 'platform_alert_email'");
        unset($_ENV['POS_ALERT_PLATFORM_EMAIL'], $_SERVER['POS_ALERT_PLATFORM_EMAIL']);
        [$_ENV['MAILER_DSN'], $_SERVER['MAILER_DSN']] = $this->mailer;
        parent::tearDown();
    }

    public function testAnApplicationReportsAnErrorMaskedGroupedAndForTheSiteOfTheRequest(): void
    {
        $body = ['app' => 'pos', 'appVersion' => '2.3.1', 'level' => 'crash', 'tenantCode' => 'autre-site', 'fingerprint' => 'choisi-par-la-source',
            'screen' => 'Vente / Paiement?client=marie.t@example.com',
            'message' => 'Échec 4521 pour marie.t@example.com au 514-555-1234 avec Bearer eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxIn0.c2lnbmF0dXJl',
            'context' => ['url' => '/vente?email=marie.t@example.com#x', 'name' => 'Marie Tremblay', 'email' => 'marie.t@example.com', 'body' => ['password' => 'secret'],
                'stack' => 'at pay() token=abcdef', 'online' => false, 'httpStatus' => 502]];
        $first = $this->json('POST', '/api/incidents/report', $body, null, 202);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $first['fingerprint']);
        $this->assertNotSame('choisi-par-la-source', $first['fingerprint'], 'l\'empreinte est calculée par le serveur');
        $row = $this->row($first['incidentId']);
        $this->assertSame([MV_TEST_TENANT_CODE, 'pos', '2.3.1', 'crash', true, 1, 'new'], [$row['tenant_code'], $row['app'], $row['app_version'], $row['level'], (bool) $row['anonymous'], (int) $row['count'], $row['status']],
            'le site est celui de la requête, jamais celui du corps');
        $this->assertSame('Vente / Paiement', $row['screen'], 'adresse sans sa requête');
        $this->assertSame('Échec 4521 pour m***@example.com au •••• 1234 avec Bearer [jeton]', $row['message']);
        $this->assertSame(['url' => '/vente', 'httpStatus' => 502, 'online' => false, 'stack' => 'at pay() token=[masqué]'], json_decode((string) $row['context'], true), 'liste blanche : ni nom, ni courriel, ni corps');
        // garde-fou du masquage : rien de ce qui désigne une personne ni un secret n'est écrit, nulle part
        $dump = mb_strtolower((string) json_encode([$row, $this->master->query('SELECT * FROM platform_incident_event')->fetchAll(\PDO::FETCH_ASSOC)], JSON_UNESCAPED_UNICODE));
        foreach (['marie', 'tremblay', '514-555', '5145551234', 'eyj', 'password', 'secret', 'abcdef', 'autre-site'] as $clear) {
            $this->assertStringNotContainsString($clear, $dump, "« $clear » jamais écrit");
        }
        // regroupement : le même message, d'autres nombres → le même incident
        $again = $this->json('POST', '/api/incidents/report', ['message' => str_replace('4521', '9876', $body['message'])] + $body, null, 202);
        $this->assertSame([$first['incidentId'], 2], [$again['incidentId'], (int) $this->row($first['incidentId'])['count']]);
        // refus champ par champ ; une application ne se dit jamais « backend »
        $this->assertSame(['app' => 'INVALID_VALUE', 'message' => 'REQUIRED', 'appVersion' => 'INVALID_VALUE', 'context' => 'INVALID_VALUE'],
            $this->json('POST', '/api/incidents/report', ['app' => 'backend', 'appVersion' => 'version invalide !', 'context' => 'texte'], null, 422)['fields']);
        $this->assertSame(['level' => 'INVALID_VALUE'], $this->json('POST', '/api/incidents/report', ['app' => 'web', 'message' => 'x', 'level' => 'fatal'], null, 422)['fields']);
        // avec un jeton : un incident non anonyme, le compte gardé par une empreinte seulement
        $known = $this->json('POST', '/api/incidents/report', ['app' => 'gestion', 'message' => 'Écran des ventes : réponse illisible'], 'admin', 202);
        $this->assertFalse((bool) $this->row($known['incidentId'])['anonymous']);
        $hash = (string) $this->master->query('SELECT user_hash FROM platform_incident_event WHERE incident_id = ' . $known['incidentId'])->fetchColumn();
        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $hash);
        // limite sans jeton par site et par adresse : 30 rapports en 5 minutes, puis 429 avec Retry-After ; un refus n'est pas une panne
        static::getContainer()->get(IncidentLimits::class)->reset('anonymous', MV_TEST_TENANT_CODE . '|10.98.0.1');
        for ($i = 0; $i < 30; ++$i) {
            $this->json('POST', '/api/incidents/report', ['app' => 'web', 'message' => 'Boucle'], null, 202, '10.98.0.1');
        }
        $refused = $this->api('POST', '/api/incidents/report', ['app' => 'web', 'message' => 'Boucle'], null, '10.98.0.1');
        $this->assertSame([429, 'RATE_LIMITED'], [$refused->getStatusCode(), json_decode((string) $refused->getContent(), true)['code']]);
        $this->assertGreaterThan(0, (int) $refused->headers->get('Retry-After'));
        $this->assertSame(0, (int) $this->master->query("SELECT COUNT(*) FROM platform_incident WHERE app = 'backend'")->fetchColumn(), 'un 429 n\'est pas un incident du serveur');
        static::getContainer()->get(IncidentLimits::class)->reset('anonymous', MV_TEST_TENANT_CODE . '|10.98.0.1');
    }

    /** INC-2 (18/11/2026) : la Boussole et Mémoires Vivantes se nomment ; les répétitions regroupées par l'application comptent */
    public function testBoussoleAndMemoiresAreKnownAndRepeatsCountWithoutBeingStored(): void
    {
        foreach (['boussole', 'memoires'] as $app) {
            $this->assertSame($app, $this->row($this->json('POST', '/api/incidents/report', ['app' => $app, 'message' => "Écran de $app : erreur"], null, 202)['incidentId'])['app']);
        }
        $first = $this->json('POST', '/api/incidents/report', ['app' => 'pos', 'message' => 'Imprimante muette', 'context' => ['repeats' => 5, 'component' => 'printer']], null, 202);
        $row = $this->row($first['incidentId']);
        $this->assertSame([5, ['component' => 'printer']], [(int) $row['count'], json_decode((string) $row['context'], true)], 'compte de 5, la clé repeats n\'est pas gardée au contexte');
        $this->assertSame(5, (int) $this->master->query('SELECT repeats FROM platform_incident_event WHERE incident_id = ' . $first['incidentId'])->fetchColumn());
        $this->json('POST', '/api/incidents/report', ['app' => 'pos', 'message' => 'Imprimante muette'], null, 202);
        $this->assertSame(6, (int) $this->row($first['incidentId'])['count'], 'sans repeats : un de plus');
        $this->json('POST', '/api/incidents/report', ['app' => 'pos', 'message' => 'Imprimante muette', 'context' => ['repeats' => PlatformIncidentService::MAX_REPEATS]], null, 202);
        $this->assertSame(6 + PlatformIncidentService::MAX_REPEATS, (int) $this->row($first['incidentId'])['count']);
        foreach ([0, PlatformIncidentService::MAX_REPEATS + 1, '3', 2.5, -1] as $bad) {
            $this->assertSame(['context.repeats' => 'INVALID_VALUE'], $this->json('POST', '/api/incidents/report', ['app' => 'pos', 'message' => 'Imprimante muette', 'context' => ['repeats' => $bad]], null, 422)['fields'], var_export($bad, true));
        }
        $this->assertSame(6 + PlatformIncidentService::MAX_REPEATS, (int) $this->row($first['incidentId'])['count'], 'un refus ne compte pas');
    }

    public function testNewIncidentsWithoutTokenAreCappedPerSiteAndDayAndCountedBeyond(): void
    {
        $x = $this->json('POST', '/api/incidents/report', ['app' => 'boutique', 'message' => 'Panier : total introuvable'], null, 202);
        $insert = $this->master->prepare("INSERT INTO platform_incident (fingerprint, tenant_code, app, level, message, anonymous, first_seen_at, last_seen_at) VALUES (?, ?, 'web', 'error', 'rempli par l''essai', true, NOW(), NOW())");
        for ($i = 1; $i < PlatformIncidentService::DAILY_NEW_ANONYMOUS; ++$i) {
            $insert->execute([hash('sha256', $this->tag . $i), MV_TEST_TENANT_CODE]);
        }
        // le 51e incident nouveau sans jeton du jour n'est pas créé : il compte dans l'incident « plafond atteint » du site
        $over = $this->json('POST', '/api/incidents/report', ['app' => 'web', 'message' => 'Message varié n° un'], null, 202);
        $overRow = $this->row($over['incidentId']);
        $this->assertSame(['backend', 'warning', MV_TEST_TENANT_CODE, 1], [$overRow['app'], $overRow['level'], $overRow['tenant_code'], (int) $overRow['count']]);
        $this->assertStringStartsWith('Plafond du jour atteint', $overRow['message']);
        $this->assertSame($over['incidentId'], $this->json('POST', '/api/incidents/report', ['app' => 'mobile', 'screen' => 'Autre écran', 'message' => 'Message varié deux'], null, 202)['incidentId']);
        $this->assertSame(2, (int) $this->row($over['incidentId'])['count']);
        $this->assertSame(PlatformIncidentService::DAILY_NEW_ANONYMOUS + 1, (int) $this->master->query('SELECT COUNT(*) FROM platform_incident')->fetchColumn(), 'rien de plus n\'est créé');
        // un incident déjà connu compte toujours ; un compte connecté crée toujours
        $this->assertSame($x['incidentId'], $this->json('POST', '/api/incidents/report', ['app' => 'boutique', 'message' => 'Panier : total introuvable'], null, 202)['incidentId']);
        $this->assertSame(2, (int) $this->row($x['incidentId'])['count']);
        $known = $this->json('POST', '/api/incidents/report', ['app' => 'gestion', 'message' => 'Nouveau message d\'un compte'], 'admin', 202);
        $this->assertSame('gestion', $this->row($known['incidentId'])['app']);
    }

    public function testServerErrorsAnErrorIncludedBecomeIncidentsWrittenOnceAMinuteWithTheNeutralAnswer(): void
    {
        // une \Error dans un contrôleur : la requête rend une 500 (le noyau la prend en charge, handle_all_throwables) et un incident naît
        $response = $this->api('GET', '/api/site/storefront', null, null, '10.98.0.2', ['HTTP_X_TEST_RAISE' => 'error']);
        $this->assertSame(500, $response->getStatusCode());
        $rows = $this->master->query("SELECT * FROM platform_incident WHERE app = 'backend'")->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertCount(1, $rows);
        $row = $rows[0];
        $this->assertSame([MV_TEST_TENANT_CODE, 'api_site_storefront', 'error', 1, ['httpStatus' => 500, 'exception' => 'TypeError']],
            [$row['tenant_code'], $row['route'], $row['level'], (int) $row['count'], json_decode((string) $row['context'], true)]);
        $this->assertStringStartsWith('TypeError: Argument #1 must be of type int', $row['message']);
        foreach (['marie.essai', '555-0199', '5145550199'] as $clear) {
            $this->assertStringNotContainsString($clear, $row['message'], "« $clear » masqué");
        }
        // rafale : au plus une écriture par empreinte et par minute, le reste compté en cache puis écrit avec la suivante
        for ($i = 0; $i < 9; ++$i) {
            $this->api('GET', '/api/site/storefront', null, null, '10.98.0.2', ['HTTP_X_TEST_RAISE' => 'error']);
        }
        $this->assertSame([1, 1], [(int) $this->row((int) $row['id'])['count'], $this->events((int) $row['id'])], 'une seule écriture pendant la minute');
        $cache = static::getContainer()->get('cache.state');
        $item = $cache->getItem('platform_incident_w_' . $row['fingerprint']);
        $this->assertSame(9, $item->get()['pending']);
        $item->set(['at' => time() - PlatformIncidentService::WRITE_EVERY_SECONDS - 1, 'pending' => 9]); // la minute est passée
        $cache->save($item);
        $this->api('GET', '/api/site/storefront', null, null, '10.98.0.2', ['HTTP_X_TEST_RAISE' => 'error']);
        $this->assertSame([11, 2], [(int) $this->row((int) $row['id'])['count'], $this->events((int) $row['id'])], 'rien de perdu : 1 + 9 + 1');
        // une exception ordinaire : un autre incident
        $this->api('GET', '/api/site/storefront', null, null, '10.98.0.2', ['HTTP_X_TEST_RAISE' => 'exception']);
        $this->assertSame(1, (int) $this->master->query("SELECT COUNT(*) FROM platform_incident WHERE app = 'backend' AND message LIKE 'RuntimeException: %'")->fetchColumn());
        // la réponse de production à une \Error (débogage coupé) : le JSON neutre, sans message ni trace
        $event = new ExceptionEvent(static::$kernel, Request::create('/api/site/storefront'), HttpKernelInterface::MAIN_REQUEST, new \TypeError(FakeControllerErrorListener::MESSAGE));
        (new ApiExceptionListener(false))($event);
        $this->assertSame([500, '{"error":"Internal Server Error"}'], [$event->getResponse()->getStatusCode(), $event->getResponse()->getContent()]);
        $cache->deleteItem('platform_incident_w_' . $row['fingerprint']);
    }

    public function testOnlyThePlatformReadsAndFollowsIncidentsAndTheDailyMailThenPurges(): void
    {
        $id = $this->json('POST', '/api/incidents/report', ['app' => 'livingmemory', 'message' => 'Lecture audio impossible'], null, 202)['incidentId'];
        // cloisonnement : ni un visiteur, ni un administrateur de site
        $this->assertSame(401, $this->api('GET', '/api/platform/incidents', null, null)->getStatusCode());
        $this->assertSame(403, $this->api('GET', '/api/platform/incidents', null, 'admin')->getStatusCode());
        $this->assertSame(403, $this->api('PATCH', "/api/platform/incidents/$id", ['status' => 'ignored'], 'admin')->getStatusCode());
        $list = $this->json('GET', '/api/platform/incidents?app=livingmemory&status=new', null, 'super', 200);
        $this->assertSame([1, $id, 'Lecture audio impossible'], [$list['total'], $list['incidents'][0]['id'], $list['incidents'][0]['message']]);
        $this->assertSame(['status' => 'INVALID_VALUE'], $this->json('GET', '/api/platform/incidents?status=perdu', null, 'super', 422)['fields']);
        $this->assertCount(1, $this->json('GET', "/api/platform/incidents/$id", null, 'super', 200)['events']);
        $this->assertSame('INCIDENT_NOT_FOUND', $this->json('GET', '/api/platform/incidents/999999999', null, 'super', 404)['code']);
        $this->assertSame(['fixedIn' => 'INVALID_VALUE', 'externalUrl' => 'INVALID_VALUE'], $this->json('PATCH', "/api/platform/incidents/$id", ['externalUrl' => 'javascript:alert(1)', 'fixedIn' => 'a b'], 'super', 422)['fields']);
        // corrigé, puis revenu : de nouveau « nouveau », marqué « revenu »
        $fixed = $this->json('PATCH', "/api/platform/incidents/$id", ['status' => 'fixed', 'fixedIn' => 'abc1234', 'note' => 'cause trouvée', 'externalUrl' => 'https://exemple.invalid/ticket/1'], 'super', 200);
        $this->assertSame(['fixed', 'abc1234', false], [$fixed['status'], $fixed['fixedIn'], $fixed['reopened']]);
        $this->assertNotNull($fixed['statusChangedBy']);
        $this->json('POST', '/api/incidents/report', ['app' => 'livingmemory', 'message' => 'Lecture audio impossible'], null, 202);
        $back = $this->json('GET', "/api/platform/incidents/$id", null, 'super', 200);
        $this->assertSame(['new', true, 2], [$back['status'], $back['reopened'], $back['count']]);
        // l'écran EasyAdmin, au super administrateur seul
        $this->loginAs(['ROLE_ADMIN']);
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin/plateforme/incidents', [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->loginAs(['ROLE_ADMIN', 'ROLE_SUPER_ADMIN']);
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin/plateforme/incidents?app=livingmemory', [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $page = (string) $this->client->getResponse()->getContent();
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertStringContainsString('Lecture audio impossible', $page);
        $this->assertSame(1, preg_match('/name="_token" value="([^"]+)"/', $page, $m));
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . "/admin/plateforme/incidents/$id", ['_token' => 'faux', 'status' => 'ignored'], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $this->assertSame('new', $this->row($id)['status'], 'jeton de sécurité faux : rien n\'est changé');
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . "/admin/plateforme/incidents/$id", ['_token' => html_entity_decode($m[1]), 'status' => 'acknowledged'], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $this->assertTrue($this->client->getResponse()->isRedirection());
        $this->assertSame('acknowledged', $this->row($id)['status']);
        // courriel du jour : rien sans l'adresse de la plateforme ; envoyé avec elle ; puis la purge
        $old = (int) $this->master->query("INSERT INTO platform_incident (fingerprint, app, level, message, first_seen_at, last_seen_at, status, status_changed_at) VALUES ('" . hash('sha256', 'vieux' . $this->tag) . "', 'web', 'error', 'ancien', NOW() - INTERVAL '400 days', NOW() - INTERVAL '400 days', 'fixed', NOW() - INTERVAL '400 days') RETURNING id")->fetchColumn();
        $this->master->exec("INSERT INTO platform_incident_event (incident_id, occurred_at) VALUES ($id, NOW() - INTERVAL '100 days')");
        $command = new CommandTester((new Application(static::$kernel))->find('app:platform:incidents-digest'));
        $command->execute(['--dry-run' => true]);
        $this->assertStringContainsString('Adresse des alertes de la plateforme absente', $command->getDisplay());
        $_ENV['POS_ALERT_PLATFORM_EMAIL'] = $_SERVER['POS_ALERT_PLATFORM_EMAIL'] = 'plateforme@example.invalid';
        $command->execute([]);
        $this->assertStringContainsString('Courriel envoyé : 1 nouveaux, 0 revenus, 0 signalements en attente.', $command->getDisplay(), $command->getDisplay());
        $this->assertSame('plateforme@example.invalid', $this->lastRecipient(), 'la variable du serveur en secours');
        $this->assertStringContainsString('Purge : 1 occurrences, 1 incidents, 0 captures.', $command->getDisplay());
        $this->assertNull($this->row($old));
        $this->assertNotNull($this->row($id));
        // le réglage de la page Plateforme passe avant la variable du serveur (19/11/2026)
        static::getContainer()->get(\App\Services\Platform\PlatformAlertRecipient::class)->set('alertes-reglage@example.invalid', 'test');
        $command->execute(['--dry-run' => true]);
        $this->assertStringContainsString('À envoyer : 1 nouveaux', $command->getDisplay());
        $this->master->exec("UPDATE platform_incident SET status = 'new'");
        $command->execute(['--dry-run' => false]);
        $this->assertSame('alertes-reglage@example.invalid', $this->lastRecipient(), 'le réglage d\'abord');
        $this->master->exec('DELETE FROM platform_incident');
        $command->execute([]);
        $this->assertStringContainsString('Aucun incident nouveau ni revenu, aucun signalement en attente : aucun courriel.', $command->getDisplay());
    }

    private function lastRecipient(): ?string
    {
        $messages = $this->getMailerMessages();
        $last = end($messages);

        return $last instanceof \Symfony\Component\Mime\Email ? ($last->getTo()[0] ?? null)?->getAddress() : null;
    }

    /** @return array<string, mixed>|null */
    private function row(int $id): ?array
    {
        $row = $this->master->query('SELECT * FROM platform_incident WHERE id = ' . $id)->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    private function events(int $id): int
    {
        return (int) $this->master->query('SELECT COUNT(*) FROM platform_incident_event WHERE incident_id = ' . $id)->fetchColumn();
    }

    /** @return array<string, mixed> */
    private function json(string $method, string $path, ?array $body, ?string $as, int $expected, ?string $ip = null): array
    {
        $response = $this->api($method, $path, $body, $as, $ip);
        $this->assertSame($expected, $response->getStatusCode(), "$method $path : " . substr((string) $response->getContent(), 0, 500));

        return json_decode((string) $response->getContent(), true) ?? [];
    }

    /** @param array<string, string> $headers */
    private function api(string $method, string $path, ?array $body, ?string $as, ?string $ip = null, array $headers = []): Response
    {
        $this->client->getCookieJar()->clear();
        $server = ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json',
            'REMOTE_ADDR' => $ip ?? '10.97.' . random_int(1, 254) . '.' . random_int(1, 254)] + $headers;
        if ($as !== null) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer ' . $this->token($as);
        }
        $this->client->request($method, 'https://' . MV_TEST_TENANT_HOST . $path, [], [], $server, $body !== null ? json_encode($body) : null);

        return $this->client->getResponse();
    }

    /** Jeton d'un compte du site de test : `super` ou `admin` */
    private function token(string $as): string
    {
        if (isset($this->tokens[$as])) {
            return $this->tokens[$as];
        }
        $user = $this->user($as === 'super' ? ['ROLE_ADMIN', 'ROLE_SUPER_ADMIN'] : ['ROLE_ADMIN']);

        return $this->tokens[$as] = static::getContainer()->get('lexik_jwt_authentication.jwt_manager')->create($user);
    }

    private function loginAs(array $roles): void
    {
        $user = $this->user($roles);
        $this->client->loginUser($user, 'main');
        OtpTestHelper::validateIfRequired($this->client, $user);
    }

    private function user(array $roles): User
    {
        $container = static::getContainer();
        $provider = $container->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $em = $provider->getEntityManager();
        $email = 'incidents-' . bin2hex(random_bytes(3)) . '-' . $this->tag . '@example.invalid';
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Incidents')->setLastname('Essai')->setRoles($roles)->setPassword('inutilisable')->setIsVerified(true);
        $em->persist($user);
        $em->flush();

        return $user;
    }
}
