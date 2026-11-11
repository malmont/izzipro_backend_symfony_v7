<?php

namespace App\Tests\Functional\Security;

use App\Services\TenantEntityManagerProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Piège à robots des formulaires de contact et de prise de rendez-vous (11/11/2026, spams reçus par esgboost) :
 * champ caché « site » rempli ou fillSeconds trop court = réponse d'un envoi réussi, rien d'enregistré ni d'envoyé.
 * Mode tolérant : un corps sans fillSeconds (front pas encore à jour, application mobile) passe comme avant.
 */
class PublicFormTrapTest extends WebTestCase
{
    private const CONTACT = ['name' => 'Marie Durand', 'email' => 'marie@example.com', 'message' => 'Bonjour, un devis ?'];
    private const BOOKING = ['service_name' => 'Accompagnement', 'reservation_date' => '2031-03-12', 'reservation_slot' => '09:00 - 12:00',
        'client_name' => 'Client Test', 'client_email' => 'client-piege@example.invalid', 'client_phone' => '514 555-0101'];

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $key = MV_TEST_TENANT_CODE . '|127.0.0.1';
        static::getContainer()->get('limiter.public_form_ip')->create($key)->reset();
        static::getContainer()->get('limiter.public_form_attempts_ip')->create($key)->reset();
        static::getContainer()->get('limiter.public_form_global_ip')->create('127.0.0.1')->reset();
        $this->db()->executeStatement('DELETE FROM contact');
        $this->db()->executeStatement("DELETE FROM reservation WHERE client_email = 'client-piege@example.invalid'");
    }

    protected function tearDown(): void
    {
        // créneaux libérés pour les autres tests de réservation
        $this->db()->executeStatement("DELETE FROM reservation WHERE client_email = 'client-piege@example.invalid'");
        parent::tearDown();
    }

    public function testContactRobotsGetASuccessButNothingIsSavedNorSent(): void
    {
        foreach ([['site' => 'https://spam.example', 'fillSeconds' => 30], ['fillSeconds' => 1], ['fillSeconds' => 'vite']] as $trap) {
            $response = $this->post('/api/contacts/create', self::CONTACT + $trap);

            $this->assertSame(201, $response->getStatusCode(), $response->getContent());
            $this->assertSame('Marie Durand', json_decode($response->getContent(), true)['name'], 'même forme qu\'un envoi réussi');
            $this->assertEmailCount(0);
        }
        $this->assertSame(201, $this->post('/api/contacts', self::CONTACT + ['fillSeconds' => 2])->getStatusCode());
        $this->assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM contact'));
    }

    public function testRealContactMessagesStillArrive(): void
    {
        $this->assertSame(201, $this->post('/api/contacts/create', self::CONTACT + ['site' => '', 'fillSeconds' => 25])->getStatusCode());
        $this->assertSame(201, $this->post('/api/contacts/create', self::CONTACT + ['fillSeconds' => '12.5'])->getStatusCode(), 'durée en texte acceptée');
        $this->assertSame(201, $this->post('/api/contacts/create', self::CONTACT)->getStatusCode(), 'front pas encore à jour : sans fillSeconds');

        $this->assertSame(3, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM contact'));
    }

    public function testContactSubmitRobotsAreNotMailed(): void
    {
        $body = ['name' => 'Robot', 'email' => 'robot@example.com', 'message' => 'Achetez !', 'site' => 'x', 'fillSeconds' => 30];
        $response = $this->post('/api/contact/submit', $body);

        $this->assertSame(200, $response->getStatusCode(), $response->getContent());
        $this->assertSame('Message envoyé avec succès.', json_decode($response->getContent(), true)['message']);
        $this->assertEmailCount(0);
    }

    public function testBookingRobotsAreNotSaved(): void
    {
        $response = $this->post('/api/reservations', self::BOOKING + ['fillSeconds' => 0.5]);

        $this->assertSame(201, $response->getStatusCode(), $response->getContent());
        $body = json_decode($response->getContent(), true);
        $this->assertTrue($body['success']);
        $this->assertSame(['2031-03-12', 'pending'], [$body['data']['reservation_date'], $body['data']['status']]);
        $this->assertEmailCount(0);
        $this->assertSame(0, $this->bookings());
    }

    public function testRealBookingsStillArrive(): void
    {
        $this->assertSame(201, $this->post('/api/reservations', self::BOOKING + ['site' => '  ', 'fillSeconds' => 40])->getStatusCode());
        $this->assertSame(201, $this->post('/api/reservations', array_replace(self::BOOKING, ['reservation_slot' => '13:00 - 17:00']))->getStatusCode(), 'application mobile : sans fillSeconds');

        $this->assertSame(2, $this->bookings());
    }

    private function bookings(): int
    {
        return (int) $this->db()->fetchOne("SELECT COUNT(*) FROM reservation WHERE client_email = 'client-piege@example.invalid'");
    }

    private function post(string $path, array $body): Response
    {
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . $path, [], [], [
            'HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST,
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], json_encode($body));

        return $this->client->getResponse();
    }

    private function db(): \Doctrine\DBAL\Connection
    {
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);

        return $provider->getEntityManager()->getConnection();
    }
}
