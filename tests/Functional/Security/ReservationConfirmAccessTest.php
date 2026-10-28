<?php

namespace App\Tests\Functional\Security;

use App\Entity\User;
use App\Services\TenantEntityManagerProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Lot sécurité du 27/10/2026 :
 * - POST /api/reservations/{id}/confirm était public : n'importe qui confirmait n'importe quel identifiant (courriel
 *   envoyé au client, adresse du client dans la réponse). Désormais : administrateur ou biographe de la réservation ;
 * - GET /api/newsletter était lisible par tout compte connecté (la création de compte client est publique).
 */
class ReservationConfirmAccessTest extends WebTestCase
{
    private const PASSWORD = 'Mot-de-passe-de-test-1!';

    private KernelBrowser $client;
    private ?string $token = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        // formulaires publics limités par IP : toute la suite partage 127.0.0.1
        $key = MV_TEST_TENANT_HOST . '|127.0.0.1';
        static::getContainer()->get('limiter.public_form_ip')->create($key)->reset();
        static::getContainer()->get('limiter.public_form_attempts_ip')->create($key)->reset();
    }

    public function testAnonymousCannotConfirmAReservation(): void
    {
        $id = $this->reservation(null);

        $response = $this->request('POST', "/api/reservations/$id/confirm");

        $this->assertSame(401, $response->getStatusCode(), $response->getContent());
        $this->assertSame('pending', $this->status($id), 'statut inchangé');
        $this->assertStringNotContainsString('client-', $response->getContent(), 'aucune donnée du client');
    }

    public function testConnectedUserWhoIsNeitherAdminNorBiographerIsRefused(): void
    {
        $biographer = $this->user(['ROLE_USER', 'ROLE_USER_INTERNET']);
        $id = $this->reservation($biographer->getId());
        $this->loginAs($this->user(['ROLE_USER_INTERNET']));

        $response = $this->request('POST', "/api/reservations/$id/confirm");

        $this->assertSame(403, $response->getStatusCode(), $response->getContent());
        $this->assertSame('pending', $this->status($id));
        $this->assertStringNotContainsString('client-', $response->getContent());
        $this->assertSame(404, $this->request('POST', '/api/reservations/999999999/confirm')->getStatusCode());
    }

    public function testBiographerConfirmsTheirReservationWithoutTheClientEmailInTheResponse(): void
    {
        $biographer = $this->user(['ROLE_USER', 'ROLE_USER_INTERNET']);
        $id = $this->reservation($biographer->getId());
        $this->loginAs($biographer);

        $response = $this->request('POST', "/api/reservations/$id/confirm");

        $this->assertSame(200, $response->getStatusCode(), $response->getContent());
        $this->assertSame('confirmed', $this->status($id));
        $body = json_decode($response->getContent(), true);
        $this->assertSame('confirmed', $body['reservation']['status']);
        $this->assertArrayNotHasKey('client_email', $body['reservation']);
        $this->assertStringNotContainsString('@example.invalid', $response->getContent());

        $other = $this->reservation($this->user(['ROLE_USER', 'ROLE_USER_INTERNET'])->getId());
        $this->assertSame(403, $this->request('POST', "/api/reservations/$other/confirm")->getStatusCode(), 'pas le rendez-vous d\'un autre biographe');
        $this->assertSame('pending', $this->status($other));
    }

    public function testAdministratorConfirmsAnyReservation(): void
    {
        $id = $this->reservation(null);
        $this->loginAs($this->user(['ROLE_ADMIN', 'ROLE_USER_INTERNET']));

        $response = $this->request('POST', "/api/reservations/$id/confirm");

        $this->assertSame(200, $response->getStatusCode(), $response->getContent());
        $this->assertSame('confirmed', $this->status($id));
    }

    public function testBookingRemainsPublic(): void
    {
        $response = $this->request('POST', '/api/reservations', [
            'service_name' => 'Accompagnement', 'reservation_date' => '2030-01-15', 'reservation_slot' => '09:00 - 12:00',
            'client_name' => 'Client Test', 'client_email' => 'client-public@example.invalid', 'client_phone' => '514 555-0101',
        ]);

        $this->assertSame(201, $response->getStatusCode(), $response->getContent());
        $this->assertSame(200, $this->request('GET', '/api/reservations/services')->getStatusCode());
        $this->assertSame(200, $this->request('GET', '/api/reservations/booked-slots?date=2030-01-15')->getStatusCode());
    }

    public function testNewsletterListIsReservedToTheAdministrator(): void
    {
        $this->db()->executeStatement("INSERT INTO newsletter_subscriber (id, email, subscribed_at) VALUES (nextval('newsletter_subscriber_id_seq'), 'abonne-test@example.invalid', NOW())");

        $this->assertSame(401, $this->request('GET', '/api/newsletter')->getStatusCode(), 'anonyme');
        $this->loginAs($this->user(['ROLE_USER_INTERNET']));
        $response = $this->request('GET', '/api/newsletter');
        $this->assertSame(403, $response->getStatusCode(), 'client connecté');
        $this->assertStringNotContainsString('abonne-test@example.invalid', $response->getContent());

        $this->loginAs($this->user(['ROLE_ADMIN', 'ROLE_USER_INTERNET']));
        $response = $this->request('GET', '/api/newsletter');
        $this->assertSame(200, $response->getStatusCode(), $response->getContent());
        $this->assertStringContainsString('abonne-test@example.invalid', $response->getContent());
        $this->assertSame(201, $this->request('POST', '/api/newsletter/subscribe', ['email' => 'nouvel-abonne@example.invalid'])->getStatusCode(), 'l\'inscription reste publique');
    }

    private function reservation(?int $biographerId): int
    {
        return (int) $this->db()->fetchOne(
            "INSERT INTO reservation (service_name, reservation_date, client_name, client_email, client_phone, status, biographer_id)
             VALUES ('Séance', '2030-01-10', 'Client Test', ?, '514 555-0100', 'pending', ?) RETURNING id",
            ['client-' . bin2hex(random_bytes(3)) . '@example.invalid', $biographerId]
        );
    }

    private function status(int $id): string
    {
        return (string) $this->db()->fetchOne('SELECT status FROM reservation WHERE id = ?', [$id]);
    }

    private function user(array $roles): User
    {
        $email = 'resa-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $container = static::getContainer();
        $provider = $container->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Résa')->setLastname('Test')->setRoles($roles)->setIsVerified(true);
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD));
        $provider->getEntityManager()->persist($user);
        $provider->getEntityManager()->flush();

        return $user;
    }

    private function loginAs(User $user): void
    {
        $this->token = null;
        $login = $this->request('POST', '/api/login', ['username' => $user->getEmail(), 'password' => self::PASSWORD, 'platform' => 'mobile']);
        $this->assertSame(200, $login->getStatusCode(), $login->getContent());
        $this->token = json_decode($login->getContent(), true)['token'];
    }

    private function request(string $method, string $path, ?array $body = null): Response
    {
        $this->client->getCookieJar()->clear();
        $this->client->request($method, 'https://' . MV_TEST_TENANT_HOST . $path, [], [], array_filter([
            'HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => $this->token ? 'Bearer ' . $this->token : null,
        ]), $body !== null ? json_encode($body) : null);

        return $this->client->getResponse();
    }

    private function db(): \Doctrine\DBAL\Connection
    {
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);

        return $provider->getEntityManager()->getConnection();
    }
}
