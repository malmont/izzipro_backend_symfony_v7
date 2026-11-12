<?php

namespace App\Tests\Functional\Gestion;

use App\Entity\User;
use App\Services\Booking\BookingClock;
use App\Services\BoutiqueDemoService\BoutiqueDemoSeeder;
use App\Services\PosOfflineService\PosOfflineCalculator;
use App\Services\PosOfflineService\PosOfflineSyncService;
use App\Services\TenantEntityManagerProvider;
use App\Tests\Fake\FakeCheckoutPaymentGateway;
use App\Tests\Fake\FakeTerminalGateway;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Lot VAR, promotion par variante (11/11/2026, `docs/promotions-gestion.md` § 8, contrat validé par l'application) : routes de la gestion
 * (validation, interrupteur, alertes et accords, remplacement, fin, listes, lots) ; même prix partout quand l'interrupteur est allumé (devis
 * boutique et caisse, prix manuel et remise F1 par-dessus, paiement, vente figée avec order_items.variant_promotion_id non exposé, favoris,
 * fiche) et rien quand il est éteint ; retour en caisse au prix payé ; résultat de période (P2) au prix vendu ; pack hors ligne v4 seulement
 * sur demande, rejeu v4 au prix promotionnel et rejeu v3 au prix v3 sans erreur.
 * Prix du jeu d'exemple fixés ici : t-shirt 3 500 (variante XL à prix propre 3 900), sweat 6 900 en promotion d'article 4 900, casquette 2 900.
 */
class VariantPromotionApiTest extends WebTestCase
{
    private const PASSWORD = 'Mot-de-passe-de-test-1!';
    private KernelBrowser $client;
    private \Doctrine\DBAL\Connection $db;
    private ?string $token = null;
    private ?string $terminal = null;
    private int $terminalId = 0;
    private string $adminToken = '';
    private int $register;
    private int $tshirtOwn;
    private int $tshirtPlain;
    private int $sweat;
    private int $cap;
    private int $cafe;
    private \DateTimeZone $tz;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        FakeCheckoutPaymentGateway::reset();
        FakeTerminalGateway::reset();
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $this->db = $provider->getEntityManager()->getConnection();
        static::getContainer()->get(BoutiqueDemoSeeder::class)->seed('client-promo-variante@example.invalid', false, false);
        $this->db->executeStatement('DELETE FROM boutique_setting');
        $this->db->executeStatement("UPDATE address_entreprise SET country = 'CA', state = 'QC', city = 'Montréal', zip = 'H1M 0A9'");
        $this->db->executeStatement("UPDATE entreprise SET currency = 'CAD', pos_timezone = NULL, pos_discrepancy_threshold = 0, pos_cash_rounding_step = NULL, pos_approval_refund = NULL, pos_approval_withdrawal = NULL,
            pos_approval_fund_withdrawal = NULL, pos_approval_drawer_open = false, pos_approval_close_without_count = false, pos_terminal_header_required = false, pos_pay_cash = true,
            pos_pay_declared_card = true, pos_pay_paypal = true, pos_pay_multi = true, pos_declared_card_blocked_with_terminal = false, prices_include_tax = false,
            pos_discount_enabled = true, pos_discount_reason_required = true, pos_custom_item_enabled = false, pos_price_override_enabled = true, pos_price_override_max_amount = NULL,
            pos_price_override_reason_required = true, pos_price_override_reasons = NULL, pos_favorites_enabled = true, pos_favorites_mode = 'both', pos_favorites_days = 30, pos_favorites_grid_size = 500,
            pos_offline_enabled = false, pos_offline_max_hours = 24, pos_offline_max_queued_sales = 100, pos_offline_max_queued_amount = 500000, pos_offline_price_tolerance = 5, pos_offline_pack_validity_hours = 24,
            variant_promotions_enabled = false");
        $this->db->executeStatement('UPDATE caisse SET is_open = false, closed_at = NOW() WHERE is_open = true');
        $this->db->executeStatement("UPDATE cash_register SET is_active = (id = (SELECT MIN(id) FROM cash_register)), terminal_enabled = false, stripe_reader_id = NULL, receipt_prefix = 'V1-'");
        $this->db->executeStatement('DELETE FROM pos_favorite');
        $this->db->executeStatement('DELETE FROM variant_promotion');
        $this->db->executeStatement('DELETE FROM variant_promotion_batch');
        $this->db->executeStatement('UPDATE product SET is_pos = true, tax_category = NULL');
        $this->db->executeStatement("UPDATE product SET price = 3500, special_price = NULL, purchase_price = 1500 WHERE code = 'DEMO-TSHIRT'");
        $this->db->executeStatement("UPDATE product SET price = 6900, special_price = 4900, special_price_from = NOW() - INTERVAL '7 days', special_price_to = NOW() + INTERVAL '60 days', purchase_price = 2000 WHERE code = 'DEMO-SWEAT'");
        $this->db->executeStatement("UPDATE product SET price = 3800, special_price = NULL, purchase_price = 1200 WHERE code = 'DEMO-CAFE'");
        $this->db->executeStatement("UPDATE product SET price = 2900, special_price = NULL, purchase_price = 1000 WHERE code = 'DEMO-CASQUETTE'");
        $this->db->executeStatement("UPDATE product_variant SET stock_quantity = 500 WHERE product_id IN (SELECT id FROM product WHERE code IN ('DEMO-TSHIRT', 'DEMO-SWEAT', 'DEMO-CAFE', 'DEMO-CASQUETTE'))");
        $this->db->executeStatement("DELETE FROM commande_line WHERE product_id IN (SELECT id FROM product WHERE code IN ('DEMO-TSHIRT', 'DEMO-SWEAT', 'DEMO-CAFE', 'DEMO-CASQUETTE'))"); // coût = prix d'achat de la fiche
        $this->register = (int) $this->db->fetchOne('SELECT MIN(id) FROM cash_register');
        $variant = fn (string $code, string $extra = '') => (int) $this->db->fetchOne("SELECT v.id FROM product_variant v JOIN product p ON p.id = v.product_id WHERE p.code = ? $extra ORDER BY v.id LIMIT 1", [$code]);
        // Prix propres des variantes fixés ici (d'autres tests les modifient) : une seule variante du t-shirt à prix propre (3 900)
        $this->db->executeStatement("UPDATE product_variant SET price = NULL WHERE product_id IN (SELECT id FROM product WHERE code IN ('DEMO-TSHIRT', 'DEMO-SWEAT', 'DEMO-CAFE', 'DEMO-CASQUETTE'))");
        $this->tshirtOwn = $variant('DEMO-TSHIRT');
        $this->tshirtPlain = $variant('DEMO-TSHIRT', 'AND v.id > ' . $this->tshirtOwn);
        $this->db->executeStatement('UPDATE product_variant SET price = 3900 WHERE id = ?', [$this->tshirtOwn]);
        $this->sweat = $variant('DEMO-SWEAT');
        $this->cap = $variant('DEMO-CASQUETTE');
        $this->cafe = $variant('DEMO-CAFE');
        $this->tz = static::getContainer()->get(BookingClock::class)->timezone();
        $this->adminToken = static::getContainer()->get('lexik_jwt_authentication.jwt_manager')->create($this->user(['ROLE_ADMIN']));
    }

