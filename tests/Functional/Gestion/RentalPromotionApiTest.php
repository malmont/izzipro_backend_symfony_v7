<?php

namespace App\Tests\Functional\Gestion;

use App\Entity\User;
use App\Services\BoutiqueDemoService\BoutiqueDemoSeeder;
use App\Services\Booking\BookingClock;
use App\Services\TenantEntityManagerProvider;
use App\Tests\Fake\FakeCheckoutPaymentGateway;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Lot LOC3, promotion de location par article (11/11/2026, `docs/locations-gestion.md` § 3, plan validé par l'application) : sans promotion qui
 * s'applique, devis de la boutique et de la caisse identiques au centime et à la clé près ; remise sur tarif × unités seulement (jamais le
 * supplément passagers ni la caution) selon le jour de DÉBUT au fuseau de la boutique (départ à 22 h le vendredi) ; promotion qui commence plus
 * tard ; même remise en boutique, en caisse, dans le paiement Stripe et dans la commande figée ; remise de caisse F1 après la promotion ;
 * aperçu, empreinte, chevauchement, article non louable, fin, lots ; rental.promotion de la fiche. Ponton du jeu d'exemple (9 500 par jour,
 * 2 jours minimum, 2 500 par passager, caution 150 000).
 */
class RentalPromotionApiTest extends WebTestCase
{
    private const PASSWORD = 'Mot-de-passe-de-test-1!';
    private KernelBrowser $client;
    private \Doctrine\DBAL\Connection $db;
    private ?string $token = null;
    private string $adminToken = '';
    private int $register;
    private int $ponton;
    private int $pontonProduct;
    private int $kayakProduct;
    private int $capProduct;
    private \DateTimeZone $tz;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        FakeCheckoutPaymentGateway::reset();
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $this->db = $provider->getEntityManager()->getConnection();
        static::getContainer()->get(BoutiqueDemoSeeder::class)->seed('client-promo-location@example.invalid', false, false);
        $this->db->executeStatement('DELETE FROM boutique_setting');
        $this->db->executeStatement("UPDATE address_entreprise SET country = 'CA', state = 'QC', city = 'Montréal', zip = 'H1M 0A9'");
        $this->db->executeStatement("UPDATE entreprise SET currency = 'CAD', pos_timezone = NULL, pos_discrepancy_threshold = 0, pos_cash_rounding_step = NULL, pos_approval_refund = 0, pos_approval_withdrawal = NULL, pos_approval_fund_withdrawal = NULL,
            pos_pay_cash = true, pos_pay_declared_card = true, pos_pay_paypal = true, pos_pay_multi = true, pos_declared_card_blocked_with_terminal = false, pos_offline_enabled = false, prices_include_tax = false");
        $this->db->executeStatement('UPDATE caisse SET is_open = false, closed_at = NOW() WHERE is_open = true');
        $this->db->executeStatement('UPDATE cash_register SET is_active = (id = (SELECT MIN(id) FROM cash_register)), terminal_enabled = false');
        $this->db->executeStatement('DELETE FROM rental_promotion');
        $this->db->executeStatement('DELETE FROM rental_promotion_batch');
        $this->db->executeStatement('DELETE FROM pos_rental_deposit');
        $this->db->executeStatement("DELETE FROM booking WHERE product_id IN (SELECT id FROM product WHERE code IN ('DEMO-PONTON', 'DEMO-KAYAK'))");
        $this->db->executeStatement('UPDATE product SET is_pos = true');
        $this->register = (int) $this->db->fetchOne('SELECT MIN(id) FROM cash_register');
        $this->pontonProduct = (int) $this->db->fetchOne("SELECT id FROM product WHERE code = 'DEMO-PONTON'");
        $this->ponton = (int) $this->db->fetchOne('SELECT id FROM product_variant WHERE product_id = ? ORDER BY id LIMIT 1', [$this->pontonProduct]);
        $this->kayakProduct = (int) $this->db->fetchOne("SELECT id FROM product WHERE code = 'DEMO-KAYAK'");
        $this->capProduct = (int) $this->db->fetchOne("SELECT id FROM product WHERE code = 'DEMO-CASQUETTE'");
        $this->tz = static::getContainer()->get(BookingClock::class)->timezone();
        $admin = $this->user(['ROLE_ADMIN']);
        $this->adminToken = static::getContainer()->get('lexik_jwt_authentication.jwt_manager')->create($admin);
    }

