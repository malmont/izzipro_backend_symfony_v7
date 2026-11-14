<?php

namespace App\Tests\Functional\MemoiresVivantes;

use App\Entity\User;
use App\MemoiresVivantes\Entity\Book;
use App\Security\TeamRoles;
use App\Services\TenantEntityManagerProvider;

/**
 * Non-régression de l'administrateur du site dans Mémoires Vivantes (14/11/2026), écrite AVANT le nouveau modèle des rôles de
 * l'équipe (responsable des biographes, biographe limité à ses livres) et jouée avant et après : un administrateur SANS rôle d'équipe
 * garde tout. Tous les livres du site avec chaque filtre, le livre d'un autre (lecture, modification, chapitres, liens signés),
 * l'affectation d'un biographe, tous les rendez-vous et leur confirmation, l'équipe, la console entière, l'IA sans quota.
 */
class MemoiresAdminNonRegressionTest extends BookTypeApiTestCase
{
    public function testAnAdministratorWithoutTeamRoleKeepsEverythingInMemoiresVivantes(): void
    {
        $admin = $this->user(['ROLE_ADMIN'], 'Admin', 'SansRôle');
        $biographer = $this->user([TeamRoles::MEMOIRES_BIOGRAPHE, 'ROLE_USER_INTERNET'], 'Bio', 'Graphe');
        $client = $this->user(['ROLE_USER_INTERNET'], 'Client', 'Suivi');
        $marker = 'non-régression ' . bin2hex(random_bytes(3));
        $ofClient = $this->book($client, "Livre du client $marker");
        $ofBiographer = $this->book($biographer, "Livre du biographe $marker");

        // Il crée un livre web, sans quota d'IA ; sur un livre de l'application non plus
        [$status, $own] = $this->call('POST', '/api/memoires/books', ['title' => "Livre de l'administrateur $marker", 'type' => 'individuel'], $admin);
        $this->assertSame([201, false, 'web'], [$status, $own['aiQuotaLimited'], $own['channel']], json_encode($own));
        [$status, $mobile] = $this->call('POST', '/api/memoires/mobile/books', ['title' => "Livre mobile $marker", 'type' => 'famille'], $admin);
        $this->assertSame([201, false, 'mobile'], [$status, $mobile['aiQuotaLimited'], $mobile['channel']], json_encode($mobile));

        // Tous les livres du site, et chaque filtre
        $ids = fn (string $query): array => array_column($this->ok('GET', '/api/memoires/books' . $query, null, $admin), 'id');
        $all = $ids('');
        foreach ([$own['id'], $ofClient, $ofBiographer] as $id) {
            $this->assertContains($id, $all, 'par défaut : tous les livres du site');
        }
        $this->assertSame([$ofClient], array_values(array_intersect($ids('?scope=all&userId=' . $client->getId()), [$own['id'], $ofClient, $ofBiographer])), 'filtre par propriétaire');
        $mine = $ids('?scope=my');
        $this->assertContains($own['id'], $mine);
        $this->assertNotContains($ofClient, $mine, 'scope=my : les siens seulement');
        $this->assertContains($ofClient, $ids('?scope=all&biographerId=none'), 'filtre « sans biographe »');

        // Le livre d'un autre : lecture, modification, affectation d'un biographe, filtre par biographe
        $this->assertSame($ofClient, $this->ok('GET', "/api/memoires/books/$ofClient", null, $admin)['id']);
        $this->assertSame('Titre revu par l\'administrateur', $this->ok('PUT', "/api/memoires/books/$ofClient", ['title' => 'Titre revu par l\'administrateur'], $admin)['title']);
        $this->assertSame($biographer->getId(), $this->ok('PUT', "/api/memoires/books/$ofClient", ['biographerId' => $biographer->getId()], $admin)['biographer']['id']);
        $this->assertSame([$ofClient], array_values(array_intersect($ids('?scope=all&biographerId=' . $biographer->getId()), [$own['id'], $ofClient, $ofBiographer])));
        $this->assertNull($this->ok('PUT', "/api/memoires/books/$ofClient", ['biographerId' => null], $admin)['biographer']);

        // Les chapitres du livre d'un autre : créer, lire, modifier, lien signé
        [$status, $chapter] = $this->call('POST', "/api/memoires/books/$ofBiographer/chapters", ['title' => 'Chapitre de l\'administrateur', 'theme' => 'regards_croises', 'position' => 1], $admin);
        $this->assertSame(201, $status, json_encode($chapter));
        $this->assertSame($chapter['id'], $this->ok('GET', '/api/memoires/chapters/' . $chapter['id'], null, $admin)['id']);
        $this->assertSame('Chapitre revu', $this->ok('PUT', '/api/memoires/chapters/' . $chapter['id'], ['title' => 'Chapitre revu'], $admin)['title']);
        $this->assertArrayHasKey('url', $this->ok('GET', '/api/memoires/chapters/' . $chapter['id'] . '/share-link', null, $admin));
        $this->ok('GET', "/api/memoires/books/$ofBiographer/reservations", null, $admin);

        // Tous les rendez-vous ; il confirme celui d'un autre biographe
        $date = sprintf('2029-%02d-%02d', random_int(1, 12), random_int(1, 28));
        [$status, $reservation] = $this->call('POST', '/api/reservations', ['service_name' => 'Séance', 'reservation_date' => $date, 'reservation_slot' => '09:00', 'client_name' => 'Cliente', 'client_email' => 'cliente-nr@example.invalid',
            'client_phone' => '0600000000', 'biographer_id' => $biographer->getId()], $admin);
        $this->assertSame(201, $status, json_encode($reservation));
        $this->ok('GET', '/api/reservations', null, $admin);
        $this->ok('GET', '/api/reservations?biographer_id=' . $biographer->getId(), null, $admin);
        [$status, $confirmed] = $this->call('POST', '/api/reservations/' . $reservation['data']['id'] . '/confirm', [], $admin);
        $this->assertSame(200, $status, json_encode($confirmed));

        // L'équipe et la console entière
        $this->assertContains($biographer->getId(), array_column($this->ok('GET', '/api/memoires/team', null, $admin), 'id'));
        foreach (['/users', '/pricing', '/ai-quotas', '/revenue', '/payments', '/mobile-users', '/book-types'] as $screen) {
            $this->ok('GET', '/api/memoires/admin' . $screen, null, $admin);
        }
        $this->assertTrue($this->ok('PUT', '/api/memoires/admin/users/' . $client->getId() . '/team', ['biographer' => true], $admin)['isBiographer']);
        $this->assertFalse($this->ok('PUT', '/api/memoires/admin/users/' . $client->getId() . '/team', ['biographer' => false], $admin)['isBiographer']);
        [$status, $created] = $this->call('POST', '/api/memoires/admin/users', ['email' => 'admin-nr-' . bin2hex(random_bytes(3)) . '@example.invalid', 'firstName' => 'Nouvel', 'lastName' => 'Administrateur', 'role' => 'ROLE_ADMIN'], $admin);
        $this->assertContains($status, [200, 201], json_encode($created));
        $this->assertSame('ROLE_ADMIN', $created['user']['role']);
    }

