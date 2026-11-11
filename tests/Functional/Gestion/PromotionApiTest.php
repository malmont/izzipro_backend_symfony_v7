<?php

namespace App\Tests\Functional\Gestion;

use App\Entity\Product;
use App\Entity\ProductVariant;
use App\Entity\User;
use App\EventSubscriber\CacheInvalidationSubscriber;
use App\Services\CaisseService\StoreClock;
use App\Gestion\Services\GestionPromotionService;
use App\Gestion\Services\PeriodCalendar;
use App\Services\TenantEntityManagerProvider;
use App\Tests\Functional\Cache\CacheTestEntityFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Lot P5, promotions datées (11/11/2026, `docs/promotions-gestion.md`, contrat validé par l'application) : arrondis vers le bas, bornes des jours du
 * magasin en UTC, aperçu sans écriture (variantes à prix propre avec leur libellé, promotion existante, location, sous le coût, grande remise),
 * empreinte, application par le chemin d'un article (prix effectif relu, boutique), remplacement d'une promotion, terminer avant et après le début
 * (ancien état remis / fin maintenant), retouchée depuis et onlyUntouched, conseil marqué fait avec la date de début puis remis en new, liste,
 * validations.
 */
class PromotionApiTest extends WebTestCase
{
    use CacheTestEntityFactory;

    private KernelBrowser $client;
    private string $token = '';

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->login();
    }

    protected function tearDown(): void
    {
        $conn = $this->em()->getConnection();
        $conn->executeStatement("DELETE FROM gestion_advice WHERE signature LIKE 'P5:%'");
        $conn->executeStatement("DELETE FROM product_variant WHERE product_id IN (SELECT id FROM product WHERE slug LIKE 'p5-%')");
        $conn->executeStatement("DELETE FROM product WHERE slug LIKE 'p5-%'");
        parent::tearDown();
    }

    public function testRoundingDown(): void
    {
        $r = fn (float $c, string $m) => GestionPromotionService::round($c, $m);
        $this->assertSame([1499, 1599, 2899, 1450, 1400, 1500, 1500, 50], [$r(1500.0, 'ends_99'), $r(1599.0, 'ends_99'), $r(2925.0, 'ends_99'), $r(1499.0, 'down_50'), $r(1499.0, 'down_100'), $r(1500.0, 'down_50'), $r(1500.4, 'none'), $r(50.0, 'ends_99')]);
    }

    public function testPreviewApplyEndAndAdvice(): void
    {
        $conn = $this->em()->getConnection();
        $clock = static::getContainer()->get(StoreClock::class);
        $today = static::getContainer()->get(PeriodCalendar::class)->today();
        $d = fn (int $n) => $today->modify("$n days")->format('Y-m-d');
        // A : 20,00 $, coût 10,00 $, une variante à prix propre ; B : promotion déjà en cours ; C : coût 19,00 $ ; R : location
        $a = $this->product('A', 2000, 1000);
        $own = $this->variant($a, 2500);
        $conn->executeStatement("UPDATE product_variant SET size_id = (SELECT id FROM size ORDER BY id LIMIT 1) WHERE id = ?", [$own]);
        $this->variant($a, null);
        $b = $this->product('B', 3000, 1000);
        $conn->executeStatement("UPDATE product SET special_price = 2700, special_price_from = NOW() - INTERVAL '1 day', special_price_to = NOW() + INTERVAL '5 days' WHERE id = ?", [$b]);
        $c = $this->product('C', 2000, 1900);
        $r = $this->product('R', 5000, 1000);
        $conn->executeStatement('UPDATE product SET rental_enabled = TRUE WHERE id = ?', [$r]);
        $this->endOfWrite();

        $body = ['productIds' => [$a, $b, $c, $r], 'percent' => 25, 'rounding' => 'ends_99', 'endDate' => $d(6)];
        $p = $this->send('POST', '/api/gestion/promotions/apercu', $body, 200);
        [$startsAt, $endExclusive] = $clock->range($d(0), $d(6));
        $this->assertSame([$d(0), $d(6), 7, $startsAt->format(\DateTimeInterface::ATOM), $endExclusive->modify('-1 second')->format(\DateTimeInterface::ATOM)],
            [$p['period']['startDate'], $p['period']['endDate'], $p['period']['days'], $p['period']['startsAt'], $p['period']['endsAt']], 'bornes des jours du magasin, en UTC');
        $rows = array_column($p['rows'], null, 'productId');
        $this->assertSame([2000, 1499, 501, 499, true, ['VARIANT_OWN_PRICE']], [$rows[$a]['regularPrice'], $rows[$a]['promoPrice'], $rows[$a]['discount'], $rows[$a]['marginPromo'], $rows[$a]['changed'], $rows[$a]['alerts']]);
        $this->assertSame(1, $rows[$a]['variantsOwnPrice']['count']);
        $this->assertSame($own, $rows[$a]['variantsOwnPrice']['variants'][0]['variantId']);
        $this->assertArrayHasKey('variantLabel', $rows[$a]['variantsOwnPrice']['variants'][0]);
        $this->assertSame([['PROMOTION_EXISTS'], 2700, true], [$rows[$b]['alerts'], $rows[$b]['current']['specialPrice'], $rows[$b]['current']['active']]);
        $this->assertSame(['BELOW_COST'], $rows[$c]['alerts']);
        $this->assertSame([['NOT_A_SALE_ITEM'], false], [$rows[$r]['alerts'], $rows[$r]['changed']]);
        $this->assertNull($conn->fetchOne('SELECT special_price FROM product WHERE id = ?', [$a]), 'aperçu : aucune écriture');

        // Application : seul A passe (B demande allowReplace, C allowBelowCost)
        $this->request('POST', '/api/gestion/promotions/appliquer', $body + ['previewHash' => 'x']);
        $this->assertSame([409, 'PRICES_CHANGED'], [$this->status(), $this->body()['error']]);
        $applied = $this->send('POST', '/api/gestion/promotions/appliquer', $body + ['previewHash' => $p['previewHash']], 201);
        $this->assertSame([1, [$a]], [$applied['changed'], array_column($applied['rows'], 'productId')]);
        $row = $conn->fetchAssociative('SELECT price, special_price, special_price_from, special_price_to FROM product WHERE id = ?', [$a]);
        $this->assertSame([2000, 1499, $startsAt->format('Y-m-d H:i:s'), $endExclusive->modify('-1 second')->format('Y-m-d H:i:s')], [(int) $row['price'], (int) $row['special_price'], substr($row['special_price_from'], 0, 19), substr($row['special_price_to'], 0, 19)], 'prix normal intact, bornes UTC');
        $this->em()->clear();
        $this->assertSame(1499.0, $this->em()->find(Product::class, $a)->getEffectivePrice(), 'prix effectif (panier, caisse) : la promotion');
        $this->assertStringContainsString('1499', json_encode($this->get("/api/products/$a")), 'la boutique relit la promotion');
        $this->assertSame(2700, (int) $conn->fetchOne('SELECT special_price FROM product WHERE id = ?', [$b]), 'B garde sa promotion (pas de allowReplace)');
        $this->assertSame([$a], array_column(array_filter($this->get('/api/gestion/promotions?status=active'), fn ($x) => $x['batchId'] === $applied['batchId']), 'productId'));

        // Terminer après le début : fin maintenant, le prix normal revient
        $ended = $this->send('POST', '/api/gestion/promotions/terminer', ['batchId' => $applied['batchId']], 200);
        $this->assertSame([1, 0], [count($ended['stopped']), count($ended['restored'])]);
        $this->em()->clear();
        $this->assertSame(2000.0, $this->em()->find(Product::class, $a)->getEffectivePrice());
        $this->assertSame(1499, (int) $conn->fetchOne('SELECT special_price FROM product WHERE id = ?', [$a]), 'gardée pour l\'historique, inactive');
        $this->request('POST', '/api/gestion/promotions/terminer', ['batchId' => $applied['batchId']]);
        $this->assertSame([409, 'ALREADY_ENDED'], [$this->status(), $this->body()['error']]);

        // Remplacement de B (allowReplace) pour demain, depuis un conseil : fait avec la date de début ; terminer avant le début remet l'ancienne
        $advice = (int) $conn->fetchOne("INSERT INTO gestion_advice (period_id, signature, moment, kind, subject, action, effect_cents, effect_label, confidence, sources, figures, params, rank, detected_on, created_at, updated_at)"
            . " VALUES (?, 'P5:slow', 'selling', 'SLOW_STOCK', ?, '{}', 300, 'tied_up', 'low', '[]', '[]', '{}', 1, CURRENT_DATE, NOW(), NOW()) RETURNING id", [static::getContainer()->get(PeriodCalendar::class)->current()->getId(), json_encode(['productIds' => [$b]])]);
        $nb = ['adviceId' => $advice, 'percent' => 20, 'startDate' => $d(1), 'endDate' => $d(10)];
        $pb = $this->send('POST', '/api/gestion/promotions/apercu', $nb, 200);
        $this->request('POST', '/api/gestion/promotions/appliquer', $nb + ['previewHash' => $pb['previewHash']]);
        $this->assertSame([422, 'NOTHING_TO_APPLY'], [$this->status(), $this->body()['error']], 'promotion existante sans allowReplace');
        $replaced = $this->send('POST', '/api/gestion/promotions/appliquer', $nb + ['previewHash' => $pb['previewHash'], 'allowReplace' => true], 201);
        $this->assertSame(['done', $d(1), 20.0], [$replaced['advice']['status'], $replaced['advice']['appliedOn'], (float) $replaced['advice']['applied']['promotionPercent']], 'conseil fait, mesure à partir du début');
        $this->assertSame(2400, (int) $conn->fetchOne('SELECT special_price FROM product WHERE id = ?', [$b]));
        $lot = $this->get('/api/gestion/promotions/lots/' . $replaced['batchId']);
        $this->assertSame(2700, $lot['rows'][0]['replaced']['specialPrice'], 'ancienne promotion gardée au journal');
        $this->assertSame([$b], array_column(array_filter($this->get('/api/gestion/promotions?status=planned'), fn ($x) => $x['batchId'] === $replaced['batchId']), 'productId'));
        $before = $this->send('POST', '/api/gestion/promotions/terminer', ['batchId' => $replaced['batchId']], 200);
        $this->assertSame([1, true], [count($before['restored']), $before['adviceRestored']]);
        $this->assertSame([2700, 'new'], [(int) $conn->fetchOne('SELECT special_price FROM product WHERE id = ?', [$b]), $conn->fetchOne('SELECT status FROM gestion_advice WHERE id = ?', [$advice])], 'pas commencée : l\'ancienne promotion revient, le conseil repasse new');

        // Retouchée depuis : tout ou rien, puis onlyUntouched
        $nc = ['productIds' => [$c, $a], 'percent' => 10, 'endDate' => $d(3)];
        $pc = $this->send('POST', '/api/gestion/promotions/apercu', $nc, 200);
        $two = $this->send('POST', '/api/gestion/promotions/appliquer', $nc + ['previewHash' => $pc['previewHash'], 'allowReplace' => true, 'allowBelowCost' => true], 201); // C : 18,00 $ sous son coût de 19,00 $
        $this->assertSame(2, $two['changed']);
        $conn->executeStatement('UPDATE product SET special_price = 1700 WHERE id = ?', [$c]);
        $this->request('POST', '/api/gestion/promotions/terminer', ['batchId' => $two['batchId']]);
        $this->assertSame([409, 'PROMOTION_EDITED_SINCE', [$c]], [$this->status(), $this->body()['error'], array_column($this->body()['rows'], 'productId')]);
        $partial = $this->send('POST', '/api/gestion/promotions/terminer', ['batchId' => $two['batchId'], 'onlyUntouched' => true], 200);
        $this->assertSame([[$a], [$c]], [array_column($partial['stopped'], 'productId'), array_column($partial['skipped'], 'productId')]);
    }

    public function testValidation(): void
    {
        $today = static::getContainer()->get(PeriodCalendar::class)->today();
        $d = fn (int $n) => $today->modify("$n days")->format('Y-m-d');
        $a = $this->product('V', 2000, 1000);
        $this->request('POST', '/api/gestion/promotions/apercu', ['percent' => 3, 'rounding' => 'up_50']);
        $this->assertSame([422, ['percent' => 'OUT_OF_RANGE', 'rounding' => 'INVALID_ROUNDING', 'endDate' => 'REQUIRED', 'productIds' => 'SCOPE_REQUIRED']], [$this->status(), $this->body()['fields']]);
        $this->request('POST', '/api/gestion/promotions/apercu', ['productIds' => [$a], 'percent' => 10, 'startDate' => $d(-1), 'endDate' => $d(5)]);
        $this->assertSame(['startDate' => 'DATE_IN_PAST'], $this->body()['fields']);
        $this->request('POST', '/api/gestion/promotions/apercu', ['productIds' => [$a], 'percent' => 10, 'startDate' => $d(5), 'endDate' => $d(4)]);
        $this->assertSame(['endDate' => 'BEFORE_START'], $this->body()['fields']);
        $this->request('POST', '/api/gestion/promotions/apercu', ['productIds' => [$a], 'percent' => 10, 'endDate' => $d(90)]);
        $this->assertSame(['endDate' => 'TOO_LONG'], $this->body()['fields'], '91 jours');
        $this->assertSame(90, $this->send('POST', '/api/gestion/promotions/apercu', ['productIds' => [$a], 'percent' => 10, 'endDate' => $d(89)], 200)['period']['days']);
        $p = $this->send('POST', '/api/gestion/promotions/apercu', ['productIds' => [$a], 'percent' => 60, 'endDate' => $d(2)], 200);
        $this->assertSame(['BELOW_COST', 'BIG_CHANGE'], $p['rows'][0]['alerts'], '8,00 $ sous le coût de 10,00 $, remise de plus de 50 %');
        $this->request('POST', '/api/gestion/promotions/terminer', ['batchId' => 999999999]);
        $this->assertSame([404, 'BATCH_NOT_FOUND'], [$this->status(), $this->body()['error']]);
        $this->request('GET', '/api/gestion/promotions?status=pigeon');
        $this->assertSame([422, ['status' => 'INVALID_STATUS']], [$this->status(), $this->body()['fields']]);
    }

    // ----- helpers -----

    private function product(string $name, int $price, ?int $purchase): int
    {
        return (int) $this->em()->getConnection()->fetchOne("INSERT INTO product (id, name, price, purchase_price, isbestseller, isfeatured, isnewarrival, isspecialoffer, is_accessory, is_landing_page, quantity, created_at, slug, product_type, sale_enabled, is_web, is_pos) VALUES (nextval('product_id_seq'), ?, ?, ?, false, false, false, false, false, false, 10, NOW(), ?, 'standard', true, true, true) RETURNING id",
            ["Promo $name", $price, $purchase, 'p5-' . bin2hex(random_bytes(5))]);
    }

    private function variant(int $product, ?int $price): int
    {
        $id = (int) $this->create(ProductVariant::class, ['product' => $this->em()->find(Product::class, $product)])->getId();
        $this->em()->getConnection()->executeStatement('UPDATE product_variant SET stock_quantity = 4, price = ? WHERE id = ?', [$price, $id]);

        return $id;
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
        $this->assertSame(200, $this->status(), $route . ' : ' . substr((string) $this->client->getResponse()->getContent(), 0, 400));

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
        $email = 'gestion-p5-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $em = $this->em();
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Gestion')->setLastname('Promo')->setRoles(['ROLE_ADMIN'])->setIsVerified(true)->setPassword('inutilisable');
        $em->persist($user);
        $em->flush();
        $this->token = static::getContainer()->get('lexik_jwt_authentication.jwt_manager')->create($user);
        $this->endOfWrite();
    }
}
