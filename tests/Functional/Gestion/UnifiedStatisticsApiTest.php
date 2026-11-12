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
use App\Gestion\Services\GestionStatisticsService;
use App\Gestion\Services\GestionStockSnapshotService;
use App\Services\TenantEntityManagerProvider;
use App\Tests\Functional\Cache\CacheTestEntityFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Lot S1, statistiques unifiées (11/11/2026, `docs/statistiques-gestion.md`, contrat validé par l'application) : plages et plage précédente
 * (mois au même jour, année sans précédente), libellés, règles du résultat de période (hors taxes, retours retirés, annulées exclues, marge au
 * coût connu), source, comparaisons et écarts (points pour un taux), séries, granularités, valeur du stock et instantané quotidien, erreurs.
 * Données de mars 2006 et mars 2005 (aucune autre donnée datée là) ; commandes, articles et instantanés supprimés après.
 */
class UnifiedStatisticsApiTest extends WebTestCase
{
    use CacheTestEntityFactory;

    private KernelBrowser $client;
    private string $token = '';
    private int $userId = 0;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->clean();
        $this->login();
    }

    protected function tearDown(): void
    {
        $this->clean();
        parent::tearDown();
    }

    public function testRangesLabelsAndYearShift(): void
    {
        $s = static::getContainer()->get(GestionStatisticsService::class);
        $t = new \DateTimeImmutable('2026-11-11');
        $this->assertSame(['month', ['2026-11-01', '2026-11-11'], ['2026-10-01', '2026-10-11']], $s->ranges(['range' => 'month'], $t), 'depuis le début du mois contre le même nombre de jours du mois précédent');
        $this->assertSame(['2026-02-01', '2026-02-28'], $s->ranges([], new \DateTimeImmutable('2026-03-31'))[2], 'mois par défaut ; 31 mars → jusqu\'au 28 février');
        $this->assertSame(['year', ['2026-01-01', '2026-11-11'], null], $s->ranges(['range' => 'year'], $t), 'année : pas de plage précédente');
        $this->assertSame(['today', ['2026-11-11', '2026-11-11'], ['2026-11-10', '2026-11-10']], $s->ranges(['range' => 'today'], $t));
        $this->assertSame(['7d', ['2026-11-05', '2026-11-11'], ['2026-10-29', '2026-11-04']], $s->ranges(['range' => '7d'], $t));
        $this->assertSame(['custom', ['2006-03-01', '2006-03-10'], ['2006-02-19', '2006-02-28']], $s->ranges(['from' => '2006-03-01', 'to' => '2006-03-10'], $t), 'dates libres : même durée juste avant');
        $this->assertSame(['2023-02-28', '2025-03-01'], [GestionStatisticsService::shiftYears('2024-02-29', -1), GestionStatisticsService::shiftYears('2024-03-01', 1)]);
        $this->assertSame(['1-11 oct.', '1-11 nov. 2025', '28 sept. - 4 oct.', '28 déc. 2025 - 3 janv. 2026', '11 nov.', 'Oct 1-11', 'Nov 1-11, 2025'], [
            GestionStatisticsService::label('2026-10-01', '2026-10-11', 'fr', '2026-11-11'), GestionStatisticsService::label('2025-11-01', '2025-11-11', 'fr', '2026-11-11'),
            GestionStatisticsService::label('2026-09-28', '2026-10-04', 'fr', '2026-11-11'), GestionStatisticsService::label('2025-12-28', '2026-01-03', 'fr', '2026-11-11'),
            GestionStatisticsService::label('2026-11-11', '2026-11-11', 'fr', '2026-11-11'), GestionStatisticsService::label('2026-10-01', '2026-10-11', 'en', '2026-11-11'),
            GestionStatisticsService::label('2025-11-01', '2025-11-11', 'en', '2026-11-11'),
        ]);
    }

    public function testIndicatorsComparisonsSeriesAndStock(): void
    {
        $tag = bin2hex(random_bytes(2));
        $conn = $this->em()->getConnection();
        foreach ([['P', 2500, '1000'], ['U', 4000, 'NULL']] as [$letter, $price, $purchase]) {
            $conn->executeStatement("INSERT INTO product (id, name, price, isbestseller, quantity, created_at, slug, product_type, purchase_price) VALUES (nextval('product_id_seq'), :n, :p, false, 0, NOW(), :s, 'standard', $purchase)", ['n' => "S1 $letter", 'p' => $price, 's' => "s1-test-$letter-$tag"]);
        }
        $variant = fn (string $letter): int => (int) $this->create(ProductVariant::class, ['product' => $this->em()->find(Product::class, (int) $conn->fetchOne('SELECT id FROM product WHERE slug = ?', ["s1-test-$letter-$tag"]))])->getId();
        [$p, $u] = [$variant('P'), $variant('U')];
        // Plage 1-10 mars 2006 : caisse P × 2, web U × 1 (coût inconnu), caisse P × 1, retour caisse P × 1, annulée P × 4 ; précédente : P × 1 ; an dernier : P × 2
        $this->sale('2006-03-02', 1, 3, 2, [[$p, 2, 2500]]);
        $this->sale('2006-03-05', 1, 3, 1, [[$u, 1, 4000]]);
        $this->sale('2006-03-05', 1, 3, 2, [[$p, 1, 2500]]);
        $this->sale('2006-03-08', 2, 6, 2, [[$p, 1, 2500]]);
        $this->sale('2006-03-09', 1, 7, 2, [[$p, 4, 2500]]);
        $this->sale('2006-02-25', 1, 3, 2, [[$p, 1, 2500]]);
        $this->sale('2005-03-04', 1, 3, 2, [[$p, 2, 2500]]);
        $this->endOfWrite();

        $r = $this->get('/api/gestion/statistiques?from=2006-03-01&to=2006-03-10&locale=fr');
        $this->assertSame(['CAD', 'all', ['type' => 'custom', 'from' => '2006-03-01', 'to' => '2006-03-10', 'days' => 10, 'granularity' => 'day'], ['from' => '2006-02-19', 'to' => '2006-02-28'], ['from' => '2005-03-01', 'to' => '2005-03-10']],
            [$r['currency'], $r['source'], $r['range'], $r['previous'], $r['lastYear']]);
        $this->assertSame(['currentLabel' => '1-10 mars 2006', 'previousLabel' => '19-28 févr. 2006', 'lastYearLabel' => '1-10 mars 2005'], $r['comparison']);
        $i = $r['indicators'];
        $pick = fn (array $x) => [$x['value'], $x['previous'], $x['lastYear'], $x['changeVsPrevious'], $x['changeVsLastYear']];
        $this->assertEquals([9000, 2500, 5000, 260.0, 80.0], $pick($i['revenue']), 'ventes nettes des retours, hors taxes ; annulée exclue');
        $this->assertEquals([3000, 1500, 3000, 100.0, 0.0], $pick($i['grossMargin']), 'marge sur les ventes au coût connu : (9000 − 4000) − (2000 + 1000 − 1000)');
        $this->assertEquals([60.0, 60.0, 60.0, 0.0, 0.0, 'points'], [...$pick($i['grossMarginRate']), $i['grossMarginRate']['changeUnit']]);
        $this->assertEquals([3, 1, 1, 200.0, 200.0], $pick($i['orders']));
        $this->assertSame([3833, 2500, 5000], array_slice($pick($i['averageBasket']), 0, 3), 'ventes brutes hors taxes ÷ ventes, retours exclus');
        $this->assertEquals([21.74, 0.0, 0.0, 21.74, 21.74, ['amount' => 2500, 'orders' => 1]], [...$pick($i['returnRate']), $i['returnRate']['returns']], 'retours ÷ ventes brutes, écart en points');
        $this->assertSame(['money', 'percent'], [$i['revenue']['unit'], $i['revenue']['changeUnit']]);
        $this->assertCount(10, $i['revenue']['spark']);
        $this->assertSame([0, 5000, 0, 0, 6500], array_slice($i['revenue']['spark'], 0, 5));
        $this->assertSame(['value' => 0, 'previous' => 0, 'lastYear' => 0], array_intersect_key($r['otherRevenue']['rentals'], ['value' => 1, 'previous' => 1, 'lastYear' => 1]));
        $this->assertArrayNotHasKey('spark', $r['otherRevenue']['rentals'], 'autres revenus : sans courbe');
        $points = array_column($r['chart']['points'], null, 'key');
        $this->assertSame(['day', 10], [$r['chart']['granularity'], count($points)]);
        $this->assertSame(['revenue' => 5000, 'grossMargin' => 3000, 'orders' => 1], array_intersect_key($points['2006-03-02'], ['revenue' => 1, 'grossMargin' => 1, 'orders' => 1]));
        $this->assertSame(['revenue' => 5000, 'grossMargin' => 3000, 'orders' => 1], $points['2006-03-04']['lastYear'], 'l\'an dernier aligné sur les mêmes dates');
        $codes = array_column($r['caveats'], null, 'code');
        $this->assertSame([4000, true, false, false], [$codes['UNKNOWN_COST']['amount'] ?? null, isset($codes['STOCK_VALUE_NOW_ONLY']), isset($codes['PARTIAL_DAY']), isset($codes['NO_LAST_YEAR_DATA'])]);
        $this->assertIsInt($i['stockValue']['value']);
        $this->assertSame([null, null, null], [$i['stockValue']['previous'], $i['stockValue']['lastYear'], $i['stockValue']['changeVsPrevious']]);

        // Source : caisse seulement
        $pos = $this->get('/api/gestion/statistiques?from=2006-03-01&to=2006-03-10&source=pos')['indicators'];
        $this->assertSame([5000, 3000, 2, 3750], [$pos['revenue']['value'], $pos['grossMargin']['value'], $pos['orders']['value'], $pos['averageBasket']['value']]);

        // Instantanés du stock : comparaison possible aux dates de fin des plages
        $conn->executeStatement("INSERT INTO gestion_stock_snapshot (snapshot_date, sale_value_cents, cost_value_cents, created_at) VALUES ('2006-02-28', 100000, 40000, NOW()), ('2005-03-10', 80000, 30000, NOW())");
        $sv = $this->get('/api/gestion/statistiques?from=2006-03-01&to=2006-03-10');
        $this->assertSame([100000, 80000], [$sv['indicators']['stockValue']['previous'], $sv['indicators']['stockValue']['lastYear']]);
        $this->assertNotContains('STOCK_VALUE_NOW_ONLY', array_column($sv['caveats'], 'code'));

        // Granularités : semaine (lundi à dimanche, bornée à la plage), mois
        $w = $this->get('/api/gestion/statistiques?from=2006-01-01&to=2006-04-30')['chart'];
        $this->assertSame(['week', ['key' => '2005-W52', 'from' => '2006-01-01', 'to' => '2006-01-01'], ['key' => '2006-W01', 'from' => '2006-01-02', 'to' => '2006-01-08']],
            [$w['granularity'], array_intersect_key($w['points'][0], ['key' => 1, 'from' => 1, 'to' => 1]), array_intersect_key($w['points'][1], ['key' => 1, 'from' => 1, 'to' => 1])]);
        $mo = $this->get('/api/gestion/statistiques?from=2006-01-01&to=2006-07-31')['chart'];
        $this->assertSame(['month', ['2006-01', '2006-02', '2006-03', '2006-04', '2006-05', '2006-06', '2006-07'], 6500], [$mo['granularity'], array_column($mo['points'], 'key'), $mo['points'][2]['revenue'] - 2500]);

        // Jour du magasin : une vente à 21 h 30 (le lendemain en UTC) reste sur son jour
        $this->sale('2006-03-20', 1, 3, 2, [[$p, 1, 2500]], '21:30');
        $late = array_column($this->get('/api/gestion/statistiques?from=2006-03-20&to=2006-03-21')['chart']['points'], 'revenue', 'key');
        $this->assertSame(['2006-03-20' => 2500, '2006-03-21' => 0], $late);

        // Erreurs
        foreach (['range=pigeon' => ['range' => 'INVALID_RANGE'], 'source=fax' => ['source' => 'INVALID_SOURCE'], 'from=2006-02-30&to=2006-03-01' => ['from' => 'INVALID_DATE'],
            'from=2006-03-10&to=2006-03-01' => ['to' => 'BEFORE_START'], 'from=2005-01-01&to=2006-03-01' => ['to' => 'RANGE_TOO_LONG'], 'from=2006-03-01' => ['to' => 'REQUIRED']] as $q => $fields) {
            $this->request('GET', "/api/gestion/statistiques?$q");
            $this->assertSame([422, $fields], [$this->status(), $this->body()['fields'] ?? null], $q);
        }
        $this->request('GET', '/api/gestion/statistiques?periodId=999999999');
        $this->assertSame([404, 'PERIOD_NOT_FOUND'], [$this->status(), $this->body()['error']]);
    }

    public function testDailySnapshotIsTakenOnce(): void
    {
        $snapshots = static::getContainer()->get(GestionStockSnapshotService::class);
        $day = new \DateTimeImmutable('2007-06-15');
        $this->assertTrue($snapshots->take($day));
        $this->em()->getConnection()->executeStatement("UPDATE gestion_stock_snapshot SET sale_value_cents = 1 WHERE snapshot_date = '2007-06-15'");
        $this->assertFalse($snapshots->take($day), 'rejouer le passage ne change rien');
        $this->assertSame(['date' => '2007-06-15', 'saleValue' => 1], array_intersect_key($snapshots->at('2007-06-15'), ['date' => 1, 'saleValue' => 1]));
        $this->assertNull($snapshots->at('2007-06-16'));
    }

    /**
     * Lot JOUR (contrat validé le 13/11/2026, `docs/journee-gestion.md`) : GET /api/gestion/journee. Les ventes du jour sont celles des
     * statistiques unifiées (« aujourd'hui », tous canaux et par canal) ; la comparaison porte sur le même jour de semaine des 4 semaines
     * précédentes, chacun arrêté à la même heure ; « à expédier » est la liste de l'écran « À traiter » ; assistance null sans le module ;
     * encaissé, trésorerie et stock bas viennent des services existants.
     */
    public function testDayOverviewMatchesStatisticsDeskAndSameHourAverage(): void
    {
        $tag = bin2hex(random_bytes(2));
        $conn = $this->em()->getConnection();
        $conn->executeStatement("INSERT INTO product (id, name, price, isbestseller, quantity, created_at, slug, product_type, purchase_price) VALUES (nextval('product_id_seq'), :n, 2500, false, 0, NOW(), :s, 'standard', 1000)", ['n' => "Jour $tag", 's' => "s1-test-jour-$tag"]);
        $v = (int) $this->create(ProductVariant::class, ['product' => $this->em()->find(Product::class, (int) $conn->fetchOne('SELECT id FROM product WHERE slug = ?', ["s1-test-jour-$tag"]))])->getId();
        $tz = static::getContainer()->get(StoreClock::class)->timezone();
        $noon = (new \DateTimeImmutable('now', $tz))->setTime(12, 0);
        $day = $noon->format('Y-m-d');
        $service = static::getContainer()->get(\App\Gestion\Services\GestionDayService::class);
        $before = $service->overview($noon);

        // Aujourd'hui : caisse 2 × 2500, web 1 × 4000, application mobile 1 × 1000, retour caisse 1 × 2500, vente annulée (exclue)
        $this->sale($day, 1, 3, 2, [[$v, 2, 2500]], '09:00');
        $this->sale($day, 1, 3, 1, [[$v, 1, 4000]], '10:00');
        $this->sale($day, 1, 3, 3, [[$v, 1, 1000]], '10:30');
        $this->sale($day, 2, 3, 2, [[$v, 1, 2500]], '11:00');
        $this->sale($day, 1, 7, 2, [[$v, 4, 2500]], '11:30');
        $this->sale($noon->modify('-1 day')->format('Y-m-d'), 1, 3, 2, [[$v, 1, 7000]], '15:00'); // hier : hors de la journée
        // Les 4 mêmes jours de semaine précédents : 1000 avant midi (compté), 9000 après midi (hors de la comparaison « à la même heure »)
        foreach ([7, 14, 21, 28] as $d) {
            $past = $noon->modify("-$d days")->format('Y-m-d');
            $this->sale($past, 1, 3, 2, [[$v, 1, 1000]], '11:00');
            $this->sale($past, 1, 3, 2, [[$v, 1, 9000]], '13:00');
        }
        $this->endOfWrite();
        $this->em()->clear();
        $after = $service->overview($noon);
        $this->assertSame([$before['sales']['total'] + 5000 + 4000 + 1000 - 2500, $before['sales']['orders'] + 3], [$after['sales']['total'], $after['sales']['orders']], 'retour en négatif, annulée exclue');
        $channel = fn (array $o, string $c) => array_column($o['sales']['byChannel'], null, 'channel')[$c];
        $this->assertSame(['ecommerce', 'pos', 'mobile_app'], array_column($after['sales']['byChannel'], 'channel'), 'toujours les trois canaux');
        $this->assertSame([$channel($before, 'pos')['total'] + 2500, $channel($before, 'ecommerce')['total'] + 4000, $channel($before, 'mobile_app')['total'] + 1000],
            [$channel($after, 'pos')['total'], $channel($after, 'ecommerce')['total'], $channel($after, 'mobile_app')['total']]);
        $cmp = $after['sales']['vsSameWeekdayAverage'];
        $this->assertSame([$before['sales']['vsSameWeekdayAverage']['average'] + 1000, 4, '12:00'], [$cmp['average'], $cmp['weeks'], $cmp['asOf']], 'moyenne arrêtée à la même heure : les ventes de 13 h ne comptent pas');
        $this->assertSame(round(($after['sales']['total'] - $cmp['average']) / abs($cmp['average']) * 100, 1), $cmp['percent']);

        // Par la route : mêmes chiffres que les statistiques unifiées d'aujourd'hui, tous canaux et par canal
        $day = $this->get('/api/gestion/journee');
        $this->assertSame(['date', 'timezone', 'currency', 'generatedAt', 'sales', 'receipts', 'toShip', 'support', 'cash', 'stock', 'screens'], array_keys($day));
        $stats = fn (string $source) => $this->get("/api/gestion/statistiques?range=today&source=$source")['indicators'];
        $all = $stats('all');
        $this->assertSame([$all['revenue']['value'], $all['orders']['value']], [$day['sales']['total'], $day['sales']['orders']], '« aujourd\'hui » de S1 = Ma journée');
        foreach (['ecommerce', 'pos', 'mobile_app'] as $source) {
            $i = $stats($source);
            $this->assertSame([$i['revenue']['value'], $i['orders']['value']], [$channel($day, $source)['total'], $channel($day, $source)['orders']], $source);
        }
        $this->assertSame([$tz->getName(), $noon->format('Y-m-d')], [$day['timezone'], $day['date']]);
        $this->assertSame(['sales' => 'statistiques', 'toShip' => 'commandes.a_expedier', 'support' => 'assistance', 'cash' => 'tresorerie', 'stock' => 'stock.low'], $day['screens']);

        // Encaissé (taxes comprises), trésorerie et stock bas : les services existants
        $cash = static::getContainer()->get(\App\Gestion\Services\GestionCashService::class);
        $split = $cash->receiptsSplit($day['date'], $day['date']);
        $this->assertSame(['total' => $split['total'], 'byMethod' => [['method' => 'cash', 'total' => $split['cash']], ['method' => 'card', 'total' => $split['card']]]], $day['receipts']);
        $alert = $cash->alert();
        $this->assertSame($alert === null ? null : [(int) $alert['balance']['amount'], $alert['balance']['date'], (int) $alert['low14']['amount'], $alert['low14']['date'], $alert['status']],
            $day['cash'] === null ? null : [$day['cash']['balance'], $day['cash']['balanceDate'], $day['cash']['lowestIn14Days']['amount'], $day['cash']['lowestIn14Days']['date'], $day['cash']['state']]);
        $this->assertSame(count(static::getContainer()->get(\App\Gestion\Services\HomeTilesService::class)->lowStock()['all']), $day['stock']['lowCount']);

        // À expédier : la liste de l'écran « À traiter » ; une commande web payée avec adresse, non expédiée, s'y ajoute
        $desk = static::getContainer()->get(\App\Services\OrderService\OrderDeskService::class);
        $this->assertSame($desk->todo(500)['counts']['toShip'], $day['toShip']['count']);
        $address = (int) $conn->fetchOne("INSERT INTO adress (id, firstname, lastname, fullname, address, phone, city, codepostal, country) VALUES (nextval('adress_id_seq'), 'J', 'Our', 'J Our', '1 rue', '0', 'Ville', 'H1A', 'CA') RETURNING id");
        $old = (new \DateTimeImmutable('2001-01-01 10:00:00', new \DateTimeZone('UTC')));
        $conn->executeStatement("UPDATE \"order\" SET shipping_adress_id = ?, order_date = ? WHERE id = (SELECT MAX(id) FROM \"order\" WHERE reference = 'S1-TEST' AND order_source_id = 1 AND status_id = 3)", [$address, $old->format('Y-m-d H:i:s')]);
        // une vente de caisse payée avec une adresse n'est pas « à expédier » (hors caisse seulement)
        $conn->executeStatement("UPDATE \"order\" SET shipping_adress_id = ? WHERE id = (SELECT MAX(id) FROM \"order\" WHERE reference = 'S1-TEST' AND order_source_id = 2 AND status_id = 3)", [$address]);
        $ship = $this->get('/api/gestion/journee')['toShip'];
        $this->assertSame([$day['toShip']['count'] + 1, $old->format(\DateTimeInterface::ATOM), $desk->todo(500)['counts']['toShip']], [$ship['count'], $ship['oldestAt'], $ship['count']], 'compte et plus ancienne commande');
        $conn->executeStatement("UPDATE \"order\" SET shipping_adress_id = NULL WHERE shipping_adress_id = ?", [$address]);
        $conn->executeStatement('DELETE FROM adress WHERE id = ?', [$address]);

        // Assistance : null sans le module ; avec lui, demandes ouvertes et en retard
        $flags = static::getContainer()->get(\App\Services\TenantCacheService::class);
        $conn->executeStatement('UPDATE entreprise SET is_support_active = false');
        $flags->delete('module_flags');
        $this->assertNull($this->get('/api/gestion/journee')['support'], 'module Assistance non accordé');
        $conn->executeStatement('UPDATE entreprise SET is_support_active = true');
        $flags->delete('module_flags');
        try {
            $support = $this->get('/api/gestion/journee')['support'];
            $this->assertSame(['open', 'overdue'], array_keys($support));
            foreach ([['open', "NOW() - INTERVAL '1 day'"], ['new', "NOW() + INTERVAL '1 day'"], ['closed', "NOW() - INTERVAL '1 day'"]] as $n => [$status, $due]) {
                $conn->executeStatement("INSERT INTO support_ticket (id, reference, subject, status, channel, due_at, created_at, updated_at) VALUES (nextval('support_ticket_id_seq'), ?, 'Jour', ?, 'agent', $due, NOW(), NOW())", ["JOUR-$tag-$n", $status]);
            }
            $this->assertSame(['open' => $support['open'] + 2, 'overdue' => $support['overdue'] + 1], $this->get('/api/gestion/journee')['support'], 'ouvertes : ni fermées ; en retard : échéance passée');
        } finally {
            $conn->executeStatement('DELETE FROM support_ticket WHERE reference LIKE ?', ["JOUR-$tag-%"]);
            $conn->executeStatement('UPDATE entreprise SET is_support_active = false');
            $flags->delete('module_flags');
        }

        // Garde : administrateur, module de gestion
        $this->client->request('GET', '/api/gestion/journee', [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'HTTP_ACCEPT' => 'application/json']);
        $this->assertSame(401, $this->status());
        $this->assertSame(\App\Services\FeatureAccess\ModuleAccessService::MANAGEMENT, \App\Services\FeatureAccess\ModuleAccessService::moduleForPath('/api/gestion/journee'));
    }

    // ----- helpers -----

    /** Commande datée à midi (magasin) : type, statut, source, lignes [[variante, quantité, prix unitaire net]] */
    private function sale(string $day, int $type, int $status, int $source, array $lines, string $time = '12:00'): void
    {
        $tz = static::getContainer()->get(StoreClock::class)->timezone();
        $at = (new \DateTimeImmutable("$day $time", $tz))->setTimezone(new \DateTimeZone('UTC'));
        $order = $this->create(Order::class, ['totalAmount' => 1.0, 'reference' => 'S1-TEST'], function (Order $o, EntityManagerInterface $em) use ($at, $type, $status, $source): void {
            $o->setOrderDate(\DateTime::createFromImmutable($at))->setStatus($em->find(StatusCommande::class, $status))->setOrderType($em->find(OrderType::class, $type))
                ->setOrderSource($em->find(OrderSource::class, $source))->setUserId($em->find(User::class, $this->userId));
        });
        foreach ($lines as [$variantId, $qty, $unit]) {
            $this->create(OrderItems::class, ['quantity' => $qty, 'unitPrice' => (float) $unit, 'totalPrice' => (float) ($unit * $qty)], function (OrderItems $i, EntityManagerInterface $em) use ($order, $variantId): void {
                $i->setOrderAssociated($em->find(Order::class, $order->getId()))->setProductVariant($em->find(ProductVariant::class, $variantId));
            });
        }
    }

    private function clean(): void
    {
        $conn = $this->em()->getConnection();
        $conn->executeStatement("DELETE FROM order_items WHERE order_associated_id IN (SELECT id FROM \"order\" WHERE reference = 'S1-TEST')");
        $conn->executeStatement("DELETE FROM \"order\" WHERE reference = 'S1-TEST'");
        $conn->executeStatement("DELETE FROM product_variant WHERE product_id IN (SELECT id FROM product WHERE slug LIKE 's1-test-%')");
        $conn->executeStatement("DELETE FROM product WHERE slug LIKE 's1-test-%'");
        $conn->executeStatement('DELETE FROM gestion_stock_snapshot');
    }

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

    private function send(string $method, string $route, array $body, int $expected): array
    {
        $this->request($method, $route, $body);
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
        $email = 'gestion-s1-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $em = $this->em();
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Gestion')->setLastname('S1')->setRoles(['ROLE_ADMIN'])->setIsVerified(true)->setPassword('inutilisable');
        $em->persist($user);
        $em->flush();
        $this->userId = (int) $user->getId();
        $this->token = static::getContainer()->get('lexik_jwt_authentication.jwt_manager')->create($user);
        $this->endOfWrite();
    }
}
