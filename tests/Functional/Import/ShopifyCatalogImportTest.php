<?php

namespace App\Tests\Functional\Import;

use App\Entity\Order;
use App\Entity\OrderItems;
use App\Entity\OrderSource;
use App\Entity\OrderType;
use App\Entity\ProductVariant;
use App\Entity\StatusCommande;
use App\Entity\User;
use App\EventSubscriber\CacheInvalidationSubscriber;
use App\Import\CatalogImportService;
use App\Import\ImportImageFetcher;
use App\Import\Message\RunCatalogImportMessage;
use App\Import\Shopify\ShopifyProductCsv;
use App\Services\ProductAiService\ProductSheetCreator;
use App\Services\TenantEntityManagerProvider;
use App\Tests\Fake\FakeImportImages;
use App\Tests\Functional\Cache\CacheTestEntityFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Import du catalogue d'une boutique Shopify par fichier (lot IMP-1, 15/11/2026, `docs/import-boutique.md`). Le fichier d'essai
 * `tests/Fixtures/import/shopify-products.csv` est composé au format de l'export des produits, avec les cas difficiles : variantes sur
 * plusieurs lignes, ligne d'image seule, HTML sur plusieurs lignes, codes-barres en double, accents, prix à virgule.
 */
class ShopifyCatalogImportTest extends WebTestCase
{
    use CacheTestEntityFactory;

