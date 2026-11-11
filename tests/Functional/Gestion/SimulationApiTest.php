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
use App\Gestion\Services\GestionSimulationService;
use App\Gestion\Services\PeriodCalendar;
use App\Services\TenantEntityManagerProvider;
use App\Tests\Functional\Cache\CacheTestEntityFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Lot B1b-2, simulateur de période (11/11/2026, `docs/simulateur-gestion.md`, contrat validé par l'application) : validation des leviers,
 * période manuelle (404) ou fermée (409), aucune écriture, réalisé jamais touché par les leviers de prix, rythme des 30 jours par variante
 * plafonné au stock, coût inconnu hors marges, frais = plus grand du prévu et du réel, point mort, stock de fin, trésorerie après achats,
 * repli sur l'an dernier, aucune vente, delta, plannedToSave.
 * Calculs exacts sur des périodes insérées loin dans le futur (base partagée : aucune autre donnée datée là), supprimées à la fin.
 */
class SimulationApiTest extends WebTestCase
{
    use CacheTestEntityFactory;

    private KernelBrowser $client;
    private string $token = '';
    private int $userId = 0;
    /** @var list<int> */
    private array $periods = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em()->getConnection()->executeStatement('DELETE FROM gestion_cash_balance');
        $this->em()->getConnection()->executeStatement('DELETE FROM gestion_cash_movement');
        $this->login();
    }

    protected function tearDown(): void
    {
        $conn = $this->em()->getConnection();
        foreach ($this->periods as $id) {
            $conn->executeStatement('DELETE FROM gestion_budget_line WHERE period_id = ?', [$id]);
            $conn->executeStatement('DELETE FROM collections WHERE id = ?', [$id]);
        }
        $conn->executeStatement('DELETE FROM order_items WHERE order_associated_id IN (SELECT id FROM "order" WHERE order_date > NOW() + INTERVAL \'3500 days\')');
        $conn->executeStatement('DELETE FROM "order" WHERE order_date > NOW() + INTERVAL \'3500 days\'');
        $conn->executeStatement("DELETE FROM note_de_frais WHERE name = 'Simulation'");
        $conn->executeStatement("DELETE FROM commande_line WHERE commande_id IN (SELECT id FROM commande WHERE name = 'Simulation')");
        $conn->executeStatement("DELETE FROM commande WHERE name = 'Simulation'");
        $conn->executeStatement('DELETE FROM gestion_cash_balance');
        $conn->executeStatement("DELETE FROM marketing_campaign WHERE name = 'Simulation LY'");
        \App\Tests\Functional\Support\AiRightTestHelper::restoreFullAi(static::getContainer());
        parent::tearDown();
    }

    public function testValidationClosedManualAndNoWrite(): void
    {
        $calendar = static::getContainer()->get(PeriodCalendar::class);
        $pid = (int) $calendar->current()->getId();
        $this->request('POST', "/api/periodes/$pid/simulation", ['pricePercent' => 'x', 'promotion' => ['percent' => 95, 'scope' => 'pigeon'], 'advertisingDelta' => 1.5, 'planned' => ['pigeon' => 1, 'other' => -1]]);
        $this->assertSame([422, ['pricePercent' => 'MUST_BE_NUMBER', 'promotion.percent' => 'OUT_OF_RANGE', 'promotion.scope' => 'INVALID_SCOPE', 'advertisingDelta' => 'MUST_BE_INTEGER_CENTS', 'planned.pigeon' => 'INVALID_CATEGORY', 'planned.other' => 'MUST_BE_INTEGER_CENTS']], [$this->status(), $this->body()['fields']]);
        $this->request('POST', "/api/periodes/$pid/simulation", ['pricePercent' => 301, 'promotion' => ['percent' => 10, 'scope' => 'products', 'productIds' => [999999999, 'a']]]);
        $this->assertSame(['pricePercent' => 'OUT_OF_RANGE', 'promotion.productIds[1]' => 'INVALID_ID', 'promotion.productIds[0]' => 'PRODUCT_NOT_FOUND'], $this->body()['fields']);
        $this->request('POST', "/api/periodes/$pid/simulation", ['promotion' => ['percent' => 10, 'scope' => 'products']]);
        $this->assertSame(['promotion.productIds' => 'REQUIRED'], $this->body()['fields']);

        // Période fermée : 409 ; collection manuelle : 404
        $closed = $this->period(-900, 'sim-closed');
        $this->request('POST', "/api/periodes/$closed/simulation", []);
        $this->assertSame([409, 'PERIOD_CLOSED'], [$this->status(), $this->body()['error']]);
        $manual = (int) $this->em()->getConnection()->fetchOne("INSERT INTO collections (id, budget_collection, start_date_collection, end_date_collection, del, nom_collection, is_closed, kind) VALUES (nextval('collections_id_seq'), 0, NOW(), NOW(), false, 'Manuelle simulation', false, 'manual') RETURNING id");
        $this->periods[] = $manual;
        $this->request('POST', "/api/periodes/$manual/simulation", []);
        $this->assertSame([404, 'NOT_A_PERIOD'], [$this->status(), $this->body()['error']]);

        // Aucune écriture, et le réalisé ne bouge jamais
        $conn = $this->em()->getConnection();
        $snapshot = fn () => $conn->fetchAssociative('SELECT (SELECT COUNT(*) FROM gestion_budget_line) AS b, (SELECT COUNT(*) FROM period_result) AS r, (SELECT COUNT(*) FROM collections) AS c,'
            . ' (SELECT COALESCE(SUM(price), 0) FROM product) AS p, (SELECT COALESCE(SUM(stock_quantity), 0) FROM product_variant) AS s, (SELECT COUNT(*) FROM gestion_cash_balance) AS cash, (SELECT COUNT(*) FROM content_audit_log) AS a');
        $before = $snapshot();
        $sim = $this->send('POST', "/api/periodes/$pid/simulation", ['pricePercent' => 25, 'promotion' => ['percent' => 30], 'advertisingDelta' => 1000, 'planned' => ['purchases' => 123]], 200);
        $this->assertSame($before, $snapshot(), 'aucune écriture');
        $this->assertSame([$sim['base']['sales']['realized'], 0, 0, 0], [$sim['simulated']['sales']['realized'], $sim['delta']['sales']['realized'], $sim['delta']['units']['realized'], $sim['delta']['units']['projected']], 'réalisé et quantités inchangés');
        $this->assertSame(['purchases', 'trip', 'shipping', 'advertising', 'other'], array_column($sim['plannedToSave']['lines'], 'category'));
        $this->assertSame(123, $sim['plannedToSave']['lines'][0]['planned']);
        $this->assertArrayNotHasKey('reached', $sim['delta']['breakEven'] ?? [], 'delta : nombres seulement');
        $this->assertContains('SAME_QUANTITIES', array_column($sim['caveats'], 'code'));
        $this->assertSame(['all', 30, []], [$sim['levers']['promotion']['scope'], $sim['levers']['promotion']['percent'], $sim['levers']['promotion']['productIds']]);
    }

    public function testScenarioArithmetic(): void
    {
        $service = static::getContainer()->get(GestionSimulationService::class);
        $today = static::getContainer()->get(PeriodCalendar::class)->today();
        $b = $today->modify('+4000 days');
        $d = fn (int $n) => $b->modify("$n days")->format('Y-m-d');
        $pid = $this->period(4000, 'sim-main');
        $period = $this->em()->find(Collections::class, $pid);
        $conn = $this->em()->getConnection();
        $t = $b->modify('+9 days');
        $stock0 = $service->simulate($period, ['pricePercent' => 10], 'fr', $t); // stock du reste de la base partagée, avant A et B
        // Article A : coût 10,00 (ligne d'achat B−10), stock 10, prix affiché 25,00 ; article B : coût inconnu, stock 1
        [$aProduct, $a] = $this->variant('Simulation A', 10);
        [$bProduct, $bv] = $this->variant('Simulation B', 1);
        $cid = (int) $conn->fetchOne("INSERT INTO commande (id, date, name, kind, budget) VALUES (nextval('commande_id_seq'), ?, 'Simulation', 'standard', 0) RETURNING id", [$d(-10) . ' 09:00:00']);
        $conn->executeStatement('INSERT INTO commande_line (commande_id, product_id, quantity_ordered, unit_cost_cents, attached_at, created_at, updated_at) VALUES (?, ?, 10, 1000, NOW(), NOW(), NOW())', [$cid, $aProduct]);
        // Ventes : A 3 × 30,00 à B−5 (avant la période), A 6 × 30,00 à B+2, B 3 × 10,00 à B+3 ; note de frais 15,00 à B+1
        $this->sale($a, 3, 3000, $d(-5));
        $this->sale($a, 6, 3000, $d(2));
        $this->sale($bv, 3, 1000, $d(3));
        $type = (int) $conn->fetchOne('SELECT id FROM type_note_de_frais ORDER BY id LIMIT 1');
        $conn->executeStatement("INSERT INTO note_de_frais (id, name, montant, date, type_note_de_frais_id) VALUES (nextval('note_de_frais_id_seq'), 'Simulation', 1500, ?, ?)", [$d(1), $type ?: null]);
        foreach (['purchases' => 50000, 'advertising' => 5000, 'other' => 2000] as $category => $planned) {
            $conn->executeStatement('INSERT INTO gestion_budget_line (period_id, category, planned_cents, updated_at) VALUES (?, ?, ?, NOW())', [$pid, $category, $planned]);
        }
        $this->endOfWrite();
        $body = ['pricePercent' => 10, 'promotion' => ['percent' => 50, 'scope' => 'products', 'productIds' => [$bProduct]], 'advertisingDelta' => 3000, 'planned' => ['other' => 4000]];

        $s = $service->simulate($period, $body, 'fr', $t);
        $this->assertSame([30, 10, 20], [$s['period']['daysTotal'], $s['period']['daysElapsed'], $s['period']['daysRemaining']]);
        $this->assertSame('pace', $s['method']);
        $base = $s['base'];
        // Rythme : A 9 en 30 j → 6 sur 20 j (stock 10) ; B 3 → 2, plafonné au stock 1
        $this->assertSame([['realized' => 21000, 'projected' => 19000, 'total' => 40000], ['realized' => 9, 'projected' => 7]], [$base['sales'], $base['units']]);
        $this->assertSame([12000, 4000, 24000, 66.67, 17000], [$base['cogs'], $base['unknownCostRevenue'], $base['grossMargin'], $base['tauxMarque'], $base['result']]);
        $this->assertSame([['trip', 0], ['shipping', 0], ['advertising', 5000], ['other', 2000]], array_map(fn ($c) => [$c['category'], $c['amount']], $base['expenses']['byCategory']), 'plus grand du prévu et du réel');
        $this->assertSame([7000, ['revenue' => 10500, 'reached' => true]], [$base['expenses']['total'], $base['breakEven']]);
        $this->assertSame([['units' => 7, 'revenue' => 19000], null], [$base['projectedSales'], $base['cashAfterPurchases']]);
        // Stock de fin : A 10 − 6 = 4 (coût 10,00, affiché 25,00), B 1 − 1 = 0 ; le reste de la base ne bouge pas
        $s0 = $stock0['base']['stockEnd'];
        $this->assertSame(['units' => $s0['units'] + 4, 'costValue' => $s0['costValue'] + 4000, 'saleValue' => $s0['saleValue'] + 10000], $base['stockEnd']);
        $sim = $s['simulated'];
        $this->assertSame([21000, 20350, 41350], array_values($sim['sales']), 'prix + 10 %, B à − 50 % : ventes restantes seulement');
        $this->assertSame([3550, 25800, 68.25, 12000, 13800, ['revenue' => 17582, 'reached' => true]], [$sim['unknownCostRevenue'], $sim['grossMargin'], $sim['tauxMarque'], $sim['expenses']['total'], $sim['result'], $sim['breakEven']]);
        $this->assertEqualsWithDelta($stock0['simulated']['stockEnd']['saleValue'] + 11000, $sim['stockEnd']['saleValue'], 1, 'stock restant de A au prix + 10 %'); 
        $this->assertSame([0, 1350, 1350, -3200, 1.58, 7082, 5000], [$s['delta']['sales']['realized'], $s['delta']['sales']['projected'], $s['delta']['sales']['total'], $s['delta']['result'], $s['delta']['tauxMarque'], $s['delta']['breakEven']['revenue'], $s['delta']['expenses']['total']]);
        $this->assertSame(['category' => 'advertising', 'amount' => 3000], $s['delta']['expenses']['byCategory'][2]);
        $this->assertSame(['revenue' => 7082], $s['delta']['breakEven'], 'delta : reached omis');
        $this->assertSame([50000, 0, 0, 5000, 4000], array_column($s['plannedToSave']['lines'], 'planned'), 'publicité ajoutée : levier, pas un prévu');
        $this->assertSame(['SAME_QUANTITIES', 'UNKNOWN_COST', 'NO_CASH_BALANCE', 'PLANNED_PURCHASES_NOT_IN_STOCK'], array_column($s['caveats'], 'code'));
        $this->assertNull($s['lastYear']);

        // Promotion datée sur A (lot P5) : 15,00 $ au lieu de 25,00 $ du B+20 au B+29, soit 10 des 20 jours restants → ventes restantes de A
        // valorisées à 30,00 × (1 − 0,5 × (1 − 0,6)) = 24,00 ; B inchangé
        $utc = fn (string $day, string $time) => (new \DateTimeImmutable("$day $time", static::getContainer()->get(\App\Services\CaisseService\StoreClock::class)->timezone()))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $conn->executeStatement('UPDATE product SET special_price = 1500, special_price_from = ?, special_price_to = ? WHERE id = ?', [$utc($d(20), '00:00:00'), $utc($d(29), '23:59:59'), $aProduct]);
        $sp = $service->simulate($period, [], 'fr', $t);
        $this->assertSame([6 * 2400 + 1000, ['PROMOTIONS_INCLUDED', [$aProduct]]], [$sp['base']['sales']['projected'], [$sp['caveats'][array_search('PROMOTIONS_INCLUDED', array_column($sp['caveats'], 'code'), true)]['code'] ?? null, $sp['caveats'][array_search('PROMOTIONS_INCLUDED', array_column($sp['caveats'], 'code'), true)]['productIds'] ?? null]]);
        $conn->executeStatement('UPDATE product SET special_price = NULL, special_price_from = NULL, special_price_to = NULL WHERE id = ?', [$aProduct]);

        // Trésorerie saisie : 1 000,00 à B+7 → après achats = solde + ventes à venir − reste à acheter − reste des frais prévus
        $conn->executeStatement('INSERT INTO gestion_cash_balance (balance_date, amount_cents, created_at, updated_at) VALUES (?, 100000, NOW(), NOW())', [$d(7)]);
        $s2 = $service->simulate($period, $body, 'fr', $t);
        $this->assertSame([63500, 59850], [$s2['base']['cashAfterPurchases'], $s2['simulated']['cashAfterPurchases']]);
        $this->assertSame(['SAME_QUANTITIES', 'UNKNOWN_COST', 'TAXES_EXCLUDED', 'PLANNED_PURCHASES_NOT_IN_STOCK'], array_column($s2['caveats'], 'code'));
    }

    public function testLastYearFallbackAndNoSales(): void
    {
        $service = static::getContainer()->get(GestionSimulationService::class);
        $today = static::getContainer()->get(PeriodCalendar::class)->today();
        $b = $today->modify('+5000 days');
        $pid = $this->period(5000, 'sim-ly');
        [, $a] = $this->variant('Simulation LY', 10);
        $this->sale($a, 4, 2000, $b->modify('+15 days')->modify('-1 year')->format('Y-m-d'));
        $this->endOfWrite();
        $s = $service->simulate($this->em()->find(Collections::class, $pid), [], 'en', $b->modify('+9 days'));
        $this->assertSame(['lastYear', 8000, 4], [$s['method'], $s['base']['sales']['projected'], $s['base']['units']['projected']]);
        $this->assertSame(['revenue' => 8000, 'grossMargin' => 0, 'units' => 4], $s['lastYear'], 'même période l\'an dernier ; coût inconnu : marge 0');
        $this->assertContains('LAST_YEAR_USED', array_column($s['caveats'], 'code'));
        $this->assertStringStartsWith('No sales in the last 30 days', $s['caveats'][array_search('LAST_YEAR_USED', array_column($s['caveats'], 'code'), true)]['text']);
        $this->assertSame([], array_values(array_diff_key($s['delta']['sales'], ['realized' => 1, 'projected' => 1, 'total' => 1])), 'aucun levier : delta nul');
        $this->assertSame([0, 0, 0], array_values($s['delta']['sales']));

        $none = $service->simulate($this->em()->find(Collections::class, $this->period(6000, 'sim-none')), [], 'fr', $today->modify('+6009 days'));
        $this->assertSame(['none', 0], [$none['method'], $none['base']['sales']['projected']]);
        $this->assertContains('NO_SALES_PACE', array_column($none['caveats'], 'code'));
    }

    public function testGuideCommentCandidatesCacheLimitAndAi(): void
    {
        \App\Tests\Fake\FakeLandingAiClient::reset();
        $service = static::getContainer()->get(\App\Gestion\Services\GestionSimulationCommentService::class);
        $today = static::getContainer()->get(PeriodCalendar::class)->today();
        $b = $today->modify('+8000 days');
        $pid = $this->period(8000, 'sim-com');
        $lyStart = $b->modify('-1 year');
        $lyId = $this->period((int) $today->diff($lyStart)->format('%r%a'), 'sim-cly');
        $period = $this->em()->find(Collections::class, $pid);
        $conn = $this->em()->getConnection();
        foreach (['purchases' => 50000, 'advertising' => 5000, 'other' => 2000] as $category => $planned) {
            $conn->executeStatement('INSERT INTO gestion_budget_line (period_id, category, planned_cents, updated_at) VALUES (?, ?, ?, NOW())', [$pid, $category, $planned]);
        }
        // L'an dernier : stock 100,00, publicité 10,00, autres frais 30,00
        [$p] = $this->variant('Simulation commentaire', 0);
        $cid = (int) $conn->fetchOne("INSERT INTO commande (id, date, name, kind, budget) VALUES (nextval('commande_id_seq'), ?, 'Simulation', 'standard', 0) RETURNING id", [$lyStart->modify('+2 days')->format('Y-m-d') . ' 09:00:00']);
        $conn->executeStatement('INSERT INTO commande_line (commande_id, product_id, quantity_ordered, unit_cost_cents, attached_at, created_at, updated_at) VALUES (?, ?, 4, 2500, NOW(), NOW(), NOW())', [$cid, $p]);
        $conn->executeStatement("INSERT INTO note_de_frais (id, name, montant, date) VALUES (nextval('note_de_frais_id_seq'), 'Simulation', 3000, ?)", [$lyStart->modify('+3 days')->format('Y-m-d')]);
        $conn->executeStatement("INSERT INTO marketing_campaign (id, name, channel, start_date, end_date, amount_cents, created_at, updated_at) VALUES (nextval('marketing_campaign_id_seq'), 'Simulation LY', 'social', ?, ?, 1000, NOW(), NOW())", [$lyStart->modify('+4 days')->format('Y-m-d'), $lyStart->modify('+4 days')->format('Y-m-d')]);
        // Trésorerie : 300,00 au B+5, seuil 0
        $conn->executeStatement('INSERT INTO gestion_cash_balance (balance_date, amount_cents, created_at, updated_at) VALUES (?, 30000, NOW(), NOW())', [$b->modify('+5 days')->format('Y-m-d')]);
        $this->endOfWrite();
        $t = $b->modify('+9 days');

        \App\Tests\Fake\FakeLandingAiClient::$queue[] = $this->aiResponse(['recommended' => 'cashSafe', 'title' => '"Protégez la trésorerie"', 'text' => ' Réduisez les achats de stock de 270,00 $ : la trésorerie reste à 0,00 $, le résultat ne bouge pas.} ']); // restes de JSON (11/11/2026) : nettoyés
        $r = $service->comment($period, [], 'fr', 'gerant@example.invalid', $t);
        $c = array_column($r['candidates'], null, 'key');
        $this->assertSame(['keep', 'lastYearShares', 'lastYearActual', 'cashSafe'], array_keys($c), 'sans prévu dans le corps : pas de « simulated »');
        $this->assertSame(['purchases' => 10000, 'trip' => 0, 'shipping' => 0, 'advertising' => 1000, 'other' => 3000], $c['lastYearActual']['planned'], 'C2 : réel de l\'an dernier par poste');
        $this->assertSame(['purchases' => 40715, 'trip' => 0, 'shipping' => 0, 'advertising' => 4071, 'other' => 12214], $c['lastYearShares']['planned'], 'même total (570,00), parts de l\'an dernier');
        $this->assertSame([-7000, -16285, -4000, -7000], [$c['keep']['result'], $c['lastYearShares']['result'], $c['lastYearActual']['result'], $c['cashSafe']['result']], 'résultat = − frais (aucune vente) ; achats seuls : résultat inchangé');
        $this->assertSame([-27000, 0, 27000, 23000, true], [$c['keep']['cashAfterPurchases'], $c['cashSafe']['cashAfterPurchases'], $c['cashSafe']['purchasesCut'], $c['cashSafe']['planned']['purchases'], $c['cashSafe']['reachesThreshold']]);
        $this->assertSame([['result' => 3000, 'cashAfterPurchases' => 43000], ['result' => 0, 'cashAfterPurchases' => 27000]], [$c['lastYearActual']['delta'], $c['cashSafe']['delta']]);
        $this->assertSame([['recommended' => 'cashSafe', 'title' => 'Protégez la trésorerie', 'text' => 'Réduisez les achats de stock de 270,00 $ : la trésorerie reste à 0,00 $, le résultat ne bouge pas.', 'textSource' => 'ai'], 'ok', false], [$r['comment'], $r['ai']['status'], $r['cached']]);
        $this->assertStringNotContainsString('gerant@example.invalid', json_encode(\App\Tests\Fake\FakeLandingAiClient::$requests[0], JSON_UNESCAPED_UNICODE));

        // Même corps, même jour : même avis, sans nouvel appel
        $again = $service->comment($period, [], 'fr', null, $t);
        $this->assertSame([true, 'ai', 1], [$again['cached'], $again['comment']['textSource'], count(\App\Tests\Fake\FakeLandingAiClient::$requests)]);
        // Avis mis en cache avant le nettoyage (11/11/2026) : nettoyé à la lecture
        $db = $this->em()->getConnection();
        $db->executeStatement("UPDATE gestion_simulation_comment SET response = jsonb_set(response::jsonb, '{comment,text}', to_jsonb((response->'comment'->>'text') || '}'))::json WHERE period_id = ?", [$period->getId()]);
        $this->assertSame('Réduisez les achats de stock de 270,00 $ : la trésorerie reste à 0,00 $, le résultat ne bouge pas.', $service->comment($period, [], 'fr', null, $t)['comment']['text']);
        // Chiffre inventé : avis des règles (cashSafe : la trésorerie l'impose)
        \App\Tests\Fake\FakeLandingAiClient::$queue[] = $this->aiResponse(['recommended' => 'keep', 'title' => 'Gardez', 'text' => 'Vous gagnerez 999,00 $.']);
        $rejected = $service->comment($period, ['pricePercent' => 10], 'fr', null, $t);
        $this->assertSame(['rules', 'cashSafe', true], [$rejected['comment']['textSource'], $rejected['comment']['recommended'], $rejected['comment']['rejected'] ?? false]);
        $this->assertStringContainsString('270,00', $rejected['comment']['text']);
        // Sans droit IA : avis des règles, rien d'envoyé
        $master = static::getContainer()->get(\App\Services\TenantConnectionManager::class)->getPdoMaster();
        $master->prepare("DELETE FROM tenant_entitlements WHERE tenant_code = ? AND feature_key IN ('ai.full', 'ai.help')")->execute([MV_TEST_TENANT_CODE]);
        try {
            $calls = count(\App\Tests\Fake\FakeLandingAiClient::$requests);
            $none = $service->comment($period, ['pricePercent' => 20], 'fr', null, $t);
            $this->assertSame(['not_included', 'rules', $calls], [$none['ai']['status'], $none['comment']['textSource'], count(\App\Tests\Fake\FakeLandingAiClient::$requests)]);
        } finally {
            \App\Tests\Functional\Support\AiRightTestHelper::restoreFullAi(static::getContainer());
        }
        // Plafond : 10 avis par jour, puis 429 COMMENT_LIMIT avec l'avis des règles
        for ($i = 0; $i < 7; ++$i) {
            $conn->executeStatement("INSERT INTO gestion_simulation_comment (period_id, body_hash, store_day, response, ai_status, created_at) VALUES (?, ?, ?, '{}', 'ok', NOW())", [$pid, "x$i", $t->format('Y-m-d')]);
        }
        try {
            $service->comment($period, ['pricePercent' => 30], 'fr', null, $t);
            $this->fail('429 attendu');
        } catch (\App\Gestion\Services\GestionConflictException $e) {
            $this->assertSame([429, 'COMMENT_LIMIT', 'cashSafe', 'rules'], [$e->status, $e->errorCode, $e->details['comment']['recommended'], $e->details['comment']['textSource']]);
        }
    }

    private function aiResponse(array $out): object
    {
        return json_decode(json_encode([
            'id' => 'msg_' . bin2hex(random_bytes(4)), 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-sonnet-5-5', 'stop_reason' => 'end_turn',
            'content' => [['type' => 'text', 'text' => json_encode($out, JSON_UNESCAPED_UNICODE)]],
            'usage' => ['input_tokens' => 2000, 'output_tokens' => 300, 'cache_read_input_tokens' => 0, 'cache_creation_input_tokens' => 0],
        ]), false);
    }

    // ----- helpers -----

    /** Période de 30 jours insérée à aujourd'hui + `$offset` jours */
    private function period(int $offset, string $tag): int
    {
        $today = static::getContainer()->get(PeriodCalendar::class)->today();
        $start = $today->modify("$offset days");
        $id = (int) $this->em()->getConnection()->fetchOne(
            "INSERT INTO collections (id, budget_collection, start_date_collection, end_date_collection, del, nom_collection, is_closed, kind, period_key) VALUES (nextval('collections_id_seq'), 0, ?, ?, false, ?, false, 'period', ?) RETURNING id",
            [$start->format('Y-m-d 00:00:00'), $start->modify('+29 days')->format('Y-m-d 00:00:00'), "Simulation $tag", $tag . '-' . bin2hex(random_bytes(2))]
        );
        $this->periods[] = $id;
        $this->em()->clear();

        return $id;
    }

    /** @return array{0: int, 1: int} article standard en vente (prix affiché 25,00, sans prix d'achat) et sa variante au stock donné */
    private function variant(string $name, int $stock): array
    {
        $conn = $this->em()->getConnection();
        $slug = 'sim-' . bin2hex(random_bytes(4));
        $conn->executeStatement("INSERT INTO product (id, name, price, isbestseller, quantity, created_at, slug, product_type, sale_enabled, is_web) VALUES (nextval('product_id_seq'), :n, 2500, false, :q, NOW(), :s, 'standard', true, true)", ['n' => $name, 'q' => $stock, 's' => $slug]);
        $id = (int) $conn->fetchOne('SELECT id FROM product WHERE slug = ?', [$slug]);
        $variant = (int) $this->create(ProductVariant::class, ['product' => $this->em()->find(Product::class, $id)])->getId();
        $conn->executeStatement('UPDATE product_variant SET stock_quantity = ?, price = NULL WHERE id = ?', [$stock, $variant]);

        return [$id, $variant];
    }

    private function sale(int $variant, int $quantity, int $unit, string $day): void
    {
        $tz = static::getContainer()->get(StoreClock::class)->timezone();
        $at = (new \DateTimeImmutable("$day 12:00", $tz))->setTimezone(new \DateTimeZone('UTC'));
        $order = $this->create(Order::class, ['totalAmount' => (float) ($unit * $quantity)], function (Order $o, EntityManagerInterface $em) use ($at): void {
            $o->setOrderDate(\DateTime::createFromImmutable($at))->setStatus($em->find(StatusCommande::class, 3))->setOrderType($em->find(OrderType::class, 1))
                ->setOrderSource($em->find(OrderSource::class, 2))->setUserId($em->find(User::class, $this->userId));
        });
        $this->create(OrderItems::class, ['quantity' => $quantity, 'unitPrice' => (float) $unit, 'totalPrice' => (float) ($unit * $quantity)], fn (OrderItems $i, EntityManagerInterface $em) => $i->setOrderAssociated($em->find(Order::class, $order->getId()))->setProductVariant($em->find(ProductVariant::class, $variant)));
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
        $email = 'gestion-sim-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $em = $this->em();
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Gestion')->setLastname('Simulation')->setRoles(['ROLE_ADMIN'])->setIsVerified(true)->setPassword('inutilisable');
        $em->persist($user);
        $em->flush();
        $this->userId = (int) $user->getId();
        $this->token = static::getContainer()->get('lexik_jwt_authentication.jwt_manager')->create($user);
        $this->endOfWrite();
    }
}