    protected function tearDown(): void
    {
        $this->db->executeStatement('DELETE FROM variant_promotion');
        $this->db->executeStatement('DELETE FROM variant_promotion_batch');
        $this->db->executeStatement('UPDATE entreprise SET variant_promotions_enabled = false, pos_offline_enabled = false');
        parent::tearDown();
    }

    public function testGestionRoutesValidateGateAlertsReplaceEndAndList(): void
    {
        $end = $this->day(10);
        // Validation
        $fields = fn (array $body) => $this->send('POST', '/api/gestion/promotions/variantes/apercu', $body, 422)['fields'] ?? null;
        $this->assertSame(['percent' => 'REQUIRED', 'endDate' => 'REQUIRED', 'variantIds' => 'SCOPE_REQUIRED'], $fields([]));
        $this->assertSame(['variantIds[0]' => 'VARIANT_NOT_FOUND'], $fields(['variantIds' => [999999], 'percent' => 20, 'endDate' => $end]));
        $this->assertSame(['variantIds' => 'SCOPE_AMBIGUOUS'], $fields(['variantIds' => [$this->tshirtOwn], 'productIds' => [1], 'percent' => 20, 'endDate' => $end]));
        foreach ([[['percent' => 3], 'percent', 'OUT_OF_RANGE'], [['percent' => '20'], 'percent', 'MUST_BE_NUMBER'], [['rounding' => 'up_50'], 'rounding', 'INVALID_ROUNDING'],
            [['startDate' => '2000-01-01', 'endDate' => '2000-01-10'], 'startDate', 'DATE_IN_PAST'], [['startDate' => $this->day(5), 'endDate' => $this->day(4)], 'endDate', 'BEFORE_START'],
            [['endDate' => $this->day(120)], 'endDate', 'TOO_LONG'], [['endDate' => '2026-02-30'], 'endDate', 'INVALID_DATE']] as [$override, $field, $code]) {
            $this->assertSame([$field => $code], $fields($override + ['variantIds' => [$this->tshirtOwn], 'percent' => 20, 'endDate' => $end]), "$field $code");
        }
        $this->assertSame(['status' => 'INVALID_STATUS'], $this->send('GET', '/api/gestion/promotions/variantes?status=old', null, 422)['fields']);

        // Interrupteur éteint : l'aperçu le signale, l'écriture est refusée, rien en base
        $body = ['variantIds' => [$this->tshirtOwn, $this->sweat], 'percent' => 20, 'endDate' => $end];
        $off = $this->send('POST', '/api/gestion/promotions/variantes/apercu', $body, 200);
        $this->assertSame([false, ['VARIANT_PROMOTIONS_OFF']], [$off['enabled'], $off['warnings']]);
        $this->assertSame('VARIANT_PROMOTIONS_OFF', $this->send('POST', '/api/gestion/promotions/variantes/appliquer', $body + ['previewHash' => $off['previewHash']], 409)['error']);
        $this->assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM variant_promotion'));

        // Allumé : t-shirt XL à prix propre 3 900 → 3 120 ; sweat sans prix propre : 6 900 → 5 520, plus haut que sa promotion d'article (4 900)
        $this->gate(true);
        $preview = $this->send('POST', '/api/gestion/promotions/variantes/apercu', $body, 200);
        $rows = array_column($preview['rows'], null, 'variantId');
        $this->assertSame([true, [], 2, 2], [$preview['enabled'], $preview['warnings'], $preview['summary']['variants'], $preview['summary']['changed']]);
        $t = $rows[$this->tshirtOwn];
        $this->assertSame([true, 3900, 3120, 780, 1500, 2400, 1620, [], null, true], [$t['ownPrice'], $t['basePrice'], $t['promoPrice'], $t['discount'], $t['cost'], $t['marginBase'], $t['marginPromo'], $t['alerts'], $t['productPromotion'], $t['changed']]);
        $s = $rows[$this->sweat];
        $this->assertSame([false, 6900, 5520, ['PRODUCT_PROMOTION_ACTIVE', 'VARIANT_PRICE_ABOVE_PRODUCT_PROMOTION'], 4900], [$s['ownPrice'], $s['basePrice'], $s['promoPrice'], $s['alerts'], $s['productPromotion']['specialPrice']]);
        $startsAt = BookingClock::utc(new \DateTimeImmutable($this->day(0) . ' 00:00:00', $this->tz))->format(\DateTimeInterface::ATOM);
        $endsAt = BookingClock::utc((new \DateTimeImmutable("$end 00:00:00", $this->tz))->modify('+1 day'))->format(\DateTimeInterface::ATOM);
        $this->assertSame([$this->day(0), $end, $startsAt, $endsAt, 11], array_values($preview['period']));
        $this->assertSame('PRICES_CHANGED', $this->send('POST', '/api/gestion/promotions/variantes/appliquer', $body + ['previewHash' => 'faux'], 409)['error']);

        // Sans accord : le sweat est écarté (alerte), le t-shirt est écrit ; avec l'accord, le sweat passerait
        $applied = $this->send('POST', '/api/gestion/promotions/variantes/appliquer', $body + ['previewHash' => $preview['previewHash']], 201);
        $this->assertSame([['variantId' => $this->sweat, 'alerts' => ['VARIANT_PRICE_ABOVE_PRODUCT_PROMOTION']]], $applied['skipped']);
        $first = $applied['batch'];
        $this->assertSame([20, 'none', $this->day(0), $end], [$first['percent'], $first['rounding'], $first['startDate'], $first['endDate']]);
        $p = $first['promotions'][0];
        $this->assertSame([$this->tshirtOwn, 3120, 3900, 'active', $startsAt, $endsAt, $end], [$p['variantId'], $p['price'], $p['basePrice'], $p['status'], $p['startsAt'], $p['endsAt'], $p['endDate']]);
        $this->assertSame([$p['id']], array_column($this->send('GET', '/api/gestion/promotions/variantes?status=active', null, 200), 'id'));
        $this->assertSame([], $this->send('GET', '/api/gestion/promotions/variantes?status=planned', null, 200));

        // Recoupement : PROMOTION_EXISTS ; sans allowReplace rien à appliquer ; avec, l'ancienne (même début) est annulée, la nouvelle s'applique
        $again = ['variantIds' => [$this->tshirtOwn], 'percent' => 30, 'endDate' => $this->day(5)];
        $preview2 = $this->send('POST', '/api/gestion/promotions/variantes/apercu', $again, 200);
        $this->assertSame(['PROMOTION_EXISTS'], $preview2['rows'][0]['alerts']);
        $this->assertSame([$p['id']], array_column($preview2['rows'][0]['current'], 'id'));
        $this->assertSame('NOTHING_TO_APPLY', $this->send('POST', '/api/gestion/promotions/variantes/appliquer', $again + ['previewHash' => $preview2['previewHash']], 422)['error']);
        $second = $this->send('POST', '/api/gestion/promotions/variantes/appliquer', $again + ['previewHash' => $preview2['previewHash'], 'allowReplace' => true], 201)['batch'];
        $this->assertSame(2730, $second['promotions'][0]['price']);
        $old = $this->send('GET', '/api/gestion/promotions/variantes/lots/' . $first['id'], null, 200)['promotions'][0];
        $this->assertSame(['cancelled', $this->day(-1), $old['startsAt']], [$old['status'], $old['endDate'], $old['endsAt']], 'remplacée dès son début : plage vide, endDate = startDate − 1');
        $this->assertSame(2730, $this->quote([['productVariantId' => $this->tshirtOwn, 'quantity' => 1]])['lines'][0]['unitPrice']);

        // À venir, liste par statut, fin d'un lot (en cours : jusqu'à ce soir), fin en double, lot inconnu, lots
        $planned = $this->apply(['variantIds' => [$this->cap], 'percent' => 10, 'startDate' => $this->day(3), 'endDate' => $this->day(6)]);
        $this->assertSame([$planned['promotions'][0]['id']], array_column($this->send('GET', '/api/gestion/promotions/variantes?status=planned', null, 200), 'id'));
        $this->assertSame([$second['promotions'][0]['id'], $planned['promotions'][0]['id']], array_column($this->send('GET', '/api/gestion/promotions/variantes', null, 200), 'id'), 'all : en cours et à venir, par début');
        $ended = $this->send('POST', '/api/gestion/promotions/variantes/terminer', ['batchId' => $second['id']], 200);
        $tonight = BookingClock::utc((new \DateTimeImmutable($this->day(0) . ' 00:00:00', $this->tz))->modify('+1 day'))->format(\DateTimeInterface::ATOM);
        $this->assertSame(['active', $this->day(0), $tonight], [$ended['promotions'][0]['status'], $ended['promotions'][0]['endDate'], $ended['promotions'][0]['endsAt']]);
        $this->assertNotNull($ended['endedAt']);
        $this->assertSame('ALREADY_ENDED', $this->send('POST', '/api/gestion/promotions/variantes/terminer', ['batchId' => $second['id']], 409)['error']);
        $this->assertSame('BATCH_NOT_FOUND', $this->send('POST', '/api/gestion/promotions/variantes/terminer', ['batchId' => 999999], 404)['error']);
        $this->assertSame(['batchId' => 'REQUIRED'], $this->send('POST', '/api/gestion/promotions/variantes/terminer', [], 422)['fields']);
        $cancelled = $this->send('POST', '/api/gestion/promotions/variantes/terminer', ['batchId' => $planned['id']], 200)['promotions'][0];
        $this->assertSame(['cancelled', $this->day(2)], [$cancelled['status'], $cancelled['endDate']], 'à venir : plage vide');
        $this->assertSame([$planned['id'], $second['id'], $first['id']], array_column($this->send('GET', '/api/gestion/promotions/variantes/lots', null, 200), 'id'));
        $this->assertSame('BATCH_NOT_FOUND', $this->send('GET', '/api/gestion/promotions/variantes/lots/999999', null, 404)['error']);
        // sans jeton : refusé
        $this->token = null;
        $this->assertSame(401, $this->request('POST', '/api/gestion/promotions/variantes/apercu', $body)->getStatusCode());
    }

