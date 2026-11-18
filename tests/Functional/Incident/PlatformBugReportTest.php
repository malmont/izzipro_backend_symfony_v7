<?php

namespace App\Tests\Functional\Incident;

use App\Entity\User;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use App\Tests\Functional\Support\OtpTestHelper;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Response;

/**
 * Signalements de la plateforme, lot INC-3 (18/11/2026, `docs/incidents-et-signalements.md`) : le personnel seul signale ; la capture est
 * réencodée, rangée en privé et servie au SEUL super administrateur (jamais au site, ni dans « Mes signalements », ni au signaleur) ;
 * cloisonnement par site et par compte ; limites ; réponse et état par la plateforme, courriel au signaleur ; escalade d'un ticket de
 * l'Assistance (un seul ouvert), état lu sur le ticket ; courriel du jour et purge des captures.
 */
class PlatformBugReportTest extends WebTestCase
{
    private const SECRET_TEXT = 'Marie Tremblay 4242';
    private KernelBrowser $client;
    private \PDO $master;
    private \Doctrine\DBAL\Connection $db;
    private string $tag;
    /** @var array{0: mixed, 1: mixed} */
    private array $mailer = [null, null];
    /** @var array<string, User> */
    private array $users = [];

    protected function setUp(): void
    {
        $this->mailer = [$_ENV['MAILER_DSN'] ?? null, $_SERVER['MAILER_DSN'] ?? null];
        $_ENV['MAILER_DSN'] = $_SERVER['MAILER_DSN'] = 'null://plateforme-envoi%40example.invalid:x@default'; // l'expéditeur de la plateforme des essais
        $this->client = static::createClient();
        $this->master = static::getContainer()->get(TenantConnectionManager::class)->getPdoMaster();
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $this->db = $provider->getEntityManager()->getConnection();
        $this->tag = bin2hex(random_bytes(3));
        $this->clean();
    }

    protected function tearDown(): void
    {
        $this->clean();
        unset($_ENV['POS_ALERT_PLATFORM_EMAIL'], $_SERVER['POS_ALERT_PLATFORM_EMAIL']);
        [$_ENV['MAILER_DSN'], $_SERVER['MAILER_DSN']] = $this->mailer;
        parent::tearDown();
    }

