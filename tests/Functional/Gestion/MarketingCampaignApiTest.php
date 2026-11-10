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
use App\Services\CaisseService\StoreClock;
use App\Services\GestionService\PeriodCalendar;
use App\Services\TenantEntityManagerProvider;
use App\Tests\Functional\Cache\CacheTestEntityFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Volet publicité (lot A1, 10/11/2026, `docs/publicite-gestion.md`, contrat validé par l'application) : campagnes (validation, devise de la régie
 * et taux, période fermée, filtres, modification, suppression, 404 à code), dépense de la période dans expenses.other, rentabilité perçue
 * (fenêtre, référence juste avant, cible, regroupement des campagnes qui se chevauchent, gain pour 1 $, remarques), facture de régie lue par IA
 * confirmée en campagne. Ventes datées d'aujourd'hui et d'hier, sur des articles propres au test (périmètre ciblé : base partagée).
 */
class MarketingCampaignApiTest extends WebTestCase
{
    use CacheTestEntityFactory;

    private KernelBrowser $client;
    private string $token = '';
    private int $userId = 0;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->login();
        // Seul ce test écrit des campagnes : on repart de zéro, sinon une campagne d'un autre test aux mêmes dates rejoindrait le groupe
        $this->em()->getConnection()->executeStatement('UPDATE gestion_document SET campaign_id = NULL WHERE campaign_id IS NOT NULL');
        $this->em()->getConnection()->executeStatement('DELETE FROM marketing_campaign');
    }

    public function testCampaignsCurrencyPeriodGuardAndPeriodExpense(): void
    {
        $tag = bin2hex(random_bytes(2));
        $calendar = static::getContainer()->get(PeriodCalendar::class);
        $today = $calendar->today();
        $current = $calendar->current();

        // Validations : canal, dates, devise inconnue, taux manquant
        $this->request('POST', '/api/gestion/campagnes', ['name' => "X $tag", 'channel' => 'pigeon', 'startDate' => $today->format('Y-m-d'), 'endDate' => $today->modify('-1 day')->format('Y-m-d'), 'amount' => 100, 'currency' => 'XXX']);
        $this->assertSame([422, 'INVALID_CHANNEL', 'BEFORE_START', 'INVALID_CURRENCY'], [$this->status(), $this->body()['fields']['channel'] ?? null, $this->body()['fields']['endDate'] ?? null, $this->body()['fields']['currency'] ?? null]);
        $this->request('POST', '/api/gestion/campagnes', ['name' => "X $tag", 'channel' => 'facebook', 'startDate' => $today->format('Y-m-d'), 'endDate' => $today->format('Y-m-d'), 'amount' => 100, 'currency' => 'USD']);
        $this->assertSame([422, ['exchangeRate' => 'EXCHANGE_RATE_REQUIRED']], [$this->status(), $this->body()['fields']]);
        // Période fermée : refus
        $old = $today->modify('-200 days')->format('Y-m-d');
        $this->request('POST', '/api/gestion/campagnes', ['name' => "X $tag", 'channel' => 'google', 'startDate' => $old, 'endDate' => $old, 'amount' => 100]);
        $this->assertSame([409, 'PERIOD_CLOSED'], [$this->status(), $this->body()['error']]);

        // Création en USD : montant converti, période de la date de début
        $c = $this->post('/api/gestion/campagnes', ['name' => "Soldes $tag", 'channel' => 'Facebook', 'startDate' => $today->format('Y-m-d'), 'endDate' => $today->format('Y-m-d'), 'amount' => 10000, 'currency' => 'usd', 'exchangeRate' => 1.35, 'notes' => 'Ciblage Montréal'], 201);
        $this->assertSame(['facebook', 10000, 'USD', 1.35, 13500, 'CAD', $current->getId()], [$c['channel'], $c['amount'], $c['currency'], $c['exchangeRate'], $c['amountSite'], $c['siteCurrency'], $c['period']['id']]);
        $local = $this->post('/api/gestion/campagnes', ['name' => "Affiches $tag", 'channel' => 'print', 'startDate' => $today->format('Y-m-d'), 'endDate' => $today->format('Y-m-d'), 'amount' => 2500, 'exchangeRate' => 9], 201);
        $this->assertSame(['CAD', null, 2500], [$local['currency'], $local['exchangeRate'], $local['amountSite']], 'devise du site : taux ignoré');

        // Liste filtrée, lecture, modification, 404 à code
        $this->request('GET', '/api/gestion/campagnes?channel=facebook&from=' . $today->format('Y-m-d'));
        $this->assertSame(200, $this->status());
        $this->assertContains($c['id'], array_column($this->body(), 'id'));
        $this->assertNotContains($local['id'], array_column($this->body(), 'id'), 'filtre par canal');
        $this->assertGreaterThanOrEqual(1, (int) $this->client->getResponse()->headers->get('X-Total-Count'));
        $this->assertSame(20000, $this->patch('/api/gestion/campagnes/' . $c['id'], ['amount' => 20000])['amount']);
        $this->assertSame(27000, $this->get('/api/gestion/campagnes/' . $c['id'])['amountSite']);
        $this->request('GET', '/api/gestion/campagnes/999999999');
        $this->assertSame([404, 'CAMPAIGN_NOT_FOUND'], [$this->status(), $this->body()['error']]);

        // Résultat de la période : la publicité entre dans expenses.other et le total
        $result = $this->get('/api/periodes/' . $current->getId() . '/resultat');
        $ads = array_values(array_filter($result['expenses']['other'], fn ($o) => $o['kind'] === 'advertising'))[0] ?? null;
        $this->assertNotNull($ads, 'ligne Publicité');
        $mine = array_sum(array_column(array_filter($ads['campaigns'], fn ($x) => in_array($x['id'], [$c['id'], $local['id']], true)), 'amount'));
        $this->assertSame([27000 + 2500, 'Publicité'], [$mine, $ads['label']]);
        $this->assertSame($result['expenses']['shipping'] + $result['expenses']['notes'] + $ads['amount'], $result['expenses']['total']);

        // Suppression
        $this->request('DELETE', '/api/gestion/campagnes/' . $local['id']);
        $this->assertSame(204, $this->status());
        $this->request('GET', '/api/gestion/campagnes/' . $local['id']);
        $this->assertSame(404, $this->status());
    }

    public function testProfitabilityBeforeAfterGroupAndTarget(): void
    {
        $tag = bin2hex(random_bytes(2));
        $today = static::getContainer()->get(PeriodCalendar::class)->today();
        $noon = (new \DateTimeImmutable('now', static::getContainer()->get(StoreClock::class)->timezone()))->setTime(12, 0);
        // Deux articles propres au test, achetés il y a 30 jours au coût de 1000
        [$robe, $robeVariant] = $this->product("Robe $tag", 3000);
        [$sac, $sacVariant] = $this->product("Sac $tag", 2000);
        $conn = $this->em()->getConnection();
        $conn->executeStatement("INSERT INTO commande (id, budget, date, name, is_closed) VALUES (nextval('commande_id_seq'), 10000, :d, :n, false)", ['d' => $noon->modify('-30 days')->format('Y-m-d H:i:s'), 'n' => "Achat pub $tag"]);
        $commande = (int) $conn->fetchOne('SELECT id FROM commande WHERE name = ?', ["Achat pub $tag"]);
        foreach ([$robe, $sac] as $p) {
            $conn->executeStatement("INSERT INTO commande_line (commande_id, product_id, quantity_ordered, quantity_received, unit_cost_cents, attached_at, source, created_at, updated_at) VALUES (?, ?, 10, 10, 1000, NOW() - INTERVAL '30 days', 'manual', NOW(), NOW())", [$commande, $p]);
        }
        // Hier : 1 robe ; aujourd'hui : 2 robes et 1 sac
        $this->sale($robeVariant, 1, 3000, $noon->modify('-1 day'));
        $this->sale($robeVariant, 2, 3000, $noon);
        $this->sale($sacVariant, 1, 2000, $noon);
        $this->endOfWrite();

        // Campagne ciblée sur la robe, en USD : 100,00 USD × 1,35 = 135,00 CAD
        $c = $this->post('/api/gestion/campagnes', ['name' => "Robe $tag", 'channel' => 'instagram', 'startDate' => $today->format('Y-m-d'), 'endDate' => $today->modify('+6 days')->format('Y-m-d'), 'amount' => 10000, 'currency' => 'USD', 'exchangeRate' => 1.35, 'productIds' => [$robe]], 201);
        $p = $this->get('/api/gestion/campagnes/' . $c['id'] . '/rentabilite');
        $this->assertSame([true, 'before_after', 'CAD', false, 1, 'targeted', [$robe]], [$p['estimate'], $p['method'], $p['currency'], $p['window']['complete'], $p['window']['days'], $p['scope']['kind'], $p['scope']['productIds']], 'fenêtre bornée à aujourd\'hui, cible la robe');
        $this->assertSame(['revenue' => 6000, 'grossMargin' => 4000, 'orders' => 1, 'items' => 2, 'unknownCostRevenue' => 0], $p['during']);
        $this->assertSame(['revenue' => 3000, 'grossMargin' => 2000, 'orders' => 1, 'items' => 1, 'unknownCostRevenue' => 0], $p['before']);
        $this->assertSame(['revenue' => 3000, 'grossMargin' => 2000], $p['uplift']);
        $this->assertSame(['revenue' => 0.22, 'grossMargin' => 0.15], $p['gainPerDollar'], 'hausse ÷ 13500 (montant du groupe en devise du site)');
        $this->assertSame([$today->modify('-1 day')->format('Y-m-d'), $today->modify('-1 day')->format('Y-m-d')], [$p['reference']['from'], $p['reference']['to']], 'référence : même durée juste avant');
        $this->assertContains('Campagne en cours : chiffres provisoires.', $p['caveats']);
        $this->assertContains('Peu de ventes avant la campagne : la comparaison est fragile.', $p['caveats']);
        $this->assertArrayHasKey('lastYear', $p);

        // Deuxième campagne qui chevauche, ciblée sur le sac : mesurées ensemble, cible = union, montant = somme
        $d = $this->post('/api/gestion/campagnes', ['name' => "Sac $tag", 'channel' => 'google', 'startDate' => $today->format('Y-m-d'), 'endDate' => $today->format('Y-m-d'), 'amount' => 6500, 'productIds' => [$sac]], 201);
        $g = $this->get('/api/gestion/campagnes/' . $d['id'] . '/rentabilite');
        $this->assertSame([true, 13500 + 6500, [$c['id'], $d['id']]], [$g['group']['shared'], $g['group']['amount'], $g['group']['campaignIds']]);
        $this->assertSame([$robe, $sac], $g['scope']['productIds']);
        $this->assertSame(8000, $g['during']['revenue'], 'robes 6000 + sac 2000');
        $this->assertSame($g['group'], $this->get('/api/gestion/campagnes/' . $c['id'] . '/rentabilite')['group'], 'même résultat pour chaque campagne du groupe');
        $this->assertNotEmpty(array_filter($g['caveats'], fn ($x) => str_starts_with($x, 'Mesurée avec 1 autre')));

        // Campagne pas encore commencée (sans chevauchement, si le calendrier couvre sa date) : rien à mesurer, aucune durée négative
        $start = $today->modify('+8 days');
        if (static::getContainer()->get(PeriodCalendar::class)->findByDate($start) !== null) {
            $f = $this->post('/api/gestion/campagnes', ['name' => "Futur $tag", 'channel' => 'email', 'startDate' => $start->format('Y-m-d'), 'endDate' => $start->modify('+1 day')->format('Y-m-d'), 'amount' => 1000], 201);
            $q = $this->get('/api/gestion/campagnes/' . $f['id'] . '/rentabilite');
            $this->assertSame([0, false, 0, 0, null], [$q['window']['days'], $q['window']['complete'], $q['during']['revenue'], $q['before']['revenue'], $q['lastYear']]);
            $this->assertContains('Campagne pas encore commencée : rien à mesurer pour l\'instant.', $q['caveats']);
        }
    }

    public function testVendorInvoiceConfirmedAsCampaign(): void
    {
        $tag = bin2hex(random_bytes(2));
        $today = static::getContainer()->get(PeriodCalendar::class)->today();
        $conn = $this->em()->getConnection();
        $conn->executeStatement("UPDATE gestion_document SET status = 'failed' WHERE status = 'reading'");
        $conn->executeStatement("INSERT INTO gestion_document (type, status, locale, files, extraction, created_at, expires_at) VALUES ('note', 'extracted', 'fr', '{\"pages\": []}', :x, NOW(), NOW() + INTERVAL '30 days')", ['x' => json_encode(['amount' => 5000, 'merchant' => "Meta $tag", 'date' => $today->format('Y-m-d')])]);
        $docId = (int) $conn->fetchOne("SELECT MAX(id) FROM gestion_document WHERE extraction::text LIKE ?", ['%Meta ' . $tag . '%']);
        $notesBefore = (int) $conn->fetchOne('SELECT COUNT(*) FROM note_de_frais');
        $this->endOfWrite();
        $r = $this->post("/api/gestion/documents/$docId/confirmer", ['as' => 'campaign', 'name' => "Meta $tag", 'channel' => 'facebook', 'startDate' => $today->format('Y-m-d'), 'endDate' => $today->format('Y-m-d'), 'amount' => 5000, 'currency' => 'USD', 'exchangeRate' => 1.4], 201);
        $this->assertSame([7000, $docId, 'confirmed', $r['campaign']['id']], [$r['campaign']['amountSite'], $r['campaign']['document']['id'], $r['document']['status'], $r['document']['campaignId']]);
        $this->assertSame($notesBefore, (int) $conn->fetchOne('SELECT COUNT(*) FROM note_de_frais'), 'aucune note de frais : la facture devient une campagne');
        // Suppression de la campagne : le justificatif est libéré puis purgé
        $this->request('DELETE', '/api/gestion/campagnes/' . $r['campaign']['id']);
        $this->assertSame(204, $this->status());
        $this->get('/api/gestion/documents');
        $this->assertSame(0, (int) $conn->fetchOne('SELECT COUNT(*) FROM gestion_document WHERE id = ?', [$docId]), 'justificatif purgé avec sa campagne');
    }

    // ----- fabrique -----

    /** @return array{0: int, 1: int} article et sa variante unique */
    private function product(string $name, int $price): array
    {
        $conn = $this->em()->getConnection();
        $slug = 'a1-' . bin2hex(random_bytes(4));
        $conn->executeStatement("INSERT INTO product (id, name, price, isbestseller, quantity, created_at, slug, product_type) VALUES (nextval('product_id_seq'), :n, :p, false, 20, NOW(), :s, 'standard')", ['n' => $name, 'p' => $price, 's' => $slug]);
        $id = (int) $conn->fetchOne('SELECT id FROM product WHERE slug = ?', [$slug]);
        $variant = (int) $this->create(ProductVariant::class, ['product' => $this->em()->find(Product::class, $id)])->getId();

        return [$id, $variant];
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

    private function post(string $route, array $body, int $expected): array
    {
        $this->request('POST', $route, $body);
        $this->assertSame($expected, $this->status(), $route . ' : ' . substr((string) $this->client->getResponse()->getContent(), 0, 400));

        return $this->body();
    }

    private function patch(string $route, array $body): array
    {
        $this->request('PATCH', $route, $body);
        $this->assertSame(200, $this->status(), $route . ' : ' . substr((string) $this->client->getResponse()->getContent(), 0, 300));

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
        $email = 'gestion-a1-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $em = $this->em();
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Gestion')->setLastname('A1')->setRoles(['ROLE_ADMIN'])->setIsVerified(true)->setPassword('inutilisable');
        $em->persist($user);
        $em->flush();
        $this->userId = (int) $user->getId();
        $this->token = static::getContainer()->get('lexik_jwt_authentication.jwt_manager')->create($user);
        $this->endOfWrite();
    }
}
