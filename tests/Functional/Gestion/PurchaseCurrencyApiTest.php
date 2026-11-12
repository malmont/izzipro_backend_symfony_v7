<?php

namespace App\Tests\Functional\Gestion;

use App\Entity\Collections;
use App\Entity\Product;
use App\Entity\Transporteur;
use App\Entity\TypeFournisseur;
use App\Entity\User;
use App\EventSubscriber\CacheInvalidationSubscriber;
use App\Services\TenantEntityManagerProvider;
use App\Tests\Functional\Cache\CacheTestEntityFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Devise d'achat par fournisseur (lot G6, 10/11/2026, `docs/gestion-mise-a-niveau.md`) : devise du fournisseur figée sur ses
 * commandes, taux vers la devise du site obligatoire, montants de la commande en devise d'achat, tableaux de bord et export
 * convertis dans la devise du site, PATCH /api/commandes/{id}, rattachement sans prix d'achat refusé en devise étrangère.
 * Le site de test est en CAD.
 */
class PurchaseCurrencyApiTest extends WebTestCase
{
    use CacheTestEntityFactory;

    private KernelBrowser $client;
    private string $token = '';

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->login();
    }

    /**
     * Lot B3 bis (12/11/2026) : GET /api/collections/{id}/frais-de-port rend en une lecture les commandes de la période avec leur frais
     * de port (null sans frais) et les totaux. Le frais de port porte désormais la devise de sa commande (`currency`), la devise du
     * site (`siteCurrency`) et son prix converti (`priceConverted`) : avant, `currency` était la devise du site même pour une commande
     * en devise étrangère.
     */
    public function testShippingCostsOfAPeriodInOneRead(): void
    {
        $tag = bin2hex(random_bytes(2));
        $today = static::getContainer()->get(\App\Gestion\Services\PeriodCalendar::class)->today();
        $type = $this->create(TypeFournisseur::class, ['name' => 'Type port ' . $tag]);
        $carrierId = $this->create(Transporteur::class, ['name' => 'Fret ' . $tag])->getId();
        $this->endOfWrite();
        $usd = $this->post('/api/fournisseurs', ['name' => "Importateur $tag", 'typeFournisseur' => $type->getId(), 'currency' => 'USD'], 201);
        $local = $this->post('/api/fournisseurs', ['name' => "Local $tag", 'typeFournisseur' => $type->getId()], 201);
        $collectionId = (int) $this->post('/api/createcollections', ['budgetCollection' => 1000000, 'nomCollection' => "Port $tag"], 201)['id'];
        $order = fn (string $name, array $supplier, array $more = []): int => (int) $this->post("/api/collections/$collectionId/commandes", ['budget' => 100000, 'date' => $today->format('Y-m-d') . ' 10:00:00', 'name' => $name, 'fournisseur' => ['id' => $supplier['id']]] + $more, 201)['id'];
        $import = $order("Import $tag", $usd, ['exchangeRate' => 1.35]);
        $bought = $order("Achat local $tag", $local);
        $bare = $order("Sans port $tag", $local);
        $route = "/api/collections/$collectionId/frais-de-port";

        // Aucun frais de port : les trois commandes, dans l'ordre de la liste des commandes, sans frais ; totaux à zéro
        $empty = $this->get($route);
        $this->assertSame(['currency', 'siteCurrency', 'commandes', 'totals'], array_keys($empty));
        $this->assertSame(['CAD', 'CAD', ['commandes' => 3, 'withShipping' => 0, 'amount' => 0]], [$empty['currency'], $empty['siteCurrency'], $empty['totals']]);
        $this->assertSame(array_column($this->get("/api/collections/$collectionId/commandes"), 'id'), array_column($empty['commandes'], 'commandeId'));
        $this->assertSame([null, null, null], array_column($empty['commandes'], 'fraisDePort'));

        // Frais de port d'une commande en USD : devise de la commande, devise du site, prix converti (10000 × 1,35)
        $created = $this->post("/api/commandes/$import/frais-de-port", ['name' => 'Fret maritime', 'facture' => "FR-$tag", 'tracknumber' => "TR-$tag", 'price' => 10000, 'transporteur' => ['id' => $carrierId]], 201);
        $this->assertSame(['USD', 'CAD', 10000, 13500], [$created['currency'], $created['siteCurrency'], $created['price'], $created['priceConverted']]);
        $single = $this->get("/api/commandes/$import/frais-de-port");
        $this->assertSame($created, $single, 'la lecture d\'une commande rend le même objet');
        $localShipping = $this->post("/api/commandes/$bought/frais-de-port", ['name' => 'Livraison', 'price' => 2550, 'transporteur' => ['id' => $carrierId]], 201);
        $this->assertSame(['CAD', 'CAD', 2550, 2550], [$localShipping['currency'], $localShipping['siteCurrency'], $localShipping['price'], $localShipping['priceConverted']], 'commande dans la devise du site');

        // La période, en une lecture (le cache a été vidé par les écritures)
        $read = $this->get($route);
        $byId = array_column($read['commandes'], null, 'commandeId');
        $this->assertSame(['commandeId', 'name', 'date', 'kind', 'currency', 'siteCurrency', 'exchangeRate', 'fournisseur', 'fraisDePort'], array_keys($byId[$import]));
        $this->assertSame(
            ["Import $tag", $today->format('Y-m-d') . ' 10:00:00', 'standard', 'USD', 'CAD', 1.35, ['id' => $usd['id'], 'name' => "Importateur $tag"]],
            array_values(array_diff_key($byId[$import], ['commandeId' => 0, 'fraisDePort' => 0]))
        );
        $this->assertSame($single, $byId[$import]['fraisDePort'], 'même forme que la lecture d\'une commande');
        $this->assertSame(['Fret maritime', "FR-$tag", "TR-$tag", $carrierId, 'Fret ' . $tag], [$single['name'], $single['facture'], $single['tracknumber'], $single['transporteur']['id'], $single['transporteur']['name']]);
        $this->assertSame(['CAD', 'CAD', null, ['id' => $local['id'], 'name' => "Local $tag"], 2550], [$byId[$bought]['currency'], $byId[$bought]['siteCurrency'], $byId[$bought]['exchangeRate'], $byId[$bought]['fournisseur'], $byId[$bought]['fraisDePort']['priceConverted']]);
        $this->assertNull($byId[$bare]['fraisDePort'], 'commande sans frais de port : présente, frais null');
        $this->assertSame(['commandes' => 3, 'withShipping' => 2, 'amount' => 16050], $read['totals'], '13500 + 2550, dans la devise du site');
        $this->assertSame($read['totals']['amount'], $this->get("/api/dashboard/collection/$collectionId")['GeneralExpenses']['totalShippingCost'], 'le total du tableau de bord de la période');

        // Les écritures vident la lecture : nouveau taux, puis suppression d'un frais de port
        $this->request('PATCH', "/api/commandes/$import", ['exchangeRate' => 1.4]);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
        $read = $this->get($route);
        $this->assertSame([14000, 16550], [array_column($read['commandes'], null, 'commandeId')[$import]['fraisDePort']['priceConverted'], $read['totals']['amount']]);
        $this->request('DELETE', "/api/commandes/$bought/frais-de-port");
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['commandes' => 3, 'withShipping' => 1, 'amount' => 14000], $this->get($route)['totals']);

        // Une autre période a sa propre lecture
        $otherId = (int) $this->post('/api/createcollections', ['budgetCollection' => 100, 'nomCollection' => "Port vide $tag"], 201)['id'];
        $this->assertSame([[], ['commandes' => 0, 'withShipping' => 0, 'amount' => 0]], [$this->get("/api/collections/$otherId/frais-de-port")['commandes'], $this->get("/api/collections/$otherId/frais-de-port")['totals']]);

        // Refus : période inconnue, appel sans connexion
        $this->request('GET', '/api/collections/999999/frais-de-port');
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
        $this->client->request('GET', $route, [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'HTTP_ACCEPT' => 'application/json']);
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
    }

    public function testSupplierCurrencyFlowsToOrdersDashboardsAndExport(): void
    {
        $tag = bin2hex(random_bytes(2));
        $today = static::getContainer()->get(\App\Gestion\Services\PeriodCalendar::class)->today(); // dates dans la période ouverte (lot P1)
        $type = $this->create(TypeFournisseur::class, ['name' => 'Type devise ' . $tag]);
        $this->endOfWrite();

        // Fournisseur en USD ; devise inconnue refusée
        $this->request('POST', '/api/fournisseurs', ['name' => "Importateur $tag", 'typeFournisseur' => $type->getId(), 'currency' => 'XXX']);
        $this->assertSame([422, ['currency' => 'INVALID_CURRENCY']], [$this->client->getResponse()->getStatusCode(), $this->body()['fields']]);
        $supplier = $this->post('/api/fournisseurs', ['name' => "Importateur $tag", 'typeFournisseur' => $type->getId(), 'currency' => 'usd'], 201);
        $this->assertSame(['USD', 'USD'], [$supplier['purchaseCurrency'], $supplier['currency']], 'devise normalisée en majuscules');
        $local = $this->post('/api/fournisseurs', ['name' => "Local $tag", 'typeFournisseur' => $type->getId()], 201);
        $this->assertSame([null, 'CAD'], [$local['purchaseCurrency'], $local['currency']], 'sans devise : celle du site');

        $collection = $this->post('/api/createcollections', ['budgetCollection' => 1000000, 'nomCollection' => "Devise $tag"], 201);
        $collectionId = (int) $collection['id'];

        // Commande chez le fournisseur USD : taux obligatoire ; budget en USD
        $this->request('POST', "/api/collections/$collectionId/commandes", ['budget' => 100000, 'date' => $today->format('Y-m-d') . ' 10:00:00', 'name' => 'Sans taux', 'fournisseur' => ['id' => $supplier['id']]]);
        $this->assertSame([422, ['exchangeRate' => 'EXCHANGE_RATE_REQUIRED']], [$this->client->getResponse()->getStatusCode(), $this->body()['fields']]);
        $commande = $this->post("/api/collections/$collectionId/commandes", ['budget' => 100000, 'date' => $today->format('Y-m-d') . ' 10:00:00', 'name' => "Import $tag", 'fournisseur' => ['id' => $supplier['id']], 'exchangeRate' => 1.35], 201);
        $commandeId = (int) $commande['id'];
        $this->assertSame(['USD', 'CAD', 1.35, 100000], [$commande['currency'], $commande['siteCurrency'], $commande['exchangeRate'], $commande['budget']]);
        $localOrder = $this->post("/api/collections/$collectionId/commandes", ['budget' => 50000, 'date' => $today->format('Y-m-d') . ' 11:00:00', 'name' => "Local $tag", 'fournisseur' => ['id' => $local['id']], 'exchangeRate' => 9.9], 201);
        $this->assertSame(['CAD', null], [$localOrder['currency'], $localOrder['exchangeRate']], 'fournisseur sans devise : taux ignoré, devise du site');

        // Rattachement d'un produit existant : prix d'achat requis en devise étrangère, puis coût 2000 USD × 2
        $em = $this->em();
        $em->getConnection()->executeStatement("INSERT INTO product (id, name, price, isbestseller, quantity, created_at, slug, product_type, coefficient_multiplier) VALUES (nextval('product_id_seq'), :n, 8000, false, 2, NOW(), :s, 'standard', 2)", ['n' => 'Produit devise ' . $tag, 's' => 'devise-' . $tag]);
        $productId = (int) $em->getConnection()->fetchOne('SELECT id FROM product WHERE slug = :s', ['s' => 'devise-' . $tag]);
        $this->endOfWrite();
        $this->request('POST', "/api/commandes/$commandeId/products/rattacher", ['products' => [['productId' => $productId]]]);
        $this->assertSame(422, $this->client->getResponse()->getStatusCode(), 'sans purchasePrice en USD : refusé');
        $this->assertStringContainsString('purchasePrice', (string) $this->client->getResponse()->getContent());
        $this->request('POST', "/api/commandes/$commandeId/products/rattacher", ['products' => [['productId' => $productId, 'purchasePrice' => 2000, 'quantity' => 2]]]);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), substr((string) $this->client->getResponse()->getContent(), 0, 300));
        $this->post("/api/commandes/$commandeId/frais-de-port", ['name' => 'Fret', 'price' => 10000, 'transporteur' => ['id' => $this->create(Transporteur::class, ['name' => 'Fret ' . $tag])->getId()]], 201);
        $this->endOfWrite();

        // Tableau de bord en CAD : coût 2000 × 2 × 1,35 = 5400 ; fret 10000 × 1,35 = 13500 ; budget 100000 × 1,35 = 135000
        $dashboard = $this->get("/api/dashboard/commande/$commandeId");
        $this->assertSame(['CAD', 5400, 13500, 18900, 135000], [$dashboard['currency'], $dashboard['purchaseCost'], $dashboard['totalFraisDePort'], $dashboard['BudgetGeneral']['usedBudget'], $dashboard['BudgetGeneral']['generalBudget']]);
        $this->assertSame(['USD', 1.35, 100000, 4000, 10000], [$dashboard['purchase']['currency'], $dashboard['purchase']['exchangeRate'], $dashboard['purchase']['generalBudget'], $dashboard['purchase']['purchaseCost'], $dashboard['purchase']['shippingCost']], 'montants d\'origine en USD');
        $this->assertSame(10800, $dashboard['ValeurStock']['stockValue'], 'coût converti × quantité × coefficient (5400 × 2)');
        $collectionDashboard = $this->get("/api/dashboard/collection/$collectionId");
        $this->assertSame([5400, 13500], [$collectionDashboard['purchaseCost'], $collectionDashboard['GeneralExpenses']['totalShippingCost']], 'collection : convertie');

        // PATCH : nouveau taux, puis commande dans la devise du site sans taux possible
        $this->request('PATCH', "/api/commandes/$commandeId", ['exchangeRate' => 1.4, 'budget' => 120000]);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
        $this->assertSame([1.4, 120000], [$this->body()['exchangeRate'], $this->body()['budget']]);
        $this->assertSame(5600, $this->get("/api/dashboard/commande/$commandeId")['purchaseCost'], 'nouveau taux appliqué (2000 × 2 × 1,4)');
        $this->request('PATCH', '/api/commandes/' . $localOrder['id'], ['exchangeRate' => 1.2]);
        $this->assertSame([422, ['exchangeRate' => 'NOT_APPLICABLE']], [$this->client->getResponse()->getStatusCode(), $this->body()['fields']]);
        $this->request('PATCH', "/api/commandes/$commandeId", ['exchangeRate' => 0]);
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());

        // Export : montants en CAD, devise d'achat et taux en fin de ligne
        $this->request('GET', '/api/gestion/exports/achats.csv?from=' . $today->format('Y-m-01') . '&to=' . $today->format('Y-m-t') . '&collectionId=' . $collectionId);
        $lines = array_values(array_filter(explode("\r\n", substr((string) $this->client->getResponse()->getContent(), 3))));
        $this->assertStringEndsWith(';"Devise d\'achat";Taux', $lines[0]);
        $this->assertStringContainsString("\"Import $tag\";" . $today->format('Y-m-d') . ";\"Importateur $tag\";\"Produit devise $tag\";2;28,00;56,00;2;;USD;1,4", $lines[1], 'coût 2000 USD × 1,4 = 28,00 CAD');
        $this->assertStringContainsString(';USD;1,4', $lines[2]);

        // Clôture : chiffres figés convertis ; rattachement refusé ensuite
        $this->request('POST', "/api/dashboard/commande/$commandeId/close");
        $this->assertSame(5600, $this->body()['purchaseCost']);
        $this->request('PATCH', "/api/commandes/$commandeId", ['budget' => 1]);
        $this->assertSame([409, 'COMMANDE_CLOSED'], [$this->client->getResponse()->getStatusCode(), $this->body()['error']]);
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
        $email = 'gestion-g6-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $em = $this->em();
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Gestion')->setLastname('G6')->setRoles(['ROLE_ADMIN'])->setIsVerified(true)->setPassword('inutilisable');
        $em->persist($user);
        $em->flush();
        $this->token = static::getContainer()->get('lexik_jwt_authentication.jwt_manager')->create($user);
        $this->endOfWrite();
    }
}