    protected function tearDown(): void
    {
        $this->db->executeStatement('DELETE FROM rental_promotion');
        $this->db->executeStatement('DELETE FROM rental_promotion_batch');
        $this->db->executeStatement('UPDATE entreprise SET pos_timezone = NULL'); // le cas d'Auckland ne doit pas fuir dans les tests suivants
        parent::tearDown();
    }

    public function testPromotionPricesTheSameEverywhereAndOnlyWhenItApplies(): void
    {
        $sat = (new \DateTimeImmutable('today', $this->tz))->modify('+14 days')->modify('next saturday'); // un samedi dans plus de deux semaines
        $d = fn (int $n) => $sat->modify("$n days")->format('Y-m-d');
        $booking = fn (string $day, string $time = '09:00', int $days = 3, int $passengers = 2) => ['start' => "{$day}T$time:00", 'end' => (new \DateTimeImmutable("$day $time", $this->tz))->modify("+$days days")->format('Y-m-d\TH:i:s'), 'passengers' => $passengers];
        $web = fn (array $b) => $this->json($this->request('POST', '/api/cart/quote', ['items' => [['productVariantId' => $this->ponton, 'quantity' => 1, 'booking' => $b]], 'shippingAddress' => ['country' => 'CA', 'province' => 'QC']]));
        $this->loginPos();
        $pos = fn (array $b, array $extra = []) => $this->json($this->request('POST', '/api/pos/quote', ['items' => [['productVariantId' => $this->ponton, 'quantity' => 1, 'booking' => $b] + $extra], 'cashRegisterId' => $this->register]));

        // (a) Photo des devis sans promotion qui s'applique
        $web0 = $web($booking($d(0)));
        $pos0 = $pos($booking($d(0)));
        $this->assertSame([9500 * 3 + 5000, 150000], [$web0['lines'][0]['unitPrice'], $web0['deposit']]);
        $this->assertArrayNotHasKey('promotion', $web0['lines'][0]['booking']);
        // promotion sur un autre article, et sur le ponton un mercredi seulement : le devis du samedi ne bouge ni d'un centime ni d'une clé
        $this->applyPromotion([$this->kayakProduct], 30, $d(-7), $d(30), [1, 2, 3, 4, 5, 6, 7]);
        $this->applyPromotion([$this->pontonProduct], 40, $d(-7), $d(30), [3]);
        $this->assertSame($web0, $web($booking($d(0))), 'boutique : devis identique');
        $this->assertSame($pos0, $pos($booking($d(0))), 'caisse : devis identique');

        // (d) promotion du samedi, 20 % : tarif × unités remisé, supplément passagers et caution intacts ; même ligne en caisse
        $sat20 = $this->applyPromotion([$this->pontonProduct], 20, $d(-7), $d(30), [6]);
        $w = $web($booking($d(0)));
        $line = $w['lines'][0];
        $this->assertSame([28500 - 5700 + 5000, 150000], [$line['unitPrice'], $w['deposit']]);
        $this->assertSame(['id' => $sat20['promotions'][0]['id'], 'percent' => 20, 'amount' => 5700, 'catalogUnitPrice' => 33500], $line['booking']['promotion']);
        $p = $pos($booking($d(0)));
        $this->assertSame([$line['unitPrice'], $line['booking']['promotion']], [$p['lines'][0]['unitPrice'], $p['lines'][0]['booking']['promotion']], 'caisse : même remise');
        $this->assertSame((int) round(($w['subtotal']) * 0.05) + (int) round($w['subtotal'] * 0.09975) + $w['subtotal'], $w['total'], 'taxes sur le prix remisé');

        // (e) départ un mardi (non coché) : rien
        $tue = $web($booking($d(3)));
        $this->assertSame(33500, $tue['lines'][0]['unitPrice']);
        $this->assertArrayNotHasKey('promotion', $tue['lines'][0]['booking']);

        // Fuseau : départ à 22 h le vendredi (le samedi en UTC) → vendredi, non coché : rien ; promotion du vendredi à 10 % → 10 %, en boutique et en caisse
        $friLate = $booking($d(-1), '22:00', 3, 0);
        $this->assertArrayNotHasKey('promotion', $web($friLate)['lines'][0]['booking'], 'le jour de début se lit au fuseau de la boutique');
        $this->applyPromotion([$this->pontonProduct], 10, $d(-7), $d(30), [5]);
        $this->assertSame(10, $web($friLate)['lines'][0]['booking']['promotion']['percent']);
        $this->assertSame(10, $pos($friLate)['lines'][0]['booking']['promotion']['percent']);
        $satEarly = $booking($d(0), '00:30', 3, 0); // samedi 0 h 30 au magasin (vendredi soir dans des fuseaux à l'ouest de UTC… et samedi ici)
        $this->assertSame(20, $web($satEarly)['lines'][0]['booking']['promotion']['percent']);

        // Promotion qui commence plus tard (sans écriture entre-temps) : le devis la prend à partir de son jour
        $this->applyPromotion([$this->pontonProduct], 15, $d(16), $d(40), [2]);
        $this->assertSame(15, $web($booking($d(17)))['lines'][0]['booking']['promotion']['percent'], 'mardi dans la fenêtre à venir');
        $this->assertArrayNotHasKey('promotion', $web($booking($d(3)))['lines'][0]['booking'], 'mardi avant la fenêtre');

        // (3) Paiement Stripe de la boutique : même montant que le devis remisé
        $intent = $this->json($this->request('POST', '/api/stripe/create-intent', ['items' => [['productVariantId' => $this->ponton, 'quantity' => 1, 'booking' => $booking($d(0))]], 'shippingAddress' => ['country' => 'CA', 'province' => 'QC']]));
        $this->assertSame($w['total'], $intent['calculatedAmount']);
        $this->assertSame($line['booking']['promotion'], $intent['quote']['lines'][0]['booking']['promotion']);

        // (f) Vente en caisse avec promotion : prix figé dans la ligne de commande ; terminer la promotion ne la change pas
        $manager = $this->loginPos(['ROLE_USER_POS', 'ROLE_POS_MANAGER']);
        $this->request('POST', '/api/caisse/open', ['cashRegisterId' => $this->register, 'cashFund' => 50000]);
        $items = [['productVariantId' => $this->ponton, 'quantity' => 1, 'booking' => $booking($d(7))]]; // samedi suivant
        $quote = $this->json($this->request('POST', '/api/pos/quote', ['items' => $items, 'cashRegisterId' => $this->register]));
        $this->assertSame(27800, $quote['lines'][0]['unitPrice']);
        $sale = $this->json($this->request('POST', '/api/order/create-multi-payment', ['items' => $items, 'paymentMethods' => [['type' => 2, 'amount' => $quote['cashTotal'] / 100]], 'cashRegisterId' => $this->register, 'deposit' => ['amount' => 150000, 'method' => 'cash']]), 201);
        $orderId = (int) ($sale['orderId'] ?? $sale['order']['id'] ?? $sale['id']);
        $this->assertSame(27800.0, (float) $this->db->fetchOne('SELECT total_price FROM order_items WHERE order_associated_id = ?', [$orderId]));
        $this->send('POST', '/api/gestion/locations/promotions/terminer', ['batchId' => $sat20['id']], 200);
        $this->assertSame(27800.0, (float) $this->db->fetchOne('SELECT total_price FROM order_items WHERE order_associated_id = ?', [$orderId]), 'commande figée');

        // (L3c) Remise de caisse F1 : la caisse ne remise jamais une ligne de location (règle F1 existante, inchangée) ; sur un ticket mixte, la
        // location garde son prix promotionnel et la remise F1 porte sur l'article vendu ; total au centime
        $cap = (int) $this->db->fetchOne('SELECT id FROM product_variant WHERE product_id = ? ORDER BY id LIMIT 1', [$this->capProduct]);
        $this->db->executeStatement("UPDATE product SET price = 2900, special_price = NULL WHERE id = ?", [$this->capProduct]);
        $this->db->executeStatement('UPDATE product_variant SET stock_quantity = 50, price = NULL WHERE id = ?', [$cap]);
        $mixed = $this->json($this->request('POST', '/api/pos/quote', ['cashRegisterId' => $this->register, 'items' => [
            ['productVariantId' => $this->ponton, 'quantity' => 1, 'booking' => $booking($d(-1), '22:00', 3, 0), 'discounts' => [['type' => 'percent', 'value' => 10, 'reasonCode' => 'fidelite']]],
            ['productVariantId' => $cap, 'quantity' => 1, 'discounts' => [['type' => 'percent', 'value' => 10, 'reasonCode' => 'fidelite']]],
        ]]));
        $net = 28500 - 2850; // promotion 10 % du vendredi
        $this->assertSame([$net, $net, 10], [$mixed['lines'][0]['unitPrice'], $mixed['lines'][0]['total'], $mixed['lines'][0]['booking']['promotion']['percent']], 'location : prix promotionnel, sans remise F1');
        $this->assertSame(2900 - 290, $mixed['lines'][1]['total'], 'article vendu : remise F1 de 10 %');
        $subtotal = $net + 2610;
        $this->assertSame($subtotal, $mixed['subtotal']);
        $this->assertSame($subtotal + array_sum(array_column($mixed['taxes'], 'amount')), $mixed['total']);
        $this->assertSame([(int) round($subtotal * 0.05), (int) round($subtotal * 0.09975)], array_column($mixed['taxes'], 'amount'), 'total au centime');
    }

