<?php

namespace App\Tests\Functional\MemoiresVivantes;

use App\Entity\User;
use App\MemoiresVivantes\Services\BookBiographerService;
use App\Security\TeamRoles;
use App\Services\TenantEntityManagerProvider;

/**
 * Biographe affecté au livre, lot MV-AFFECTATION (13/11/2026) : biographerId posé par l'administrateur ou le responsable des biographes
 * (14/11/2026 : plus par un biographe), ou automatiquement pour le biographe qui crée un livre ; cible = porteur de ROLE_MEMOIRES_BIOGRAPHE (400 BIOGRAPHER_INVALID sinon) ; le propriétaire ne change pas la
 * valeur (403 FORBIDDEN, rien d'appliqué) mais peut la renvoyer telle quelle ; rendu `biographer {id, fullName}` dans les lectures et la
 * vue des revenus ; filtre ?biographerId=<id>|none ; journal book_biographer ; le rendez-vous créé depuis un livre prend son biographe
 * affecté (plus le propriétaire). Contrat : docs/memoires-vivantes.md, « Équipe ».
 */
class MemoiresBookBiographerTest extends BookTypeApiTestCase
{
    public function testTheTeamAssignsABiographerToABookAndTheOwnerCannot(): void
    {
        $biographer = $this->user([TeamRoles::MEMOIRES_BIOGRAPHE, 'ROLE_USER_INTERNET'], 'Biographe', 'Affecté');
        $adminWithoutRole = $this->user(['ROLE_ADMIN'], 'Admin', 'SansRôle');
        [$status, $book] = $this->api('POST', '/books', ['title' => 'Livre à affecter', 'type' => 'individuel'], 'client');
        $this->assertSame(201, $status, json_encode($book));
        $this->assertNull($book['biographer'], 'un livre naît sans biographe');
        $id = $book['id'];

        // Affectation par l'administrateur
        [$status, $updated] = $this->api('PUT', "/books/$id", ['biographerId' => $biographer->getId()], 'admin');
        $this->assertSame(200, $status, json_encode($updated));
        $this->assertSame(['id' => $biographer->getId(), 'fullName' => 'Biographe Affecté'], $updated['biographer']);
        [, $read] = $this->api('GET', "/books/$id", null, 'client');
        $this->assertSame(['id' => $biographer->getId(), 'fullName' => 'Biographe Affecté'], $read['biographer'], 'rendu au propriétaire aussi');

        // Cibles refusées
        foreach ([$adminWithoutRole->getId(), 999999, 'abc'] as $bad) {
            [$status, $body] = $this->api('PUT', "/books/$id", ['biographerId' => $bad], 'admin');
            $this->assertSame(400, $status, json_encode($body));
            $this->assertSame('BIOGRAPHER_INVALID', $body['code']);
        }

        // Le propriétaire : même valeur ignorée et reste appliqué ; valeur différente refusée, rien d'appliqué
        [$status, $same] = $this->api('PUT', "/books/$id", ['title' => 'Titre changé par le client', 'biographerId' => $biographer->getId()], 'client');
        $this->assertSame(200, $status, json_encode($same));
        $this->assertSame('Titre changé par le client', $same['title']);
        $this->assertSame($biographer->getId(), $same['biographer']['id']);
        [$status, $body] = $this->api('PUT', "/books/$id", ['title' => 'Interdit', 'biographerId' => null], 'client');
        $this->assertSame(403, $status, json_encode($body));
        $this->assertSame('FORBIDDEN', $body['code']);
        [, $read] = $this->api('GET', "/books/$id", null, 'client');
        $this->assertSame('Titre changé par le client', $read['title'], 'rien d\'appliqué après le refus');
        $this->assertSame($biographer->getId(), $read['biographer']['id']);

        // Le biographe affecté travaille sur le livre mais n'affecte pas (14/11/2026) : c'est le responsable des biographes ou l'administrateur
        [$status, $body] = $this->call('PUT', "/api/memoires/books/$id", ['biographerId' => null], $biographer);
        $this->assertSame([403, 'FORBIDDEN'], [$status, $body['code']], json_encode($body));
        $manager = $this->user([TeamRoles::MEMOIRES_RESPONSABLE, 'ROLE_USER_INTERNET'], 'Responsable', 'Équipe');
        // Le responsable des biographes affecte et retire ; idempotent ; journal
        [$status, $cleared] = $this->call('PUT', "/api/memoires/books/$id", ['biographerId' => null], $manager);
        $this->assertSame(200, $status, json_encode($cleared));
        $this->assertNull($cleared['biographer']);
        [$status] = $this->call('PUT', "/api/memoires/books/$id", ['biographerId' => null], $manager);
        $this->assertSame(200, $status);
        $journal = $this->journal($id);
        $this->assertSame(['assign', 'unassign'], array_column($journal, 'action'), 'une ligne par changement, rien pour l\'idempotent');
        $this->assertSame(self::ADMIN_EMAIL, $journal[0]['user_identifier']);
        $this->assertStringContainsString('Biographe Affecté', (string) $journal[0]['after_state']);
        $this->assertSame($manager->getEmail(), $journal[1]['user_identifier']);
    }

