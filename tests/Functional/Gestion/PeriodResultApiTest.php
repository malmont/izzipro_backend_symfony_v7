<?php

namespace App\Tests\Functional\Gestion;

use App\Entity\Collections;
use App\Entity\Order;
use App\Entity\OrderItems;
use App\Entity\OrderSource;
use App\Entity\OrderType;
use App\Entity\Product;
use App\Entity\ProductVariant;
use App\Entity\StatusCommande;
use App\Entity\Subscription;
use App\Entity\SubscriptionPlan;
use App\Entity\TypeFournisseur;
use App\Entity\User;
use App\EventSubscriber\CacheInvalidationSubscriber;
use App\Gestion\Services\PeriodCalendar;
use App\Services\TenantEntityManagerProvider;
use App\Tests\Functional\Cache\CacheTestEntityFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Résultat de période (lot P2, 10/11/2026, `docs/periodes-gestion.md` § 3, contrat validé par l'application) :
 * ventes d'articles nettes des retours et hors taxes, part au coût inconnu exclue des marges, retours déduits du coût au même
 * coût unitaire (retour d'une vente d'une période précédente compris), locations et abonnements à part, livraison facturée à
 * part, annulations et cartes-cadeaux exclues, frais et investissement de la période, taux null sans dénominateur, figé à la
 * première lecture d'une période fermée en ignorant les rattachements postérieurs à l'échéance, réouverture, 404 NOT_A_PERIOD.
 * Les ventes sont datées dans la période d'il y a 400 jours (aucun autre test n'y écrit) ; les taux null dans celle d'il y a 650 jours.
 */