    // ---------------------------------------------------------------- aides

    /** @return mixed corps d'une réponse 200 */
    private function ok(string $method, string $path, ?array $body, User $as): mixed
    {
        [$status, $decoded] = $this->call($method, $path, $body, $as);
        $this->assertSame(200, $status, $method . ' ' . $path . ' : ' . json_encode($decoded));

        return $decoded;
    }

    private function user(array $roles, string $firstName, string $lastName): User
    {
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $email = 'nr-' . strtolower($firstName) . '-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname($firstName)->setLastname($lastName)->setRoles($roles)->setIsVerified(true)->setPassword('inutilisable');
        $provider->getEntityManager()->persist($user);
        $provider->getEntityManager()->flush();

        return $user;
    }

    /** Livre web appartenant à ce compte, posé en base (comme un livre d'avant) ; rend son identifiant */
    private function book(User $owner, string $title): string
    {
        $em = static::getContainer()->get(TenantEntityManagerProvider::class)->getEntityManager();
        $book = (new Book())->setTitle($title)->setType('individuel')->setPerson1FirstName('Lina')->setUser($em->getRepository(User::class)->find($owner->getId()));
        $em->persist($book);
        $em->flush();

        return (string) $book->getId();
    }

    /** @return array{0: int, 1: mixed} appel avec le jeton d'un compte créé par le test */
    private function call(string $method, string $path, ?array $body, User $as): array
    {
        $server = ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . static::getContainer()->get('lexik_jwt_authentication.jwt_manager')->create($as)];
        if ($method !== 'GET') {
            $server['HTTP_X_XSRF_TOKEN'] = 'jeton-xsrf-de-test';
        }
        $this->client->getCookieJar()->clear();
        $this->client->request($method, $path . (str_contains($path, '?') ? '&' : '?') . 'locale=fr', [], [], $server, $body !== null ? json_encode($body) : null);
        $response = $this->client->getResponse();

        return [$response->getStatusCode(), json_decode((string) $response->getContent(), true)];
    }
}