    public function testGestionPreviewApplyOverlapEndAndProductSheet(): void
    {
        $start = (new \DateTimeImmutable('today', $this->tz))->modify('+3 days')->format('Y-m-d');
        $end = (new \DateTimeImmutable('today', $this->tz))->modify('+30 days')->format('Y-m-d');
        foreach ([[['percent' => 3, 'endDate' => $end, 'productIds' => [$this->pontonProduct]], ['percent' => 'OUT_OF_RANGE']],
            [['percent' => 20, 'productIds' => [$this->pontonProduct]], ['endDate' => 'REQUIRED']],
            [['percent' => 20, 'startDate' => '2000-01-01', 'endDate' => '2000-01-10', 'productIds' => [$this->pontonProduct]], ['startDate' => 'DATE_IN_PAST']],
            [['percent' => 20, 'startDate' => $start, 'endDate' => (new \DateTimeImmutable($start))->modify('+120 days')->format('Y-m-d'), 'productIds' => [$this->pontonProduct]], ['endDate' => 'TOO_LONG']],
            [['percent' => 20, 'endDate' => $end, 'weekdays' => [0, 8], 'productIds' => [$this->pontonProduct]], ['weekdays' => 'INVALID_WEEKDAYS']],
            [['percent' => 20, 'endDate' => $end], ['productIds' => 'SCOPE_REQUIRED']]] as [$body, $fields]) {
            $this->adminRequest('POST', '/api/gestion/locations/promotions/apercu', $body);
            $this->assertSame([422, $fields], [$this->client->getResponse()->getStatusCode(), json_decode($this->client->getResponse()->getContent(), true)['fields'] ?? null], json_encode($body));
        }
        // Aperçu : aucune écriture, exemple de tarif ; article non louable refusé à l'application
        $body = ['percent' => 25, 'startDate' => $start, 'endDate' => $end, 'weekdays' => [6, 7], 'productIds' => [$this->pontonProduct, $this->capProduct]];
        $preview = $this->send('POST', '/api/gestion/locations/promotions/apercu', $body, 200);
        $this->assertSame([['ok' => 1, 'notRentable' => 1, 'overlap' => 0], ['durationType' => 'day', 'rate' => 9500, 'discounted' => 7125]], [$preview['counts'], $preview['items'][0]['example']]);
        $this->assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM rental_promotion'), 'aperçu : aucune écriture');
        $this->adminRequest('POST', '/api/gestion/locations/promotions/appliquer', $body + ['previewHash' => $preview['previewHash']]);
        $this->assertSame([422, 'NOT_RENTABLE'], [$this->client->getResponse()->getStatusCode(), json_decode($this->client->getResponse()->getContent(), true)['error']]);
        $this->adminRequest('POST', '/api/gestion/locations/promotions/appliquer', ['previewHash' => 'x'] + $body);
        $this->assertSame(409, $this->client->getResponse()->getStatusCode(), 'empreinte périmée');
        // Application tout ou rien, puis chevauchement refusé (mêmes jours) ; un autre jour passe
        $batch = $this->applyPromotion([$this->pontonProduct], 25, $start, $end, [6, 7]);
        $this->assertSame([25, [6, 7], 'upcoming'], [$batch['percent'], $batch['weekdays'], $batch['promotions'][0]['status']]);
        $overlap = $this->send('POST', '/api/gestion/locations/promotions/apercu', ['percent' => 10, 'startDate' => $start, 'endDate' => $end, 'weekdays' => [7], 'productIds' => [$this->pontonProduct]], 200);
        $this->assertSame(['PROMOTION_OVERLAP', [$batch['promotions'][0]['id']]], [$overlap['items'][0]['status'], $overlap['items'][0]['conflicts']]);
        $this->adminRequest('POST', '/api/gestion/locations/promotions/appliquer', ['percent' => 10, 'startDate' => $start, 'endDate' => $end, 'weekdays' => [7], 'productIds' => [$this->pontonProduct], 'previewHash' => $overlap['previewHash']]);
        $this->assertSame([409, 'PROMOTION_OVERLAP'], [$this->client->getResponse()->getStatusCode(), json_decode($this->client->getResponse()->getContent(), true)['error']]);
        $this->assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM rental_promotion'), 'rien de plus');
        // Fiche de l'article : rental.promotion (jours du magasin et instants UTC)
        $this->loginPos();
        $rentals = $this->json($this->request('GET', '/api/pos/rentals'));
        $sheet = array_values(array_filter($rentals, fn ($r) => $r['code'] === 'DEMO-PONTON'))[0];
        $this->assertSame([25, $start, $end, [6, 7]], [$sheet['rental']['promotion']['percent'], $sheet['rental']['promotion']['startDate'], $sheet['rental']['promotion']['endDate'], $sheet['rental']['promotion']['weekdays']]);
        $this->assertSame(BookingClock::utc(new \DateTimeImmutable("$start 00:00:00", $this->tz))->format(\DateTimeInterface::ATOM), $sheet['rental']['promotion']['startsAt']);
        $this->assertSame(BookingClock::utc((new \DateTimeImmutable("$end 00:00:00", $this->tz))->modify('+1 day'))->format(\DateTimeInterface::ATOM), $sheet['rental']['promotion']['endsAt'], 'fin exclusive : minuit du magasin après le dernier jour');
        $kayak = array_values(array_filter($rentals, fn ($r) => $r['code'] === 'DEMO-KAYAK'))[0];
        $this->assertArrayNotHasKey('promotion', $kayak['rental'], 'sans promotion : clé absente');
        // Liste, lots, fin d'un lot à venir (ne s'appliquera jamais), fin en double refusée
        $this->assertSame([$batch['promotions'][0]['id']], array_column($this->send('GET', '/api/gestion/locations/promotions', null, 200), 'id'));
        $ended = $this->send('POST', '/api/gestion/locations/promotions/terminer', ['batchId' => $batch['id']], 200);
        $this->assertSame('cancelled', $ended['promotions'][0]['status']);
        $after = array_values(array_filter($this->json($this->request('GET', '/api/pos/rentals')), fn ($r) => $r['code'] === 'DEMO-PONTON'))[0];
        $this->assertArrayNotHasKey('promotion', $after['rental'], 'lot terminé avant son début : plus rien sur la fiche');
        $this->assertSame([], $this->send('GET', '/api/gestion/locations/promotions', null, 200));
        $this->adminRequest('POST', '/api/gestion/locations/promotions/terminer', ['batchId' => $batch['id']]);
        $this->assertSame([409, 'ALREADY_ENDED'], [$this->client->getResponse()->getStatusCode(), json_decode($this->client->getResponse()->getContent(), true)['error']]);
        $this->assertSame([$batch['id']], array_column($this->send('GET', '/api/gestion/locations/promotions/lots', null, 200), 'id'));
    }

