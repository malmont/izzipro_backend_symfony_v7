<?php

namespace App\Tests\Functional\MemoiresVivantes;

use App\Entity\User;
use App\Security\RoleAssignmentPolicy;
use App\Services\TenantEntityManagerProvider;

/**
 * Écran de gestion des utilisateurs de Mémoires Vivantes : le compte du propriétaire de la plateforme
 * (ROLE_SUPER_ADMIN) ne peut être ni modifié ni supprimé par un administrateur de site, et un changement de rôle
 * fait par cet écran ne lui retire jamais ROLE_SUPER_ADMIN.
 */
class OwnerAccountProtectionTest extends BookTypeApiTestCase
{
    public function testSiteAdminCannotEditOrDeleteTheOwnerAccount(): void
    {
        $owner = $this->createUser([RoleAssignmentPolicy::SUPER_ADMIN, 'ROLE_ADMIN', 'ROLE_USER_INTERNET']);

        [$status] = $this->api('PUT', '/admin/users/' . $owner, ['role' => 'ROLE_USER']);
        $this->assertSame(403, $status, 'modification refusée');
        [$status] = $this->api('DELETE', '/admin/users/' . $owner);
        $this->assertSame(403, $status, 'suppression refusée');

        $this->assertContains(RoleAssignmentPolicy::SUPER_ADMIN, $this->rolesOf($owner));
        $this->assertContains('ROLE_ADMIN', $this->rolesOf($owner));
    }

    public function testSiteAdminStillManagesOrdinaryAccounts(): void
    {
        $user = $this->createUser(['ROLE_USER', 'ROLE_USER_INTERNET']);

        [$status] = $this->api('PUT', '/admin/users/' . $user, ['role' => 'ROLE_ADMIN']);

        $this->assertSame(200, $status);
        $this->assertContains('ROLE_ADMIN', $this->rolesOf($user));
        $this->assertNotContains(RoleAssignmentPolicy::SUPER_ADMIN, $this->rolesOf($user));
    }

    private function createUser(array $roles): int
    {
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $em = $provider->getEntityManager();
        $email = 'owner-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Test')->setLastname('Owner')
            ->setRoles($roles)->setPassword('inutilisable')->setIsVerified(true);
        $em->persist($user);
        $em->flush();

        return $user->getId();
    }

    /** @return list<string> */
    private function rolesOf(int $id): array
    {
        $statement = self::db()->prepare('SELECT roles FROM "user" WHERE id = ?');
        $statement->execute([$id]);

        return json_decode((string) $statement->fetchColumn(), true) ?? [];
    }
}
