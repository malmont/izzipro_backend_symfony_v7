<?php

namespace App\Tests\Functional\Security;

use App\Controller\Admin\UserCrudController;
use App\Entity\User;
use App\Security\RoleAssignmentPolicy;
use App\Services\TenantEntityManagerProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Écran EasyAdmin des utilisateurs : un administrateur de site ne peut ni s'attribuer ni retirer ROLE_SUPER_ADMIN
 * (propriétaire de la plateforme, qui pilote la configuration commune à tous les sites). Jusqu'au 30/09/2026, les
 * rôles étaient un champ libre.
 */
class UserRolesEasyAdminTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testSiteAdminDoesNotSeeTheSuperAdminRole(): void
    {
        $admin = $this->loginAs(['ROLE_ADMIN']);

        $crawler = $this->admin('edit', $admin->getId());

        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $values = $crawler->filter('input[name="User[roles][]"]')->each(fn ($input) => $input->attr('value'));
        $this->assertContains('ROLE_ADMIN', $values);
        $this->assertNotContains(RoleAssignmentPolicy::SUPER_ADMIN, $values);
    }

    public function testSiteAdminCannotGrantThemselvesSuperAdminEvenWithAForgedForm(): void
    {
        $admin = $this->loginAs(['ROLE_ADMIN']);
        $crawler = $this->admin('edit', $admin->getId());
        $form = $crawler->filter('form[name="edit-User-form"], form[name="User"]')->form();
        $form->disableValidation();
        $values = $form->getPhpValues();
        $values['User']['roles'] = ['ROLE_ADMIN', RoleAssignmentPolicy::SUPER_ADMIN];

        $this->client->request('POST', $form->getUri(), $values);

        $this->assertNotContains(RoleAssignmentPolicy::SUPER_ADMIN, $this->rolesOf($admin->getId()));
    }

    public function testSiteAdminCannotRemoveSuperAdminFromAnotherUser(): void
    {
        $this->loginAs(['ROLE_ADMIN']);
        $owner = $this->createUser([RoleAssignmentPolicy::SUPER_ADMIN, 'ROLE_ADMIN', 'ROLE_USER_INTERNET']);
        $crawler = $this->admin('edit', $owner->getId());
        $form = $crawler->filter('form[name="edit-User-form"], form[name="User"]')->form();
        $values = $form->getPhpValues();
        $values['User']['roles'] = ['ROLE_USER_INTERNET'];

        $this->client->request('POST', $form->getUri(), $values);

        $roles = $this->rolesOf($owner->getId());
        $this->assertContains(RoleAssignmentPolicy::SUPER_ADMIN, $roles, 'rôle conservé');
        $this->assertNotContains('ROLE_ADMIN', $roles, 'les autres rôles suivent le formulaire');
    }

    public function testSuperAdminCanGrantTheRole(): void
    {
        $this->loginAs([RoleAssignmentPolicy::SUPER_ADMIN, 'ROLE_ADMIN']);
        $target = $this->createUser(['ROLE_USER_INTERNET']);
        $crawler = $this->admin('edit', $target->getId());
        $values = $crawler->filter('form[name="edit-User-form"], form[name="User"]')->form()->getPhpValues();
        $values['User']['roles'] = ['ROLE_USER_INTERNET', RoleAssignmentPolicy::SUPER_ADMIN];

        $this->client->request('POST', $crawler->filter('form[name="edit-User-form"], form[name="User"]')->form()->getUri(), $values);

        $this->assertContains(RoleAssignmentPolicy::SUPER_ADMIN, $this->rolesOf($target->getId()));
    }

    public function testPolicyKeepsUnknownRolesAndDropsForgedOnes(): void
    {
        $policy = static::getContainer()->get(RoleAssignmentPolicy::class);

        $this->assertSame(['ROLE_ADMIN', 'ROLE_ANCIEN'], $policy->apply(['ROLE_ADMIN', 'ROLE_INVENTE'], ['ROLE_ANCIEN'], false));
        $this->assertSame(['ROLE_ADMIN'], $policy->apply(['ROLE_ADMIN', RoleAssignmentPolicy::SUPER_ADMIN], [], false));
        $this->assertSame(['ROLE_ADMIN', RoleAssignmentPolicy::SUPER_ADMIN], $policy->apply(['ROLE_ADMIN', RoleAssignmentPolicy::SUPER_ADMIN], [], true));
    }

    private function admin(string $action, ?int $entityId = null): \Symfony\Component\DomCrawler\Crawler
    {
        return $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin?' . http_build_query(array_filter([
            'crudAction' => $action,
            'crudControllerFqcn' => UserCrudController::class,
            'entityId' => $entityId,
        ])), [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
    }

    private function loginAs(array $roles): User
    {
        $user = $this->createUser($roles);
        $this->client->loginUser($user, 'main');

        return $user;
    }

    private function createUser(array $roles): User
    {
        $email = 'roles-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Test')->setLastname('Roles')->setRoles($roles)->setIsVerified(true)->setPassword('x');
        $em = $this->em();
        $em->persist($user);
        $em->flush();

        return $user;
    }

    /** @return list<string> rôles enregistrés en base */
    private function rolesOf(int $id): array
    {
        $raw = $this->em()->getConnection()->fetchOne('SELECT roles FROM "user" WHERE id = ?', [$id]);

        return json_decode((string) $raw, true) ?? [];
    }

    private function em()
    {
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);

        return $provider->getEntityManager();
    }
}
