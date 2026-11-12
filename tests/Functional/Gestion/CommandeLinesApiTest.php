<?php

namespace App\Tests\Functional\Gestion;

use App\Entity\Order;
use App\Entity\OrderItems;
use App\Entity\OrderSource;
use App\Entity\OrderType;
use App\Entity\Product;
use App\Entity\ProductVariant;
use App\Entity\StatusCommande;
use App\Entity\TypeFournisseur;
use App\Entity\User;
use App\EventSubscriber\CacheInvalidationSubscriber;
use App\Services\CommandeService\CommandeLineService;
use App\Gestion\Services\CommandeLinesVerifier;
use App\Gestion\Services\PeriodCalendar;
use App\Services\TenantEntityManagerProvider;
use App\Tests\Functional\Cache\CacheTestEntityFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Lignes d'achat (lot L0, 10/11/2026, `docs/lignes-achat-gestion.md`, contrat validé par l'application et le propriétaire) : routes
 * GET / PATCH / DELETE des lignes et leurs gardes, unicité sans variante (index partiel), lignes par variante, raccourcis du produit
 * recalculés ou remis à NULL, reprise des rattachements (même SQL que la migration ; coût inconnu jamais remplacé par le prix de vente),
 * coût moyen pondéré à la date de la vente dans le résultat de période, vérificateur ancien calcul / lignes.
 * Les ventes du coût moyen sont datées dans la période d'il y a 560 jours (aucun autre test n'y écrit).
 */
class CommandeLinesApiTest extends WebTestCase
{
    use CacheTestEntityFactory;

