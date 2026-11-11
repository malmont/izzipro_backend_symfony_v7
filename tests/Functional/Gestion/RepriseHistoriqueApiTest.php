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
use App\Entity\User;
use App\EventSubscriber\CacheInvalidationSubscriber;
use App\Gestion\Services\PeriodCalendar;
use App\Services\TenantEntityManagerProvider;
use App\Tests\Functional\Cache\CacheTestEntityFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Reprise d'historique (lot P3, 10/11/2026, `docs/periodes-gestion.md` § 4, contrat validé par l'application) : simulation
 * paginée sans écriture, application (coûts du corps, puis prix d'achat de la fiche, puis estimation par coefficient ; déjà
 * rattaché → `skipped`), commande de reprise par période figée, quantité de ligne distincte du stock, **aucun effet sur la vente**
 * (prix, prix d'achat, stock, variantes intacts), résultat de période recalculé avec la part estimée, une reprise à la fois,
 * annulation qui ne retire que ce que la reprise a posé (période créée restée vide comprise), validations.
 * Les ventes sont datées dans la période d'il y a 500 jours (aucun autre test n'y écrit).
 */
class RepriseHistoriqueApiTest extends WebTestCase
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

    public function testHistoryRecoveryAppliesAndCancelsWithoutTouchingSales(): void
    {
        $tag = bin2hex(random_bytes(2));
        $calendar = static::getContainer()->get(PeriodCalendar::class);
        $today = $calendar->today();
        $def = PeriodCalendar::definition($today->modify('-500 days'), $calendar->frequency());
        $periodKey = $def['key'];
        $inPeriod = $def['start']->modify('+5 days')->setTime(12, 0);
        $existedBefore = $this->em()->getRepository(Collections::class)->findOneBy(['periodKey' => $periodKey, 'kind' => Collections::KIND_PERIOD]) !== null; // créée par un autre test si la suite a déjà repris le calendrier plus loin
        $exists = fn (): bool => $this->em()->getRepository(Collections::class)->findOneBy(['periodKey' => $periodKey, 'kind' => Collections::KIND_PERIOD]) !== null;

        // Produits : E sans coût (coût fourni), F au prix d'achat de la fiche, G et J sans rien (estimés), H déjà rattaché à une commande fournisseur
        $conn = $this->em()->getConnection();
        foreach ([['E', 1500, 'NULL'], ['F', 2000, '300'], ['G', 1000, 'NULL'], ['H', 800, 'NULL'], ['J', 700, 'NULL']] as [$letter, $price, $purchase]) {
            $conn->executeStatement("INSERT INTO product (id, name, price, isbestseller, quantity, created_at, slug, product_type, purchase_price, code) VALUES (nextval('product_id_seq'), :n, :p, false, 7, NOW(), :s, 'standard', $purchase, :c)", ['n' => "Reprise $letter $tag", 'p' => $price, 's' => "reprise-$letter-$tag", 'c' => "RP-$letter-$tag"]);
        }
        $pid = fn (string $letter): int => (int) $conn->fetchOne('SELECT id FROM product WHERE slug = :s', ['s' => "reprise-$letter-$tag"]);
        $ids = ['E' => $pid('E'), 'F' => $pid('F'), 'G' => $pid('G'), 'H' => $pid('H'), 'J' => $pid('J')];
        $variants = [];
        foreach ($ids as $letter => $productId) {
            $variants[$letter] = (int) $this->create(ProductVariant::class, ['product' => $this->em()->find(Product::class, $productId)])->getId();
        }
        $conn = $this->em()->getConnection();
        $conn->executeStatement('UPDATE product SET quantity = 7 WHERE id IN (:ids)', ['ids' => array_values($ids)], ['ids' => \Doctrine\DBAL\ArrayParameterType::INTEGER]); // la création des variantes recalcule le stock
        $conn->executeStatement("INSERT INTO commande (id, budget, date, name, is_closed) VALUES (nextval('commande_id_seq'), 20000, :d, :n, false)", ['d' => $inPeriod->format('Y-m-d H:i:s'), 'n' => "Commande H $tag"]);
        $commandeH = (int) $conn->fetchOne('SELECT id FROM commande WHERE name = :n', ['n' => "Commande H $tag"]);
        $conn->executeStatement('UPDATE product SET commande_id = :c, commande_cost_cents = 200, commande_attached_at = :at WHERE id = :p', ['c' => $commandeH, 'at' => $inPeriod->format('Y-m-d H:i:s'), 'p' => $ids['H']]);
        static::getContainer()->get(\App\Services\CommandeService\CommandeLineService::class)->backfill(); // rattachement d'avant le lot L0 : repris en ligne d'achat, comme la migration
        // Ventes dans la période : E 2 vendus puis 1 retourné, F 1, G 3, H 1, J 1
        $this->item($this->order(1, 3, 2, $inPeriod), $variants['E'], 2, 1500);
        $this->item($this->order(2, 3, 2, $inPeriod->modify('+1 day')), $variants['E'], 1, 1500);
        $this->item($this->order(1, 3, 1, $inPeriod), $variants['F'], 1, 2000);
        $this->item($this->order(1, 3, 3, $inPeriod), $variants['G'], 3, 1000);
        $this->item($this->order(1, 3, 2, $inPeriod), $variants['H'], 1, 800);
        $this->item($this->order(1, 3, 2, $inPeriod), $variants['J'], 1, 700);
        $this->endOfWrite();
        $snapshot = $this->snapshot(array_values($ids));

        // Validations
        $this->request('POST', '/api/gestion/reprise/simulation', ['months' => 30]);
        $this->assertSame([422, ['months' => 'OUT_OF_RANGE']], [$this->client->getResponse()->getStatusCode(), $this->body()['fields']]);
        $this->request('POST', '/api/gestion/reprise/appliquer', ['months' => 20, 'estimateCoefficient' => 0, 'costs' => [['productId' => 999999999, 'purchasePrice' => 1], ['productId' => $ids['E'], 'purchasePrice' => 12.5]]]);
        $this->assertSame([422, ['costs[0].productId' => 'PRODUCT_NOT_FOUND', 'costs[1].purchasePrice' => 'MUST_BE_INTEGER_CENTS', 'estimateCoefficient' => 'MUST_BE_POSITIVE']], [$this->client->getResponse()->getStatusCode(), $this->body()['fields']]);
        $this->assertSame($existedBefore, $exists(), 'une requête invalide n\'écrit rien');

        // Simulation sans coefficient : coûts connus / inconnus, pagination
        $sim = $this->post('/api/gestion/reprise/simulation', ['months' => 20, 'limit' => 200], 200);
        $mine = $this->mine($sim['products'], $ids);
        $this->assertSame(['quantitySold' => 1, 'revenue' => 1500, 'costKnown' => false, 'costSource' => null, 'unitCost' => null, 'firstPeriodKey' => $periodKey, 'code' => "RP-E-$tag"], $this->pick($mine['E'], ['quantitySold', 'revenue', 'costKnown', 'costSource', 'unitCost', 'firstPeriodKey', 'code']), 'E : 2 vendus − 1 retourné');
        $this->assertSame([true, 'purchasePrice', 300], [$mine['F']['costKnown'], $mine['F']['costSource'], $mine['F']['unitCost']]);
        $this->assertSame([true, 'commande', 200], [$mine['H']['costKnown'], $mine['H']['costSource'], $mine['H']['unitCost']]);
        $this->assertSame([null, 3], [$mine['G']['costSource'], $mine['G']['quantitySold']]);
        $this->assertGreaterThanOrEqual(5, $sim['productsTotal']);
        $this->assertSame('CAD', $sim['currency'], 'devise du site à la racine (demande de l\'application)');
        $this->assertSame([$periodKey, $existedBefore], [$sim['periods'][array_search($periodKey, array_column($sim['periods'], 'periodKey'), true)]['periodKey'], $sim['periods'][array_search($periodKey, array_column($sim['periods'], 'periodKey'), true)]['exists']], 'période du créneau');
        $this->assertGreaterThanOrEqual(5, $sim['sales']['unknownCostItems']);
        $page = $this->post('/api/gestion/reprise/simulation', ['months' => 20, 'limit' => 2, 'page' => 1], 200);
        $this->assertSame([2, 2, 1, $sim['productsTotal'], $sim['sales']], [count($page['products']), $page['limit'], $page['page'], $page['productsTotal'], $page['sales']], 'page de 2 ; totaux sur tout le créneau');
        $this->assertSame([], $this->post('/api/gestion/reprise/simulation', ['months' => 20, 'limit' => 200, 'page' => 50], 200)['products']);
        // Avec coefficient 2 : G estimé à 500, J à 350 ; investissement estimé
        $sim2 = $this->post('/api/gestion/reprise/simulation', ['months' => 20, 'limit' => 200, 'estimateCoefficient' => 2], 200);
        $mine2 = $this->mine($sim2['products'], $ids);
        $this->assertSame(['estimated', 500, false], [$mine2['G']['costSource'], $mine2['G']['unitCost'], $mine2['G']['costKnown']]);
        $this->assertGreaterThanOrEqual(3 * 500 + 350, $sim2['investment']['estimated']);
        $this->assertSame($existedBefore, $exists(), 'la simulation n\'écrit rien');
        $this->assertSame([false, 'CAD'], [$this->get('/api/gestion/reprise')['active'], $this->get('/api/gestion/reprise')['currency']]);

        // Application : E au coût fourni, F au prix d'achat, G et J estimés, H ignoré (skipped)
        $applied = $this->post('/api/gestion/reprise/appliquer', ['months' => 20, 'estimateCoefficient' => 2, 'costs' => [['productId' => $ids['E'], 'purchasePrice' => 400], ['productId' => $ids['H'], 'purchasePrice' => 999]]], 201);
        $this->assertSame([true, 20, 2.0, [['productId' => $ids['H'], 'code' => 'ALREADY_ATTACHED']]], [$applied['active'], $applied['months'], (float) $applied['estimateCoefficient'], $applied['skipped']]);
        $periodRow = $applied['periods'][array_search($periodKey, array_column($applied['periods'], 'periodKey'), true)];
        $this->assertSame(!$existedBefore, $periodRow['created'], 'période créée par la reprise quand elle manquait');
        $commandeRow = $applied['commandes'][array_search($periodKey, array_column($applied['commandes'], 'periodKey'), true)];
        $this->assertSame([4, 2, 400 + 300 + 1500 + 350], [$commandeRow['productCount'], $commandeRow['estimatedCount'], $commandeRow['investment']], 'E 1 × 400, F 1 × 300, G 3 × 500, J 1 × 350');
        $rows = $this->em()->getConnection()->fetchAllAssociative('SELECT id, commande_id, commande_cost_cents, commande_quantity, commande_attached_at, commande_cost_estimated FROM product WHERE id IN (:ids) ORDER BY id', ['ids' => array_values($ids)], ['ids' => \Doctrine\DBAL\ArrayParameterType::INTEGER]);
        $byId = array_column($rows, null, 'id');
        $this->assertSame([$commandeRow['id'], 400, 1, false, $def['start']->format('Y-m-d 00:00:00')], [(int) $byId[$ids['E']]['commande_id'], (int) $byId[$ids['E']]['commande_cost_cents'], (int) $byId[$ids['E']]['commande_quantity'], (bool) $byId[$ids['E']]['commande_cost_estimated'], $byId[$ids['E']]['commande_attached_at']], 'E : coût fourni, quantité vendue sur la ligne, rattachement au début de la période');
        $this->assertSame([300, false], [(int) $byId[$ids['F']]['commande_cost_cents'], (bool) $byId[$ids['F']]['commande_cost_estimated']]);
        $this->assertSame([500, 3, true], [(int) $byId[$ids['G']]['commande_cost_cents'], (int) $byId[$ids['G']]['commande_quantity'], (bool) $byId[$ids['G']]['commande_cost_estimated']]);
        $this->assertSame([$commandeH, 200], [(int) $byId[$ids['H']]['commande_id'], (int) $byId[$ids['H']]['commande_cost_cents']], 'H : jamais touché');
        $this->assertSame($snapshot, $this->snapshot(array_values($ids)), 'AUCUN EFFET SUR LA VENTE : prix, prix d\'achat, stock, variantes intacts');
        $commandes = $this->get('/api/collections/' . $periodRow['id'] . '/commandes');
        $reprise = $commandes[array_search($commandeRow['id'], array_column($commandes, 'id'), true)];
        $this->assertSame(['reprise', 'Reprise d\'historique'], [$reprise['kind'], $reprise['fournisseur']['name']]);
        $this->assertTrue($this->em()->find(\App\Entity\Commande::class, $commandeRow['id'])->getIsClosed(), 'commande de reprise figée');
        // Résultat de période : coûts de reprise comptés, part estimée exposée
        $result = $this->get('/api/periodes/' . $periodRow['id'] . '/resultat');
        $this->assertSame(['amount' => 400 + 300 + 1500 + 200 + 350, 'items' => 8, 'unknownCostItems' => 0, 'estimatedItems' => 4, 'estimatedAmount' => 1850], $result['cogs'], 'E 2 × 400 − 1 × 400, F 300, G 1500, H 200, J 350 ; 8 articles vendus au coût connu ; dont G et J estimés');
        $this->assertSame([true, 9500 - 1500], [$result['frozen'], $result['sales']['revenue']], 'ventes 9500 − retour 1500');
        // Une reprise à la fois
        $this->request('POST', '/api/gestion/reprise/appliquer', ['months' => 20]);
        $this->assertSame([409, 'REPRISE_ACTIVE'], [$this->client->getResponse()->getStatusCode(), $this->body()['error']]);
        $this->assertSame([true, 20], [$this->get('/api/gestion/reprise')['active'], $this->get('/api/gestion/reprise')['months']]);

        // Annulation : rattachements de la reprise retirés, H intact, période créée restée vide supprimée, résultat figé effacé
        $cancelled = $this->post('/api/gestion/reprise/annuler', [], 200);
        $this->assertTrue($cancelled['cancelled']);
        $this->assertGreaterThanOrEqual(1, $cancelled['commandesRemoved']);
        $this->assertGreaterThanOrEqual(4, $cancelled['productsDetached']);
        $this->assertSame(!$existedBefore, in_array($periodKey, $cancelled['periodsRemoved'], true), 'période créée par la reprise et restée vide : supprimée ; préexistante : gardée');
        $rows = $this->em()->getConnection()->fetchAllAssociative('SELECT id, commande_id, commande_cost_cents, commande_quantity, commande_attached_at, commande_cost_estimated FROM product WHERE id IN (:ids) ORDER BY id', ['ids' => array_values($ids)], ['ids' => \Doctrine\DBAL\ArrayParameterType::INTEGER]);
        foreach ($rows as $row) {
            if ((int) $row['id'] === $ids['H']) {
                $this->assertSame([$commandeH, 200], [(int) $row['commande_id'], (int) $row['commande_cost_cents']], 'H : toujours rattaché à sa commande');
            } else {
                $this->assertSame([null, null, null, null, false], [$row['commande_id'], $row['commande_cost_cents'], $row['commande_quantity'], $row['commande_attached_at'], (bool) $row['commande_cost_estimated']], 'détaché proprement');
            }
        }
        $this->assertSame($snapshot, $this->snapshot(array_values($ids)), 'annulation : vente intacte');
        $this->assertSame($existedBefore, $exists(), 'période : état d\'avant la reprise');
        $this->assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM commande WHERE kind = ?', ['reprise']));
        $this->assertFalse($this->get('/api/gestion/reprise')['active']);
        $this->request('POST', '/api/gestion/reprise/annuler');
        $this->assertSame([404, 'REPRISE_NOT_FOUND'], [$this->client->getResponse()->getStatusCode(), $this->body()['error']]);
        $this->assertGreaterThanOrEqual(2, (int) $this->em()->getConnection()->fetchOne("SELECT COUNT(*) FROM content_audit_log WHERE resource = 'gestion-reprise'"), 'journal : apply + cancel');
    }

    // ----- fabrique -----

    /** État « vente » des produits : prix, prix d'achat, stock, variantes */
    private function snapshot(array $ids): array
    {
        return $this->em()->getConnection()->fetchAllAssociative('SELECT p.id, p.price, p.purchase_price, p.quantity, p.coefficient_multiplier, (SELECT COUNT(*) FROM product_variant v WHERE v.product_id = p.id) AS variants FROM product p WHERE p.id IN (:ids) ORDER BY p.id', ['ids' => $ids], ['ids' => \Doctrine\DBAL\ArrayParameterType::INTEGER]);
    }

    /** @return array<string, array> lignes de simulation des produits du test, par lettre */
    private function mine(array $products, array $ids): array
    {
        $byId = array_column($products, null, 'productId');
        $out = [];
        foreach ($ids as $letter => $id) {
            $this->assertArrayHasKey($id, $byId, "produit $letter dans la simulation");
            $out[$letter] = $byId[$id];
        }

        return $out;
    }

    private function pick(array $row, array $keys): array
    {
        $out = [];
        foreach ($keys as $k) {
            $out[$k] = $row[$k];
        }

        return $out;
    }

    private function order(int $type, int $status, int $source, \DateTimeImmutable $at): Order
    {
        return $this->create(Order::class, ['totalAmount' => 1.0], function (Order $o, EntityManagerInterface $em) use ($type, $status, $source, $at): void {
            $o->setOrderDate(\DateTime::createFromInterface($at))->setStatus($em->find(StatusCommande::class, $status))->setOrderType($em->find(OrderType::class, $type))
                ->setOrderSource($em->find(OrderSource::class, $source))->setUserId($em->find(User::class, $this->userId));
        });
    }

    private function item(Order $order, int $variantId, int $quantity, int $unitPrice): void
    {
        $this->create(OrderItems::class, ['quantity' => $quantity, 'unitPrice' => (float) $unitPrice, 'totalPrice' => (float) ($unitPrice * $quantity)], function (OrderItems $i, EntityManagerInterface $em) use ($order, $variantId): void {
            $i->setOrderAssociated($em->find(Order::class, $order->getId()))->setProductVariant($em->find(ProductVariant::class, $variantId));
        });
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
        $email = 'gestion-p3-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $em = $this->em();
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Gestion')->setLastname('P3')->setRoles(['ROLE_ADMIN'])->setIsVerified(true)->setPassword('inutilisable');
        $em->persist($user);
        $em->flush();
        $this->userId = (int) $user->getId();
        $this->token = static::getContainer()->get('lexik_jwt_authentication.jwt_manager')->create($user);
        $this->endOfWrite();
    }
}
