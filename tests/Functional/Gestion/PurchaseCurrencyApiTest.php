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
     * Lot EDIT (12/11/2026) : modification partielle d'un fournisseur, d'un transporteur et d'un frais de port (seules les clés
     * envoyées changent). Devise d'un fournisseur figée dès sa première commande ; transporteur des factures non renommable ; frais de
     * port d'une commande figée ou d'une période fermée non modifiable ; les chiffres de la période ouverte suivent le nouveau prix ;
     * chaque modification est inscrite au journal des contenus.
     */
    public function testSupplierCarrierAndShippingCostCanBeEdited(): void
    {
        $tag = bin2hex(random_bytes(2));
        $today = static::getContainer()->get(\App\Gestion\Services\PeriodCalendar::class)->today();
        $typeA = $this->create(TypeFournisseur::class, ['name' => 'Type A ' . $tag])->getId();
        $typeB = $this->create(TypeFournisseur::class, ['name' => 'Type B ' . $tag])->getId();
        $carrier1 = $this->create(Transporteur::class, ['name' => 'Transport un ' . $tag])->getId();
        $carrier2 = $this->create(Transporteur::class, ['name' => 'Transport deux ' . $tag])->getId();
        $this->endOfWrite();
        $error = function (string $method, string $route, ?array $body): array {
            $this->request($method, $route, $body);

            return [$this->client->getResponse()->getStatusCode(), $this->body()['error'] ?? null, $this->body()['fields'] ?? null];
        };
        $patch = function (string $route, array $body): array {
            $this->request('PATCH', $route, $body);
            $this->assertSame(200, $this->client->getResponse()->getStatusCode(), $route . ' : ' . substr((string) $this->client->getResponse()->getContent(), 0, 300));

            return $this->body();
        };
        $audit = fn (string $resource, int $id): array => array_map(fn (array $r) => ['fields' => $r['fields'], 'before' => json_decode((string) $r['before_state'], true), 'after' => json_decode((string) $r['after_state'], true)],
            $this->em()->getConnection()->fetchAllAssociative("SELECT fields, before_state, after_state FROM content_audit_log WHERE resource = ? AND resource_id = ? AND action = 'update' ORDER BY id", [$resource, (string) $id]));

        // ----- Fournisseur
        $supplier = $this->post('/api/fournisseurs', ['name' => "Maison $tag", 'typeFournisseur' => $typeA, 'ville' => 'Montréal', 'tel' => '514 555 0100', 'currency' => 'USD'], 201);
        $s = "/api/fournisseurs/{$supplier['id']}";
        $edited = $patch($s, ['ville' => 'Québec']);
        $this->assertSame(array_merge($supplier, ['ville' => 'Québec']), $edited, 'une seule clé envoyée : le reste ne change pas, forme de la liste');
        $edited = $patch($s, ['tel' => '', 'pays' => null, 'adresse' => ' 12 rue des Érables ', 'champEnTrop' => 1]);
        $this->assertSame([null, null, '12 rue des Érables', 'Québec', "Maison $tag"], [$edited['tel'], $edited['pays'], $edited['adresse'], $edited['ville'], $edited['name']], 'vide ou null : effacé ; texte rogné');
        $edited = $patch($s, ['name' => "Maison renommée $tag", 'typeFournisseur' => $typeB]);
        $this->assertSame(["Maison renommée $tag", $typeB], [$edited['name'], (int) $this->em()->getConnection()->fetchOne('SELECT type_fournisseur_id FROM fournisseur WHERE id = ?', [$supplier['id']])]);
        $listed = array_column($this->get('/api/fournisseurs?limit=100&q=' . urlencode("renommée $tag")), null, 'id');
        $this->assertSame($edited, $listed[$supplier['id']] ?? null, 'la liste rend le fournisseur modifié');
        // Devise : libre tant qu'il n'a aucune commande
        $this->assertSame(['EUR', 'EUR'], [$patch($s, ['currency' => 'eur'])['purchaseCurrency'], $this->body()['currency']]);
        $this->assertSame([null, 'CAD'], [$patch($s, ['currency' => null])['purchaseCurrency'], $this->body()['currency']], 'null : retour à la devise du site');
        $this->assertSame('USD', $patch($s, ['currency' => 'USD'])['purchaseCurrency']);
        // Refus : rien n'est écrit
        $state = fn (): string => (string) $this->em()->getConnection()->fetchOne('SELECT md5(CAST(row_to_json(f) AS text)) FROM fournisseur f WHERE id = ?', [$supplier['id']]);
        $before = $state();
        $this->assertSame([422, 'VALIDATION_FAILED', ['name' => 'REQUIRED']], $error('PATCH', $s, ['name' => '  ']));
        $this->assertSame([422, 'VALIDATION_FAILED', ['name' => 'REQUIRED', 'typeFournisseur' => 'INVALID_ID', 'currency' => 'INVALID_CURRENCY']], $error('PATCH', $s, ['name' => ['x'], 'typeFournisseur' => 'abc', 'currency' => 'XXX', 'ville' => 'Ignorée']), 'toutes les erreurs en une réponse');
        $this->assertSame([404, 'TYPE_FOURNISSEUR_NOT_FOUND', null], $error('PATCH', $s, ['typeFournisseur' => 99999999, 'ville' => 'Ignorée']));
        $this->assertSame([404, 'FOURNISSEUR_NOT_FOUND', null], $error('PATCH', '/api/fournisseurs/99999999', ['ville' => 'X']));
        $this->client->request('PATCH', $s, [], [], $this->headers(), 'pas du json');
        $this->assertSame([400, 'INVALID_JSON'], [$this->client->getResponse()->getStatusCode(), $this->body()['error']]);
        $this->assertSame($before, $state(), 'aucun refus n\'écrit');
        // Devise figée dès la première commande ; le reste se modifie toujours
        $collectionId = (int) $this->post('/api/createcollections', ['budgetCollection' => 1000000, 'nomCollection' => "Edit $tag"], 201)['id'];
        $commande = $this->post("/api/collections/$collectionId/commandes", ['budget' => 100000, 'date' => $today->format('Y-m-d') . ' 10:00:00', 'name' => "Import $tag", 'fournisseur' => ['id' => $supplier['id']], 'exchangeRate' => 1.35], 201);
        $commandeId = (int) $commande['id'];
        foreach (['EUR', null, ''] as $other) {
            $this->request('PATCH', $s, ['currency' => $other, 'ville' => 'Ignorée']);
            $this->assertSame([409, 'FOURNISSEUR_CURRENCY_LOCKED', 1], [$this->client->getResponse()->getStatusCode(), $this->body()['error'], $this->body()['commandes'] ?? null], 'devise figée : ' . json_encode($other) . ' (null = retour à la devise du site, donc un changement)');
        }
        $this->assertSame($before, $state(), 'refus : la ville envoyée avec la devise n\'est pas écrite non plus');
        $final = $patch($s, ['currency' => 'usd', 'name' => "Maison finale $tag"]);
        $this->assertSame(['USD', "Maison finale $tag"], [$final['purchaseCurrency'], $final['name']], 'la même devise n\'est pas un changement');
        $this->assertSame("Maison finale $tag", array_column($this->get("/api/collections/$collectionId/commandes"), null, 'id')[$commandeId]['fournisseur']['name'], 'la liste des commandes montre le nouveau nom aussitôt');
        $this->assertSame("Maison finale $tag", array_column($this->get("/api/collections/$collectionId/frais-de-port")['commandes'], null, 'commandeId')[$commandeId]['fournisseur']['name']);
        $this->assertSame($final, $patch($s, ['ville' => 'Québec', 'currency' => 'USD', 'name' => "Maison finale $tag"]), 'valeurs identiques : même réponse');
        $entries = $audit('fournisseurs', (int) $supplier['id']);
        $this->assertSame('ville', $entries[0]['fields']);
        $this->assertSame(['Montréal', 'Québec'], [$entries[0]['before']['ville'], $entries[0]['after']['ville']], 'journal : avant, après');
        $this->assertCount(7, $entries, 'une entrée par modification effective, aucune pour un refus ni pour un envoi qui ne change rien');

        // ----- Transporteur
        $t = "/api/transporteurs/$carrier1";
        $this->assertSame(['id' => $carrier1, 'name' => 'Transport un ' . $tag, 'logo' => null, 'contact' => '1 800 555 0199'], $patch($t, ['contact' => '1 800 555 0199']), 'forme de la création');
        $this->assertSame(['Transport renommé ' . $tag, 'logo.png', '1 800 555 0199'], array_values(array_diff_key($patch($t, ['name' => 'Transport renommé ' . $tag, 'logo' => 'logo.png']), ['id' => 1])));
        $this->assertSame([null, null], [$patch($t, ['logo' => '', 'contact' => null])['logo'], $this->body()['contact']]);
        $this->assertContains('Transport renommé ' . $tag, array_column($this->get('/api/transporteurs'), 'name'), 'la liste rend le transporteur modifié');
        $this->assertSame([422, 'VALIDATION_FAILED', ['name' => 'REQUIRED']], $error('PATCH', $t, ['name' => '']));
        $this->assertSame([404, 'TRANSPORTEUR_NOT_FOUND', null], $error('PATCH', '/api/transporteurs/99999999', ['contact' => 'x']));
        $reserved = $this->em()->getRepository(Transporteur::class)->findOneBy(['name' => \App\Services\TransporteurService\TransporteurService::RESERVED_NAME]) ?? $this->create(Transporteur::class, ['name' => \App\Services\TransporteurService\TransporteurService::RESERVED_NAME]);
        $this->endOfWrite();
        $r = '/api/transporteurs/' . $reserved->getId();
        $this->assertSame('Sur facture fournisseur', \App\Services\TransporteurService\TransporteurService::RESERVED_NAME);
        $this->assertSame([409, 'TRANSPORTEUR_RESERVED', null], $error('PATCH', $r, ['name' => 'Autre nom', 'contact' => 'ignoré']));
        $this->assertSame(['Sur facture fournisseur', 'Service des achats'], [$patch($r, ['name' => 'Sur facture fournisseur', 'contact' => 'Service des achats'])['name'], $this->body()['contact']], 'même nom : pas un renommage ; contact modifiable');
        $patch($r, ['contact' => null]);
        $patch($t, ['name' => 'Transport renommé ' . $tag, 'logo' => null, 'contact' => '']);
        $this->assertCount(3, $audit('transporteurs', $carrier1), 'aucune entrée pour un envoi qui ne change rien');

        // ----- Frais de port
        $f = "/api/commandes/$commandeId/frais-de-port";
        $this->assertSame([404, 'FRAIS_DE_PORT_NOT_FOUND', null], $error('PATCH', $f, ['price' => 1]), 'commande sans frais de port');
        $this->assertSame([404, 'COMMANDE_NOT_FOUND', null], $error('PATCH', '/api/commandes/99999999/frais-de-port', ['price' => 1]));
        $shipping = $this->post($f, ['name' => 'Fret maritime', 'facture' => "FR-$tag", 'tracknumber' => "TR-$tag", 'price' => 10000, 'transporteur' => ['id' => $carrier1]], 201);
        $edited = $patch($f, ['price' => 12000]);
        $this->assertSame(array_merge($shipping, ['price' => 12000, 'priceConverted' => 16200]), $edited, 'prix seul : le reste ne change pas, forme de la lecture (devise de la commande, prix converti à 1,35)');
        $this->assertSame($edited, $this->get($f), 'la lecture rend le frais de port modifié');
        $this->assertSame([16200, 16200], [$this->get("/api/dashboard/collection/$collectionId")['GeneralExpenses']['totalShippingCost'], $this->get("/api/collections/$collectionId/frais-de-port")['totals']['amount']], 'les chiffres de la période ouverte suivent le nouveau prix');
        $edited = $patch($f, ['transporteur' => ['id' => $carrier2], 'tracknumber' => 'TR-2', 'facture' => '', 'name' => 'Fret aérien']);
        $this->assertSame([$carrier2, 'Transport deux ' . $tag, 'TR-2', '', 'Fret aérien', 12000], [$edited['transporteur']['id'], $edited['transporteur']['name'], $edited['tracknumber'], $edited['facture'], $edited['name'], $edited['price']]);
        $this->assertSame($edited, $patch($f, ['price' => 12000, 'name' => 'Fret aérien', 'transporteur' => ['id' => $carrier2]]), 'valeurs identiques : même réponse, rien au journal');
        $stateF = fn (): string => (string) $this->em()->getConnection()->fetchOne('SELECT md5(CAST(row_to_json(f) AS text)) FROM frais_de_port f WHERE commande_id = ?', [$commandeId]);
        $before = $stateF();
        $this->assertSame([422, 'VALIDATION_FAILED', ['name' => 'REQUIRED', 'price' => 'MUST_BE_INTEGER_CENTS', 'transporteur' => 'INVALID_ID']], $error('PATCH', $f, ['name' => '', 'price' => 12.5, 'transporteur' => ['id' => 'x']]));
        $this->assertSame([422, 'VALIDATION_FAILED', ['price' => 'TOO_LARGE']], $error('PATCH', $f, ['price' => 2147483648]));
        $this->assertSame([422, 'VALIDATION_FAILED', ['price' => 'REQUIRED']], $error('PATCH', $f, ['price' => null]), 'un prix envoyé vide n\'efface pas le prix');
        $this->assertSame([404, 'TRANSPORTEUR_NOT_FOUND', null], $error('PATCH', $f, ['transporteur' => ['id' => 99999999], 'price' => 1]));
        // Commande figée, puis période fermée : non modifiable (gardes de la création et de la suppression)
        $conn = $this->em()->getConnection();
        $conn->executeStatement('UPDATE commande SET is_closed = true WHERE id = ?', [$commandeId]);
        $this->em()->clear();
        $this->assertSame([409, 'COMMANDE_CLOSED', null], $error('PATCH', $f, ['price' => 1]));
        $conn = $this->em()->getConnection();
        $conn->executeStatement("UPDATE commande SET is_closed = false, date = NOW() - INTERVAL '400 days' WHERE id = ?", [$commandeId]);
        $this->em()->clear();
        $this->request('PATCH', $f, ['price' => 1]);
        $this->assertSame([409, 'PERIOD_CLOSED', true], [$this->client->getResponse()->getStatusCode(), $this->body()['error'], isset($this->body()['periodId'])], 'commande datée dans une période fermée');
        $conn = $this->em()->getConnection();
        $conn->executeStatement('UPDATE commande SET date = ? WHERE id = ?', [$today->format('Y-m-d') . ' 10:00:00', $commandeId]);
        $this->em()->clear();
        $this->assertSame($before, $stateF(), 'aucun refus n\'écrit');
        // Frais de port venu d'un document lu par IA : le prix reste modifiable ; le journal garde l'identifiant du document
        $document = $this->create(\App\Gestion\Entity\GestionDocument::class, ['type' => 'transport', 'status' => 'confirmed']);
        $this->em()->getConnection()->executeStatement('UPDATE frais_de_port SET document_id = ? WHERE commande_id = ?', [$document->getId(), $commandeId]);
        $this->em()->clear();
        $this->endOfWrite();
        $this->assertSame([9900, $document->getId()], [$patch($f, ['price' => 9900])['price'], $this->body()['document']['id'] ?? null]);
        $entries = $audit('frais-de-port', (int) $shipping['id']);
        $this->assertCount(3, $entries);
        $this->assertSame(['price', 10000, 12000, $commandeId, null], [$entries[0]['fields'], $entries[0]['before']['price'], $entries[0]['after']['price'], $entries[0]['after']['commandeId'], $entries[0]['after']['documentId']]);
        $this->assertSame([12000, 9900, $document->getId()], [$entries[2]['before']['price'], $entries[2]['after']['price'], $entries[2]['after']['documentId']], 'l\'écart avec le document se retrouve au journal');

        // ----- Rôles : administrateur seulement ; sans jeton, 401
        $admin = $this->token;
        $this->token = static::getContainer()->get('lexik_jwt_authentication.jwt_manager')->create($this->create(User::class, ['email' => "client-$tag@example.invalid", 'username' => "client-$tag@example.invalid", 'roles' => ['ROLE_USER_INTERNET'], 'password' => 'inutilisable', 'isVerified' => true]));
        foreach ([$s, $t, $f] as $route) {
            $this->request('PATCH', $route, ['name' => 'Refusé']);
            $this->assertSame(403, $this->client->getResponse()->getStatusCode(), $route);
            $this->client->request('PATCH', $route, [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'], '{"name":"Refusé"}');
            $this->assertSame(401, $this->client->getResponse()->getStatusCode(), $route);
        }
        $this->token = $admin;
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
