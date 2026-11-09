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

    public function testSupplierCurrencyFlowsToOrdersDashboardsAndExport(): void
    {
        $tag = bin2hex(random_bytes(2));
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
        $this->request('POST', "/api/collections/$collectionId/commandes", ['budget' => 100000, 'date' => '2026-05-05 10:00:00', 'name' => 'Sans taux', 'fournisseur' => ['id' => $supplier['id']]]);
        $this->assertSame([422, ['exchangeRate' => 'EXCHANGE_RATE_REQUIRED']], [$this->client->getResponse()->getStatusCode(), $this->body()['fields']]);
        $commande = $this->post("/api/collections/$collectionId/commandes", ['budget' => 100000, 'date' => '2026-05-05 10:00:00', 'name' => "Import $tag", 'fournisseur' => ['id' => $supplier['id']], 'exchangeRate' => 1.35], 201);
        $commandeId = (int) $commande['id'];
        $this->assertSame(['USD', 'CAD', 1.35, 100000], [$commande['currency'], $commande['siteCurrency'], $commande['exchangeRate'], $commande['budget']]);
        $localOrder = $this->post("/api/collections/$collectionId/commandes", ['budget' => 50000, 'date' => '2026-05-06 10:00:00', 'name' => "Local $tag", 'fournisseur' => ['id' => $local['id']], 'exchangeRate' => 9.9], 201);
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
        $this->request('GET', '/api/gestion/exports/achats.csv?from=2026-05-01&to=2026-05-31&collectionId=' . $collectionId);
        $lines = array_values(array_filter(explode("\r\n", substr((string) $this->client->getResponse()->getContent(), 3))));
        $this->assertStringEndsWith(';"Devise d\'achat";Taux', $lines[0]);
        $this->assertStringContainsString("\"Import $tag\";2026-05-05;\"Importateur $tag\";\"Produit devise $tag\";2;28,00;56,00;2;;USD;1,4", $lines[1], 'coût 2000 USD × 1,4 = 28,00 CAD');
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
