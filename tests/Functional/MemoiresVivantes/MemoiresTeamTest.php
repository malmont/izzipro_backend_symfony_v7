<?php

namespace App\Tests\Functional\MemoiresVivantes;

use App\Command\MemoiresTeamGrantCommand;
use App\Entity\User;
use App\MemoiresVivantes\Entity\Book;
use App\Security\TeamRoles;
use App\Services\TenantEntityManagerProvider;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Équipe de Mémoires Vivantes, lot MV-BIOGRAPHE (13/11/2026) : rôle ROLE_MEMOIRES_BIOGRAPHE posé sur un compte de la base commune,
 * attribution et retrait journalisés (content_audit_log, user_team), population « team » réservée à ce rôle, « admin » pour un
 * administrateur sans lui, filtre serveur ?population=, liste légère GET /team, livres de l'entreprise ouverts au biographe,
 * écrans d'administration toujours réservés à ROLE_ADMIN. Contrat : docs/memoires-vivantes.md, « Équipe ».
 */
class MemoiresTeamTest extends BookTypeApiTestCase
{
    public function testAnAdminGrantsAndRevokesTheBiographerRoleWithAJournal(): void
    {
        $member = $this->user(['ROLE_USER_INTERNET'], 'futur-biographe');
        $admin = $this->existing(self::ADMIN_EMAIL);

        [$status, $body] = $this->call('PUT', '/api/memoires/admin/users/' . $member->getId() . '/team', ['biographer' => true], $admin);
        $this->assertSame(200, $status, json_encode($body));
        $this->assertTrue($body['isBiographer']);
        $this->assertTrue($body['changed']);
        $this->assertSame([TeamRoles::MEMOIRES_BIOGRAPHE], $body['teamRoles']);
        $this->assertContains('ROLE_USER_INTERNET', $body['roles'], 'le rôle compagnon d\'accès à l\'API est garanti');

        [$status, $body] = $this->call('PUT', '/api/memoires/admin/users/' . $member->getId() . '/team', ['biographer' => true], $admin);
        $this->assertSame(200, $status);
        $this->assertFalse($body['changed'], 'idempotent');
        $journal = $this->journal($member);
        $this->assertCount(1, $journal, 'rien n\'est journalisé quand rien ne change');
        $this->assertSame('grant', $journal[0]['action']);
        $this->assertSame(self::ADMIN_EMAIL, $journal[0]['user_identifier']);
        $this->assertStringContainsString(TeamRoles::MEMOIRES_BIOGRAPHE, $journal[0]['fields']);
        $this->assertStringContainsString(TeamRoles::MEMOIRES_BIOGRAPHE, (string) $journal[0]['after_state']);
        $this->assertStringNotContainsString(TeamRoles::MEMOIRES_BIOGRAPHE, (string) $journal[0]['before_state']);

        // Population team : les biographes, et eux seuls ; admin : l'administrateur du site sans ce rôle
        [$status, $team, $headers] = $this->callWithHeaders('GET', '/api/memoires/admin/users?population=team', null, $admin);
        $this->assertSame(200, $status);
        $this->assertSame(count($team), (int) $headers['x-total-count']);
        $this->assertContains($member->getId(), array_column($team, 'id'));
        foreach ($team as $row) {
            $this->assertTrue($row['isBiographer'], $row['email']);
            $this->assertSame('team', $row['population']);
        }
        [$status, $admins] = $this->call('GET', '/api/memoires/admin/users?population=admin', null, $admin);
        $this->assertSame(200, $status);
        $this->assertContains($admin->getId(), array_column($admins, 'id'));
        $this->assertNotContains($member->getId(), array_column($admins, 'id'));
        foreach ($admins as $row) {
            $this->assertSame('admin', $row['population']);
            $this->assertFalse($row['isBiographer']);
        }
        [$status, $body] = $this->call('GET', '/api/memoires/admin/users?population=inconnue', null, $admin);
        $this->assertSame(400, $status);
        $this->assertSame('POPULATION_INVALID', $body['code']);

        // Le changement admin / utilisateur de l'écran existant ne fait pas perdre le rôle d'équipe
        [$status] = $this->call('PUT', '/api/memoires/admin/users/' . $member->getId(), ['role' => 'ROLE_USER'], $admin);
        $this->assertSame(200, $status);
        [, $team] = $this->call('GET', '/api/memoires/admin/users?population=team', null, $admin);
        $this->assertContains($member->getId(), array_column($team, 'id'), 'le rôle d\'équipe survit à l\'écran des rôles');

        // Erreurs
        [$status, $body] = $this->call('PUT', '/api/memoires/admin/users/999999/team', ['biographer' => true], $admin);
        $this->assertSame(404, $status);
        $this->assertSame('USER_NOT_FOUND', $body['code']);
        [$status, $body] = $this->call('PUT', '/api/memoires/admin/users/' . $member->getId() . '/team', ['biographer' => 'oui'], $admin);
        $this->assertSame(400, $status);
        $this->assertSame('INVALID_BODY', $body['code']);
        $super = $this->user(['ROLE_ADMIN', 'ROLE_SUPER_ADMIN'], 'proprietaire');
        [$status, $body] = $this->call('PUT', '/api/memoires/admin/users/' . $super->getId() . '/team', ['biographer' => true], $admin);
        $this->assertSame(403, $status);
        $this->assertSame('OWNER_PROTECTED', $body['code']);

        // Retrait
        [$status, $body] = $this->call('PUT', '/api/memoires/admin/users/' . $member->getId() . '/team', ['biographer' => false], $admin);
        $this->assertSame(200, $status);
        $this->assertFalse($body['isBiographer']);
        $this->assertSame([], $body['teamRoles']);
        $journal = $this->journal($member);
        $this->assertCount(2, $journal);
        $this->assertSame('revoke', $journal[0]['action']);
        [, $team] = $this->call('GET', '/api/memoires/admin/users?population=team', null, $admin);
        $this->assertNotContains($member->getId(), array_column($team, 'id'));
    }