    public function testSamePriceEverywhereWhenOnNothingWhenOffReturnAndPeriodResult(): void
    {
        $this->gate(true);
        $tshirtPromo = $this->apply(['variantIds' => [$this->tshirtOwn], 'percent' => 20, 'endDate' => $this->day(10)])['promotions'][0]['id'];
        $sweatPromo = $this->apply(['variantIds' => [$this->sweat], 'percent' => 30, 'endDate' => $this->day(10)])['promotions'][0]['id']; // 4 830 < 4 900 : pas d'accord requis
        $capPromo = $this->apply(['variantIds' => [$this->cap], 'percent' => 20, 'rounding' => 'ends_99', 'endDate' => $this->day(10)])['promotions'][0]['id']; // 2 320 → 2 299
        $items = [['productVariantId' => $this->tshirtOwn, 'quantity' => 2], ['productVariantId' => $this->tshirtPlain, 'quantity' => 1], ['productVariantId' => $this->sweat, 'quantity' => 1],
            ['productVariantId' => $this->cafe, 'quantity' => 1], ['productVariantId' => $this->cap, 'quantity' => 1]];

        // Boutique : la variante l'emporte sur la promotion de l'article ; clé absente sans promotion de variante
        $web = $this->quote($items);
        $this->assertSame([3120, 3500, 4830, 3800, 2299], array_column($web['lines'], 'unitPrice'));
        $this->assertSame(['id' => $tshirtPromo, 'kind' => 'variant', 'percent' => 20, 'amount' => 780, 'catalogUnitPrice' => 3900], $web['lines'][0]['promotion']);
        $this->assertSame(['id' => $sweatPromo, 'kind' => 'variant', 'percent' => 30, 'amount' => 2070, 'catalogUnitPrice' => 6900], $web['lines'][2]['promotion'], 'remplace la promotion de l\'article (4 900), sans cumul');
        $this->assertSame([false, false], [array_key_exists('promotion', $web['lines'][1]), array_key_exists('promotion', $web['lines'][3])]);
        $intent = $this->call('POST', '/api/stripe/create-intent', ['items' => $items, 'shippingAddress' => ['country' => 'CA', 'province' => 'QC']]);
        $this->assertSame($web['total'], $intent['body']['calculatedAmount'], 'paiement : même montant que le devis');

        // Caisse : prix manuel et remise F1 par-dessus le prix promotionnel ; vente figée ; colonne non exposée
        $this->loginPos(['ROLE_USER_POS', 'ROLE_POS_MANAGER']);
        $this->call('POST', '/api/caisse/open', ['cashRegisterId' => $this->register, 'cashFund' => 50000]);
        $pos = $items;
        $pos[4]['priceOverride'] = ['unitPrice' => 2000, 'reasonCode' => 'prix_concurrent'];
        $pos[2]['discounts'] = [['type' => 'percent', 'value' => 10, 'reasonCode' => 'fidelite']];
        $quote = $this->json($this->request('POST', '/api/pos/quote', ['items' => $pos, 'cashRegisterId' => $this->register]));
        $this->assertSame([3120, 3500, 4830, 3800, 2000], array_column($quote['lines'], 'unitPrice'));
        $this->assertSame([2299, 4347], [$quote['lines'][4]['catalogUnitPrice'], $quote['lines'][2]['total']], 'prix manuel : catalogue = prix promotionnel ; F1 sur le prix promotionnel');
        $this->assertSame($web['lines'][0]['promotion'], $quote['lines'][0]['promotion']);
        $revenueBefore = $this->periodResult();
        $sale = $this->json($this->request('POST', '/api/order/create-multi-payment', ['items' => $pos, 'paymentMethods' => [['type' => 2, 'amount' => $quote['cashTotal'] / 100]], 'cashRegisterId' => $this->register]), 201);
        $lines = $this->db->fetchAllAssociative('SELECT id, unit_price, total_price, catalog_unit_price, variant_promotion_id FROM order_items WHERE order_associated_id = ? ORDER BY id', [$sale['orderId']]);
        $this->assertSame([$tshirtPromo, null, $sweatPromo, null, $capPromo], array_map(fn ($l) => $l['variant_promotion_id'] === null ? null : (int) $l['variant_promotion_id'], $lines));
        $this->assertSame([6240.0, 3500.0, 4347.0, 3800.0, 2000.0], array_map(fn ($l) => (float) $l['total_price'], $lines));
        // Reçu de la vente (dollars) : promotion figée sur les lignes concernées, clé absente ailleurs ; prix manuel par-dessus : remise de la promotion
        // calculée sur son prix promotionnel (2 900 − 2 299), le prix manuel restant dans catalogUnitPrice / priceReason
        $promotionOf = fn (array $line) => $line['promotion'] ?? null;
        $this->assertSame([['kind' => 'variant', 'percent' => 20, 'amount' => 7.8, 'catalogUnitPrice' => 39], null, ['kind' => 'variant', 'percent' => 30, 'amount' => 20.7, 'catalogUnitPrice' => 69], null,
            ['kind' => 'variant', 'percent' => 20, 'amount' => 6.01, 'catalogUnitPrice' => 29]], array_map($promotionOf, $sale['lines']));
        $this->assertArrayNotHasKey('promotion', $sale['lines'][1]);
        $this->assertSame([2299, 22.99], [(int) $this->db->fetchOne('SELECT catalog_unit_price FROM order_items WHERE id = ?', [$lines[4]['id']]), $sale['lines'][4]['catalogUnitPrice']], 'prix manuel : son prix catalogue est le prix promotionnel');
        foreach (["/api/orders/{$sale['orderId']}", '/api/orders?orderSource=2'] as $path) {
            $this->assertStringNotContainsString('ariantPromotion', (string) $this->request('GET', $path)->getContent(), "$path : colonne non exposée");
        }

        // Favoris (casquette, variante unique, parmi les plus vendus après la vente) et fiche : promotion de variante
        $favorites = array_column($this->json($this->request('GET', '/api/pos/favorites'))['items'], null, 'defaultVariantId');
        $this->assertSame([2299, $web['lines'][4]['promotion']], [$favorites[$this->cap]['price'], $favorites[$this->cap]['promotion']]);
        $this->assertArrayNotHasKey('promotion', $favorites[$this->cafe]);
        $this->token = null;
        $tshirtId = (int) $this->db->fetchOne("SELECT id FROM product WHERE code = 'DEMO-TSHIRT'");
        foreach (["/api/products/$tshirtId", "/api/productsid/$tshirtId"] as $path) { // fiche (ProductCommerceDto) ; /variants n'expose aucun prix
            $body = $this->json($this->request('GET', $path));
            $variants = array_column($body['variants'] ?? $body, null, 'id');
            $sheet = $variants[$this->tshirtOwn]['promotion'];
            $this->assertSame([$tshirtPromo, 20, 3120, 3900, $this->day(0), $this->day(10), true], [$sheet['id'], $sheet['percent'], $sheet['price'], $sheet['basePrice'], $sheet['startDate'], $sheet['endDate'], $sheet['active']], $path);
            $this->assertArrayNotHasKey('promotion', $variants[$this->tshirtPlain], "$path : autre variante sans clé");
        }

        // Catalogue par catégorie ou recherche (GET /api/products/by-category, public, lu par la caisse en ligne) : même variants[].promotion
        $list = $this->json($this->request('GET', '/api/products/by-category?keyword=Horizon&pageSize=50'));
        $rows = $list['products'] ?? $list['data'] ?? $list['items'] ?? $list;
        $listed = array_values(array_filter(is_array($rows) ? $rows : [], fn ($p) => is_array($p) && ($p['id'] ?? null) === $tshirtId))[0] ?? $this->fail('by-category : ' . json_encode(array_keys($list)));
        $this->assertSame($tshirtPromo, array_column($listed['variants'], null, 'id')[$this->tshirtOwn]['promotion']['id'] ?? null, 'liste : variants[].promotion');

        // Résultat de période (P2) : chiffre d'affaires et marge au prix réellement vendu (coût = prix d'achat de la fiche)
        $after = $this->periodResult();
        $revenue = 6240 + 3500 + 4347 + 3800 + 2000;
        $cogs = 2 * 1500 + 1500 + 2000 + 1200 + 1000;
        $this->assertSame([$revenue, $cogs, $revenue - $cogs], [$after['sales']['revenue'] - $revenueBefore['sales']['revenue'], $after['cogs']['amount'] - $revenueBefore['cogs']['amount'], $after['result']['gross'] - $revenueBefore['result']['gross']]);

        // Retour en caisse d'une unité de t-shirt : remboursée au prix payé (3 120 + taxes)
        $this->loginPos(['ROLE_USER_POS', 'ROLE_POS_MANAGER']);
        $look = $this->json($this->request('GET', '/api/pos/returns/lookup?receipt=' . urlencode($sale['receiptReference'])));
        $line = array_column($look['lines'], null, 'productVariantId')[$this->tshirtOwn];
        $this->assertSame(3120, $line['unitPrice'], 'prix figé : le prix promotionnel');
        $this->assertGreaterThan(3120, $line['unitRefundable'], 'remboursable : prix payé et sa part de taxes');
        $ret = $this->json($this->request('POST', '/api/pos/returns', ['orderId' => $sale['orderId'], 'lines' => [['orderItemId' => $line['orderItemId'], 'quantity' => 1]], 'reasonCode' => 'defectueux', 'refund' => ['method' => 'cash'], 'cashRegisterId' => $this->register]), 201);
        $this->assertSame([3120, $line['unitRefundable']], [$ret['subTotal'], $ret['amount']]);

        // Éteint : prix d'avant le lot partout, aucune clé, même avec les promotions en base
        $this->gate(false);
        $this->token = null;
        $off = $this->quote($items);
        $this->assertSame([3900, 3500, 4900, 3800, 2900], array_column($off['lines'], 'unitPrice'));
        $this->assertSame([], array_filter(array_map(fn ($l) => array_key_exists('promotion', $l), $off['lines'])));
        $variants = array_column($this->json($this->request('GET', "/api/products/$tshirtId"))['variants'], null, 'id');
        $this->assertArrayNotHasKey('promotion', $variants[$this->tshirtOwn]);
        $this->loginPos(['ROLE_USER_POS']);
        $favoritesOff = array_column($this->json($this->request('GET', '/api/pos/favorites'))['items'], null, 'defaultVariantId');
        $this->assertSame(2900, $favoritesOff[$this->cap]['price']);
        $this->assertArrayNotHasKey('promotion', $favoritesOff[$this->cap]);

        // Réimpression après la fin de la promotion et un changement de prix : le reçu garde la promotion figée à la vente
        $this->db->executeStatement("UPDATE variant_promotion SET ends_at = NOW() - INTERVAL '1 minute'");
        $this->db->executeStatement('UPDATE product_variant SET price = 4500 WHERE id = ?', [$this->tshirtOwn]);
        $this->loginPos(['ROLE_USER_POS', 'ROLE_POS_MANAGER']);
        $reprint = $this->json($this->request('POST', '/api/pos/receipts/reprint', ['orderId' => $sale['orderId']])); // réimpression explicite (lot REIMP)
        $this->assertTrue($reprint['duplicate']);
        $this->assertSame(array_map($promotionOf, $sale['lines']), array_map($promotionOf, $reprint['lines']), 'duplicata : promotion figée');
    }

