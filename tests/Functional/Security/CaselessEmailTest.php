<?php

namespace App\Tests\Functional\Security;

use App\Entity\User;
use App\Services\TenantEntityManagerProvider;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Lot SÉCURITÉ-2 (16/11/2026) : UNE ADRESSE, UN COMPTE, À LA CASSE PRÈS. Un index unique sur l'adresse en minuscules l'impose à la
 * base ; la connexion, le « mot de passe oublié », les invitations et les contrôles d'unicité retrouvent le compte quelle que soit la
 * casse saisie. Rien n'est réécrit dans les adresses gardées : on compare seulement en minuscules.
 */
class CaselessEmailTest extends WebTestCase
{
    private const PASSWORD = 'Un-mot-de-passe-solide-42';

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
        $this->db->executeStatement("DELETE FROM refresh_tokens WHERE lower(username) LIKE 'casse-%@example.invalid'");
        $this->db->executeStatement("DELETE FROM \"user\" WHERE lower(email) LIKE 'casse-%@example.invalid'");
        parent::tearDown();
    }

    public function testTheLoginTheForgottenPasswordAndTheInvitationsFindTheAccountWhateverTheCase(): void
    {
        $stored = "casse-client-{$this->tag}@example.invalid";
        $id = $this->account($stored, ['ROLE_USER_INTERNET']);
        $typed = 'Casse-Client-' . strtoupper($this->tag) . '@EXAMPLE.invalid';

        // LA BASE refuse un second compte de même adresse à la casse près
        try {
            $this->db->executeStatement("INSERT INTO \"user\" (id, email, username, firstname, lastname, roles, password, is_verified, created_at) VALUES (nextval('user_id_seq'), ?, ?, 'X', 'Y', '[]', 'x', true, NOW())", [$typed, $typed]);
            $this->fail('un second compte de même adresse à la casse près a été accepté');
        } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException) {
            $this->addToAssertionCount(1);
        }

        // LA CONNEXION : la casse saisie ne compte pas
        foreach ([$stored, $typed, '  ' . $typed . ' '] as $username) {
            $this->post('/api/login', ['username' => $username, 'password' => self::PASSWORD, 'platform' => 'mobile']);
            $this->assertSame(200, $this->client->getResponse()->getStatusCode(), "« $username » : " . substr((string) $this->client->getResponse()->getContent(), 0, 200));
        }
        $this->post('/api/login', ['username' => $typed, 'password' => 'mauvais-mot-de-passe', 'platform' => 'mobile']);
        $this->assertSame(401, $this->client->getResponse()->getStatusCode(), 'le mot de passe compte, lui');

        // LE MOT DE PASSE OUBLIÉ : le lien est créé pour le compte, et part à l'adresse GARDÉE (jamais réécrite)
        $this->post('/api/password-reset/request', ['email' => $typed]);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $mails = self::getMailerMessages();
        $this->assertNotNull($this->db->fetchOne('SELECT reset_token FROM "user" WHERE id = ?', [$id]), 'le lien est créé');
        $this->assertSame([$stored], array_map(static fn ($a) => $a->getAddress(), $mails[0]->getTo()));
        $this->assertSame($stored, $this->db->fetchOne('SELECT email FROM "user" WHERE id = ?', [$id]), 'l\'adresse gardée n\'est pas réécrite');

        // L'INVITATION par la console : une adresse déjà prise à la casse près est refusée, sans créer de second compte
        $admin = static::getContainer()->get('lexik_jwt_authentication.jwt_manager')->create(static::getContainer()->get(TenantEntityManagerProvider::class)->getEntityManager()->find(User::class, $this->account("casse-admin-{$this->tag}@example.invalid", ['ROLE_ADMIN'])));
        $this->post('/api/memoires/admin/users', ['email' => $typed, 'firstName' => 'Autre', 'lastName' => 'Personne'], $admin);
        $this->assertSame(409, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
        // … de même le changement d'adresse d'un autre compte vers celle-ci, à la casse près
        $other = $this->account("casse-autre-{$this->tag}@example.invalid", ['ROLE_USER_INTERNET']);
        $this->client->request('PUT', 'https://' . MV_TEST_TENANT_HOST . "/api/memoires/admin/users/$other?locale=fr", [], [], $this->server($admin), json_encode(['email' => $typed]));
        $this->assertSame(409, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
        // … et l'invitation d'un administrateur de site par une agence ou la plateforme : le compte existant est reconnu
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $invite = static::getContainer()->get(\App\Services\Agency\AgencySiteInviter::class)->invite($typed, 'Autre', 'Personne', 'fr', null);
        $this->assertSame(['sent' => false, 'reason' => 'ACCOUNT_EXISTS'], array_intersect_key($invite, ['sent' => 1, 'reason' => 1]));
        $this->assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM "user" WHERE lower(email) = lower(?)', [$stored]), 'un seul compte pour cette adresse');

        // la recherche du dépôt : la casse et les espaces de bord ne comptent pas ; une adresse vide ne trouve rien
        $repository = $provider->getEntityManager()->getRepository(User::class);
        $this->assertSame($id, $repository->findOneByEmailCaseless(' ' . strtoupper($stored) . ' ')?->getId());
        $this->assertNull($repository->findOneByEmailCaseless(''));
        $this->assertNull($repository->findOneByEmailCaseless(null));
    }

    private function post(string $path, array $body, ?string $bearer = null): void
    {
        $this->client->getCookieJar()->clear();
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . $path, [], [], $this->server($bearer), json_encode($body));
    }

    /** @return array<string, string> */
    private function server(?string $bearer): array
    {
        return ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'REMOTE_ADDR' => '10.79.' . random_int(1, 254) . '.' . random_int(1, 254)] + ($bearer !== null ? ['HTTP_AUTHORIZATION' => 'Bearer ' . $bearer] : []);
    }

    private function account(string $email, array $roles): int
    {
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $em = $provider->getEntityManager();
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Casse')->setLastname('Essai')->setRoles($roles)->setIsVerified(true);
        $user->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD));
        $em->persist($user);
        $em->flush();

        return (int) $user->getId();
    }
}
