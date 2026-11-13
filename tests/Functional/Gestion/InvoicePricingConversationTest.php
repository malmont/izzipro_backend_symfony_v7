<?php

namespace App\Tests\Functional\Gestion;

use App\Entity\User;
use App\EventSubscriber\CacheInvalidationSubscriber;
use App\Gestion\Message\ReadGestionDocumentMessage;
use App\Gestion\MessageHandler\ReadGestionDocumentHandler;
use App\Gestion\Services\PeriodCalendar;
use App\Services\TenantEntityManagerProvider;
use App\Tests\Fake\FakeLandingAiClient;
use App\Tests\Functional\Cache\CacheTestEntityFactory;
use App\Tests\Functional\Support\AiRightTestHelper;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Prix de vente et mise en ligne dans le fil d'une facture (lot IA-ACT-5, 13/11/2026, `docs/conversation-gestion.md` § 13) : le
 * coefficient proposé pour un article créé (semblables, fournisseur, site) avec ses chiffres, le coefficient de la facture et celui
 * d'une ligne, l'arrondi du site, le prix d'un article déjà au catalogue (garder, coefficient, prix saisi), la mise en ligne en caisse et
 * sur la boutique séparément (non, tout de suite, à la réception), la réception qui met en ligne, l'annulation et ses refus.
 */
class InvoicePricingConversationTest extends WebTestCase
{
    use CacheTestEntityFactory;
    use PricingFixtures;