    public function testOfflinePackV4OnlyOnRequestAndReplayRules(): void
    {
        $this->db->executeStatement('UPDATE entreprise SET pos_offline_enabled = true');
        $this->loginPos(['ROLE_USER_POS', 'ROLE_POS_MANAGER']);
        $this->terminal = $this->posTerminal();
        $seller = $this->user(['ROLE_USER_POS']);
        $pack = fn (array $headers = []) => $this->request('GET', "/api/pos/offline-pack?cashRegisterId=$this->register", null, $headers);
        $v3Off = $pack();
        $v4Off = $pack(['HTTP_X_POS_OFFLINE_RULES' => 'v4']);
        $this->assertSame([$v3Off->headers->get('ETag'), PosOfflineCalculator::RULE], [$v4Off->headers->get('ETag'), json_decode($v4Off->getContent(), true)['calculationRule']], 'éteint : v3 même demandé en v4');

        $this->gate(true);
        $promo = $this->apply(['variantIds' => [$this->cap], 'percent' => 20, 'rounding' => 'ends_99', 'endDate' => $this->day(10)])['promotions'][0];
        $v3 = $pack();
        $this->assertSame($v3Off->headers->get('ETag'), $v3->headers->get('ETag'), 'tablette v3 : pack identique octet pour octet');
        $this->assertStringNotContainsString('promotions', (string) $v3->getContent());
        $v4 = $this->json($pack(['HTTP_X_POS_OFFLINE_RULES' => 'v4']));
        $this->assertSame(PosOfflineCalculator::RULE_V4, $v4['calculationRule']);
        $capPack = array_values(array_filter($v4['products'], fn ($p) => $p['code'] === 'DEMO-CASQUETTE'))[0]['variants'][0];
        $this->assertSame([['id' => $promo['id'], 'price' => 2299, 'from' => $promo['startsAt'], 'to' => $promo['endsAt'], 'percent' => 20, 'basePrice' => 2900]], $capPack['promotions']);
        // ETag selon la règle : une tablette qui passe à v4 avec l'ETag de son pack v3 reçoit 200 et la v4, jamais 304 ; même règle, même ETag : 304
        $v4Etag = $pack(['HTTP_X_POS_OFFLINE_RULES' => 'v4'])->headers->get('ETag');
        $this->assertNotSame($v3->headers->get('ETag'), $v4Etag, 'l\'ETag dépend de la règle');
        $switch = $pack(['HTTP_X_POS_OFFLINE_RULES' => 'v4', 'HTTP_IF_NONE_MATCH' => $v3->headers->get('ETag')]);
        $this->assertSame([200, PosOfflineCalculator::RULE_V4], [$switch->getStatusCode(), json_decode((string) $switch->getContent(), true)['calculationRule']]);
        $this->assertSame(304, $pack(['HTTP_X_POS_OFFLINE_RULES' => 'v4', 'HTTP_IF_NONE_MATCH' => $v4Etag])->getStatusCode());
        $this->assertSame(200, $pack(['HTTP_IF_NONE_MATCH' => $v4Etag])->getStatusCode(), 'tablette v3 avec un ETag v4 : 200');
        $this->assertArrayNotHasKey('promotions', array_values(array_filter($v4['products'], fn ($p) => $p['code'] === 'DEMO-CAFE'))[0]['variants'][0]);

        // Vente hors ligne pendant la promotion : rejeu v4 au prix promotionnel ; rejeu v3 au prix v3, sans erreur ni écart
        $this->request('POST', '/api/caisse/open', ['cashRegisterId' => $this->register, 'cashFund' => 10000]);
        $saleV4 = $this->offlineSale($seller, 'P-' . $this->terminalId . '-1', 2299, $v4);
        $r = $this->json($this->request('POST', '/api/pos/offline-sales', ['packVersion' => $v4['packVersion'], 'sales' => [$saleV4]]));
        $this->assertSame(['accepted', 0], [$r['results'][0]['status'], $r['results'][0]['priceDrift']['amount']], json_encode($r));
        $this->assertSame([2299.0, $promo['id']], $this->orderLine($r['results'][0]['orderId']));
        $this->assertSame(2900, (int) $this->db->fetchOne('SELECT variant_catalog_unit_price FROM order_items WHERE order_associated_id = ?', [$r['results'][0]['orderId']]), 'prix barré de l\'instantané v4 figé');
        $v3Pack = json_decode((string) $v3->getContent(), true);
        $saleV3 = $this->offlineSale($seller, 'P-' . $this->terminalId . '-2', 2900, $v3Pack, $saleV4['chain']['hash']);
        $r = $this->json($this->request('POST', '/api/pos/offline-sales', ['packVersion' => $v3Pack['packVersion'], 'sales' => [$saleV3]]));
        $this->assertSame(['accepted', 0], [$r['results'][0]['status'], $r['results'][0]['priceDrift']['amount']], json_encode($r));
        $this->assertSame([2900.0, null], $this->orderLine($r['results'][0]['orderId']), 'tablette v3 : prix v3, sans promotion de variante');
    }