    public function testABiographerWorksOnTheCompanyBooksAndTheTeamButNotOnTheAdminScreens(): void
    {
        $biographer = $this->user([TeamRoles::MEMOIRES_BIOGRAPHE, 'ROLE_USER_INTERNET'], 'biographe');
        $client = $this->user(['ROLE_USER_INTERNET'], 'client-suivi');
        $other = $this->user(['ROLE_USER_INTERNET'], 'autre-client');
        $em = static::getContainer()->get(TenantEntityManagerProvider::class)->getEntityManager();
        $owner = $em->getRepository(User::class)->find($client->getId()); // relu : l'entité vient d'un autre gestionnaire
        $book = (new Book())->setTitle('Livre du client suivi')->setType('individuel')->setPerson1FirstName('Lina')->setUser($owner);
        $em->persist($book);
        $em->flush();
        $bookId = (string) $book->getId();

        // Livres de l'entreprise
        [$status, $books] = $this->call('GET', '/api/memoires/books', null, $biographer);
        $this->assertSame(200, $status);
        $this->assertContains($bookId, array_column($books, 'id'), 'le biographe voit les livres de tous les clients');
        [$status, $books] = $this->call('GET', '/api/memoires/books', null, $other);
        $this->assertSame(200, $status);
        $this->assertNotContains($bookId, array_column($books, 'id'), 'un client ne voit que les siens');
        [$status] = $this->call('GET', '/api/memoires/books/' . $bookId, null, $biographer);
        $this->assertSame(200, $status, 'BookVoter : le biographe accède au livre d\'un client');
        [$status] = $this->call('GET', '/api/memoires/books/' . $bookId, null, $other);
        $this->assertContains($status, [401, 403], 'un autre client est refusé (règle existante du contrôleur)');

        // Liste légère de l'équipe
        [$status, $team] = $this->call('GET', '/api/memoires/team', null, $biographer);
        $this->assertSame(200, $status, json_encode($team));
        $me = array_values(array_filter($team, fn ($m) => $m['id'] === $biographer->getId()));
        $this->assertCount(1, $me);
        $this->assertSame(['id', 'fullName', 'email', 'teamRoles'], array_keys($me[0]));
        $this->assertSame('Test Biographe', $me[0]['fullName']);
        $this->assertNotContains($client->getId(), array_column($team, 'id'));
        [$status] = $this->call('GET', '/api/memoires/team', null, $client);
        $this->assertSame(403, $status, 'un client ne voit pas l\'équipe');
        [$status] = $this->call('GET', '/api/memoires/team', null, null);
        $this->assertSame(401, $status);
        [$status] = $this->call('GET', '/api/memoires/team', null, $this->existing(self::ADMIN_EMAIL));
        $this->assertSame(200, $status, 'l\'administrateur du site aussi');

        // Console Équipe : liste et invitation d'un client, rien d'autre
        [$status, $users, $headers] = $this->callWithHeaders('GET', '/api/memoires/admin/users?population=web_client', null, $biographer);
        $this->assertSame(200, $status);
        $this->assertNotSame([], $users);
        $this->assertGreaterThanOrEqual(count($users), (int) $headers['x-total-count']);
        $this->assertArrayHasKey('isBiographer', $users[0]);
        [$status, $body] = $this->call('POST', '/api/memoires/admin/users', ['email' => 'invite-' . bin2hex(random_bytes(3)) . '@example.invalid', 'firstName' => 'Invité', 'lastName' => 'Test', 'role' => 'ROLE_ADMIN'], $biographer);
        $this->assertSame(403, $status);
        $this->assertSame('FORBIDDEN', $body['code']);
        [$status, $body] = $this->call('POST', '/api/memoires/admin/users', ['email' => 'invite-' . bin2hex(random_bytes(3)) . '@example.invalid', 'firstName' => 'Invité', 'lastName' => 'Test', 'password' => 'Mot-de-passe-1!'], $biographer);
        $this->assertSame(201, $status, json_encode($body));
        $this->assertSame('ROLE_USER', $body['user']['role']);
        [$status] = $this->call('PUT', '/api/memoires/admin/users/' . $client->getId() . '/team', ['biographer' => true], $biographer);
        $this->assertSame(403, $status, 'un biographe n\'attribue pas le rôle');
        [$status] = $this->call('PUT', '/api/memoires/admin/users/' . $client->getId(), ['firstName' => 'X'], $biographer);
        $this->assertSame(403, $status);
        [$status] = $this->call('DELETE', '/api/memoires/admin/users/' . $other->getId(), null, $biographer);
        $this->assertSame(403, $status);
        foreach (['/api/memoires/admin/pricing', '/api/memoires/admin/payments', '/api/memoires/admin/revenue', '/api/memoires/admin/book-types', '/api/memoires/admin/ai-quotas', '/api/memoires/admin/mobile-users'] as $path) {
            [$status] = $this->call('GET', $path, null, $biographer);
            $this->assertSame(403, $status, $path . ' reste à ROLE_ADMIN');
        }
    }