    public function testTheListFiltersByBiographerAndTheRevenueOverviewAndReservationsFollowTheBook(): void
    {
        $biographer = $this->user([TeamRoles::MEMOIRES_BIOGRAPHE, 'ROLE_USER_INTERNET'], 'Nadia', 'Rédactrice');
        $marker = 'affectation ' . bin2hex(random_bytes(3));
        [, $assigned] = $this->api('POST', '/books', ['title' => "Livre affecté $marker", 'type' => 'individuel'], 'client');
        [, $free] = $this->api('POST', '/books', ['title' => "Livre libre $marker", 'type' => 'individuel'], 'client');
        [$status] = $this->api('PUT', "/books/{$assigned['id']}", ['biographerId' => $biographer->getId()], 'admin');
        $this->assertSame(200, $status);

        [$status, $mine] = $this->api('GET', '/books?scope=all&biographerId=' . $biographer->getId(), null, 'admin');
        $this->assertSame(200, $status);
        $this->assertSame([$assigned['id']], array_column($mine, 'id'));
        [$status, $none] = $this->api('GET', '/books?scope=all&biographerId=none', null, 'admin');
        $this->assertSame(200, $status);
        $this->assertContains($free['id'], array_column($none, 'id'));
        $this->assertNotContains($assigned['id'], array_column($none, 'id'));
        [$status, $body] = $this->api('GET', '/books?scope=all&biographerId=abc', null, 'admin');
        $this->assertSame(400, $status);
        $this->assertSame('BIOGRAPHER_INVALID', $body['code']);
        [$status, $asBiographer] = $this->call('GET', '/api/memoires/books?scope=all&biographerId=' . $biographer->getId(), null, $biographer);
        $this->assertSame(200, $status);
        $this->assertSame([$assigned['id']], array_column($asBiographer, 'id'), 'le biographe filtre ses livres');

        // Vue d'ensemble des revenus
        self::db()->exec("UPDATE mv_book SET payment_status = 'paid', payment_amount = 10, payment_currency = 'eur', paid_at = NOW() WHERE id IN ('{$assigned['id']}', '{$free['id']}')");
        [$status, $overview] = $this->api('GET', '/admin/revenue?search=' . urlencode($marker));
        $this->assertSame(200, $status, json_encode($overview));
        $byId = array_column($overview['books'], null, 'id');
        $this->assertSame(['id' => $biographer->getId(), 'fullName' => 'Nadia Rédactrice'], $byId[$assigned['id']]['biographer']);
        $this->assertNull($byId[$free['id']]['biographer']);

        // Rendez-vous créé depuis un livre : le biographe affecté, sinon aucun (avant : le propriétaire du livre)
        $date = sprintf('2028-%02d-%02d', random_int(1, 12), random_int(1, 28)); // jour tiré au sort : la base de test garde les créneaux des autres tests
        $payload = ['service_name' => 'Séance d\'écriture', 'reservation_date' => $date, 'reservation_slot' => '10:00', 'client_name' => 'Cliente Test', 'client_email' => self::CLIENT_EMAIL, 'client_phone' => '0600000000'];
        [$status, $res] = $this->api('POST', '/reservations', $payload + ['book_id' => $assigned['id']], 'client', '/api');
        $this->assertSame(201, $status, json_encode($res));
        $this->assertSame($biographer->getId(), $res['data']['biographer_id']);
        [$status, $res] = $this->api('POST', '/reservations', ['book_id' => $free['id'], 'reservation_slot' => '11:00'] + $payload, 'client', '/api');
        $this->assertSame(201, $status, json_encode($res));
        $this->assertNull($res['data']['biographer_id'], 'le propriétaire n\'est plus pris pour biographe');
        [$status, $res] = $this->api('POST', '/reservations', ['biographer_id' => self::clientId(), 'reservation_slot' => '12:00'] + $payload, 'client', '/api');
        $this->assertSame(400, $status, json_encode($res));
        $this->assertSame('BIOGRAPHER_INVALID', $res['code']);
    }

    private static function clientId(): int
    {
        return (int) self::db()->query("SELECT id FROM \"user\" WHERE email = '" . self::CLIENT_EMAIL . "'")->fetchColumn();
    }

    /** @return list<array<string, mixed>> entrées book_biographer du livre, la plus ancienne d'abord */
    private function journal(string $bookId): array
    {
        $st = self::db()->prepare('SELECT action, user_identifier, before_state, after_state FROM content_audit_log WHERE resource = ? AND resource_id = ? ORDER BY id');
        $st->execute([BookBiographerService::AUDIT_RESOURCE, $bookId]);

        return $st->fetchAll(\PDO::FETCH_ASSOC);
    }

    private function user(array $roles, string $firstName, string $lastName): User
    {
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $email = strtolower($firstName) . '-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname($firstName)->setLastname($lastName)->setRoles($roles)->setIsVerified(true)->setPassword('inutilisable');
        $provider->getEntityManager()->persist($user);
        $provider->getEntityManager()->flush();

        return $user;
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