    /** Règles du résolveur : fenêtre [début, fin[ (fin exclue), promotion périmée (prix catalogue baissé sous le prix promotionnel) ignorée */
    public function testResolverWindowEndIsExclusiveAndStalePromotionIsIgnored(): void
    {
        $this->gate(true);
        $promo = $this->apply(['variantIds' => [$this->cap], 'percent' => 20, 'rounding' => 'ends_99', 'endDate' => $this->day(10)])['promotions'][0];
        $em = static::getContainer()->get(TenantEntityManagerProvider::class)->getEntityManager();
        $em->clear();
        $variant = $em->find(\App\Entity\ProductVariant::class, $this->cap);
        $resolver = \App\Services\OrderService\VariantPriceResolver::class;
        $endsAt = new \DateTimeImmutable($promo['endsAt']);
        $this->assertSame($promo['id'], $resolver::activeAt($variant, $endsAt->modify('-1 second'))?->getId(), 'dernière seconde : appliquée');
        $this->assertNull($resolver::activeAt($variant, $endsAt), 'fin exclue');
        $this->assertSame($promo['id'], $resolver::activeAt($variant, new \DateTimeImmutable($promo['startsAt']))?->getId(), 'début inclus');
        $this->assertNull($resolver::activeAt($variant, (new \DateTimeImmutable($promo['startsAt']))->modify('-1 second')));

        // Prix de l'article baissé sous le prix promotionnel (2 299) : la promotion ne relève jamais le prix, elle est ignorée partout
        $this->db->executeStatement("UPDATE product SET price = 2200 WHERE code = 'DEMO-CASQUETTE'");
        $line = $this->quote([['productVariantId' => $this->cap, 'quantity' => 1]])['lines'][0];
        $this->assertSame(2200, $line['unitPrice']);
        $this->assertArrayNotHasKey('promotion', $line);
        $this->token = null;
        $capId = (int) $this->db->fetchOne("SELECT id FROM product WHERE code = 'DEMO-CASQUETTE'");
        $this->assertArrayNotHasKey('promotion', $this->json($this->request('GET', "/api/productsid/$capId"))['variants'][0], 'fiche : pas de promotion périmée');
    }

