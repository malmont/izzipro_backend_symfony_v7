<?php

namespace App\Tests\Functional\Gestion;

use App\Entity\User;
use App\EventSubscriber\CacheInvalidationSubscriber;
use App\Gestion\Services\GestionCashService;
use App\Gestion\Services\GestionFixedCostService;
use App\Gestion\Services\PeriodResultCalculator;
use App\Services\CaisseService\StoreClock;
use App\Services\TenantEntityManagerProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Lots S2-a et S2-b, sections des statistiques unifiées (14/11/2026, `docs/statistiques-gestion.md` § 6) : `sections=payments,expenses,
 * stock,shipping,taxes` sur `GET /api/gestion/statistiques`. S2-b : `stock` (mouvements par type, valeur et sa courbe par les instantanés,
 * stock bas, égal à l'ancienne route sur la semaine), `shipping` (port facturé, par transporteur, égal au résultat de période) et `taxes`
 * (taxes collectées par taxe, retours en négatif). Sans le paramètre, la réponse de S1 ne change pas d'une clé. `payments` : l'encaissé daté par la
 * commande (reçu, remboursé, net), par moyen de paiement, ce que les lignes de paiement n'expliquent pas en « autre », le canal, et les
 * clôtures de caisse (un nombre rond revient en entier dans le JSON : 100, pas 100.0). `expenses` : notes de frais, frais de port d'achat et publicité, au cent près le total des dépenses du résultat
 * de période ; frais fixes à côté, hors du total. Comparaisons à la plage précédente et à l'an dernier, graphique, erreurs.
 *
 * Données du 1er au 10 avril 2007, précédées du 22 au 31 mars 2007 et de l'an dernier, du 1er au 10 avril 2006 ; tout est retiré après.
 */
class StatisticsSectionsApiTest extends WebTestCase
{
    private const RANGE = 'from=2007-04-01&to=2007-04-10';
    private const TAG = 'S2-TEST';
    private KernelBrowser $client;
    private string $token = '';
    private int $userId = 0;
    private \Doctrine\DBAL\Connection $db;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->db = $this->em()->getConnection();
        $this->clean();
        $this->login();
    }

    protected function tearDown(): void
    {
        $this->db = $this->em()->getConnection();
        $this->clean();
        parent::tearDown();
    }

    public function testWithoutTheParameterTheAnswerOfS1DoesNotChangeAndUnknownSectionsAreRefused(): void
    {
        $this->order('2007-04-02', 1, 3, 2, 11500, [[2, 11500]]);
        $this->note('2007-04-02', 4500, 1);
        $without = $this->get('/api/gestion/statistiques?' . self::RANGE);
        $this->assertSame(['currency', 'source', 'range', 'previous', 'lastYear', 'comparison', 'indicators', 'otherRevenue', 'chart', 'caveats'], array_keys($without), 'les clés de S1, sans « sections »');
        $this->assertSame($without, $this->get('/api/gestion/statistiques?' . self::RANGE . '&sections='), 'paramètre vide : la réponse de S1');
        $with = $this->get('/api/gestion/statistiques?' . self::RANGE . '&sections=EXPENSES,%20payments,payments');
        $this->assertSame([...array_keys($without), 'sections'], array_keys($with));
        $this->assertSame($without, array_diff_key($with, ['sections' => true]), 'avec des sections, tout le reste de la réponse est celui de S1');
        $this->assertSame(['payments', 'expenses'], array_keys($with['sections']), 'ordre fixe, sans doublon, casse et espaces tolérés');
        $this->assertSame(['payments'], array_keys($this->get('/api/gestion/statistiques?' . self::RANGE . '&sections=payments')['sections']));
        foreach (['payments', 'expenses'] as $name) {
            $this->assertSame(['granularity', 'points'], array_keys($with['sections'][$name]['chart']));
            $this->assertSame(array_column($without['chart']['points'], 'key'), array_column($with['sections'][$name]['chart']['points'], 'key'), "$name : les mêmes tranches que le graphique de S1");
        }

        // Les cinq sections, dans leur ordre fixe ; chacune a les tranches du graphique de S1
        $all = $this->get('/api/gestion/statistiques?' . self::RANGE . '&sections=taxes,shipping,stock,expenses,payments');
        $this->assertSame(['payments', 'expenses', 'stock', 'shipping', 'taxes'], array_keys($all['sections']));
        $this->assertSame($without, array_diff_key($all, ['sections' => true]));
        foreach ($all['sections'] as $name => $section) {
            $this->assertSame(array_column($without['chart']['points'], 'key'), array_column($section['chart']['points'], 'key'), $name);
            $this->assertSame(['indicators', 'breakdown', 'chart', 'caveats', 'appliesSource'], array_values(array_intersect(array_keys($section), ['indicators', 'breakdown', 'chart', 'caveats', 'appliesSource'])), "$name : le socle commun");
        }
        $this->assertSame(['payments' => true, 'expenses' => false, 'stock' => false, 'shipping' => true, 'taxes' => true], array_map(fn (array $x) => $x['appliesSource'], $all['sections']));

        // Une section inconnue : 422, rien n'est calculé
        foreach (['sections=inconnue', 'sections=payments,inconnue', 'sections=,', 'sections[]=payments', 'sections=stocks'] as $query) {
            $this->request('GET', '/api/gestion/statistiques?' . self::RANGE . '&' . $query);
            $this->assertSame([422, ['sections' => 'INVALID_SECTION']], [$this->status(), $this->body()['fields'] ?? null], $query);
        }
    }

    public function testPaymentsAreTheReceiptsDatedByTheOrderWithMethodsChannelAndComparisons(): void
    {
        $empty = $this->get('/api/gestion/statistiques?' . self::RANGE . '&sections=payments')['sections']['payments'];
        $this->assertSame([[0, 0, 0], [], null], [array_column($empty['indicators'], 'value'), $empty['breakdown'], $empty['indicators']['net']['lastYear']], 'aucune donnée datée là avant le test');

        // Plage : caisse espèces 115,00 ; web 230,00 dont 30,00 par carte-cadeau (200,00 par carte) ; caisse 57,50 SANS ligne de paiement ;
        // application 46,00 par PayPal ; retour caisse 23,00 rendu en espèces ; une vente annulée et une incomplète (exclues), un paiement échoué (exclu)
        $a = $this->order('2007-04-02', 1, 3, 2, 11500, [[2, 11500]]);
        $this->payment($a, 2, 500, 3); // paiement échoué : ne compte pas
        $this->order('2007-04-05', 1, 3, 1, 23000, [[1, 20000]], giftCard: 3000);
        $this->order('2007-04-05', 1, 3, 2, 5750, []);
        $this->order('2007-04-06', 1, 3, 3, 4600, [[3, 4600]]);
        $this->order('2007-04-08', 2, 6, 2, 2300, [[2, -2300]]);
        $this->order('2007-04-09', 1, 7, 2, 9999, [[1, 9999]]);
        $this->order('2007-04-09', 1, 1, 1, 8888, [[1, 8888]]);
        // Plage précédente (22 au 31 mars) et an dernier (1er au 10 avril 2006)
        $this->order('2007-03-25', 1, 3, 2, 10000, [[2, 10000]]);
        $this->order('2006-04-03', 1, 3, 1, 30000, [[1, 30000]]);
        $this->order('2006-04-08', 2, 6, 2, 5000, [[2, -5000]]);
        // Juste hors de la plage : la veille à 23 h 30 et le lendemain à 0 h 30 (heure du magasin)
        $this->order('2007-03-31', 1, 3, 2, 700, [[2, 700]], time: '23:30');
        $this->order('2007-04-11', 1, 3, 2, 900, [[2, 900]], time: '00:30');
        $this->order('2007-04-10', 1, 3, 2, 100, [[2, 100]], time: '23:30'); // dernier jour, tard le soir : dans la plage

        $stats = $this->get('/api/gestion/statistiques?' . self::RANGE . '&sections=payments');
        $p = $stats['sections']['payments'];
        // (lot AVOIRS : six lignes à part, hors de l'encaissé ; leur contenu est vérifié par StoreCreditReceiptsApiTest)
        $this->assertSame(['indicators', 'breakdown', 'cashDesk', 'chart', 'caveats', 'appliesSource',
            'storeCreditIssued', 'giftCardRecredited', 'paidByStoreCredit', 'paidByGiftCard', 'outstandingStoreCredit', 'outstandingGiftCards'], array_keys($p));
        $values = fn (array $i): array => [$i['value'], $i['unit'], $i['previous'], $i['lastYear'], $i['changeVsPrevious'], $i['changeVsLastYear'], $i['changeUnit']];
        // reçu 115,00 + 200,00 + 57,50 + 46,00 + 1,00 = 419,50 ; remboursé 23,00 ; net 396,50 ; précédente 100,00 + 7,00 ; an dernier 300,00 − 50,00
        $this->assertSame([
            'received' => [41950, 'money', 10700, 30000, 292.1, 39.8, 'percent'],
            'refunded' => [2300, 'money', 0, 5000, null, -54, 'percent'],
            'net' => [39650, 'money', 10700, 25000, 270.6, 58.6, 'percent'],
        ], array_map($values, $p['indicators']));
        $this->assertSame([0, 11500, 0, 0, 25750, 4600, 0, 0, 0, 100], $p['indicators']['received']['spark'], 'un point par jour du magasin');

        // Par moyen de paiement : les lignes de paiement réussies ; la vente sans ligne de paiement en « autre » ; les moyens additionnés font le net
        $this->assertSame([
            ['method' => 'card', 'label' => 'Carte', 'received' => 20000, 'refunded' => 0, 'net' => 20000, 'share' => 50.4, 'previousNet' => 0, 'lastYearNet' => 30000],
            ['method' => 'cash', 'label' => 'Espèces', 'received' => 11600, 'refunded' => 2300, 'net' => 9300, 'share' => 23.5, 'previousNet' => 10700, 'lastYearNet' => -5000],
            ['method' => 'paypal', 'label' => 'PayPal', 'received' => 4600, 'refunded' => 0, 'net' => 4600, 'share' => 11.6, 'previousNet' => 0, 'lastYearNet' => 0],
            ['method' => 'other', 'label' => 'Autre', 'received' => null, 'refunded' => null, 'net' => 5750, 'share' => 14.5, 'previousNet' => null, 'lastYearNet' => null],
        ], $p['breakdown']);
        $this->assertSame($p['indicators']['net']['value'], array_sum(array_column($p['breakdown'], 'net')));
        $this->assertSame([['PAYMENTS_BY_ORDER_DATE', null], ['PAYMENTS_UNMATCHED', 5750]], array_map(fn (array $c) => [$c['code'], $c['amount'] ?? null], $p['caveats']));
        $this->assertStringContainsString('57,50', $p['caveats'][1]['text']);
        $this->assertTrue($p['appliesSource']);

        // Graphique : reçu, remboursé et net par jour, l'an dernier aligné sur les mêmes dates
        $point = fn (string $key): array => array_values(array_filter($p['chart']['points'], fn (array $pt) => $pt['key'] === $key))[0];
        $this->assertSame(['key' => '2007-04-05', 'from' => '2007-04-05', 'to' => '2007-04-05', 'received' => 25750, 'refunded' => 0, 'net' => 25750, 'lastYear' => ['received' => 0, 'refunded' => 0, 'net' => 0]], $point('2007-04-05'));
        $this->assertSame([[0, 2300, -2300], ['received' => 0, 'refunded' => 5000, 'net' => -5000]], [[$point('2007-04-08')['received'], $point('2007-04-08')['refunded'], $point('2007-04-08')['net']], $point('2007-04-08')['lastYear']]);
        $this->assertSame(['received' => 30000, 'refunded' => 0, 'net' => 30000], $point('2007-04-03')['lastYear']);
        $this->assertSame(['day', 10], [$p['chart']['granularity'], count($p['chart']['points'])]);

        // Recoupement : la règle de « Ma journée » et de la trésorerie, au cent près, jour par jour et sur la plage
        $cash = static::getContainer()->get(GestionCashService::class);
        $byDay = $cash->receiptsByDay('2007-04-01', '2007-04-10')['days'];
        ksort($byDay);
        $this->assertSame($byDay, array_filter(array_column($p['chart']['points'], 'net', 'key')), 'le net de chaque jour = l\'encaissé du jour');
        $split = $cash->receiptsSplit('2007-04-01', '2007-04-10');
        $byMethod = array_column($p['breakdown'], 'net', 'method');
        $this->assertSame([$split['total'], $split['cash'], $split['card']], [$p['indicators']['net']['value'], $byMethod['cash'], $byMethod['card'] + $byMethod['paypal'] + $byMethod['other']],
            '« Ma journée » range en « carte » tout ce qui n\'est pas en espèces');

        // Par canal : la caisse seule, la boutique en ligne seule
        $pos = $this->get('/api/gestion/statistiques?' . self::RANGE . '&sections=payments&source=pos')['sections']['payments'];
        $this->assertSame([[17350, 2300, 15050], [10700, 0, 10700], [0, 5000, -5000]], [array_column($pos['indicators'], 'value'), array_column($pos['indicators'], 'previous'), array_column($pos['indicators'], 'lastYear')]);
        $this->assertSame([['cash', 9300, 10700, -5000], ['other', 5750, null, null]], array_map(fn (array $m) => [$m['method'], $m['net'], $m['previousNet'], $m['lastYearNet']], $pos['breakdown']));
        $web = $this->get('/api/gestion/statistiques?' . self::RANGE . '&sections=payments&source=ecommerce')['sections']['payments'];
        $this->assertSame([[20000, 0, 20000], [['card', 20000, 100]], ['PAYMENTS_BY_ORDER_DATE']], [array_column($web['indicators'], 'value'), array_map(fn (array $m) => [$m['method'], $m['net'], $m['share']], $web['breakdown']), array_column($web['caveats'], 'code')]);
        $this->assertSame([[4600, 0, 4600], 'Cash'], [array_column($this->get('/api/gestion/statistiques?' . self::RANGE . '&sections=payments&source=mobile_app')['sections']['payments']['indicators'], 'value'),
            array_column($this->get('/api/gestion/statistiques?' . self::RANGE . '&sections=payments&locale=en')['sections']['payments']['breakdown'], 'label', 'method')['cash']]);

        // Année : pas de plage précédente ; an dernier seulement
        $year = $this->get('/api/gestion/statistiques?range=year&sections=payments')['sections']['payments'];
        $this->assertSame([null, null], [$year['indicators']['net']['previous'], $year['indicators']['net']['changeVsPrevious']]);
        $this->assertNull($year['cashDesk']['closings']['previous']);

        // Un retour sans ligne de paiement (rendu en avoir, par exemple) : « autre » devient négatif, et la remarque parle de remboursements
        $this->order('2007-04-07', 2, 6, 2, 9000, []);
        $after = $this->get('/api/gestion/statistiques?' . self::RANGE . '&sections=payments')['sections']['payments'];
        $other = array_column($after['breakdown'], null, 'method')['other'];
        $this->assertSame([[41950, 11300, 30650], -3250, -10.6, $after['indicators']['net']['value']], [array_column($after['indicators'], 'value'), $other['net'], $other['share'], array_sum(array_column($after['breakdown'], 'net'))]);
        $this->assertSame(['REFUNDS_UNMATCHED', -3250], [$after['caveats'][1]['code'], $after['caveats'][1]['amount']]);
        $this->assertStringContainsString('32,50', $after['caveats'][1]['text']);
        $this->assertStringNotContainsString('-32,50', $after['caveats'][1]['text'], 'le texte dit le montant sans signe ; le signe est dans amount');
        $this->assertStringContainsString('remboursements', $after['caveats'][1]['text']);
    }

    public function testTheCashDeskBlockCountsTheClosingsOfTheRangeAndIsForTheTillOnly(): void
    {
        $register = (int) $this->db->fetchOne("INSERT INTO cash_register (name, is_active) VALUES ('Poste S2-TEST', true) RETURNING id");
        $session = (int) $this->db->fetchOne("INSERT INTO caisse (id, amount_total, is_open, cash_register_id) VALUES (nextval('caisse_id_seq'), 0, false, ?) RETURNING id", [$register]);
        // une clôture par session de caisse (un rapport de fermeture par session)
        $closing = fn (string $day, string $time, int $expected, int $counted, int $deposit) => $this->db->executeStatement(
            "INSERT INTO cash_register_report (cash_register_id, caisse_id, number, closed_at, expected_cash, counted_cash, discrepancy, bank_deposit, data) VALUES (?, ?, (SELECT COALESCE(MAX(number), 0) + 1 FROM cash_register_report), ?, ?, ?, ?, ?, '{}')",
            [$register, (int) $this->db->fetchOne("INSERT INTO caisse (id, amount_total, is_open, cash_register_id) VALUES (nextval('caisse_id_seq'), 0, false, ?) RETURNING id", [$register]),
                $this->utc($day, $time), $expected, $counted, $counted - $expected, $deposit]);
        $movement = fn (string $day, int $type, float $amount, ?int $cash) => $this->db->executeStatement(
            "INSERT INTO transaction_caisse (id, caisse_id, user_caisse_id, transaction_type_id, transaction_date, amount, cash_amount) VALUES (nextval('transaction_caisse_id_seq'), ?, ?, ?, ?, ?, ?)",
            [$session, $this->userId, $type, $this->utc($day, '12:00'), $amount, $cash]);

        $closing('2007-04-03', '21:00', 20000, 19500, 15000); // 5,00 de moins que l'attendu
        $closing('2007-04-10', '23:30', 10000, 10000, 8000);  // dernier jour, tard le soir : dans la plage
        $closing('2007-04-11', '00:30', 10000, 19999, 7777);  // le lendemain : hors plage
        $closing('2007-03-28', '21:00', 5000, 5200, 1000);    // plage précédente : 2,00 de trop
        $movement('2007-04-02', 3, 4000, 4000);    // dépôt
        $movement('2007-04-04', 6, -1500, -1500);  // retrait
        $movement('2007-04-05', 8, 10000, null);   // ajout de fond, ligne d'avant la colonne des espèces : le montant
        $movement('2007-04-06', 7, -2500, -2500);  // retrait de fond
        $movement('2007-04-06', 1, 8000, 8000);    // une vente : pas un mouvement de fond
        $movement('2007-03-20', 3, 9999, 9999);    // hors plage

        $desk = $this->get('/api/gestion/statistiques?' . self::RANGE . '&sections=payments')['sections']['payments']['cashDesk'];
        $this->assertSame(['closings', 'discrepancy', 'discrepancyCount', 'bankDeposits', 'movements'], array_keys($desk));
        $values = fn (array $i): array => [$i['value'], $i['unit'], $i['previous'], $i['lastYear'], $i['changeVsPrevious'], $i['changeVsLastYear']];
        $this->assertSame([[2, 'count', 1, null, 100, null], [-500, 'money', 200, null, -350, null], 1, [23000, 'money', 1000, null, 2200, null]],
            [$values($desk['closings']), $values($desk['discrepancy']), $desk['discrepancyCount'], $values($desk['bankDeposits'])], 'aucune clôture l\'an dernier : pas de comparaison');
        $this->assertArrayNotHasKey('spark', $desk['closings']);
        $this->assertSame(['deposits' => 4000, 'withdrawals' => 1500, 'fundDeposits' => 10000, 'fundWithdrawals' => 2500], $desk['movements']);

        // Le bloc vaut pour la caisse : rendu pour tout le site et pour le canal caisse, null pour les autres canaux
        $of = fn (string $source) => $this->get('/api/gestion/statistiques?' . self::RANGE . "&sections=payments&source=$source")['sections']['payments']['cashDesk'];
        $this->assertSame([$desk, null, null], [$of('pos'), $of('ecommerce'), $of('mobile_app')]);
        // Une clôture l'an dernier : la comparaison apparaît
        $closing('2006-04-05', '21:00', 5000, 4000, 500);
        $after = $of('all');
        $this->assertSame([[1, 100], [-1000, 50], [500, 4500]], [[$after['closings']['lastYear'], $after['closings']['changeVsLastYear']], [$after['discrepancy']['lastYear'], $after['discrepancy']['changeVsLastYear']],
            [$after['bankDeposits']['lastYear'], $after['bankDeposits']['changeVsLastYear']]]);
    }

    public function testExpensesAreThoseOfThePeriodResultWithFixedCostsBesideTheTotal(): void
    {
        $empty = $this->get('/api/gestion/statistiques?' . self::RANGE . '&sections=expenses')['sections']['expenses'];
        $this->assertSame([[0, 0, 0, 0], [], [], null], [array_column($empty['indicators'], 'value'), $empty['notesByType'], $empty['campaigns'], $empty['indicators']['total']['lastYear']], 'aucune donnée datée là avant le test');
        $fixedBefore = $empty['fixedCosts']['value'];

        // Plage : notes 45,00 (Déplacement) + 15,00 (sans type) + 30,00 (Repas) ; port d'achat 20,00 au taux 1,5 + 12,50 ; publicité 100,00 au taux 1,2
        $this->note('2007-04-02', 4500, 1);
        $this->note('2007-04-02', 1500, null);
        $this->note('2007-04-09', 3000, 2);
        $this->purchaseShipping('2007-04-04', 2000, 1.5);
        $this->purchaseShipping('2007-04-10', 1250, null);
        $this->campaign('Campagne de la plage', '2007-04-06', 10000, 1.2);
        // Précédente : note 20,00 et publicité 50,00 ; an dernier : note 10,00 ; juste hors plage : une note et un port
        $this->note('2007-03-25', 2000, 1);
        $this->campaign('Campagne d\'avant', '2007-03-31', 5000, null); // dernier jour de la plage précédente : compté
        $this->note('2006-04-05', 1000, 5);
        $this->note('2007-04-11', 7777, 1);
        $this->purchaseShipping('2007-03-21', 6666, null);
        // Un frais fixe : 365,00 par mois, soit 12,00 par jour, depuis le 1er janvier 2007
        $this->db->executeStatement("INSERT INTO gestion_cash_movement (label, category, direction, amount_cents, start_date, recurrence, fixed_cost, created_by, created_at, updated_at) VALUES ('Loyer S2-TEST', 'rent', 'out', 36500, '2007-01-01', 'monthly', true, 'test', NOW(), NOW())");

        $e = $this->get('/api/gestion/statistiques?' . self::RANGE . '&sections=expenses')['sections']['expenses'];
        $this->assertSame(['indicators', 'breakdown', 'notesByType', 'campaigns', 'fixedCosts', 'chart', 'caveats', 'appliesSource'], array_keys($e));
        $values = fn (array $i): array => [$i['value'], $i['unit'], $i['previous'], $i['lastYear'], $i['changeVsPrevious'], $i['changeVsLastYear'], $i['changeUnit']];
        $this->assertSame([
            'total' => [25250, 'money', 7000, 1000, 260.7, 2425, 'percent'],
            'notes' => [9000, 'money', 2000, 1000, 350, 800, 'percent'],
            'purchaseShipping' => [4250, 'money', 0, 0, null, null, 'percent'],
            'advertising' => [12000, 'money', 5000, 0, 140, null, 'percent'],
        ], array_map($values, $e['indicators']));
        $this->assertSame([
            ['kind' => 'notes', 'label' => 'Notes de frais', 'amount' => 9000, 'share' => 35.6],
            ['kind' => 'purchaseShipping', 'label' => 'Frais de port d\'achat', 'amount' => 4250, 'share' => 16.8],
            ['kind' => 'advertising', 'label' => 'Publicité', 'amount' => 12000, 'share' => 47.5],
        ], $e['breakdown']);
        $this->assertSame([['typeId' => 1, 'name' => 'Déplacement', 'amount' => 4500, 'share' => 50], ['typeId' => 2, 'name' => 'Repas', 'amount' => 3000, 'share' => 33.3], ['typeId' => null, 'name' => 'Sans type', 'amount' => 1500, 'share' => 16.7]], $e['notesByType']);
        $this->assertSame([['name' => 'Campagne de la plage', 'amount' => 12000, 'startDate' => '2007-04-06']], array_map(fn (array $c) => array_diff_key($c, ['id' => 1]), $e['campaigns']));
        $this->assertFalse($e['appliesSource']);

        // Graphique : chaque frais à son jour ; l'an dernier aligné ; les points additionnés font le total
        $points = array_column($e['chart']['points'], null, 'key');
        $this->assertSame([[6000, 6000, 0, 0], [3000, 0, 3000, 0], [12000, 0, 0, 12000], [4250, 3000, 1250, 0]],
            [[$points['2007-04-02']['total'], $points['2007-04-02']['notes'], $points['2007-04-02']['purchaseShipping'], $points['2007-04-02']['advertising']],
                [$points['2007-04-04']['total'], $points['2007-04-04']['notes'], $points['2007-04-04']['purchaseShipping'], $points['2007-04-04']['advertising']],
                [$points['2007-04-06']['total'], $points['2007-04-06']['notes'], $points['2007-04-06']['purchaseShipping'], $points['2007-04-06']['advertising']],
                [array_sum(array_column($points, 'purchaseShipping')), $points['2007-04-04']['purchaseShipping'], $points['2007-04-10']['purchaseShipping'], 0]]);
        $this->assertSame([25250, 1000, 1000], [array_sum(array_column($points, 'total')), $points['2007-04-05']['lastYear']['total'], array_sum(array_map(fn (array $pt) => $pt['lastYear']['total'], $points))]);
        $this->assertSame([0, 6000, 0, 3000, 0, 12000, 0, 0, 3000, 1250], $e['indicators']['total']['spark']);

        // Frais fixes : à côté, HORS du total ; 12,00 par jour sur 10 jours ; l'an dernier le loyer n'existait pas
        $fixed = $e['fixedCosts'];
        $this->assertSame([12000, 12000, 10, false, 'money'], [$fixed['value'] - $fixedBefore, $fixed['previous'] - $empty['fixedCosts']['previous'], $fixed['days'], $fixed['includedInTotal'], $fixed['unit']]);
        $service = static::getContainer()->get(GestionFixedCostService::class);
        $this->assertSame($service->between('2007-04-01', '2007-04-10')['total'], $fixed['value']);
        $lastYear = $service->between('2006-04-01', '2006-04-10')['total'];
        $this->assertSame($lastYear === 0 ? null : $lastYear, $fixed['lastYear'], 'aucun frais fixe l\'an dernier : pas de comparaison (null, et non 0)');
        $this->assertContains('rent', array_column($fixed['byCategory'], 'category'));
        $this->assertSame(['FIXED_COSTS_OUTSIDE_TOTAL', 'FIXED_COSTS_PRORATED'], array_slice(array_column($e['caveats'], 'code'), 0, 2));
        $this->assertStringContainsString('ne sont pas compris dans le total', $e['caveats'][0]['text']);

        // Le canal ne s'applique pas : mêmes chiffres, et c'est dit ; en anglais, libellés traduits
        $pos = $this->get('/api/gestion/statistiques?' . self::RANGE . '&sections=expenses&source=pos&locale=en')['sections']['expenses'];
        $this->assertSame([array_column($e['indicators'], 'value'), 'SOURCE_NOT_APPLICABLE', ['Expense notes', 'Purchase shipping', 'Advertising'], ['Travel', 'Meals', 'No type']],
            [array_column($pos['indicators'], 'value'), $pos['caveats'][0]['code'], array_column($pos['breakdown'], 'label'), array_column($pos['notesByType'], 'name')]);

        // Recoupement : sur une période, le total de la section = le total des dépenses du résultat de période, au cent près, composante par composante
        // (une période d'avril 2007, posée en base : le calendrier ne remonte pas si loin ; elle contient aussi la note du 11 avril)
        $periodId = (int) $this->db->fetchOne("INSERT INTO collections (id, budget_collection, start_date_collection, end_date_collection, del, nom_collection, is_closed, kind, period_key)"
            . " VALUES (nextval('collections_id_seq'), 0, '2007-04-01', '2007-04-30', false, 'Avril 2007 S2-TEST', false, 'period', 'T-S2-TEST') RETURNING id");
        $result = static::getContainer()->get(PeriodResultCalculator::class)->compute($this->em()->find(\App\Entity\Collections::class, $periodId), null)['expenses'];
        $i = $this->get("/api/gestion/statistiques?periodId=$periodId&sections=expenses")['sections']['expenses']['indicators'];
        $this->assertSame([$result['total'], $result['notes'], $result['shipping'], array_sum(array_column($result['other'], 'amount'))],
            [$i['total']['value'], $i['notes']['value'], $i['purchaseShipping']['value'], $i['advertising']['value']]);
        $this->assertSame([25250 + 7777, 9000 + 7777, 4250, 12000], [$i['total']['value'], $i['notes']['value'], $i['purchaseShipping']['value'], $i['advertising']['value']], 'avril entier : la plage du test et la note du 11');
        $this->assertSame(['id', 'name', 'amount'], array_keys($result['other'][0]['campaigns'][0]), 'la forme du résultat de période ne change pas');
    }

    public function testStockGivesTheMovementsOfTheRangeTheValueCurveAndMatchesTheOldRouteOnTheWeek(): void
    {
        $product = (int) $this->db->fetchOne("INSERT INTO product (id, name, price, isbestseller, quantity, created_at, slug, product_type) VALUES (nextval('product_id_seq'), 'Article S2-TEST', 2500, false, 0, NOW(), ?, 'standard') RETURNING id", ['s2-test-' . bin2hex(random_bytes(3))]);
        // 4 pièces en stock, vendues 25,00 et achetées 10,00 : la valeur de vente du stock et sa valeur au coût diffèrent
        $this->db->executeStatement('UPDATE product SET purchase_price = 1000 WHERE id = ?', [$product]);
        $variant = (int) $this->db->fetchOne("INSERT INTO product_variant (id, product_id, stock_quantity) VALUES (nextval('product_variant_id_seq'), ?, 4) RETURNING id", [$product]);
        $move = fn (string $day, int $type, int $quantity, string $time = '12:00') => $this->db->executeStatement(
            "INSERT INTO inventory_movements (id, product_variant_id, movement_type_id, quantity, movement_date) VALUES (nextval('inventory_movements_id_seq'), ?, ?, ?, ?)", [$variant, $type, $quantity, $this->utc($day, $time)]);
        // Plage : 10 entrées, 3 + 1 sorties, 20 reçues d'un fournisseur, 2 ajustées ; précédente : 4 sorties ; an dernier : 5 entrées
        $move('2007-04-02', 1, 10);
        $move('2007-04-02', 2, 3);
        $move('2007-04-05', 5, 20);
        $move('2007-04-07', 4, 2);
        $move('2007-04-10', 2, 1, '23:30'); // dernier jour, tard le soir : dans la plage
        $move('2007-04-11', 2, 9, '00:30'); // le lendemain : hors plage
        $move('2007-03-25', 2, 4);
        $move('2006-04-03', 1, 5);
        // Instantanés de la valeur du stock : le 3, le 4 et le 9 avril
        foreach ([['2007-04-03', 100000, 60000], ['2007-04-04', 110000, 65000], ['2007-04-09', 90000, 50000]] as [$day, $sale, $cost]) {
            $this->db->executeStatement('INSERT INTO gestion_stock_snapshot (snapshot_date, sale_value_cents, cost_value_cents, created_at) VALUES (?, ?, ?, NOW())', [$day, $sale, $cost]);
        }
        $first = substr((string) $this->db->fetchOne('SELECT MIN(snapshot_date) FROM gestion_stock_snapshot'), 0, 10);

        $st = $this->get('/api/gestion/statistiques?' . self::RANGE . '&sections=stock')['sections']['stock'];
        $this->assertSame(['indicators', 'breakdown', 'value', 'lowCount', 'chart', 'caveats', 'appliesSource'], array_keys($st));
        $values = fn (array $i): array => [$i['value'], $i['unit'], $i['previous'], $i['lastYear'], $i['changeVsPrevious'], $i['changeVsLastYear']];
        $this->assertSame([
            'unitsIn' => [10, 'count', 0, 5, null, 100], 'unitsOut' => [4, 'count', 4, 0, 0, null], 'receptions' => [20, 'count', 0, 0, null, null],
            'adjustments' => [2, 'count', 0, 0, null, null], 'returns' => [0, 'count', 0, 0, null, null],
        ], array_map($values, $st['indicators']));
        $this->assertSame([0, 3, 0, 0, 0, 0, 0, 0, 0, 1], $st['indicators']['unitsOut']['spark']);
        $this->assertSame([
            ['code' => 'in', 'label' => 'Entrées', 'quantity' => 10, 'previous' => 0, 'lastYear' => 5], ['code' => 'out', 'label' => 'Sorties', 'quantity' => 4, 'previous' => 4, 'lastYear' => 0],
            ['code' => 'return', 'label' => 'Retours', 'quantity' => 0, 'previous' => 0, 'lastYear' => 0], ['code' => 'adjustment', 'label' => 'Ajustements', 'quantity' => 2, 'previous' => 0, 'lastYear' => 0],
            ['code' => 'reception', 'label' => 'Réceptions fournisseur', 'quantity' => 20, 'previous' => 0, 'lastYear' => 0],
        ], $st['breakdown']);
        // Graphique : mouvements du jour, et la valeur du stock le jour d'un instantané (null sinon)
        $points = array_column($st['chart']['points'], null, 'key');
        $this->assertSame(['key' => '2007-04-02', 'from' => '2007-04-02', 'to' => '2007-04-02', 'in' => 10, 'out' => 3, 'return' => 0, 'adjustment' => 0, 'reception' => 0, 'saleValue' => null, 'costValue' => null,
            'lastYear' => ['in' => 0, 'out' => 0, 'return' => 0, 'adjustment' => 0, 'reception' => 0]], $points['2007-04-02']);
        $this->assertSame([[100000, 60000], [110000, 65000], [null, null], [90000, 50000], 5], [[$points['2007-04-03']['saleValue'], $points['2007-04-03']['costValue']], [$points['2007-04-04']['saleValue'], $points['2007-04-04']['costValue']],
            [$points['2007-04-05']['saleValue'], $points['2007-04-05']['costValue']], [$points['2007-04-09']['saleValue'], $points['2007-04-09']['costValue']], $points['2007-04-03']['lastYear']['in']]);
        // La courbe ne commence qu'au premier instantané : c'est dit, avec la date
        $this->assertSame([$first, ['STOCK_HISTORY_STARTS'], $first], [$st['value']['historyStartsAt'], array_column($st['caveats'], 'code'), $st['caveats'][0]['date']]);
        $this->assertStringContainsString((new \DateTimeImmutable($first))->format('d/m/Y'), $st['caveats'][0]['text']);
        $this->assertFalse($st['appliesSource']);

        // Valeur d'aujourd'hui et stock bas : les services existants (S1 et « Ma journée »)
        $this->em();
        $today = static::getContainer()->get(\App\Gestion\Services\PeriodCalendar::class)->today();
        $now = static::getContainer()->get(\App\Gestion\Services\GestionStockSnapshotService::class)->current($today);
        $this->assertSame([$now['saleValue'], $now['costValue'], count(static::getContainer()->get(\App\Gestion\Services\HomeTilesService::class)->lowStock()['all'])],
            [$st['value']['saleValue'], $st['value']['costValue'], $st['lowCount']]);
        $this->assertGreaterThan($st['value']['costValue'], $st['value']['saleValue'], 'valeur de vente et valeur au coût ne sont pas la même chose');
        $this->assertGreaterThanOrEqual(4000, $st['value']['costValue']);

        // Par semaine : la valeur d'une tranche est son DERNIER instantané ; une plage qui commence au premier instantané : plus de remarque
        $weeks = array_column($this->get('/api/gestion/statistiques?from=2007-01-01&to=2007-04-10&sections=stock')['sections']['stock']['chart']['points'], null, 'key');
        $this->assertSame([[110000, 10, 3, 20, 2], [90000, 0, 1, 0, 0]], [[$weeks['2007-W14']['saleValue'], $weeks['2007-W14']['in'], $weeks['2007-W14']['out'], $weeks['2007-W14']['reception'], $weeks['2007-W14']['adjustment']],
            [$weeks['2007-W15']['saleValue'], $weeks['2007-W15']['in'], $weeks['2007-W15']['out'], $weeks['2007-W15']['reception'], $weeks['2007-W15']['adjustment']]]);
        $fromFirst = $this->get("/api/gestion/statistiques?from=$first&to=2007-04-10&sections=stock")['sections']['stock'];
        $this->assertSame([[], $this->db->fetchOne('SELECT sale_value_cents FROM gestion_stock_snapshot WHERE snapshot_date = ?', [$first])], [$fromFirst['caveats'], $fromFirst['chart']['points'][0]['saleValue']],
            'le premier jour de la plage porte son instantané');
        // Le canal ne s'applique pas : mêmes chiffres, et c'est dit ; libellés en anglais
        $pos = $this->get('/api/gestion/statistiques?' . self::RANGE . '&sections=stock&source=pos&locale=en')['sections']['stock'];
        $this->assertSame([array_column($st['indicators'], 'value'), 'SOURCE_NOT_APPLICABLE', ['In', 'Out', 'Returns', 'Adjustments', 'Supplier receptions']],
            [array_column($pos['indicators'], 'value'), $pos['caveats'][0]['code'], array_column($pos['breakdown'], 'label')]);

        // Recoupement : sur la semaine en cours, les mêmes totaux que l'ancienne route stock-evolution
        $tz = static::getContainer()->get(StoreClock::class)->timezone();
        $monday = (new \DateTimeImmutable('now', $tz))->modify('monday this week');
        $this->db->executeStatement("INSERT INTO inventory_movements (id, product_variant_id, movement_type_id, quantity, movement_date) VALUES (nextval('inventory_movements_id_seq'), ?, 5, 7, NOW())", [$variant]);
        $old = array_column($this->get('/api/stock-evolution')['stock_evolution_week'], 'quantity', 'code');
        $week = $this->get('/api/gestion/statistiques?from=' . $monday->format('Y-m-d') . '&to=' . $monday->modify('+6 days')->format('Y-m-d') . '&sections=stock')['sections']['stock'];
        $this->assertSame($old, array_column($week['breakdown'], 'quantity', 'code'));
        $this->assertGreaterThanOrEqual(7, $week['indicators']['receptions']['value']);

        // Une plage sans mouvement ni l'an dernier : zéro, et pas de comparaison à l'an dernier
        $quiet = $this->get('/api/gestion/statistiques?from=2007-06-01&to=2007-06-10&sections=stock')['sections']['stock'];
        $this->assertSame([[0, null], [0, null]], [[$quiet['indicators']['unitsIn']['value'], $quiet['indicators']['unitsIn']['lastYear']], [$quiet['breakdown'][0]['quantity'], $quiet['breakdown'][0]['lastYear']]]);

        // Sans aucun instantané : la remarque le dit
        $this->db->executeStatement('DELETE FROM gestion_stock_snapshot');
        $none = $this->get('/api/gestion/statistiques?' . self::RANGE . '&sections=stock')['sections']['stock'];
        $this->assertSame([null, ['STOCK_HISTORY_EMPTY']], [$none['value']['historyStartsAt'], array_column($none['caveats'], 'code')]);
    }

    public function testShippingIsWhatWasBilledToCustomersByCarrierAndMatchesThePeriodResult(): void
    {
        $carrier = fn (string $name, string $kind): int => (int) $this->db->fetchOne("INSERT INTO carrier (id, name, description, price, created_at, kind) VALUES (nextval('carrier_id_seq'), ?, 'S2-TEST', 9999, NOW(), ?) RETURNING id", [$name, $kind]);
        [$a, $b] = [$carrier('Transporteur A S2-TEST', 'fixed'), $carrier('Retrait B S2-TEST', 'pickup')];
        // Plage : A 15,00 deux fois (web), B retrait gratuit (caisse), 8,00 sans transporteur (application), un retour de A −15,00 ; annulée et incomplète exclues
        $this->order('2007-04-02', 1, 3, 1, 11500, [], carrier: $a, shipping: 1500);
        $this->order('2007-04-03', 1, 3, 1, 11500, [], carrier: $a, shipping: 1500);
        $this->order('2007-04-05', 1, 3, 2, 5000, [], carrier: $b, shipping: 0);
        $this->order('2007-04-06', 1, 3, 3, 5800, [], shipping: 800);
        $this->order('2007-04-04', 1, 3, 2, 1000, []); // vente de caisse sans transporteur ni port : n'apparaît nulle part
        $this->order('2007-04-08', 2, 6, 1, 11500, [], carrier: $a, shipping: 1500);
        $this->order('2007-04-09', 1, 7, 1, 9999, [], carrier: $a, shipping: 1500);
        $this->order('2007-04-09', 1, 1, 1, 8888, [], carrier: $a, shipping: 1500);
        // Précédente : A 10,00 ; an dernier : B 5,00
        $this->order('2007-03-25', 1, 3, 1, 5000, [], carrier: $a, shipping: 1000);
        $this->order('2006-04-04', 1, 3, 2, 5000, [], carrier: $b, shipping: 500);

        $sh = $this->get('/api/gestion/statistiques?' . self::RANGE . '&sections=shipping')['sections']['shipping'];
        $this->assertSame(['indicators', 'breakdown', 'chart', 'caveats', 'appliesSource'], array_keys($sh));
        $values = fn (array $i): array => [$i['value'], $i['unit'], $i['previous'], $i['lastYear'], $i['changeVsPrevious'], $i['changeVsLastYear']];
        // facturé 15 + 15 + 0 + 8 − 15 = 23,00 ; 3 ventes avec transporteur ; 7,67 par vente
        $this->assertSame(['billed' => [2300, 'money', 1000, 500, 130, 360], 'orders' => [3, 'count', 1, 1, 200, 200], 'averagePerOrder' => [767, 'money', 1000, 500, -23.3, 53.4]], array_map($values, $sh['indicators']));
        $this->assertSame([
            ['carrierId' => $a, 'name' => 'Transporteur A S2-TEST', 'kind' => 'fixed', 'billed' => 1500, 'orders' => 2, 'share' => 65.2],
            ['carrierId' => null, 'name' => 'Sans transporteur', 'kind' => null, 'billed' => 800, 'orders' => 0, 'share' => 34.8],
            ['carrierId' => $b, 'name' => 'Retrait B S2-TEST', 'kind' => 'pickup', 'billed' => 0, 'orders' => 1, 'share' => 0],
        ], $sh['breakdown'], 'le prix catalogue du transporteur (99,99) ne compte pas : seul le port facturé');
        $this->assertSame([2300, 3], [array_sum(array_column($sh['breakdown'], 'billed')), array_sum(array_column($sh['breakdown'], 'orders'))]);
        $points = array_column($sh['chart']['points'], null, 'key');
        $this->assertSame([['billed' => 1500, 'orders' => 1], ['billed' => -1500, 'orders' => 0], ['billed' => 500, 'orders' => 1], [0, 1500, 1500, 0, 0, 800, 0, -1500, 0, 0]],
            [array_intersect_key($points['2007-04-02'], ['billed' => 1, 'orders' => 1]), array_intersect_key($points['2007-04-08'], ['billed' => 1, 'orders' => 1]), $points['2007-04-04']['lastYear'], $sh['indicators']['billed']['spark']]);
        $this->assertSame([['SHIPPING_BILLED_NOT_COST'], true], [array_column($sh['caveats'], 'code'), $sh['appliesSource']]);

        // Par canal
        $of = fn (string $source) => $this->get('/api/gestion/statistiques?' . self::RANGE . "&sections=shipping&source=$source")['sections']['shipping'];
        $this->assertSame([[1500, 2, 750], [0, 1, 0], [800, 0, null]], [array_column($of('ecommerce')['indicators'], 'value'), array_column($of('pos')['indicators'], 'value'), array_column($of('mobile_app')['indicators'], 'value')]);
        $this->assertSame([[$a], [null], [$b]], [array_column($of('ecommerce')['breakdown'], 'carrierId'), array_column($of('mobile_app')['breakdown'], 'carrierId'), array_column($of('pos')['breakdown'], 'carrierId')]);
        $this->assertSame('No carrier', $this->get('/api/gestion/statistiques?' . self::RANGE . '&sections=shipping&locale=en')['sections']['shipping']['breakdown'][1]['name']);

        // Recoupement : sur une période, le port facturé du résultat de période, au cent près
        $periodId = (int) $this->db->fetchOne("INSERT INTO collections (id, budget_collection, start_date_collection, end_date_collection, del, nom_collection, is_closed, kind, period_key)"
            . " VALUES (nextval('collections_id_seq'), 0, '2007-04-01', '2007-04-30', false, 'Avril 2007 S2-TEST', false, 'period', 'T-S2-TEST') RETURNING id");
        $result = static::getContainer()->get(PeriodResultCalculator::class)->compute($this->em()->find(\App\Entity\Collections::class, $periodId), null)['sales']['shippingBilled'];
        $this->assertSame([2300, $result], [$result, $this->get("/api/gestion/statistiques?periodId=$periodId&sections=shipping")['sections']['shipping']['indicators']['billed']['value']]);
    }

    public function testTaxesAreTheCollectedTaxesByTaxWithReturnsAsNegatives(): void
    {
        $legacy = (int) $this->db->fetchOne("INSERT INTO tax (id, name, rate, type, code) VALUES (nextval('tax_id_seq'), 'Taxe S2-TEST', 3, 'percent', 'S2T') RETURNING id");
        $tax = fn (int $order, int $amount, ?string $code, ?string $label = null, ?int $base = null, ?int $ppm = null, ?string $jurisdiction = null, ?int $taxId = null) => $this->db->executeStatement(
            "INSERT INTO order_tax (id, order_tax_id, tax_id, amount, base, tax_label, tax_rate_ppm, tax_code, tax_jurisdiction) VALUES (nextval('order_tax_id_seq'), ?, ?, ?, ?, ?, ?, ?, ?)",
            [$order, $taxId, $amount, $base, $label, $ppm, $code, $jurisdiction]);
        $both = function (int $order, int $gst, int $qst, int $base) use ($tax): void {
            $tax($order, $gst, 'gst', 'TPS', $base, 50000, 'CA');
            $tax($order, $qst, 'qst', 'TVQ', $base, 99750, 'QC');
        };
        // Plage : caisse (5,00 + 9,98), web (2,50 + 4,99), retour caisse (−1,00 − 2,00), une ligne d'avant les codes (3,00) ; annulée et incomplète exclues
        $both($this->order('2007-04-02', 1, 3, 2, 11498, []), 500, 998, 10000);
        $both($this->order('2007-04-05', 1, 3, 1, 5749, []), 250, 499, 5000);
        $both($this->order('2007-04-08', 2, 6, 2, 2300, []), -100, -200, -2000);
        $tax($this->order('2007-04-06', 1, 3, 1, 10300, []), 300, null, taxId: $legacy);
        $both($this->order('2007-04-09', 1, 7, 2, 9999, []), 999, 999, 9999);
        $both($this->order('2007-04-09', 1, 1, 1, 8888, []), 888, 888, 8888);
        // Précédente : 1,00 ; an dernier : 4,00
        $tax($this->order('2007-03-25', 1, 3, 2, 2100, []), 100, 'gst', 'TPS', 2000, 50000, 'CA');
        $tax($this->order('2006-04-03', 1, 3, 1, 8400, []), 400, 'gst', 'TPS', 8000, 50000, 'CA');

        $tx = $this->get('/api/gestion/statistiques?' . self::RANGE . '&sections=taxes')['sections']['taxes'];
        $this->assertSame(['indicators', 'breakdown', 'chart', 'caveats', 'appliesSource'], array_keys($tx));
        $i = $tx['indicators']['collected'];
        // 5,00 + 9,98 + 2,50 + 4,99 − 1,00 − 2,00 + 3,00 = 22,47
        $this->assertSame([2247, 'money', 100, 400, 2147, 461.8, 'percent'], [$i['value'], $i['unit'], $i['previous'], $i['lastYear'], $i['changeVsPrevious'], $i['changeVsLastYear'], $i['changeUnit']]);
        $this->assertSame([
            ['code' => 'qst', 'label' => 'TVQ', 'jurisdiction' => 'QC', 'ratePpm' => 99750, 'base' => 13000, 'collected' => 1297, 'share' => 57.7],
            ['code' => 'gst', 'label' => 'TPS', 'jurisdiction' => 'CA', 'ratePpm' => 50000, 'base' => 13000, 'collected' => 650, 'share' => 28.9],
            ['code' => 'S2T', 'label' => 'Taxe S2-TEST', 'jurisdiction' => null, 'ratePpm' => null, 'base' => null, 'collected' => 300, 'share' => 13.4],
        ], $tx['breakdown'], 'par taxe ; une ligne d\'avant les codes prend le code et le nom de sa taxe');
        $this->assertSame(2247, array_sum(array_column($tx['breakdown'], 'collected')));
        $points = array_column($tx['chart']['points'], null, 'key');
        $this->assertSame([1498, -300, ['collected' => 400], [0, 1498, 0, 0, 749, 300, 0, -300, 0, 0]], [$points['2007-04-02']['collected'], $points['2007-04-08']['collected'], $points['2007-04-03']['lastYear'], $i['spark']]);
        $this->assertSame([['TAXES_NOT_A_RETURN'], true], [array_column($tx['caveats'], 'code'), $tx['appliesSource']]);
        $this->assertStringContainsString('n\'en sont pas une', $tx['caveats'][0]['text']);

        // Par canal : la caisse (vente et retour), la boutique en ligne
        $of = fn (string $source) => $this->get('/api/gestion/statistiques?' . self::RANGE . "&sections=taxes&source=$source")['sections']['taxes'];
        $this->assertSame([[1198, 100, null], [1049, 0, 400]], [[$of('pos')['indicators']['collected']['value'], $of('pos')['indicators']['collected']['previous'], $of('pos')['indicators']['collected']['lastYear']],
            [$of('ecommerce')['indicators']['collected']['value'], $of('ecommerce')['indicators']['collected']['previous'], $of('ecommerce')['indicators']['collected']['lastYear']]]);
        $this->assertSame([['qst', 798], ['gst', 400]], array_map(fn (array $t) => [$t['code'], $t['collected']], $of('pos')['breakdown']));
        // Aucune taxe : zéro, liste vide, pas de comparaison à l'an dernier
        $empty = $this->get('/api/gestion/statistiques?from=2007-06-01&to=2007-06-10&sections=taxes')['sections']['taxes'];
        $this->assertSame([0, null, []], [$empty['indicators']['collected']['value'], $empty['indicators']['collected']['lastYear'], $empty['breakdown']]);
    }

    // ---------------------------------------------------------------- aides

    /**
     * Commande datée à l'heure du magasin : type (1 vente, 2 retour), statut, canal, total TTC en cents, paiements réussis [[moyen, montant]]
     *
     * @return int identifiant de la commande
     */
    private function order(string $day, int $type, int $status, int $source, int $total, array $payments, int $giftCard = 0, string $time = '12:00', ?int $carrier = null, ?int $shipping = null): int
    {
        $id = (int) $this->db->fetchOne(
            "INSERT INTO \"order\" (id, reference, order_date, order_type_id, status_id, order_source_id, user_id_id, total_amount, gift_card_applied, carrier_id, shipping_cost) VALUES (nextval('order_id_seq'), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) RETURNING id",
            [self::TAG, $this->utc($day, $time), $type, $status, $source, $this->userId, $total, $giftCard, $carrier, $shipping]
        );
        foreach ($payments as [$method, $amount]) {
            $this->payment($id, $method, $amount, 2);
        }

        return $id;
    }

    private function payment(int $order, int $method, int $amount, int $status): void
    {
        $this->db->executeStatement("INSERT INTO payments (id, order_payment_id, payment_method_id, statut_payment_id, payment_type_id, amount, payment_date) VALUES (nextval('payments_id_seq'), ?, ?, ?, ?, ?, NOW())",
            [$order, $method, $status, $amount < 0 ? 1 : 2, $amount]);
    }

    private function note(string $day, int $amount, ?int $type): void
    {
        $this->db->executeStatement("INSERT INTO note_de_frais (id, name, montant, date, type_note_de_frais_id) VALUES (nextval('note_de_frais_id_seq'), ?, ?, ?, ?)", [self::TAG, $amount, $day, $type]);
    }

    private function purchaseShipping(string $day, int $price, ?float $rate): void
    {
        $order = (int) $this->db->fetchOne("INSERT INTO commande (id, name, budget, date, exchange_rate) VALUES (nextval('commande_id_seq'), ?, 0, ?, ?) RETURNING id", [self::TAG, $day . ' 10:00:00', $rate]);
        $this->db->executeStatement("INSERT INTO frais_de_port (id, name, price, commande_id) VALUES (nextval('frais_de_port_id_seq'), ?, ?, ?)", [self::TAG, $price, $order]);
    }

    private function campaign(string $name, string $start, int $amount, ?float $rate): void
    {
        $this->db->executeStatement("INSERT INTO marketing_campaign (name, channel, start_date, end_date, amount_cents, exchange_rate, created_by, created_at, updated_at) VALUES (?, 'other', ?, ?, ?, ?, ?, NOW(), NOW())",
            [$name, $start, $start, $amount, $rate, self::TAG]);
    }

    /** Heure du magasin => instant UTC, tel que la base le garde */
    private function utc(string $day, string $time): string
    {
        return (new \DateTimeImmutable("$day $time", static::getContainer()->get(StoreClock::class)->timezone()))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private function clean(): void
    {
        $this->db->executeStatement("DELETE FROM payments WHERE order_payment_id IN (SELECT id FROM \"order\" WHERE reference = ?)", [self::TAG]);
        $this->db->executeStatement("DELETE FROM order_tax WHERE order_tax_id IN (SELECT id FROM \"order\" WHERE reference = ?)", [self::TAG]);
        $this->db->executeStatement("DELETE FROM inventory_movements WHERE product_variant_id IN (SELECT v.id FROM product_variant v JOIN product p ON p.id = v.product_id WHERE p.slug LIKE 's2-test-%')");
        $this->db->executeStatement("DELETE FROM product_variant WHERE product_id IN (SELECT id FROM product WHERE slug LIKE 's2-test-%')");
        $this->db->executeStatement("DELETE FROM product WHERE slug LIKE 's2-test-%'");
        $this->db->executeStatement("DELETE FROM gestion_stock_snapshot WHERE snapshot_date BETWEEN '2007-01-01' AND '2007-12-31'");
        $this->db->executeStatement('DELETE FROM "order" WHERE reference = ?', [self::TAG]);
        $this->db->executeStatement("DELETE FROM carrier WHERE description = 'S2-TEST'");
        $this->db->executeStatement("DELETE FROM tax WHERE code = 'S2T'");
        $this->db->executeStatement('DELETE FROM note_de_frais WHERE name = ?', [self::TAG]);
        $this->db->executeStatement('DELETE FROM frais_de_port WHERE name = ?', [self::TAG]);
        $this->db->executeStatement('DELETE FROM commande WHERE name = ?', [self::TAG]);
        $this->db->executeStatement('DELETE FROM marketing_campaign WHERE created_by = ?', [self::TAG]);
        $this->db->executeStatement("DELETE FROM gestion_cash_movement WHERE label = 'Loyer S2-TEST'");
        $this->db->executeStatement("DELETE FROM cash_register_report WHERE cash_register_id IN (SELECT id FROM cash_register WHERE name = 'Poste S2-TEST')");
        $this->db->executeStatement("DELETE FROM transaction_caisse WHERE caisse_id IN (SELECT c.id FROM caisse c JOIN cash_register r ON r.id = c.cash_register_id WHERE r.name = 'Poste S2-TEST')");
        $this->db->executeStatement("DELETE FROM caisse WHERE cash_register_id IN (SELECT id FROM cash_register WHERE name = 'Poste S2-TEST')");
        $this->db->executeStatement("DELETE FROM cash_register WHERE name = 'Poste S2-TEST'");
        $this->db->executeStatement("DELETE FROM collections WHERE period_key = 'T-S2-TEST'");
    }

    private function status(): int
    {
        return $this->client->getResponse()->getStatusCode();
    }

    private function body(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    private function request(string $method, string $route): void
    {
        $this->client->request($method, $route, [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $this->token]);
    }

    private function get(string $route): array
    {
        $this->request('GET', $route);
        $this->assertSame(200, $this->status(), $route . ' : ' . substr((string) $this->client->getResponse()->getContent(), 0, 400));

        return $this->body();
    }

    private function em(): EntityManagerInterface
    {
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);

        return $provider->getEntityManager();
    }

    private function login(): void
    {
        $email = 'gestion-s2-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $em = $this->em();
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Gestion')->setLastname('S2')->setRoles(['ROLE_ADMIN'])->setIsVerified(true)->setPassword('inutilisable');
        $em->persist($user);
        $em->flush();
        $this->userId = (int) $user->getId();
        $this->token = static::getContainer()->get('lexik_jwt_authentication.jwt_manager')->create($user);
        static::getContainer()->get(CacheInvalidationSubscriber::class)->invalidateWrittenTenants();
    }
}