    public function testTheConsoleCommandGrantsTheRoleOnASiteWithAJournalMark(): void
    {
        $member = $this->user(['ROLE_USER_INTERNET'], 'par-console');
        $tester = new CommandTester(static::getContainer()->get(MemoiresTeamGrantCommand::class));
        $this->assertSame(0, $tester->execute(['tenant' => MV_TEST_TENANT_CODE, 'email' => $member->getEmail()]), $tester->getDisplay());
        $this->assertStringContainsString('biographe ajouté', $tester->getDisplay());
        $journal = $this->journal($member);
        $this->assertCount(1, $journal);
        $this->assertSame('grant', $journal[0]['action']);
        $this->assertStringContainsString('console', $journal[0]['fields']);
        $roles = json_decode((string) self::db()->query('SELECT roles FROM "user" WHERE id = ' . (int) $member->getId())->fetchColumn(), true);
        $this->assertContains(TeamRoles::MEMOIRES_BIOGRAPHE, $roles);
        $this->assertContains('ROLE_USER_INTERNET', $roles);

        $this->assertSame(0, $tester->execute(['tenant' => MV_TEST_TENANT_CODE, 'email' => $member->getEmail(), '--revoke' => true]));
        $this->assertStringContainsString('biographe retiré', $tester->getDisplay());
        $this->assertSame(1, $tester->execute(['tenant' => 'site-inexistant', 'email' => $member->getEmail()]));
        $this->assertSame(1, $tester->execute(['tenant' => MV_TEST_TENANT_CODE, 'email' => 'personne@example.invalid']));
    }

    /** @return list<array<string, mixed>> entrées user_team du compte, la plus récente d'abord */
    private function journal(User $user): array
    {
        $st = self::db()->prepare('SELECT action, fields, user_identifier, before_state, after_state FROM content_audit_log WHERE resource = ? AND resource_id = ? ORDER BY id DESC');
        $st->execute([TeamRoles::AUDIT_RESOURCE, (string) $user->getId()]);

        return $st->fetchAll(\PDO::FETCH_ASSOC);
    }

    private function user(array $roles, string $prefix): User
    {
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $email = $prefix . '-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Test')->setLastname(ucfirst(explode('-', $prefix)[0]))->setRoles($roles)->setIsVerified(true)->setPassword('inutilisable');
        $provider->getEntityManager()->persist($user);
        $provider->getEntityManager()->flush();

        return $user;
    }

    private function existing(string $email): User
    {
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $user = $provider->getEntityManager()->getRepository(User::class)->findOneBy(['email' => $email]);
        $this->assertNotNull($user, $email);

        return $user;
    }

    /** @return array{0: int, 1: mixed} */
    private function call(string $method, string $path, ?array $body, ?User $as): array
    {
        [$status, $json] = $this->callWithHeaders($method, $path, $body, $as);

        return [$status, $json];
    }

    /** @return array{0: int, 1: mixed, 2: array<string, string>} */
    private function callWithHeaders(string $method, string $path, ?array $body, ?User $as): array
    {
        $server = ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'];
        if ($method !== 'GET') {
            $server['HTTP_X_XSRF_TOKEN'] = 'jeton-xsrf-de-test';
        }
        if ($as !== null) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer ' . static::getContainer()->get('lexik_jwt_authentication.jwt_manager')->create($as);
        }
        $this->client->getCookieJar()->clear();
        $this->client->request($method, $path . (str_contains($path, '?') ? '&' : '?') . 'locale=fr', [], [], $server, $body !== null ? json_encode($body) : null);
        $response = $this->client->getResponse();
        $headers = [];
        foreach ($response->headers->all() as $name => $values) {
            $headers[strtolower($name)] = implode(',', $values);
        }

        return [$response->getStatusCode(), json_decode((string) $response->getContent(), true), $headers];
    }
}
