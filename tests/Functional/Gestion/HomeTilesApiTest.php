<?php

namespace App\Tests\Functional\Gestion;

use App\Entity\Order;
use App\Entity\OrderItems;
use App\Entity\OrderSource;
use App\Entity\OrderType;
use App\Entity\Product;
use App\Entity\ProductVariant;
use App\Entity\StatusCommande;
use App\Entity\User;
use App\EventSubscriber\CacheInvalidationSubscriber;
use App\Services\TenantEntityManagerProvider;
use App\Tests\Functional\Cache\CacheTestEntityFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Vignettes d'accueil, première version (11/11/2026, `docs/vignettes-gestion.md`, contrat validé par l'application) : alerte de stock
 * bas (articles qui se vendent et vont manquer ; articles hors vente, de location ou bien fournis exclus), premiers pas par règles,
 * 3 vignettes au plus triées par urgence, écart par utilisateur (premier pas pour toujours ; alerte 7 jours ou jusqu'à ce que la liste
 * change), 404 à code, langue. Base partagée : l'appartenance d'un article à l'alerte se lit dans action.params.variantIds.
 */
class HomeTilesApiTest extends WebTestCase
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

    public function testLowStockFirstStepsDismissalAndLanguage(): void
    {
        $tag = bin2hex(random_bytes(2));
        $now = new \DateTimeImmutable('-1 hour');
        // En vente, stock 1, 3 vendus en 30 jours → 10 jours de couverture : alerte
        $short = $this->variant("Bonnet $tag", 1, ['is_web' => true]);
        // Bien fourni : stock 100 pour 3 vendus → pas d'alerte
        $plenty = $this->variant("Écharpe $tag", 100, ['is_web' => true]);
        // Une seule vente → ne « se vend » pas encore
        $once = $this->variant("Gants $tag", 0, ['is_pos' => true]);
        // Hors vente (invisible partout, comme un article créé par une facture), de location, vente coupée → exclus
        $hidden = $this->variant("Caché $tag", 0, ['is_web' => false, 'is_pos' => false]);
        $rental = $this->variant("Location $tag", 0, ['is_web' => true, 'rental_enabled' => true]);
        $noSale = $this->variant("Retiré $tag", 0, ['is_web' => true, 'sale_enabled' => false]);
        foreach ([$short, $plenty, $hidden, $rental, $noSale] as $v) {
            $this->sale($v, 3, 1000, $now);
        }
        $this->sale($once, 1, 1000, $now);
        // Vieille vente (40 jours) : hors fenêtre
        $old = $this->variant("Ancien $tag", 0, ['is_web' => true]);
        $this->sale($old, 5, 1000, $now->modify('-40 days'));
        $this->endOfWrite();

        $tiles = $this->get('/api/gestion/accueil/vignettes?locale=fr');
        $this->assertLessThanOrEqual(3, count($tiles));
        $this->assertSame(array_column($tiles, 'priority'), $this->sortedDesc(array_column($tiles, 'priority')), 'les plus urgentes d\'abord');
        $low = $this->tile($tiles, 'low-stock');
        $this->assertNotNull($low, 'alerte de stock bas');
        $this->assertSame(['alert', 80, 'stock.low', true, 'Stock bas'], [$low['kind'], $low['priority'], $low['action']['screen'], $low['dismissible'], $low['title']]);
        $ids = $low['action']['params']['variantIds'];
        $this->assertContains($short, $ids);
        foreach ([$plenty, $once, $hidden, $rental, $noSale, $old] as $v) {
            $this->assertNotContains($v, $ids, 'exclu de l\'alerte');
        }
        $this->assertStringContainsString(count($ids) . (count($ids) === 1 ? ' article qui se vend' : ' articles qui se vendent'), $low['text']);
        $this->assertSame(['Ventes des 30 derniers jours', 'Stock actuel'], array_column($low['sources'], 'label'));
        $this->assertLessThanOrEqual(20, count($low['figures']));
        $mine = array_values(array_filter($low['figures'], fn ($f) => $f['variantId'] === $short))[0] ?? null;
        if ($mine !== null) {
            $this->assertSame(["Bonnet $tag", 1, 3, 10], [$mine['name'], $mine['stock'], $mine['soldLast30Days'], $mine['daysLeft']]);
        }
        $this->assertArrayNotHasKey('signature', $low);

        // Écartée : disparaît pour cet utilisateur
        $this->post('/api/gestion/accueil/vignettes/low-stock/ecarter', 204);
        $this->assertNull($this->tile($this->get('/api/gestion/accueil/vignettes'), 'low-stock'));
        // La liste change (un nouvel article va manquer) : elle revient avant 7 jours
        $other = $this->variant("Mitaines $tag", 0, ['is_web' => true]);
        $this->sale($other, 2, 1000, $now);
        $this->endOfWrite();
        $back = $this->tile($this->get('/api/gestion/accueil/vignettes'), 'low-stock');
        $this->assertNotNull($back, 'liste changée : l\'alerte revient');
        $this->assertContains($other, $back['action']['params']['variantIds']);
        // Écartée de nouveau, puis 7 jours passés : elle revient
        $this->post('/api/gestion/accueil/vignettes/low-stock/ecarter', 204);
        $this->assertNull($this->tile($this->get('/api/gestion/accueil/vignettes'), 'low-stock'));
        $until = $this->em()->getConnection()->fetchOne("SELECT until FROM gestion_tile_dismissal WHERE user_id = ? AND tile_id = 'low-stock'", [$this->userId]);
        $this->assertEqualsWithDelta((new \DateTimeImmutable('+7 days', new \DateTimeZone('UTC')))->getTimestamp(), (new \DateTimeImmutable($until, new \DateTimeZone('UTC')))->getTimestamp(), 120, 'retour au bout de 7 jours');
        $this->em()->getConnection()->executeStatement("UPDATE gestion_tile_dismissal SET until = NOW() - INTERVAL '1 minute' WHERE user_id = ?", [$this->userId]);
        $this->assertNotNull($this->tile($this->get('/api/gestion/accueil/vignettes'), 'low-stock'), 'délai passé : elle revient');
        // Un autre gérant la voit toujours : l'écart est personnel
        $this->post('/api/gestion/accueil/vignettes/low-stock/ecarter', 204);
        $first = $this->userId;
        $this->login();
        $this->assertNotNull($this->tile($this->get('/api/gestion/accueil/vignettes'), 'low-stock'), 'écart par utilisateur');
        $this->assertNotSame($first, $this->userId);

        // Langue
        $en = $this->tile($this->get('/api/gestion/accueil/vignettes?locale=en'), 'low-stock');
        $this->assertSame('Low stock', $en['title']);

        // Premiers pas : présents selon l'état du site ; un premier pas écarté ne revient plus
        $conn = $this->em()->getConnection();
        $conn->executeStatement('UPDATE gestion_document SET campaign_id = NULL WHERE campaign_id IS NOT NULL');
        $conn->executeStatement('DELETE FROM marketing_campaign');
        $this->endOfWrite();
        foreach (['low-stock', 'first-invoice', 'first-note', 'cash-first-balance', 'cash-low'] as $id) { // trésorerie (B1b-1) : dépend de l'état de la base partagée
            $this->post("/api/gestion/accueil/vignettes/$id/ecarter", 204);
        }
        $tiles = $this->get('/api/gestion/accueil/vignettes');
        $campaign = $this->tile($tiles, 'first-campaign');
        $this->assertNotNull($campaign, 'aucune campagne : premier pas publicité');
        $this->assertSame(['first_steps', 10, 'campagnes.new', [], [], []], [$campaign['kind'], $campaign['priority'], $campaign['action']['screen'], (array) $campaign['action']['params'], $campaign['sources'], $campaign['figures']]);
        $this->assertSame(['first-campaign'], array_column($tiles, 'id'), 'les autres sont écartées');
        $this->assertNull($conn->fetchOne("SELECT until FROM gestion_tile_dismissal WHERE user_id = ? AND tile_id = 'first-note'", [$this->userId]), 'premier pas écarté pour toujours');
        $conn->executeStatement("INSERT INTO marketing_campaign (name, channel, start_date, end_date, amount_cents, created_at, updated_at) VALUES ('Vignettes', 'email', CURRENT_DATE, CURRENT_DATE, 100, NOW(), NOW())");
        $this->assertSame([], $this->get('/api/gestion/accueil/vignettes'), 'campagne notée : le premier pas disparaît seul');
        // L'état du site décide des deux autres premiers pas (base partagée : on lit l'état avant de conclure)
        $this->login();
        $ids = array_column($this->get('/api/gestion/accueil/vignettes'), 'id');
        $noInvoice = $conn->fetchOne('SELECT 1 FROM commande_line LIMIT 1') === false && $conn->fetchOne("SELECT 1 FROM gestion_document WHERE type = 'facture' AND status = 'confirmed' LIMIT 1") === false;
        $this->assertSame($noInvoice, in_array('first-invoice', $ids, true));
        $noNote = $conn->fetchOne('SELECT 1 FROM note_de_frais LIMIT 1') === false;
        $this->assertSame($noNote, in_array('first-note', $ids, true));
        $conn->executeStatement('DELETE FROM marketing_campaign');

        // Vignette inconnue
        $this->request('POST', '/api/gestion/accueil/vignettes/inconnue/ecarter');
        $this->assertSame([404, 'TILE_NOT_FOUND'], [$this->status(), $this->body()['error']]);
    }

    public function testAnonymousIsRefused(): void
    {
        $this->client->request('GET', '/api/gestion/accueil/vignettes', [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
        $this->client->request('POST', '/api/gestion/accueil/vignettes/low-stock/ecarter', [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
    }

    /** @param list<int> $p */
    private function sortedDesc(array $p): array
    {
        rsort($p);

        return $p;
    }

    /** @param list<array<string, mixed>> $tiles */
    private function tile(array $tiles, string $id): ?array
    {
        return array_values(array_filter($tiles, fn ($t) => $t['id'] === $id))[0] ?? null;
    }

    /** Article standard à une variante, stock donné, colonnes de vente / visibilité données */
    private function variant(string $name, int $stock, array $flags): int
    {
        $conn = $this->em()->getConnection();
        $slug = 'vg-' . bin2hex(random_bytes(4));
        $conn->executeStatement("INSERT INTO product (id, name, price, isbestseller, quantity, created_at, slug, product_type) VALUES (nextval('product_id_seq'), :n, 2000, false, :q, NOW(), :s, 'standard')", ['n' => $name, 'q' => $stock, 's' => $slug]);
        $id = (int) $conn->fetchOne('SELECT id FROM product WHERE slug = ?', [$slug]);
        foreach ($flags as $col => $value) {
            $conn->executeStatement("UPDATE product SET $col = :v WHERE id = :id", ['v' => $value, 'id' => $id], ['v' => \Doctrine\DBAL\ParameterType::BOOLEAN]);
        }
        $variant = (int) $this->create(ProductVariant::class, ['product' => $this->em()->find(Product::class, $id)])->getId();
        $conn->executeStatement('UPDATE product_variant SET stock_quantity = ? WHERE id = ?', [$stock, $variant]);

        return $variant;
    }

    private function sale(int $variant, int $quantity, int $unit, \DateTimeImmutable $at): void
    {
        $order = $this->create(Order::class, ['totalAmount' => (float) ($unit * $quantity)], function (Order $o, EntityManagerInterface $em) use ($at): void {
            $o->setOrderDate(\DateTime::createFromImmutable($at->setTimezone(new \DateTimeZone('UTC'))))->setStatus($em->find(StatusCommande::class, 3))->setOrderType($em->find(OrderType::class, 1))
                ->setOrderSource($em->find(OrderSource::class, 2))->setUserId($em->find(User::class, $this->userId));
        });
        $this->create(OrderItems::class, ['quantity' => $quantity, 'unitPrice' => (float) $unit, 'totalPrice' => (float) ($unit * $quantity)], fn (OrderItems $i, EntityManagerInterface $em) => $i->setOrderAssociated($em->find(Order::class, $order->getId()))->setProductVariant($em->find(ProductVariant::class, $variant)));
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
        $this->assertSame(200, $this->status(), $route . ' : ' . substr((string) $this->client->getResponse()->getContent(), 0, 300));

        return $this->body();
    }

    private function post(string $route, int $expected): void
    {
        $this->request('POST', $route);
        $this->assertSame($expected, $this->status(), $route . ' : ' . substr((string) $this->client->getResponse()->getContent(), 0, 400));
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
        $email = 'gestion-vignettes-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $em = $this->em();
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Gestion')->setLastname('Vignettes')->setRoles(['ROLE_ADMIN'])->setIsVerified(true)->setPassword('inutilisable');
        $em->persist($user);
        $em->flush();
        $this->userId = (int) $user->getId();
        $this->token = static::getContainer()->get('lexik_jwt_authentication.jwt_manager')->create($user);
        $this->endOfWrite();
    }
}