    private KernelBrowser $client;
    private string $token = '';
    private int $userId = 0;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->login();
    }

    public function testBackfillSqlIsTheMigrationSql(): void
    {
        $migration = (string) file_get_contents(__DIR__ . '/../../../migrations/Version20261202100000.php');
        $this->assertStringContainsString('BACKFILL_SQL = "' . CommandeLineService::BACKFILL_SQL . '";', $migration, 'service et migration : même reprise');
    }

    public function testLineRoutesVariantsUniquenessAndShortcuts(): void
    {
        $tag = bin2hex(random_bytes(2));
        $today = static::getContainer()->get(PeriodCalendar::class)->today();
        $supplier = (int) $this->post('/api/fournisseurs', ['name' => "Fournisseur $tag", 'typeFournisseur' => $this->type()], 201)['id'];
        $a = $this->post('/api/commandes', ['budget' => 100000, 'date' => $today->format('Y-m-d') . ' 09:00:00', 'name' => "A $tag", 'fournisseur' => ['id' => $supplier]], 201);
        $b = $this->post('/api/commandes', ['budget' => 100000, 'date' => $today->format('Y-m-d') . ' 10:00:00', 'name' => "B $tag", 'fournisseur' => ['id' => $supplier]], 201);
        $conn = $this->em()->getConnection();
        $conn->executeStatement("INSERT INTO product (id, name, price, isbestseller, quantity, created_at, slug, product_type, purchase_price) VALUES (nextval('product_id_seq'), :n, 4000, false, 9, NOW(), :s, 'standard', 1500)", ['n' => "Robe $tag", 's' => "robe-l0-$tag"]);
        $conn->executeStatement('UPDATE product SET coefficient_multiplier = 2.5 WHERE slug = ?', ["robe-l0-$tag"]); // l'article redevient « à classer » en fin de test : complet pour le semis demo d'un test suivant
        $robe = (int) $conn->fetchOne('SELECT id FROM product WHERE slug = ?', ["robe-l0-$tag"]);
        $s = (int) $this->create(ProductVariant::class, ['product' => $this->em()->find(Product::class, $robe)])->getId();
        $m = (int) $this->create(ProductVariant::class, ['product' => $this->em()->find(Product::class, $robe)])->getId();
        $this->em()->getConnection()->executeStatement('UPDATE product SET quantity = 9 WHERE id = ?', [$robe]);
        $this->endOfWrite();
        $stock = fn (): array => $this->em()->getConnection()->fetchAssociative('SELECT price, purchase_price, quantity FROM product WHERE id = ?', [$robe]);
        $before = $stock();

        // Lignes par variante dans A ; même variante rejouée = mise à jour ; sans quantité = stock de l'article (9)
        $r = $this->post('/api/commandes/' . $a['id'] . '/products/rattacher', ['products' => [['productId' => $robe, 'variantId' => $s, 'quantity' => 2, 'purchasePrice' => 1400], ['productId' => $robe, 'variantId' => $m, 'quantity' => 3]]], 200);
        $this->assertSame([[], [$s, $m], [1400, 1500]], [$r['updated'], array_column($r['lines'], 'variantId'), array_column($r['lines'], 'unitCost')], 'deux lignes ; coût par défaut = prix d\'achat de la fiche');
        $again = $this->post('/api/commandes/' . $a['id'] . '/products/rattacher', ['products' => [['productId' => $robe, 'variantId' => $s, 'quantity' => 4]]], 200);
        $this->assertSame([[$r['lines'][0]['id']], 4, 1400], [$again['updated'], $again['lines'][0]['quantityOrdered'], $again['lines'][0]['unitCost']]);
        $noQty = $this->post('/api/commandes/' . $b['id'] . '/products/rattacher', ['products' => [['productId' => $robe]]], 200);
        $this->assertSame([9, null], [$noQty['lines'][0]['quantityOrdered'], $noQty['lines'][0]['variantId']], 'sans quantité : stock actuel de l\'article ; ligne sans variante');
        $this->assertSame($before, $stock(), 'prix, prix d\'achat et stock intacts');
        $this->request('POST', '/api/commandes/' . $a['id'] . '/products/rattacher', ['products' => [['productId' => $robe, 'variantId' => 999999999]]]);
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());

        // Unicité sans variante : index partiel (NULL ≠ NULL dans un index unique ordinaire)
        try {
            $this->em()->getConnection()->executeStatement("INSERT INTO commande_line (commande_id, product_id, quantity_ordered, quantity_received, attached_at, source, created_at, updated_at) VALUES (?, ?, 1, 1, NOW(), 'manual', NOW(), NOW())", [$b['id'], $robe]);
            $this->fail('doublon sans variante accepté');
        } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException) {
            $this->addToAssertionCount(1);
        }

        // GET des lignes
        $lines = $this->get('/api/commandes/' . $a['id'] . '/lignes');
        $this->assertSame([2, ['id' => $robe, 'name' => "Robe $tag", 'code' => null], 4, 4, 1400, 'manual'], [count($lines), $lines[0]['product'], $lines[0]['quantityOrdered'], $lines[0]['quantityReceived'], $lines[0]['unitCost'], $lines[0]['source']]);
        $this->assertSame($s, $lines[0]['variant']['id']);
        // PATCH : quantité et coût ; coût inconnu (null) ; variante déjà prise → 409 ; ligne d'une autre commande → 404 ; entrée invalide → 422
        $patched = $this->patch('/api/commandes/' . $a['id'] . '/lignes/' . $lines[0]['id'], ['quantity' => 5, 'unitCost' => 1300]);
        $this->assertSame([5, 1300], [$patched['quantityOrdered'], $patched['unitCost']]);
        $this->assertNull($this->patch('/api/commandes/' . $a['id'] . '/lignes/' . $lines[1]['id'], ['unitCost' => null])['unitCost'], 'coût inconnu');
        $this->request('PATCH', '/api/commandes/' . $a['id'] . '/lignes/' . $lines[1]['id'], ['variantId' => $s]);
        $this->assertSame([409, 'LINE_ALREADY_EXISTS'], [$this->client->getResponse()->getStatusCode(), $this->body()['error']]);
        $this->request('PATCH', '/api/commandes/' . $b['id'] . '/lignes/' . $lines[0]['id'], ['quantity' => 1]);
        $this->assertSame([404, 'LINE_NOT_FOUND'], [$this->client->getResponse()->getStatusCode(), $this->body()['error']]);
        $this->request('PATCH', '/api/commandes/' . $a['id'] . '/lignes/' . $lines[0]['id'], ['quantity' => -1]);
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
        $dashboard = $this->get('/api/dashboard/commande/' . $a['id']);
        $this->assertSame([5 * 1300 + 3 * 1500, 8, 1], [$dashboard['purchaseCost'], $dashboard['Statistics']['itemCount'], $dashboard['Statistics']['modelCount']], 'tableau de bord sur les lignes ; coût de ligne inconnu → prix d\'achat de la fiche (repli historique) ; un modèle en deux variantes');

        // Raccourcis : dernière ligne (B) ; suppression de la ligne de B → retour sur A ; plus aucune ligne → NULL
        $shortcut = fn () => $this->em()->getConnection()->fetchOne('SELECT commande_id FROM product WHERE id = ?', [$robe]);
        $this->assertSame((int) $b['id'], (int) $shortcut());
        $this->request('DELETE', '/api/commandes/' . $b['id'] . '/lignes/' . $noQty['lines'][0]['id']);
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertSame((int) $a['id'], (int) $shortcut(), 'raccourci recalculé sur la ligne restante');
        // Gardes : commande figée → 409 COMMANDE_CLOSED sur PATCH et DELETE
        $this->em()->getConnection()->executeStatement('UPDATE commande SET is_closed = true WHERE id = ?', [$a['id']]);
        $this->endOfWrite();
        foreach (['PATCH', 'DELETE'] as $method) {
            $this->request($method, '/api/commandes/' . $a['id'] . '/lignes/' . $lines[0]['id'], ['quantity' => 1]);
            $this->assertSame([409, 'COMMANDE_CLOSED'], [$this->client->getResponse()->getStatusCode(), $this->body()['error']], $method);
        }
        $this->em()->getConnection()->executeStatement('UPDATE commande SET is_closed = false WHERE id = ?', [$a['id']]);
        $this->endOfWrite();
        foreach ($lines as $line) {
            $this->request('DELETE', '/api/commandes/' . $a['id'] . '/lignes/' . $line['id']);
            $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        }
        $this->assertNull($shortcut(), 'plus aucune ligne : raccourcis remis à NULL');
        $this->assertContains($robe, array_column($this->get('/api/commandes/products/disponibles?q=' . urlencode("Robe $tag"))['items'], 'id'), 'de nouveau « à classer »');
    }

    public function testClosedPeriodGuardsLineWrites(): void
    {
        $tag = bin2hex(random_bytes(2));
        $calendar = static::getContainer()->get(PeriodCalendar::class);
        $old = $calendar->periodFor($calendar->today()->modify('-560 days')); // période réservée aux tests de ce fichier (base partagée)
        $conn = $this->em()->getConnection();
        $conn->executeStatement("INSERT INTO commande (id, budget, date, name, is_closed, collections_id) VALUES (nextval('commande_id_seq'), 1000, :d, :n, false, :c)", ['d' => \DateTimeImmutable::createFromInterface($old->getStartDateCollection())->modify('+3 days')->format('Y-m-d 10:00:00'), 'n' => "Fermée $tag", 'c' => $old->getId()]);
        $commandeId = (int) $conn->fetchOne('SELECT id FROM commande WHERE name = ?', ["Fermée $tag"]);
        $conn->executeStatement("INSERT INTO product (id, name, price, isbestseller, quantity, created_at, slug, product_type) VALUES (nextval('product_id_seq'), :n, 1000, false, 1, NOW(), :s, 'standard')", ['n' => "Gants $tag", 's' => "gants-l0-$tag"]);
        $product = (int) $conn->fetchOne('SELECT id FROM product WHERE slug = ?', ["gants-l0-$tag"]);
        $conn->executeStatement("INSERT INTO commande_line (commande_id, product_id, quantity_ordered, quantity_received, unit_cost_cents, attached_at, source, created_at, updated_at) VALUES (?, ?, 2, 2, 500, NOW(), 'manual', NOW(), NOW())", [$commandeId, $product]);
        $lineId = (int) $conn->fetchOne('SELECT id FROM commande_line WHERE commande_id = ?', [$commandeId]);
        $this->endOfWrite();
        foreach (['PATCH', 'DELETE'] as $method) {
            $this->request($method, "/api/commandes/$commandeId/lignes/$lineId", ['quantity' => 1]);
            $this->assertSame([409, 'PERIOD_CLOSED', $old->getPeriodKey()], [$this->client->getResponse()->getStatusCode(), $this->body()['error'], $this->body()['periodKey'] ?? null], $method);
        }
        $this->assertCount(1, $this->get("/api/commandes/$commandeId/lignes"), 'lecture permise');
    }

    public function testBackfillKeepsDashboardsAndNeverTurnsSalePriceIntoCost(): void
    {
        $tag = bin2hex(random_bytes(2));
        $today = static::getContainer()->get(PeriodCalendar::class)->today();
        $conn = $this->em()->getConnection();
        $conn->executeStatement("INSERT INTO commande (id, budget, date, name, is_closed) VALUES (nextval('commande_id_seq'), 50000, :d, :n, false)", ['d' => $today->format('Y-m-d 08:00:00'), 'n' => "Avant L0 $tag"]);
        $commandeId = (int) $conn->fetchOne('SELECT id FROM commande WHERE name = ?', ["Avant L0 $tag"]);
        // P : coût connu (1200) ; Q : rattaché avant L0 sans coût (repli sur le prix de vente 3000) ; R : sans coût, prix d'achat de la fiche 800
        foreach ([['P', 2500, 'NULL', 1200, 4], ['Q', 3000, 'NULL', 3000, 2], ['R', 2000, '800', 'NULL', 5]] as [$k, $price, $purchase, $cost, $qty]) {
            $conn->executeStatement("INSERT INTO product (id, name, price, isbestseller, quantity, created_at, slug, product_type, purchase_price, commande_id, commande_cost_cents, coefficient_multiplier) VALUES (nextval('product_id_seq'), :n, :p, false, $qty, NOW(), :s, 'standard', $purchase, :c, $cost, 2)", ['n' => "$k $tag", 'p' => $price, 's' => "bf-$k-$tag", 'c' => $commandeId]);
        }
        $verifier = static::getContainer()->get(\App\Gestion\Services\PurchaseMetricsCalculator::class);
        $this->em();
        $legacy = $verifier->legacy(fn () => $verifier->commandeTotals($commandeId));
        $this->assertGreaterThanOrEqual(3, static::getContainer()->get(CommandeLineService::class)->backfill());
        $this->assertSame(0, static::getContainer()->get(CommandeLineService::class)->backfill(), 'rejouable : rien de plus au second passage');
        $costs = $conn->fetchAllKeyValue('SELECT p.name, l.unit_cost_cents FROM commande_line l JOIN product p ON p.id = l.product_id WHERE l.commande_id = ? ORDER BY p.name', [$commandeId]);
        $this->assertSame(["P $tag" => 1200, "Q $tag" => null, "R $tag" => 800], array_map(fn ($v) => $v === null ? null : (int) $v, $costs), 'Q : coût inconnu (NULL), jamais le prix de vente');
        $this->assertSame(3, (int) $conn->fetchOne('SELECT COUNT(*) FROM commande_line WHERE commande_id = ? AND source = ? AND quantity_received = quantity_ordered', [$commandeId, 'migration']), 'réputées reçues');
        $this->assertSame($legacy, $verifier->commandeTotals($commandeId), 'tableau de bord identique (Q compté au prix de vente, comme avant)');
        // Vérificateur : aucun écart pour cette commande ; un écart provoqué est signalé
        $this->em();
        $report = static::getContainer()->get(CommandeLinesVerifier::class)->verify();
        $this->assertSame([], array_values(array_filter($report['dashboardDiffs'], fn ($d) => str_starts_with($d, "commande $commandeId "))));
        $conn->executeStatement('UPDATE commande_line SET quantity_ordered = quantity_ordered + 1 WHERE commande_id = ? AND unit_cost_cents = 1200', [$commandeId]);
        $report = static::getContainer()->get(CommandeLinesVerifier::class)->verify();
        $this->assertCount(1, array_filter($report['dashboardDiffs'], fn ($d) => str_starts_with($d, "commande $commandeId ")), 'écart signalé');
    }

    public function testWeightedAverageCostAtSaleDate(): void
    {
        $tag = bin2hex(random_bytes(2));
        $calendar = static::getContainer()->get(PeriodCalendar::class);
        $today = $calendar->today();
        $period = $calendar->periodFor($today->modify('-560 days'));
        $this->assertNotContains($period->getPeriodKey(), array_map(fn ($d) => $calendar->periodFor($today->modify("-$d days"))?->getPeriodKey(), [400, 500, 650]), 'période réservée à ce test');
        $start = \DateTimeImmutable::createFromInterface($period->getStartDateCollection())->setTime(12, 0);
        $conn = $this->em()->getConnection();
        $conn->executeStatement("INSERT INTO product (id, name, price, isbestseller, quantity, created_at, slug, product_type) VALUES (nextval('product_id_seq'), :n, 3000, false, 10, NOW(), :s, 'standard')", ['n' => "Pull $tag", 's' => "pull-l0-$tag"]);
        $pull = (int) $conn->fetchOne('SELECT id FROM product WHERE slug = ?', ["pull-l0-$tag"]);
        $variant = (int) $this->create(ProductVariant::class, ['product' => $this->em()->find(Product::class, $pull)])->getId();
        $conn = $this->em()->getConnection();
        // Achat 1 au jour 5 (2 × 1000), achat 2 au jour 20 (2 × 1600) ; ventes au jour 10 (1) et au jour 30 (1)
        foreach ([[5, 1000, 'un'], [20, 1600, 'deux']] as [$day, $cost, $n]) {
            $date = $start->modify("+$day days");
            $conn->executeStatement("INSERT INTO commande (id, budget, date, name, is_closed, collections_id) VALUES (nextval('commande_id_seq'), 10000, :d, :n, false, :c)", ['d' => $date->format('Y-m-d 09:00:00'), 'n' => "Achat $n $tag", 'c' => $period->getId()]);
            $cid = (int) $conn->fetchOne('SELECT id FROM commande WHERE name = ?', ["Achat $n $tag"]);
            $conn->executeStatement("INSERT INTO commande_line (commande_id, product_id, quantity_ordered, quantity_received, unit_cost_cents, attached_at, source, created_at, updated_at) VALUES (?, ?, 2, 2, ?, ?, 'manual', NOW(), NOW())", [$cid, $pull, $cost, $date->format('Y-m-d 09:00:00')]);
        }
        foreach ([10, 30] as $day) {
            $order = $this->create(Order::class, ['totalAmount' => 3000.0], function (Order $o, EntityManagerInterface $em) use ($start, $day): void {
                $o->setOrderDate(\DateTime::createFromImmutable($start->modify("+$day days")))->setStatus($em->find(StatusCommande::class, 3))->setOrderType($em->find(OrderType::class, 1))
                    ->setOrderSource($em->find(OrderSource::class, 2))->setUserId($em->find(User::class, $this->userId));
            });
            $this->create(OrderItems::class, ['quantity' => 1, 'unitPrice' => 3000.0, 'totalPrice' => 3000.0], fn (OrderItems $i, EntityManagerInterface $em) => $i->setOrderAssociated($em->find(Order::class, $order->getId()))->setProductVariant($em->find(ProductVariant::class, $variant)));
        }
        $this->endOfWrite();
        $result = $this->get('/api/periodes/' . $period->getId() . '/resultat');
        $this->assertSame(['amount' => 1000 + 1300, 'items' => 2, 'unknownCostItems' => 0, 'estimatedItems' => 0, 'estimatedAmount' => 0], $result['cogs'], 'jour 10 : seul l\'achat 1 (1000) ; jour 30 : moyenne pondérée (2 × 1000 + 2 × 1600) / 4 = 1300');
        $this->assertSame(6000 - 2300, $result['result']['gross']);
    }

    /**
     * ART-SUPPR (contrat validé le 13/11/2026) : un article ou une variante qui a une histoire (ventes, lignes d'achat, mouvements de stock)
     * ne se supprime plus : 409 PRODUCT_IN_USE { productId, variantId?, uses }, rien n'est effacé. Avant, DELETE /api/products/{id}
     * supprimait en silence les lignes d'achat (cascade) et l'historique de stock. Détacher un article d'une commande : refusé (409
     * LINE_RECEIVED) si une ligne est déjà reçue.
     */
    public function testAProductOrVariantWithHistoryIsNotDeletedAndAReceivedLineIsNotDetached(): void
    {
        $tag = bin2hex(random_bytes(2));
        $today = static::getContainer()->get(PeriodCalendar::class)->today();
        $conn = $this->em()->getConnection();
        $supplier = (int) $this->post('/api/fournisseurs', ['name' => "Fournisseur suppr $tag", 'typeFournisseur' => $this->type()], 201)['id'];
        $commande = $this->post('/api/commandes', ['budget' => 100000, 'date' => $today->format('Y-m-d') . ' 09:00:00', 'name' => "Suppr $tag", 'fournisseur' => ['id' => $supplier]], 201);
        $product = function (string $name) use ($conn, $tag): array {
            $conn->executeStatement("INSERT INTO product (id, name, price, isbestseller, quantity, created_at, slug, product_type, purchase_price, coefficient_multiplier) VALUES (nextval('product_id_seq'), :n, 4000, false, 5, NOW(), :s, 'standard', 1500, 2.5)", ['n' => "$name $tag", 's' => strtolower($name) . "-suppr-$tag"]);
            $id = (int) $conn->fetchOne('SELECT id FROM product WHERE slug = ?', [strtolower($name) . "-suppr-$tag"]);

            return [$id, (int) $this->create(ProductVariant::class, ['product' => $this->em()->find(Product::class, $id)])->getId()];
        };
        [$blank] = $product('Vierge');
        [$bought, $boughtVariant] = $product('Achete');
        [$sold, $soldVariant] = $product('Vendu');
        [$moved, $movedVariant] = $product('Bouge');
        [$received] = $product('Recu');
        $spare = (int) $this->create(ProductVariant::class, ['product' => $this->em()->find(Product::class, $blank)])->getId();
        $this->post('/api/commandes/' . $commande['id'] . '/products/rattacher', ['products' => [['productId' => $bought, 'variantId' => $boughtVariant, 'quantity' => 2, 'purchasePrice' => 1400], ['productId' => $received, 'quantity' => 1, 'purchasePrice' => 900]]], 200);
        $order = $this->create(Order::class, ['totalAmount' => 4000.0], function (Order $o, EntityManagerInterface $em): void {
            $o->setOrderDate(new \DateTime())->setStatus($em->find(StatusCommande::class, 3))->setOrderType($em->find(OrderType::class, 1))->setOrderSource($em->find(OrderSource::class, 1))->setUserId($em->find(User::class, $this->userId));
        });
        $this->create(OrderItems::class, ['quantity' => 1, 'unitPrice' => 4000.0, 'totalPrice' => 4000.0], fn (OrderItems $i, EntityManagerInterface $em) => $i->setOrderAssociated($em->find(Order::class, $order->getId()))->setProductVariant($em->find(ProductVariant::class, $soldVariant)));
        $conn->executeStatement("INSERT INTO inventory_movements (id, product_variant_id, movement_type_id, quantity, movement_date, stock_before_movement, stock_after_movement) VALUES (nextval('inventory_movements_id_seq'), ?, 4, 1, NOW(), 0, 1)", [$movedVariant]);
        $this->endOfWrite();
        $none = ['sales' => 0, 'purchaseLines' => 0, 'stockMovements' => 0, 'bookings' => 0, 'subscriptionPlans' => 0, 'openCheckouts' => 0];
        $refused = function (string $route) {
            $this->request('DELETE', $route);
            $this->assertSame(409, $this->client->getResponse()->getStatusCode(), $route . ' : ' . $this->client->getResponse()->getContent());

            return $this->body();
        };
        $exists = fn (string $table, int $id) => (int) $conn->fetchOne("SELECT COUNT(*) FROM $table WHERE id = ?", [$id]);

        // Articles avec une histoire : refus, rien n'est effacé
        $b = $refused("/api/products/$bought");
        $this->assertSame(['PRODUCT_IN_USE', 'PRODUCT_IN_USE', $bought, array_replace($none, ['purchaseLines' => 1])], [$b['error'], $b['code'], $b['productId'], $b['uses']]);
        $this->assertStringContainsString('retirez-le de la vente', $b['message']);
        $this->assertSame([1, 1], [$exists('product', $bought), (int) $conn->fetchOne('SELECT COUNT(*) FROM commande_line WHERE product_id = ?', [$bought])], 'la ligne d\'achat n\'est plus supprimée en silence');
        $this->assertSame(array_replace($none, ['sales' => 1]), $refused("/api/products/$sold")['uses']);
        $this->assertSame(array_replace($none, ['stockMovements' => 1]), $refused("/api/products/$moved")['uses'], 'un mouvement de stock seul bloque');
        $this->assertSame([1, 1, 1], [$exists('product', $sold), $exists('product', $moved), (int) $conn->fetchOne('SELECT COUNT(*) FROM inventory_movements WHERE product_variant_id = ?', [$movedVariant])]);

        // Variantes : même garde, avec variantId ; l'historique de stock n'est plus effacé
        $v = $refused("/api/product-variants/$movedVariant");
        $this->assertSame(['PRODUCT_IN_USE', $moved, $movedVariant, array_replace($none, ['stockMovements' => 1])], [$v['error'], $v['productId'], $v['variantId'], $v['uses']]);
        $this->assertSame([1, 1], [$exists('product_variant', $movedVariant), (int) $conn->fetchOne('SELECT COUNT(*) FROM inventory_movements WHERE product_variant_id = ?', [$movedVariant])]);
        $this->assertSame(array_replace($none, ['sales' => 1]), $refused("/api/product-variants/$soldVariant")['uses']);
        $this->assertSame(array_replace($none, ['purchaseLines' => 1]), $refused("/api/product-variants/$boughtVariant")['uses']);
        $this->request('DELETE', "/api/product-variants/$spare");
        $this->assertSame([204, 0], [$this->client->getResponse()->getStatusCode(), $exists('product_variant', $spare)], 'variante sans histoire : supprimée');

        // EasyAdmin : même refus, avec le même message
        try {
            static::getContainer()->get(\App\Controller\Admin\ProductCrudController::class)->deleteEntity($this->em(), $this->em()->find(Product::class, $sold));
            $this->fail('article vendu supprimé depuis EasyAdmin');
        } catch (\Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException $e) {
            $this->assertStringContainsString('retirez-le de la vente', $e->getMessage());
        }
        $this->assertSame(1, $exists('product', $sold));
        $this->em()->clear();

        // Détacher : ligne reçue refusée, ligne non reçue détachée (seules les lignes de cet article dans cette commande)
        $line = (int) $conn->fetchOne('SELECT id FROM commande_line WHERE product_id = ? AND commande_id = ?', [$received, $commande['id']]);
        $conn->executeStatement('UPDATE commande_line SET quantity_received = 0 WHERE id = ?', [$line]); // ligne « à recevoir », comme une facture confirmée
        $this->em()->clear();
        $this->post('/api/commandes/' . $commande['id'] . "/lignes/$line/reception", [], 200);
        $this->assertSame(1, (int) $conn->fetchOne('SELECT COUNT(*) FROM inventory_movements WHERE commande_line_id = ?', [$line]), 'réception : le stock entre par un mouvement lié à la ligne');
        $r = $refused('/api/commandes/' . $commande['id'] . "/products/$received");
        $this->assertSame(['LINE_RECEIVED', $received, [['id' => $line, 'quantityReceived' => 1]]], [$r['error'], $r['context']['productId'], $r['context']['lines']]);
        $this->assertStringContainsString('annulez d\'abord la réception', $r['message']);
        $this->assertSame(1, $exists('commande_line', $line), 'ligne reçue gardée');
        $this->request('DELETE', '/api/commandes/' . $commande['id'] . "/products/$bought");
        $this->assertSame(204, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
        $this->assertSame([0, 1, 1], [(int) $conn->fetchOne('SELECT COUNT(*) FROM commande_line WHERE product_id = ?', [$bought]), $exists('product', $bought), $exists('commande_line', $line)], 'ligne réputée reçue (rattachée, sans mouvement) : lien retiré, article et autres lignes gardés');

        // Article louable : avec une réservation, refus ; sans réservation, supprimé avec son réglage de location. Liens sans règle en base
        // (avant : erreur de clé étrangère) : les articles associés partent avec lui, un contenu « véhicule » de la page est gardé et détaché.
        [$rented] = $product('Loue');
        [$rentable] = $product('Louable');
        foreach ([$rented, $rentable] as $id) {
            $conn->executeStatement("INSERT INTO booking_configuration (id, product_id, granularity, stock_quantity, min_duration, buffer_time) VALUES (nextval('booking_configuration_id_seq'), ?, 'days', 1, 1, 0)", [$id]);
        }
        $conn->executeStatement("INSERT INTO booking (id, product_id, start_at, end_at, quantity, status, is_finalized, created_at) VALUES (nextval('booking_id_seq'), ?, NOW(), NOW() + INTERVAL '1 day', 1, 'confirmed', false, NOW())", [$rented]);
        $conn->executeStatement("INSERT INTO related_product (id, product_id) VALUES (nextval('related_product_id_seq'), ?)", [$rentable]);
        $vehicle = (int) $conn->fetchOne("INSERT INTO vehicle (id, product_id, title) VALUES (nextval('vehicle_id_seq'), ?, 'Véhicule de la page') RETURNING id", [$rentable]);
        $this->em()->clear();
        $this->assertSame(array_replace($none, ['bookings' => 1]), $refused("/api/products/$rented")['uses'], 'une réservation est une histoire');
        $this->assertSame(1, (int) $conn->fetchOne('SELECT COUNT(*) FROM booking_configuration WHERE product_id = ?', [$rented]));
        $this->request('DELETE', "/api/products/$rentable");
        $this->assertSame(204, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
        $this->assertSame([0, 0, 0], [$exists('product', $rentable), (int) $conn->fetchOne('SELECT COUNT(*) FROM booking_configuration WHERE product_id = ?', [$rentable]), (int) $conn->fetchOne('SELECT COUNT(*) FROM related_product WHERE product_id = ?', [$rentable])], 'article louable sans réservation : supprimé avec son réglage et ses liens');
        $this->assertSame(['id' => $vehicle, 'product_id' => null], $conn->fetchAssociative('SELECT id, product_id FROM vehicle WHERE id = ?', [$vehicle]), 'contenu « véhicule » gardé, détaché');
        $conn->executeStatement('DELETE FROM vehicle WHERE id = ?', [$vehicle]);

        // Articles sans histoire : supprimés comme avant (204 sans corps)
        foreach ([$blank, $bought] as $id) {
            $this->request('DELETE', "/api/products/$id");
            $this->assertSame([204, '', 0], [$this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent(), $exists('product', $id)], "article $id sans histoire");
        }
        $this->request('DELETE', '/api/products/999999999');
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    // ----- helpers -----

    private function type(): int
    {
        $type = $this->em()->getRepository(TypeFournisseur::class)->findOneBy([]) ?? $this->create(TypeFournisseur::class, ['name' => 'Autre']);
        $this->endOfWrite();

        return (int) $type->getId();
    }

    private function body(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    private function headers(): array
    {
        return ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $this->token];
    }

    private function request(string $method, string $route, ?array $body = null): void
    {
        $this->client->request($method, $route, [], [], $this->headers(), $body === null ? null : json_encode($body));
    }

    private function get(string $route): array
    {
        $this->request('GET', $route);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), $route . ' : ' . substr((string) $this->client->getResponse()->getContent(), 0, 300));

        return $this->body();
    }

    private function post(string $route, array $body, int $expected): array
    {
        $this->request('POST', $route, $body);
        $this->assertSame($expected, $this->client->getResponse()->getStatusCode(), $route . ' : ' . substr((string) $this->client->getResponse()->getContent(), 0, 400));

        return $this->body();
    }

    private function patch(string $route, array $body): array
    {
        $this->request('PATCH', $route, $body);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), $route . ' : ' . substr((string) $this->client->getResponse()->getContent(), 0, 300));

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
        $email = 'gestion-l0-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $em = $this->em();
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Gestion')->setLastname('L0')->setRoles(['ROLE_ADMIN'])->setIsVerified(true)->setPassword('inutilisable');
        $em->persist($user);
        $em->flush();
        $this->userId = (int) $user->getId();
        $this->token = static::getContainer()->get('lexik_jwt_authentication.jwt_manager')->create($user);
        $this->endOfWrite();
    }
}
