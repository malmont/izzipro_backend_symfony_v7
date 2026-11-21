<?php

namespace App\Tests\Functional\Abo;

use App\Controller\Admin\UserCrudController;
use App\Entity\User;
use App\Security\TeamRoles;
use App\Services\TenantEntityManagerProvider;
use App\Tests\Functional\Support\OtpTestHelper;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Lot ABO-3b, vérification demandée par le chef (21/11/2026) : un compte intervenant (ROLE_STAFF_PROVIDER, avec ROLE_USER_INTERNET comme
 * tout intervenant invité) n'obtient, hors de `/api/staff/abonnements`, rien de plus qu'un compte client ordinaire : toute la gestion,
 * les écrans du site (abonnements de l'équipe compris), la plateforme, la caisse, l'Assistance et EasyAdmin lui sont refusés.
 * Et la règle « ni administrateur, ni client » de l'invitation vaut aussi dans l'écran Utilisateurs d'EasyAdmin (AboStaffEligibility).
 * Modèle : `tests/Functional/Support/SupportRoleMatrixTest.php`. Un compte neuf par appel : une route qui efface son propre compte ne
 * fausse pas la suite du balayage.
 */
class AboStaffRoleMatrixTest extends WebTestCase
{
    private const PREFIX = 'abo3b-matrice-';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->setServerParameter('HTTP_X_TENANT_HOST', MV_TEST_TENANT_HOST);
        static::getContainer()->get(TenantEntityManagerProvider::class)->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
    }

    protected function tearDown(): void
    {
        $db = $this->db();
        $db->executeStatement('DELETE FROM user_app_membership WHERE user_id IN (SELECT id FROM "user" WHERE email LIKE ?)', [self::PREFIX . '%']);
        $db->executeStatement('DELETE FROM refresh_tokens WHERE username LIKE ?', [self::PREFIX . '%']);
        $db->executeStatement("DELETE FROM content_audit_log WHERE resource = 'user_team' AND resource_id IN (SELECT id::text FROM \"user\" WHERE email LIKE ?)", [self::PREFIX . '%']);
        $db->executeStatement('DELETE FROM "user" WHERE email LIKE ?', [self::PREFIX . '%']);
        parent::tearDown();
    }

    public function testAStaffMemberGetsNothingMoreThanACustomerOutsideHisRoutes(): void
    {
        $routes = [];
        foreach (static::getContainer()->get('router')->getRouteCollection() as $name => $route) {
            if (!str_starts_with($route->getPath(), '/api/')) {
                continue;
            }
            foreach ($route->getMethods() ?: ['GET'] as $method) {
                if (in_array($method, ['HEAD', 'OPTIONS'], true)) {
                    continue;
                }
                $path = (string) preg_replace('#\{[^}]+\}#', '1', $route->getPath());
                $routes[$method . ' ' . $path] = [$method, $path, $name];
            }
        }
        $this->assertGreaterThan(500, count($routes));
        $violations = [];
        $own = 0;
        $customerSpace = [];
        foreach ($routes as [$method, $path, $name]) {
            $staff = $this->status($method, $path, $this->token([TeamRoles::STAFF_PROVIDER, 'ROLE_USER_INTERNET']));
            if (preg_match('#^/api/staff/abonnements(/|$)#', $path)) {
                if (in_array($staff, [401, 403], true)) {
                    $violations[] = "$method $path ($name) : sa route, refusée à l'intervenant ($staff)";
                }
                if ($this->status($method, $path, $this->token(['ROLE_USER_INTERNET'])) !== 403) {
                    $violations[] = "$method $path ($name) : servie à un client sans le rôle";
                }
                ++$own;
                continue;
            }
            if (in_array($staff, [401, 403, 429], true)) {
                continue;
            }
            // servie à l'intervenant : servie de même à un compte client ordinaire (son propre compte, ses commandes) ou à un visiteur
            $customer = $this->status($method, $path, $this->token(['ROLE_USER_INTERNET']));
            if ($customer !== $staff) {
                $violations[] = "$method $path ($name) : intervenant $staff, client $customer";
                continue;
            }
            if ($this->status($method, $path, null) !== $staff) {
                $customerSpace[] = "$method $path";
            }
        }
        $this->assertSame([], $violations, 'hors de /api/staff/abonnements, l\'intervenant n\'a rien de plus qu\'un client');
        $this->assertSame(6, $own, 'les 6 routes de l\'intervenant (aujourd\'hui, semaine, fait, absent, preuve, séance entière)');
        foreach ($customerSpace as $route) {
            $this->assertDoesNotMatchRegularExpression('#^\S+ /api/(gestion|site|platform|pos|support|agency|pertes|commandes|memoires/admin)(/|$)#', $route, 'jamais d\'écran d\'équipe dans l\'espace du client');
        }

        // EasyAdmin : fermé à l'intervenant connecté par la session (accueil, écran Utilisateurs, écran de son ancien rôle)
        $staff = $this->user([TeamRoles::STAFF_PROVIDER, 'ROLE_USER_INTERNET']);
        $this->client->loginUser($staff, 'main');
        foreach (['/admin', '/admin?' . http_build_query(['crudAction' => 'index', 'crudControllerFqcn' => UserCrudController::class]), '/admin/aide'] as $url) {
            $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . $url);
            $this->assertContains($this->client->getResponse()->getStatusCode(), [302, 401, 403], 'EasyAdmin fermé à l\'intervenant : ' . $url);
        }
    }

    public function testTheUsersScreenOfEasyAdminRefusesTheStaffRoleToAnAdminOrACustomer(): void
    {
        $admin = $this->user(['ROLE_ADMIN', 'ROLE_USER_INTERNET']);
        $this->client->loginUser($admin, 'main');
        OtpTestHelper::validateIfRequired($this->client, $admin);
        $otherAdmin = $this->user(['ROLE_ADMIN', 'ROLE_USER_POS']);
        $customer = $this->user(['ROLE_USER_INTERNET']);
        $this->db()->executeStatement("INSERT INTO user_app_membership (user_id, app, source, joined_at, created_at) VALUES (?, 'boutique', 'signup', NOW(), NOW())", [$customer->getId()]);
        $plain = $this->user(['ROLE_USER_POS']);

        // un administrateur : le rôle d'intervenant n'est pas enregistré, le refus est affiché, le reste de la fiche l'est
        $this->editRoles($otherAdmin, ['ROLE_ADMIN', 'ROLE_USER_POS', TeamRoles::STAFF_PROVIDER], 'Admin modifié');
        $this->assertSame([true, false], [in_array('ROLE_ADMIN', $this->rolesOf($otherAdmin), true), in_array(TeamRoles::STAFF_PROVIDER, $this->rolesOf($otherAdmin), true)]);
        $this->assertSame('Admin modifié', $this->db()->fetchOne('SELECT firstname FROM "user" WHERE id = ?', [$otherAdmin->getId()]));
        $this->client->followRedirect();
        $this->assertStringContainsString('il a déjà accès à toutes les dates', (string) $this->client->getResponse()->getContent());

        // un client (appartenance à la boutique) : refusé de même
        $this->editRoles($customer, ['ROLE_USER_INTERNET', TeamRoles::STAFF_PROVIDER]);
        $this->assertNotContains(TeamRoles::STAFF_PROVIDER, $this->rolesOf($customer));

        // un intervenant qui deviendrait administrateur perd le rôle d'intervenant, pas l'inverse
        $staff = $this->user([TeamRoles::STAFF_PROVIDER, 'ROLE_USER_POS']);
        $this->editRoles($staff, [TeamRoles::STAFF_PROVIDER, 'ROLE_USER_POS', 'ROLE_ADMIN']);
        $this->assertSame([true, false], [in_array('ROLE_ADMIN', $this->rolesOf($staff), true), in_array(TeamRoles::STAFF_PROVIDER, $this->rolesOf($staff), true)]);

        // un compte d'équipe ordinaire : le rôle est donné
        $this->editRoles($plain, ['ROLE_USER_POS', TeamRoles::STAFF_PROVIDER]);
        $this->assertContains(TeamRoles::STAFF_PROVIDER, $this->rolesOf($plain));
    }

    /** @param list<string> $roles */
    private function editRoles(User $user, array $roles, ?string $firstname = null): void
    {
        $crawler = $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin?' . http_build_query(['crudAction' => 'edit', 'crudControllerFqcn' => UserCrudController::class, 'entityId' => $user->getId()]));
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $form = $crawler->filter('form[name="edit-User-form"], form[name="User"]')->form();
        $values = $form->getPhpValues();
        $values['User']['roles'] = $roles;
        if ($firstname !== null) {
            $values['User']['firstname'] = $firstname;
        }
        $this->client->request('POST', $form->getUri(), $values);
        $this->assertTrue($this->client->getResponse()->isRedirect(), 'fiche enregistrée');
    }

    /** @return list<string> rôles en base, sans ROLE_USER, triés */
    private function rolesOf(User $user): array
    {
        $roles = array_values(array_diff((array) json_decode((string) $this->db()->fetchOne('SELECT roles FROM "user" WHERE id = ?', [$user->getId()]), true), ['ROLE_USER']));
        sort($roles);

        return $roles;
    }

    /** @param list<string> $roles */
    private function token(array $roles): string
    {
        return static::getContainer()->get('lexik_jwt_authentication.jwt_manager')->create($this->user($roles));
    }

    /** @param list<string> $roles */
    private function user(array $roles): User
    {
        $em = static::getContainer()->get(TenantEntityManagerProvider::class)->getEntityManager();
        $email = self::PREFIX . bin2hex(random_bytes(4)) . '@example.invalid';
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Matrice')->setLastname('Intervenant')->setRoles($roles)->setIsVerified(true)
            ->setPassword('$2y$13$abcdefghijklmnopqrstuuABCDEFGHIJKLMNOPQRSTUVWXYZ01234');
        $em->persist($user);
        $em->flush();

        return $user;
    }

    private function db(): Connection
    {
        return static::getContainer()->get(TenantEntityManagerProvider::class)->getEntityManager()->getConnection();
    }

    private function status(string $method, string $path, ?string $token): int
    {
        $this->client->getCookieJar()->clear();
        try {
            $this->client->request($method, 'https://' . MV_TEST_TENANT_HOST . $path, [], [], array_filter([
                'HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
                'HTTP_AUTHORIZATION' => $token ? 'Bearer ' . $token : null, 'REMOTE_ADDR' => '10.78.' . random_int(1, 254) . '.' . random_int(1, 254),
            ]), in_array($method, ['POST', 'PUT', 'PATCH'], true) ? '{}' : null);
        } catch (\Throwable) {
            return 500; // une route qui plante compte comme une 500, pour l'intervenant comme pour le client
        }

        return $this->client->getResponse()->getStatusCode();
    }
}