class PeriodResultApiTest extends WebTestCase
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

    public function testPeriodResultFollowsTheContractAndFreezesWithCostCutoff(): void
    {
        $tag = bin2hex(random_bytes(2));
        $calendar = static::getContainer()->get(PeriodCalendar::class);
        $today = $calendar->today();
        $period = $calendar->periodFor($today->modify('-400 days'));
        $this->assertNotNull($period, 'période d\'il y a 400 jours créée par reprise vers le passé');
        $this->assertTrue($calendar->isClosed($period), 'fermée par la date');
        $periodId = (int) $period->getId();
        $start = \DateTimeImmutable::createFromInterface($period->getStartDateCollection())->setTime(0, 0);
        $inPeriod = $start->modify('+5 days')->setTime(12, 0); // loin des bornes de journée, stocké en heure UTC
        $before = $start->modify('-100 days')->setTime(12, 0); // période précédente

        // Produits : A au coût de commande fournisseur (rattaché pendant la période), C sans aucun coût, D au prix d'achat seul
        $em = $this->em();
        $conn = $em->getConnection();
        foreach ([['A', 2500, 'NULL'], ['C', 4000, 'NULL'], ['D', 1000, '300']] as [$letter, $price, $purchase]) {
            $conn->executeStatement("INSERT INTO product (id, name, price, isbestseller, quantity, created_at, slug, product_type, purchase_price) VALUES (nextval('product_id_seq'), :n, :p, false, 2, NOW(), :s, 'standard', $purchase)", ['n' => "Résultat $letter $tag", 'p' => $price, 's' => "resultat-$letter-$tag"]);
        }
        $pid = fn (string $letter): int => (int) $conn->fetchOne('SELECT id FROM product WHERE slug = :s', ['s' => "resultat-$letter-$tag"]);
        [$a, $c, $d] = [$pid('A'), $pid('C'), $pid('D')];
        $variant = fn (int $productId): int => (int) $this->create(ProductVariant::class, ['product' => $this->em()->find(Product::class, $productId)])->getId();
        [$va, $vc, $vd] = [$variant($a), $variant($c), $variant($d)];
        // Commande fournisseur datée dans la période : A rattaché (coût 1000, quantité 2 → investissement 2000 ; la quantité est reposée après la création des variantes, qui la recalcule), fret 150
        $conn = $this->em()->getConnection();
        $conn->executeStatement("INSERT INTO commande (id, budget, date, name, is_closed, collections_id) VALUES (nextval('commande_id_seq'), 500000, :d, :n, false, :col)", ['d' => $inPeriod->format('Y-m-d H:i:s'), 'n' => "Achat $tag", 'col' => $periodId]);
        $commandeId = (int) $conn->fetchOne('SELECT id FROM commande WHERE name = :n', ['n' => "Achat $tag"]);
        $conn->executeStatement('UPDATE product SET commande_id = :c, commande_cost_cents = 1000, commande_attached_at = :at, quantity = 2 WHERE id = :p', ['c' => $commandeId, 'at' => $inPeriod->format('Y-m-d H:i:s'), 'p' => $a]);
        static::getContainer()->get(\App\Services\CommandeService\CommandeLineService::class)->backfill(); // rattachement d'avant le lot L0 : repris en ligne d'achat, comme la migration
        $conn->executeStatement("INSERT INTO frais_de_port (id, name, price, commande_id) VALUES (nextval('frais_de_port_id_seq'), 'Fret', 150, :c)", ['c' => $commandeId]);
        $conn->executeStatement("INSERT INTO note_de_frais (id, name, montant, date, description, collection_id) VALUES (nextval('note_de_frais_id_seq'), 'Essence', 500, :d, 'Essence', :col)", ['d' => $inPeriod->format('Y-m-d'), 'col' => $periodId]);

        // Ventes d'articles : A 3 × 2500 en caisse (livraison facturée 700) ; C 1 × 4000 web (coût inconnu) ; D 2 × 1000 mobile ; D 1 × 1150 TTC (taxe 150) mobile
        $s1 = $this->order(1, 3, 2, $inPeriod, fn (Order $o) => $o->setShippingCost(700.0));
        $this->item($s1, $va, 3, 2500);
        $this->item($this->order(1, 3, 1, $inPeriod), $vc, 1, 4000);
        $this->item($this->order(1, 3, 3, $inPeriod), $vd, 2, 1000);
        $this->item($this->order(1, 3, 3, $inPeriod, fn (Order $o) => $o->setPricesIncludeTax(true)->setSubTotal(1150.0)->setTotalTax(150.0)), $vd, 1, 1150);
        // Location (ligne liée à une réservation) et abonnement : autres revenus, hors résultat
        $rentalItem = $this->item($this->order(1, 3, 1, $inPeriod), $va, 1, 5000);
        $conn->executeStatement("INSERT INTO booking (id, start_at, end_at, quantity, status, is_finalized, created_at, order_item_id) VALUES (nextval('booking_id_seq'), NOW(), NOW(), 1, 'confirmed', false, NOW(), :i)", ['i' => $rentalItem]);
        $plan = $this->create(SubscriptionPlan::class, ['product' => $this->em()->find(Product::class, $a), 'names' => ['fr' => 'Plan'], 'interval' => 'month', 'price' => 2000]);
        $subscription = $this->create(Subscription::class, ['user' => $this->em()->find(User::class, $this->userId), 'plan' => $plan, 'quantity' => 1, 'status' => 'active', 'cancelAtPeriodEnd' => false, 'shippingAmount' => 0]);
        $this->item($this->order(1, 3, 3, $inPeriod, fn (Order $o, EntityManagerInterface $em) => $o->setSubscription($em->find(Subscription::class, $subscription->getId()))), $va, 1, 2000);
        // Retour dans la période d'une vente de la période précédente : A 1 × 2500 rendu (coût 1000 retiré)
        $original = $this->order(1, 3, 2, $before);
        $this->item($original, $va, 1, 2500);
        $this->item($this->order(2, 3, 2, $inPeriod, fn (Order $o, EntityManagerInterface $em) => $o->setReturnedFrom($em->find(Order::class, $original->getId()))->setTotalAmount(-2500.0)), $va, 1, 2500);
        // Exclues : commande annulée (statut 7), vente de carte-cadeau (type 3), commande incomplète (statut 1)
        $this->item($this->order(1, 7, 2, $inPeriod), $va, 10, 2500);
        $this->item($this->order(3, 3, 2, $inPeriod), null, 1, 5000);
        $this->item($this->order(1, 1, 1, $inPeriod), $va, 4, 2500);
        $this->endOfWrite();

        // Rattachement tardif de C (coût 2200) à une commande du jour : après l'échéance de la période → coût toujours inconnu pour elle
        $type = $this->create(TypeFournisseur::class, ['name' => 'Type résultat ' . $tag]);
        $this->endOfWrite();
        $supplier = $this->post('/api/fournisseurs', ['name' => "Fournisseur $tag", 'typeFournisseur' => $type->getId()], 201);
        $todayOrder = $this->post('/api/commandes', ['budget' => 100000, 'date' => $today->format('Y-m-d') . ' 10:00:00', 'name' => "Aujourd'hui $tag", 'fournisseur' => ['id' => $supplier['id']]], 201);
        $this->request('POST', '/api/commandes/' . $todayOrder['id'] . '/products/rattacher', ['products' => [['productId' => $c, 'purchasePrice' => 2200, 'quantity' => 1]]]);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), substr((string) $this->client->getResponse()->getContent(), 0, 300));
        $this->assertNotNull($this->em()->getConnection()->fetchOne('SELECT commande_attached_at FROM product WHERE id = :p', ['p' => $c]), 'rattachement horodaté (lot P2)');
        $this->endOfWrite();

        // Résultat figé à la première lecture
        $result = $this->get("/api/periodes/$periodId/resultat");
        $this->assertSame([$periodId, $period->getPeriodKey(), 'closed', 'CAD', true], [$result['period']['id'], $result['period']['periodKey'], $result['period']['status'], $result['currency'], $result['frozen']]);
        $this->assertNotNull($result['frozenAt']);
        $this->assertSame(['revenue' => 12000, 'orders' => 4, 'items' => 7, 'returns' => ['amount' => 2500, 'orders' => 1, 'items' => 1], 'unknownCostRevenue' => 4000, 'shippingBilled' => 700,
            'bySource' => ['ecommerce' => 4000, 'pos' => 5000, 'mobile_app' => 3000]], $result['sales'], 'ventes nettes des retours, hors taxes (1150 TTC → 1000), annulée / carte-cadeau / incomplète exclues');
        $this->assertSame(['rentals' => 5000, 'subscriptions' => 2000, 'total' => 7000], $result['otherRevenue']);
        $this->assertSame(['amount' => 2900, 'items' => 6, 'unknownCostItems' => 1, 'estimatedItems' => 0, 'estimatedAmount' => 0], $result['cogs'], 'A 3 × 1000 + D 3 × 300 − retour 1 × 1000 ; C inconnu');
        $this->assertSame(['shipping' => 150, 'notes' => 500, 'other' => [], 'total' => 650], $result['expenses']);
        $this->assertSame(['gross' => 5100, 'net' => 4450, 'tauxMarge' => 175.86, 'tauxMarque' => 63.75, 'netRate' => 55.63, 'netAfterFixed' => 4450, 'netAfterFixedRate' => 55.63], $result['result'], 'gross = (12000 − 4000) − 2900 ; net = gross − 650 ; taux sur le coût et sur les ventes au coût connu ; sans frais fixe (FF1) : après frais fixes = net');
        $this->assertSame(['purchases' => 2000, 'shipping' => 150, 'total' => 2150], $result['investment']);
        $this->assertSame(1, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM period_result WHERE collection_id = ?', [$periodId]));

        // Figé : une vente ajoutée ensuite ne change rien ; même réponse par l'alias des collections
        $this->item($this->order(1, 3, 2, $inPeriod), $va, 1, 2500);
        $this->endOfWrite();
        $this->assertSame($result, $this->get("/api/periodes/$periodId/resultat"), 'figé');
        $this->assertSame($result, $this->get("/api/collections/$periodId/resultat"), 'alias');

        // Réouverture : résultat figé effacé, calcul vivant sans coupure (la vente ajoutée compte) ; nouvelle clôture : refigé, coupure = cette clôture.
        // Lot L0 : le coût de C vient d'un achat daté d'aujourd'hui, après ses ventes : il ne leur est jamais imputé (coût à la date de la vente)
        $this->request('POST', "/api/dashboard/collection/$periodId/reopen");
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM period_result WHERE collection_id = ?', [$periodId]));
        $live = $this->get("/api/periodes/$periodId/resultat");
        $this->assertSame([false, null, 'open', 14500], [$live['frozen'], $live['frozenAt'], $live['period']['status'], $live['sales']['revenue']], 'vivant après réouverture');
        $this->assertSame(['amount' => 3900, 'items' => 7, 'unknownCostItems' => 1, 'estimatedItems' => 0, 'estimatedAmount' => 0], $live['cogs'], 'rouverte : la vente ajoutée compte (A 1000) ; C, acheté après ses ventes, reste au coût inconnu');
        $this->request('POST', "/api/dashboard/collection/$periodId/close");
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
        $frozen = $this->get("/api/periodes/$periodId/resultat");
        $this->assertSame([true, 'closed', 14500, 4000, 3900], [$frozen['frozen'], $frozen['period']['status'], $frozen['sales']['revenue'], $frozen['sales']['unknownCostRevenue'], $frozen['cogs']['amount']], 'refigée : coûts à la date de chaque vente');

        // Période sans vente : taux null ; collection manuelle : 404 NOT_A_PERIOD ; période courante : vivante
        $empty = $calendar->periodFor($today->modify('-650 days')); // hors des périodes du semis demo (2025-12 → 2026), dans les 24 mois du calendrier
        $emptyResult = $this->get('/api/periodes/' . $empty->getId() . '/resultat');
        $this->assertSame([0, null, null, null], [$emptyResult['sales']['revenue'], $emptyResult['result']['tauxMarge'], $emptyResult['result']['tauxMarque'], $emptyResult['result']['netRate']]);
        $manual = $this->post('/api/createcollections', ['budgetCollection' => 1000, 'nomCollection' => "Manuelle $tag"], 201);
        $this->request('GET', '/api/periodes/' . $manual['id'] . '/resultat');
        $this->assertSame([404, 'NOT_A_PERIOD'], [$this->client->getResponse()->getStatusCode(), $this->body()['error']]);
        $current = $this->get('/api/periodes/courante');
        $this->assertFalse($this->get('/api/periodes/' . $current['id'] . '/resultat')['frozen']);
    }

    // ----- fabrique -----

    private function order(int $type, int $status, int $source, \DateTimeImmutable $at, ?callable $more = null): Order
    {
        return $this->create(Order::class, ['totalAmount' => 1.0], function (Order $o, EntityManagerInterface $em) use ($type, $status, $source, $at, $more): void {
            $o->setOrderDate(\DateTime::createFromInterface($at))->setStatus($em->find(StatusCommande::class, $status))->setOrderType($em->find(OrderType::class, $type))
                ->setOrderSource($em->find(OrderSource::class, $source))->setUserId($em->find(User::class, $this->userId));
            if ($more !== null) {
                $more($o, $em);
            }
        });
    }

    /** Ligne de commande client (prix nets en cents, comme la caisse) ; retour : total positif, le type de la commande fait le signe */
    private function item(Order $order, ?int $variantId, int $quantity, int $unitPrice): int
    {
        $item = $this->create(OrderItems::class, ['quantity' => $quantity, 'unitPrice' => (float) $unitPrice, 'totalPrice' => (float) ($unitPrice * $quantity)], function (OrderItems $i, EntityManagerInterface $em) use ($order, $variantId): void {
            $i->setOrderAssociated($em->find(Order::class, $order->getId()));
            if ($variantId !== null) {
                $i->setProductVariant($em->find(ProductVariant::class, $variantId));
            } else {
                $i->setCustomLabel('Carte-cadeau');
            }
        });

        return (int) $item->getId();
    }

    // ----- helpers -----

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
        $this->assertSame($expected, $this->client->getResponse()->getStatusCode(), $route . ' : ' . substr((string) $this->client->getResponse()->getContent(), 0, 300));

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
        $email = 'gestion-p2-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $em = $this->em();
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Gestion')->setLastname('P2')->setRoles(['ROLE_ADMIN'])->setIsVerified(true)->setPassword('inutilisable');
        $em->persist($user);
        $em->flush();
        $this->userId = (int) $user->getId();
        $this->token = static::getContainer()->get('lexik_jwt_authentication.jwt_manager')->create($user);
        $this->endOfWrite();
    }
}