    /**
     * Correction LOC3 (11/11/2026) : à la fin d'une promotion, endDate suit endsAt (invariant endsAt = minuit du magasin le lendemain de endDate) ;
     * en cours : dernier jour couvert = aujourd'hui ; pas commencée : plage vide, endDate = startDate − 1 jour ; le lot garde ses dates prévues.
     */
    public function testEndingAPromotionAlignsEndDateWithEndsAt(): void
    {
        $today = new \DateTimeImmutable('today', $this->tz);
        $day = fn (int $n) => $today->modify("+$n days")->format('Y-m-d');
        $midnightAfter = fn (string $d) => BookingClock::utc((new \DateTimeImmutable("$d 00:00:00", $this->tz))->modify('+1 day'))->format(\DateTimeInterface::ATOM);

        // terminée avant sa date de fin : endDate = aujourd'hui, endsAt = ce soir minuit ; toujours appliquée jusque-là, fiche comprise
        $active = $this->applyPromotion([$this->pontonProduct], 15, $day(0), $day(20), [1, 2, 3, 4, 5, 6, 7]);
        $ended = $this->send('POST', '/api/gestion/locations/promotions/terminer', ['batchId' => $active['id']], 200);
        $row = $ended['promotions'][0];
        $this->assertSame([$day(0), $day(0), $midnightAfter($day(0)), 'active'], [$row['startDate'], $row['endDate'], $row['endsAt'], $row['status']], 'terminée avant sa date de fin : endDate = aujourd\'hui');
        $this->assertNotNull($row['endedAt']);
        $this->assertSame([$day(0), $day(20)], [$ended['startDate'], $ended['endDate']], 'le lot garde ses dates prévues');
        $this->assertSame($day(0), (string) $this->db->fetchOne('SELECT end_date FROM rental_promotion WHERE id = ?', [$row['id']]));
        $this->loginPos();
        $sheet = array_values(array_filter($this->json($this->request('GET', '/api/pos/rentals')), fn ($r) => $r['code'] === 'DEMO-PONTON'))[0];
        $this->assertSame([15, $day(0), $midnightAfter($day(0))], [$sheet['rental']['promotion']['percent'], $sheet['rental']['promotion']['endDate'], $sheet['rental']['promotion']['endsAt']], 'fiche : endDate fiable');

        // annulée avant son début : plage vide, endDate = startDate − 1 jour ; plus rien sur la fiche ni dans la liste
        $upcoming = $this->applyPromotion([$this->kayakProduct], 25, $day(5), $day(12), [1, 2, 3, 4, 5, 6, 7]);
        $cancelled = $this->send('POST', '/api/gestion/locations/promotions/terminer', ['batchId' => $upcoming['id']], 200)['promotions'][0];
        $this->assertSame([$day(5), $day(4), 'cancelled'], [$cancelled['startDate'], $cancelled['endDate'], $cancelled['status']], 'annulée avant son début : endDate = startDate − 1');
        $this->assertSame($cancelled['startsAt'], $cancelled['endsAt']);
        $this->assertSame($midnightAfter($cancelled['endDate']), $cancelled['endsAt'], 'même invariant pour la plage vide');
        $this->assertSame([$row['id']], array_column($this->send('GET', '/api/gestion/locations/promotions', null, 200), 'id'), 'seule la promotion terminée ce soir reste en cours');

        // fuseau à l'est de UTC (Auckland) : minuit du magasin tombe la veille en UTC ; endDate reste le jour du magasin
        $this->db->executeStatement("UPDATE entreprise SET pos_timezone = 'Pacific/Auckland'");
        $akl = new \DateTimeZone('Pacific/Auckland');
        $todayAkl = (new \DateTimeImmutable('today', $akl))->format('Y-m-d');
        $body = ['percent' => 10, 'startDate' => $todayAkl, 'endDate' => (new \DateTimeImmutable($todayAkl))->modify('+9 days')->format('Y-m-d'), 'weekdays' => [1, 2, 3, 4, 5, 6, 7], 'productIds' => [$this->kayakProduct]];
        $preview = $this->send('POST', '/api/gestion/locations/promotions/apercu', $body, 200);
        $east = $this->send('POST', '/api/gestion/locations/promotions/appliquer', $body + ['previewHash' => $preview['previewHash']], 201)['batch'];
        $eastRow = $this->send('POST', '/api/gestion/locations/promotions/terminer', ['batchId' => $east['id']], 200)['promotions'][0];
        $this->assertSame($todayAkl, $eastRow['endDate'], 'Auckland : dernier jour couvert = aujourd\'hui au magasin');
        $this->assertSame(BookingClock::utc((new \DateTimeImmutable("$todayAkl 00:00:00", $akl))->modify('+1 day'))->format(\DateTimeInterface::ATOM), $eastRow['endsAt']);
    }