    /** Changer l'interrupteur (écriture Doctrine, comme la fiche Entreprise d'EasyAdmin) vide le cache des fiches produit du site */
    public function testSwitchChangeClearsCachedProductSheets(): void
    {
        $em = static::getContainer()->get(TenantEntityManagerProvider::class)->getEntityManager();
        $em->find(\App\Entity\ProductVariant::class, $this->tshirtOwn)->setStockQuantity(499); // écriture Doctrine : fiche hors du cache
        $em->flush();
        $tshirtId = (int) $this->db->fetchOne("SELECT id FROM product WHERE code = 'DEMO-TSHIRT'");
        $sheet = fn () => array_column($this->json($this->request('GET', "/api/productsid/$tshirtId"))['variants'], null, 'id')[$this->tshirtOwn];
        $this->assertArrayNotHasKey('promotion', $sheet(), 'éteint');
        $this->db->executeStatement("INSERT INTO variant_promotion (product_variant_id, percent, price, base_price, start_date, end_date, starts_at, ends_at, created_at)
            VALUES (?, 20, 3120, 3900, CURRENT_DATE - 1, CURRENT_DATE + 10, NOW() - INTERVAL '1 day', NOW() + INTERVAL '10 days', NOW())", [$this->tshirtOwn]); // SQL : ne vide rien
        $this->assertArrayNotHasKey('promotion', $sheet(), 'fiche en cache');
        $em->clear();
        $em->getRepository(\App\Entity\Entreprise::class)->findOneBy([], ['id' => 'ASC'])->setVariantPromotionsEnabled(true);
        $em->flush();
        $this->assertSame(3120, $sheet()['promotion']['price'] ?? null, 'interrupteur allumé : la fiche en cache est vidée, la promotion apparaît');
        $em->clear();
        $em->getRepository(\App\Entity\Entreprise::class)->findOneBy([], ['id' => 'ASC'])->setVariantPromotionsEnabled(false);
        $em->flush();
        $this->assertArrayNotHasKey('promotion', $sheet(), 'éteint de nouveau : vidée encore');
    }

    // ----- helpers -----

    private function day(int $n): string
    {
        return (new \DateTimeImmutable('today', $this->tz))->modify("$n days")->format('Y-m-d');
    }

    private function gate(bool $on): void
    {
        $this->db->executeStatement('UPDATE entreprise SET variant_promotions_enabled = ?', [$on], [\Doctrine\DBAL\ParameterType::BOOLEAN]);
    }

    private function apply(array $body): array
    {
        $preview = $this->send('POST', '/api/gestion/promotions/variantes/apercu', $body, 200);

        return $this->send('POST', '/api/gestion/promotions/variantes/appliquer', $body + ['previewHash' => $preview['previewHash']], 201)['batch'];
    }

    private function quote(array $items): array
    {
        $saved = $this->token;
        $this->token = null;
        $quote = $this->json($this->request('POST', '/api/cart/quote', ['items' => $items, 'shippingAddress' => ['country' => 'CA', 'province' => 'QC']]));
        $this->token = $saved;

        return $quote;
    }

    /** Résultat d'une période manuelle autour d'aujourd'hui (P2) */
    private function periodResult(): array
    {
        static $period = null;
        $period ??= (int) $this->db->fetchOne(
            "INSERT INTO collections (id, budget_collection, start_date_collection, end_date_collection, del, nom_collection, is_closed, kind, period_key) VALUES (nextval('collections_id_seq'), 0, ?, ?, false, 'VAR', false, 'period', ?) RETURNING id",
            [$this->day(-2) . ' 00:00:00', $this->day(2) . ' 00:00:00', 'var-' . bin2hex(random_bytes(3))]
        );

        return $this->send('GET', "/api/periodes/$period/resultat", null, 200);
    }

    /** @return array{0: float, 1: ?int} prix unitaire figé et promotion de variante de la ligne de la commande */
    private function orderLine(int $orderId): array
    {
        $row = $this->db->fetchAssociative('SELECT unit_price, variant_promotion_id FROM order_items WHERE order_associated_id = ?', [$orderId]);

        return [(float) $row['unit_price'], $row['variant_promotion_id'] === null ? null : (int) $row['variant_promotion_id']];
    }

    private function offlineSale(User $seller, string $provisional, int $unitPrice, array $pack, string $prevHash = ''): array
    {
        $r = PosOfflineCalculator::compute([['unitPrice' => $unitPrice, 'quantity' => 1, 'discounts' => []]], null, $pack['taxes'], $pack['site']['cashRoundingStep'], null);
        $sale = ['idempotencyKey' => self::uuid(), 'provisionalNumber' => $provisional, 'cashRegisterId' => $this->register, 'sellerId' => $seller->getId(), 'soldAt' => (new \DateTimeImmutable('-30 seconds'))->format(\DateTimeInterface::ATOM),
            'items' => [['productVariantId' => $this->cap, 'quantity' => 1]], 'ticketDiscount' => null, 'payments' => [['type' => 2, 'amount' => $r['cashTotal']]], 'cashReceived' => $r['cashTotal'],
            'totals' => ['subtotal' => $r['subtotal'], 'taxes' => array_map(fn ($t) => ['label' => $t['label'], 'amount' => $t['amount']], $r['taxes']), 'total' => $r['total'], 'cashTotal' => $r['cashTotal'], 'cashRounding' => $r['cashRounding']]];
        $sale['chain'] = ['seq' => (int) substr($provisional, strrpos($provisional, '-') + 1), 'prevHash' => $prevHash, 'hash' => PosOfflineSyncService::hashOf($sale, $prevHash)];

        return $sale;
    }

    private function posTerminal(): string
    {
        $saved = $this->token;
        $this->loginPos(['ROLE_ADMIN', 'ROLE_USER_POS']);
        $key = $this->json($this->request('POST', '/api/app-keys', ['app' => 'pos', 'label' => 'Tablette promotions de variante', 'sendEmail' => false]), 201)['key'];
        $this->token = $saved;
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . '/api/app/activate', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['key' => $key, 'app' => 'pos']));
        $terminal = json_decode($this->client->getResponse()->getContent(), true)['terminal'];
        $this->terminalId = (int) $terminal['id'];

