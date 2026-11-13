<?php

namespace App\Tests\Functional\Gestion;

use App\Entity\Product;
use App\Entity\ProductVariant;
use App\Entity\User;
use App\EventSubscriber\CacheInvalidationSubscriber;
use App\Gestion\Services\GestionPriceService;
use App\Services\TenantEntityManagerProvider;
use App\Tests\Functional\Cache\CacheTestEntityFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Lot P4, appliquer un prix (11/11/2026, `docs/prix-gestion.md`, contrat validé par l'application) : arrondis, aperçu sans écriture (article et
 * variantes à prix propre, promotion en cours, location exclue, sous le coût, grand écart), empreinte périmée (409), application par le chemin d'un
 * article (prix relu par la boutique, journal d'audit par article), coefficient sur le coût de la commande, lien avec le guide (conseil fait puis
 * remis en new), annulation (tout ou rien, lignes intactes seulement, déjà annulé, expirée), validations.
 */
class PriceApiTest extends WebTestCase
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
        $conn->executeStatement("DELETE FROM commande_line WHERE commande_id IN (SELECT id FROM commande WHERE name = 'Prix P4')");
        $conn->executeStatement("DELETE FROM commande WHERE name = 'Prix P4'");
        $conn->executeStatement("DELETE FROM gestion_advice WHERE signature LIKE 'P4:%'");
        $conn->executeStatement("DELETE FROM product_variant WHERE product_id IN (SELECT id FROM product WHERE slug LIKE 'p4-%')");
        $conn->executeStatement("DELETE FROM product WHERE slug LIKE 'p4-%'");
        parent::tearDown();
    }

    public function testRounding(): void
    {
        $r = fn (float $c, string $mode) => GestionPriceService::round($c, $mode);
        $this->assertSame([2699, 2699, 2699, 99, 2650, 2650, 2700, 2600, 2624], [$r(2624.0, 'ends_99'), $r(2600.0, 'ends_99'), $r(2699.0, 'ends_99'), $r(1.0, 'ends_99'),
            $r(2601.0, 'up_50'), $r(2650.0, 'up_50'), $r(2601.0, 'up_100'), $r(2600.0, 'up_100'), $r(2624.4, 'none')]);
    }

    public function testPreviewApplyUndoAndAdvice(): void
    {
        $conn = $this->em()->getConnection();
        // A : 20,00 $, coût 10,00 $, une variante qui hérite et une à 25,00 $ ; B : 10,00 $, promotion à 8,00 $ en cours, coût 9,00 $ ; R : location
        $a = $this->product('A', 2000, 1000);
        $inherit = $this->variant($a, null, 5);
        $own = $this->variant($a, 2500, 3);
        $conn->executeStatement("UPDATE product_variant SET size_id = (SELECT id FROM size ORDER BY id LIMIT 1), color_id = (SELECT id FROM color ORDER BY id LIMIT 1) WHERE id = ?", [$own]);
        $expectedLabel = GestionPriceService::variantLabel($conn->fetchOne('SELECT name FROM size ORDER BY id LIMIT 1') ?: null, $conn->fetchOne('SELECT name FROM color ORDER BY id LIMIT 1') ?: null);
        $b = $this->product('B', 1000, 900);
        $conn->executeStatement("UPDATE product SET special_price = 800, special_price_from = CURRENT_DATE - 1, special_price_to = CURRENT_DATE + 1 WHERE id = ?", [$b]);
        $r = $this->product('R', 5000, 1000);
        $conn->executeStatement('UPDATE product SET rental_enabled = TRUE WHERE id = ?', [$r]);
        $this->endOfWrite();
        $readBefore = $this->get("/api/products/$a");

        $body = ['productIds' => [$a, $b, $r], 'mode' => 'percent', 'value' => 10, 'rounding' => 'ends_99'];
        $p = $this->send('POST', '/api/gestion/prix/apercu', $body, 200);
        $rows = array_map(fn ($x) => [$x['productId'] === $a ? 'A' : ($x['productId'] === $b ? 'B' : 'R'), $x['level'], $x['oldPrice'], $x['newPrice'], $x['changed'], $x['alerts']], $p['rows']);
        $this->assertSame([
            ['A', 'product', 2000, 2299, true, []],
            ['A', 'variant', 2500, 2799, true, []],
            ['B', 'product', 1000, 1199, true, ['SPECIAL_PRICE_ACTIVE']],
            ['R', 'product', 5000, 5000, false, ['NOT_A_SALE_ITEM']],
        ], $rows);
        $this->assertSame([1, $own, 1000, 1299, 50, 56.5], [$p['rows'][0]['inheritingVariants'], $p['rows'][1]['variantId'], $p['rows'][0]['marginBefore'], $p['rows'][0]['marginAfter'], $p['rows'][0]['marginRateBefore'], $p['rows'][0]['marginRateAfter']]);
        $this->assertSame(['products' => 3, 'rows' => 4, 'changed' => 3, 'skipped' => 1], $p['summary']);
        $this->assertSame([$expectedLabel, false], [$p['rows'][1]['variantLabel'], array_key_exists('variantLabel', $p['rows'][0])], 'variantLabel sur les lignes de variante');
        $this->assertSame(['XL · Noir', 'XL', 'Noir', null], [GestionPriceService::variantLabel('XL', 'Noir'), GestionPriceService::variantLabel('XL', null), GestionPriceService::variantLabel(' ', 'Noir'), GestionPriceService::variantLabel(null, null)]);
        $this->assertSame([2000, 2500], [(int) $conn->fetchOne('SELECT price FROM product WHERE id = ?', [$a]), (int) $conn->fetchOne('SELECT price FROM product_variant WHERE id = ?', [$own])], 'aperçu : aucune écriture');

        // Empreinte périmée : 409 avec l'aperçu à jour ; sans empreinte : 422
        $this->request('POST', '/api/gestion/prix/appliquer', $body + ['previewHash' => 'x']);
        $this->assertSame([409, 'PRICES_CHANGED', $p['previewHash']], [$this->status(), $this->body()['error'], $this->body()['preview']['previewHash']]);
        $this->request('POST', '/api/gestion/prix/appliquer', $body);
        $this->assertSame([422, ['previewHash' => 'REQUIRED']], [$this->status(), $this->body()['fields']]);
        // Un prix retouché entre l'aperçu et l'application : la même empreinte ne passe plus
        $conn->executeStatement('UPDATE product SET price = 2001 WHERE id = ?', [$a]);
        $this->request('POST', '/api/gestion/prix/appliquer', $body + ['previewHash' => $p['previewHash']]);
        $this->assertSame([409, 'PRICES_CHANGED', 2001], [$this->status(), $this->body()['error'], $this->body()['preview']['rows'][0]['oldPrice']]);
        $conn->executeStatement('UPDATE product SET price = 2000 WHERE id = ?', [$a]);

        // Application, avec un conseil du guide sur A : prix écrits par le chemin d'un article, conseil marqué fait
        $advice = (int) $conn->fetchOne("INSERT INTO gestion_advice (period_id, signature, moment, kind, subject, action, effect_cents, effect_label, confidence, sources, figures, params, rank, detected_on, created_at, updated_at)"
            . " VALUES (?, 'P4:fast', 'selling', 'FAST_SELLER', ?, '{}', 300, 'extra_margin', 'low', '[]', '[]', '{}', 1, CURRENT_DATE, NOW(), NOW()) RETURNING id", [static::getContainer()->get(\App\Gestion\Services\PeriodCalendar::class)->current()->getId(), json_encode(['productIds' => [$a]])]);
        $audit0 = (int) $conn->fetchOne("SELECT COUNT(*) FROM content_audit_log WHERE resource IN ('products', 'product-variants') AND resource_id IN (?, ?, ?)", [(string) $a, (string) $own, (string) $b]);
        $applied = $this->send('POST', '/api/gestion/prix/appliquer', $body + ['previewHash' => $p['previewHash'], 'adviceId' => null], 201);
        $this->assertSame(3, $applied['changed']);
        $this->assertSame([2299, 2799, 1199, 800], [(int) $conn->fetchOne('SELECT price FROM product WHERE id = ?', [$a]), (int) $conn->fetchOne('SELECT price FROM product_variant WHERE id = ?', [$own]),
            (int) $conn->fetchOne('SELECT price FROM product WHERE id = ?', [$b]), (int) $conn->fetchOne('SELECT special_price FROM product WHERE id = ?', [$b])], 'prix promotionnel intact');
        $this->assertNull($conn->fetchOne('SELECT price FROM product_variant WHERE id = ?', [$inherit]), 'la variante qui hérite garde null');
        $this->assertSame($audit0 + 3, (int) $conn->fetchOne("SELECT COUNT(*) FROM content_audit_log WHERE resource IN ('products', 'product-variants') AND resource_id IN (?, ?, ?)", [(string) $a, (string) $own, (string) $b]), 'une entrée d\'audit par article ou variante');
        $readAfter = $this->get("/api/products/$a");
        $this->assertNotSame(json_encode($readBefore), json_encode($readAfter), 'la boutique relit le nouveau prix (cache invalidé)');
        $this->assertStringContainsString('2299', json_encode($readAfter));

        // Lien avec le guide : appliquer depuis le conseil le marque fait (applied.pricePercent), l'annulation le remet en new
        $pa = $this->send('POST', '/api/gestion/prix/apercu', ['adviceId' => $advice, 'mode' => 'percent', 'value' => 5], 200);
        $this->assertSame([$a, $a], array_column($pa['rows'], 'productId'));
        $byAdvice = $this->send('POST', '/api/gestion/prix/appliquer', ['adviceId' => $advice, 'mode' => 'percent', 'value' => 5, 'previewHash' => $pa['previewHash']], 201);
        $this->assertSame(['done', 5.0, [$a]], [$byAdvice['advice']['status'], (float) $byAdvice['advice']['applied']['pricePercent'], $byAdvice['advice']['applied']['productIds']]);
        $this->send('POST', '/api/gestion/prix/annuler', ['batchId' => $byAdvice['batchId']], 200);
        $this->assertSame(['new', null], [$conn->fetchOne('SELECT status FROM gestion_advice WHERE id = ?', [$advice]), $conn->fetchOne('SELECT applied_on FROM gestion_advice WHERE id = ?', [$advice])]);
        $this->assertSame(2299, (int) $conn->fetchOne('SELECT price FROM product WHERE id = ?', [$a]), 'annulé : retour au prix du lot précédent');

        // Annulation du premier lot après une retouche de B : tout ou rien, puis lignes intactes seulement
        $conn->executeStatement('UPDATE product SET price = 1250 WHERE id = ?', [$b]);
        $this->request('POST', '/api/gestion/prix/annuler', ['batchId' => $applied['batchId']]);
        $this->assertSame([409, 'PRICE_EDITED_SINCE', [[$b, 1250]]], [$this->status(), $this->body()['error'], array_map(fn ($x) => [$x['productId'], $x['currentPrice']], $this->body()['rows'])]);
        $this->assertSame(2299, (int) $conn->fetchOne('SELECT price FROM product WHERE id = ?', [$a]), 'rien n\'est annulé');
        $u = $this->send('POST', '/api/gestion/prix/annuler', ['batchId' => $applied['batchId'], 'onlyUntouched' => true], 200);
        $this->assertSame([2, 1], [count($u['restored']), count($u['skipped'])]);
        $this->assertSame([2000, 2500, 1250], [(int) $conn->fetchOne('SELECT price FROM product WHERE id = ?', [$a]), (int) $conn->fetchOne('SELECT price FROM product_variant WHERE id = ?', [$own]), (int) $conn->fetchOne('SELECT price FROM product WHERE id = ?', [$b])]);
        $this->request('POST', '/api/gestion/prix/annuler', ['batchId' => $applied['batchId']]);
        $this->assertSame([409, 'ALREADY_UNDONE'], [$this->status(), $this->body()['error']]);
        $lot = $this->get('/api/gestion/prix/lots/' . $applied['batchId']);
        $this->assertSame([false, 'ALREADY_UNDONE', 3, 1], [$lot['undoable'], $lot['undoBlockedBy'], count($lot['rows']), count($lot['undoSkipped'])]);
        $this->assertSame([null, $expectedLabel], [$lot['rows'][0]['variantLabel'], $lot['rows'][1]['variantLabel']], 'variantLabel dans le détail du lot');

        // Grand écart et prix sous le coût : écrits seulement avec l'accord explicite
        $big = ['productIds' => [$b], 'mode' => 'percent', 'value' => 60];
        $pb = $this->send('POST', '/api/gestion/prix/apercu', $big, 200);
        $this->assertSame(['BIG_CHANGE', 'SPECIAL_PRICE_ACTIVE'], $pb['rows'][0]['alerts']);
        $this->request('POST', '/api/gestion/prix/appliquer', $big + ['previewHash' => $pb['previewHash']]);
        $this->assertSame([422, 'NOTHING_TO_APPLY'], [$this->status(), $this->body()['error']]);
        $this->send('POST', '/api/gestion/prix/appliquer', $big + ['previewHash' => $pb['previewHash'], 'allowBigChange' => true], 201);
        $this->assertSame(2000, (int) $conn->fetchOne('SELECT price FROM product WHERE id = ?', [$b]));
        $below = ['productIds' => [$a], 'mode' => 'percent', 'value' => -40, 'includeVariants' => false];
        $pl = $this->send('POST', '/api/gestion/prix/apercu', $below, 200);
        $this->assertSame([1200, []], [$pl['rows'][0]['newPrice'], $pl['rows'][0]['alerts']], 'coût 10,00 : 12,00 reste au-dessus, écart de 40 % sous le seuil de 50 %');
        $below['value'] = -60;
        $pl = $this->send('POST', '/api/gestion/prix/apercu', $below, 200);
        $this->assertSame([800, ['BELOW_COST', 'BIG_CHANGE']], [$pl['rows'][0]['newPrice'], $pl['rows'][0]['alerts']]);
        $this->request('POST', '/api/gestion/prix/appliquer', $below + ['previewHash' => $pl['previewHash'], 'allowBigChange' => true]);
        $this->assertSame([422, 'NOTHING_TO_APPLY'], [$this->status(), $this->body()['error']], 'sous le coût sans accord');

        // Lot expiré
        $conn->executeStatement("UPDATE gestion_price_batch SET created_at = NOW() - INTERVAL '25 hours' WHERE id = (SELECT MAX(id) FROM gestion_price_batch)");
        $last = (int) $conn->fetchOne('SELECT MAX(id) FROM gestion_price_batch');
        $this->request('POST', '/api/gestion/prix/annuler', ['batchId' => $last]);
        $this->assertSame([409, 'UNDO_EXPIRED'], [$this->status(), $this->body()['error']]);
    }

    public function testCoefficientOnCommandeCostAndValidation(): void
    {
        $conn = $this->em()->getConnection();
        // C : prix d'achat de la fiche 5,00 $, mais 12,00 $ sur la commande → coefficient 2 : 24,00 $ (Product::updatePrice : coût × coefficient)
        $c = $this->product('C', 1500, 500);
        $cid = (int) $conn->fetchOne("INSERT INTO commande (id, date, name, kind, budget) VALUES (nextval('commande_id_seq'), NOW(), 'Prix P4', 'standard', 0) RETURNING id");
        $conn->executeStatement('INSERT INTO commande_line (commande_id, product_id, quantity_ordered, unit_cost_cents, attached_at, created_at, updated_at) VALUES (?, ?, 4, 1200, NOW(), NOW(), NOW())', [$cid, $c]);
        $p = $this->send('POST', '/api/gestion/prix/apercu', ['commandeId' => $cid, 'mode' => 'coefficient', 'value' => 2], 200);
        $this->assertSame([2400, 1200, ['BIG_CHANGE']], [$p['rows'][0]['newPrice'], $p['rows'][0]['cost'], $p['rows'][0]['alerts']]);
        $d = $this->product('D', 1500, null);
        $this->assertSame(['COST_UNKNOWN'], $this->send('POST', '/api/gestion/prix/apercu', ['productIds' => [$d], 'mode' => 'coefficient', 'value' => 2], 200)['rows'][0]['alerts']);

        $this->request('POST', '/api/gestion/prix/apercu', ['mode' => 'pigeon', 'value' => 'x', 'rounding' => 'au_hasard']);
        $this->assertSame([422, ['mode' => 'INVALID_MODE', 'value' => 'MUST_BE_NUMBER', 'rounding' => 'INVALID_ROUNDING', 'productIds' => 'SCOPE_REQUIRED']], [$this->status(), $this->body()['fields']]);
        $this->request('POST', '/api/gestion/prix/apercu', ['productIds' => [$c], 'commandeId' => $cid, 'mode' => 'percent', 'value' => 301]);
        $this->assertSame(['value' => 'OUT_OF_RANGE', 'productIds' => 'SCOPE_AMBIGUOUS'], $this->body()['fields']);
        $this->request('POST', '/api/gestion/prix/apercu', ['productIds' => [999999999], 'mode' => 'percent', 'value' => 5]);
        $this->assertSame(['productIds[0]' => 'PRODUCT_NOT_FOUND'], $this->body()['fields']);
        $this->request('POST', '/api/gestion/prix/apercu', ['commandeId' => 999999999, 'mode' => 'percent', 'value' => 5]);
        $this->assertSame(['commandeId' => 'COMMANDE_NOT_FOUND'], $this->body()['fields']);
        $this->request('POST', '/api/gestion/prix/apercu', ['productIds' => range(1, 501), 'mode' => 'percent', 'value' => 5]);
        $this->assertSame(['productIds' => 'TOO_MANY'], $this->body()['fields']);
        $this->request('POST', '/api/gestion/prix/annuler', ['batchId' => 999999999]);
        $this->assertSame([404, 'BATCH_NOT_FOUND'], [$this->status(), $this->body()['error']]);
    }

    /** Durée d'un lot de 500 articles (mesure, PRICE_PERF=1) */
    public function testFiveHundredRowsDuration(): void
    {
        if (getenv('PRICE_PERF') !== '1') {
            $this->markTestSkipped('Mesure de durée : PRICE_PERF=1');
        }
        $ids = [];
        for ($i = 0; $i < 500; ++$i) {
            $ids[] = $this->product("Perf $i", 1000 + $i, 500);
        }
        $body = ['productIds' => $ids, 'mode' => 'percent', 'value' => 5];
        $t0 = microtime(true);
        $p = $this->send('POST', '/api/gestion/prix/apercu', $body, 200);
        $t1 = microtime(true);
        $r = $this->send('POST', '/api/gestion/prix/appliquer', $body + ['previewHash' => $p['previewHash']], 201);
        $t2 = microtime(true);
        $this->send('POST', '/api/gestion/prix/annuler', ['batchId' => $r['batchId']], 200);
        $t3 = microtime(true);
        fwrite(STDERR, sprintf("\nP4 500 lignes : aperçu %.2f s, application %.2f s, annulation %.2f s\n", $t1 - $t0, $t2 - $t1, $t3 - $t2));
        $this->assertSame(500, $r['changed']);
    }

    // ----- helpers -----

    private function product(string $name, int $price, ?int $purchase): int
    {
        $conn = $this->em()->getConnection();
        $slug = 'p4-' . bin2hex(random_bytes(5));

        return (int) $conn->fetchOne("INSERT INTO product (id, name, price, purchase_price, isbestseller, isfeatured, isnewarrival, isspecialoffer, is_accessory, is_landing_page, quantity, created_at, slug, product_type, sale_enabled, is_web, is_pos) VALUES (nextval('product_id_seq'), ?, ?, ?, false, false, false, false, false, false, 10, NOW(), ?, 'standard', true, true, true) RETURNING id",
            ["Prix $name", $price, $purchase, $slug]);
    }

    private function variant(int $product, ?int $price, int $stock): int
    {
        $id = (int) $this->create(ProductVariant::class, ['product' => $this->em()->find(Product::class, $product)])->getId();
        $this->em()->getConnection()->executeStatement('UPDATE product_variant SET stock_quantity = ?, price = ? WHERE id = ?', [$stock, $price, $id]);

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
        $email = 'gestion-p4-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $em = $this->em();
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Gestion')->setLastname('Prix')->setRoles(['ROLE_ADMIN'])->setIsVerified(true)->setPassword('inutilisable');
        $em->persist($user);
        $em->flush();
        $this->token = static::getContainer()->get('lexik_jwt_authentication.jwt_manager')->create($user);
        $this->endOfWrite();
    }
}
