<?php

namespace App\Tests\Functional\Boutique;

use App\Entity\BookingConfiguration;
use App\Entity\Categories;
use App\Entity\Product;
use App\Entity\RentalPack;
use App\Services\Booking\BookingClock;
use App\Services\ProductAiService\ProductSheetCreator;
use App\Services\TenantEntityManagerProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Location « prise et retour avec date et heure » (grille `datetime`, 08/11/2026) : tranches de 24 h arrondies au-dessus après la
 * tolérance de retard, formules jour / semaine / heure, pas des heures, heures de prise et de retour dans les heures d'ouverture
 * n'importe quel jour, disponibilité à l'heure près avec battement sur plusieurs jours, fiche produit (timeStepMinutes,
 * returnToleranceMinutes).
 */
class RentalDatetimeApiTest extends WebTestCase
{
    private KernelBrowser $client;
    private \Doctrine\DBAL\Connection $db;
    private int $productId = 0;
    private int $variantId = 0;
    private int $categoryId = 0;
    private int $packId = 0;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $this->db = $provider->getEntityManager()->getConnection();
        $em = $provider->getEntityManager();
        $category = (new Categories())->setName('Pontons date et heure'); // le modèle de test n'a aucune catégorie
        $em->persist($category);
        $em->flush();
        $this->categoryId = (int) $category->getId();
        $created = static::getContainer()->get(ProductSheetCreator::class)->create(['name' => 'Ponton date et heure', 'priceCents' => 10000, 'language' => 'fr', 'modes' => ['sale' => false, 'rental' => true], 'categoryIds' => [$this->categoryId]], 'test')['body'];
        $this->productId = (int) $created['productId'];
        $this->variantId = (int) $created['variantId'];
        $pack = (new RentalPack())->setName('Tarifs ponton')->setHourRate(2000.0)->setDayRate(10000.0)->setWeekRate(50000.0);
        $pack->addCategory($category);
        $em->persist($pack);
        $product = $em->getRepository(Product::class)->find($this->productId);
        $config = (new BookingConfiguration())->setProduct($product)->setGranularity('datetime')->setStockQuantity(1)->setMinDuration(1)->setBufferTime(30)
            ->setOpeningStart('08:00')->setOpeningEnd('20:00')->setReturnToleranceMinutes(60)->setTimeStepMinutes(60);
        $product->setBookingConfiguration($config);
        $em->persist($config);
        $em->flush();
        $this->packId = (int) $pack->getId();
        static::getContainer()->get(\App\Services\TenantCacheService::class)->invalidateTenant();
    }

    protected function tearDown(): void
    {
        $this->db->executeStatement('DELETE FROM booking WHERE product_id = ?', [$this->productId]);
        $this->db->executeStatement('DELETE FROM booking_configuration WHERE product_id = ?', [$this->productId]);
        foreach (['product_variant', 'product_picture', 'product_translation', 'product_categories'] as $t) {
            $this->db->executeStatement("DELETE FROM $t WHERE product_id = ?", [$this->productId]);
        }
        $this->db->executeStatement('DELETE FROM product WHERE id = ?', [$this->productId]);
        $this->db->executeStatement('DELETE FROM rental_pack_categories WHERE rental_pack_id = ?', [$this->packId]);
        $this->db->executeStatement('DELETE FROM rental_pack WHERE id = ?', [$this->packId]);
        $this->db->executeStatement('DELETE FROM categories WHERE id = ?', [$this->categoryId]);
        static::getContainer()->get(\App\Services\TenantCacheService::class)->invalidateTenant();
        parent::tearDown();
    }

    public function testPickupAndReturnWithDateAndTimeOverSeveralDays(): void
    {
        $d = (new \DateTimeImmutable('+30 days'))->setTime(0, 0);
        $at = fn (int $plusDays, string $time) => $d->modify("+$plusDays days")->format('Y-m-d') . 'T' . $time . ':00';
        $quote = fn (string $start, string $end, array $extra = []) => $this->quote(['items' => [['productVariantId' => $this->variantId, 'quantity' => 1, 'booking' => ['start' => $start, 'end' => $end] + $extra]]]);

        // Fiche produit : la grille et ses réglages sont exposés
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/api/products/' . $this->productId, [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'HTTP_ACCEPT' => 'application/json']);
        $rental = json_decode((string) $this->client->getResponse()->getContent(), true)['rental'];
        $this->assertSame(['datetime', 60, 60, ['start' => '08:00', 'end' => '20:00']], [$rental['granularity'], $rental['timeStepMinutes'], $rental['returnToleranceMinutes'], $rental['openingHours']]);

        // Du 2 à 9 h au 9 à 10 h = 169 h ; tolérance 60 min → 168 h facturées → 7 jours ; à la semaine → 1 semaine
        $seven = $quote($at(0, '09:00'), $at(7, '10:00'));
        $this->assertSame(200, $seven['status'], json_encode($seven['body']));
        $this->assertSame(['day', 7, 10000, 70000], [$seven['body']['lines'][0]['booking']['durationType'], $seven['body']['lines'][0]['booking']['units'], $seven['body']['lines'][0]['booking']['rate'], $seven['body']['lines'][0]['unitPrice']], '169 h avec 60 min de tolérance = 7 jours');
        $week = $quote($at(0, '09:00'), $at(7, '10:00'), ['durationType' => 'week'])['body'];
        $this->assertSame([1, 50000], [$week['lines'][0]['booking']['units'], $week['lines'][0]['unitPrice']]);

        // 170 h → 169 h facturées → 8 jours ; 16 h → 1 jour, ou 15 h à l'heure
        $eight = $quote($at(0, '09:00'), $at(7, '11:00'))['body'];
        $this->assertSame([8, 80000], [$eight['lines'][0]['booking']['units'], $eight['lines'][0]['unitPrice']], 'une heure au-delà de la tolérance = un jour de plus');
        $one = $quote($at(0, '18:00'), $at(1, '10:00'))['body'];
        $this->assertSame([1, 10000], [$one['lines'][0]['booking']['units'], $one['lines'][0]['unitPrice']], '16 h = 1 jour');
        $hourly = $quote($at(0, '18:00'), $at(1, '10:00'), ['durationType' => 'hour'])['body'];
        $this->assertSame([15, 30000], [$hourly['lines'][0]['booking']['units'], $hourly['lines'][0]['unitPrice']], '16 h moins la tolérance = 15 h à l\'heure');

        // Pas des heures, heures de prise et de retour
        $codes = fn (array $r) => array_map(fn ($e) => [$e['path'], $e['code']], $r['body']['errors'] ?? []);
        $this->assertContains(['items[0].booking.end', 'TIME_STEP_INVALID'], $codes($quote($at(0, '09:00'), $at(7, '10:30'))));
        $this->assertContains(['items[0].booking.start', 'OUTSIDE_PICKUP_HOURS'], $codes($quote($at(0, '06:00'), $at(7, '10:00'))));
        $this->assertContains(['items[0].booking.end', 'OUTSIDE_RETURN_HOURS'], $codes($quote($at(0, '09:00'), $at(7, '21:00'))));
        $refused = $quote($at(0, '06:00'), $at(7, '21:00'));
        $this->assertSame(422, $refused['status']);
        $this->assertSame(['OUTSIDE_PICKUP_HOURS', 'OUTSIDE_RETURN_HOURS'], array_column($refused['body']['errors'], 'code'));
        $this->assertSame(['start' => '08:00', 'end' => '20:00'], $refused['body']['errors'][0]['params']);

        // Disponibilité à l'heure près sur plusieurs jours, avec le battement de 30 min : un retour le 9 à 10 h libère à 10 h 30
        $clock = static::getContainer()->get(BookingClock::class);
        $this->db->executeStatement("INSERT INTO booking (id, product_id, start_at, end_at, quantity, status, is_finalized, created_at) VALUES (nextval('booking_id_seq'), ?, ?, ?, 1, 'confirmed', TRUE, NOW())",
            [$this->productId, BookingClock::utc($clock->parse($at(0, '09:00')))->format('Y-m-d H:i:s'), BookingClock::utc($clock->parse($at(7, '10:00')))->format('Y-m-d H:i:s')]);
        static::getContainer()->get(\App\Services\TenantCacheService::class)->invalidateTenant();
        $check = function (string $start, string $end): int {
            $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/api/booking/check/' . $this->productId . '?' . http_build_query(['start' => $start, 'end' => $end]), [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);

            return $this->client->getResponse()->getStatusCode();
        };
        $this->assertSame(409, $check($at(7, '10:00'), $at(8, '10:00')), 'retour à 10 h + 30 min de battement : 10 h encore pris');
        $this->assertSame(200, $check($at(7, '11:00'), $at(8, '10:00')), 'libre à 11 h');
        $this->assertSame(409, $check($at(3, '09:00'), $at(4, '09:00')), 'pris au milieu de la location');
        $this->assertContains(['items[0].quantity', 'DATES_UNAVAILABLE'], $codes($quote($at(7, '10:00'), $at(8, '10:00'))));
        $this->assertSame(200, $quote($at(7, '11:00'), $at(8, '10:00'))['status']);

        // Jours minimum facturés sur la grille datetime (question de Next et de l'application mobile, 08/11/2026) : après la tolérance
        $this->db->executeStatement('UPDATE booking_configuration SET min_days_standard = 2 WHERE product_id = ?', [$this->productId]);
        static::getContainer()->get(TenantEntityManagerProvider::class)->getEntityManager()->clear();
        static::getContainer()->get(\App\Services\TenantCacheService::class)->invalidateTenant();
        $short = $quote($at(10, '09:00'), $at(11, '10:00'));
        $this->assertSame(422, $short['status'], '25 h moins 60 min = 1 jour facturé : moins que 2');
        $this->assertContains(['items[0].booking.end', 'DAYS_MINIMUM'], $codes($short));
        $this->assertSame(['value' => 2], array_column($short['body']['errors'], 'params', 'code')['DAYS_MINIMUM']);
        $this->assertSame(200, $quote($at(10, '09:00'), $at(12, '10:00'))['status'], '49 h moins 60 min = 2 jours facturés');
    }

    /** @return array{status: int, body: array} */
    private function quote(array $body): array
    {
        $this->client->getCookieJar()->clear();
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . '/api/cart/quote', [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'HTTP_X_XSRF_TOKEN' => 'jeton-xsrf-de-test'], json_encode($body));

        return ['status' => $this->client->getResponse()->getStatusCode(), 'body' => json_decode((string) $this->client->getResponse()->getContent(), true) ?? []];
    }
}
