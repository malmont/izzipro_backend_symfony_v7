<?php

namespace App\Tests\Functional\Gestion;

use App\Entity\Product;
use App\Entity\ProductVariant;
use App\Entity\Size;
use App\Entity\User;
use App\EventSubscriber\CacheInvalidationSubscriber;
use App\Services\BarcodeService\BarcodeLabelPrinter;
use App\Services\BarcodeService\CatalogBarcode;
use App\Services\CommandeService\CommandeLabelService;
use App\Services\TenantEntityManagerProvider;
use App\Tests\Functional\Cache\CacheTestEntityFactory;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Étiquettes de codes-barres d'une commande fournisseur reçue, lot CB-2 (15/11/2026, `docs/codes-barres.md` § 7) :
 * `GET /api/commandes/{id}/etiquettes`, `POST …/etiquettes/codes`, `POST …/etiquettes/pdf`. Une étiquette désigne la variante qui a
 * reçu le stock ; aucune de ces routes n'écrit de stock.
 */
class CommandeLabelsApiTest extends WebTestCase
{
    use CacheTestEntityFactory;

    private KernelBrowser $client;
    private string $token = '';
    private string $tag;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->tag = bin2hex(random_bytes(3));
        $this->token = $this->tokenFor(['ROLE_ADMIN']);
    }

    protected function tearDown(): void
    {
        // D'autres tests comptent les articles : ceux du test sont effacés, avec leurs variantes, leurs mouvements et leurs commandes
        $conn = $this->em()->getConnection();
        $ids = $conn->fetchFirstColumn('SELECT id FROM product WHERE name LIKE ?', ['% ' . $this->tag]);
        if ($ids !== []) {
            $conn->executeStatement('DELETE FROM inventory_movements WHERE product_variant_id IN (SELECT id FROM product_variant WHERE product_id IN (?))', [$ids], [ArrayParameterType::INTEGER]);
            $conn->executeStatement('DELETE FROM commande_line WHERE product_id IN (?)', [$ids], [ArrayParameterType::INTEGER]);
            $conn->executeStatement('DELETE FROM product_variant WHERE product_id IN (?)', [$ids], [ArrayParameterType::INTEGER]);
            $conn->executeStatement('DELETE FROM product WHERE id IN (?)', [$ids], [ArrayParameterType::INTEGER]);
        }
        $conn->executeStatement('DELETE FROM commande WHERE name LIKE ?', ['% ' . $this->tag]);
        $conn->executeStatement('DELETE FROM size_translation WHERE size_id IN (SELECT id FROM size WHERE code = ?)', ['cb2-m-' . $this->tag]);
        $conn->executeStatement('DELETE FROM size WHERE code = ?', ['cb2-m-' . $this->tag]);
        parent::tearDown();
    }

    public function testTheLabelsOfAReceivedOrderTheMissingCodesAndThePdf(): void
    {
        $s = strtoupper($this->tag);
        $conn = $this->em()->getConnection();
        $commande = $this->commande("Étiquettes $this->tag");
        $base = "/api/commandes/$commande/etiquettes";

        // A : article sans variante, 3 reçues puis 1 annulée → la variante par défaut créée par la réception, solde 2
        $a = $this->product('Sac', 2500);
        $lineA = $this->line($commande, $a, null, 4, 0);
        $this->post("/api/commandes/$commande/lignes/$lineA/reception", ['quantity' => 3], 200);
        $this->post("/api/commandes/$commande/lignes/$lineA/reception/annuler", ['quantity' => 1], 200);
        $va = (int) $conn->fetchOne('SELECT id FROM product_variant WHERE product_id = ?', [$a]);
        // B : deux variantes, ligne sans variante, 5 reçues sur M (taille M, prix propre 4500)
        $b = $this->product('Robe', 4000);
        $size = $this->create(Size::class, ['name' => 'M', 'code' => 'cb2-m-' . $this->tag]);
        $bs = (int) $this->create(ProductVariant::class, ['product' => $this->em()->find(Product::class, $b)])->getId();
        $bm = (int) $this->create(ProductVariant::class, ['product' => $this->em()->find(Product::class, $b), 'size' => $size, 'price' => 4500])->getId();
        $lineB = $this->line($commande, $b, null, 5, 0);
        $this->post("/api/commandes/$commande/lignes/$lineB/reception", ['quantity' => 5, 'variantId' => $bm], 200);
        // C : ligne « réputée reçue » (rattachement : aucun mouvement), une seule variante, code porté par l'ARTICLE
        $c = $this->product('Gourde', 1990);
        $vc = (int) $this->create(ProductVariant::class, ['product' => $this->em()->find(Product::class, $c)])->getId();
        $this->line($commande, $c, null, 6, 6);
        // D : réputée reçue, deux variantes, ligne sans variante : la répartition n'est pas connue
        $d = $this->product('Maillot', 3000);
        $d1 = (int) $this->create(ProductVariant::class, ['product' => $this->em()->find(Product::class, $d), 'stockQuantity' => 7])->getId();
        $d2 = (int) $this->create(ProductVariant::class, ['product' => $this->em()->find(Product::class, $d), 'stockQuantity' => 0])->getId();
        $this->line($commande, $d, null, 9, 9);
        // E : réputée reçue, aucune variante (article pas encore vendable) ; F : rien de reçu
        $e = $this->product('Chapeau', 1500);
        $lineE = $this->line($commande, $e, null, 2, 2);
        $f = $this->product('Écharpe', 1200);
        $vf = (int) $this->create(ProductVariant::class, ['product' => $this->em()->find(Product::class, $f)])->getId();
        $this->line($commande, $f, null, 3, 0);
        $conn = $this->em()->getConnection();
        $conn->executeStatement('UPDATE product_variant SET barcode = NULL WHERE id IN (?)', [[$va, $bs, $bm, $vc, $d1, $d2, $vf]], [ArrayParameterType::INTEGER]);
        $conn->executeStatement('UPDATE product SET barcode = ? WHERE id = ?', ["4006381333931", $c]);
        $conn->executeStatement('UPDATE product_variant SET barcode = ? WHERE id = ?', ["FAB-$s", $d2]);
        $this->endOfWrite();
        $stock = fn (): array => [$conn->fetchAllKeyValue('SELECT id, stock_quantity FROM product_variant WHERE id IN (?) ORDER BY id', [[$va, $bs, $bm, $vc, $d1, $d2, $vf]], [ArrayParameterType::INTEGER]),
            (int) $conn->fetchOne('SELECT COUNT(*) FROM inventory_movements'), (int) $conn->fetchOne('SELECT SUM(quantity_received) FROM commande_line WHERE commande_id = ?', [$commande])];
        $stockBefore = $stock();

        // Réservé à l'administrateur ; commande inconnue
        foreach ([['GET', $base], ['POST', "$base/codes"], ['POST', "$base/pdf"]] as [$method, $route]) {
            $this->token = '';
            $this->request($method, $route);
            $this->assertSame(401, $this->status(), "$method $route anonyme");
            $this->token = $this->tokenFor(['ROLE_USER_INTERNET']);
            $this->request($method, $route);
            $this->assertSame(403, $this->status(), "$method $route compte client");
        }
        $this->token = $this->tokenFor(['ROLE_ADMIN']);
        foreach ([['GET', '/api/commandes/99999999/etiquettes'], ['POST', '/api/commandes/99999999/etiquettes/codes'], ['POST', '/api/commandes/99999999/etiquettes/pdf']] as [$method, $route]) {
            $this->request($method, $route, $method === 'POST' ? [] : null);
            $this->assertSame([404, 'COMMANDE_NOT_FOUND'], [$this->status(), $this->body()['error'] ?? null], "$method $route");
        }

        // 1. La liste : une ligne par variante qui a reçu, dans l'ordre des lignes de la commande
        $list = $this->get($base);
        $this->assertSame(['commande', 'format', 'currency', 'pricesIncludeTax', 'maxLabels', 'labels', 'missing', 'skipped', 'totals'], array_keys($list));
        $this->assertSame([['id' => $commande, 'name' => "Étiquettes $this->tag", 'receptionStatus' => 'partial'], ['code' => 'a4-50x30', 'widthMm' => 50, 'heightMm' => 30], 500],
            [$list['commande'], $list['format'], $list['maxLabels']]);
        $this->assertSame((string) $conn->fetchOne('SELECT currency FROM entreprise ORDER BY id LIMIT 1'), $list['currency']);
        $this->assertSame([$va, $bm, $vc, $d1, $d2], array_column($list['labels'], 'variantId'), 'ni la variante S (rien reçu), ni l\'article sans variante, ni la ligne sans réception');
        $this->assertSame(['variantId', 'product', 'variant', 'title', 'subtitle', 'barcode', 'barcodeOf', 'symbology', 'priceCents', 'priceIncludesTax', 'quantityReceived', 'copies', 'printable', 'reason'], array_keys($list['labels'][0]));
        $row = fn (array $l): array => [$l['title'], $l['subtitle'], $l['barcode'], $l['barcodeOf'], $l['symbology'], $l['priceCents'], $l['quantityReceived'], $l['copies'], $l['printable'], $l['reason']];
        $this->assertSame([
            ["Sac $this->tag", null, null, null, null, 2500, 2, 2, false, 'BARCODE_MISSING'], // 3 reçues, 1 annulée ; prix de la fiche
            ["Robe $this->tag", 'M', null, null, null, 4500, 5, 5, false, 'BARCODE_MISSING'], // prix propre de la variante
            ["Gourde $this->tag", null, '4006381333931', 'product', 'ean13', 1990, 6, 6, true, null], // seule variante : le code de l'article sert
            ["Maillot $this->tag", null, null, null, null, 3000, null, 7, false, 'BARCODE_MISSING'], // répartition inconnue : le stock est proposé
            ["Maillot $this->tag", null, "FAB-$s", 'variant', 'code128', 3000, null, 0, true, null],
        ], array_map($row, $list['labels']));
        $this->assertSame(['id' => $bm, 'size' => 'M', 'color' => null], $list['labels'][1]['variant']);
        $this->assertSame(['id' => $b, 'name' => "Robe $this->tag", 'code' => null], $list['labels'][1]['product']);
        $this->assertSame([false, false], [$list['pricesIncludeTax'], $list['labels'][0]['priceIncludesTax']]);
        $this->assertSame(['count' => 3, 'variantIds' => [$va, $bm, $d1]], $list['missing']);
        $this->assertSame([['lineId' => $lineE, 'product' => ['id' => $e, 'name' => "Chapeau $this->tag", 'code' => null], 'quantityReceived' => 2, 'reason' => 'NO_VARIANT']], $list['skipped']);
        $this->assertSame(['lines' => 5, 'printable' => 2, 'labels' => 6], $list['totals'], 'les exemplaires proposés des lignes imprimables : 6 + 0');
        // Site réglé « Prix affichés taxes comprises » : le prix est le même (aucun calcul), il est seulement dit TTC
        $conn->executeStatement('UPDATE entreprise SET prices_include_tax = true');
        $this->endOfWrite();
        try {
            $ttc = $this->get($base);
            $this->assertSame([true, true, 2500], [$ttc['pricesIncludeTax'], $ttc['labels'][0]['priceIncludesTax'], $ttc['labels'][0]['priceCents']]);
        } finally {
            $conn->executeStatement('UPDATE entreprise SET prices_include_tax = false');
            $this->endOfWrite();
        }
        // Commande sans rien de reçu : liste vide
        $empty = $this->commande("Vide $this->tag");
        $this->assertSame([[], ['count' => 0, 'variantIds' => []], ['lines' => 0, 'printable' => 0, 'labels' => 0], 'none'],
            [$this->get("/api/commandes/$empty/etiquettes")['labels'], $this->get("/api/commandes/$empty/etiquettes")['missing'], $this->get("/api/commandes/$empty/etiquettes")['totals'], $this->get("/api/commandes/$empty/etiquettes")['commande']['receptionStatus']]);

        // 3 (avant 2). Le PDF refuse tant qu'un code manque, et ses autres refus n'impriment rien
        $refused = function (array $body) use ($base): array {
            $this->request('POST', "$base/pdf", $body);
            $this->assertStringContainsString('json', (string) $this->client->getResponse()->headers->get('Content-Type'));

            return [$this->status(), $this->body()['error'] ?? null];
        };
        $this->assertSame([409, 'BARCODE_MISSING'], $refused(['labels' => [['variantId' => $vc, 'copies' => 2], ['variantId' => $va, 'copies' => 1], ['variantId' => $d1, 'copies' => 1]]]));
        $this->assertSame([$va, $d1], $this->body()['variantIds']);
        foreach ([[], ['labels' => []], ['labels' => 'tout'], ['labels' => ['a' => ['variantId' => $vc, 'copies' => 1]]]] as $body) {
            $this->assertSame([422, 'VALIDATION_FAILED'], $refused($body));
            $this->assertSame(['labels' => 'REQUIRED'], $this->body()['fields']);
        }
        $this->assertSame([422, 'VALIDATION_FAILED'], $refused(['labels' => [['variantId' => $vc, 'copies' => 0], ['variantId' => $vc, 'copies' => 2], ['variantId' => 'x', 'copies' => 1.5], 'rien'], 'showPrice' => 'oui']));
        $this->assertSame(['labels[0].copies' => 'MUST_BE_POSITIVE', 'labels[1].variantId' => 'DUPLICATE', 'labels[2].variantId' => 'MUST_BE_POSITIVE', 'labels[2].copies' => 'MUST_BE_POSITIVE',
            'labels[3].variantId' => 'MUST_BE_POSITIVE', 'labels[3].copies' => 'MUST_BE_POSITIVE', 'showPrice' => 'MUST_BE_BOOLEAN'], $this->body()['fields']);
        $this->assertSame([422, 'LABELS_LIMIT_EXCEEDED'], $refused(['labels' => [['variantId' => $vc, 'copies' => 300], ['variantId' => $d2, 'copies' => 201]]]));
        $this->assertSame([500, 501], [$this->body()['max'], $this->body()['requested']]);
        $this->assertSame([422, 'VARIANT_NOT_IN_COMMANDE'], $refused(['labels' => [['variantId' => $vc, 'copies' => 1], ['variantId' => $bs, 'copies' => 1], ['variantId' => $vf, 'copies' => 1]]]));
        $this->assertSame([$bs, $vf], $this->body()['variantIds'], 'la variante S n\'a rien reçu ; la variante de F non plus');
        $this->request('POST', "$base/pdf", null);
        $this->assertSame(422, $this->status(), 'sans corps : sélection attendue');

        // 2. Générer les codes manquants : une sélection, puis tout le reste ; jamais un code présent ; idempotent
        $audit = fn (): int => (int) $conn->fetchOne("SELECT COUNT(*) FROM content_audit_log WHERE resource = 'catalog_barcode' AND action = 'generate'");
        $auditBefore = $audit();
        $this->request('POST', "$base/codes", ['variantIds' => [$va, $bs]]);
        $this->assertSame([422, 'VARIANT_NOT_IN_COMMANDE', [$bs]], [$this->status(), $this->body()['error'], $this->body()['variantIds']]);
        foreach ([['variantIds' => 'tout'], ['variantIds' => [$va, 'x']], ['variantIds' => [0]]] as $body) {
            $this->request('POST', "$base/codes", $body);
            $this->assertSame([422, 'VALIDATION_FAILED', ['variantIds' => 'MUST_BE_INTEGER_LIST']], [$this->status(), $this->body()['error'], $this->body()['fields']]);
        }
        $this->assertSame($list['missing'], $this->get($base)['missing'], 'aucun refus n\'a rien écrit');
        $one = $this->post("$base/codes", ['variantIds' => [$va, $d2]], 200); // d2 a déjà son code : rien à faire pour elle
        $this->assertSame(['generated', 'commande', 'format', 'currency', 'pricesIncludeTax', 'maxLabels', 'labels', 'missing', 'skipped', 'totals'], array_keys($one));
        $this->assertSame([$va], array_column($one['generated'], 'variantId'));
        $codeA = $one['generated'][0]['barcode'];
        $this->assertTrue(str_starts_with($codeA, '29') && CatalogBarcode::isEan13($codeA), $codeA);
        $this->assertSame([$codeA, 'variant', 'ean13', true, null], array_slice($row($one['labels'][0]), 2, 3) + [3 => $one['labels'][0]['printable'], 4 => $one['labels'][0]['reason']]);
        $this->assertSame(['count' => 2, 'variantIds' => [$bm, $d1]], $one['missing']);
        $this->assertSame([$auditBefore + 1, ['scope' => 'commande', 'commandeId' => $commande, 'written' => 1]], [$audit(),
            array_intersect_key(json_decode((string) $conn->fetchOne("SELECT after_state FROM content_audit_log WHERE resource = 'catalog_barcode' ORDER BY id DESC LIMIT 1"), true), ['written' => 1, 'scope' => 1, 'commandeId' => 1])]);
        $rest = $this->post("$base/codes", [], 200);
        $this->assertSame([$bm, $d1], array_column($rest['generated'], 'variantId'));
        $this->assertSame([['count' => 0, 'variantIds' => []], ['lines' => 5, 'printable' => 5, 'labels' => 20]], [$rest['missing'], $rest['totals']], '2 + 5 + 6 + 7 + 0');
        $again = $this->post("$base/codes", [], 200);
        $this->assertSame([[], $auditBefore + 2], [$again['generated'], $audit()], 'idempotent : rien à générer, pas de ligne au journal');
        $codes = $conn->fetchAllKeyValue('SELECT id, barcode FROM product_variant WHERE id IN (?)', [[$va, $bs, $bm, $vc, $d1, $d2, $vf]], [ArrayParameterType::INTEGER]);
        $this->assertSame([$codeA, null, "FAB-$s", null, null], [$codes[$va], $codes[$bs], $codes[$d2], $codes[$vc], $codes[$vf]], 'ni la variante hors commande, ni un code présent, ni la variante servie par le code de son article');
        $this->assertCount(3, array_unique([$codes[$va], $codes[$bm], $codes[$d1]]));
        $this->assertSame('4006381333931', $conn->fetchOne('SELECT barcode FROM product WHERE id = ?', [$c]));

        // 3. Le PDF de la sélection
        $this->request('POST', "$base/pdf", ['labels' => [['variantId' => $va, 'copies' => 2], ['variantId' => $bm, 'copies' => 12], ['variantId' => $vc, 'copies' => 3]], 'showPrice' => true]);
        $response = $this->client->getResponse();
        $this->assertSame([200, 'application/pdf', "attachment; filename=\"etiquettes-commande-$commande.pdf\"", '17'],
            [$response->getStatusCode(), $response->headers->get('Content-Type'), $response->headers->get('Content-Disposition'), $response->headers->get('X-Labels-Count')]);
        $this->assertStringStartsWith('%PDF-', (string) $response->getContent());
        $this->assertGreaterThan(2000, strlen((string) $response->getContent()));
        // Ce qui part à l'impression : prix écrit seulement si on le demande (et s'il existe), symbole selon le code
        $service = static::getContainer()->get(CommandeLabelService::class);
        $entity = $service->commande($commande);
        $selection = ['labels' => [['variantId' => $va, 'copies' => 2], ['variantId' => $bm, 'copies' => 1], ['variantId' => $d2, 'copies' => 4]]];
        $conn->executeStatement('UPDATE product SET price = 0 WHERE id = ?', [$d]); // prix à zéro : « pas de prix »
        $this->endOfWrite();
        $this->em()->clear(); // l'article déjà lu par ce processus porte encore l'ancien prix
        $entity = static::getContainer()->get(CommandeLabelService::class)->commande($commande);
        $priced = static::getContainer()->get(CommandeLabelService::class)->selection($entity, $selection + ['showPrice' => true], 'fr');
        $this->assertSame([["Sac $this->tag", null, $codeA, 2], ["Robe $this->tag", 'M', $codes[$bm], 1], ["Maillot $this->tag", null, "FAB-$s", 4]], array_map(fn (array $l) => [$l['title'], $l['subtitle'], $l['barcode'], $l['copies']], $priced));
        $this->assertMatchesRegularExpression('/^25,00[\x{A0}\x{202F} ]\$( CA)?$/u', (string) $priced[0]['price']);
        $this->assertMatchesRegularExpression('/^45,00/u', (string) $priced[1]['price']);
        $this->assertNull($priced[2]['price'], 'ni la variante ni l\'article n\'ont de prix : étiquette sans prix, même demandé');
        $plain = static::getContainer()->get(CommandeLabelService::class)->selection($entity, $selection, 'fr');
        $this->assertSame([null, null, null], array_column($plain, 'price'), 'sans showPrice : pas de prix');
        $this->assertStringStartsWith('$25.00', (string) static::getContainer()->get(CommandeLabelService::class)->selection($entity, $selection + ['showPrice' => true], 'en')[0]['price']);
        $printer = static::getContainer()->get(BarcodeLabelPrinter::class);
        $html = $printer->html($priced);
        $this->assertSame([7, 3], [substr_count($html, 'class="label"'), substr_count($html, 'class="price"')], '2 + 1 + 4 étiquettes ; le prix sur celles qui en ont un');
        $this->assertSame(['ean13', 'code128', 'code128'], [BarcodeLabelPrinter::symbology('4006381333931'), BarcodeLabelPrinter::symbology('4006381333932'), BarcodeLabelPrinter::symbology("FAB-$s")]);
        try {
            $printer->html([['title' => 'x', 'subtitle' => null, 'barcode' => 'X1', 'price' => null, 'copies' => 501]]);
            $this->fail('plus de 500 étiquettes');
        } catch (\LengthException) {
            $this->addToAssertionCount(1);
        }

        // Aucune de ces routes n'a écrit de stock ni touché à la réception
        $this->assertSame($stockBefore, $stock());
    }

    private function commande(string $name): int
    {
        $period = static::getContainer()->get(\App\Gestion\Services\PeriodCalendar::class)->current()->getId(); // période courante, créée si besoin
        $conn = $this->em()->getConnection();
        $conn->executeStatement("INSERT INTO commande (id, budget, date, name, is_closed, collections_id) VALUES (nextval('commande_id_seq'), 10000, NOW(), :n, false, :c)", ['n' => $name, 'c' => $period ?: null]);

        return (int) $conn->fetchOne('SELECT id FROM commande WHERE name = ?', [$name]);
    }

    private function product(string $name, int $price): int
    {
        $conn = $this->em()->getConnection();
        $slug = 'cb2-' . bin2hex(random_bytes(4));
        $conn->executeStatement("INSERT INTO product (id, name, price, isbestseller, quantity, created_at, slug, product_type, is_web, is_pos) VALUES (nextval('product_id_seq'), :n, :p, false, 0, NOW(), :s, 'standard', false, false)",
            ['n' => "$name $this->tag", 'p' => $price, 's' => $slug]);

        return (int) $conn->fetchOne('SELECT id FROM product WHERE slug = ?', [$slug]);
    }

    private function line(int $commande, int $product, ?int $variant, int $ordered, int $received): int
    {
        $conn = $this->em()->getConnection();
        $conn->executeStatement("INSERT INTO commande_line (commande_id, product_id, product_variant_id, quantity_ordered, quantity_received, unit_cost_cents, attached_at, source, created_at, updated_at) VALUES (?, ?, ?, ?, ?, 1000, NOW(), 'document', NOW(), NOW())",
            [$commande, $product, $variant, $ordered, $received]);
        $this->endOfWrite();

        return (int) $conn->fetchOne('SELECT MAX(id) FROM commande_line WHERE commande_id = ? AND product_id = ?', [$commande, $product]);
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
        $this->client->request($method, $route, [], [], array_filter(['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => $this->token !== '' ? 'Bearer ' . $this->token : null]), $body === null ? null : json_encode($body));
    }

    private function get(string $route): array
    {
        $this->request('GET', $route);
        $this->assertSame(200, $this->status(), $route . ' : ' . substr((string) $this->client->getResponse()->getContent(), 0, 400));

        return $this->body();
    }

    private function post(string $route, array $body, int $expected): array
    {
        $this->request('POST', $route, $body);
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

    private function tokenFor(array $roles): string
    {
        $email = 'gestion-cb2-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $em = $this->em();
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Gestion')->setLastname('CB2')->setRoles($roles)->setIsVerified(true)->setPassword('inutilisable');
        $em->persist($user);
        $em->flush();
        $this->endOfWrite();

        return static::getContainer()->get('lexik_jwt_authentication.jwt_manager')->create($user);
    }
}