        return $terminal['token'];
    }

    private static function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }

    private function send(string $method, string $path, ?array $body, int $expected): array
    {
        $saved = $this->token;
        $this->token = $this->adminToken;
        $response = $this->request($method, $path, $body);
        $this->token = $saved;
        $this->assertSame($expected, $response->getStatusCode(), $path . ' : ' . substr((string) $response->getContent(), 0, 600));

        return json_decode((string) $response->getContent(), true) ?? [];
    }

    private function call(string $method, string $path, ?array $body = null): array
    {
        $response = $this->request($method, $path, $body);

        return ['status' => $response->getStatusCode(), 'body' => json_decode((string) $response->getContent(), true)];
    }

    private function request(string $method, string $path, ?array $body = null, array $extraHeaders = []): Response
    {
        $this->client->getCookieJar()->clear();
        $this->client->request($method, 'https://' . MV_TEST_TENANT_HOST . $path, [], [], array_filter([
            'HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => $this->token ? 'Bearer ' . $this->token : null, 'HTTP_X_POS_TERMINAL' => $this->terminal,
        ]) + $extraHeaders, $body !== null ? json_encode($body) : null);

        return $this->client->getResponse();
    }

    private function json(Response $response, int $expected = 200): array
    {
        $this->assertSame($expected, $response->getStatusCode(), (string) $response->getContent());

        return json_decode((string) $response->getContent(), true);
    }

    private function loginPos(array $roles): User
    {
        $user = $this->user($roles);
        $this->token = null;
        $login = $this->request('POST', '/api/login', ['username' => $user->getEmail(), 'password' => self::PASSWORD, 'platform' => 'pos']);
        $this->assertSame(200, $login->getStatusCode(), $login->getContent());
        $this->token = json_decode($login->getContent(), true)['token'];

        return $user;
    }

    private function user(array $roles): User
    {
        $email = 'promo-variante-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $container = static::getContainer();
        $provider = $container->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Promo')->setLastname('Variante')->setRoles($roles)->setIsVerified(true);
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD));
        $provider->getEntityManager()->persist($user);
        $provider->getEntityManager()->flush();

        return $user;
    }
}
