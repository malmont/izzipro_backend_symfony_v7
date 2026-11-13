<?php

namespace App\Tests\Functional\Gestion;

use App\Entity\Categories;
use App\Entity\Product;
use App\Entity\ProductVariant;
use App\Entity\User;
use App\EventSubscriber\CacheInvalidationSubscriber;
use App\Services\TenantEntityManagerProvider;
use App\Tests\Functional\Cache\CacheTestEntityFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Action `price.set` du fil de la gestion et réglages de prix du site (lot IA-ACT-5, 13/11/2026, `docs/conversation-gestion.md` § 13,
 * `docs/prix-gestion.md` § 5) : chaque portée et chaque mode, l'arrondi du site par défaut, les articles exclus et nommés, les prix à
 * risque tenus à l'écart tant que le gérant n'a pas répondu « appliquer quand même », la marge potentielle du stock avant et après,
 * l'empreinte, la confirmation par le service des prix (lot P4), l'annulation et son refus quand un prix a bougé.
 */
class PriceSetActionApiTest extends WebTestCase
{
    use CacheTestEntityFactory;

    private KernelBrowser $client;
    private string $token = '';
    private string $tag = '';
    /** @var list<int> */
    private array $collections = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->tag = bin2hex(random_bytes(3));
        $this->login(['ROLE_ADMIN']);
        $this->resetSettings();
    }

    protected function tearDown(): void
    {
        // la période et la collection d'essai sont retirées : d'autres tests de la base partagée lisent les périodes d'une année entière
        if ($this->collections !== []) {
            $conn = $this->em()->getConnection();
            $conn->executeStatement('UPDATE commande SET collections_id = NULL WHERE collections_id IN (?)', [$this->collections], [\Doctrine\DBAL\ArrayParameterType::INTEGER]);
            $conn->executeStatement('DELETE FROM collections WHERE id IN (?)', [$this->collections], [\Doctrine\DBAL\ArrayParameterType::INTEGER]);
        }
        $this->resetSettings();
        parent::tearDown();
    }

    public function testACoefficientOnAListHoldsRiskyPricesBackThenIsConfirmedAndUndone(): void
    {
        $tag = $this->tag;
        $conn = fn () => $this->em()->getConnection();
        $p1 = $this->product("Un $tag", 2500, 1000, 10);
        $p2 = $this->product("Deux $tag", 9000, 4000, 5);
        $p3 = $this->product("Sans coût $tag", 2000, null, 3);
        $p4 = $this->product("Sous-évalué $tag", 1000, 3000, 2);
        $p5 = $this->product("Location $tag", 4000, 1500, 1, 'rental_enabled = true');
        $p6 = $this->product("Déjà au prix $tag", 2099, 1000, 0);
        $p7 = $this->product("En promotion $tag", 5000, 2000, 4, 'special_price = 4000');
        $ids = [$p1, $p2, $p3, $p4, $p5, $p6, $p7];
        $prices = fn (): array => array_map(fn (int $id) => (int) round((float) $conn()->fetchOne('SELECT price FROM product WHERE id = ?', [$id])), $ids);
        $before = [2500, 9000, 2000, 1000, 4000, 2099, 5000];
        $batches = fn (): int => (int) $conn()->fetchOne('SELECT COUNT(*) FROM gestion_price_batch');
        $batchesBefore = $batches();

        // Le fil s'ouvre avec ses paramètres : portée, mode, valeur ; l'arrondi du site est proposé, les prix à risque sont listés
        $thread = $this->post('/api/gestion/conversations', ['action' => 'price.set', 'params' => ['scope' => ['productIds' => $ids], 'mode' => 'coefficient', 'value' => 2]], 201);
        $id = (int) $thread['id'];
        $questions = array_column($thread['questions'], null, 'id');
        $this->assertSame(['price.set', 'ready', 0, ['scope', 'mode', 'value', 'rounding', 'force'], []], [$thread['kind'], $thread['state'], $thread['openQuestions'], array_keys($questions), $thread['issues']], json_encode($thread['issues']));
        $this->assertSame([['productIds' => $ids], 'coefficient', 2, 'psy_99', true, 'exclude', true], [$questions['scope']['answer'], $questions['mode']['answer'], $questions['value']['answer'],
            $questions['rounding']['answer'], $questions['rounding']['proposed'], $questions['force']['answer'], $questions['force']['proposed']]);
        $this->assertSame(['coefficient', 'percent', 'fixed'], array_column($questions['mode']['options'], 'value'), 'un prix saisi est proposé pour une liste d\'articles');
        $this->assertSame([['type' => 'number', 'min' => 0.5, 'max' => 20], 2, '2 : le coefficient par défaut du site'], [$questions['value']['input'], $questions['value']['options'][0]['value'], $questions['value']['options'][0]['label']]);
        $this->assertSame([1, [$p4], ['BIG_CHANGE']], [$questions['force']['count'], array_column($questions['force']['rows'], 'productId'), $questions['force']['rows'][0]['alerts']]);
        $this->assertStringStartsWith('1 prix passerait sous son coût d\'achat ou bougerait de plus de 50 %. Il est tenu à l\'écart', $questions['force']['text']);

        // L'aperçu : 3 prix changent ; l'article sans coût et la location sont exclus et nommés ; le prix à risque est tenu à l'écart
        $preview = $thread['preview'];
        $this->assertSame(['products' => 7, 'rows' => 7, 'written' => 3, 'unchanged' => 1, 'excluded' => 2, 'heldBack' => 1, 'promotions' => 1], $preview['summary']);
        $this->assertSame([['type' => 'products', 'id' => null, 'count' => 7, 'label' => '7 articles choisis'], 'coefficient', 2, 'psy_99'], [$preview['scope'], $preview['mode'], $preview['value'], $preview['rounding']]);
        $this->assertSame([[$p1, 2500, 2099, 1000, 10], [$p2, 9000, 8099, 4000, 5], [$p7, 5000, 4099, 2000, 4]], array_map(fn (array $l) => [$l['productId'], $l['oldPrice'], $l['newPrice'], $l['cost'], $l['stock']], $preview['lines']));
        $this->assertSame(['SPECIAL_PRICE_ACTIVE'], $preview['lines'][2]['alerts']);
        $this->assertSame([[$p3, "Sans coût $tag", 'COST_UNKNOWN'], [$p5, "Location $tag", 'NOT_A_SALE_ITEM']], array_map(fn (array $e) => [$e['productId'], $e['name'], $e['reason']], $preview['excluded']));
        $this->assertSame([[$p4, 1000, 6099, 3000]], array_map(fn (array $l) => [$l['productId'], $l['oldPrice'], $l['newPrice'], $l['cost']], $preview['heldBack']));
        // Marge potentielle du stock, stock × (prix − coût) : 15 000 + 25 000 − 4 000 + 12 000 avant ; 10 990 + 20 495 − 4 000 + 8 396 après
        $this->assertSame(['units' => 21, 'before' => ['amount' => 48000, 'rate' => 52.17], 'after' => ['amount' => 35881, 'rate' => 44.92]], $preview['stockMargin']);
        $this->assertNull($preview['more']);
        $this->assertStringContainsString('visibles tout de suite en caisse et sur la boutique en ligne', $preview['notice']);
        $this->assertStringContainsString('1 article a une promotion en cours : son prix promotionnel ne change pas.', $preview['notice']);
        $rows = array_column($preview['rows'], null, 'label');
        $this->assertSame(['7 articles choisis', 'Coefficient 2 sur le coût d\'achat', 'Prix psychologique (9,99 ; 54,99 ; 159)', 3, [35881, 48000], ['44,92 %', '52,17 %'], 1, 2],
            [$rows['Articles concernés']['value'], $rows['Façon de fixer le prix']['value'], $rows['Arrondi']['value'], $rows['Prix qui changeront']['value'],
                [$rows['Marge potentielle du stock']['value'], $rows['Marge potentielle du stock']['before']], [$rows['Taux de marge du stock']['value'], $rows['Taux de marge du stock']['before']],
                $rows['Prix à risque laissés tels quels']['value'], $rows['Articles exclus (sans coût, sans prix ou en location)']['value']]);
        $this->assertSame($before, $prices(), 'l\'aperçu n\'écrit rien');

        // « Appliquer quand même » : le prix à risque entre dans les prix écrits, l'aperçu et son empreinte changent
        $hash = $thread['previewHash'];
        $thread = $this->post("/api/gestion/conversations/$id/answers", ['answers' => [['questionId' => 'force', 'value' => 'apply']]], 200);
        $this->assertSame(['ready', 4, 0, ['amount' => 46079, 'rate' => 51.15]], [$thread['state'], $thread['preview']['summary']['written'], $thread['preview']['summary']['heldBack'], $thread['preview']['stockMargin']['after']]);
        $this->assertNotSame($hash, $thread['previewHash']);
        $this->assertSame('PREVIEW_CHANGED', $this->post("/api/gestion/conversations/$id/confirm", ['previewHash' => $hash], 409)['code'], 'l\'empreinte d\'avant la réponse ne vaut plus');
        // L'empreinte couvre les prix : un prix qui bouge entre l'aperçu et la confirmation fait refuser l'empreinte
        $hash = $thread['previewHash'];
        $conn()->executeStatement('UPDATE product SET price = 9100 WHERE id = ?', [$p2]);
        $this->endOfWrite();
        $changed = $this->post("/api/gestion/conversations/$id/confirm", ['previewHash' => $hash], 409);
        $this->assertSame(['PREVIEW_CHANGED', 9100], [$changed['code'], array_column($changed['preview']['lines'], 'oldPrice', 'productId')[$p2]]);
        $this->assertSame($batchesBefore, $batches(), 'rien n\'est écrit par une confirmation refusée');
        $conn()->executeStatement('UPDATE product SET price = 9000 WHERE id = ?', [$p2]);
        $this->endOfWrite();

        // Confirmation : par le service des prix (un lot), prix calculés arrondis ; exclus et inchangés intacts ; la promotion garde son prix
        $thread = $this->get("/api/gestion/conversations/$id");
        $done = $this->post("/api/gestion/conversations/$id/confirm", ['previewHash' => $thread['previewHash']], 201);
        $this->assertSame([4, true], [$done['result']['changed'], $done['undo']['available']]);
        $batch = (int) $done['result']['batchId'];
        $this->assertSame([2099, 8099, 2000, 6099, 4000, 2099, 4099], $prices());
        $this->assertSame(4000, (int) round((float) $conn()->fetchOne('SELECT special_price FROM product WHERE id = ?', [$p7])));
        $this->assertSame([$batchesBefore + 1, 4, 'coefficient'], [$batches(), (int) $conn()->fetchOne('SELECT COUNT(*) FROM gestion_price_change WHERE batch_id = ?', [$batch]), $conn()->fetchOne('SELECT mode FROM gestion_price_batch WHERE id = ?', [$batch])]);
        $thread = $this->get("/api/gestion/conversations/$id");
        $this->assertSame(['confirmed', true, [], $batch], [$thread['state'], $thread['undo']['available'], $thread['undo']['blockers'], $thread['action']['result']['batchId']]);
        $this->assertArrayNotHasKey('undo', end($thread['messages'])['content'], 'ce qui sert à défaire reste au serveur');

        // Annulation refusée tant qu'un prix écrit a été retouché depuis ; puis annulation : tous les anciens prix reviennent, une fois
        $conn()->executeStatement('UPDATE product SET price = 2222 WHERE id = ?', [$p1]);
        $this->endOfWrite();
        $blocked = $this->post("/api/gestion/conversations/$id/undo", [], 409);
        $this->assertSame(['UNDO_BLOCKED', ['PRICE_CHANGED'], [$p1]], [$blocked['code'], array_column($blocked['reasons'], 'code'), array_column($blocked['reasons'][0]['products'], 'productId')]);
        $this->assertStringContainsString("Un $tag", $blocked['reasons'][0]['message']);
        $this->assertSame(['PRICE_CHANGED'], array_column($this->get("/api/gestion/conversations/$id")['undo']['blockers'], 'code'));
        $this->assertSame(8099, $prices()[1], 'tout ou rien : aucun prix n\'est remis par une annulation refusée');
        $conn()->executeStatement('UPDATE product SET price = 2099 WHERE id = ?', [$p1]);
        $this->endOfWrite();
        $undone = $this->post("/api/gestion/conversations/$id/undo", [], 200);
        $this->assertSame(['batchId' => $batch, 'prices' => 4], $undone['undone']['restored']);
        $this->assertSame($before, $prices());
        $this->assertNotNull($conn()->fetchOne('SELECT undone_at FROM gestion_price_batch WHERE id = ?', [$batch]));
        $this->assertSame('ALREADY_UNDONE', $this->post("/api/gestion/conversations/$id/undo", [], 409)['code']);
        $this->assertSame('ready', $this->get("/api/gestion/conversations/$id")['state'], 'après l\'annulation, le fil revient à son aperçu');

        // Un lot annulé depuis l'écran des prix (route du lot P4) bloque l'annulation du fil, sans rien remettre deux fois
        $thread = $this->get("/api/gestion/conversations/$id");
        $again = $this->post("/api/gestion/conversations/$id/confirm", ['previewHash' => $thread['previewHash']], 201);
        $this->post('/api/gestion/prix/annuler', ['batchId' => (int) $again['result']['batchId']], 200);
        $this->assertSame(['ALREADY_UNDONE'], array_column($this->post("/api/gestion/conversations/$id/undo", [], 409)['reasons'], 'code'));
        $this->assertSame($before, $prices());
    }

    public function testEveryScopeEveryModeTheSiteRoundingAndTheRefusals(): void
    {
        $tag = $this->tag;
        $conn = fn () => $this->em()->getConnection();
        $price = fn (int $id): int => (int) round((float) $conn()->fetchOne('SELECT price FROM product WHERE id = ?', [$id]));
        $p1 = $this->product("Alpha $tag", 2500, 1000, 4);
        $p2 = $this->product("Bêta $tag", 9000, 4000, 2);
        $p3 = $this->product("Gamma $tag", 12000, 5400, 1);
        $category = (int) $this->create(Categories::class, ['name' => "Rayon $tag"])->getId();
        $empty = (int) $this->create(Categories::class, ['name' => "Rayon vide $tag"])->getId();
        $later = (int) $this->create(Categories::class, ['name' => "Rayon suivant $tag"])->getId(); // créée après : ses articles ne sont pas ceux du premier rayon
        $conn()->executeStatement('INSERT INTO product_categories (product_id, categories_id) VALUES (?, ?), (?, ?), (?, ?)', [$p1, $category, $p2, $category, $p3, $later]);
        // une période à part, ancienne : celle d'aujourd'hui reçoit les commandes de tous les tests de la base partagée
        $period = (int) $conn()->fetchOne("INSERT INTO collections (id, budget_collection, start_date_collection, end_date_collection, del, nom_collection, is_closed, kind, period_key) VALUES (nextval('collections_id_seq'), 0, '1987-03-01', '1987-05-31', false, ?, false, 'period', ?) RETURNING id", ["Printemps $tag", "T-$tag"]);
        $this->collections = [$period];
        $collection = (int) $conn()->fetchOne("INSERT INTO collections (id, budget_collection, start_date_collection, end_date_collection, del, nom_collection, is_closed, kind) VALUES (nextval('collections_id_seq'), 0, NOW(), NOW() + INTERVAL '30 days', false, ?, false, 'manual') RETURNING id", ["Voyage $tag"]);
        $this->collections[] = $collection;
        $commande = fn (int $collectionId, array $products): int => $this->commande($collectionId, $products);
        $inPeriod = $commande($period, [$p1 => 1000, $p3 => 5400]);
        $commande($collection, [$p2 => 4000, $p3 => 5400]);
        $this->endOfWrite();
        $open = fn (array $params, int $expected = 201): array => $this->post('/api/gestion/conversations', ['action' => 'price.set', 'params' => $params], $expected);
        $written = fn (array $thread): array => array_column($thread['preview']['lines'], 'newPrice', 'productId');

        // Portées : catégorie de la boutique, période, collection d'achats, commande ; chacune dit en clair ce qu'elle couvre
        $byCategory = $open(['scope' => ['categoryId' => $category], 'mode' => 'percent', 'value' => 10, 'rounding' => 'none']);
        $this->assertSame(['ready', ['type' => 'category', 'id' => $category, 'count' => null, 'label' => "La catégorie « Rayon $tag »"], [$p1 => 2750, $p2 => 9900]], [$byCategory['state'], $byCategory['preview']['scope'], $written($byCategory)]);
        $this->assertSame(['coefficient', 'percent'], array_column(array_column($byCategory['questions'], null, 'id')['mode']['options'], 'value'), 'pas de prix saisi pour toute une catégorie');
        $this->assertSame('+10 % sur le prix actuel', array_column($byCategory['preview']['rows'], 'value', 'label')['Façon de fixer le prix']);
        $byPeriod = $open(['scope' => ['periodId' => $period], 'mode' => 'coefficient', 'value' => 2.4]);
        $this->assertSame([['type' => 'period', 'id' => $period, 'count' => null, 'label' => "Les articles achetés pendant « Printemps $tag »"], [$p1 => 2499, $p3 => 13900]], [$byPeriod['preview']['scope'], $written($byPeriod)],
            'coût × 2,4 puis arrondi du site : 24,00 → 24,99 ; 129,60 → 139,00 ; un article qui n\'a pas été acheté dans la période n\'est pas dans la portée');
        $byCollection = $open(['scope' => ['collectionId' => $collection], 'mode' => 'coefficient', 'value' => 2, 'rounding' => 'integer']);
        $this->assertSame([['type' => 'collection', 'id' => $collection, 'count' => null, 'label' => "Les articles de la collection « Voyage $tag »"], [$p2 => 8000, $p3 => 10800]], [$byCollection['preview']['scope'], $written($byCollection)]);
        $byCommande = $open(['scope' => ['commandeId' => $inPeriod], 'mode' => 'percent', 'value' => -10, 'rounding' => 'ends_95']);
        $this->assertSame(['commande', [$p1 => 2295, $p3 => 10895]], [$byCommande['preview']['scope']['type'], $written($byCommande)], '22,50 → 22,95 ; 108,00 → 108,95');
        // L'arrondi du site, réglé par le gérant, est celui que l'action propose
        $this->put('/api/gestion/reglages/prix', ['rounding' => 'ends_95']);
        $site = $open(['scope' => ['productIds' => [$p1]], 'mode' => 'coefficient', 'value' => 2.2]);
        $this->assertSame(['ends_95', true, [$p1 => 2295]], [array_column($site['questions'], 'answer', 'id')['rounding'], array_column($site['questions'], 'proposed', 'id')['rounding'], $written($site)]);
        $this->put('/api/gestion/reglages/prix', ['rounding' => null]);

        // Prix saisi : une liste d'articles seulement, écrit tel quel, sans question d'arrondi
        $fixed = $open(['scope' => ['productIds' => [$p1, $p2]], 'mode' => 'fixed', 'value' => 3150]);
        $this->assertSame(['ready', ['scope', 'mode', 'value', 'force'], null, [$p1 => 3150]], [$fixed['state'], array_column($fixed['questions'], 'id'), $fixed['preview']['rounding'], $written($fixed)], 'le deuxième article bouge de plus de 50 % : tenu à l\'écart');
        $this->assertSame(['type' => 'money', 'currency' => 'CAD'], array_column($fixed['questions'], 'input', 'id')['value']);
        $done = $this->post("/api/gestion/conversations/{$fixed['id']}/confirm", ['previewHash' => $fixed['previewHash']], 201);
        $this->assertSame([1, 3150, 9000], [$done['result']['changed'], $price($p1), $price($p2)]);
        $this->post("/api/gestion/conversations/{$fixed['id']}/undo", [], 200);
        $this->assertSame(2500, $price($p1));

        // Les mêmes garde-fous par la route du lot P4 : un prix saisi demande une liste d'articles, et n'est jamais arrondi
        $this->request('POST', '/api/gestion/prix/apercu', ['categoryId' => $category, 'mode' => 'fixed', 'value' => 3150]);
        $this->assertSame([422, 'FIXED_NEEDS_LIST'], [$this->client->getResponse()->getStatusCode(), $this->body()['fields']['mode'] ?? null]);
        $direct = $this->post('/api/gestion/prix/apercu', ['productIds' => [$p1], 'mode' => 'fixed', 'value' => 3150, 'rounding' => 'psy_99'], 200);
        $this->assertSame(3150, $direct['rows'][0]['newPrice'], 'un prix saisi n\'est jamais arrondi, même si un arrondi est demandé');
        // appliqué par la route directe avec un arrondi demandé : le prix saisi est écrit tel quel, et le lot note « sans arrondi »
        $applied = $this->post('/api/gestion/prix/appliquer', ['productIds' => [$p1], 'mode' => 'fixed', 'value' => 3150, 'rounding' => 'psy_99', 'previewHash' => $direct['previewHash']], 201);
        $this->assertSame([3150, 'none'], [$price($p1), $conn()->fetchOne('SELECT rounding FROM gestion_price_batch WHERE id = ?', [$applied['batchId']])]);
        $this->post('/api/gestion/prix/annuler', ['batchId' => $applied['batchId']], 200);
        $this->assertSame(2500, $price($p1));
        $this->request('POST', '/api/gestion/prix/apercu', ['periodId' => $collection, 'mode' => 'percent', 'value' => 10]);
        $this->assertSame([422, 'PERIOD_NOT_FOUND'], [$this->client->getResponse()->getStatusCode(), $this->body()['fields']['periodId'] ?? null], 'une collection d\'achats n\'est pas une période');

        // Variantes, comme au lot P4 : la variante à prix propre suit, celle qui hérite du prix de l'article n'a pas de ligne
        $withVariants = $this->product("Delta $tag", 5000, 2000, 0);
        $own = (int) $this->create(ProductVariant::class, ['product' => $this->em()->find(Product::class, $withVariants)])->getId();
        $inherits = (int) $this->create(ProductVariant::class, ['product' => $this->em()->find(Product::class, $withVariants)])->getId();
        $conn()->executeStatement('UPDATE product_variant SET price = 5500, stock_quantity = 3 WHERE id = ?', [$own]);
        $conn()->executeStatement('UPDATE product_variant SET price = NULL, stock_quantity = 2 WHERE id = ?', [$inherits]);
        $this->endOfWrite();
        $variants = $open(['scope' => ['productIds' => [$withVariants]], 'mode' => 'coefficient', 'value' => 3, 'rounding' => 'none']);
        $this->assertSame([[null, 5000, 6000, 2], [$own, 5500, 6000, 3]], array_map(fn (array $l) => [$l['variantId'], $l['oldPrice'], $l['newPrice'], $l['stock']], $variants['preview']['lines']));
        $this->post("/api/gestion/conversations/{$variants['id']}/confirm", ['previewHash' => $variants['previewHash']], 201);
        $this->assertSame([6000, 6000, null], [$price($withVariants), (int) round((float) $conn()->fetchOne('SELECT price FROM product_variant WHERE id = ?', [$own])), $conn()->fetchOne('SELECT price FROM product_variant WHERE id = ?', [$inherits])]);
        $this->post("/api/gestion/conversations/{$variants['id']}/undo", [], 200);
        $this->assertSame([5000, 5500], [$price($withVariants), (int) round((float) $conn()->fetchOne('SELECT price FROM product_variant WHERE id = ?', [$own]))]);

        // Rien à écrire : le fil le dit et ne rend pas d'empreinte
        $nothing = $open(['scope' => ['productIds' => [$p1]], 'mode' => 'coefficient', 'value' => 2.5, 'rounding' => 'none']);
        $this->assertSame(['questions', 0, [['path' => 'scope', 'code' => 'NOTHING_TO_APPLY']], null, null], [$nothing['state'], $nothing['openQuestions'], $nothing['issues'], $nothing['preview'], $nothing['previewHash']]);
        $this->assertSame('CONVERSATION_NOT_READY', $this->post("/api/gestion/conversations/{$nothing['id']}/confirm", ['previewHash' => 'x'], 409)['code']);

        // Refus à l'ouverture : chaque paramètre est contrôlé comme une réponse par bouton
        $refused = [
            [['scope' => []], 'params.scope', 'INVALID_VALUE'],
            [['scope' => ['productIds' => [$p1], 'categoryId' => $category]], 'params.scope', 'INVALID_VALUE'],
            [['scope' => ['adviceId' => 1]], 'params.scope', 'INVALID_VALUE'],
            [['scope' => ['productIds' => []]], 'params.scope', 'REQUIRED'],
            [['scope' => ['productIds' => [2147483000]]], 'params.scope', 'PRODUCT_NOT_FOUND'],
            [['scope' => ['productIds' => ['x']]], 'params.scope', 'INVALID_ID'],
            [['scope' => ['periodId' => $collection]], 'params.scope', 'PERIOD_NOT_FOUND'],
            [['scope' => ['collectionId' => 2147483000]], 'params.scope', 'COLLECTION_NOT_FOUND'],
            [['scope' => ['categoryId' => 2147483000]], 'params.scope', 'CATEGORY_NOT_FOUND'],
            [['scope' => ['commandeId' => 2147483000]], 'params.scope', 'COMMANDE_NOT_FOUND'],
            [['scope' => ['categoryId' => $empty]], 'params.scope', 'SCOPE_EMPTY'],
            [['mode' => 'gratuit'], 'params.mode', 'INVALID_MODE'],
            [['scope' => ['categoryId' => $category], 'mode' => 'fixed'], 'params.mode', 'FIXED_NEEDS_LIST'],
            [['mode' => 'coefficient', 'value' => 21], 'params.value', 'OUT_OF_RANGE'],
            [['mode' => 'coefficient', 'value' => 0.4], 'params.value', 'OUT_OF_RANGE'],
            [['mode' => 'percent', 'value' => -91], 'params.value', 'OUT_OF_RANGE'],
            [['mode' => 'fixed', 'value' => 12.5], 'params.value', 'INVALID_AMOUNT'],
            [['mode' => 'fixed', 'value' => 0], 'params.value', 'INVALID_AMOUNT'],
            [['value' => 'deux'], 'params.value', 'MUST_BE_NUMBER'],
            [['mode' => 'percent', 'rounding' => 'au pif'], 'params.rounding', 'INVALID_ROUNDING'],
        ];
        $threads = (int) $conn()->fetchOne("SELECT COUNT(*) FROM gestion_conversation WHERE kind = 'price.set'");
        foreach ($refused as [$params, $field, $code]) {
            $this->assertSame($code, $open($params, 422)['fields'][$field] ?? null, json_encode($params));
        }
        $this->assertSame($threads, (int) $conn()->fetchOne("SELECT COUNT(*) FROM gestion_conversation WHERE kind = 'price.set'"), 'un fil refusé à l\'ouverture n\'est pas créé');
        // Une valeur donnée avant le mode est recontrôlée quand le mode arrive : le fil ne devient pas prêt avec un coefficient de 150
        $late = $open(['scope' => ['productIds' => [$p1]], 'value' => 150]);
        $late = $this->post("/api/gestion/conversations/{$late['id']}/answers", ['answers' => [['questionId' => 'mode', 'value' => 'coefficient']]], 200);
        $this->assertSame(['questions', [['path' => 'value', 'code' => 'OUT_OF_RANGE']], null], [$late['state'], $late['issues'], $late['previewHash']]);
        // Sans paramètre : le fil propose les portées (périodes, collections d'achats, catégories) et attend trois réponses
        $bare = $open([]);
        $groups = array_unique(array_column(array_column($bare['questions'], null, 'id')['scope']['options'], 'group'));
        $this->assertSame(['questions', 3], [$bare['state'], $bare['openQuestions']]);
        $this->assertContains('period', $groups);
        $this->assertContains('category', $groups);
        $this->assertContains(['collectionId' => $collection], array_column(array_column($bare['questions'], null, 'id')['scope']['options'], 'value'));
    }

    public function testPriceSettingsOfTheSite(): void
    {
        $conn = fn () => $this->em()->getConnection();
        // un nombre entier est rendu sans décimale (2, pas 2.0)
        $defaults = ['defaultCoefficient' => 2, 'minCoefficient' => 1.6, 'rounding' => 'psy_99', 'roundings' => ['psy_99', 'ends_95', 'integer', 'none'], 'stored' => ['defaultCoefficient' => null, 'minCoefficient' => null, 'rounding' => null]];
        $this->assertSame($defaults, $this->get('/api/gestion/reglages/prix'));
        $audits = fn (): int => (int) $conn()->fetchOne("SELECT COUNT(*) FROM content_audit_log WHERE resource = 'gestion-reglages-prix'");
        $auditsBefore = $audits();
        // seules les clés envoyées changent
        $set = $this->put('/api/gestion/reglages/prix', ['defaultCoefficient' => 2.4, 'rounding' => 'ends_95']);
        $this->assertSame([2.4, 1.6, 'ends_95', ['defaultCoefficient' => 2.4, 'minCoefficient' => null, 'rounding' => 'ends_95']], [$set['defaultCoefficient'], $set['minCoefficient'], $set['rounding'], $set['stored']]);
        $this->assertSame($auditsBefore + 1, $audits());
        $set = $this->put('/api/gestion/reglages/prix', ['minCoefficient' => 2]);
        $this->assertSame([2.4, 2, 'ends_95'], [$set['defaultCoefficient'], $set['minCoefficient'], $set['rounding']]);
        $this->put('/api/gestion/reglages/prix', ['minCoefficient' => 2]);
        $this->assertSame($auditsBefore + 2, $audits(), 'rien au journal quand rien ne change');
        $refused = [
            [['defaultCoefficient' => 0.9], 'defaultCoefficient', 'OUT_OF_RANGE'], [['defaultCoefficient' => 20.5], 'defaultCoefficient', 'OUT_OF_RANGE'], [['minCoefficient' => '2'], 'minCoefficient', 'MUST_BE_NUMBER'],
            [['rounding' => 'ends_99'], 'rounding', 'INVALID_ROUNDING'], [['minCoefficient' => 2.5], 'minCoefficient', 'MIN_ABOVE_DEFAULT'], [['defaultCoefficient' => 1.9], 'minCoefficient', 'MIN_ABOVE_DEFAULT'],
        ];
        foreach ($refused as [$body, $field, $code]) {
            $this->request('PUT', '/api/gestion/reglages/prix', $body);
            $this->assertSame([422, $code], [$this->client->getResponse()->getStatusCode(), $this->body()['fields'][$field] ?? null], json_encode($body));
        }
        $this->assertSame([2.4, 2], [$this->get('/api/gestion/reglages/prix')['defaultCoefficient'], $this->get('/api/gestion/reglages/prix')['minCoefficient']], 'un refus n\'écrit rien');
        // `null` remet la valeur par défaut
        $this->assertSame($defaults, $this->put('/api/gestion/reglages/prix', ['defaultCoefficient' => null, 'minCoefficient' => null, 'rounding' => null]));

        // GARDE (demande du chef de projet, 13/11/2026) : l'arrondi ne s'applique qu'à un prix CALCULÉ par coefficient ou pourcentage.
        // Les prix existants ne bougent pas, quel que soit le réglage ; un prix saisi est écrit tel quel ; un article déjà à son prix n'est pas « à arrondir ».
        $round = $this->product("Prix rond {$this->tag}", 2500, 1000, 3);
        $odd = $this->product("Prix quelconque {$this->tag}", 12345, 5000, 1);
        $variant = (int) $this->create(ProductVariant::class, ['product' => $this->em()->find(Product::class, $odd)])->getId();
        $conn()->executeStatement('UPDATE product_variant SET price = 12777 WHERE id = ?', [$variant]);
        $this->endOfWrite();
        $stored = fn (): array => [(int) round((float) $conn()->fetchOne('SELECT price FROM product WHERE id = ?', [$round])), (int) round((float) $conn()->fetchOne('SELECT price FROM product WHERE id = ?', [$odd])),
            (int) round((float) $conn()->fetchOne('SELECT price FROM product_variant WHERE id = ?', [$variant]))];
        foreach (['psy_99', 'ends_95', 'integer', 'none', null] as $mode) {
            $this->put('/api/gestion/reglages/prix', ['rounding' => $mode]);
            $this->assertSame([2500, 12345, 12777], $stored(), 'changer le réglage d\'arrondi (ou le lire) ne touche à aucun prix existant : ' . json_encode($mode));
            $this->assertSame([2500, 12345], [$this->get("/api/productsid/$round")['price'] ?? null, $this->get("/api/productsid/$odd")['price'] ?? null], 'la fiche rend le prix enregistré, jamais un prix arrondi à la lecture');
        }
        // un prix saisi égal au prix actuel : rien à écrire (le prix rond 25,00 n'est pas « à passer à 25,99 »)
        $same = $this->post('/api/gestion/conversations', ['action' => 'price.set', 'params' => ['scope' => ['productIds' => [$round]], 'mode' => 'fixed', 'value' => 2500]], 201);
        $this->assertSame([['path' => 'scope', 'code' => 'NOTHING_TO_APPLY']], $same['issues']);
        // un prix saisi quelconque est écrit tel quel, la variante à prix propre aussi ; l'arrondi par défaut du site n'y touche pas
        $typed = $this->post('/api/gestion/conversations', ['action' => 'price.set', 'params' => ['scope' => ['productIds' => [$odd]], 'mode' => 'fixed', 'value' => 13131]], 201);
        $this->post("/api/gestion/conversations/{$typed['id']}/confirm", ['previewHash' => $typed['previewHash']], 201);
        $this->assertSame([2500, 13131, 13131], $stored());
        $this->post("/api/gestion/conversations/{$typed['id']}/undo", [], 200);
        $this->assertSame([2500, 12345, 12777], $stored(), 'l\'annulation remet les prix au cent près, sans arrondi');
        // seul un prix CALCULÉ est arrondi : 10,00 × 2,5 = 25,00 → 25,99 par le coefficient ; l'article que personne ne recalcule garde 123,45
        $computed = $this->post('/api/gestion/conversations', ['action' => 'price.set', 'params' => ['scope' => ['productIds' => [$round]], 'mode' => 'coefficient', 'value' => 2.5]], 201);
        $this->assertSame([$round => 2599], array_column($computed['preview']['lines'], 'newPrice', 'productId'));
        $this->assertSame([2500, 12345, 12777], $stored(), 'un aperçu n\'écrit rien');
        // accès : administrateur du site
        $admin = $this->token;
        $this->token = '';
        $this->request('PUT', '/api/gestion/reglages/prix', ['rounding' => 'none']);
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
        $this->login(['ROLE_USER']);
        $this->request('PUT', '/api/gestion/reglages/prix', ['rounding' => 'none']);
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->request('GET', '/api/gestion/reglages/prix');
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->token = $admin;
        $this->assertSame('psy_99', $this->get('/api/gestion/reglages/prix')['rounding']);
    }

    // ---- outils --------------------------------------------------------------------------------------------------------------------

    /** Article sans variante : prix, coût de la fiche (null : inconnu), stock ; `$more` : colonnes de plus (`rental_enabled = true`…) */
    private function product(string $name, int $price, ?int $cost, int $stock, ?string $more = null): int
    {
        $conn = $this->em()->getConnection();
        $slug = 'prix-' . bin2hex(random_bytes(5));
        $conn->executeStatement("INSERT INTO product (id, name, price, isbestseller, quantity, created_at, slug, product_type, purchase_price) VALUES (nextval('product_id_seq'), ?, ?, false, ?, NOW(), ?, 'standard', ?)", [$name, $price, $stock, $slug, $cost]);
        $id = (int) $conn->fetchOne('SELECT id FROM product WHERE slug = ?', [$slug]);
        if ($more !== null) {
            $conn->executeStatement("UPDATE product SET $more WHERE id = ?", [$id]);
        }
        $this->endOfWrite();

        return $id;
    }

    /**
     * Commande d'une période ou d'une collection, avec une ligne d'achat par article.
     *
     * @param array<int, int> $products article => coût unitaire
     */
    private function commande(int $collectionId, array $products): int
    {
        $conn = $this->em()->getConnection();
        $id = (int) $conn->fetchOne("INSERT INTO commande (id, date, name, kind, budget, collections_id) VALUES (nextval('commande_id_seq'), NOW(), ?, 'standard', 0, ?) RETURNING id", ["Achat {$this->tag}", $collectionId]);
        foreach ($products as $product => $cost) {
            $conn->executeStatement('INSERT INTO commande_line (commande_id, product_id, quantity_ordered, unit_cost_cents, attached_at, created_at, updated_at) VALUES (?, ?, 1, ?, NOW(), NOW(), NOW())', [$id, $product, $cost]);
        }

        return $id;
    }

    private function resetSettings(): void
    {
        $this->em()->getConnection()->executeStatement('UPDATE entreprise SET gestion_default_coefficient = NULL, gestion_min_coefficient = NULL, gestion_price_rounding = NULL');
        $this->endOfWrite();
    }

    private function body(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    private function request(string $method, string $route, ?array $body = null): void
    {
        $this->client->request($method, $route, [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'] + ($this->token !== '' ? ['HTTP_AUTHORIZATION' => 'Bearer ' . $this->token] : []), $body === null ? null : json_encode($body));
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

    private function put(string $route, array $body): array
    {
        $this->request('PUT', $route, $body);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), $route . ' : ' . substr((string) $this->client->getResponse()->getContent(), 0, 400));

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
        $email = 'gestion-prix-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $em = $this->em();
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Gestion')->setLastname('Prix')->setRoles($roles)->setIsVerified(true)->setPassword('inutilisable');
        $em->persist($user);
        $em->flush();
        $this->token = static::getContainer()->get('lexik_jwt_authentication.jwt_manager')->create($user);
        $this->endOfWrite();
    }
}
