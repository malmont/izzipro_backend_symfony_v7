<?php

namespace App\Tests\Functional\Boutique;

use App\Services\BoutiqueDemoService\BoutiqueDemoSeeder;
use App\Services\BoutiqueSettingsService\BoutiqueSettingsService;
use App\Services\TenantEntityManagerProvider;
use App\Tests\Fake\FakeCheckoutPaymentGateway;
use App\Tests\Fake\FakeSubscriptionStripeGateway;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fuseau horaire de la boutique pour les réservations (04/11/2026, lot L7b de l'application e-commerce mobile) : une date sans
 * fuseau est une heure de la boutique, une date avec fuseau ou en UTC est convertie avant tout contrôle ; les dates rendues sont en
 * ATOM dans le fuseau de la boutique ; le calendrier reste en heures de la boutique. Site de test au Québec → America/Toronto.
 * Kayak : grille horaire, ouvert 09:00-18:00 ; spectacle : dates proposées, créneau du soir 20:30-23:00.
 */
class BookingTimezoneApiTest extends WebTestCase
{
    private const DAY = '2027-06-07'; // été : -04:00 à Montréal
    private KernelBrowser $client;
    private \Doctrine\DBAL\Connection $db;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $this->db = $provider->getEntityManager()->getConnection();
        static::getContainer()->get(BoutiqueDemoSeeder::class)->seed('client-fuseau@example.invalid', false, false);
        $this->db->executeStatement('DELETE FROM boutique_setting');
        // Site de référence : Québec, sans réglage de fuseau (des tests antérieurs laissent la France ou l'Ontario)
        $this->db->executeStatement("UPDATE address_entreprise SET country = 'Canada', state = 'QC'");
        $this->db->executeStatement('UPDATE entreprise SET pos_timezone = NULL');
        FakeCheckoutPaymentGateway::reset();
        FakeSubscriptionStripeGateway::reset();
    }

    public function testTheFiveCasesMeasuredOnDemo(): void
    {
        $kayak = $this->variant('DEMO-KAYAK');
        // 1. 16 h-18 h de Montréal envoyé en UTC (format du site Next) : accepté (422 OUTSIDE_OPENING_HOURS auparavant)
        $quote = $this->quote($kayak, self::DAY . 'T20:00:00.000Z', self::DAY . 'T22:00:00.000Z');
        $this->assertSame(200, $quote['status'], json_encode($quote['body']));
        $this->assertSame([self::DAY . 'T16:00:00-04:00', self::DAY . 'T18:00:00-04:00'], [$quote['body']['lines'][0]['booking']['start'], $quote['body']['lines'][0]['booking']['end']], 'rendu ATOM dans le fuseau de la boutique');

        // 2. 8 h-10 h de Montréal envoyé en UTC, avant l'ouverture : refusé (accepté auparavant)
        $quote = $this->quote($kayak, self::DAY . 'T12:00:00.000Z', self::DAY . 'T14:00:00.000Z');
        $this->assertSame([422, 'OUTSIDE_OPENING_HOURS'], [$quote['status'], $quote['body']['errors'][0]['code'] ?? null], json_encode($quote['body']));

        // 3. Même instant avec son décalage : accepté, même rendu
        $quote = $this->quote($kayak, self::DAY . 'T16:00:00-04:00', self::DAY . 'T18:00:00-04:00');
        $this->assertSame(200, $quote['status'], json_encode($quote['body']));
        $this->assertSame(self::DAY . 'T16:00:00-04:00', $quote['body']['lines'][0]['booking']['start']);

        // 4. Spectacle : 20 h 30 d'une date proposée, envoyé en UTC (donc le lendemain en UTC) : accepté (422 DATE_NOT_OFFERED auparavant)
        $spectacle = $this->variant('DEMO-SPECTACLE');
        $date = $this->showDate();
        $tz = new \DateTimeZone('America/Toronto');
        $utc = fn (string $local) => (new \DateTimeImmutable($local, $tz))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.000\Z');
        $quote = $this->quote($spectacle, $utc("$date 20:30"), $utc("$date 23:00"), ['durationType' => 'day']);
        $this->assertSame(200, $quote['status'], json_encode($quote['body']));
        $this->assertStringStartsWith("{$date}T20:30:00", $quote['body']['lines'][0]['booking']['start']);

        // 5. Spectacle avec décalage : accepté
        $quote = $this->quote($spectacle, "{$date}T20:30:00-04:00", "{$date}T23:00:00-04:00", ['durationType' => 'day']);
        $this->assertSame(200, $quote['status'], json_encode($quote['body']));
    }

    public function testADateWithoutTimezoneIsAShopTime(): void
    {
        $kayak = $this->variant('DEMO-KAYAK');
        foreach ([[self::DAY . 'T16:00:00', self::DAY . 'T18:00:00'], [self::DAY . ' 16:00', self::DAY . ' 18:00']] as [$start, $end]) {
            $quote = $this->quote($kayak, $start, $end);
            $this->assertSame(200, $quote['status'], json_encode($quote['body']));
            $this->assertSame([self::DAY . 'T16:00:00-04:00', self::DAY . 'T18:00:00-04:00'], [$quote['body']['lines'][0]['booking']['start'], $quote['body']['lines'][0]['booking']['end']], $start);
        }
        $quote = $this->quote($kayak, self::DAY . 'T08:00:00', self::DAY . 'T10:00:00');
        $this->assertSame([422, 'OUTSIDE_OPENING_HOURS'], [$quote['status'], $quote['body']['errors'][0]['code'] ?? null], '8 h de la boutique : avant l\'ouverture');
    }

    public function testDaylightSavingChangesOfMarchAndNovember(): void
    {
        $kayak = $this->variant('DEMO-KAYAK');
        // Passage à l'heure avancée le 14 mars 2027, retour à l'heure normale le 7 novembre 2027 (Montréal)
        foreach ([
            ['2027-03-13T10:00:00', '2027-03-13T12:00:00', '2027-03-13T10:00:00-05:00'],
            ['2027-03-14T10:00:00', '2027-03-14T12:00:00', '2027-03-14T10:00:00-04:00'],
            ['2027-11-06T10:00:00', '2027-11-06T12:00:00', '2027-11-06T10:00:00-04:00'],
            ['2027-11-07T10:00:00', '2027-11-07T12:00:00', '2027-11-07T10:00:00-05:00'],
            ['2027-03-13T15:00:00Z', '2027-03-13T17:00:00Z', '2027-03-13T10:00:00-05:00'],
            ['2027-03-15T14:00:00Z', '2027-03-15T16:00:00Z', '2027-03-15T10:00:00-04:00'],
            ['2027-11-08T15:00:00Z', '2027-11-08T17:00:00Z', '2027-11-08T10:00:00-05:00'],
        ] as [$start, $end, $expected]) {
            $quote = $this->quote($kayak, $start, $end);
            $this->assertSame(200, $quote['status'], $start . ' : ' . json_encode($quote['body']));
            $this->assertSame($expected, $quote['body']['lines'][0]['booking']['start'], $start);
        }
        // 2 h-4 h le 14 mars n'existent pas à Montréal : PHP avance l'heure ; en UTC le 13 mars 15 h-17 h = 10 h-12 h, accepté ci-dessus
    }

    public function testCheckReadsDatesLikeTheQuoteAndTheCalendarIsUnchanged(): void
    {
        $product = $this->productId('DEMO-KAYAK');
        foreach (['start=' . self::DAY . 'T20:00:00.000Z&end=' . self::DAY . 'T22:00:00.000Z', 'start=' . self::DAY . 'T16:00:00&end=' . self::DAY . 'T18:00:00'] as $query) {
            $response = $this->request('GET', "/api/booking/check/$product?$query&quantity=1");
            $this->assertSame(200, $response->getStatusCode(), $response->getContent());
            $this->assertTrue(json_decode($response->getContent(), true)['available']);
        }

        $response = $this->request('GET', "/api/booking/calendar/$product?start=" . self::DAY . '&end=' . self::DAY);
        $this->assertSame(200, $response->getStatusCode(), $response->getContent());
        $slots = json_decode($response->getContent(), true);
        $this->assertCount(9, $slots, 'créneaux horaires de 09:00 à 18:00');
        $this->assertSame([self::DAY . ' 09:00:00', self::DAY . ' 10:00:00'], [$slots[0]['start'], $slots[0]['end']], 'heures de la boutique, sans décalage, inchangé');
        $this->assertSame(self::DAY . ' 18:00:00', $slots[8]['end']);
        $this->assertSame(['start', 'end', 'remaining', 'is_available', 'status'], array_keys($slots[0]));
    }

    public function testTheShopTimezoneIsExposedReadOnlyAndFollowsTheSiteSetting(): void
    {
        // Sans réglages enregistrés la route rend null : une ligne vide suffit pour lire commerce.* (le cache est vidé à l'écriture)
        $this->db->executeStatement("INSERT INTO boutique_setting (configuration) VALUES ('{\"commerce\": {\"guestCheckout\": true}}')");
        $settings = json_decode($this->request('GET', '/api/boutique-settings')->getContent(), true);
        $this->assertSame('America/Toronto', $settings['configuration']['commerce']['timezone'] ?? ($settings['commerce']['timezone'] ?? null), json_encode($settings));
        $this->assertContains('timezone', BoutiqueSettingsService::READ_ONLY_COMMERCE, 'jamais écrit par un PUT');

        $this->db->executeStatement("UPDATE entreprise SET pos_timezone = 'Europe/Paris'");
        try {
            $settings = json_decode($this->request('GET', '/api/boutique-settings')->getContent(), true);
            $this->assertSame('Europe/Paris', $settings['configuration']['commerce']['timezone'] ?? ($settings['commerce']['timezone'] ?? null));
            $quote = $this->quote($this->variant('DEMO-KAYAK'), self::DAY . 'T16:00:00', self::DAY . 'T18:00:00');
            $this->assertSame(200, $quote['status'], json_encode($quote['body']));
            $this->assertSame(self::DAY . 'T16:00:00+02:00', $quote['body']['lines'][0]['booking']['start'], 'réglage du site : heure de Paris');
        } finally {
            $this->db->executeStatement('UPDATE entreprise SET pos_timezone = NULL');
        }
    }

    public function testAGuestOrderStoresTheInstantAndRendersItInTheShopTimezone(): void
    {
        $kayak = $this->variant('DEMO-KAYAK');
        $email = 'invite-fuseau-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $items = [['productVariantId' => $kayak, 'quantity' => 1, 'booking' => ['start' => self::DAY . 'T20:00:00.000Z', 'end' => self::DAY . 'T22:00:00.000Z']]];
        $intent = $this->request('POST', '/api/stripe/create-intent', json_encode(['items' => $items, 'shippingAddress' => ['province' => 'QC']]));
        $this->assertSame(200, $intent->getStatusCode(), $intent->getContent());
        $pi = json_decode($intent->getContent(), true)['paymentIntentId'];
        FakeCheckoutPaymentGateway::confirm($pi);
        $body = [
            'guestInfo' => ['email' => $email, 'firstName' => 'Invité', 'lastName' => 'Fuseau', 'phone' => '5145550100', 'licenseNumber' => 'T1234-567890-12', 'licenseExpirationDate' => '2029-12-31'], // permis exigé pour une location
            'items' => $items, 'paymentIntentId' => $pi,
            'shippingAddress' => ['firstname' => 'Invité', 'lastname' => 'Fuseau', 'address' => '1 rue du Test', 'city' => 'Québec', 'province' => 'QC', 'postalCode' => 'G1R 4P5'],
        ];
        $created = $this->request('POST', '/api/order/create-guest', json_encode($body));
        $this->assertSame(201, $created->getStatusCode(), $created->getContent());
        $order = json_decode($created->getContent(), true);

        $row = $this->db->fetchAssociative('SELECT b.start_at, b.end_at FROM booking b JOIN order_items oi ON b.order_item_id = oi.id WHERE oi.order_associated_id = ?', [$order['orderId']]);
        $this->assertSame([self::DAY . ' 20:00:00', self::DAY . ' 22:00:00'], [$row['start_at'], $row['end_at']], 'instant enregistré en UTC : 16 h-18 h de Montréal');

        $detail = $this->request('GET', '/api/orders/' . $order['orderId'] . '?token=' . $order['guestToken']);
        $this->assertSame(200, $detail->getStatusCode(), $detail->getContent());
        $items = json_decode($detail->getContent(), true)['orderItems'];
        $this->assertSame([self::DAY . 'T16:00:00-04:00', self::DAY . 'T18:00:00-04:00'], [$items[0]['booking']['start'], $items[0]['booking']['end']], 'détail de commande en ATOM dans le fuseau de la boutique');
    }

    /** @return array{status: int, body: array} */
    private function quote(int $variant, string $start, string $end, array $booking = []): array
    {
        $response = $this->request('POST', '/api/cart/quote', json_encode(['items' => [['productVariantId' => $variant, 'quantity' => 1, 'booking' => $booking + ['start' => $start, 'end' => $end]]]]));

        return ['status' => $response->getStatusCode(), 'body' => json_decode($response->getContent(), true) ?? []];
    }

    private function showDate(): string
    {
        $dates = json_decode((string) $this->db->fetchOne("SELECT bc.allowed_dates FROM booking_configuration bc JOIN product p ON p.id = bc.product_id WHERE p.code = 'DEMO-SPECTACLE'"), true);
        $this->assertNotEmpty($dates, 'le spectacle de démonstration a des dates proposées');

        return $dates[0];
    }

    private function variant(string $code): int
    {
        return (int) $this->db->fetchOne('SELECT v.id FROM product_variant v JOIN product p ON p.id = v.product_id WHERE p.code = ? ORDER BY v.id LIMIT 1', [$code]);
    }

    private function productId(string $code): int
    {
        return (int) $this->db->fetchOne('SELECT id FROM product WHERE code = ?', [$code]);
    }

    private function request(string $method, string $path, ?string $body = null): Response
    {
        $this->client->getCookieJar()->clear();
        $this->client->request($method, 'https://' . MV_TEST_TENANT_HOST . $path, [], [], [
            'HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
        ], $body);

        return $this->client->getResponse();
    }
}