    // ----- helpers -----

    private function applyPromotion(array $productIds, int $percent, string $start, string $end, array $weekdays): array
    {
        $today = (new \DateTimeImmutable('today', $this->tz))->format('Y-m-d');
        $body = ['percent' => $percent, 'startDate' => max($start, $today), 'endDate' => $end, 'weekdays' => $weekdays, 'productIds' => $productIds];
        $preview = $this->send('POST', '/api/gestion/locations/promotions/apercu', $body, 200);

        return $this->send('POST', '/api/gestion/locations/promotions/appliquer', $body + ['previewHash' => $preview['previewHash']], 201)['batch'];
    }

    private function send(string $method, string $path, ?array $body, int $expected): array
    {
        $this->adminRequest($method, $path, $body);
        $this->assertSame($expected, $this->client->getResponse()->getStatusCode(), $path . ' : ' . substr((string) $this->client->getResponse()->getContent(), 0, 500));

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    private function adminRequest(string $method, string $path, ?array $body = null): void
    {
        $this->client->getCookieJar()->clear();
        $this->client->request($method, 'https://' . MV_TEST_TENANT_HOST . $path, [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->adminToken], $body !== null ? json_encode($body) : null);
    }

    private function json(Response $response, int $expected = 200): array
    {
        $this->assertSame($expected, $response->getStatusCode(), $response->getContent());

        return json_decode($response->getContent(), true);
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

    private function user(array $roles): User
    {
        $email = 'promo-location-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $container = static::getContainer();
        $provider = $container->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Promo')->setLastname('Location')->setRoles($roles)->setIsVerified(true);
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD));
        $provider->getEntityManager()->persist($user);
        $provider->getEntityManager()->flush();

        return $user;
    }

    private function loginPos(array $roles = ['ROLE_USER_POS']): User
    {
        $user = $this->user($roles);
        $this->token = null;
        $login = $this->request('POST', '/api/login', ['username' => $user->getEmail(), 'password' => self::PASSWORD, 'platform' => 'pos']);
        $this->assertSame(200, $login->getStatusCode(), $login->getContent());
        $this->token = json_decode($login->getContent(), true)['token'];

        return $user;
    }
}
