<?php

namespace App\Tests\Functional\Gestion;

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
 * Réception de stock (lot R1, 10/11/2026, `docs/lignes-achat-gestion.md` § 7, contrat validé par l'application ; partie stock relue par la
 * session de la caisse) : réception d'une ligne (variante de la ligne, variante unique, variante par défaut créée sans couleur, taille, option
 * ni prix, stock initial conservé), choix de variante exigé sur un article à plusieurs variantes, réception en un geste avec needsVariant et
 * idempotence, annulation par mouvement inverse sans stock négatif, mouvements type 5 / 4 reliés à la ligne, réception permise sur une
 * commande figée, liste « à recevoir », non-régression : l'article reçu se cote au panier comme un article simple, au prix de sa fiche.
 */
class CommandeReceptionApiTest extends WebTestCase
{
    use CacheTestEntityFactory;

    private KernelBrowser $client;
    private string $token = '';

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->login();
    }

    public function testReceptionOfLinesWithAndWithoutVariants(): void
    {
        $tag = bin2hex(random_bytes(2));
        $conn = $this->em()->getConnection();
        $commande = $this->commande("Réception $tag");
        // Article A sans aucune variante, stock saisi 9, actif sur le web au prix 2500
        $a = $this->product("Sac $tag", 2500, 9, true);
        $lineA = $this->line($commande, $a, null, 4);
        $variantsBefore = (int) $conn->fetchOne('SELECT COUNT(*) FROM product_variant');

        // Réception partielle : 3 sur 4, variante par défaut créée
        $r = $this->post("/api/commandes/$commande/lignes/$lineA/reception", ['quantity' => 3], 200);
        $this->assertSame([3, 1, 3, 9, 12, 5], [$r['line']['quantityReceived'], $r['line']['remaining'], $r['movement']['quantity'], $r['movement']['stockBefore'], $r['movement']['stockAfter'], $r['movement']['type']], 'stock 9 + 3 ; type 5 « Réception fournisseur »');
        $this->assertSame($variantsBefore + 1, (int) $conn->fetchOne('SELECT COUNT(*) FROM product_variant'), 'une seule variante créée');
        $default = $conn->fetchAssociative('SELECT id, color_id, size_id, price, stock_quantity FROM product_variant WHERE product_id = ?', [$a]);
        $this->assertSame([null, null, null, 12], [$default['color_id'], $default['size_id'], $default['price'], (int) $default['stock_quantity']], 'variante par défaut : sans couleur, sans taille, prix NULL, stock initial conservé');
        $this->assertSame(0, (int) $conn->fetchOne('SELECT COUNT(*) FROM product_variant_product_option_value WHERE product_variant_id = ?', [$default['id']]), 'sans option');
        $this->assertSame([12, 2500.0, true], [(int) $conn->fetchOne('SELECT quantity FROM product WHERE id = ?', [$a]), (float) $conn->fetchOne('SELECT price FROM product WHERE id = ?', [$a]), (bool) $conn->fetchOne('SELECT is_web FROM product WHERE id = ?', [$a])], 'stock de l\'article recalculé ; prix et visibilité intacts');
        $this->assertSame((int) $lineA, (int) $conn->fetchOne('SELECT commande_line_id FROM inventory_movements WHERE id = ?', [$r['movement']['id']]), 'mouvement relié à sa ligne');
        // Trop, puis le reste, puis plus rien
        $this->request('POST', "/api/commandes/$commande/lignes/$lineA/reception", ['quantity' => 2]);
        $this->assertSame([422, ['quantity' => 'EXCEEDS_REMAINING']], [$this->status(), $this->body()['fields']]);
        $this->assertSame([4, 0], array_values(array_intersect_key($this->post("/api/commandes/$commande/lignes/$lineA/reception", [], 200)['line'], ['quantityReceived' => 0, 'remaining' => 0])));
        $this->request('POST', "/api/commandes/$commande/lignes/$lineA/reception");
        $this->assertSame([409, 'NOTHING_TO_RECEIVE'], [$this->status(), $this->body()['error']]);
        $this->assertSame((int) $default['id'], (int) $conn->fetchOne('SELECT id FROM product_variant WHERE product_id = ?', [$a]), 'la seconde réception réutilise la variante unique');

        // Non-régression vente : l'article reçu se cote au panier comme un article simple, au prix de sa fiche
        $quote = $this->post('/api/cart/quote', ['items' => [['productVariantId' => (int) $default['id'], 'quantity' => 1]], 'shippingAddress' => ['country' => 'CA', 'province' => 'QC']], 200);
        $this->assertSame(2500, (int) round((float) ($quote['lines'][0]['unitPrice'] ?? $quote['items'][0]['unitPrice'] ?? -1)), 'prix NULL de la variante : prix de la fiche ; ' . substr(json_encode($quote), 0, 300));

        // Article B à deux variantes, ligne sans variante : choix exigé, puis réception sur la variante choisie
        $b = $this->product("Robe $tag", 4000, 0, false);
        $s = (int) $this->create(ProductVariant::class, ['product' => $this->em()->find(Product::class, $b)])->getId();
        $m = (int) $this->create(ProductVariant::class, ['product' => $this->em()->find(Product::class, $b)])->getId();
        $conn = $this->em()->getConnection();
        $lineB = $this->line($commande, $b, null, 2);
        $this->request('POST', "/api/commandes/$commande/lignes/$lineB/reception");
        $this->assertSame([422, 'VARIANT_REQUIRED', [$s, $m]], [$this->status(), $this->body()['error'], array_column($this->body()['variants'], 'id')]);
        $this->assertSame([1, $m], [$this->post("/api/commandes/$commande/lignes/$lineB/reception", ['quantity' => 1, 'variantId' => $m], 200)['movement']['quantity'], $this->body()['movement']['variantId']]);
        $this->assertSame(1, (int) $conn->fetchOne('SELECT stock_quantity FROM product_variant WHERE id = ?', [$m]));

        // Annulation : mouvement inverse type 4 ; jamais de stock négatif
        $c = $this->post("/api/commandes/$commande/lignes/$lineA/reception/annuler", ['quantity' => 1], 200);
        $this->assertSame([-1, 4, 3], [$c['movement']['quantity'], $c['movement']['type'], $c['line']['quantityReceived']]);
        $this->assertSame(12, (int) $conn->fetchOne('SELECT stock_quantity FROM product_variant WHERE id = ?', [$default['id']]));
        $conn->executeStatement('UPDATE product_variant SET stock_quantity = 1 WHERE id = ?', [$default['id']]); // articles vendus entre-temps
        $this->endOfWrite();
        $this->request('POST', "/api/commandes/$commande/lignes/$lineA/reception/annuler", ['quantity' => 3]);
        $this->assertSame([409, 'STOCK_INSUFFICIENT', 1], [$this->status(), $this->body()['error'], $this->body()['stock']]);
        $this->assertSame(1, (int) $conn->fetchOne('SELECT stock_quantity FROM product_variant WHERE id = ?', [$default['id']]), 'rien n\'a bougé');
        $this->assertSame([0, 0], [(int) $conn->fetchOne('SELECT COUNT(*) FROM inventory_movements WHERE commande_line_id = ? AND quantity > 0 AND movement_type_id <> 5', [$lineA]), (int) $conn->fetchOne('SELECT COUNT(*) FROM inventory_movements WHERE commande_line_id = ? AND quantity < 0 AND movement_type_id <> 4', [$lineA])], 'réception = type 5, annulation = type 4');
    }

    public function testOneGestureReceptionNeedsVariantIdempotenceAndClosedCommande(): void
    {
        $tag = bin2hex(random_bytes(2));
        $commande = $this->commande("En un geste $tag");
        $single = $this->product("Tuque $tag", 2000, 5, false);
        $only = (int) $this->create(ProductVariant::class, ['product' => $this->em()->find(Product::class, $single)])->getId();
        $multi = $this->product("Chemise $tag", 3000, 0, false);
        $this->create(ProductVariant::class, ['product' => $this->em()->find(Product::class, $multi)]);
        $this->create(ProductVariant::class, ['product' => $this->em()->find(Product::class, $multi)]);
        $conn = $this->em()->getConnection();
        $conn->executeStatement('UPDATE product_variant SET stock_quantity = 5 WHERE id = ?', [$only]);
        $lineSingle = $this->line($commande, $single, null, 2);
        $lineMulti = $this->line($commande, $multi, null, 3);
        $conn->executeStatement('UPDATE commande SET is_closed = true WHERE id = ?', [$commande]); // commande figée : la réception reste permise
        $this->endOfWrite();

        // « à recevoir »
        $this->request('GET', '/api/commandes/lignes/a-recevoir?limit=200');
        $this->assertSame(200, $this->status());
        $rows = array_column($this->body(), null, 'id');
        $this->assertSame([2, "En un geste $tag"], [$rows[$lineSingle]['remaining'], $rows[$lineSingle]['commande']['name']]);
        $this->assertGreaterThanOrEqual(2, (int) $this->client->getResponse()->headers->get('X-Total-Count'));

        // En un geste : la tuque est reçue, la chemise attend son choix de variante
        $all = $this->post("/api/commandes/$commande/reception", [], 200);
        $this->assertSame([[$lineSingle], [$lineMulti], 2], [array_column($all['received'], 'lineId'), array_column($all['needsVariant'], 'lineId'), count($all['needsVariant'][0]['variants'])]);
        $this->assertSame(['partial', 3], [$all['commande']['receptionStatus'], $all['commande']['remaining']]);
        $this->assertSame(7, (int) $conn->fetchOne('SELECT stock_quantity FROM product_variant WHERE id = ?', [$only]), '5 + 2');
        // Rejeu après coupure : rien n'est reçu deux fois
        $again = $this->post("/api/commandes/$commande/reception", [], 200);
        $this->assertSame([[], [$lineMulti]], [$again['received'], array_column($again['needsVariant'], 'lineId')]);
        $this->assertSame(7, (int) $conn->fetchOne('SELECT stock_quantity FROM product_variant WHERE id = ?', [$only]));
        // Ligne choisie avec sa variante par la même route
        $variant = $all['needsVariant'][0]['variants'][1]['id'];
        $done = $this->post("/api/commandes/$commande/reception", ['lines' => [['lineId' => $lineMulti, 'variantId' => $variant]]], 200);
        $this->assertSame([[$lineMulti], [], 'complete', 0], [array_column($done['received'], 'lineId'), $done['needsVariant'], $done['commande']['receptionStatus'], $done['commande']['remaining']]);
        $this->request('POST', "/api/commandes/$commande/reception", ['lines' => [['lineId' => 999999999]]]);
        $this->assertSame([404, 'LINE_NOT_FOUND'], [$this->status(), $this->body()['error']]);
        $collection = (int) $conn->fetchOne('SELECT collections_id FROM commande WHERE id = ?', [$commande]);
        $listed = array_column($this->get("/api/collections/$collection/commandes"), null, 'id');
        $this->assertSame('complete', $listed[$commande]['receptionStatus'], 'état de réception dans les lectures de commandes');
    }

    // ----- fabrique -----

    private function commande(string $name): int
    {
        $period = static::getContainer()->get(\App\Services\GestionService\PeriodCalendar::class)->current()->getId(); // période courante, créée si besoin
        $conn = $this->em()->getConnection();
        $conn->executeStatement("INSERT INTO commande (id, budget, date, name, is_closed, collections_id) VALUES (nextval('commande_id_seq'), 10000, NOW(), :n, false, :c)", ['n' => $name, 'c' => $period ?: null]);

        return (int) $conn->fetchOne('SELECT id FROM commande WHERE name = ?', [$name]);
    }

    private function product(string $name, int $price, int $quantity, bool $web): int
    {
        $conn = $this->em()->getConnection();
        $slug = 'r1-' . bin2hex(random_bytes(4));
        $conn->executeStatement("INSERT INTO product (id, name, price, isbestseller, quantity, created_at, slug, product_type, is_web, is_pos) VALUES (nextval('product_id_seq'), :n, :p, false, :q, NOW(), :s, 'standard', :w, :w)", ['n' => $name, 'p' => $price, 'q' => $quantity, 's' => $slug, 'w' => $web], ['w' => \Doctrine\DBAL\ParameterType::BOOLEAN]);

        return (int) $conn->fetchOne('SELECT id FROM product WHERE slug = ?', [$slug]);
    }

    private function line(int $commande, int $product, ?int $variant, int $quantity): int
    {
        $conn = $this->em()->getConnection();
        $conn->executeStatement("INSERT INTO commande_line (commande_id, product_id, product_variant_id, quantity_ordered, quantity_received, unit_cost_cents, attached_at, source, created_at, updated_at) VALUES (?, ?, ?, ?, 0, 1000, NOW(), 'document', NOW(), NOW())", [$commande, $product, $variant, $quantity]);
        $this->endOfWrite();

        return (int) $conn->fetchOne('SELECT MAX(id) FROM commande_line WHERE commande_id = ? AND product_id = ?', [$commande, $product]);
    }

    // ----- helpers -----

    private function status(): int
    {
        return $this->client->getResponse()->getStatusCode();
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
        $this->assertSame(200, $this->status(), $route);

        return $this->body();
    }

    private function post(string $route, array $body, int $expected): array
    {
        $this->request('POST', $route, $body);
        $this->assertSame($expected, $this->status(), $route . ' : ' . substr((string) $this->client->getResponse()->getContent(), 0, 400));

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
        $email = 'gestion-r1-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $em = $this->em();
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Gestion')->setLastname('R1')->setRoles(['ROLE_ADMIN'])->setIsVerified(true)->setPassword('inutilisable');
        $em->persist($user);
        $em->flush();
        $this->token = static::getContainer()->get('lexik_jwt_authentication.jwt_manager')->create($user);
        $this->endOfWrite();
    }
}