    public function testStaffReportsAndTheScreenshotIsServedToThePlatformAlone(): void
    {
        $png = $this->png();
        $this->assertStringContainsString(self::SECRET_TEXT, (string) file_get_contents($png), 'la capture d\'origine porte un texte caché');
        // le personnel seul : un client, un visiteur sont refusés
        $this->assertSame(403, $this->post('customer', ['app' => 'boutique', 'description' => 'La page du panier se fige.'])->getStatusCode());
        $this->assertSame(401, $this->post(null, ['app' => 'boutique', 'description' => 'La page du panier se fige.'])->getStatusCode());
        // un administrateur du site, avec capture ; texte masqué, genre tiré des rôles
        $sent = $this->post('admin', ['app' => 'gestion', 'appVersion' => '2.1.0', 'screen' => 'Achats/Facture?fournisseur=12', 'reporterKind' => 'cashier',
            'description' => 'Le total est faux pour marie.t@example.com, rappeler au 514-555-1234.', 'context' => json_encode(['component' => 'invoice', 'name' => 'Marie'])], $png);
        $this->assertSame(201, $sent->getStatusCode(), (string) $sent->getContent());
        $report = json_decode((string) $sent->getContent(), true)['report'];
        $row = $this->row($report['id']);
        $this->assertSame([MV_TEST_TENANT_CODE, 'gestion', 'site_admin', 'received', 'Achats/Facture', ['component' => 'invoice']],
            [$row['tenant_code'], $row['app'], $row['reporter_kind'], $row['status'], $row['screen'], json_decode((string) $row['context'], true)]);
        $this->assertSame('Le total est faux pour m***@example.com, rappeler au •••• 1234.', $row['description']);
        // la capture : réencodée (le texte caché ne survit pas), rangée en privé
        $stored = static::getContainer()->getParameter('kernel.project_dir') . '/var/storage/private_media/platform-bug-reports/' . $row['screenshot_path'];
        $this->assertFileExists($stored);
        $this->assertStringNotContainsString(self::SECRET_TEXT, (string) file_get_contents($stored), 'métadonnées retirées par le réencodage');
        // jamais rendue au signaleur ni au site : ni la capture, ni sa présence, ni son chemin
        $this->assertSame(['id', 'app', 'appVersion', 'screen', 'description', 'status', 'answer', 'createdAt', 'answeredAt'], array_keys($report));
        $mine = $this->json('GET', '/api/incidents/bug-reports', null, 'admin', 200);
        $this->assertSame([$report['id']], array_column($mine['reports'], 'id'));
        foreach ([(string) $sent->getContent(), json_encode($mine)] as $seen) {
            $this->assertStringNotContainsString('platform-bug-reports', $seen);
            $this->assertStringNotContainsString((string) $row['screenshot_path'], $seen);
            $this->assertStringNotContainsString('screenshot', $seen);
        }
        $this->assertSame(403, $this->api('GET', '/api/platform/bug-reports/' . $report['id'] . '/capture', null, 'admin')->getStatusCode(), 'pas au site');
        $this->assertSame(403, $this->api('GET', '/api/platform/bug-reports/' . $report['id'], null, 'admin')->getStatusCode());
        $this->loginAs(['ROLE_ADMIN']);
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin/plateforme/signalements/' . $report['id'] . '/capture', [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $this->assertSame(403, $this->client->getResponse()->getStatusCode(), 'pas dans l\'espace d\'administration du site');
        // au super administrateur seul, sans cache
        $image = $this->api('GET', '/api/platform/bug-reports/' . $report['id'] . '/capture', null, 'super');
        $this->assertSame([200, 'image/png'], [$image->getStatusCode(), $image->headers->get('Content-Type')]);
        $this->assertStringContainsString('no-store', (string) $image->headers->get('Cache-Control'));
        $this->assertTrue($this->json('GET', '/api/platform/bug-reports/' . $report['id'], null, 'super', 200)['screenshot']);
        // cloisonnement : un autre compte du même site, un autre site ne voient rien
        $this->assertSame(201, $this->post('cashier', ['app' => 'pos', 'description' => 'La caisse ne lit plus les codes.'])->getStatusCode());
        $cashier = $this->json('GET', '/api/incidents/bug-reports', null, 'cashier', 200)['reports'];
        $this->assertCount(1, $cashier);
        $this->assertSame('cashier', $this->row($cashier[0]['id'])['reporter_kind']);
        $this->master->prepare("INSERT INTO platform_bug_report (tenant_code, app, description, reporter_kind, reporter_user_id, created_at, updated_at) VALUES ('autre-site', 'web', 'd''un autre site', 'site_admin', ?, NOW(), NOW())")
            ->execute([$this->users['admin']->getId()]);
        $this->assertSame([$report['id']], array_column($this->json('GET', '/api/incidents/bug-reports', null, 'admin', 200)['reports'], 'id'), 'même compte, autre site : rien');
        // refus champ par champ
        $gif = tempnam(sys_get_temp_dir(), 'gif');
        file_put_contents($gif, base64_decode('R0lGODlhAQABAAAAACw='));
        $refused = json_decode((string) $this->post('admin', ['app' => 'dessin', 'description' => 'court', 'appVersion' => 'version invalide !'], $gif)->getContent(), true);
        $this->assertSame(['description' => 'INVALID_LENGTH', 'app' => 'INVALID_VALUE', 'appVersion' => 'INVALID_VALUE', 'screenshot' => 'FORMAT'], $refused['fields']);
        // limites : 10 par compte et 30 par site et par jour
        $fill = $this->master->prepare("INSERT INTO platform_bug_report (tenant_code, app, description, reporter_kind, reporter_user_id, created_at, updated_at) VALUES (?, 'web', 'rempli par l''essai', 'site_admin', ?, NOW(), NOW())");
        for ($i = 1; $i < 10; ++$i) {
            $fill->execute([MV_TEST_TENANT_CODE, $this->users['admin']->getId()]);
        }
        $this->assertSame(['BUG_REPORT_LIMIT', 'account'], array_values(array_intersect_key(json_decode((string) $this->post('admin', ['app' => 'web', 'description' => 'Un onzième signalement.'])->getContent(), true), ['code' => 1, 'scope' => 1])));
        for ($i = 0; $i < 20; ++$i) {
            $fill->execute([MV_TEST_TENANT_CODE, null]);
        }
        $site = $this->post('cashier', ['app' => 'pos', 'description' => 'Le trente et unième du site.']);
        $this->assertSame([429, 'site'], [$site->getStatusCode(), json_decode((string) $site->getContent(), true)['scope']]);
    }

    public function testThePlatformAnswersTheReporterIsMailedAndReadsOnlyThePublicAnswer(): void
    {
        $id = json_decode((string) $this->post('admin', ['app' => 'web', 'description' => 'Le formulaire de contact ne part pas.'])->getContent(), true)['report']['id'];
        $this->assertSame(403, $this->api('PATCH', "/api/platform/bug-reports/$id", ['status' => 'fixed'], 'admin')->getStatusCode());
        $this->assertSame(['status' => 'INVALID_VALUE', 'incidentId' => 'INVALID_VALUE'], $this->json('PATCH', "/api/platform/bug-reports/$id", ['status' => 'perdu', 'incidentId' => 999999999], 'super', 422)['fields']);
        // une note interne seule : pas de courriel
        $this->assertFalse($this->json('PATCH', "/api/platform/bug-reports/$id", ['note' => 'note interne : voir le journal du serveur'], 'super', 200)['mailed']);
        // la réponse et l'état final : courriel au signaleur
        $incident = (int) $this->master->query("INSERT INTO platform_incident (fingerprint, app, level, message, first_seen_at, last_seen_at) VALUES ('" . hash('sha256', 'inc' . $this->tag) . "', 'web', 'error', 'x', NOW(), NOW()) RETURNING id")->fetchColumn();
        $done = $this->json('PATCH', "/api/platform/bug-reports/$id", ['status' => 'fixed', 'answer' => 'Corrigé dans la version du 19 novembre.', 'incidentId' => $incident], 'super', 200);
        $this->assertSame([true, 'fixed', $incident], [$done['mailed'], $done['status'], $done['incidentId']]);
        $this->assertNotNull($done['answeredAt']);
        $mine = $this->json('GET', '/api/incidents/bug-reports', null, 'admin', 200)['reports'][0];
        $this->assertSame(['fixed', 'Corrigé dans la version du 19 novembre.'], [$mine['status'], $mine['answer']]);
        $this->assertStringNotContainsString('note interne', json_encode($mine), 'jamais la note interne');
        $this->assertSame('BUG_REPORT_NOT_FOUND', $this->json('GET', '/api/platform/bug-reports/999999999', null, 'super', 404)['code']);
        $this->assertSame([1, $id], [$this->json('GET', '/api/platform/bug-reports?status=fixed&tenant=' . MV_TEST_TENANT_CODE, null, 'super', 200)['total'], $this->json('GET', '/api/platform/bug-reports?status=fixed', null, 'super', 200)['reports'][0]['id']]);
    }

    public function testASupportTicketIsEscalatedOnceAndTheMerchantReadsOnlyTheState(): void
    {
        $ticket = $this->json('POST', '/api/support/tickets', ['subject' => 'Paiement refusé', 'body' => 'La cliente Alice Martin (alice@example.invalid) ne peut pas payer.', 'customer' => ['name' => 'Alice Martin', 'email' => 'alice@example.invalid']], 'agent', 201);
        $tid = (int) ($ticket['id'] ?? $ticket['ticket']['id']);
        $this->assertNull($this->json('GET', "/api/support/tickets/$tid", null, 'agent', 200)['platform'], 'jamais transmis');
        $this->assertSame(['summary' => 'INVALID_LENGTH'], $this->json('POST', "/api/support/tickets/$tid/escalade", ['summary' => 'trop court'], 'agent', 422)['fields']);
        $sent = $this->json('POST', "/api/support/tickets/$tid/escalade", ['summary' => 'Le paiement par carte échoue pour alice@example.invalid depuis ce matin.'], 'agent', 201);
        $this->assertSame(['status' => 'transmitted', 'answer' => null], $sent['platform']);
        $this->assertSame('ALREADY_ESCALATED', $this->json('POST', "/api/support/tickets/$tid/escalade", ['summary' => 'Une seconde transmission du même ticket.'], 'agent', 409)['code']);
        $this->assertSame('TICKET_NOT_FOUND', $this->json('POST', '/api/support/tickets/999999999/escalade', ['summary' => 'Un ticket qui n\'existe pas du tout.'], 'agent', 404)['code']);
        // le signalement : le résumé masqué, lié au ticket ; ni le fil, ni le client
        $row = $this->master->query("SELECT * FROM platform_bug_report WHERE support_ticket_id = $tid AND tenant_code = '" . MV_TEST_TENANT_CODE . "'")->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame(['support', 'support_agent', 'Le paiement par carte échoue pour a***@example.invalid depuis ce matin.'], [$row['app'], $row['reporter_kind'], $row['description']]);
        $this->assertStringNotContainsString('Alice', json_encode($row), 'aucune copie du fil ni du client');
        // la plateforme répond : le commerçant lit l'état et la réponse publique seulement ; l'agent est prévenu
        $answer = $this->json('PATCH', '/api/platform/bug-reports/' . $row['id'], ['status' => 'wont_fix', 'answer' => 'Refus de la banque, rien à corriger chez nous.', 'note' => 'note interne'], 'super', 200);
        $this->assertTrue($answer['mailed']);
        $seen = $this->json('GET', "/api/support/tickets/$tid", null, 'agent', 200);
        $this->assertSame(['status' => 'wont_fix', 'answer' => 'Refus de la banque, rien à corriger chez nous.'], $seen['platform']);
        $this->assertStringNotContainsString('note interne', json_encode($seen));
        // une fois la transmission close, le ticket peut être transmis de nouveau
        $this->assertSame('transmitted', $this->json('POST', "/api/support/tickets/$tid/escalade", ['summary' => 'Le problème revient après la correction de la banque.'], 'agent', 201)['platform']['status']);
    }

    public function testTheDailyMailListsUnansweredReportsAndOldScreenshotsArePurged(): void
    {
        $sent = $this->post('admin', ['app' => 'gestion', 'description' => 'Une capture qui vieillira.'], $this->png());
        $id = json_decode((string) $sent->getContent(), true)['report']['id'];
        $path = (string) $this->row($id)['screenshot_path'];
        $file = static::getContainer()->getParameter('kernel.project_dir') . '/var/storage/private_media/platform-bug-reports/' . $path;
        $this->master->exec("UPDATE platform_bug_report SET created_at = NOW() - INTERVAL '50 hours' WHERE id = $id");
        $this->master->exec("INSERT INTO platform_settings (key, value, updated_at, updated_by) VALUES ('bug_report_screenshot_days', '1', NOW(), 'essai') ON CONFLICT (key) DO UPDATE SET value = '1'");
        $_ENV['POS_ALERT_PLATFORM_EMAIL'] = $_SERVER['POS_ALERT_PLATFORM_EMAIL'] = 'plateforme@example.invalid';
        $command = new CommandTester((new Application(static::$kernel))->find('app:platform:incidents-digest'));
        $command->execute([]);
        $this->assertStringContainsString('Courriel envoyé : 0 nouveaux, 0 revenus, 1 signalements en attente.', $command->getDisplay(), $command->getDisplay());
        $this->assertStringContainsString('1 captures.', $command->getDisplay());
        $this->assertFileDoesNotExist($file);
        $row = $this->row($id);
        $this->assertSame([null, true], [$row['screenshot_path'], $row['screenshot_deleted_at'] !== null]);
        $this->assertSame('BUG_REPORT_SCREENSHOT_NOT_FOUND', $this->json('GET', "/api/platform/bug-reports/$id/capture", null, 'super', 404)['code']);
    }

    private function clean(): void
    {
        foreach ($this->master->query('SELECT screenshot_path FROM platform_bug_report WHERE screenshot_path IS NOT NULL')->fetchAll(\PDO::FETCH_COLUMN) as $path) {
            @unlink(static::getContainer()->getParameter('kernel.project_dir') . '/var/storage/private_media/platform-bug-reports/' . $path);
        }
        $this->master->exec('DELETE FROM platform_bug_report');
        $this->master->exec('DELETE FROM platform_incident');
        $this->master->exec("DELETE FROM platform_settings WHERE key = 'bug_report_screenshot_days'");
        $this->db->executeStatement("DELETE FROM support_message WHERE ticket_id IN (SELECT id FROM support_ticket WHERE subject = 'Paiement refusé')");
        $this->db->executeStatement("DELETE FROM support_ticket WHERE subject = 'Paiement refusé'");
    }

    /** Une petite image PNG qui porte un bloc de texte caché (tEXt), comme des métadonnées */
    private function png(): string
    {
        $image = imagecreatetruecolor(4, 4);
        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();
        $data = 'Comment' . "\0" . self::SECRET_TEXT;
        $chunk = pack('N', strlen($data)) . 'tEXt' . $data . pack('N', crc32('tEXt' . $data));
        $bytes = substr($bytes, 0, 33) . $chunk . substr($bytes, 33); // après l'en-tête et le bloc IHDR
        $path = tempnam(sys_get_temp_dir(), 'cap') . '.png';
        file_put_contents($path, $bytes);

        return $path;
    }

    /** @param array<string, mixed> $fields */
    private function post(?string $as, array $fields, ?string $file = null): Response
    {
        $this->client->getCookieJar()->clear();
        $server = ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'HTTP_ACCEPT' => 'application/json', 'REMOTE_ADDR' => '10.96.' . random_int(1, 254) . '.' . random_int(1, 254)];
        if ($as !== null) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer ' . $this->token($as);
        }
        $files = $file !== null ? ['screenshot' => new UploadedFile($file, basename($file), null, null, true)] : [];
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . '/api/incidents/bug-reports', $fields, $files, $server);

        return $this->client->getResponse();
    }

    /** @return array<string, mixed>|null */
    private function row(int $id): ?array
    {
        $row = $this->master->query('SELECT * FROM platform_bug_report WHERE id = ' . $id)->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /** @return array<string, mixed> */
    private function json(string $method, string $path, ?array $body, ?string $as, int $expected): array
    {
        $response = $this->api($method, $path, $body, $as);
        $this->assertSame($expected, $response->getStatusCode(), "$method $path : " . substr((string) $response->getContent(), 0, 500));

        return json_decode((string) $response->getContent(), true) ?? [];
    }

    private function api(string $method, string $path, ?array $body, ?string $as): Response
    {
        $this->client->getCookieJar()->clear();
        $server = ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'];
        if ($as !== null) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer ' . $this->token($as);
        }
        $this->client->request($method, 'https://' . MV_TEST_TENANT_HOST . $path, [], [], $server, $body !== null ? json_encode($body) : null);

        return $this->client->getResponse();
    }

    /** Jeton d'un compte du site de test : `super`, `admin`, `cashier`, `agent` (Assistance) ou `customer` */
    private function token(string $as): string
    {
        $roles = ['super' => ['ROLE_ADMIN', 'ROLE_SUPER_ADMIN'], 'admin' => ['ROLE_ADMIN'], 'cashier' => ['ROLE_USER_POS'], 'agent' => ['ROLE_SUPPORT'], 'customer' => ['ROLE_USER_INTERNET']][$as];
        $this->users[$as] ??= $this->user($roles);

        return static::getContainer()->get('lexik_jwt_authentication.jwt_manager')->create($this->users[$as]);
    }

    private function loginAs(array $roles): void
    {
        $user = $this->user($roles);
        $this->client->loginUser($user, 'main');
        OtpTestHelper::validateIfRequired($this->client, $user);
    }

    private function user(array $roles): User
    {
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $em = $provider->getEntityManager();
        $email = 'signalements-' . bin2hex(random_bytes(3)) . '-' . $this->tag . '@example.invalid';
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Signalements')->setLastname('Essai')->setRoles($roles)->setPassword('inutilisable')->setIsVerified(true);
        $em->persist($user);
        $em->flush();

        return $user;
    }
}