    private KernelBrowser $client;
    private string $token = '';
    private string $tag = '';
    private int $usageMark = 0;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        FakeLandingAiClient::reset();
        AiRightTestHelper::restoreFullAi(static::getContainer());
        $this->tag = bin2hex(random_bytes(3));
        $this->login(['ROLE_ADMIN']);
        $conn = $this->em()->getConnection();
        $this->usageMark = (int) $conn->fetchOne('SELECT COALESCE(MAX(id), 0) FROM ai_usage');
        $conn->executeStatement("UPDATE gestion_document SET status = 'failed' WHERE status = 'reading'"); // base partagée : une lecture à la fois par site
        $conn->executeStatement('UPDATE entreprise SET gestion_default_coefficient = NULL, gestion_min_coefficient = NULL, gestion_price_rounding = NULL');
    }

    protected function tearDown(): void
    {
        $this->em()->getConnection()->executeStatement('DELETE FROM ai_usage WHERE id > ?', [$this->usageMark]);
        $this->removePricingFixtures();
        parent::tearDown();
    }

    public function testAnInvoiceSetsPricesAndPutsItemsOnSaleThenTheReceptionPublishesAndUndoIsRefusedOrRestoresEverything(): void
    {
        $tag = $this->tag;
        $conn = fn () => $this->em()->getConnection();
        $calendar = static::getContainer()->get(PeriodCalendar::class);
        $today = $calendar->today()->format('Y-m-d');
        $season = PeriodCalendar::definition($calendar->today()->modify('-1 year'), $calendar->frequency());
        // Un fournisseur dont 5 articles à 10,00 de coût, affichés à 2,5, se sont vendus à 90 % sans rabais la même saison l'an dernier
        $supplier = $this->supplier("Maison Prix $tag");
        foreach (range(1, 5) as $i) {
            $this->sale($this->article($supplier, "Historique $i $tag", 1000, 2500, $season['start']->modify('+2 days')->format('Y-m-d'))[1], 9, 2500, 2500, $season['start']->modify('+16 days')->format('Y-m-d'));
        }
        $conn()->executeStatement("INSERT INTO product (id, name, price, isbestseller, quantity, created_at, slug, product_type, code, purchase_price) VALUES (nextval('product_id_seq'), :n, 2500, false, 5, NOW(), :s, 'standard', :c, 900)", ['n' => "Tuque prix $tag", 's' => "tuque-prix-$tag", 'c' => "TP-$tag"]);
        $tuque = (int) $conn()->fetchOne('SELECT id FROM product WHERE slug = ?', ["tuque-prix-$tag"]);
        $this->endOfWrite();
        // libellés des articles nouveaux sur les factures : aucun mot d'un article du catalogue de la base partagée (le rapprochement se fait par les mots du nom)
        $new = [bin2hex(random_bytes(5)) . ' ' . bin2hex(random_bytes(5)), bin2hex(random_bytes(5)) . ' ' . bin2hex(random_bytes(5)), bin2hex(random_bytes(5)) . ' ' . bin2hex(random_bytes(5))];
        $similarLabel = '2,7 : 5 semblables de ce fournisseur affichés à 2,5, vendus à 90 % en 2 semaines pour les unités vendues, sans rabais (+0,2)';
        $reading = fn (string $number, array $lines) => ['supplierName' => "Maison Prix $tag", 'invoiceNumber' => $number, 'invoiceDate' => $today, 'currency' => 'CAD', 'amountsMode' => 'ht', 'lines' => $lines,
            'taxes' => [], 'shipping' => null, 'subtotal' => null, 'taxTotal' => null, 'total' => null, 'confidence' => ['overall' => 0.9, 'number' => 0.9, 'date' => 0.9, 'lines' => 0.9, 'totals' => 0.9], 'notes' => []];
        $answer = fn (int $id, string $question, mixed $value, int $expected = 200): array => $this->post("/api/gestion/conversations/$id/answers", ['answers' => [['questionId' => $question, 'value' => $value]]], $expected);

        // ---- Une facture : un article déjà au catalogue (reconnu par son code) et un article nouveau ----
        [$id] = $this->startThread($reading("P1-$tag", [
            ['baseLabel' => 'Tuque prix', 'supplierCode' => "TP-$tag", 'quantity' => 4, 'unitCost' => 1000, 'total' => 4000],
            ['baseLabel' => $new[0], 'quantity' => 2, 'unitCost' => 1100, 'total' => 2200],
        ]));
        $thread = $this->get("/api/gestion/conversations/$id");
        $questions = array_column($thread['questions'], null, 'id');
        $this->assertSame([['supplier', 'line:n2', 'price.mode'], 1], [array_keys($questions), $thread['openQuestions']], 'le prix des articles existants est UNE question PROPOSÉE : elle ne compte pas parmi les questions ouvertes');
        // Prix des articles déjà au catalogue : une seule question pour la facture, proposée à « garder » ; elle dit ce que donnerait le coefficient proposé
        $mode = $questions['price.mode'];
        $this->assertSame(['price.mode', 'lines', 'keep', true, ['type' => 'choice'], 'Cette facture comprend un article déjà en vente. Voulez-vous toucher à son prix de vente ?'],
            [$mode['kind'], $mode['path'], $mode['answer'], $mode['proposed'], $mode['input'], $mode['text']]);
        $this->assertSame([['keep', 'Garder les prix actuels'], ['suggested', 'Appliquer à chacun le coefficient proposé'], ['each', 'Régler article par article']], array_map(fn (array $o) => [$o['value'], $o['label']], $mode['options']));
        $this->assertSame([['productId' => $tuque, 'name' => "Tuque prix $tag", 'lines' => [1], 'currentPrice' => 2500, 'cost' => 1000, 'currentCoefficient' => 2.5, 'proposed' => 2.7, 'proposedSource' => 'similar', 'label' => $similarLabel, 'price' => 2799]], $mode['articles']);
        $line = fn (array $thread, int $n): array => array_column($thread['preview']['lines'], null, 'n')[$n];
        $kept = ['mode' => 'keep', 'coefficient' => null, 'newPrice' => 2500, 'currentPrice' => 2500, 'changed' => false, 'belowCost' => false];
        $this->assertSame([$kept, null], [$line($thread, 1)['price'], $line($thread, 1)['plannedPrice']], 'l\'aperçu montre le prix avant et après de chaque article, même gardé');

        // L'article nouveau est créé : le coefficient proposé vient de ses semblables, avec les chiffres ; la mise en ligne est proposée à « non », par canal
        $thread = $answer($id, 'line:n2', ['create' => ['name' => "Écharpe neuve $tag"]]);
        $questions = array_column($thread['questions'], null, 'id');
        $this->assertSame(['ready', 0, ['supplier', 'line:n2', 'coefficient', 'publish.pos', 'publish.web', 'price.mode']], [$thread['state'], $thread['openQuestions'], array_keys($questions)]);
        $coefficient = $questions['coefficient'];
        $this->assertSame(['coefficient', 'lines', 'suggested', true, ['type' => 'choice', 'number' => ['min' => 1, 'max' => 20]]], [$coefficient['kind'], $coefficient['path'], $coefficient['answer'], $coefficient['proposed'], $coefficient['input']]);
        $this->assertSame([['suggested', $similarLabel], [2.5, '2,5 : constaté chez ce fournisseur, sur 5 articles'], [2, '2 : le coefficient par défaut du site']], array_map(fn (array $o) => [$o['value'], $o['label']], $coefficient['options']));
        $article = $coefficient['articles'][0];
        $this->assertSame([[2], "Écharpe neuve $tag", 1100, 2.7, 'similar', 2.7, $similarLabel, 2999, 5, 0.9], [$article['lines'], $article['name'], $article['cost'], $article['coefficient'], $article['source'], $article['proposed'], $article['label'], $article['price'],
            $article['similar']['count'], $article['similar']['soldShare']]);
        foreach (['publish.pos' => 'En caisse', 'publish.web' => 'Sur la boutique en ligne'] as $key => $title) {
            $this->assertSame([$title, 'no', true, ['no', 'now', 'reception'], ['Non, pas maintenant', 'Oui, tout de suite', 'À la réception du stock']],
                [$questions[$key]['title'], $questions[$key]['answer'], $questions[$key]['proposed'], array_column($questions[$key]['options'], 'value'), array_column($questions[$key]['options'], 'label')]);
        }
        $created = fn (array $thread): array => array_intersect_key($line($thread, 2), array_flip(['kind', 'coefficient', 'plannedPrice', 'publishPos', 'publishWeb', 'mentions']));
        // 11,00 × 2,7 = 29,70, arrondi du site (prix psychologique) : 29,99 ; invisible partout
        $this->assertSame(['kind' => 'create', 'coefficient' => 2.7, 'plannedPrice' => 2999, 'publishPos' => 'no', 'publishWeb' => 'no', 'mentions' => []], $created($thread));
        $this->assertSame(['existing', 2500, $kept, null], [$line($thread, 1)['kind'], $line($thread, 1)['currentPrice'], $line($thread, 1)['price'], $line($thread, 1)['plannedPrice']], 'sans décision, le prix de l\'article existant est gardé');

        // Refus : chaque réponse est contrôlée ; le prix d'un article ne se règle pas tant que « article par article » n'est pas choisi
        $refused = [
            ['coefficient', 0, 'MUST_BE_POSITIVE'], ['coefficient', 21, 'OUT_OF_RANGE'], ['coefficient', 'beaucoup', 'MUST_BE_POSITIVE'],
            ['publish.pos', 'plus tard', 'INVALID_VALUE'], ['publish.web', true, 'INVALID_VALUE'], ['price.mode', 'au hasard', 'INVALID_VALUE'],
            ['line:n2', ['create' => ['name' => 'X', 'publishWeb' => 'un jour']], 'INVALID_VALUE'],
        ];
        foreach ($refused as [$question, $value, $code]) {
            $this->assertSame($code, $answer($id, $question, $value, 422)['fields']['answers[0].value'] ?? null, json_encode([$question, $value]));
        }
        $this->assertSame('UNKNOWN_QUESTION', $answer($id, "price:$tuque", ['mode' => 'keep'], 422)['fields']['answers[0].questionId'] ?? null);
        // Un coefficient pour toute la facture, puis pour une seule ligne, puis retour au coefficient proposé
        $thread = $answer($id, 'coefficient', 3);
        $this->assertSame([3, 3399, 'invoice', false], [$created($thread)['coefficient'], $created($thread)['plannedPrice'], array_column($thread['questions'], null, 'id')['coefficient']['articles'][0]['source'], array_column($thread['questions'], null, 'id')['coefficient']['proposed']]);
        $thread = $answer($id, 'line:n2', ['create' => ['name' => "Écharpe neuve $tag", 'coefficient' => 2]]);
        $this->assertSame([2, 2299, 'line'], [$created($thread)['coefficient'], $created($thread)['plannedPrice'], array_column($thread['questions'], null, 'id')['coefficient']['articles'][0]['source']], 'le coefficient d\'une ligne passe avant celui de la facture');
        $answer($id, 'line:n2', ['create' => ['name' => "Écharpe neuve $tag"]]);
        $thread = $answer($id, 'coefficient', 'suggested');
        $this->assertSame([2.7, 2999], [$created($thread)['coefficient'], $created($thread)['plannedPrice']]);
        // Mise en ligne : en caisse tout de suite, sur la boutique à la réception du stock ; l'aperçu dit ce que le client verra
        $answer($id, 'publish.pos', 'now');
        $thread = $answer($id, 'publish.web', 'reception');
        $this->assertSame(['now', 'reception', ['STOCK_ZERO', 'NO_PHOTO']], [$created($thread)['publishPos'], $created($thread)['publishWeb'], $created($thread)['mentions']]);
        // Prix des articles existants : « appliquer à chacun le coefficient proposé » (10,00 × 2,7 = 27,00 → 27,99), sans question par article
        $thread = $answer($id, 'price.mode', 'suggested');
        $this->assertSame([['mode' => 'coefficient', 'coefficient' => 2.7, 'newPrice' => 2799, 'currentPrice' => 2500, 'changed' => true, 'belowCost' => false], 2799, 2.7, false], [$line($thread, 1)['price'], $line($thread, 1)['plannedPrice'], $line($thread, 1)['coefficient'],
            in_array("price:$tuque", array_column($thread['questions'], 'id'), true)]);
        // « Régler article par article » : la question de l'article apparaît, proposée à « garder », avec chaque coefficient possible, ses chiffres et le prix qu'il donne
        $thread = $answer($id, 'price.mode', 'each');
        $questions = array_column($thread['questions'], null, 'id');
        $this->assertSame([['supplier', 'line:n2', 'coefficient', 'publish.pos', 'publish.web', 'price.mode', "price:$tuque"], 'ready', $kept], [array_keys($questions), $thread['state'], $line($thread, 1)['price']]);
        $price = $questions["price:$tuque"];
        $this->assertSame(['line.price', 'lines[1].price', true, ['mode' => 'keep'], ['id' => $tuque, 'name' => "Tuque prix $tag"], [1], 2500, 1000, 2.5],
            [$price['kind'], $price['path'], $price['proposed'], $price['answer'], $price['product'], $price['lines'], $price['currentPrice'], $price['cost'], $price['currentCoefficient']]);
        $this->assertSame([
            [['mode' => 'keep'], 'Garder 25,00 CAD (coefficient actuel 2,5)', null],
            [['mode' => 'coefficient', 'coefficient' => 2.7], $similarLabel . ' → 27,99 CAD', 2799],
            [['mode' => 'coefficient', 'coefficient' => 2.5], '2,5 : constaté chez ce fournisseur, sur 5 articles → 25,99 CAD', 2599],
            [['mode' => 'coefficient', 'coefficient' => 2], '2 : le coefficient par défaut du site → 20,99 CAD', 2099],
        ], array_map(fn (array $o) => [$o['value'], $o['label'], $o['price'] ?? null], $price['options']));
        $this->assertSame(['type' => 'choice', 'money' => ['currency' => 'CAD'], 'number' => ['min' => 1, 'max' => 20]], $price['input']);
        foreach ([[['mode' => 'gratuit'], 'INVALID_VALUE'], [['mode' => 'fixed', 'price' => 0], 'INVALID_AMOUNT'], [['mode' => 'fixed', 'price' => 31.5], 'INVALID_AMOUNT'],
            [['mode' => 'coefficient', 'coefficient' => 25], 'OUT_OF_RANGE'], [['mode' => 'coefficient', 'coefficient' => 0], 'MUST_BE_POSITIVE']] as [$value, $code]) {
            $this->assertSame($code, $answer($id, "price:$tuque", $value, 422)['fields']['answers[0].value'] ?? null, json_encode($value));
        }
        $thread = $answer($id, "price:$tuque", ['mode' => 'coefficient']);
        $this->assertSame([2799, 2.7], [$line($thread, 1)['price']['newPrice'], $line($thread, 1)['price']['coefficient']], 'sans coefficient : celui que le serveur propose pour cet article');
        // puis un prix saisi, écrit tel quel
        $thread = $answer($id, "price:$tuque", ['mode' => 'fixed', 'price' => 3150]);
        $this->assertSame([['mode' => 'fixed', 'coefficient' => null, 'newPrice' => 3150, 'currentPrice' => 2500, 'changed' => true, 'belowCost' => false], 'ready'], [$line($thread, 1)['price'], $thread['state']]);
        $this->assertSame(2500, (int) round((float) $conn()->fetchOne('SELECT price FROM product WHERE id = ?', [$tuque])), 'l\'aperçu n\'écrit rien');

        // Confirmation : l'article créé a son prix arrondi et sa mise en ligne par canal ; l'article existant a son nouveau prix, au journal
        $audits = fn (string $resource, int $resourceId): int => (int) $conn()->fetchOne('SELECT COUNT(*) FROM content_audit_log WHERE resource = ? AND resource_id = ?', [$resource, (string) $resourceId]);
        $priceAudits = $audits('products', $tuque);
        $done = $this->post("/api/gestion/conversations/$id/confirm", ['previewHash' => $thread['previewHash']], 201);
        $commande = (int) $done['commande']['id'];
        $scarf = (int) $done['created'][0]['productId'];
        $this->assertSame([['level' => 'product', 'productId' => $tuque, 'variantId' => null, 'name' => "Tuque prix $tag", 'old' => 2500, 'new' => 3150, 'mode' => 'fixed', 'coefficient' => null]], $done['prices']);
        $this->assertSame([3150, $priceAudits + 1], [(int) round((float) $conn()->fetchOne('SELECT price FROM product WHERE id = ?', [$tuque])), $audits('products', $tuque)]);
        $state = fn (int $product): array => array_map(fn ($v) => is_bool($v) ? $v : (int) round((float) $v), $conn()->fetchAssociative('SELECT price, purchase_price, COALESCE(is_pos, false) AS is_pos, COALESCE(is_web, false) AS is_web, publish_pos_on_reception AS pos_later, publish_web_on_reception AS web_later FROM product WHERE id = ?', [$product]));
        $this->assertSame(['price' => 2999, 'purchase_price' => 1100, 'is_pos' => true, 'is_web' => false, 'pos_later' => false, 'web_later' => true], $state($scarf), 'en caisse tout de suite (stock 0), sur la boutique à la réception : la marque est posée');
        $this->assertSame(['confirmed', true, []], [$this->get("/api/gestion/conversations/$id")['state'], $this->get("/api/gestion/conversations/$id")['undo']['available'], $this->get("/api/gestion/conversations/$id")['undo']['blockers']]);

        // Première réception du stock : l'article marqué passe en vente sur la boutique, la marque s'efface, c'est écrit au journal
        $scarfLine = (int) $conn()->fetchOne('SELECT id FROM commande_line WHERE commande_id = ? AND product_id = ?', [$commande, $scarf]);
        $tuqueLine = (int) $conn()->fetchOne('SELECT id FROM commande_line WHERE commande_id = ? AND product_id = ?', [$commande, $tuque]);
        $this->post("/api/commandes/$commande/lignes/$tuqueLine/reception", ['quantity' => 1], 200);
        $this->assertSame([false, true, 0], [$state($scarf)['is_web'], $state($scarf)['web_later'], $audits('gestion-mise-en-ligne', $scarf)], 'recevoir un AUTRE article ne met rien en ligne');
        $this->post("/api/commandes/$commande/lignes/$scarfLine/reception", ['quantity' => 1], 200);
        $this->assertSame(['price' => 2999, 'purchase_price' => 1100, 'is_pos' => true, 'is_web' => true, 'pos_later' => false, 'web_later' => false], $state($scarf));
        $this->assertSame(1, $audits('gestion-mise-en-ligne', $scarf));
        $this->post("/api/commandes/$commande/lignes/$scarfLine/reception", ['quantity' => 1], 200);
        $this->assertSame(1, $audits('gestion-mise-en-ligne', $scarf), 'une seule mise en ligne : la marque est levée par la première réception');
        // Après la réception, l'annulation d'un bloc est refusée, et elle dit pourquoi
        $blocked = $this->post("/api/gestion/conversations/$id/undo", [], 409);
        $codes = array_column($blocked['reasons'], 'code');
        $this->assertSame('UNDO_BLOCKED', $blocked['code']);
        $this->assertContains('LINE_RECEIVED', $codes);
        $this->assertContains('PUBLISHED_ON_RECEPTION', $codes);
        $this->assertSame(3150, (int) round((float) $conn()->fetchOne('SELECT price FROM product WHERE id = ?', [$tuque])), 'annulation refusée : le prix changé reste');

        // ---- Deuxième facture : seulement l'article existant ; son prix change, est retouché (refus), puis l'annulation remet tout ----
        // une variante de la tuque a son prix propre : elle suit le prix de l'article, comme au lot P4
        $ownVariant = (int) $this->create(\App\Entity\ProductVariant::class, ['product' => $this->em()->find(\App\Entity\Product::class, $tuque)])->getId();
        $conn()->executeStatement('UPDATE product_variant SET price = 3300 WHERE id = ?', [$ownVariant]);
        $this->endOfWrite();
        $variantPrice = fn (): int => (int) round((float) $conn()->fetchOne('SELECT price FROM product_variant WHERE id = ?', [$ownVariant]));
        [$id2, $doc2] = $this->startThread($reading("P2-$tag", [['baseLabel' => 'Tuque prix', 'supplierCode' => "TP-$tag", 'quantity' => 1, 'unitCost' => 1000, 'total' => 1000]]));
        $thread = $this->get("/api/gestion/conversations/$id2");
        $this->assertSame(['supplier', 'target', 'price.mode'], array_column($thread['questions'], 'id'), 'aucun article créé : ni coefficient, ni mise en ligne ; la commande ouverte du fournisseur est proposée');
        $answer($id2, 'target', 'new');
        $answer($id2, 'price.mode', 'each');
        $thread = $answer($id2, "price:$tuque", ['mode' => 'coefficient', 'coefficient' => 2.8]);
        $this->assertSame(['ready', ['mode' => 'coefficient', 'coefficient' => 2.8, 'newPrice' => 2899, 'currentPrice' => 3150, 'changed' => true, 'belowCost' => false]], [$thread['state'], $line($thread, 1)['price']]);
        $done = $this->post("/api/gestion/conversations/$id2/confirm", ['previewHash' => $thread['previewHash']], 201);
        $commande2 = (int) $done['commande']['id'];
        $this->assertSame([['product', null, 3150, 2899, 'coefficient', 2.8], ['variant', $ownVariant, 3300, 2899, 'coefficient', 2.8]], array_map(fn (array $p) => [$p['level'], $p['variantId'], $p['old'], $p['new'], $p['mode'], $p['coefficient']], $done['prices']));
        $this->assertSame(2899, $variantPrice());
        $conn()->executeStatement('UPDATE product SET price = 2950 WHERE id = ?', [$tuque]);
        $this->endOfWrite();
        $blocked = $this->post("/api/gestion/conversations/$id2/undo", [], 409);
        $this->assertSame([['PRICE_CHANGED'], $tuque], [array_column($blocked['reasons'], 'code'), $blocked['reasons'][0]['productId']]);
        $this->assertSame(['PRICE_CHANGED'], array_column($this->get("/api/gestion/conversations/$id2")['undo']['blockers'], 'code'));
        $conn()->executeStatement('UPDATE product SET price = 2899 WHERE id = ?', [$tuque]);
        $this->endOfWrite();
        $undone = $this->post("/api/gestion/conversations/$id2/undo", [], 200);
        $this->assertSame([$commande2, ['prices' => 2], 3300], [$undone['undone']['commandeId'], $undone['undone']['restored'], $variantPrice()], 'le prix de l\'article et celui de sa variante reviennent');
        $this->assertSame([3150, 0, 'extracted'], [(int) round((float) $conn()->fetchOne('SELECT price FROM product WHERE id = ?', [$tuque])), (int) $conn()->fetchOne('SELECT COUNT(*) FROM commande WHERE id = ?', [$commande2]), $conn()->fetchOne('SELECT status FROM gestion_document WHERE id = ?', [$doc2])]);

        // ---- Troisième facture : la mise en ligne réglée pour une seule ligne ; un article créé visible n'empêche pas l'annulation ----
        [$id3] = $this->startThread($reading("P3-$tag", [
            ['baseLabel' => $new[1], 'quantity' => 2, 'unitCost' => 1000, 'total' => 2000],
            ['baseLabel' => $new[2], 'quantity' => 2, 'unitCost' => 1000, 'total' => 2000],
        ]));
        $answer($id3, 'target', 'new');
        $answer($id3, 'line:n1', ['create' => ['name' => "Mitaine neuve $tag", 'publishPos' => 'now', 'publishWeb' => 'now']]);
        $thread = $answer($id3, 'line:n2', ['create' => ['name' => "Bonnet neuf $tag"]]);
        $this->assertSame([['now', 'now', ['STOCK_ZERO', 'NO_PHOTO']], ['no', 'no', []]], array_map(fn (int $n) => [$line($thread, $n)['publishPos'], $line($thread, $n)['publishWeb'], $line($thread, $n)['mentions']], [1, 2]));
        $this->assertSame([2, [2799, 2799]], [count(array_column($thread['questions'], null, 'id')['coefficient']['articles']), array_column($thread['preview']['lines'], 'plannedPrice')]);
        $done = $this->post("/api/gestion/conversations/$id3/confirm", ['previewHash' => $thread['previewHash']], 201);
        [$mitten, $hat] = array_column($done['created'], 'productId');
        $this->assertSame([[true, true], [false, false]], [[$state($mitten)['is_pos'], $state($mitten)['is_web']], [$state($hat)['is_pos'], $state($hat)['is_web']]]);
        $this->assertSame([], $this->get("/api/gestion/conversations/$id3")['undo']['blockers'], 'l\'article est visible parce que la confirmation l\'a décidé : ce n\'est pas une modification');
        $this->post("/api/gestion/conversations/$id3/undo", [], 200);
        $this->assertSame(0, (int) $conn()->fetchOne('SELECT COUNT(*) FROM product WHERE id IN (?, ?)', [$mitten, $hat]));
    }

    // ---- outils --------------------------------------------------------------------------------------------------------------------

    /** @return array{0: int, 1: int} fil, document : la facture est envoyée puis lue par le faux modèle */
    private function startThread(array $reading): array
    {
        $this->client->request('POST', '/api/gestion/conversations', [], ['files' => [$this->image('f.png')]], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $this->token]);
        $thread = $this->body();
        $this->assertSame(201, $this->client->getResponse()->getStatusCode(), json_encode($thread));
        FakeLandingAiClient::$queue[] = json_decode(json_encode([
            'id' => 'msg_' . bin2hex(random_bytes(4)), 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-sonnet-5-5', 'stop_reason' => 'end_turn',
            'content' => [['type' => 'text', 'text' => json_encode($reading)]],
            'usage' => ['input_tokens' => 4000, 'output_tokens' => 600, 'cache_read_input_tokens' => 0, 'cache_creation_input_tokens' => 0],
        ]), false);
        static::getContainer()->get(ReadGestionDocumentHandler::class)(new ReadGestionDocumentMessage((int) $thread['document']['id'], MV_TEST_TENANT_CODE));
        $this->em()->clear();
        $this->endOfWrite();

        return [(int) $thread['id'], (int) $thread['document']['id']];
    }

    private function image(string $name): UploadedFile
    {
        $tmp = tempnam(sys_get_temp_dir(), 'img');
        copy(__DIR__ . '/../../../public/assets/arkanoa-favicon.png', $tmp);

        return new UploadedFile($tmp, $name, 'image/png', null, true);
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
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), $route . ' : ' . substr((string) $this->client->getResponse()->getContent(), 0, 400));

        return $this->body();
    }

    private function post(string $route, array $body, int $expected): array
    {
        $this->request('POST', $route, $body);
        $this->assertSame($expected, $this->client->getResponse()->getStatusCode(), $route . ' : ' . substr((string) $this->client->getResponse()->getContent(), 0, 800));

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

    private function login(array $roles): void
    {
        $email = 'gestion-prix-fil-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $em = $this->em();
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Gestion')->setLastname('Prix')->setRoles($roles)->setIsVerified(true)->setPassword('inutilisable');
        $em->persist($user);
        $em->flush();
        $this->fixtureUserId = (int) $user->getId();
        $this->token = static::getContainer()->get('lexik_jwt_authentication.jwt_manager')->create($user);
        $this->endOfWrite();
    }
}
