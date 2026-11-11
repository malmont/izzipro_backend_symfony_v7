<?php

namespace App\Tests\Functional\Gestion;

use App\Entity\Collections;
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
use App\Gestion\Services\GestionSimulationCommentService;
use App\Gestion\Services\GestionStatisticsService;
use App\Gestion\Services\GestionYearService;
use App\Services\TenantEntityManagerProvider;
use App\Tests\Fake\FakeLandingAiClient;
use App\Tests\Functional\Cache\CacheTestEntityFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Lot AN + EX, l'année de l'entreprise et l'exercice du site (11/11/2026, `docs/annee-gestion.md`, contrats validés par l'application) :
 * séries mensuelles aux règles de P2, frais variables à leur date, frais fixes au prorata, résultat et ses composantes, projection (an dernier
 * pour les ventes ; budget, an dernier ou rythme des 90 jours pour les frais, source par mois), point mort, écart, leviers, année terminée et
 * « ce qui aurait suffi », avis de l'IA (cache séparé de G1b-2 et P6), exercice d'avril à mars (ordre des mois, périodes à cheval), S1
 * range=year. Données de 2001 et 2002 (aucune autre donnée datée là) ; « aujourd'hui » fixé au 15/06/2002 pour les calculs exacts.
 */
class YearApiTest extends WebTestCase
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
        FakeLandingAiClient::reset();
    }

    protected function tearDown(): void
    {
        $this->clean();
        parent::tearDown();
    }

    public function testYearProjectionLeversFinishedYearAdviceAndFiscalYear(): void
    {
        $conn = $this->em()->getConnection();
        $tag = bin2hex(random_bytes(2));
        $conn->executeStatement("INSERT INTO product (id, name, price, isbestseller, quantity, created_at, slug, product_type, purchase_price) VALUES (nextval('product_id_seq'), 'AN P', 2500, false, 0, NOW(), ?, 'standard', 1000)", ["an-test-p-$tag"]);
        $p = (int) $this->create(ProductVariant::class, ['product' => $this->em()->find(Product::class, (int) $conn->fetchOne('SELECT id FROM product WHERE slug = ?', ["an-test-p-$tag"]))])->getId();
        // 2001 : mars 4 × 25,00 $ (port d'achat 3,00 $ le 5), septembre 2 × 25,00 $ (note 10,00 $ le 12) ; 2002 : février 2 ×, mai 1 × (note 7,00 $ le 21)
        foreach ([['2001-03-10', 4], ['2001-09-10', 2], ['2002-02-10', 2], ['2002-05-20', 1]] as [$day, $qty]) {
            $this->sale($day, [[$p, $qty, 2500]]);
        }
        $conn->executeStatement("INSERT INTO commande (id, budget, date, name, is_closed) VALUES (nextval('commande_id_seq'), 0, '2001-03-05 12:00:00', 'AN achat', false)");
        $conn->executeStatement("INSERT INTO frais_de_port (id, name, price, commande_id) VALUES (nextval('frais_de_port_id_seq'), 'AN fret', 300, (SELECT id FROM commande WHERE name = 'AN achat'))");
        foreach ([['2001-09-12', 1000], ['2002-05-21', 700]] as [$day, $cents]) {
            $conn->executeStatement("INSERT INTO note_de_frais (id, name, montant, date) VALUES (nextval('note_de_frais_id_seq'), ?, ?, ?)", ["AN note $day", $cents, $day]);
        }
        // Location du 15/03/2001 (40,00 $, autre revenu) et note de voyage du 20/01/2001 (4,00 $, poste « trip », pas aussi dans « notes »)
        $this->sale('2001-03-15', [[$p, 1, 4000]]);
        $item = (int) $conn->fetchOne("SELECT oi.id FROM order_items oi JOIN \"order\" o ON o.id = oi.order_associated_id WHERE o.reference = 'AN-TEST' AND oi.total_price = 4000");
        $conn->executeStatement("INSERT INTO booking (id, start_at, end_at, quantity, status, created_at, order_item_id) VALUES (nextval('booking_id_seq'), '2001-03-20 10:00', '2001-03-21 10:00', 1, 'CONFIRMED', NOW(), ?)", [$item]);
        $conn->executeStatement("INSERT INTO note_de_frais (id, name, montant, date, trip_id) VALUES (nextval('note_de_frais_id_seq'), 'AN note voyage', 400, '2001-01-20', 999999)");
        // Frais fixe : loyer 36,50 $ par mois = 1,20 $ par jour (FF1)
        $conn->executeStatement("INSERT INTO gestion_cash_movement (label, category, direction, amount_cents, start_date, recurrence, created_at, updated_at) VALUES ('AN loyer', 'rent', 'out', 3650, '2000-01-01', 'monthly', NOW(), NOW())");
        $this->endOfWrite();
        $years = static::getContainer()->get(GestionYearService::class);
        $today = new \DateTimeImmutable('2002-06-15');

        // Exercice 2002 en cours au 15/06/2002
        $y = $years->overview(['year' => '2002'], 'fr', $today);
        $this->assertSame(['in_progress', ['startMonth' => 1, 'from' => '2002-01-01', 'to' => '2002-12-31', 'label' => '2002']], [$y['status'], $y['fiscalYear']]);
        $this->assertSame([1, true, 'partial', false], [$y['months'][0]['month'], $y['months'][0]['elapsed'], $y['months'][5]['elapsed'], $y['months'][6]['elapsed']]);
        $this->assertNull($y['months'][6]['sales'], 'mois à venir : valeurs nulles');
        $this->assertSame([5000, 3000, -360], [$y['months'][1]['sales'], $y['months'][1]['grossMargin'], $y['months'][1]['result']], 'février : 2 × 25,00 $, marge 60 %, loyer 28 × 1,20 $');
        $t = $y['totals'];
        $this->assertSame([7500, 4500, ['shipping' => 0, 'advertising' => 0, 'notes' => 700, 'trip' => 0, 'total' => 700], 19920, -16120, -16120, 0],
            [$t['sales'], $t['grossMargin'], $t['variableCosts'], $t['fixedCosts'], $t['resultBeforeOtherRevenue'], $t['result'], $t['otherRevenue']['total']], 'résultat = marge + autres revenus − frais variables − frais fixes');
        $this->assertSame($t['grossMargin'] - $t['variableCosts']['total'] - $t['fixedCosts'], $t['resultBeforeOtherRevenue'], 'l\'addition se refait');
        $this->assertSame([-16120, 7500], [$y['cumulative'][5]['result'], $y['cumulative'][5]['sales']], 'cumul : juin, depuis janvier');
        $this->assertNull(end($y['cumulative'])['result']);
        $pr = $y['profitability'];
        $this->assertEquals(60.0, $pr['tauxMarque'], 'taux de marque en %, arrondi à 0,1');
        $proj = $pr['projection'];
        $this->assertSame(['lastYear', 12500, 7500, 3014, 43800, -39314], [$proj['method'], $proj['sales'], $proj['grossMargin'], $proj['variableCosts']['total'], $proj['fixedCosts'], $proj['result']]);
        $pm = array_column($proj['months'], null, 'month');
        $this->assertSame([[6, '2002-06-16', 117, 'lastYear', 'pace'], [9, '2002-09-01', 1000, 'lastYear', 'lastYear']], [
            [$pm[6]['month'], $pm[6]['from'], $pm[6]['variableCosts']['total'], $pm[6]['sources']['sales'], $pm[6]['sources']['variableCosts']],
            [$pm[9]['month'], $pm[9]['from'], $pm[9]['variableCosts']['total'], $pm[9]['sources']['sales'], $pm[9]['sources']['variableCosts']],
        ], 'frais : même mois l\'an dernier s\'il en a, sinon rythme des 90 jours (7,00 $ ÷ 90 par jour)');
        $this->assertSame([5000, 3000], [$pm[9]['sales'], $pm[9]['grossMargin']], 'ventes de septembre l\'an dernier, au taux de marque de l\'année');
        $this->assertSame(['sales' => 78024, 'reached' => false], $pr['breakEven'], 'point mort = (frais variables + frais fixes − autres revenus) ÷ (taux de marque ÷ 100)');
        $this->assertSame([39314, 65524, ['margin' => 6009, 'sales' => 10015]], [$pr['gap']['margin'], $pr['gap']['sales'], array_intersect_key($pr['gap']['perRemainingMonth'], ['margin' => 1, 'sales' => 1])]);
        $levers = array_column($y['levers'], null, 'key');
        $this->assertSame(['worstPeriod', 'fixedCostsWeight', 'price', 'dormantStock'], array_column($y['levers'], 'key'), 'triés par effet décroissant');
        $this->assertEquals([250, 786.3, 5], [$levers['price']['effect'], $levers['price']['requiredPercent'], $levers['price']['percent']]);
        $this->assertStringContainsString('à quantités égales', $levers['price']['detail']);
        $this->assertEquals([2388, 584.0], [$levers['fixedCostsWeight']['effect'], $levers['fixedCostsWeight']['weight']]);
        $this->assertSame([3720, 1, null], [$levers['worstPeriod']['effect'], $levers['worstPeriod']['month'], $levers['worstPeriod']['periodId']], 'aucune période : le mois le plus déficitaire (janvier)');
        $this->assertSame([false, false], [$levers['price']['coversGap'], $levers['worstPeriod']['coversGap']]);
        $this->assertStringContainsString('at equal quantities', array_column($years->overview(['year' => '2002', 'pricePercent' => '10'], 'en', $today)['levers'], null, 'key')['price']['detail'], 'libellés selon la langue');
        $this->assertSame(['PROJECTION_LAST_YEAR', 'PARTIAL_MONTH', 'FIXED_COSTS_PRORATED'], array_column($y['caveats'], 'code'));
        $this->assertSame(2001, $y['lastYear']['year']);
        $this->assertSame([10000, 4000, 5980], [$y['lastYear']['months'][2]['sales'], $y['lastYear']['months'][2]['otherRevenue']['total'], $y['lastYear']['months'][2]['result']], 'an dernier : mars 2001, location 40,00 $, port 3,00 $, loyer 31 × 1,20 $');

        // Exercice 2001 terminé : bilan, meilleurs et pires mois, « ce qui aurait suffi » ; une période pour ranger l'avis
        $conn->executeStatement("INSERT INTO collections (id, budget_collection, start_date_collection, end_date_collection, del, nom_collection, is_closed, kind, period_key) VALUES (nextval('collections_id_seq'), 0, '2001-03-01', '2001-05-31', false, 'AN printemps', false, 'period', ?)", ['an-' . $tag]);
        $f = $years->overview(['year' => '2001'], 'fr', $today);
        $this->assertSame(['finished', -32500, -36500, 4000, false, null], [$f['status'], $f['finished']['bilan']['result'], $f['finished']['bilan']['resultBeforeOtherRevenue'], $f['finished']['bilan']['otherRevenue']['total'], $f['finished']['bilan']['profitable'], $f['levers']]);
        $this->assertSame(['shipping' => 300, 'advertising' => 0, 'notes' => 1000, 'trip' => 400, 'total' => 1700], $f['totals']['variableCosts'], 'note de voyage dans « trip » seulement');
        $this->assertSame(['month' => 3, 'result' => 5980, 'sales' => 10000], $f['finished']['bestMonths'][0]);
        $this->assertSame(['month' => 1, 'result' => -4120, 'sales' => 0], $f['finished']['worstMonths'][0]);
        $this->assertEquals(['priceRequiredPercent' => 216.7, 'fixedCostsCutRequired' => 32500, 'salesRequired' => 69167], $f['finished']['hindsight']);
        $q = $f['periods'][0];
        $this->assertSame(['AN printemps', 10000, 6000, 5700, 5700 - 92 * 120, 4000, false, 92, 92, false], [$q['name'], $q['revenue'], $q['grossMargin'], $q['net'], $q['netAfterFixed'], $q['otherRevenue'], $q['partial'], $q['overlapDays'], $q['days'], $q['frozen']],
            'période de l\'exercice : résultat P2 (net sans les autres revenus), autres revenus à côté');

        // Avis de l'IA : année terminée seulement ; réponse vérifiée, mise en cache, séparée des avis G1b-2 / P6
        try {
            $years->advise(['year' => '2002'], 'fr', null, $today);
            $this->fail('YEAR_NOT_FINISHED attendu');
        } catch (\App\Gestion\Services\GestionConflictException $e) {
            $this->assertSame([409, 'YEAR_NOT_FINISHED'], [$e->status, $e->errorCode]);
        }
        FakeLandingAiClient::$queue[] = json_decode(json_encode(['type' => 'message', 'role' => 'assistant', 'model' => 'claude-sonnet-5-5', 'stop_reason' => 'end_turn',
            'content' => [['type' => 'text', 'text' => json_encode(['recommended' => 'year', 'title' => 'Une année à reprendre', 'text' => 'Résultat de -325,00 $ : les frais fixes pèsent lourd.}', 'lessons' => ['Préparez mars plus tôt.', 'Revoyez le loyer.']], JSON_UNESCAPED_UNICODE)]],
            'usage' => ['input_tokens' => 10, 'output_tokens' => 10]]));
        $a = $years->advise(['year' => '2001'], 'fr', null, $today);
        $this->assertSame(['ai', 'Résultat de -325,00 $ : les frais fixes pèsent lourd.', ['Préparez mars plus tôt.', 'Revoyez le loyer.'], false], [$a['comment']['textSource'], $a['comment']['text'], $a['comment']['lessons'], $a['cached']]);
        $this->assertArrayNotHasKey('recommended', $a['comment']);
        $this->assertTrue($years->advise(['year' => '2001'], 'fr', null, $today)['cached'], 'même exercice, même jour : en cache');
        $period = $this->em()->getRepository(Collections::class)->findOneBy(['periodKey' => 'an-' . $tag]);
        $comments = static::getContainer()->get(GestionSimulationCommentService::class);
        $row = $conn->fetchAssociative('SELECT body_hash, response FROM gestion_simulation_comment WHERE period_id = ?', [$period->getId()]);
        $this->assertSame('year', json_decode($row['response'], true)['kind']);
        $simHash = hash('sha256', (string) json_encode([0, null, 0, [], 'fr'])); // forme d'une empreinte G1b-2
        $prepHash = hash('sha256', 'prep:' . json_encode([[], 'fr', 'x']));     // forme d'une empreinte P6
        $this->assertNotContains($row['body_hash'], [$simHash, $prepHash]);
        $this->assertNull($comments->cached($period, $simHash, '2002-06-15'), 'un avis du simulateur ne lit jamais l\'avis de l\'année');
        $this->assertNull($comments->cached($period, $prepHash, '2002-06-15'), 'ni un avis de la préparation');
        // Une ligne étrangère rangée sous l'empreinte de l'année (sans kind) n'est pas prise pour l'avis de l'année
        $conn->executeStatement("UPDATE gestion_simulation_comment SET response = ? WHERE period_id = ?", [json_encode(['simulation' => [], 'comment' => ['recommended' => 'keep', 'title' => 'G1', 'text' => 'Avis du simulateur', 'textSource' => 'rules']]), $period->getId()]);
        FakeLandingAiClient::$queue[] = json_decode(json_encode(['type' => 'message', 'role' => 'assistant', 'model' => 'claude-sonnet-5-5', 'stop_reason' => 'end_turn',
            'content' => [['type' => 'text', 'text' => json_encode(['recommended' => 'year', 'title' => 'Titre', 'text' => 'Texte sans chiffre.', 'lessons' => ['Une.', 'Deux.']])]], 'usage' => ['input_tokens' => 1, 'output_tokens' => 1]]));
        $again = $years->advise(['year' => '2001'], 'fr', null, $today);
        $this->assertSame([false, 'Texte sans chiffre.'], [$again['cached'], $again['comment']['text']], 'pas un avis de l\'année : recalculé');

        // Exercice d'avril à mars : ordre des mois, libellé, exercice précédent, période à cheval, S1 range=year
        $put = $this->send('PUT', '/api/gestion/reglages/exercice', ['startMonth' => 4], 200);
        $this->assertSame(4, $put['startMonth']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{4}$/', $put['label']);
        $fy = $years->overview(['year' => '2001'], 'fr', $today);
        $this->assertSame([['startMonth' => 4, 'from' => '2001-04-01', 'to' => '2002-03-31', 'label' => '2001-2002'], 'finished', [4, 2001], [3, 2002]],
            [$fy['fiscalYear'], $fy['status'], [$fy['months'][0]['month'], $fy['months'][0]['year']], [$fy['months'][11]['month'], $fy['months'][11]['year']]]);
        $this->assertSame([10000, 5000, 10000], [$fy['totals']['sales'], $fy['cumulative'][5]['sales'], $fy['lastYear']['months'][11]['sales']], 'septembre 2001 + février 2002 ; exercice précédent : mars 2001 en dernier mois');
        $this->assertSame([true, 61, 92], [$fy['periods'][0]['partial'], $fy['periods'][0]['overlapDays'], $fy['periods'][0]['days']]);
        $codes = array_column($fy['caveats'], null, 'code');
        $this->assertSame([3, 6, 9, 12], $codes['FISCAL_YEAR_NOT_ALIGNED_WITH_PERIODS']['alignedMonths'] ?? null);
        $this->assertSame(['year', ['2002-04-01', '2002-06-15'], null], static::getContainer()->get(GestionStatisticsService::class)->ranges(['range' => 'year'], $today), 'S1 : l\'exercice en cours');
        try {
            $years->overview(['year' => '2003'], 'fr', $today);
            $this->fail('INVALID_YEAR attendu');
        } catch (\App\Gestion\Services\GestionValidationException $e) {
            $this->assertSame(['year' => 'INVALID_YEAR'], $e->fields);
        }
        $this->assertSame('2002-04-01', $years->overview(['year' => '2002'], 'fr', $today)['fiscalYear']['from'], 'exercice 2002 au 15/06/2002 : commencé en avril');
    }

    public function testRoutesAndValidation(): void
    {
        $s = $this->get('/api/gestion/reglages/exercice');
        $this->assertSame(1, $s['startMonth']);
        foreach ([['startMonth' => 13], ['startMonth' => 'avril'], []] as $i => $body) {
            $this->request('PUT', '/api/gestion/reglages/exercice', $body);
            $this->assertSame([422, ['startMonth' => ['OUT_OF_RANGE', 'MUST_BE_INTEGER', 'REQUIRED'][$i]]], [$this->status(), $this->body()['fields'] ?? null]);
        }
        $this->assertSame(1, $this->send('PUT', '/api/gestion/reglages/exercice', ['startMonth' => 1], 200)['startMonth']);
        $year = $this->get('/api/gestion/statistiques/annee');
        $this->assertSame(['in_progress', 1], [$year['status'], $year['fiscalYear']['startMonth']]);
        $this->assertCount(12, $year['months']);
        foreach (['year=1999' => ['year' => 'INVALID_YEAR'], 'year=2999' => ['year' => 'INVALID_YEAR'], 'pricePercent=31' => ['pricePercent' => 'OUT_OF_RANGE']] as $q => $fields) {
            $this->request('GET', "/api/gestion/statistiques/annee?$q");
            $this->assertSame([422, $fields], [$this->status(), $this->body()['fields'] ?? null], $q);
        }
        $this->request('POST', '/api/gestion/statistiques/annee/avis');
        $this->assertSame([409, 'YEAR_NOT_FINISHED'], [$this->status(), $this->body()['error'] ?? null]);
    }

    // ----- helpers -----

    private function sale(string $day, array $lines): void
    {
        $tz = static::getContainer()->get(StoreClock::class)->timezone();
        $at = (new \DateTimeImmutable("$day 12:00", $tz))->setTimezone(new \DateTimeZone('UTC'));
        $order = $this->create(Order::class, ['totalAmount' => 1.0, 'reference' => 'AN-TEST'], function (Order $o, EntityManagerInterface $em) use ($at): void {
            $o->setOrderDate(\DateTime::createFromImmutable($at))->setStatus($em->find(StatusCommande::class, 3))->setOrderType($em->find(OrderType::class, 1))
                ->setOrderSource($em->find(OrderSource::class, 2))->setUserId($em->find(User::class, $this->userId));
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
        $conn->executeStatement("DELETE FROM booking WHERE order_item_id IN (SELECT oi.id FROM order_items oi JOIN \"order\" o ON o.id = oi.order_associated_id WHERE o.reference = 'AN-TEST')");
        $conn->executeStatement("DELETE FROM order_items WHERE order_associated_id IN (SELECT id FROM \"order\" WHERE reference = 'AN-TEST')");
        $conn->executeStatement("DELETE FROM \"order\" WHERE reference = 'AN-TEST'");
        $conn->executeStatement("DELETE FROM product_variant WHERE product_id IN (SELECT id FROM product WHERE slug LIKE 'an-test-%')");
        $conn->executeStatement("DELETE FROM product WHERE slug LIKE 'an-test-%'");
        $conn->executeStatement("DELETE FROM frais_de_port WHERE name = 'AN fret'");
        $conn->executeStatement("DELETE FROM commande WHERE name = 'AN achat'");
        $conn->executeStatement("DELETE FROM note_de_frais WHERE name LIKE 'AN note%'");
        $conn->executeStatement("DELETE FROM collections WHERE nom_collection = 'AN printemps'");
        $conn->executeStatement('DELETE FROM gestion_cash_movement');
        $conn->executeStatement('UPDATE entreprise SET fiscal_year_start_month = NULL');
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
        $email = 'gestion-an-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $em = $this->em();
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Gestion')->setLastname('An')->setRoles(['ROLE_ADMIN'])->setIsVerified(true)->setPassword('inutilisable');
        $em->persist($user);
        $em->flush();
        $this->userId = (int) $user->getId();
        $this->token = static::getContainer()->get('lexik_jwt_authentication.jwt_manager')->create($user);
        $this->endOfWrite();
    }
}
