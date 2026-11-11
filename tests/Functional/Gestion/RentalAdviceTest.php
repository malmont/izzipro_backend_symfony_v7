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
use App\Gestion\Services\AdviceMeasure;
use App\Gestion\Services\AdviceRules;
use App\Services\TenantEntityManagerProvider;
use App\Tests\Functional\Cache\CacheTestEntityFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Lot LOC2, règles de location du guide (11/11/2026, `docs/locations-gestion.md` § 2) : RENT1 jours pleins (samedis loués à 88,9 % sur 8 semaines
 * → hausse de 10 % ces jours-là), RENT2 semaine creuse (occupation sous 25 %, lundi à jeudi sous 15 % → promotion de 20 %, potentiel), RENT3
 * article qui ne se loue plus (aucune location en 60 jours, revenu de la même fenêtre l'an dernier), mesure après application sur le revenu de
 * location par date de location. « Aujourd'hui » fixé au lundi 30/06/2008, période de juin à août 2008 ; données de 2007 et 2008 supprimées après.
 */
class RentalAdviceTest extends WebTestCase
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
        $this->em()->getConnection()->executeStatement("DELETE FROM collections WHERE nom_collection = 'LOC2 été'");
        parent::tearDown();
    }

    public function testRentalRulesAndMeasure(): void
    {
        $conn = $this->em()->getConnection();
        $tag = bin2hex(random_bytes(2));
        $make = function (string $letter, string $granularity, string $created) use ($conn, $tag): array {
            $conn->executeStatement("INSERT INTO product (id, name, price, isbestseller, quantity, created_at, slug, product_type) VALUES (nextval('product_id_seq'), ?, 1000, false, 0, ?, ?, 'standard')", ["Loc2 $letter", $created, "loc2-test-$letter-$tag"]);
            $pid = (int) $conn->fetchOne('SELECT id FROM product WHERE slug = ?', ["loc2-test-$letter-$tag"]);
            $conn->executeStatement("INSERT INTO booking_configuration (id, product_id, granularity, stock_quantity, min_duration, buffer_time, opening_start, opening_end) VALUES (nextval('booking_configuration_id_seq'), ?, ?, 1, 1, 0, '09:00', '18:00')", [$pid, $granularity]);
            $vid = (int) $this->create(ProductVariant::class, ['product' => $this->em()->find(Product::class, $pid)])->getId();

            return [$pid, $vid];
        };
        [$k, $kv] = $make('K', 'hours', '2007-01-01 00:00:00');
        [$i, $iv] = $make('I', 'days', '2007-01-01 00:00:00');
        [$r] = $make('R', 'days', '2008-06-15 00:00:00'); // louable depuis 15 jours seulement : pas encore « ne se loue plus »
        [$j, $jv] = $make('J', 'days', '2029-01-01 00:00:00');
        // K : les 8 samedis du 10/05 au 28/06/2008, 9 h-17 h (8 h sur 9 d'ouverture), 80,00 $ chacun ; I : une location en mai 2007 seulement
        for ($d = new \DateTimeImmutable('2008-05-10'); $d <= new \DateTimeImmutable('2008-06-28'); $d = $d->modify('+7 days')) {
            $day = $d->format('Y-m-d');
            $this->rental($k, $kv, $day, "$day 09:00", "$day 17:00", 1, 8000, 'CONFIRMED');
        }
        $this->rental($i, $iv, '2007-05-09', '2007-05-10 10:00', '2007-05-12 10:00', 1, 6000, 'CONFIRMED');
        $this->rental($j, $jv, '2029-05-09', '2029-05-10 10:00', '2029-05-11 10:00', 1, 3000, 'CONFIRMED');
        $period = (int) $conn->fetchOne("INSERT INTO collections (id, budget_collection, start_date_collection, end_date_collection, del, nom_collection, is_closed, kind, period_key) VALUES (nextval('collections_id_seq'), 0, '2008-06-01', '2008-08-31', false, 'LOC2 été', false, 'period', ?) RETURNING id", ['loc2-' . $tag]);
        $this->endOfWrite();

        $rules = static::getContainer()->get(AdviceRules::class);
        // période passée (fermée par la date) : les règles de location sont appelées directement, comme detect() le fait aux moments « selling » et « end »
        $found = (new \ReflectionMethod($rules, 'rentalRules'))->invoke($rules, $this->em()->find(\App\Entity\Collections::class, $period), new \DateTimeImmutable('2008-06-30'));
        $mine = [];
        foreach ($found as $a) {
            if (str_starts_with($a['kind'], 'RENTAL_') && array_intersect($a['subject']['productIds'], [$k, $i, $r]) !== []) {
                $mine[$a['signature']] = $a;
            }
        }
        $this->assertEqualsCanonicalizing(["RENTAL_PEAK_DAYS:p$k", "RENTAL_LOW_WEEKDAYS:p$k", "RENTAL_IDLE:p$i"], array_keys($mine));
        $peak = $mine["RENTAL_PEAK_DAYS:p$k"];
        $this->assertSame([7200, 'extra_rental_revenue', 'medium', [6], 10], [$peak['effect']['amount'], $peak['effect']['label'], $peak['confidence'], $peak['params']['weekdays'], $peak['action']['params']['percent']],
            '8 h par samedi × 9 samedis restants × 10,00 $ de l\'heure × 10 %');
        $this->assertEquals([['occupancy', 88.9], ['rentals', 8], ['price_increase', 10]], array_map(fn ($s) => [$s['key'], $s['value']], $peak['sources']));
        $low = $mine["RENTAL_LOW_WEEKDAYS:p$k"];
        $this->assertSame([259200, 'rental_potential', 'low', [1, 2, 3, 4]], [$low['effect']['amount'], $low['effect']['label'], $low['confidence'], $low['action']['params']['weekdays']], '9 h × 36 jours du lundi au jeudi restants × 10,00 $ × 80 %');
        $this->assertEquals([14.8, 0.0], [$low['sources'][0]['value'], $low['sources'][1]['value']]);
        $idle = $mine["RENTAL_IDLE:p$i"];
        $this->assertSame([6000, 'rental_revenue_last_year', 'low'], [$idle['effect']['amount'], $idle['effect']['label'], $idle['confidence']], 'même fenêtre l\'an dernier');
        $texts = static::getContainer()->get(\App\Gestion\Services\GestionAdviceService::class)->fallback('RENTAL_PEAK_DAYS', $peak['sources'], $peak['params'], $peak['effect']['amount'], 'fr');
        $this->assertStringContainsString('le samedi', $texts[1]);

        // detect() appelle les règles de location aux moments « selling » et « end » (période ouverte de 2030)
        $open = (int) $conn->fetchOne("INSERT INTO collections (id, budget_collection, start_date_collection, end_date_collection, del, nom_collection, is_closed, kind, period_key) VALUES (nextval('collections_id_seq'), 0, '2030-06-01', '2030-08-31', false, 'LOC2 été', false, 'period', ?) RETURNING id", ['loc2b-' . $tag]);
        $detected = $rules->detect($this->em()->find(\App\Entity\Collections::class, $open), new \DateTimeImmutable('2030-06-30'));
        $this->assertContains("RENTAL_IDLE:p$j", array_column($detected['advices'], 'signature'));

        // Mesure après application : revenu de location par date de location, 14 jours avant et après le 11/05/2007
        $m = static::getContainer()->get(AdviceMeasure::class)->measure(['id' => 1, 'kind' => 'RENTAL_IDLE', 'applied' => null, 'subject' => json_encode(['productIds' => [$i]]), 'applied_on' => '2007-05-11',
            'done_at' => null, 'effect_cents' => 6000, 'params' => json_encode(['name' => 'Loc2 I']), 'status' => 'done'], 'fr', new \DateTimeImmutable('2007-06-30'));
        $this->assertSame([true, 1750, 4250, 2500], [$m['measurable'], $m['before']['revenue'], $m['after']['revenue'], $m['change']['revenue']], '48 h louées : 14 h avant le 11/05, 34 h après');
        $this->assertStringContainsString('revenu de location', $m['text']);
    }

    // ----- helpers -----

    /** Location vendue le `$soldOn` (midi, magasin) : ligne de commande + réservation du `$start` au `$end` (heure du magasin, stockée en UTC) */
    private function rental(int $productId, int $variantId, string $soldOn, string $start, string $end, int $qty, int $amount, string $status): void
    {
        $tz = static::getContainer()->get(StoreClock::class)->timezone();
        $utc = fn (string $at) => (new \DateTimeImmutable($at, $tz))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $order = $this->create(Order::class, ['totalAmount' => (float) $amount, 'reference' => 'LOC2-TEST'], function (Order $o, EntityManagerInterface $em) use ($soldOn, $tz): void {
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
        $conn->executeStatement("DELETE FROM booking WHERE product_id IN (SELECT id FROM product WHERE slug LIKE 'loc2-test-%')");
        $conn->executeStatement("DELETE FROM order_items WHERE order_associated_id IN (SELECT id FROM \"order\" WHERE reference = 'LOC2-TEST')");
        $conn->executeStatement("DELETE FROM \"order\" WHERE reference = 'LOC2-TEST'");
        $conn->executeStatement("DELETE FROM booking_configuration WHERE product_id IN (SELECT id FROM product WHERE slug LIKE 'loc2-test-%')");
        $conn->executeStatement("DELETE FROM product_variant WHERE product_id IN (SELECT id FROM product WHERE slug LIKE 'loc2-test-%')");
        $conn->executeStatement("DELETE FROM product WHERE slug LIKE 'loc2-test-%'");
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
        $email = 'gestion-loc2-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $em = $this->em();
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Gestion')->setLastname('Loc')->setRoles(['ROLE_ADMIN'])->setIsVerified(true)->setPassword('inutilisable');
        $em->persist($user);
        $em->flush();
        $this->userId = (int) $user->getId();
        $this->token = static::getContainer()->get('lexik_jwt_authentication.jwt_manager')->create($user);
        $this->endOfWrite();
    }
}
