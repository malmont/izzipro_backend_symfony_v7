<?php

namespace App\Tests\Functional\Gestion;

use App\Entity\Collections;
use App\Entity\Product;
use App\Entity\Transporteur;
use App\Entity\TypeFournisseur;
use App\Entity\NoteDeFrais;
use App\Entity\TypeNoteDeFrais;
use App\Entity\User;
use App\EventSubscriber\CacheInvalidationSubscriber;
use App\Services\TenantEntityManagerProvider;
use App\Tests\Functional\Cache\CacheTestEntityFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Contrat des routes d'achats de l'application de gestion (lots G0 et G1, 09/11/2026, `docs/gestion-mise-a-niveau.md`) :
 * parcours complet collection → fournisseur → commande → produit → frais de port → notes de frais → tableaux de bord →
 * clôtures. Chaque lot suivant (G2 à G4) remplace ici les valeurs attendues qu'il change, et rien d'autre ne doit bouger.
 *
 * État fixé par G1 : tous les montants en cents entiers (entrée et sortie), `currency` du site sur chaque réponse portant un
 * montant, 422 VALIDATION_FAILED à l'entrée ; tableaux de bord au coût d'achat (`Product::commandeUnitCost()`, lot
 * « rattachement ») calculés par agrégats SQL (G2 : taux arrondis, purchaseCost, clôture 200 objet / 409) ; contrat HTTP
 * (G4) : 201 avec l'objet créé, 204 sans corps, 200 null sans frais de port, 404 / 409 à code, propriétaire = jeton,
 * suppression logique des collections, produits détachés à la suppression d'une commande, rôles administrateur.
 */
class PurchasesApiTest extends WebTestCase
{
    use CacheTestEntityFactory;

    private const PASSWORD = 'Gestion-test-2026!';

