<?php

namespace App\Tests\Functional\Gestion;

use App\Entity\Order;
use App\Entity\OrderSource;
use App\Entity\OrderType;
use App\Entity\StatusCommande;
use App\Entity\User;
use App\EventSubscriber\CacheInvalidationSubscriber;
use App\Services\CaisseService\StoreClock;
use App\Gestion\Services\GestionCashService;
use App\Gestion\Services\GestionNotificationService;
use App\Gestion\Services\PeriodCalendar;
use App\Services\TenantEntityManagerProvider;
use App\Tests\Fake\FakePushSender;
use App\Tests\Functional\Cache\CacheTestEntityFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Lot B1b-1, trésorerie saisie (11/11/2026, `docs/tresorerie-gestion.md`, contrat validé par l'application) : soldes (validation, une
 * ligne par date remplacée, date prise, 404), mouvements prévus (validation, libellés, filtre, récurrences au 31 et au 29 février,
 * salaires regroupés pour un lecteur sans ROLE_ADMIN), réglages, projection jour par jour (mouvements, dépenses saisies à leur date,
 * reprise exclue, encaissements réels des jours passés puis moyenne des 30 jours, point bas, statut, série d'alerte), vignettes
 * (premier pas, trésorerie basse), notification cash_critical (dès le seuil, pas de répétition sauf aggravation).
 * Les calculs exacts se font dans un futur lointain (base partagée : aucune autre donnée datée là) ; tables de trésorerie vidées avant et après.
 */
class CashApiTest extends WebTestCase
{
    use CacheTestEntityFactory;

    private const TABLES = ['gestion_cash_balance', 'gestion_cash_movement', 'gestion_cash_setting', 'push_device', 'notification_log', 'notification_preference', 'gestion_tile_dismissal'];

    private KernelBrowser $client;
    private string $token = '';
    private int $userId = 0;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->clean();
        FakePushSender::reset();
        $this->login();
    }

    protected function tearDown(): void
    {
        $this->clean();
        parent::tearDown();
    }

    public function testBalancesMovementsSettingsAndTiles(): void
    {
        $calendar = static::getContainer()->get(PeriodCalendar::class);
        $today = $calendar->today();
        $d = fn (int $n) => $today->modify("$n days")->format('Y-m-d');

        // Sans solde : rien à projeter ; premier pas selon l'état du site (un mouvement prévu ou une période en cours)
        $p0 = $this->get('/api/gestion/tresorerie');
        $this->assertSame(['no_balance', null, null, null, 'projection', ['threshold' => 0, 'includeEstimatedSales' => false, 'tolerance' => 100], 'CAD'], [$p0['status'], $p0['balance'], $p0['projection'], $p0['projectionWithSales'], $p0['alertSeries'], $p0['settings'], $p0['currency']]);
        $this->assertSame([30, true], [$p0['estimatedDailyReceipts']['windowDays'], $p0['estimatedDailyReceipts']['estimated']]);
        $periodNow = $calendar->findByDate($today) !== null;
        $this->assertSame($periodNow, $this->tile('cash-first-balance') !== null, 'premier pas seulement avec une période en cours ou un mouvement');

        // Validation de la projection
        foreach (['days=abc' => 'MUST_BE_INTEGER', 'days=13' => 'OUT_OF_RANGE', 'days=366' => 'OUT_OF_RANGE'] as $q => $code) {
            $this->request('GET', "/api/gestion/tresorerie?$q");
            $this->assertSame([422, ['days' => $code]], [$this->status(), $this->body()['fields']], $q);
        }

        // Mouvements prévus : validation, libellés, prochaine date
        $this->request('POST', '/api/gestion/tresorerie/mouvements', ['label' => '', 'category' => 'pigeon', 'direction' => 'sideways', 'amount' => 0, 'startDate' => $d(0), 'recurrence' => 'daily']);
        $this->assertSame([422, ['label' => 'REQUIRED', 'category' => 'INVALID_CATEGORY', 'direction' => 'INVALID_DIRECTION', 'recurrence' => 'INVALID_RECURRENCE', 'amount' => 'MUST_BE_POSITIVE']], [$this->status(), $this->body()['fields']]);
        $this->request('POST', '/api/gestion/tresorerie/mouvements', ['label' => 'Loyer', 'category' => 'rent', 'amount' => 1.5, 'startDate' => $d(5), 'recurrence' => 'monthly', 'endDate' => $d(1)]);
        $this->assertSame(['amount' => 'MUST_BE_INTEGER_CENTS', 'endDate' => 'BEFORE_START'], $this->body()['fields']);
        $this->request('POST', '/api/gestion/tresorerie/mouvements', ['label' => 'Loyer']);
        $this->assertSame(['amount' => 'REQUIRED', 'startDate' => 'REQUIRED', 'category' => 'REQUIRED'], $this->body()['fields']);
        $rent = $this->send('POST', '/api/gestion/tresorerie/mouvements', ['label' => 'Loyer boutique', 'category' => 'RENT', 'amount' => 150000, 'date' => $d(3), 'recurrence' => 'monthly'], 201);
        $this->assertSame(['rent', 'Loyer', 'out', 150000, $d(3), 'monthly', null, $d(3)], [$rent['category'], $rent['categoryLabel'], $rent['direction'], $rent['amount'], $rent['startDate'], $rent['recurrence'], $rent['endDate'], $rent['nextDate']]);
        $this->assertNotNull($this->tile('cash-first-balance'), 'un mouvement prévu sans solde : premier pas');
        $once = $this->send('POST', '/api/gestion/tresorerie/mouvements', ['label' => 'Remboursement TPS', 'category' => 'tax', 'direction' => 'in', 'amount' => 9000, 'startDate' => $d(-10), 'endDate' => $d(30)], 201);
        $this->assertSame([null, null], [$once['endDate'], $once['nextDate']], 'ponctuel : pas de fin, passé : plus de prochaine date');
        $salary = $this->send('POST', '/api/gestion/tresorerie/mouvements', ['label' => 'Paie de Julie', 'category' => 'salaries', 'amount' => 120000, 'startDate' => $d(-14), 'recurrence' => 'weekly'], 201);
        $this->assertSame($d(0), $salary['nextDate']);
        $upd = $this->send('PATCH', '/api/gestion/tresorerie/mouvements/' . $rent['id'], ['amount' => 160000, 'endDate' => $d(100)], 200);
        $this->assertSame([160000, $d(100), 'Loyer boutique'], [$upd['amount'], $upd['endDate'], $upd['label']]);
        $this->assertSame(['Rent'], array_column($this->get('/api/gestion/tresorerie/mouvements?category=rent&locale=en'), 'categoryLabel'));
        $this->request('GET', '/api/gestion/tresorerie/mouvements?category=pigeon');
        $this->assertSame([422, ['category' => 'INVALID_CATEGORY']], [$this->status(), $this->body()['fields']]);
        // Administrateur : chaque salaire ; lecteur sans ROLE_ADMIN : un total des 30 prochains jours, sans libellé individuel
        $all = $this->get('/api/gestion/tresorerie/mouvements');
        $this->assertContains('Paie de Julie', array_column($all, 'label'));
        $grouped = static::getContainer()->get(GestionCashService::class)->movements(null, 'fr', false, $today);
        $this->assertNotContains('Paie de Julie', array_column($grouped, 'label'));
        $row = array_values(array_filter($grouped, fn ($m) => $m['category'] === 'salaries'))[0];
        $weeks = count(GestionCashService::occurrences($d(-14), 'weekly', null, $d(0), $d(29)));
        $this->assertSame([null, true, 'Salaires', 120000 * $weeks, 1], [$row['id'], $row['grouped'], $row['label'], $row['amount'], $row['count']]);
        $this->assertCount(3, $grouped);
        $this->request('DELETE', '/api/gestion/tresorerie/mouvements/' . $once['id']);
        $this->assertSame(204, $this->status());
        $this->request('DELETE', '/api/gestion/tresorerie/mouvements/' . $once['id']);
        $this->assertSame([404, 'MOVEMENT_NOT_FOUND'], [$this->status(), $this->body()['error']]);

        // Soldes : validation, une ligne par date remplacée, date prise
        $this->request('POST', '/api/gestion/tresorerie/soldes', ['date' => $d(1), 'amount' => '12']);
        $this->assertSame([422, ['amount' => 'MUST_BE_INTEGER_CENTS', 'date' => 'DATE_IN_FUTURE']], [$this->status(), $this->body()['fields']]);
        $this->request('POST', '/api/gestion/tresorerie/soldes', []);
        $this->assertSame(['date' => 'REQUIRED', 'amount' => 'REQUIRED'], $this->body()['fields']);
        $b1 = $this->send('POST', '/api/gestion/tresorerie/soldes', ['date' => $d(-1), 'amount' => -5000, 'note' => 'Découvert'], 201);
        $this->assertSame([$d(-1), -5000, 'Découvert', false, 1], [$b1['date'], $b1['amount'], $b1['note'], $b1['replaced'], $b1['ageDays']]);
        $b1bis = $this->send('POST', '/api/gestion/tresorerie/soldes', ['date' => $d(-1), 'amount' => 20000], 200);
        $this->assertSame([$b1['id'], 20000, null, true], [$b1bis['id'], $b1bis['amount'], $b1bis['note'], $b1bis['replaced']], 'même date : remplacée');
        $b2 = $this->send('POST', '/api/gestion/tresorerie/soldes', ['date' => $d(0), 'amount' => 3000000], 201);
        $this->assertSame([$d(0), $d(-1)], array_column($this->get('/api/gestion/tresorerie/soldes'), 'date'), 'du plus récent au plus ancien');
        $this->request('PATCH', '/api/gestion/tresorerie/soldes/' . $b2['id'], ['date' => $d(-1)]);
        $this->assertSame([409, 'BALANCE_DATE_TAKEN', $b1['id']], [$this->status(), $this->body()['error'], $this->body()['balanceId']]);
        $this->assertSame(3500000, $this->send('PATCH', '/api/gestion/tresorerie/soldes/' . $b2['id'], ['amount' => 3500000], 200)['amount']);
        $this->request('DELETE', '/api/gestion/tresorerie/soldes/' . $b1['id']);
        $this->assertSame(204, $this->status());
        $this->request('PATCH', '/api/gestion/tresorerie/soldes/' . $b1['id'], ['amount' => 1]);
        $this->assertSame([404, 'BALANCE_NOT_FOUND'], [$this->status(), $this->body()['error']]);
        $this->assertNull($this->tile('cash-first-balance'), 'un solde saisi : plus de premier pas');

        // Réglages
        $this->assertSame(['threshold' => 0, 'includeEstimatedSales' => false, 'tolerance' => 100], $this->get('/api/gestion/tresorerie/reglages'));
        $this->request('PUT', '/api/gestion/tresorerie/reglages', ['threshold' => 'beaucoup', 'includeEstimatedSales' => 'oui']);
        $this->assertSame([422, ['threshold' => 'MUST_BE_INTEGER_CENTS', 'includeEstimatedSales' => 'MUST_BE_BOOLEAN']], [$this->status(), $this->body()['fields']]);
        $this->assertSame(['threshold' => 900000000, 'includeEstimatedSales' => false, 'tolerance' => 100], $this->send('PUT', '/api/gestion/tresorerie/reglages', ['threshold' => 900000000], 200));
        $this->assertSame(['threshold' => 900000000, 'includeEstimatedSales' => true, 'tolerance' => 100], $this->send('PUT', '/api/gestion/tresorerie/reglages', ['includeEstimatedSales' => true], 200), 'champ absent : gardé');
        $this->send('PUT', '/api/gestion/tresorerie/reglages', ['includeEstimatedSales' => false], 200);

        // Projection réelle : forme, longueur, série d'alerte, statut cohérent avec le point bas ; vignette au-dessus du stock bas
        $p = $this->get('/api/gestion/tresorerie?days=30&locale=en');
        $this->assertCount(30, $p['projection']['days'], 'jour du solde (aujourd\'hui) + 29 jours');
        $this->assertSame([$d(0), true, 3500000], [$p['projection']['days'][0]['date'], $p['projection']['days'][0]['opening'], $p['projection']['days'][0]['balance']]);
        $this->assertSame(GestionCashService::status($p['projection']['low14']['amount'], 900000000), $p['status']);
        $this->assertContains($p['status'], ['warning', 'critical']);
        $this->assertSame(['RECORDED_EXPENSES_INCLUDED', 'TAXES_INCLUDED'], array_values(array_diff(array_column($p['caveats'], 'code'), ['NO_SALES_HISTORY'])));
        $this->assertSame('Rent', $p['byCategory'][0]['label']);
        $tile = $this->tile('cash-low');
        $this->assertNotNull($tile);
        $this->assertSame(['alert', 85, 'tresorerie', 'Trésorerie basse', $p['projection']['low14']['date'], 'projection'], [$tile['kind'], $tile['priority'], $tile['action']['screen'], $tile['title'], $tile['figures'][0]['date'], $tile['figures'][0]['series']]);
        $this->assertSame(204, $this->post('/api/gestion/accueil/vignettes/cash-low/ecarter'));
        $this->assertNull($this->tile('cash-low'), 'écartée 7 jours');
        $this->send('PUT', '/api/gestion/tresorerie/reglages', ['threshold' => -900000000], 200);
        $this->assertSame('ok', $this->get('/api/gestion/tresorerie')['status'], 'découvert autorisé : rien');
    }

    public function testProjectionArithmeticInTheFuture(): void
    {
        $service = static::getContainer()->get(GestionCashService::class);
        $b = static::getContainer()->get(PeriodCalendar::class)->today()->modify('+3000 days');
        $d = fn (int $n) => $b->modify("$n days")->format('Y-m-d');
        $conn = $this->em()->getConnection();
        $conn->executeStatement("INSERT INTO gestion_cash_balance (balance_date, amount_cents, created_at, updated_at) VALUES (?, 100000, NOW(), NOW())", [$d(0)]);
        $mv = fn (string $cat, string $dir, int $amount, string $start, string $rec) => $conn->executeStatement(
            'INSERT INTO gestion_cash_movement (label, category, direction, amount_cents, start_date, recurrence, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())', [$cat, $cat, $dir, $amount, $start, $rec]);
        $mv('rent', 'out', 30000, $d(3), 'monthly');
        $mv('salaries', 'out', 20000, $d(-7), 'weekly');   // B, B+7, B+14 : le jour du solde est déjà compris
        $mv('loan', 'in', 50000, $d(10), 'none');
        // Dépenses saisies : note B+2, commande B+3 (4 × 25,00 + port 15,00), reprise B+3 (exclue), campagne B+4 en USD au taux 1,5
        $type = (int) $conn->fetchOne('SELECT id FROM type_note_de_frais ORDER BY id LIMIT 1');
        $conn->executeStatement("INSERT INTO note_de_frais (id, name, montant, date, type_note_de_frais_id) VALUES (nextval('note_de_frais_id_seq'), 'Taxi futur', 5000, ?, ?)", [$d(2), $type ?: null]);
        $product = $this->product();
        foreach (['standard' => 2500, 'reprise' => 9999] as $kind => $cost) {
            $cid = (int) $conn->fetchOne("INSERT INTO commande (id, date, name, kind, budget) VALUES (nextval('commande_id_seq'), ?, 'Achat futur', ?, 0) RETURNING id", [$d(3) . ' 11:00:00', $kind]);
            $conn->executeStatement("INSERT INTO commande_line (commande_id, product_id, quantity_ordered, unit_cost_cents, attached_at, created_at, updated_at) VALUES (?, ?, 4, ?, NOW(), NOW(), NOW())", [$cid, $product, $cost]);
            $conn->executeStatement("INSERT INTO frais_de_port (id, commande_id, name, price) VALUES (nextval('frais_de_port_id_seq'), ?, 'Port', ?)", [$cid, $kind === 'standard' ? 1500 : 7777]);
        }
        $conn->executeStatement("INSERT INTO marketing_campaign (id, name, channel, start_date, end_date, amount_cents, currency, exchange_rate, created_at, updated_at) VALUES (nextval('marketing_campaign_id_seq'), 'Futur', 'social', ?, ?, 1000, 'USD', 1.5, NOW(), NOW())", [$d(4), $d(4)]);
        // Encaissements : vente B+1 de 200,00 dont 50,00 en carte-cadeau ; retour B+2 de 30,00 dont 10,00 en avoir ; carte-cadeau vendue B+4 ;
        // annulée B+4 (exclue) ; vente B+6 (jour à venir : ni réel, ni dans la moyenne)
        $this->order($d(1), 1, 3, 20000, ['gift_card_applied' => 5000]);
        $this->order($d(2), 2, 3, -3000, ['credit_applied' => 1000]);
        $this->order($d(4), 3, 3, 4000, []);
        $this->order($d(4), 1, 7, 99999, []);
        $this->order($d(6), 1, 3, 7777, []);
        $this->endOfWrite();

        $t = $b->modify('+5 days');
        $p = $service->projection(14, 'fr', $t);
        $this->assertSame([$d(0), 100000, 5], [$p['balance']['date'], $p['balance']['amount'], $p['balance']['ageDays']]);
        $this->assertSame([567, 3, $d(-25), $d(4)], [$p['estimatedDailyReceipts']['amount'], $p['estimatedDailyReceipts']['orders'], $p['estimatedDailyReceipts']['from'], $p['estimatedDailyReceipts']['to']], '17 000 sur 30 jours');
        $plain = array_column($p['projection']['days'], 'balance', 'date');
        $this->assertSame([100000, 100000, 95000, 53500, 52000, 52000, 52000, 32000, 32000, 32000, 82000, 82000, 82000, 82000, 62000, 62000, 62000, 62000, 62000], array_values($plain));
        $this->assertSame([$d(0), $d(18)], [array_key_first($plain), array_key_last($plain)], 'du jour du solde à aujourd\'hui + 13');
        $this->assertSame([['date' => $d(7), 'amount' => 32000], ['date' => $d(7), 'amount' => 32000], null, ['date' => $d(18), 'amount' => 62000]], [$p['projection']['low'], $p['projection']['low14'], $p['projection']['belowThresholdFrom'], $p['projection']['end']]);
        $ws = $p['projectionWithSales'];
        $this->assertSame([100000, 115000, 108000, 66500, 69000, 69567, 70134, 50701], array_slice(array_column($ws['days'], 'balance'), 0, 8), 'réels jusqu\'à hier, moyenne dès aujourd\'hui');
        $this->assertSame([false, false, false, false, true], array_slice(array_column($ws['days'], 'receiptsEstimated'), 1, 5));
        $this->assertSame([['date' => $d(7), 'amount' => 50701], true], [$ws['low14'], $ws['estimated']]);
        $cats = array_column($p['byCategory'], null, 'category');
        $this->assertSame([['in' => 0, 'out' => 30000], ['in' => 0, 'out' => 40000], ['in' => 50000, 'out' => 0]], [array_intersect_key($cats['rent'], ['in' => 1, 'out' => 1]), array_intersect_key($cats['salaries'], ['in' => 1, 'out' => 1]), array_intersect_key($cats['loan'], ['in' => 1, 'out' => 1])]);
        $this->assertSame([18000, ['purchases' => 10000, 'shipping' => 1500, 'notes' => 5000, 'advertising' => 1500], 'Dépenses saisies'], [$cats['recorded']['out'], $cats['recorded']['details'], $cats['recorded']['label']]);
        $this->assertSame(['RECORDED_EXPENSES_INCLUDED', 'TAXES_INCLUDED'], array_column($p['caveats'], 'code'));
        $this->assertSame('ok', $p['status'], 'seuil 0');

        // Seuil 400,00 : la projection seule passe dessous (warning) ; avec les ventes estimées, non ; un découvert → critical
        $service->putSettings(['threshold' => 40000]);
        $this->assertSame(['warning', 'projection'], [$service->projection(14, 'fr', $t)['status'], $service->alert($t)['series']]);
        $service->putSettings(['includeEstimatedSales' => true]);
        $this->assertSame(['ok', 'projectionWithSales'], [$service->projection(14, 'fr', $t)['status'], $service->alert($t)['series']]);
        $service->putSettings(['includeEstimatedSales' => false]);
        $this->assertSame(['date' => $d(7), 'amount' => 32000], $service->alert($t)['low14']);
        // Solde ancien : remarque
        $this->assertContains('STALE_BALANCE', array_column($service->projection(14, 'fr', $b->modify('+8 days'))['caveats'], 'code'));

        // Notification : dès le seuil, à 10 h du magasin ; pas de répétition de la même alerte ; à nouveau si le point bas se creuse
        $tz = static::getContainer()->get(StoreClock::class)->timezone();
        $ten = (new \DateTimeImmutable($d(5) . ' 10:15', $tz))->setTimezone(new \DateTimeZone('UTC'));
        $this->send('POST', '/api/notifications/appareils', ['token' => 'cash-' . bin2hex(random_bytes(3)), 'platform' => 'admin', 'locale' => 'fr'], 201);
        $notifications = static::getContainer()->get(GestionNotificationService::class);
        $this->assertSame(1, $notifications->runDaily($ten)['notified']);
        $sent = FakePushSender::$sent[0];
        $this->assertSame(['cash_critical', 'tresorerie', 'Trésorerie basse'], [$sent['data']['kind'], $sent['data']['screen'], $sent['title']]);
        $this->assertStringContainsString('320,00', $sent['body']);
        $conn->executeStatement("UPDATE notification_log SET sent_at = ? WHERE kind = 'cash_critical'", [$ten->format('Y-m-d H:i:s')]); // envoyée « à 10 h » ce jour-là (futur)
        FakePushSender::$sent = [];
        $notifications->runDaily($ten->modify('+1 day'));
        $this->assertNotContains('cash_critical', array_map(fn ($s) => $s['data']['kind'], FakePushSender::$sent), 'même point bas : pas de répétition');
        $mv('other', 'out', 1000, $d(7), 'none');
        FakePushSender::$sent = [];
        $notifications->runDaily($ten->modify('+2 days'));
        $this->assertSame('cash_critical', FakePushSender::$sent[0]['data']['kind'] ?? null, 'le point bas se creuse : notification');
        $this->assertSame(['date' => $d(7), 'amount' => 31000, 'status' => 'warning'], json_decode((string) $conn->fetchOne("SELECT subject FROM notification_log WHERE kind = 'cash_critical' ORDER BY id DESC LIMIT 1"), true));

        // Nettoyage des données datées dans le futur (base partagée)
        $conn->executeStatement("DELETE FROM note_de_frais WHERE name = 'Taxi futur'");
        $conn->executeStatement("DELETE FROM frais_de_port WHERE commande_id IN (SELECT id FROM commande WHERE name = 'Achat futur')");
        $conn->executeStatement("DELETE FROM commande_line WHERE commande_id IN (SELECT id FROM commande WHERE name = 'Achat futur')");
        $conn->executeStatement("DELETE FROM commande WHERE name = 'Achat futur'");
        $conn->executeStatement("DELETE FROM marketing_campaign WHERE name = 'Futur'");
        $conn->executeStatement('DELETE FROM "order" WHERE order_date > NOW() + INTERVAL \'2000 days\'');
    }

    public function testRecurrences(): void
    {
        $o = fn (string $start, string $rec, ?string $end, string $from, string $to) => GestionCashService::occurrences($start, $rec, $end, $from, $to);
        $this->assertSame(['2026-01-31', '2026-02-28', '2026-03-31', '2026-04-30', '2026-05-31', '2026-06-30'], $o('2026-01-31', 'monthly', null, '2026-01-01', '2026-06-30'), 'le 31 : dernier jour des mois courts');
        $this->assertSame(['2025-02-28', '2026-02-28', '2027-02-28', '2028-02-29'], $o('2024-02-29', 'yearly', null, '2025-01-01', '2028-12-31'));
        $this->assertSame(['2027-02-28', '2027-05-30'], $o('2026-11-30', 'quarterly', '2027-06-01', '2027-01-01', '2027-12-31'), 'fin respectée');
        $this->assertSame(['2026-11-13', '2026-11-20'], $o('2020-01-03', 'weekly', null, '2026-11-11', '2026-11-20'), 'début lointain');
        $this->assertSame([], $o('2026-11-30', 'none', null, '2026-12-01', '2026-12-31'));
        // Statut : critical sous le seuil et sous 0 ; warning sous le seuil encore positif ; seuil négatif = découvert autorisé
        $this->assertSame(['critical', 'ok', 'warning', 'critical', 'ok', 'critical'], [GestionCashService::status(-1, 0), GestionCashService::status(0, 0), GestionCashService::status(5, 10), GestionCashService::status(-5, 10), GestionCashService::status(-5, -10), GestionCashService::status(-11, -10)]);
        $this->assertSame(['2026-12-15'], $o('2026-12-15', 'none', null, '2026-12-01', '2026-12-31'));
    }

    // ----- helpers -----

    private function product(): int
    {
        $conn = $this->em()->getConnection();
        $slug = 'cash-' . bin2hex(random_bytes(4));
        $conn->executeStatement("INSERT INTO product (id, name, price, isbestseller, quantity, created_at, slug, product_type) VALUES (nextval('product_id_seq'), 'Article trésorerie', 2000, false, 0, NOW(), :s, 'standard')", ['s' => $slug]);

        return (int) $conn->fetchOne('SELECT id FROM product WHERE slug = ?', [$slug]);
    }

    /** Commande client datée à midi (magasin) de `$day`, type / statut donnés, colonnes de paiement en plus */
    private function order(string $day, int $type, int $status, int $total, array $columns): void
    {
        $tz = static::getContainer()->get(StoreClock::class)->timezone();
        $at = (new \DateTimeImmutable("$day 12:00", $tz))->setTimezone(new \DateTimeZone('UTC'));
        $order = $this->create(Order::class, ['totalAmount' => (float) $total], function (Order $o, EntityManagerInterface $em) use ($at, $type, $status): void {
            $o->setOrderDate(\DateTime::createFromImmutable($at))->setStatus($em->find(StatusCommande::class, $status))->setOrderType($em->find(OrderType::class, $type))
                ->setOrderSource($em->find(OrderSource::class, 2))->setUserId($em->find(User::class, $this->userId));
        });
        foreach ($columns as $col => $value) {
            $this->em()->getConnection()->executeStatement("UPDATE \"order\" SET $col = ? WHERE id = ?", [$value, $order->getId()]);
        }
    }

    private function clean(): void
    {
        $conn = $this->em()->getConnection();
        foreach (self::TABLES as $table) {
            $conn->executeStatement("DELETE FROM $table");
        }
    }

    private function tile(string $id): ?array
    {
        return array_values(array_filter($this->get('/api/gestion/accueil/vignettes'), fn ($t) => $t['id'] === $id))[0] ?? null;
    }

    private function post(string $route): int
    {
        $this->request('POST', $route, []);

        return $this->status();
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
        $email = 'gestion-b1b-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $em = $this->em();
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Gestion')->setLastname('B1b')->setRoles(['ROLE_ADMIN'])->setIsVerified(true)->setPassword('inutilisable');
        $em->persist($user);
        $em->flush();
        $this->userId = (int) $user->getId();
        $this->token = static::getContainer()->get('lexik_jwt_authentication.jwt_manager')->create($user);
        $this->endOfWrite();
    }
}
