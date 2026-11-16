<?php

namespace App\Tests\Functional\Security;

use App\Controller\Admin\UserCrudController;
use App\Entity\User;
use App\Services\Http\TenantHostFormat;
use App\Services\SuperAdminService\SuperAdminAccountService;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use App\Tests\Functional\Support\OtpTestHelper;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Train de sécurité du 16/11/2026 : trois chemins d'élévation trouvés par la seconde relecture du train, fermés.
 * 1. Un lien de courriel « empoisonné » par l'en-tête de site (`victime:@pirate.example`) : le jeton de réinitialisation d'un
 *    administrateur partait vers un hôte de l'attaquant.
 * 2. L'application du compte de la plateforme visait la première ligne venue de même adresse à la casse près : un compte qui aurait
 *    pris cette adresse à une majuscule près devenait super administrateur.
 * 3. L'administrateur du site d'attache d'une agence pouvait changer le mot de passe ou l'adresse d'un compte d'agence.
 */
class ElevationPathsTest extends WebTestCase
{
    private KernelBrowser $client;
    private Connection $db;
    private string $tag;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $this->db = $provider->getEntityManager()->getConnection();
        $this->tag = bin2hex(random_bytes(3));
    }

    protected function tearDown(): void
    {
        foreach (static::getContainer()->get(SuperAdminAccountService::class)->databases() as $base) {
            try {
                $pdo = $this->pdo($base['dbname']);
                $pdo->exec("DELETE FROM refresh_tokens WHERE lower(username) LIKE 'elevation-%@example.invalid'");
                $pdo->exec("DELETE FROM \"user\" WHERE lower(email) LIKE 'elevation-%@example.invalid'");
            } catch (\Throwable) {
                // base d'essai absente : rien à nettoyer
            }
        }
        parent::tearDown();
    }

    public function testASiteAskedWithAnythingButAHostNameIsRefusedAndNoResetLinkLeavesForAnotherHost(): void
    {
        foreach (['mvtest.test', 'mvtest', 'demo.localhost:3000', 'www.boutique.ca', 'boutique.ca.', 'a-b_c.exemple.test:443'] as $host) {
            $this->assertTrue(TenantHostFormat::valid($host), $host);
        }
        foreach (['mvtest:@pirate.example', 'mvtest@pirate.example', 'pirate.example/mvtest', 'mvtest.test:abc', 'mvtest.test:80@x', 'mvtest test', 'mvtest\\pirate', '-mvtest.test', 'mvtest..test', '', 'https://mvtest.test'] as $host) {
            $this->assertFalse(TenantHostFormat::valid($host), $host);
        }
        $admin = $this->account('admin', ['ROLE_ADMIN']);
        // l'en-tête empoisonné : refusé à l'entrée, avant toute résolution ; aucun lien n'est créé
        foreach (['mvtest:@pirate.example', MV_TEST_TENANT_CODE . ':@pirate.example', MV_TEST_TENANT_HOST . '@pirate.example'] as $poisoned) {
            $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . '/api/password-reset/request', [], [], ['HTTP_X_TENANT_HOST' => $poisoned, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
                'REMOTE_ADDR' => '10.80.' . random_int(1, 254) . '.' . random_int(1, 254)], json_encode(['email' => $admin->getEmail()]));
            $this->assertSame(400, $this->client->getResponse()->getStatusCode(), $poisoned);
            $this->assertSame('TENANT_HOST_INVALID', json_decode((string) $this->client->getResponse()->getContent(), true)['code'] ?? null);
            $this->assertNull($this->db->fetchOne('SELECT reset_token FROM "user" WHERE id = ?', [$admin->getId()]) ?: null, "$poisoned : aucun lien créé");
            $this->assertCount(0, self::getMailerMessages());
        }
        // de même par le paramètre ?tenant= et sur toute autre route
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/api/site/storefront?tenant=' . urlencode('mvtest:@pirate.example'), [], [], ['HTTP_ACCEPT' => 'application/json']);
        $this->assertSame(400, $this->client->getResponse()->getStatusCode());
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/api/site/storefront', [], [], ['HTTP_X_TENANT_HOST' => 'mvtest:@pirate.example', 'HTTP_ACCEPT' => 'application/json']);
        $this->assertSame(400, $this->client->getResponse()->getStatusCode());
        // un en-tête de bonne forme marche comme avant : le lien part, vers un hôte du site
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . '/api/password-reset/request', [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'REMOTE_ADDR' => '10.80.' . random_int(1, 254) . '.' . random_int(1, 254)], json_encode(['email' => $admin->getEmail()]));
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $mails = self::getMailerMessages();
        $this->assertNotNull($this->db->fetchOne('SELECT reset_token FROM "user" WHERE id = ?', [$admin->getId()]));
        $this->assertCount(1, $mails);
        $this->assertStringNotContainsString('pirate', (string) $mails[0]->getHtmlBody());
        $this->assertMatchesRegularExpression('#https://' . preg_quote(MV_TEST_TENANT_HOST, '#') . '/#', (string) $mails[0]->getHtmlBody());
    }

    public function testApplyingThePlatformAccountNeverPromotesAnotherAccountOfTheSameAddressToACaseNear(): void
    {
        $service = static::getContainer()->get(SuperAdminAccountService::class);
        $owner = "elevation-proprio-{$this->tag}@example.invalid";
        // sur le site d'essai : le vrai compte de la plateforme, et un client qui a pris son adresse à une majuscule près
        $this->insert($owner, '["ROLE_ADMIN","ROLE_SUPER_ADMIN"]', 'empreinte-proprietaire');
        $this->insert(ucfirst($owner), '["ROLE_USER_INTERNET"]', 'empreinte-client');
        $results = array_column($service->apply($owner, 'Un-mot-de-passe-solide-42', 'Pro', 'Prio'), null, 'dbname');
        $this->assertSame('updated', $results[MV_TEST_TENANT_DB]['action'], (string) $results[MV_TEST_TENANT_DB]['message']);
        $client = $this->db->fetchAssociative('SELECT roles, password FROM "user" WHERE email = ?', [ucfirst($owner)]);
        $this->assertSame(['["ROLE_USER_INTERNET"]', 'empreinte-client'], [$client['roles'], $client['password']], 'le client n\'est pas devenu super administrateur');
        $this->assertNotSame('empreinte-proprietaire', $this->db->fetchOne('SELECT password FROM "user" WHERE email = ?', [$owner]), 'c\'est le vrai compte qui a été mis à jour');

        // deux lignes de même adresse à la casse près, AUCUNE ne porte le rôle : rien n'est écrit, la base est signalée
        $other = "elevation-ambigu-{$this->tag}@example.invalid";
        $this->insert($other, '["ROLE_ADMIN"]', 'empreinte-a');
        $this->insert(strtoupper($other), '["ROLE_USER_INTERNET"]', 'empreinte-b');
        $results = array_column($service->apply($other, 'Un-mot-de-passe-solide-42', 'Am', 'Bigu'), null, 'dbname');
        $this->assertSame('error', $results[MV_TEST_TENANT_DB]['action']);
        $this->assertStringContainsString('casse près', (string) $results[MV_TEST_TENANT_DB]['message']);
        $this->assertSame(0, (int) $this->db->fetchOne("SELECT COUNT(*) FROM \"user\" WHERE lower(email) = lower(?) AND roles::text LIKE '%ROLE_SUPER_ADMIN%'", [$other]));
    }

    public function testAnAgencyAccountIsChangedOnlyByThePlatformOrByItself(): void
    {
        $agency = $this->account('agence', ['ROLE_AGENCY']);
        $member = $this->account('membre', ['ROLE_ADMIN', 'ROLE_AGENCY_MEMBER']);
        $before = $this->db->fetchAssociative('SELECT email, password FROM "user" WHERE id = ?', [$agency->getId()]);
        // la console de Mémoires Vivantes, par l'administrateur du site d'attache : refusé
        $admin = static::getContainer()->get('lexik_jwt_authentication.jwt_manager')->create($this->account('admin-attache', ['ROLE_ADMIN']));
        foreach ([[$agency->getId(), ['email' => "elevation-detournee-{$this->tag}@example.invalid"]], [$member->getId(), ['password' => 'Un-autre-mot-de-passe-9']]] as [$id, $body]) {
            $this->client->request('PUT', 'https://' . MV_TEST_TENANT_HOST . "/api/memoires/admin/users/$id?locale=fr", [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $admin], json_encode($body));
            $this->assertSame(403, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
            $this->assertSame('OWNER_PROTECTED', json_decode((string) $this->client->getResponse()->getContent(), true)['code'] ?? null);
        }
        $this->client->request('DELETE', 'https://' . MV_TEST_TENANT_HOST . '/api/memoires/admin/users/' . $agency->getId(), [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $admin]);
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
        // EasyAdmin, par l'administrateur du site : la fiche d'un compte d'agence ne s'ouvre pas en modification
        $this->loginEasyAdmin($this->account('ecran', ['ROLE_ADMIN']));
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin?' . http_build_query(['crudAction' => 'edit', 'crudControllerFqcn' => UserCrudController::class, 'entityId' => $agency->getId()]), [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->assertSame($before, $this->db->fetchAssociative('SELECT email, password FROM "user" WHERE id = ?', [$agency->getId()]), 'le compte d\'agence n\'a pas bougé');
        // … mais par le super administrateur, oui ; et un compte ordinaire reste modifiable par l'administrateur
        $this->loginEasyAdmin($this->account('plateforme', ['ROLE_ADMIN', 'ROLE_SUPER_ADMIN']));
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin?' . http_build_query(['crudAction' => 'edit', 'crudControllerFqcn' => UserCrudController::class, 'entityId' => $agency->getId()]), [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $ordinary = $this->account('ordinaire', ['ROLE_USER_INTERNET']);
        $this->client->request('PUT', 'https://' . MV_TEST_TENANT_HOST . '/api/memoires/admin/users/' . $ordinary->getId() . '?locale=fr', [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $admin], json_encode(['firstName' => 'Autre']));
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
    }

    private function insert(string $email, string $roles, string $password): void
    {
        $this->db->executeStatement("INSERT INTO \"user\" (id, email, username, firstname, lastname, roles, password, is_verified, created_at) VALUES (nextval('user_id_seq'), ?, ?, 'Elevation', 'Essai', ?, ?, true, NOW())",
            [$email, $email, $roles, $password]);
    }

    private function account(string $name, array $roles): User
    {
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $em = $provider->getEntityManager();
        $email = "elevation-$name-{$this->tag}@example.invalid";
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Elevation')->setLastname($name)->setRoles($roles)->setPassword('x')->setIsVerified(true);
        $em->persist($user);
        $em->flush();

        return $user;
    }

    private function loginEasyAdmin(User $user): void
    {
        $this->client->getCookieJar()->clear();
        $this->client->loginUser($user, 'main');
        OtpTestHelper::validateIfRequired($this->client, $user);
    }

    private function pdo(string $dbname): \PDO
    {
        return static::getContainer()->get(\App\Services\Platform\TenantDatabaseConnector::class)->pdo($dbname);
    }
}