    private KernelBrowser $client;
    private int $userId = 0;
    private string $token = '';

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->login(['ROLE_ADMIN']);
    }

    public function testCollectionCommandeProductsShippingAndDashboards(): void
    {
        // Référentiels créés pour le test (le modèle de la base de test peut ne pas en avoir)
        $typeFournisseur = $this->create(TypeFournisseur::class, ['name' => 'Grossiste test']);
        $typeNote = $this->create(TypeNoteDeFrais::class, ['name' => 'Déplacement test']);
        $transporteur = $this->create(Transporteur::class, ['name' => 'Transporteur test']);
        $this->endOfWrite();

        // Collection : 201 avec l'objet créé (lot G4) ; propriétaire = utilisateur du jeton (le userId du corps est ignoré)
        $name = 'Collection G0 ' . bin2hex(random_bytes(2));
        $collection = $this->post('/api/createcollections', [
            'budgetCollection' => 1000000.0, 'startDateCollection' => '2026-11-01 00:00:00', 'endDateCollection' => '2026-11-30 00:00:00',
            'nomCollection' => $name, 'userId' => 999999,
        ], 201);
        $collectionId = (int) $collection['id'];
        $this->assertSame([1000000, $name, false, false, 'CAD'], [$collection['budgetCollection'], $collection['nomCollection'], $collection['isClosed'], $collection['del'], $collection['currency']], 'un nombre JSON intégral (1000000.0) vaut 1000000 cents');
        $this->assertSame($this->userId, $collection['user']['id'] ?? null, 'propriétaire = utilisateur du jeton, pas le userId du corps');
        $this->assertSame($collection, $this->collectionByName($name), 'la réponse 201 a la forme de la liste');

        // Fournisseur : 201 avec l'objet créé (lot G4) ; type inconnu → 404
        $this->request('POST', '/api/fournisseurs', ['name' => 'Sans type', 'typeFournisseur' => 999999]);
        $this->assertSame([404, 'TYPE_FOURNISSEUR_NOT_FOUND'], [$this->client->getResponse()->getStatusCode(), json_decode((string) $this->client->getResponse()->getContent(), true)['error']]);
        $fournisseur = $this->post('/api/fournisseurs', ['name' => 'Fournisseur G0', 'typeFournisseur' => $typeFournisseur->getId(), 'ville' => 'Montréal', 'pays' => 'Canada', 'tel' => '514'], 201);
        $fournisseurId = (int) $fournisseur['id'];
        $this->assertSame(['Fournisseur G0', 'Montréal'], [$fournisseur['name'], $fournisseur['ville']]);
        $fournisseurs = $this->get('/api/fournisseurs');
        $this->assertContains($fournisseurId, array_column($fournisseurs, 'id'));

        // Commande fournisseur : 201 avec l'objet créé (lot G4) ; fournisseur inconnu → 404
        $this->request('POST', "/api/collections/$collectionId/commandes", ['budget' => 1, 'date' => '2026-11-05 10:00:00', 'name' => 'Inconnu', 'fournisseur' => ['id' => 999999]]);
        $this->assertSame([404, 'FOURNISSEUR_NOT_FOUND'], [$this->client->getResponse()->getStatusCode(), json_decode((string) $this->client->getResponse()->getContent(), true)['error']]);
        $created = $this->post("/api/collections/$collectionId/commandes", ['budget' => 500000, 'date' => '2026-11-05 10:00:00', 'name' => 'Commande G0', 'fournisseur' => ['id' => $fournisseurId]], 201);
        $commandes = $this->get("/api/collections/$collectionId/commandes");
        $this->assertCount(1, $commandes);
        $commande = $commandes[0];
        $commandeId = (int) $commande['id'];
        $this->assertSame($commande, $created, 'la réponse 201 a la forme de la liste');
        $this->assertSame([500000, 'Commande G0', $collectionId, $fournisseurId, 'CAD'], [$commande['budget'], $commande['name'], $commande['collectionId'], $commande['fournisseur']['id'], $commande['currency']]);

        // Produit de la commande (multipart) : prix d'achat en CENTS, prix de vente dérivé = achat × coefficient
        $this->client->request('POST', "/api/commandes/$commandeId/products", ['name' => 'Sans style', 'description' => 'd', 'purchasePrice' => '1', 'coefficientMultiplier' => '2', 'style_id' => '999999'], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $this->token]);
        $this->assertSame([404, 'STYLE_NOT_FOUND'], [$this->client->getResponse()->getStatusCode(), json_decode((string) $this->client->getResponse()->getContent(), true)['error']], 'style inconnu → 404 à code (lot G4, avant : 500)');
        // quantité facultative à la création (lot G4) ; sinon elle vient des variantes (rappels Doctrine de ProductVariant)
        $product = $this->postMultipart("/api/commandes/$commandeId/products", ['name' => 'Produit G0', 'description' => 'Description', 'purchasePrice' => '1000', 'coefficientMultiplier' => '2', 'quantity' => '3'], 201);
        $productId = (int) $product['id'];
        $this->assertSame([1000.0, 2.0], array_map('floatval', [$product['purchasePrice'], $product['coefficientMultiplier']]), 'création : ProductOutputDTO (sans price)');

        $products = $this->get("/api/commandes/$commandeId/products");
        $this->assertSame([$productId], array_column($products, 'id'));
        $this->assertSame([3, 2000.0, 1000.0], [$products[0]['quantity'], (float) $products[0]['price'], (float) $products[0]['purchasePrice']], 'lecture : ProductDetailedOutputDTO, price = purchasePrice × coefficient');

        // Frais de port : un seul par commande ; prix en unités monétaires (état avant G1) ; 201 {"success"}
        $this->assertNull($this->getRaw("/api/commandes/$commandeId/frais-de-port"), 'commande sans frais de port : 200 null (lot G4, avant : 404)');
        $this->request('POST', "/api/commandes/$commandeId/frais-de-port", ['name' => 'X', 'price' => 1, 'transporteur' => ['id' => 999999]]);
        $this->assertSame([404, 'TRANSPORTEUR_NOT_FOUND'], [$this->client->getResponse()->getStatusCode(), json_decode((string) $this->client->getResponse()->getContent(), true)['error']]);
        $createdShipping = $this->post("/api/commandes/$commandeId/frais-de-port", ['name' => 'Expédition', 'facture' => 'F-1', 'tracknumber' => 'T-1', 'price' => 15000, 'transporteur' => ['id' => $transporteur->getId()]], 201);
        $shipping = $this->get("/api/commandes/$commandeId/frais-de-port");
        $this->assertSame([15000, 'Expédition', $transporteur->getId(), 'CAD'], [$shipping['price'], $shipping['name'], $shipping['transporteur']['id'], $shipping['currency']]);
        $this->assertSame($shipping, $createdShipping, 'la réponse 201 a la forme de la lecture');
        $this->request('POST', "/api/commandes/$commandeId/frais-de-port", ['name' => 'Second', 'price' => 1, 'transporteur' => ['id' => $transporteur->getId()]]);
        $this->assertSame([409, 'SHIPPING_COST_ALREADY_SET'], [$this->client->getResponse()->getStatusCode(), json_decode((string) $this->client->getResponse()->getContent(), true)['error']], 'un seul frais de port par commande (avant : 500)');
        $this->request('DELETE', '/api/transporteurs/' . $transporteur->getId());
        $this->assertSame([409, 'TRANSPORTEUR_IN_USE'], [$this->client->getResponse()->getStatusCode(), json_decode((string) $this->client->getResponse()->getContent(), true)['error']], 'transporteur utilisé par un frais de port (avant : violation de clé)');

        // Tableau de bord de la commande : chiffres actuels (prix de vente × quantité, coefficient réappliqué)
        $dashboard = $this->get("/api/dashboard/commande/$commandeId");
        // Règle du 09/11/2026 (lot « rattachement », posflutter backend) : coût d'achat = commande_cost_cents ?? purchase_price ?? price
        $this->assertSame([3000, 3000], [$dashboard['totalItemCost'], $dashboard['purchaseCost']], 'Σ coût d\'achat × quantité (1000 × 3), entier en cents');
        $this->assertSame([15000, 'CAD'], [$dashboard['totalFraisDePort'], $dashboard['currency']]);
        $this->assertSame([500000, 18000, 482000], [$dashboard['BudgetGeneral']['generalBudget'], $dashboard['BudgetGeneral']['usedBudget'], $dashboard['BudgetGeneral']['remainingBudget']], 'tout en cents : coût + frais de port');
        $this->assertSame([3, 1], [$dashboard['Statistics']['itemCount'], $dashboard['Statistics']['modelCount']]);
        $this->assertSame(2.0, (float) $dashboard['averageMultiplier']);
        $this->assertSame([6000, -12000], [$dashboard['ValeurStock']['stockValue'], $dashboard['ValeurStock']['marge']], 'stockValue = totalItemCost × coefficient moyen');
        $this->assertSame([-66.67, -200.0], [(float) $dashboard['TauxMarge']['tauxMarge'], (float) $dashboard['TauxMarge']['tauxMarque']], 'taux de marge = marge / coût, taux de marque = marge / valeur de vente, en %, 2 décimales (lots G2 et P1)');
        $this->assertSame('Transporteur test', $dashboard['Transporteur']['name']);

        // Notes de frais de la collection : montant en unités monétaires (état avant G1)
        $this->request('POST', "/api/collections/$collectionId/notes-de-frais", ['description' => 'X', 'montant' => 1, 'date' => '2026-11-06', 'typeNoteDeFraisId' => 999999]);
        $this->assertSame([404, 'TYPE_NOTE_DE_FRAIS_NOT_FOUND'], [$this->client->getResponse()->getStatusCode(), json_decode((string) $this->client->getResponse()->getContent(), true)['error']]);
        $createdNote = $this->post("/api/collections/$collectionId/notes-de-frais", ['description' => 'Essence', 'montant' => 8000, 'date' => '2026-11-06', 'typeNoteDeFraisId' => $typeNote->getId()], 201);
        $notes = $this->get("/api/collections/$collectionId/notes-de-frais");
        $this->assertCount(1, $notes);
        $noteId = (int) $notes[0]['id'];
        $this->assertSame([8000, 'Essence', '2026-11-06', 'CAD'], [$notes[0]['montant'], $notes[0]['description'], $notes[0]['date'], $notes[0]['currency']]);
        $this->assertSame($notes[0], $createdNote, 'la réponse 201 a la forme de la liste (lot G4)');
        $this->assertSame($typeNote->getId(), $notes[0]['typeNoteDeFraisId'], 'type de la note renvoyé, pour préremplir le PUT (12/11/2026)');

        $this->request('PUT', "/api/notes-de-frais/$noteId", ['description' => 'Essence', 'montant' => 9000, 'date' => '2026-11-06', 'typeNoteDeFraisId' => $typeNote->getId()]);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertSame(9000, json_decode((string) $this->client->getResponse()->getContent(), true)['montant'], 'PUT : 200 avec l\'objet (lot G4)');
        $this->assertSame($typeNote->getId(), json_decode((string) $this->client->getResponse()->getContent(), true)['typeNoteDeFraisId']);
        $this->assertSame(9000, $this->get("/api/collections/$collectionId/notes-de-frais")[0]['montant']);
        // Note sans type (colonne nullable) : null
        $em = $this->em();
        $em->find(NoteDeFrais::class, $noteId)->setTypeNoteDeFrais(null);
        $em->flush();
        $this->assertArrayHasKey('typeNoteDeFraisId', $listed = $this->get("/api/collections/$collectionId/notes-de-frais")[0]);
        $this->assertNull($listed['typeNoteDeFraisId'], 'sans type : null');
        $em = $this->em();
        $em->find(NoteDeFrais::class, $noteId)->setTypeNoteDeFrais($em->find(TypeNoteDeFrais::class, $typeNote->getId()));
        $em->flush();

        // Tableau de bord de la collection : produits + frais de port + notes de frais, durée
        $dashboard = $this->get("/api/dashboard/collection/$collectionId");
        $this->assertSame([3000, 15000, 9000, 24000, 'CAD'], [$dashboard['totalItemCost'], $dashboard['GeneralExpenses']['totalShippingCost'], $dashboard['GeneralExpenses']['totalExpenseCost'], $dashboard['GeneralExpenses']['totalGeneralExpenses'], $dashboard['currency']]);
        $this->assertSame([1000000, 27000, 973000], [$dashboard['BudgetGeneral']['generalBudget'], $dashboard['BudgetGeneral']['usedBudget'], $dashboard['BudgetGeneral']['remainingBudget']]);
        $this->assertSame([1, 3, 1], [$dashboard['Statistics']['orderCount'], $dashboard['Statistics']['itemCount'], $dashboard['Statistics']['modelCount']]);
        $this->assertSame([6000, 3000], [$dashboard['ValeurStock']['stockValue'], $dashboard['purchaseCost']], 'Σ coût × quantité × coefficient');
        $this->assertSame(['2026-11-01', '2026-11-30', 29], [$dashboard['CollectionDuration']['startDate'], $dashboard['CollectionDuration']['endDate'], $dashboard['CollectionDuration']['days']]);

        $combined = $this->get('/api/dashboard/collections-combined');
        $this->assertGreaterThanOrEqual(3000, $combined['totalItemCost'], 'le combiné additionne toutes les collections');
        $this->assertSame('CAD', $combined['currency']);

        // Suppression du frais de port : 204 sans corps, puis 404 à la lecture (état avant G4)
        $this->request('DELETE', "/api/commandes/$commandeId/frais-de-port");
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertSame('', (string) $this->client->getResponse()->getContent());
        $this->assertNull($this->getRaw("/api/commandes/$commandeId/frais-de-port"), 'après suppression : 200 null');
        $this->assertSame([$commandeId], array_column($this->get("/api/collections/$collectionId/commandes"), 'id'), 'la commande survit à la suppression de son frais de port (FraisDePort::commande ne doit pas porter cascade remove)');
        $this->assertSame([$productId], array_column($this->get("/api/commandes/$commandeId/products"), 'id'), 'ses produits aussi');

        // Suppression de la note de frais : 204
        $this->request('DELETE', "/api/notes-de-frais/$noteId");
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $this->get("/api/collections/$collectionId/notes-de-frais"));

        // Clôture de la commande (lot G2) : 200 avec le tableau de bord figé, puis 409 ALREADY_CLOSED
        $this->request('POST', "/api/dashboard/commande/$commandeId/close");
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), 'close commande : ' . substr((string) $this->client->getResponse()->getContent(), 0, 400));
        $closed = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame([3000, 0, 'CAD'], [$closed['purchaseCost'], $closed['totalFraisDePort'], $closed['currency']], 'la clôture rend le tableau de bord figé');
        $frozen = $this->get("/api/dashboard/commande/$commandeId");
        $this->assertSame([3000, 0, 3000, 'CAD'], [$frozen['totalItemCost'], $frozen['totalFraisDePort'], $frozen['BudgetGeneral']['usedBudget'], $frozen['currency']], 'figé après la suppression du frais de port');
        $this->request('POST', "/api/dashboard/commande/$commandeId/close");
        $this->assertSame(409, $this->client->getResponse()->getStatusCode());
        $this->assertSame('ALREADY_CLOSED', json_decode((string) $this->client->getResponse()->getContent(), true)['error']);

        // Clôture de la collection
        $this->request('POST', "/api/dashboard/collection/$collectionId/close");
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
        $closed = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame([3000, 'CAD'], [$closed['BudgetGeneral']['usedBudget'], $closed['currency']]);
        $frozen = $this->get("/api/dashboard/collection/$collectionId");
        $this->assertSame([3000, 3000], [$frozen['BudgetGeneral']['usedBudget'], $frozen['purchaseCost']]);
        $this->assertTrue($this->collectionById($collectionId)['isClosed']);
        $this->request('POST', "/api/dashboard/collection/$collectionId/close");
        $this->assertSame(409, $this->client->getResponse()->getStatusCode());
        $this->assertSame('ALREADY_CLOSED', json_decode((string) $this->client->getResponse()->getContent(), true)['error']);

        // Lot G4 : fournisseur et transporteur utilisés → 409 ; commande supprimée → produit détaché, pas supprimé ; collection supprimée logiquement
        $this->request('DELETE', "/api/fournisseurs/$fournisseurId");
        $this->assertSame([409, 'FOURNISSEUR_IN_USE'], [$this->client->getResponse()->getStatusCode(), json_decode((string) $this->client->getResponse()->getContent(), true)['error']]);
        $this->request('DELETE', '/api/transporteurs/' . $transporteur->getId());
        $this->assertSame([204, ''], [$this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent()], 'plus utilisé (frais de port supprimé avant la clôture) : 204 sans corps');
        $this->request('DELETE', "/api/commandes/$commandeId");
        $this->assertSame(409, $this->client->getResponse()->getStatusCode(), 'commande figée : non supprimable (lot P1)');
        $this->request('POST', "/api/dashboard/collection/$collectionId/reopen");
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), 'réouverture manuelle de la collection (lot P1) : ' . substr((string) $this->client->getResponse()->getContent(), 0, 200));
        $this->request('DELETE', "/api/commandes/$commandeId");
        $this->assertSame(204, $this->client->getResponse()->getStatusCode(), 'route dédiée (lot P1) : ' . substr((string) $this->client->getResponse()->getContent(), 0, 200));
        $this->endOfWrite();
        $product = $this->em()->find(Product::class, $productId);
        $this->assertNotNull($product, 'le produit de la boutique survit à la commande');
        $this->assertNull($product->getCommande(), 'détaché (FK ON DELETE SET NULL)');
        $this->assertSame([], $this->get("/api/collections/$collectionId/commandes"));
        $this->request('DELETE', "/api/collections/$collectionId");
        $this->assertSame([204, ''], [$this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent()]);
        $this->assertNotContains($collectionId, array_column($this->get('/api/collections'), 'id'), 'suppression logique : plus listée');
        $this->assertTrue($this->em()->find(Collections::class, $collectionId)->isDel(), 'toujours en base, del = true');
        $this->request('DELETE', "/api/products/$productId");
        $this->assertSame([204, ''], [$this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent()], 'DELETE produit : 204 sans corps (avant : 200)');
    }

    public function testShippingCostNotFoundAndInvalidJson(): void
    {
        $collection = $this->post('/api/createcollections', ['budgetCollection' => 100, 'nomCollection' => 'Collection vide ' . bin2hex(random_bytes(2))], 201);
        $this->request('GET', '/api/commandes/999999/frais-de-port');
        $this->assertSame(404, $this->client->getResponse()->getStatusCode(), 'commande inconnue');

        $this->client->request('POST', '/api/collections/' . $collection['id'] . '/notes-de-frais', [], [], $this->headers(), 'pas du json');
        $this->assertSame(400, $this->client->getResponse()->getStatusCode());
        $this->assertSame('INVALID_JSON', json_decode((string) $this->client->getResponse()->getContent(), true)['error'] ?? null);
    }

    public function testAmountsMustBeIntegerCents(): void
    {
        $this->request('POST', '/api/createcollections', ['budgetCollection' => 12.5, 'nomCollection' => 'Refusée', 'userId' => $this->userId]);
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame(['VALIDATION_FAILED', ['budgetCollection' => 'MUST_BE_INTEGER_CENTS']], [$body['error'], $body['fields']]);

        $this->request('POST', '/api/createcollections', ['budgetCollection' => '12.50', 'userId' => $this->userId]);
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame([422, ['budgetCollection' => 'MUST_BE_INTEGER_CENTS', 'nomCollection' => 'REQUIRED']], [$this->client->getResponse()->getStatusCode(), $body['fields']], 'chaîne décimale refusée (une chaîne entière comme "1250" est acceptée : multipart), nom requis');

        $name = 'Collection 422 ' . bin2hex(random_bytes(2));
        $this->post('/api/createcollections', ['budgetCollection' => 100, 'nomCollection' => $name, 'userId' => $this->userId], 201);
        $collectionId = (int) $this->collectionByName($name)['id'];
        $this->request('POST', "/api/collections/$collectionId/commandes", ['budget' => -5, 'name' => 'Négatif', 'date' => 'pas une date', 'fournisseur' => ['id' => 'x']]);
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame([422, ['budget' => 'MUST_BE_INTEGER_CENTS', 'date' => 'INVALID_DATE', 'fournisseur' => 'INVALID_ID']], [$this->client->getResponse()->getStatusCode(), $body['fields']]);
        $this->request('POST', "/api/collections/$collectionId/notes-de-frais", ['montant' => 1.5, 'date' => '2026-11-06']);
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame([422, ['montant' => 'MUST_BE_INTEGER_CENTS', 'typeNoteDeFraisId' => 'REQUIRED']], [$this->client->getResponse()->getStatusCode(), $body['fields']]);
    }

    /**
     * Correctif du 12/11/2026 : la création d'un article dans une commande ne sort jamais en 500 pour un corps incomplet ou mal formé.
     * Obligatoires : `name`, `purchasePrice` et `coefficientMultiplier` (le prix de vente est leur produit) ; `description` est
     * facultative (avant : 500 sans elle) ; un champ de formulaire laissé vide vaut « absent » ; un champ mal formé donne 422
     * VALIDATION_FAILED avec son code ; l'image doit être une image (type lu dans le fichier).
     */
    public function testCreatingAnArticleInAnOrderNeverCrashesOnMissingEmptyOrMalformedFields(): void
    {
        $collection = $this->post('/api/createcollections', ['budgetCollection' => 100, 'nomCollection' => 'Collection DTO ' . bin2hex(random_bytes(2))], 201);
        $commande = $this->create(\App\Entity\Commande::class, [], fn (\App\Entity\Commande $c, EntityManagerInterface $em) => $c->setCollections($em->find(Collections::class, (int) $collection['id'])));
        $categoryA = $this->create(\App\Entity\Categories::class, ['name' => 'Catégorie DTO A ' . bin2hex(random_bytes(2))]);
        $categoryB = $this->create(\App\Entity\Categories::class, ['name' => 'Catégorie DTO B ' . bin2hex(random_bytes(2))]);
        $this->endOfWrite();
        $route = '/api/commandes/' . $commande->getId() . '/products';
        $count = fn (): int => (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM product WHERE commande_id = ?', [$commande->getId()]);
        $stored = fn (int $id): array => $this->em()->getConnection()->fetchAssociative('SELECT name, description, slug, image, style_id, purchase_price, price, (SELECT COUNT(*) FROM product_categories pc WHERE pc.product_id = p.id) AS categories FROM product p WHERE id = ?', [$id]);
        $refused = function (array $fields, array $files = []) use ($route, $count): array {
            $before = $count();
            $this->client->request('POST', $route, $fields, $files, ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $this->token]);
            $body = json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
            $this->assertSame($before, $count(), 'un refus ne crée rien');

            return [$this->client->getResponse()->getStatusCode(), $body['error'] ?? null, $body['fields'] ?? null];
        };

        // Sans description : 201, description null (le défaut signalé : 500)
        $price = ['purchasePrice' => '1000', 'coefficientMultiplier' => '2.5'];
        $bare = $this->postMultipart($route, ['name' => 'Article sans description'] + $price, 201);
        $row = $stored((int) $bare['id']);
        $this->assertSame(['Article sans description', null, 'article-sans-description', null, 1000.0, 2500.0, 0], [$row['name'], $row['description'], $row['slug'], $row['style_id'], (float) $row['purchase_price'], (float) $row['price'], (int) $row['categories']]);
        $this->assertNull($bare['description']);

        // Prix d'achat et coefficient : obligatoires, absents comme vides (avant : 500, le prix de vente restait nul)
        $this->assertSame([422, 'VALIDATION_FAILED', ['purchasePrice' => 'REQUIRED', 'coefficientMultiplier' => 'REQUIRED']], $refused(['name' => 'Sans prix']));
        $this->assertSame([422, 'VALIDATION_FAILED', ['purchasePrice' => 'REQUIRED', 'coefficientMultiplier' => 'REQUIRED']], $refused(['name' => 'Prix vides', 'purchasePrice' => '', 'coefficientMultiplier' => '']));
        $this->assertSame([422, 'VALIDATION_FAILED', ['coefficientMultiplier' => 'REQUIRED']], $refused(['name' => 'Sans coefficient', 'purchasePrice' => '1000']));
        $free = $stored((int) $this->postMultipart($route, ['name' => 'Gratuit', 'purchasePrice' => '0', 'coefficientMultiplier' => '0'], 201)['id']);
        $this->assertSame([0.0, 0.0], [(float) $free['purchase_price'], (float) $free['price']], 'zéro est une valeur, pas une absence');

        // Champs facultatifs laissés vides = absents (avant : 500 sur un style vide, 404 sur une catégorie vide) ; 0 = aucun style
        $empty = $this->postMultipart($route, ['name' => '  Article aux champs vides  ', 'description' => '', 'quantity' => '', 'style_id' => '', 'category_ids' => '', 'image' => ''] + $price, 201);
        $row = $stored((int) $empty['id']);
        $this->assertSame(['Article aux champs vides', '', null, 0], [$row['name'], $row['description'], $row['style_id'], (int) $row['categories']], 'nom rogné, description vide gardée');
        $this->assertSame(null, $stored((int) $this->postMultipart($route, ['name' => 'Style zéro', 'style_id' => '0'] + $price, 201)['id'])['style_id']);

        // Un champ d'une autre forme que prévu est ignoré s'il est facultatif (description, image en texte), refusé sinon
        $odd = $this->postMultipart($route, ['name' => 'Description en tableau', 'description' => ['a', 'b'], 'image' => ['x']] + $price, 201);
        $this->assertNull($stored((int) $odd['id'])['description']);
        $this->assertSame([422, 'VALIDATION_FAILED', ['name' => 'REQUIRED']], $refused(['description' => 'Sans nom'] + $price));
        $this->assertSame([422, 'VALIDATION_FAILED', ['name' => 'REQUIRED']], $refused(['name' => ['tableau']] + $price));
        $this->assertSame([422, 'VALIDATION_FAILED', ['name' => 'REQUIRED']], $refused(['name' => '   '] + $price));
        $this->assertSame(
            [422, 'VALIDATION_FAILED', ['purchasePrice' => 'MUST_BE_INTEGER_CENTS', 'coefficientMultiplier' => 'MUST_BE_NUMBER', 'quantity' => 'MUST_BE_INTEGER', 'style_id' => 'INVALID_ID', 'category_ids' => 'INVALID_ID']],
            $refused(['name' => 'Mal formé', 'purchasePrice' => '12.5', 'coefficientMultiplier' => 'deux', 'quantity' => '-1', 'style_id' => 'abc', 'category_ids' => $categoryA->getId() . ',abc']),
            'toutes les erreurs en une réponse'
        );
        $this->assertSame([422, 'VALIDATION_FAILED', ['category_ids' => 'INVALID_ID']], $refused(['name' => 'Catégories imbriquées', 'category_ids' => [[$categoryA->getId()]]] + $price));
        $this->assertSame([422, 'VALIDATION_FAILED', ['category_ids' => 'INVALID_ID']], $refused(['name' => 'Catégorie zéro', 'category_ids' => '0'] + $price));
        $this->assertSame([404, 'CATEGORY_NOT_FOUND', null], $refused(['name' => 'Catégorie inconnue', 'category_ids' => '99999999'] + $price), 'référence inconnue : 404 à code, inchangé');

        // Catégories : chaîne « 1, 2 » ou tableau ; vides ignorés, doublons retirés
        $asString = $this->postMultipart($route, ['name' => 'Catégories en chaîne', 'category_ids' => $categoryA->getId() . ', ' . $categoryB->getId() . ','] + $price, 201);
        $this->assertSame(2, (int) $stored((int) $asString['id'])['categories']);
        $asArray = $this->postMultipart($route, ['name' => 'Catégories en tableau', 'category_ids' => ['', (string) $categoryA->getId(), (string) $categoryA->getId()]] + $price, 201);
        $this->assertSame(1, (int) $stored((int) $asArray['id'])['categories']);

        $ids = new \App\Gestion\Services\GestionInput(['a' => ['3', 3, ' 4 ', ''], 'b' => '7,7, 8', 'c' => 5, 'd' => null, 'e' => '1,x']);
        $this->assertSame([[3, 4], [7, 8], [5], [], [], [], ['e' => 'INVALID_ID']], [$ids->ids('a'), $ids->ids('b'), $ids->ids('c'), $ids->ids('d'), $ids->ids('absent'), $ids->ids('e'), $ids->errors()], 'liste d\'identifiants : doublons retirés');

        // Nom trop long : borné à la colonne (avant : 500 avec le message SQL)
        $long = $this->postMultipart($route, ['name' => str_repeat('Œ', 300)] + $price, 201);
        $row = $stored((int) $long['id']);
        $this->assertSame([255, true], [mb_strlen($row['name']), mb_strlen($row['slug']) <= 255 && $row['slug'] !== '']);

        // Image : une image, type lu dans le fichier ; le reste est refusé avant toute écriture
        $dir = sys_get_temp_dir() . '/dto-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents("$dir/page.png", '<html><body><script>alert(1)</script></body></html>');
        file_put_contents("$dir/dessin.svg", '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        file_put_contents("$dir/photo.png", base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
        $this->assertSame([422, 'VALIDATION_FAILED', ['image' => 'INVALID_IMAGE']], $refused(['name' => 'Page déguisée'] + $price, ['image' => new UploadedFile("$dir/page.png", 'photo.png', 'image/png', null, true)]), 'le nom et le type annoncés ne comptent pas');
        $this->assertSame([422, 'VALIDATION_FAILED', ['image' => 'INVALID_IMAGE']], $refused(['name' => 'Dessin SVG'] + $price, ['image' => new UploadedFile("$dir/dessin.svg", 'dessin.svg', 'image/svg+xml', null, true)]));
        $this->assertSame([422, 'VALIDATION_FAILED', ['image' => 'UPLOAD_FAILED']], $refused(['name' => 'Envoi coupé'] + $price, ['image' => new UploadedFile("$dir/photo.png", 'photo.png', 'image/png', \UPLOAD_ERR_PARTIAL, true)]));
        $this->assertSame([422, 'VALIDATION_FAILED', ['name' => 'REQUIRED', 'image' => 'INVALID_IMAGE']], $refused($price, ['image' => new UploadedFile("$dir/page.png", 'photo.png', 'image/png', null, true)]), 'erreurs du corps et de l\'image ensemble');

        // Rangée dans le dossier que nginx sert sous /assets/uploads/products (avant : public/assets/uploads/products, impossible à créer → 500)
        $uploads = static::getContainer()->getParameter('kernel.project_dir') . '/var/storage/public_bucket/assets/uploads';
        $this->client->request('POST', $route, ['name' => 'Avec photo'] + $price, ['image' => new UploadedFile("$dir/photo.png", 'photo.jpg', 'image/jpeg', null, true)], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $this->token]);
        $this->assertSame(201, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
        $image = (string) $stored((int) json_decode((string) $this->client->getResponse()->getContent(), true)['id'])['image'];
        try {
            $this->assertMatchesRegularExpression('/^[0-9a-f]+\.png$/', $image, 'extension du type réel, pas du nom envoyé');
            $this->assertFileExists($uploads . '/products/' . $image);
            $this->assertStringEndsWith('/assets/uploads/products/' . $image, (string) json_decode((string) $this->client->getResponse()->getContent(), true)['image'], 'adresse rendue = adresse servie');
        } finally {
            @unlink($uploads . '/products/' . $image);
            array_map('unlink', glob("$dir/*") ?: []);
            rmdir($dir);
        }
    }

    public function testPurchaseRoutesRequireAnAdministrator(): void
    {
        foreach (['/api/collections', '/api/transporteurs', '/api/type-fournisseurs', '/api/type-note-de-frais'] as $route) {
            $this->client->request('GET', $route, [], [], $this->headers(false));
            $this->assertSame(401, $this->client->getResponse()->getStatusCode(), $route . ' sans jeton (lot G4 : transporteurs n\'est plus public)');
        }
        $customer = $this->tokenFor(['ROLE_USER_INTERNET']);
        foreach (['/api/transporteurs', '/api/type-fournisseurs', '/api/type-note-de-frais'] as $route) {
            $this->client->request('GET', $route, [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $customer]);
            $this->assertSame(403, $this->client->getResponse()->getStatusCode(), $route . ' : client connecté non administrateur (lot G4)');
        }
    }

    // ----- helpers -----

    /** GET : corps JSON décodé tel quel (null compris) */
    private function getRaw(string $route): mixed
    {
        $this->request('GET', $route);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), $route . ' : ' . substr((string) $this->client->getResponse()->getContent(), 0, 300));

        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    private function tokenFor(array $roles): string
    {
        $email = 'gestion-client-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $em = $this->em();
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Client')->setLastname('Test')->setRoles($roles)->setIsVerified(true)->setPassword('inutilisable');
        $em->persist($user);
        $em->flush();

        return static::getContainer()->get('lexik_jwt_authentication.jwt_manager')->create($user);
    }

    private function collectionById(int $id): array
    {
        foreach ($this->get('/api/collections') as $collection) {
            if ((int) $collection['id'] === $id) {
                return $collection;
            }
        }
        $this->fail("collection $id absente de la liste");
    }

    private function collectionByName(string $name): array
    {
        foreach ($this->get('/api/collections') as $collection) {
            if ($collection['nomCollection'] === $name) {
                return $collection;
            }
        }
        $this->fail("collection « $name » absente de la liste");
    }

    private function setQuantity(int $productId, int $quantity): void
    {
        $em = $this->em();
        $product = $em->find(Product::class, $productId);
        $product->setQuantity($quantity);
        $em->flush();
        $this->endOfWrite();
    }

    private function headers(bool $authenticated = true): array
    {
        $headers = ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
        if ($authenticated) {
            $headers['HTTP_AUTHORIZATION'] = 'Bearer ' . $this->token;
        }

        return $headers;
    }

    private function request(string $method, string $route, ?array $body = null): void
    {
        $this->client->request($method, $route, [], [], $this->headers(), $body === null ? null : json_encode($body));
    }

    private function get(string $route): array
    {
        $this->request('GET', $route);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), $route . ' : ' . substr((string) $this->client->getResponse()->getContent(), 0, 300));

        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    private function post(string $route, array $body, int $expected): array|string
    {
        $this->request('POST', $route, $body);
        $this->assertSame($expected, $this->client->getResponse()->getStatusCode(), $route . ' : ' . substr((string) $this->client->getResponse()->getContent(), 0, 300));
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertTrue(is_array($decoded) || is_string($decoded), $route . ' : corps JSON attendu, reçu ' . substr((string) $this->client->getResponse()->getContent(), 0, 300));

        return $decoded;
    }

    private function postMultipart(string $route, array $fields, int $expected): array
    {
        $this->client->request('POST', $route, $fields, [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $this->token]);
        $this->assertSame($expected, $this->client->getResponse()->getStatusCode(), $route . ' : ' . substr((string) $this->client->getResponse()->getContent(), 0, 300));

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

    /** Administrateur créé pour le test, authentifié par jeton JWT comme l'application de gestion (en-tête Authorization) */
    private function login(array $roles): void
    {
        $email = 'gestion-test-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $container = static::getContainer();
        $em = $this->em();
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Gestion')->setLastname('Test')->setRoles($roles)->setIsVerified(true);
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD));
        $em->persist($user);
        $em->flush();
        $this->userId = (int) $user->getId();
        $this->token = $container->get('lexik_jwt_authentication.jwt_manager')->create($user);
        $this->endOfWrite();
    }
}