    private KernelBrowser $client;
    private string $token = '';
    private string $tag;
    private int $adminId = 0;
    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->tag = strtoupper(bin2hex(random_bytes(3)));
        FakeImportImages::reset();
        $this->wipe();
        $this->token = $this->tokenFor(['ROLE_ADMIN']);
    }

    protected function tearDown(): void
    {
        $this->wipe();
        foreach ($this->files as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    public function testAnExportIsAnalysedWithoutWritingThenImportedReplayedAndCancelled(): void
    {
        $conn = $this->em()->getConnection();
        $site = static::getContainer()->get(ProductSheetCreator::class)->create(['name' => "Article du site {$this->tag}", 'priceCents' => 1000, 'barcode' => "SITE-{$this->tag}"], 'test');
        $this->assertSame(201, $site['status']);
        $this->endOfWrite();
        $before = (int) $conn->fetchOne('SELECT COUNT(*) FROM product');

        // Droits : ni anonyme, ni compte client
        $admin = $this->token;
        $this->token = '';
        $this->request('GET', '/api/site/imports');
        $this->assertSame(401, $this->status());
        $this->token = $this->tokenFor(['ROLE_USER_INTERNET']);
        $this->upload();
        $this->assertSame(403, $this->status());
        $this->token = $admin;

        // Dépôt : analysé, rien d'écrit
        $this->upload();
        $this->assertSame(201, $this->status(), (string) $this->client->getResponse()->getContent());
        $job = $this->body();
        $id = (int) $job['id'];
        $report = $job['report'];
        $this->assertSame(['analysed', 'shopify', 'catalog', 10, true], [$job['status'], $job['source'], $job['kind'], $job['rows'], $job['cancellable']]);
        $this->assertEquals(['total' => 6, 'importable' => 4, 'refused' => 2, 'alreadyImported' => 0], $report['products']); // colonne JSONB : l'ordre des clés n'est pas gardé
        $this->assertSame([7, 23], [$report['variants'], $report['stockUnits']]);
        $this->assertEqualsCanonicalizing(['Hauts', 'Maison', 'Vestes'], $report['categoriesToCreate']);
        $this->assertEquals(['total' => 3, 'refusedHost' => 1], $report['images']);
        $this->assertEquals(['kept' => 2, 'dropped' => ['duplicateInFile' => 2, 'alreadyUsed' => 1, 'invalid' => 1]], $report['barcodes']);
        $this->assertSame([1, 8], [$report['compareAtIgnored'], $report['issuesTotal']]);
        $this->assertArrayHasKey('sitePricesIncludeTax', $report);
        $codes = array_column($report['issues'], 'code', 'handle');
        $this->assertSame(['TITLE_MISSING', 'PRICE_INVALID'], [$codes['=cmd|calc'], $codes['prix-illisible']]);
        $this->assertSame($before, (int) $conn->fetchOne('SELECT COUNT(*) FROM product'), 'l\'analyse n\'écrit rien');
        $this->assertSame(0, (int) $conn->fetchOne('SELECT COUNT(*) FROM import_item'));

        // Un seul import actif par site
        $this->upload();
        $this->assertSame([409, 'IMPORT_ALREADY_ACTIVE'], [$this->status(), $this->body()['error']]);

        // Confirmation : choix refusés, puis acceptée une seule fois ; l'exécution part en tâche de fond
        $this->request('POST', "/api/site/imports/$id/confirm", ['visibility' => 'partout', 'pos' => 'oui']);
        $this->assertEquals([422, ['visibility' => 'INVALID_VALUE', 'pos' => 'MUST_BE_BOOLEAN']], [$this->status(), $this->body()['fields']]);
        $this->request('POST', "/api/site/imports/$id/confirm", ['visibility' => 'as_source', 'pos' => false]);
        $this->assertSame([202, 'running'], [$this->status(), $this->body()['status']]);
        $this->assertSame(1.0, (float) $this->body()['options']['priceFactor']);
        $sent = array_filter(static::getContainer()->get('messenger.transport.media')->getSent(), fn ($e) => $e->getMessage() instanceof RunCatalogImportMessage && $e->getMessage()->jobId === $id);
        $this->assertCount(1, $sent);
        $this->request('POST', "/api/site/imports/$id/confirm", []);
        $this->assertSame([409, 'IMPORT_NOT_READY'], [$this->status(), $this->body()['error']]);
        $this->assertSame($before, (int) $conn->fetchOne('SELECT COUNT(*) FROM product'));

        // Exécution (comme le worker). Images : une bonne, une qui n'est pas une image, une redirigée hors des serveurs permis
        FakeImportImages::$responses = [
            'https://cdn.shopify.com/s/files/1/0001/t-shirt-face.png' => ['status' => 200, 'headers' => [], 'body' => FakeImportImages::png()],
            'https://cdn.shopify.com/s/files/1/0001/t-shirt-dos.png' => ['status' => 200, 'headers' => [], 'body' => '<html>pas une image</html>'],
            'https://cdn.shopify.com/s/files/1/0001/veste-redirigee.png' => ['status' => 302, 'headers' => ['Location' => 'https://evil.example.com/veste.png'], 'body' => ''],
        ];
        $this->em();
        static::getContainer()->get(CatalogImportService::class)->run($id, 'test');
        $this->endOfWrite();
        $this->assertSame([], array_values(array_filter(FakeImportImages::$calls, fn (string $url) => !str_starts_with($url, 'https://cdn.shopify.com/'))), 'une adresse hors des serveurs permis n\'est jamais appelée, même par redirection');
        $done = $this->get("/api/site/imports/$id");
        $this->assertSame('done', $done['status']);
        $result = $done['result'];
        $this->assertSame([4, 7, 23, 2, 3, 1, 2, 0, 0], [$result['created'], $result['variants'], $result['stockUnits'], $result['barcodes'], $result['categoriesCreated'], $result['imagesStored'], $result['imagesFailed'], $result['failed'], $result['skipped']]);
        $this->assertEqualsCanonicalizing([ImportImageFetcher::NOT_AN_IMAGE, ImportImageFetcher::REFUSED], array_column($result['failures'], 'code'));
        $this->assertSame($before + 4, (int) $conn->fetchOne('SELECT COUNT(*) FROM product'));

        // T-shirt : HTML nettoyé, trois variantes avec leur prix, leur stock et leur taille, un seul code gardé, coût d'achat, catégorie, image
        $shirt = $conn->fetchAssociative("SELECT * FROM product WHERE id = (SELECT entity_id FROM import_item WHERE kind = 'product' AND ref = 't-shirt-ete')");
        $this->assertSame(['T-shirt Été', 1999.0, 750.0, true, false, 8], [$shirt['name'], (float) $shirt['price'], (float) $shirt['purchase_price'], (bool) $shirt['is_web'], (bool) $shirt['is_pos'], (int) $shirt['quantity']]);
        $this->assertStringContainsString('<strong>t-shirt</strong>', (string) $shirt['description']);
        $this->assertStringContainsString('<li>Coton bio</li>', (string) $shirt['description']);
        $this->assertFalse((bool) $shirt['isnewarrival'], 'un catalogue importé n\'est pas une nouveauté');
        $this->assertSame([(string) $shirt['image']], $conn->fetchFirstColumn('SELECT image_url FROM product_picture WHERE product_id = ?', [$shirt['id']]), 'la photo principale est aussi dans la galerie');
        $this->assertStringNotContainsString('script', (string) $shirt['description']);
        $this->assertStringNotContainsString('alert', (string) $shirt['description']);
        $this->assertMatchesRegularExpression('/^imp-[a-f0-9]{24}\.png$/', (string) $shirt['image']);
        $stored = static::getContainer()->getParameter('kernel.project_dir') . '/var/storage/public_bucket/assets/uploads/products/' . $shirt['image'];
        $this->files[] = $stored;
        $this->assertFileExists($stored);
        $variants = $conn->fetchAllAssociative('SELECT v.price, v.stock_quantity, v.barcode, s.name AS size FROM product_variant v LEFT JOIN size s ON s.id = v.size_id WHERE v.product_id = ? ORDER BY v.id', [$shirt['id']]);
        $this->assertSame([[1999, 5, '3760123456789', 'S'], [1999, 0, null, 'M'], [2100, 3, null, 'L']], array_map(fn (array $v) => [(int) $v['price'], (int) $v['stock_quantity'], $v['barcode'], $v['size']], $variants));
        $this->assertSame(['Hauts'], $conn->fetchFirstColumn('SELECT c.name FROM categories c JOIN product_categories pc ON pc.categories_id = c.id WHERE pc.product_id = ?', [$shirt['id']]));
        // Le stock de départ : des mouvements « Import », jamais une réception ni une entrée
        $movements = $conn->fetchAllAssociative('SELECT t.name, m.quantity, m.stock_before_movement, m.stock_after_movement FROM inventory_movements m JOIN movement_type t ON t.id = m.movement_type_id WHERE m.product_variant_id IN (SELECT id FROM product_variant WHERE product_id = ?) ORDER BY m.id', [$shirt['id']]);
        $this->assertSame([['Import', 5, 0, 5], ['Import', 3, 0, 3]], array_map(fn (array $m) => [$m['name'], (int) $m['quantity'], (int) $m['stock_before_movement'], (int) $m['stock_after_movement']], $movements));
        $this->assertNotContains((int) $conn->fetchOne("SELECT id FROM movement_type WHERE name = 'Import'"), array_keys(\App\Services\StockEvolutionService\StockEvolutionService::TYPES), 'le type « Import » ne compte ni dans les entrées ni dans les réceptions des statistiques');

        // Mug : sans option, prix à virgule, non publié, code en double écarté
        $mug = $conn->fetchAssociative("SELECT p.name, p.price, p.is_web, v.barcode, v.size_id, v.color_id, v.stock_quantity FROM product p JOIN product_variant v ON v.product_id = p.id WHERE p.id = (SELECT entity_id FROM import_item WHERE kind = 'product' AND ref = 'mug-cafe')");
        $this->assertSame(['Mug « Café »', 1250.0, false, null, null, null, 12], [$mug['name'], (float) $mug['price'], (bool) $mug['is_web'], $mug['barcode'], $mug['size_id'], $mug['color_id'], (int) $mug['stock_quantity']]);

        // Veste : couleur, taille, autre option ; brouillon donc masquée ; stock négatif ramené à zéro ; code de forme refusée écarté ; aucune image (redirection refusée)
        $jacket = $conn->fetchAllAssociative("SELECT p.is_web, p.image, v.price, v.stock_quantity, v.barcode, c.name AS color, s.name AS size, (SELECT string_agg(o.name || '=' || ov.value, ',') FROM product_variant_product_option_value x JOIN product_option_value ov ON ov.id = x.product_option_value_id JOIN product_option o ON o.id = ov.product_option_id WHERE x.product_variant_id = v.id) AS options"
            . " FROM product p JOIN product_variant v ON v.product_id = p.id LEFT JOIN color c ON c.id = v.color_id LEFT JOIN size s ON s.id = v.size_id WHERE p.id = (SELECT entity_id FROM import_item WHERE kind = 'product' AND ref = 'veste-laine') ORDER BY v.id");
        $this->assertSame([[false, '', 12000, 2, 'VESTE-BLEU-M', 'Bleu nuit', 'M', 'Matière=Laine'], [false, '', 12550, 0, null, 'Rouge', 'M', 'Matière=Laine']],
            array_map(fn (array $v) => [(bool) $v['is_web'], (string) $v['image'], (int) $v['price'], (int) $v['stock_quantity'], $v['barcode'], $v['color'], $v['size'], $v['options']], $jacket));

        // Code déjà porté par une fiche du site : l'article est importé sans code, la fiche du site garde le sien
        $this->assertNull($conn->fetchOne("SELECT v.barcode FROM product_variant v WHERE v.product_id = (SELECT entity_id FROM import_item WHERE kind = 'product' AND ref = 'deja-pris')"));
        $this->assertSame(1, (int) $conn->fetchOne('SELECT (SELECT COUNT(*) FROM product WHERE barcode = :c) + (SELECT COUNT(*) FROM product_variant WHERE barcode = :c)', ['c' => "SITE-{$this->tag}"]));

        // Le fichier des lignes signalées : une cellule qui commence par « = » est neutralisée
        $this->request('GET', "/api/site/imports/$id/issues.csv");
        $csv = (string) $this->client->getResponse()->getContent();
        $this->assertSame(200, $this->status());
        $this->assertStringStartsWith("\xEF\xBB\xBF" . 'line;handle;code;detail', $csv);
        $this->assertStringContainsString(";'=cmd|calc;TITLE_MISSING;", $csv);
        $this->assertStringNotContainsString(';=cmd', $csv);
        $this->assertSame(["'=1+1", "'+33", "'-2", "'@x", 'ok', ''], array_map(CatalogImportService::safeCell(...), ['=1+1', '+33', '-2', '@x', 'ok', '']));

        // Rejouer le même fichier : rien à importer, aucun doublon ; l'import analysé s'abandonne
        $this->upload();
        $replay = $this->body();
        $this->assertSame([201, 0, 4], [$this->status(), $replay['report']['products']['importable'], $replay['report']['products']['alreadyImported']]);
        $this->request('POST', "/api/site/imports/{$replay['id']}/confirm", []);
        $this->assertSame([409, 'IMPORT_NOTHING_TO_IMPORT'], [$this->status(), $this->body()['error']]);
        $this->request('POST', "/api/site/imports/{$replay['id']}/cancel");
        $this->assertSame([200, 'cancelled'], [$this->status(), $this->body()['status']]);
        $this->assertSame($before + 4, (int) $conn->fetchOne('SELECT COUNT(*) FROM product'));

        // Annulation de l'import terminé : l'article vendu est gardé, les trois autres sont retirés avec leur image et les catégories restées vides
        $soldVariant = (int) $conn->fetchOne("SELECT v.id FROM product_variant v WHERE v.product_id = (SELECT entity_id FROM import_item WHERE kind = 'product' AND ref = 'mug-cafe')");
        $this->sell($soldVariant);
        $this->request('POST', "/api/site/imports/$id/cancel");
        $cancelled = $this->body();
        $this->assertSame([200, 'cancelled', 3, 1, 'SOLD'], [$this->status(), $cancelled['status'], $cancelled['result']['cancel']['removed'], count($cancelled['result']['cancel']['kept']), $cancelled['result']['cancel']['kept'][0]['reason']]);
        $this->assertSame($before + 1, (int) $conn->fetchOne('SELECT COUNT(*) FROM product'));
        $this->assertFileDoesNotExist($stored);
        $this->assertSame(['Maison'], $conn->fetchFirstColumn("SELECT name FROM categories WHERE name IN ('Hauts', 'Maison', 'Vestes') ORDER BY name"), 'la catégorie de l\'article gardé reste');
        $this->assertSame(0, (int) $conn->fetchOne('SELECT COUNT(*) FROM product_variant WHERE barcode = ?', ['3760123456789']), 'le code est de nouveau libre');
        $this->request('POST', "/api/site/imports/$id/cancel");
        $this->assertSame([409, 'IMPORT_NOT_CANCELLABLE'], [$this->status(), $this->body()['error']]);
        $this->assertSame(['mug-cafe'], $conn->fetchFirstColumn("SELECT ref FROM import_item WHERE job_id = ? AND kind = 'product'", [$id]), 'seul l\'article gardé reste noté');
        // Rejeu après l'annulation : l'article gardé reste « déjà importé », les trois retirés redeviennent importables, sans doublon
        $this->upload();
        $again = $this->body();
        $this->assertEquals([201, 3, 1], [$this->status(), $again['report']['products']['importable'], $again['report']['products']['alreadyImported']]);
        $this->request('POST', "/api/site/imports/{$again['id']}/cancel");
        $list = $this->get('/api/site/imports')['imports'];
        $this->assertSame(['cancelled', 'cancelled', 'cancelled'], array_column($list, 'status'));
        $this->assertSame(1, (int) $conn->fetchOne("SELECT COUNT(*) FROM product WHERE name = 'Mug « Café »'"));
        $this->request('GET', '/api/site/imports/999999');
        $this->assertSame([404, 'IMPORT_NOT_FOUND'], [$this->status(), $this->body()['error']]);
    }

    public function testFilesThatAreNotAProductExportAreRefusedAndTheReaderTakesOtherSeparators(): void
    {
        foreach (['IMPORT_NOT_A_PRODUCT_FILE' => "Nom,Prix\nChaise,10\n", 'IMPORT_EMPTY_FILE' => '', 'IMPORT_TOO_MANY_ROWS' => "Handle,Title,Variant Price\n" . str_repeat("a,A,1\n", ShopifyProductCsv::MAX_ROWS + 1)] as $code => $content) {
            $this->upload($content);
            $this->assertSame([422, $code], [$this->status(), $this->body()['error'] ?? null], $code);
        }
        $this->assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM import_job'), 'un fichier refusé ne laisse pas d\'import');
        $this->client->request('POST', '/api/site/imports', ['source' => 'autre'], ['file' => $this->file("Handle,Title\na,A\n")], $this->headers(false));
        $this->assertSame([422, 'IMPORT_SOURCE_UNKNOWN'], [$this->status(), $this->body()['error']]);

        // Fichier passé par un tableur : point-virgule, guillemets, accents, lignes dans le désordre (le titre arrive après)
        $path = $this->temp("Handle;Title;Variant Price;Option1 Name;Option1 Value\r\nbol;;\"9,90\";Couleur;\"Vert d'eau\"\r\nbol;\"Bol « Été »\";;;\r\n");
        $read = (new ShopifyProductCsv())->read($path);
        $this->assertSame([null, 2, 1, 'Bol « Été »', ['Couleur', '', ''], 1, "Vert d'eau", 990], [$read['error'], $read['rows'], count($read['products']), $read['products'][0]['title'], $read['products'][0]['optionNames'],
            count($read['products'][0]['variants']), $read['products'][0]['variants'][0]['options'][0], ShopifyProductCsv::cents($read['products'][0]['variants'][0]['priceRaw'])]);
        $this->assertSame([1999, 1999, 129900, 129900, 129900, 1200, 0, null, null, null], array_map(ShopifyProductCsv::cents(...), ['19.99', '19,99', '1 299,00', '1,299.00', '1,299', '12', '0', 'abc', '-3', '']));

        // Adresses d'images : https, serveurs de la source seulement, ni identifiants ni port
        $this->assertSame([true, false, false, false, false, false], array_map(ImportImageFetcher::allowed(...), ['https://cdn.shopify.com/s/a.png', 'http://cdn.shopify.com/s/a.png', 'https://cdn.shopify.com.evil.example/a.png',
            'https://user:pass@cdn.shopify.com/a.png', 'https://cdn.shopify.com:8443/a.png', 'https://169.254.169.254/latest/meta-data']));
    }

    public function testTheWriterAppliesThePriceFactorAndTheHiddenChoiceAndAnInterruptedImportCanBeCancelled(): void
    {
        $conn = $this->em()->getConnection();
        $this->upload("Handle,Title,Type,Published,Variant Price,Variant Inventory Qty,Status\nlampe-{$this->tag},Lampe {$this->tag},Maison,TRUE,115.00,4,active\n");
        $id = (int) $this->body()['id'];
        $this->assertSame(201, $this->status(), (string) $this->client->getResponse()->getContent());
        // Le facteur de prix et le choix « tout masqué » sont appliqués par l'écriture (ici un prix taxes comprises ramené hors taxes à 15 %)
        $conn->executeStatement("UPDATE import_job SET status = 'running', started_at = NOW(), options = ? WHERE id = ?", [json_encode(['visibility' => 'hidden', 'pos' => true, 'priceFactor' => 1 / 1.15]), $id]);
        static::getContainer()->get(CatalogImportService::class)->run($id, 'test');
        $this->endOfWrite();
        $lamp = $conn->fetchAssociative('SELECT p.price, p.is_web, p.is_pos, v.price AS variant_price, v.stock_quantity FROM product p JOIN product_variant v ON v.product_id = p.id WHERE p.name = ?', ["Lampe {$this->tag}"]);
        $this->assertSame([10000.0, false, true, 10000, 4], [(float) $lamp['price'], (bool) $lamp['is_web'], (bool) $lamp['is_pos'], (int) $lamp['variant_price'], (int) $lamp['stock_quantity']]);
        $this->assertSame('done', $this->get("/api/site/imports/$id")['status']);

        // Mouvementé à la main depuis l'import : l'annulation le garde (un ajustement est une histoire, pas une trace de l'import)
        $variant = (int) $conn->fetchOne('SELECT v.id FROM product_variant v JOIN product p ON p.id = v.product_id WHERE p.name = ?', ["Lampe {$this->tag}"]);
        $conn->executeStatement('INSERT INTO inventory_movements (id, product_variant_id, movement_type_id, quantity, movement_date, stock_before_movement, stock_after_movement) VALUES ((SELECT COALESCE(MAX(id), 0) + 1 FROM inventory_movements), ?, 4, 1, NOW(), 4, 5)', [$variant]);
        $this->request('POST', "/api/site/imports/$id/cancel");
        $cancel = $this->body()['result']['cancel'];
        $this->assertSame([0, 'IN_USE'], [$cancel['removed'], $cancel['kept'][0]['reason']]);
        $this->assertSame(2, (int) $conn->fetchOne('SELECT COUNT(*) FROM inventory_movements WHERE product_variant_id = ?', [$variant]), 'aucun mouvement n\'est effacé d\'un article gardé');

        // Un import resté « en cours » : pas annulable tout de suite, annulable une fois tenu pour interrompu ; le site n'est jamais bloqué
        $this->upload("Handle,Title,Variant Price\nvase-{$this->tag},Vase {$this->tag},20\n");
        $stuck = (int) $this->body()['id'];
        $conn->executeStatement("UPDATE import_job SET status = 'running', started_at = NOW() WHERE id = ?", [$stuck]);
        $this->request('POST', "/api/site/imports/$stuck/cancel");
        $this->assertSame([409, 'IMPORT_NOT_CANCELLABLE'], [$this->status(), $this->body()['error']]);
        $conn->executeStatement("UPDATE import_job SET started_at = NOW() - INTERVAL '2 hours' WHERE id = ?", [$stuck]);
        $this->assertTrue($this->get("/api/site/imports/$stuck")['cancellable']);
        $this->request('POST', "/api/site/imports/$stuck/cancel");
        $this->assertSame([200, 'cancelled'], [$this->status(), $this->body()['status']]);
        $this->upload("Handle,Title,Variant Price\nvase-{$this->tag},Vase {$this->tag},20\n");
        $this->assertSame(201, $this->status(), 'le site n\'est plus occupé');

        // Fichier mal encodé (Latin-1) : refusé au dépôt, sans import fantôme
        $this->request('POST', '/api/site/imports/' . $this->body()['id'] . '/cancel');
        $this->upload("Handle,Title,Variant Price\nete,T-shirt " . chr(0xC9) . "t" . chr(0xE9) . ",10\n");
        $this->assertSame([422, 'IMPORT_NOT_UTF8'], [$this->status(), $this->body()['error']]);
        $this->assertNull(static::getContainer()->get(\App\Import\ImportJobStore::class)->activeId());

        // Le nettoyeur de description : balises permises sans attribut, jamais de script, de lien ni d'image
        $this->assertSame('<p>Un <strong>mot</strong></p>lien<ul><li>A</li></ul>', \App\Import\ImportHtml::clean('<p onclick="x()" style="a">Un <strong class="z">mot</strong></p><script>alert(1)</script><a href="javascript:x">lien</a><img src=x onerror=y><ul><li>A</li></ul>'));
        $this->assertNull(\App\Import\ImportHtml::clean('<script>alert(1)</script>'));
    }

    private function sell(int $variantId): void
    {
        $order = $this->create(Order::class, ['totalAmount' => 1250.0], function (Order $o, EntityManagerInterface $em): void {
            $o->setOrderDate(new \DateTime())->setStatus($em->find(StatusCommande::class, 3))->setOrderType($em->find(OrderType::class, 1))->setOrderSource($em->find(OrderSource::class, 2))->setUserId($em->find(User::class, $this->adminId));
        });
        $this->create(OrderItems::class, ['quantity' => 1, 'unitPrice' => 1250.0, 'totalPrice' => 1250.0], fn (OrderItems $i, EntityManagerInterface $em) => $i->setOrderAssociated($em->find(Order::class, $order->getId()))->setProductVariant($em->find(ProductVariant::class, $variantId)));
        $this->endOfWrite();
    }

    /** Efface ce que les imports du test ont laissé : articles importés, ventes d'essai, imports, article du site */
    private function wipe(): void
    {
        $conn = $this->em()->getConnection();
        $ids = array_map('intval', $conn->fetchFirstColumn("SELECT entity_id FROM import_item WHERE kind = 'product' UNION SELECT id FROM product WHERE name LIKE 'Article du site %' OR name LIKE 'Lampe %' OR name LIKE 'Vase %' OR name IN ('T-shirt Été', 'Mug « Café »', 'Veste en laine', 'Article au code déjà pris')"));
        if ($ids !== []) {
            $in = implode(',', $ids);
            $orders = array_map('intval', $conn->fetchFirstColumn("SELECT DISTINCT order_associated_id FROM order_items WHERE product_variant_id IN (SELECT id FROM product_variant WHERE product_id IN ($in))"));
            $conn->executeStatement("DELETE FROM order_items WHERE product_variant_id IN (SELECT id FROM product_variant WHERE product_id IN ($in))");
            if ($orders !== []) {
                $conn->executeStatement('DELETE FROM "order" WHERE id IN (' . implode(',', $orders) . ')');
            }
            $conn->executeStatement("DELETE FROM inventory_movements WHERE product_variant_id IN (SELECT id FROM product_variant WHERE product_id IN ($in))");
            $conn->executeStatement("DELETE FROM product_variant_product_option_value WHERE product_variant_id IN (SELECT id FROM product_variant WHERE product_id IN ($in))");
            $conn->executeStatement("DELETE FROM product_variant WHERE product_id IN ($in)");
            foreach (['product_categories', 'product_translation', 'product_picture'] as $table) {
                $conn->executeStatement("DELETE FROM $table WHERE product_id IN ($in)");
            }
            $conn->executeStatement("DELETE FROM product WHERE id IN ($in)");
        }
        $conn->executeStatement("DELETE FROM categories WHERE name IN ('Hauts', 'Maison', 'Vestes') AND id NOT IN (SELECT categories_id FROM product_categories)");
        $conn->executeStatement('DELETE FROM import_job');
        foreach (glob(static::getContainer()->getParameter('kernel.project_dir') . '/var/storage/private_media/imports/' . MV_TEST_TENANT_CODE . '/*.csv') ?: [] as $csv) {
            @unlink($csv); // les fichiers déposés par le test
        }
        static::getContainer()->get(CacheInvalidationSubscriber::class)->invalidateWrittenTenants();
    }

    private function upload(?string $content = null): void
    {
        $content ??= str_replace('__SITE_CODE__', "SITE-{$this->tag}", (string) file_get_contents(dirname(__DIR__, 2) . '/Fixtures/import/shopify-products.csv'));
        $this->client->request('POST', '/api/site/imports', ['source' => 'shopify'], ['file' => $this->file($content)], $this->headers(false));
    }

    private function file(string $content): UploadedFile
    {
        return new UploadedFile($this->temp($content), 'export-produits.csv', 'text/csv', null, true);
    }

    private function temp(string $content): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'imp');
        file_put_contents($path, $content);
        $this->files[] = $path;

        return $path;
    }

    private function headers(bool $json = true): array
    {
        return array_filter(['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => $json ? 'application/json' : null, 'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => $this->token !== '' ? 'Bearer ' . $this->token : null]);
    }

    private function request(string $method, string $route, ?array $body = null): void
    {
        $this->client->request($method, $route, [], [], $this->headers(), $body === null ? null : json_encode($body));
    }

    private function get(string $route): array
    {
        $this->request('GET', $route);
        $this->assertSame(200, $this->status(), $route . ' : ' . substr((string) $this->client->getResponse()->getContent(), 0, 400));

        return $this->body();
    }

    private function status(): int
    {
        return $this->client->getResponse()->getStatusCode();
    }

    private function body(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
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
        $email = 'import-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $em = $this->em();
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Irène')->setLastname('Import')->setRoles($roles)->setIsVerified(true)->setPassword('inutilisable');
        $em->persist($user);
        $em->flush();
        $this->endOfWrite();
        if (in_array('ROLE_ADMIN', $roles, true)) {
            $this->adminId = (int) $user->getId();
        }

        return static::getContainer()->get('lexik_jwt_authentication.jwt_manager')->create($user);
    }
}
