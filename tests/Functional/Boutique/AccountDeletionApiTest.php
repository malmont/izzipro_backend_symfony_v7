<?php

namespace App\Tests\Functional\Boutique;

use App\Entity\Adress;
use App\Entity\User;
use App\Services\TenantEntityManagerProvider;
use App\Tests\Fake\FakeSubscriptionStripeGateway;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * DELETE /api/account (01/11/2026, exigence des stores) : confirmation par mot de passe ou par code, administrateur refusé,
 * réservation à venir bloquante, données personnelles anonymisées (compte, adresses, infolettre, client Stripe), jetons révoqués.
 */
class AccountDeletionApiTest extends WebTestCase
{
    private const PASSWORD = 'Mot-de-passe-de-test-1!';
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        FakeSubscriptionStripeGateway::reset();
    }

    public function testDeletionWithThePasswordErasesPersonalDataAndRevokesTheSession(): void
    {
        [$email, $id] = $this->user();
        $db = $this->db();
        $db->executeStatement('INSERT INTO newsletter_subscriber (id, email, subscribed_at) VALUES (nextval(\'newsletter_subscriber_id_seq\'), ?, NOW())', [$email]);
        $db->executeStatement("INSERT INTO reservation (id, service_name, reservation_date, client_name, client_email, client_phone, number_of_guests, status, created_at)
            VALUES (nextval('reservation_id_seq'), 'Visite', CURRENT_DATE - 30, 'Marie Tremblay', ?, '+1 514 555 0100', 1, 'confirmed', NOW())", [$email]);
        $db->executeStatement('UPDATE "user" SET stripe_customer_id = ? WHERE id = ?', ['cus_test_' . $id, $id]);
        $token = $this->login($email);
        $this->assertSame(1, $this->tokenCount($email));

        $refused = $this->delete($token, []);
        $this->assertSame([403, 'PASSWORD_REQUIRED'], [$refused->getStatusCode(), json_decode($refused->getContent(), true)['error']]);
        $refused = $this->delete($token, ['password' => 'faux']);
        $this->assertSame([403, 'PASSWORD_INVALID'], [$refused->getStatusCode(), json_decode($refused->getContent(), true)['error']]);

        $done = $this->delete($token, ['password' => self::PASSWORD]);
        $this->assertSame(200, $done->getStatusCode(), $done->getContent());
        $this->assertSame(
            ['deleted' => true, 'addressesAnonymized' => 1, 'reviewsAnonymized' => 0, 'reservationsAnonymized' => 1, 'newsletterRemoved' => true, 'stripeCustomerDeleted' => true],
            json_decode($done->getContent(), true)
        );
        $this->assertContains(['deleteCustomer', 'cus_test_' . $id], FakeSubscriptionStripeGateway::$calls);

        $user = $db->fetchAssociative('SELECT email, firstname, lastname, roles::text AS roles, is_verified, deleted_at, stripe_customer_id, primary_address_id FROM "user" WHERE id = ?', [$id]);
        $this->assertSame(["supprime-$id@compte-supprime.invalid", 'Compte', 'supprimé', '[]', false, null, null], [$user['email'], $user['firstname'], $user['lastname'], $user['roles'], (bool) $user['is_verified'], $user['stripe_customer_id'], $user['primary_address_id']]);
        $this->assertNotNull($user['deleted_at']);
        $address = $db->fetchAssociative('SELECT firstname, address, phone, city, codepostal, country, user_adress_id FROM adress WHERE id = (SELECT MAX(id) FROM adress)');
        $this->assertSame(['Compte', '-', '-', '-', '-', 'CA', null], array_values($address), 'adresse anonymisée sur place, pays gardé');
        $this->assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM newsletter_subscriber WHERE email = ?', [$email]));
        $this->assertSame('Client anonyme', $db->fetchOne('SELECT client_name FROM reservation WHERE id = (SELECT MAX(id) FROM reservation)'));
        $this->assertSame(0, $this->tokenCount($email), 'jetons de rafraîchissement révoqués');

        $this->assertSame(401, $this->request('GET', '/api/profile', null, $token)->getStatusCode(), 'le jeton en cours ne sert plus');
        $login = $this->request('POST', '/api/login', json_encode(['username' => $email, 'password' => self::PASSWORD, 'platform' => 'mobile']));
        $this->assertSame(401, $login->getStatusCode(), 'connexion impossible');
    }

    public function testDeletionWithACodeForAnAccountWithoutAChosenPassword(): void
    {
        [$email, $id] = $this->user(passwordSet: false);
        $token = $this->login($email);

        $sent = $this->delete($token, []);
        $body = json_decode($sent->getContent(), true);
        $this->assertSame([403, 'OTP_REQUIRED', 300], [$sent->getStatusCode(), $body['error'], $body['lifetimeSeconds']]);
        $this->assertStringContainsString('@example.invalid', $body['sentTo']);
        $code = $this->db()->fetchOne('SELECT code FROM otp_code WHERE user_otp_id = ? ORDER BY id DESC', [$id]);
        $this->assertNotEmpty($code);

        $wrong = $this->delete($token, ['otpCode' => '000000']);
        $this->assertSame([403, 'OTP_INVALID'], [$wrong->getStatusCode(), json_decode($wrong->getContent(), true)['error']]);
        $done = $this->delete($token, ['otpCode' => $code]);
        $this->assertSame(200, $done->getStatusCode(), $done->getContent());
        $this->assertTrue(json_decode($done->getContent(), true)['deleted']);
        $this->assertNotNull($this->db()->fetchOne('SELECT deleted_at FROM "user" WHERE id = ?', [$id]));
    }

    public function testAnAdministratorAndAnAccountWithAnUpcomingReservationAreRefused(): void
    {
        [$admin] = $this->user(roles: ['ROLE_USER', 'ROLE_USER_INTERNET', 'ROLE_ADMIN']);
        $refused = $this->delete($this->login($admin), ['password' => self::PASSWORD]);
        $this->assertSame([403, 'ADMIN_ACCOUNT'], [$refused->getStatusCode(), json_decode($refused->getContent(), true)['error']]);

        [$email, $id] = $this->user();
        $this->db()->executeStatement("INSERT INTO reservation (id, service_name, reservation_date, client_name, client_email, client_phone, number_of_guests, status, created_at)
            VALUES (nextval('reservation_id_seq'), 'Visite', CURRENT_DATE + 3, 'Marie Tremblay', ?, '+1 514 555 0100', 1, 'confirmed', NOW())", [$email]);
        $blocked = $this->delete($this->login($email), ['password' => self::PASSWORD]);
        $this->assertSame([409, 'RESERVATION_UPCOMING', 1], [$blocked->getStatusCode(), json_decode($blocked->getContent(), true)['error'], json_decode($blocked->getContent(), true)['count']]);
        $this->assertNull($this->db()->fetchOne('SELECT deleted_at FROM "user" WHERE id = ?', [$id]), 'rien n\'est effacé');
    }

    private function delete(string $token, array $body): Response
    {
        return $this->request('DELETE', '/api/account', json_encode($body), $token);
    }

    private function login(string $email): string
    {
        $login = $this->request('POST', '/api/login', json_encode(['username' => $email, 'password' => self::PASSWORD, 'platform' => 'mobile']));
        $this->assertSame(200, $login->getStatusCode(), $login->getContent());

        return json_decode($login->getContent(), true)['token'];
    }

    private function tokenCount(string $email): int
    {
        return (int) $this->db()->fetchOne('SELECT COUNT(*) FROM refresh_tokens WHERE username = ?', [$email]);
    }

    private function request(string $method, string $path, ?string $body = null, ?string $bearer = null): Response
    {
        $this->client->getCookieJar()->clear();
        $this->client->request($method, 'https://' . MV_TEST_TENANT_HOST . $path, [], [], array_filter([
            'HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => $bearer ? 'Bearer ' . $bearer : null,
        ]), $body);

        return $this->client->getResponse();
    }

    /** @return array{0: string, 1: int} e-mail et identifiant, avec une adresse principale */
    private function user(array $roles = ['ROLE_USER', 'ROLE_USER_INTERNET'], bool $passwordSet = true): array
    {
        $email = 'suppr-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $container = static::getContainer();
        $provider = $container->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $em = $provider->getEntityManager();
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Marie')->setLastname('Tremblay')->setRoles($roles)->setIsVerified(true)->setPasswordSet($passwordSet);
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD));
        $address = (new Adress())->setFirstname('Marie')->setLastname('Tremblay')->setFullname('Tremblay Marie')->setAddress('1 rue du Test')->setCity('Montréal')
            ->setCodepostal('H1A 1A1')->setCountry('CA')->setPhone('+1 514 555 0000')->setProvince('QC')->setUserAdress($user);
        $em->persist($user);
        $em->persist($address);
        $em->flush();
        $user->setPrimaryAddress($address);
        $em->flush();

        return [$email, (int) $user->getId()];
    }

    private function db(): \Doctrine\DBAL\Connection
    {
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);

        return $provider->getEntityManager()->getConnection();
    }
}
