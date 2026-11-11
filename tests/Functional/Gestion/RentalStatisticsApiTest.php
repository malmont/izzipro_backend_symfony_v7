<?php

namespace App\Tests\Functional\Gestion;

use App\Entity\Order;
use App\Entity\OrderItems;
use App\Entity\OrderSource;
use App\Entity\OrderType;
use App\Entity\Product;
use App\Entity\ProductVariant;
use App\Entity\StatusCommande;
use App\Entity\User;
use App\EventSubscriber\CacheInvalidationSubscriber;
use App\Services\CaisseService\StoreClock;
use App\Services\TenantEntityManagerProvider;
use App\Tests\Functional\Cache\CacheTestEntityFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Lot LOC1, statistiques des locations (11/11/2026, `docs/locations-gestion.md`, contrat LOC validé par l'application) : revenu à la date de
 * vente (règle de P2, réservation annulée à commande gardée comprise) et par date de location (prorata), occupation en heures (heures
 * d'ouverture) ou en jours, réservations annulées hors occupation, par jour de la semaine, jours creux. Semaine du lundi 5 mai 2003 (aucune
 * autre donnée datée là) ; articles, commandes et réservations supprimés après.
 */
class RentalStatisticsApiTest extends WebTestCase
{
    use CacheTestEntityFactory;

    private KernelBrowser $client;
    private string $token = '';
    private int $userId = 0;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->clean();
        $this->login();
    }

    protected function tearDown(): void
    {
        $this->clean();
        parent::tearDown();
    }

    public function testRevenueOccupancyAndWeekdays(): void
    {
        $conn = $this->em()->getConnection();
        $tag = bin2hex(random_bytes(2));
        $make = function (string $letter, string $granularity, int $capacity, ?string $open, ?string $close) use ($conn, $tag): array {
            $conn->executeStatement("INSERT INTO product (id, name, price, isbestseller, quantity, created_at, slug, product_type) VALUES (nextval('product_id_seq'), ?, 1000, false, 0, NOW(), ?, 'standard')", ["Loc $letter", "loc-test-$letter-$tag"]);
            $pid = (int) $conn->fetchOne('SELECT id FROM product WHERE slug = ?', ["loc-test-$letter-$tag"]);
            $conn->executeStatement('INSERT INTO booking_configuration (id, product_id, granularity, stock_quantity, min_duration, buffer_time, opening_start, opening_end) VALUES (nextval(\'booking_configuration_id_seq\'), ?, ?, ?, 1, 0, ?, ?)', [$pid, $granularity, $capacity, $open, $close]);
            $vid = (int) $this->create(ProductVariant::class, ['product' => $this->em()->find(Product::class, $pid)])->getId();

            return [$pid, $vid];
        };
        [$h, $hv] = $make('H', 'hours', 2, '09:00', '18:00');
        [$d, $dv] = $make('D', 'days', 1, null, null);
        [$n] = $make('N', 'hours', 1, null, null);
        // H : lundi 10 h-13 h (vendu le 28/04, avant la plage), mardi 10 h-12 h × 2, mercredi annulée (commande gardée) ; D : samedi 10 h → lundi 10 h (2 jours)
        $this->rental($h, $hv, '2003-04-28', '2003-05-05 10:00', '2003-05-05 13:00', 1, 3000, 'CONFIRMED');
        $this->rental($h, $hv, '2003-05-06', '2003-05-06 10:00', '2003-05-06 12:00', 2, 2000, 'CONFIRMED');
        $this->rental($h, $hv, '2003-05-06', '2003-05-07 10:00', '2003-05-07 11:00', 1, 1000, 'CANCELLED');
        $this->rental($d, $dv, '2003-05-09', '2003-05-10 10:00', '2003-05-12 10:00', 1, 5000, 'CONFIRMED');
        $this->rental($d, $dv, '2003-05-07', '2003-05-08 10:00', '2003-05-09 10:00', 1, 1000, 'CONFIRMED'); // 24 h = 1 jour (jeudi), pas 2
        $this->endOfWrite();

        $r = $this->get("/api/gestion/locations/statistiques?from=2003-05-05&to=2003-05-11&productIds=$h,$d&locale=fr");
        $this->assertSame([['type' => 'custom', 'from' => '2003-05-05', 'to' => '2003-05-11', 'days' => 7], 9000, 10958, 4, 1], [$r['range'], $r['revenue'], $r['revenueByRentalDate'], $r['rentals'], $r['cancellations']],
            'revenu à la date de vente = P2 (2000 + 1000 annulée gardée + 5000 + 1000) ; par date de location : 3000 + 2000 + 1000 + 5000 × 38 h ÷ 48 h + 1000');
        $p = array_column($r['products'], null, 'productId');
        $this->assertEquals([['hours', 9.0, 126.0, 7.0, 5.6, 2, 2.5, 1, 3000, 6000]], [[$p[$h]['unit'], $p[$h]['hoursPerDay'], $p[$h]['offeredTime'], $p[$h]['bookedTime'], $p[$h]['occupancy'], $p[$h]['rentals'], $p[$h]['averageDuration'], $p[$h]['cancellations'], $p[$h]['revenue'], $p[$h]['revenueByRentalDate']]],
            'heures : 2 × 9 h × 7 jours offertes ; 3 h + 2 × 2 h louées ; annulée hors occupation');
        $this->assertEquals([['days', null, 7.0, 3.0, 42.9, 2, 1.5, 0, 6000, 4958]], [[$p[$d]['unit'], $p[$d]['hoursPerDay'], $p[$d]['offeredTime'], $p[$d]['bookedTime'], $p[$d]['occupancy'], $p[$d]['rentals'], $p[$d]['averageDuration'], $p[$d]['cancellations'], $p[$d]['revenue'], $p[$d]['revenueByRentalDate']]],
            'jours : jeudi 10 h → vendredi 10 h = 1 jour ; samedi 10 h → lundi 10 h = 2 jours (samedi, dimanche)');
        $w = array_column($r['weekdays'], null, 'weekday');
        $this->assertEquals([8.3, 11.1, 0.0, 50.0, 0.0, 50.0, 50.0, 'lundi'], [$w[1]['occupancy'], $w[2]['occupancy'], $w[3]['occupancy'], $w[4]['occupancy'], $w[5]['occupancy'], $w[6]['occupancy'], $w[7]['occupancy'], $w[1]['label']], 'moyenne des occupations des articles par jour');
        $this->assertSame([1, 1, 0, 1], [$w[1]['rentals'], $w[2]['rentals'], $w[3]['rentals'], $w[6]['rentals']], 'l\'annulée ne compte pas');
        $this->assertSame([[], [1, 2, 3, 5]], [$r['highDays'], $r['lowDays']]);
        $this->assertSame(['REVENUE_AT_SALE_DATE', 'CANCELLED_EXCLUDED_FROM_OCCUPANCY'], array_column($r['caveats'], 'code'));

        $none = $this->get("/api/gestion/locations/statistiques?from=2003-05-05&to=2003-05-11&productIds=$n");
        $this->assertEquals([24.0, 168.0, false], [$none['products'][0]['hoursPerDay'], $none['products'][0]['offeredTime'], $none['products'][0]['openingHours']]);
        $this->assertSame([$n], array_column($none['caveats'], null, 'code')['NO_OPENING_HOURS']['productIds']);

        foreach (['from=2003-05-11&to=2003-05-05' => ['to' => 'BEFORE_START'], 'from=2003-02-30&to=2003-03-01' => ['from' => 'INVALID_DATE'], 'from=2003-01-01&to=2004-06-01' => ['to' => 'RANGE_TOO_LONG'], 'productIds=abc' => ['productIds' => 'INVALID_ID']] as $q => $fields) {
            $this->request('GET', "/api/gestion/locations/statistiques?$q");
            $this->assertSame([422, $fields], [$this->status(), $this->body()['fields'] ?? null], $q);
        }
        $this->request('GET', '/api/gestion/locations/statistiques?periodId=999999999');
        $this->assertSame([404, 'PERIOD_NOT_FOUND'], [$this->status(), $this->body()['error'] ?? null]);
        $this->assertArrayHasKey('range', $this->get('/api/gestion/locations/statistiques'), 'sans plage : période en cours, sinon 30 derniers jours');
    }

    // ----- helpers -----

    /** Location vendue le `$soldOn` (midi, magasin) : ligne de commande + réservation du `$start` au `$end` (heure du magasin, stockée en UTC) */
    private function rental(int $productId, int $variantId, string $soldOn, string $start, string $end, int $qty, int $amount, string $status): void
    {
        $tz = static::getContainer()->get(StoreClock::class)->timezone();
        $utc = fn (string $at) => (new \DateTimeImmutable($at, $tz))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $order = $this->create(Order::class, ['totalAmount' => (float) $amount, 'reference' => 'LOC-TEST'], function (Order $o, EntityManagerInterface $em) use ($soldOn, $tz): void {
            $o->setOrderDate(\DateTime::createFromImmutable((new \DateTimeImmutable("$soldOn 12:00", $tz))->setTimezone(new \DateTimeZone('UTC'))))->setStatus($em->find(StatusCommande::class, 3))
                ->setOrderType($em->find(OrderType::class, 1))->setOrderSource($em->find(OrderSource::class, 1))->setUserId($em->find(User::class, $this->userId));
        });
        $item = $this->create(OrderItems::class, ['quantity' => $qty, 'unitPrice' => (float) ($amount / $qty), 'totalPrice' => (float) $amount], function (OrderItems $i, EntityManagerInterface $em) use ($order, $variantId): void {
            $i->setOrderAssociated($em->find(Order::class, $order->getId()))->setProductVariant($em->find(ProductVariant::class, $variantId));
        });
        $this->em()->getConnection()->executeStatement("INSERT INTO booking (id, product_id, order_item_id, start_at, end_at, quantity, status, created_at) VALUES (nextval('booking_id_seq'), ?, ?, ?, ?, ?, ?, NOW())",
            [$productId, $item->getId(), $utc($start), $utc($end), $qty, $status]);
    }

    private function clean(): void
    {
        $conn = $this->em()->getConnection();
        $conn->executeStatement("DELETE FROM booking WHERE product_id IN (SELECT id FROM product WHERE slug LIKE 'loc-test-%')");
        $conn->executeStatement("DELETE FROM order_items WHERE order_associated_id IN (SELECT id FROM \"order\" WHERE reference = 'LOC-TEST')");
        $conn->executeStatement("DELETE FROM \"order\" WHERE reference = 'LOC-TEST'");
        $conn->executeStatement("DELETE FROM booking_configuration WHERE product_id IN (SELECT id FROM product WHERE slug LIKE 'loc-test-%')");
        $conn->executeStatement("DELETE FROM product_variant WHERE product_id IN (SELECT id FROM product WHERE slug LIKE 'loc-test-%')");
        $conn->executeStatement("DELETE FROM product WHERE slug LIKE 'loc-test-%'");
    }

    private function status(): int
    {
        return $this->client->getResponse()->getStatusCode();
    }

    private function body(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    private function request(string $method, string $route, ?array $body = null): void
    {
        $this->client->request($method, $route, [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $this->token], $body === null ? null : json_encode($body));
    }

    private function get(string $route): array
    {
        $this->request('GET', $route);
        $this->assertSame(200, $this->status(), $route . ' : ' . substr((string) $this->client->getResponse()->getContent(), 0, 300));

        return $this->body();
    }

    private function send(string $method, string $route, array $body, int $expected): array
    {
        $this->request($method, $route, $body);
        $this->assertSame($expected, $this->status(), $route . ' : ' . substr((string) $this->client->getResponse()->getContent(), 0, 400));

        return $this->body();
    }

    protected function em(): EntityManagerInterface
    {
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);

        return $provider->getEntityManager();
    }

    private function endOfWrite(): void
    {
        static::getContainer()->get(CacheInvalidationSubscriber::class)->invalidateWrittenTenants();
    }

    private function login(): void
    {
        $email = 'gestion-loc-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $em = $this->em();
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Gestion')->setLastname('Loc')->setRoles(['ROLE_ADMIN'])->setIsVerified(true)->setPassword('inutilisable');
        $em->persist($user);
        $em->flush();
        $this->userId = (int) $user->getId();
        $this->token = static::getContainer()->get('lexik_jwt_authentication.jwt_manager')->create($user);
        $this->endOfWrite();
    }
}
