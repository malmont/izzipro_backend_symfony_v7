<?php

namespace App\Tests\Functional\Platform;

use App\Controller\Admin\UserCrudController;
use App\Entity\User;
use App\Services\Platform\PlatformAccessJournal;
use App\Services\TenantEntityManagerProvider;
use App\Tests\Functional\Support\OtpTestHelper;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Le compte de la plateforme sur un site (lot AG-2, décision du propriétaire du 15/11/2026, `docs/agences-et-import.md` § 8.9) :
 * visible de l'administrateur du site avec sa mention, jamais modifiable par lui (garde existante : `UserCrudSuperAdminGuardTest`), et
 * chaque connexion de ce compte laisse une ligne au journal du site, que l'administrateur lit.
 */
class PlatformAccountGuardsTest extends WebTestCase
{
    private const PASSWORD = 'Un-mot-de-passe-solide-42';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->setServerParameter('HTTP_X_TENANT_HOST', MV_TEST_TENANT_HOST);
        $this->db()->executeStatement("DELETE FROM content_audit_log WHERE resource = 'platform_access'");
    }

    protected function tearDown(): void
    {
        $this->db()->executeStatement("DELETE FROM content_audit_log WHERE resource = 'platform_access'");
        $this->db()->executeStatement("DELETE FROM refresh_tokens WHERE username LIKE 'garde-pf-%@example.invalid'");
        parent::tearDown();
    }

    public function testEveryLoginOfThePlatformAccountLeavesALineInTheSiteJournalThatItsAdministratorReads(): void
    {
        $platform = $this->user(['ROLE_ADMIN', 'ROLE_SUPER_ADMIN']);
        $admin = $this->user(['ROLE_ADMIN']);
        $lines = fn (): array => $this->db()->fetchAllAssociative("SELECT user_identifier, tenant, resource_id, action, after_state FROM content_audit_log WHERE resource = 'platform_access' ORDER BY id");

        // Un administrateur du site qui se connecte ne laisse aucune ligne : seul le compte de la plateforme est tracé
        $login = $this->api('POST', '/api/login', ['username' => $admin->getEmail(), 'password' => self::PASSWORD, 'platform' => 'webadmin']);
        $this->assertSame(200, $login->getStatusCode(), (string) $login->getContent());
        $this->assertSame([], $lines());

        // Le compte de la plateforme, par l'API : identifiants, puis code à usage unique ; la ligne s'écrit quand la connexion aboutit
        $step = $this->api('POST', '/api/login', ['username' => $platform->getEmail(), 'password' => self::PASSWORD, 'platform' => 'webadmin']);
        $this->assertStringContainsString('otp', strtolower((string) $step->getContent()));
        $this->assertSame([], $lines(), 'tant que le code n\'est pas validé, la connexion n\'a pas abouti');
        $this->db()->executeStatement("INSERT INTO otp_code (id, user_otp_id, code, expiration) VALUES (nextval('otp_code_id_seq'), ?, '424242', NOW() + INTERVAL '5 minutes')", [$platform->getId()]);
        $refused = $this->api('POST', '/api/otp-verify', ['username' => $platform->getEmail(), 'otp' => '000000', 'platform' => 'webadmin']);
        $this->assertNotSame(200, $refused->getStatusCode());
        $this->assertSame([], $lines(), 'un code faux ne trace rien');
        $verified = $this->api('POST', '/api/otp-verify', ['username' => $platform->getEmail(), 'otp' => '424242', 'platform' => 'webadmin']);
        $this->assertSame(200, $verified->getStatusCode(), (string) $verified->getContent());
        $this->assertSame([['user_identifier' => $platform->getEmail(), 'tenant' => MV_TEST_TENANT_CODE, 'resource_id' => (string) $platform->getId(), 'action' => 'login', 'after_state' => '{"channel":"webadmin"}']], $lines());

        // Une session renouvelée n'est pas une connexion : rien de plus
        $refresh = null;
        foreach ($verified->headers->getCookies() as $cookie) {
            $this->client->getCookieJar()->set(new \Symfony\Component\BrowserKit\Cookie($cookie->getName(), (string) $cookie->getValue(), null, '/', MV_TEST_TENANT_HOST, true));
            $refresh = str_starts_with($cookie->getName(), 'refresh_token') ? $cookie->getName() : $refresh;
        }
        $this->assertNotNull($refresh, 'la connexion a rendu un jeton de renouvellement');
        $csrf = (string) (json_decode((string) $verified->getContent(), true)['csrf_token'] ?? '');
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . '/api/token/refresh', [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json', 'HTTP_X_XSRF_TOKEN' => $csrf], '{}');
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), 'la session se renouvelle : ' . substr((string) $this->client->getResponse()->getContent(), 0, 300));
        $this->assertCount(1, $lines());

        // Par l'espace d'administration : rien tant que le code n'est pas validé ; puis UNE ligne, à la première page ouverte
        $this->client->getCookieJar()->clear();
        $this->client->loginUser($platform, 'main');
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin');
        $this->assertSame(302, $this->client->getResponse()->getStatusCode(), 'le code à usage unique est demandé');
        $this->assertCount(1, $lines(), 'le compte n\'est pas encore entré');
        OtpTestHelper::validateIfRequired($this->client, $platform);
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin');
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin/acces-plateforme');
        $this->assertSame(['{"channel":"webadmin"}', '{"channel":"easyadmin"}'], array_column($lines(), 'after_state'), 'une ligne par entrée, pas une par page');

        // Sans se reconnecter, le compte de la plateforme passe à l'espace d'administration d'un AUTRE site (son compte y existe, avec le
        // même mot de passe) : une ligne s'écrit au journal de CE site-là
        $second = static::getContainer()->get(\App\Services\Platform\TenantDatabaseConnector::class)->pdo(MV_TEST_TENANT2_DB);
        $second->exec("DELETE FROM content_audit_log WHERE resource = 'platform_access'");
        $second->prepare('INSERT INTO "user" (id, email, roles, password, username, firstname, lastname, is_verified, password_set, otp_enabled, created_at) VALUES ((SELECT COALESCE(MAX(id), 0) + 1 FROM "user"), ?, ?, ?, ?, \'Garde\', \'Plateforme\', true, true, false, NOW())')
            ->execute([$platform->getEmail(), '["ROLE_ADMIN","ROLE_SUPER_ADMIN"]', $platform->getPassword(), $platform->getEmail()]);
        try {
            $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin?tenant=' . MV_TEST_TENANT2_CODE, [], [], ['HTTP_X_TENANT_HOST' => '']);
            $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin/acces-plateforme', [], [], ['HTTP_X_TENANT_HOST' => '']);
            $this->assertSame([[$platform->getEmail(), MV_TEST_TENANT2_CODE, '{"channel":"easyadmin"}']], $second->query("SELECT user_identifier, tenant, after_state FROM content_audit_log WHERE resource = 'platform_access' ORDER BY id")->fetchAll(\PDO::FETCH_NUM));
            $this->assertCount(2, $lines(), 'rien de plus au journal du premier site');
        } finally {
            $second->exec("DELETE FROM content_audit_log WHERE resource = 'platform_access'");
            $second->prepare('DELETE FROM "user" WHERE email = ?')->execute([$platform->getEmail()]);
        }

        // L'administrateur du site lit ces lignes : sur sa page, et par l'API du journal
        $this->client->getCookieJar()->clear();
        $this->client->loginUser($admin, 'main');
        $page = $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin/acces-plateforme');
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), substr((string) $this->client->getResponse()->getContent(), 0, 500));
        $rows = $page->filter('table.acces-plateforme-lignes tbody tr');
        $this->assertCount(2, $rows);
        $this->assertStringContainsString($platform->getEmail(), $rows->eq(0)->text());
        $this->assertStringContainsString('Espace d\'administration', $rows->eq(0)->text());
        $this->assertStringContainsString('Espace d\'administration du web', $rows->eq(1)->text());
        $this->assertStringContainsString(PlatformAccessJournal::LABEL, $page->filter('[data-acces-plateforme]')->text());
        $audit = $this->api('GET', '/api/landingpage-audit?resource=platform_access', null, $admin);
        $this->assertSame(200, $audit->getStatusCode(), (string) $audit->getContent());
        $this->assertStringContainsString($platform->getEmail(), (string) $audit->getContent());
        // Un client du site ne lit ni la page ni le journal
        $customer = $this->user(['ROLE_USER_INTERNET']);
        $this->assertSame(403, $this->api('GET', '/api/landingpage-audit?resource=platform_access', null, $customer)->getStatusCode());
        $this->client->getCookieJar()->clear();
        $this->client->loginUser($customer, 'main');
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin/acces-plateforme');
        $this->assertNotSame(200, $this->client->getResponse()->getStatusCode());

        // Une panne du journal ne fait jamais échouer une connexion
        $journal = static::getContainer()->get(PlatformAccessJournal::class);
        $journal->recordLogin($platform, 'canal-inconnu');
        $this->assertSame('{"channel":"webadmin"}', (string) $this->db()->fetchOne("SELECT after_state FROM content_audit_log WHERE resource = 'platform_access' ORDER BY id DESC LIMIT 1"), 'un canal inconnu est rangé, jamais écrit tel quel');
        $before = (int) $this->db()->fetchOne("SELECT COUNT(*) FROM content_audit_log WHERE resource = 'platform_access'");
        $journal->recordLogin($admin, 'webadmin');
        $this->assertSame($before, (int) $this->db()->fetchOne("SELECT COUNT(*) FROM content_audit_log WHERE resource = 'platform_access'"));
    }

    public function testTheSiteAdministratorSeesThePlatformAccountWithItsMentionInTheUsersLists(): void
    {
        $platform = $this->user(['ROLE_ADMIN', 'ROLE_SUPER_ADMIN']);
        $admin = $this->user(['ROLE_ADMIN']);
        $this->client->loginUser($admin, 'main');

        // Écran des utilisateurs : la ligne du compte de la plateforme porte la mention ; celle d'un autre compte ne la porte pas
        $row = fn (User $user) => $this->indexRow((int) $user->getId());
        $badge = $row($platform)->filter('[data-application="platform"]');
        $this->assertCount(1, $badge);
        $this->assertSame('Compte de la plateforme, pour le dépannage', trim($badge->text()));
        $this->assertCount(0, $row($admin)->filter('[data-application="platform"]'));
        // il le voit, sans pouvoir le modifier ni le supprimer
        $this->assertCount(0, $row($platform)->filter('a.action-edit, a.action-delete'));
        $detail = $this->client->request('GET', $this->adminUrl('detail', (int) $platform->getId()));
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertStringContainsString('Compte de la plateforme, pour le dépannage', $detail->filter('body')->text());

        // API de la console Mémoires Vivantes : le compte est listé et dit
        // la liste est paginée (200 par page) et la suite complète crée des centaines de comptes avant cet essai : toutes les pages sont lues
        $users = [];
        for ($page = 1; $page <= 50; ++$page) {
            $list = $this->api('GET', '/api/memoires/admin/users?limit=200&page=' . $page, null, $admin);
            $this->assertSame(200, $list->getStatusCode(), substr((string) $list->getContent(), 0, 300));
            $body = json_decode((string) $list->getContent(), true);
            $rows = $body['users'] ?? $body['data'] ?? $body;
            if (!is_array($rows) || $rows === []) {
                break;
            }
            $users += array_column($rows, null, 'email');
        }
        $this->assertSame([true, false], [$users[$platform->getEmail()]['platformAccount'], $users[$admin->getEmail()]['platformAccount']]);
    }

    private function user(array $roles): User
    {
        $email = 'garde-pf-' . bin2hex(random_bytes(4)) . '@example.invalid';
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Garde')->setLastname('Plateforme')->setRoles($roles)->setIsVerified(true);
        $user->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD));
        $em = $this->em();
        $em->persist($user);
        $em->flush();

        return $user;
    }

    private function api(string $method, string $path, ?array $body, ?User $as = null): Response
    {
        $this->client->getCookieJar()->clear();
        $server = ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => '10.87.' . random_int(1, 254) . '.' . random_int(1, 254)];
        if ($as !== null) {
            $this->em();
            $server['HTTP_AUTHORIZATION'] = 'Bearer ' . static::getContainer()->get('lexik_jwt_authentication.jwt_manager')->create($as);
        }
        $this->client->request($method, 'https://' . MV_TEST_TENANT_HOST . $path, [], [], $server, $body !== null ? json_encode($body) : null);

        return $this->client->getResponse();
    }

    private function adminUrl(string $action, ?int $entityId = null, array $extra = []): string
    {
        return 'https://' . MV_TEST_TENANT_HOST . '/admin?' . http_build_query(array_filter(['crudAction' => $action, 'crudControllerFqcn' => UserCrudController::class, 'entityId' => $entityId]) + $extra);
    }

    /** Ligne d'un compte dans la liste (triée par identifiant croissant, 20 par page) */
    private function indexRow(int $id): \Symfony\Component\DomCrawler\Crawler
    {
        $position = (int) $this->db()->fetchOne('SELECT COUNT(*) FROM "user" WHERE id <= ?', [$id]);
        $crawler = $this->client->request('GET', $this->adminUrl('index', null, ['page' => intdiv($position - 1, 20) + 1]));
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());

        return $crawler->filter(sprintf('tr[data-id="%d"]', $id));
    }

    private function em()
    {
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);

        return $provider->getEntityManager();
    }

    private function db(): Connection
    {
        return $this->em()->getConnection();
    }
}
