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
use App\Message\GenerateGestionAdviceMessage;
use App\MessageHandler\GenerateGestionAdviceHandler;
use App\Services\CaisseService\StoreClock;
use App\Services\GestionService\AdviceMeasure;
use App\Services\GestionService\AdviceRules;
use App\Services\GestionService\GestionAdviceService;
use App\Services\GestionService\PeriodCalendar;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use App\Tests\Fake\FakeLandingAiClient;
use App\Tests\Functional\Cache\CacheTestEntityFactory;
use App\Tests\Functional\Support\AiRightTestHelper;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Lot G1a, guide IA de la gestion (11/11/2026, `docs/guide-ia-gestion.md`, contrat validé par l'application) : règles chiffrées (articles qui
 * partent vite au seuil et juste dessous, stock qui dort, poste dépassé, trésorerie tendue, achats à décaler avec ou sans date, retard sur l'an
 * dernier, plafond), routes (liste, fait, écarté, statut gardé d'une génération à l'autre, relance limitée et unique), rédaction par l'IA (textes
 * acceptés, chiffre inventé rejeté → texte de repli, sans droit IA → rules_only, aucune donnée personnelle envoyée), vignette « advice »
 * calculée sans IA, vérification des nombres. Données datées loin dans le futur (base partagée), supprimées à la fin.
 */
class AdviceApiTest extends WebTestCase
{
    use CacheTestEntityFactory;

    private KernelBrowser $client;
    private string $token = '';
    private int $userId = 0;
    private string $email = '';
    /** @var list<int> */
    private array $periods = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        FakeLandingAiClient::reset();
        $conn = $this->em()->getConnection();
        foreach (['gestion_advice', 'gestion_advice_run', 'gestion_cash_balance', 'gestion_cash_movement', 'gestion_cash_setting', 'gestion_tile_dismissal'] as $t) {
            $conn->executeStatement("DELETE FROM $t");
        }
        AiRightTestHelper::restoreFullAi(static::getContainer());
        $this->login();
    }

    protected function tearDown(): void
    {
        $conn = $this->em()->getConnection();
        foreach ($this->periods as $id) {
            $conn->executeStatement('DELETE FROM collections WHERE id = ?', [$id]);
        }
        $conn->executeStatement('DELETE FROM order_items WHERE order_associated_id IN (SELECT id FROM "order" WHERE order_date > NOW() + INTERVAL \'6500 days\')');
        $conn->executeStatement('DELETE FROM "order" WHERE order_date > NOW() + INTERVAL \'6500 days\'');
        $conn->executeStatement("DELETE FROM note_de_frais WHERE name = 'Conseil'");
        $conn->executeStatement("DELETE FROM commande_line WHERE commande_id IN (SELECT id FROM commande WHERE name = 'Conseil')");
        $conn->executeStatement("DELETE FROM commande WHERE name = 'Conseil'");
        foreach (['gestion_advice', 'gestion_advice_run', 'gestion_cash_balance', 'gestion_cash_movement'] as $t) {
            $conn->executeStatement("DELETE FROM $t");
        }
        AiRightTestHelper::restoreFullAi(static::getContainer());
        parent::tearDown();
    }

    public function testRules(): void
    {
        $rules = static::getContainer()->get(AdviceRules::class);
        $today = static::getContainer()->get(PeriodCalendar::class)->today();
        $b = $today->modify('+7000 days');
        $d = fn (int $n) => $b->modify("$n days")->format('Y-m-d');
        $pid = $this->period(7000, 'adv-rules');
        $period = $this->em()->find(Collections::class, $pid);
        $t = $b->modify('+9 days');
        $conn = $this->em()->getConnection();
        $this->assertSame(['selling'], $rules->moments($period, $t)['moments']);
        $this->assertSame(['end'], $rules->moments($period, $b->modify('+20 days'))['moments'], 'période de 30 jours : dernier tiers (10 jours)');
        $this->assertSame(['selling'], $rules->moments($period, $b->modify('+19 days'))['moments']);
        $this->assertSame(['planning'], $rules->moments($period, $b->modify('-1 day'))['moments']);
        $this->assertSame(['closing'], $rules->moments($period, $b->modify('+89 days'))['moments'], 'fin + 60 jours');
        $this->assertSame([], $rules->moments($period, $b->modify('+90 days'))['moments']);

        // R1 : F vendu 6 fois en 30 j, stock 3 (moins de 21 jours) → conseil ; F2 vendu 4 fois (sous le seuil de 5) → rien
        [$f, $fv] = $this->variant('Conseil rapide', 3, 1000, $d(-60));
        [$f2, $f2v] = $this->variant('Conseil presque', 1, 1000, $d(-60));
        $this->sale($fv, 6, 2000, $d(-5));
        $this->sale($f2v, 4, 2000, $d(-5));
        // R2 : S en stock, valeur au coût énorme, jamais vendu, acheté il y a 100 jours → conseil ; S2 sous 100 $ → rien
        [$s] = $this->variant('Conseil dormant', 1000, 100000, $d(-100));
        [$s2] = $this->variant('Conseil petit', 5, 1000, $d(-100));
        // R4 : poste « autres frais » dépassé (prévu 10,00, réel 15,00) ; R8 : 500,00 de stock prévu, rien d'acheté
        $conn->executeStatement("INSERT INTO note_de_frais (id, name, montant, date) VALUES (nextval('note_de_frais_id_seq'), 'Conseil', 1500, ?)", [$d(1)]);
        foreach (['other' => 1000, 'purchases' => 50000] as $category => $planned) {
            $conn->executeStatement('INSERT INTO gestion_budget_line (period_id, category, planned_cents, updated_at) VALUES (?, ?, ?, NOW())', [$pid, $category, $planned]);
        }
        // R7 / R8 : solde 100,00 à B+5, sortie de 200,00 à B+12 → point bas −100,00 sous le seuil 0
        $conn->executeStatement('INSERT INTO gestion_cash_balance (balance_date, amount_cents, created_at, updated_at) VALUES (?, 10000, NOW(), NOW())', [$d(5)]);
        $conn->executeStatement("INSERT INTO gestion_cash_movement (label, category, direction, amount_cents, start_date, recurrence, created_at, updated_at) VALUES ('Loyer', 'rent', 'out', 20000, ?, 'none', NOW(), NOW())", [$d(12)]);
        // R10 : 1 000,00 de ventes l'an dernier sur la même période
        [, $lyv] = $this->variant('Conseil an dernier', 0, 1000, $d(-500));
        $this->sale($lyv, 10, 10000, $b->modify('+3 days')->modify('-1 year')->format('Y-m-d'));
        $this->endOfWrite();

        $r = $rules->detect($period, $t);
        $by = [];
        foreach ($r['advices'] as $a) {
            $by[$a['signature']] = $a;
        }
        $this->assertArrayHasKey("FAST_SELLER:p$f", $by);
        $this->assertArrayNotHasKey("FAST_SELLER:p$f2", $by, '4 ventes : sous le seuil de 5');
        $fast = $by["FAST_SELLER:p$f"];
        $this->assertSame([300, 'extra_margin', 'low', 'articles', ['productIds' => [$f]]], [$fast['effect']['amount'], $fast['effect']['label'], $fast['confidence'], $fast['action']['screen'], $fast['action']['params']], '3 restantes (stock) × 20,00 × 5 %');
        $this->assertSame(['productId' => $f, 'variantId' => null, 'name' => 'Conseil rapide', 'price' => 2500, 'cost' => 1000, 'stock' => 3, 'soldLast30Days' => 6], $fast['figures'][0]);
        $this->assertSame([6, 3, 15], [$fast['sources'][0]['value'], $fast['sources'][1]['value'], $fast['sources'][2]['value']], 'ventes, stock, jours de stock');
        $this->assertArrayHasKey("SLOW_STOCK:p$s", $by);
        $this->assertArrayNotHasKey("SLOW_STOCK:p$s2", $by, '50,00 au coût : sous 100 $');
        $slow = $by["SLOW_STOCK:p$s"];
        $this->assertSame([100000000, 'simulateur', 20, [$s]], [$slow['effect']['amount'], $slow['action']['screen'], $slow['action']['params']['promotion']['percent'], $slow['action']['params']['promotion']['productIds']]);
        $this->assertSame(109, $slow['sources'][2]['value'], 'jours sans vente depuis l\'achat');
        $this->assertSame([-500, 'high', 'over_budget'], [$by['BUDGET_PACE:other']['effect']['amount'], $by['BUDGET_PACE:other']['confidence'], $by['BUDGET_PACE:other']['effect']['label']]);
        $this->assertSame([-10000, 'high', 'tresorerie'], [$by['CASH_TIGHT']['effect']['amount'], $by['CASH_TIGHT']['confidence'], $by['CASH_TIGHT']['action']['screen']]);
        $this->assertSame([-50000, null, 'horizon_days'], [$by['PURCHASE_CAPACITY']['effect']['amount'], $by['PURCHASE_CAPACITY']['params']['date'], $by['PURCHASE_CAPACITY']['sources'][2]['key']], 'jamais finançable dans les 90 jours');
        $this->assertArrayHasKey('PACE_BEHIND', $by);
        $pace = $by['PACE_BEHIND'];
        $this->assertSame($pace['sources'][0]['value'] - 100000, $pace['effect']['amount']);
        $this->assertTrue($pace['sources'][2]['value'] < 85);
        $this->assertLessThanOrEqual(AdviceRules::MAX_ADVICES + 2, count($r['advices']));
        $this->assertTrue($by['CASH_TIGHT']['pinned'] && $by['PURCHASE_CAPACITY']['pinned']);

        // R8 avec date : une entrée de 1 000,00 à B+20 rend l'achat possible ce jour-là
        $conn->executeStatement("INSERT INTO gestion_cash_movement (label, category, direction, amount_cents, start_date, recurrence, created_at, updated_at) VALUES ('Prêt', 'loan', 'in', 100000, ?, 'none', NOW(), NOW())", [$d(20)]);
        $capacity = array_values(array_filter($rules->detect($period, $t)['advices'], fn ($a) => $a['kind'] === 'PURCHASE_CAPACITY'))[0];
        $this->assertSame([$d(20), 'purchase_from'], [$capacity['params']['date'], $capacity['sources'][2]['key']]);
        [$title, $text] = static::getContainer()->get(GestionAdviceService::class)->fallback('PURCHASE_CAPACITY', $capacity['sources'], $capacity['params'], $capacity['effect']['amount'], 'fr');
        $this->assertSame('Achats de stock à décaler', $title);
        $this->assertStringContainsString('500,00', $text);
    }

    public function testRoutesStatusesRefreshAndAiWriting(): void
    {
        $today = static::getContainer()->get(PeriodCalendar::class)->today();
        $pid = $this->period(7100, 'adv-api');
        $b = $today->modify('+7100 days');
        $conn = $this->em()->getConnection();
        // Période à venir (planning) : seul le poste dépassé est détecté
        $conn->executeStatement("INSERT INTO note_de_frais (id, name, montant, date) VALUES (nextval('note_de_frais_id_seq'), 'Conseil', 1500, ?)", [$b->modify('+1 day')->format('Y-m-d')]);
        $conn->executeStatement('INSERT INTO gestion_budget_line (period_id, category, planned_cents, updated_at) VALUES (?, ?, ?, NOW())', [$pid, 'other', 1000]);
        $this->endOfWrite();
        $runs = fn () => $conn->fetchFirstColumn('SELECT status FROM gestion_advice_run WHERE period_id = ? ORDER BY id', [$pid]);

        $l = $this->get("/api/gestion/conseils?periodId=$pid&locale=fr");
        $this->assertSame([['planning'], 'pending', true, 3, 'CAD'], [$l['moments'], $l['ai']['status'], $l['generating'], $l['manualRefreshesLeft'], $l['currency']]);
        $this->assertCount(1, $l['advices']);
        $a = $l['advices'][0];
        $this->assertSame(['BUDGET_PACE', 'planning', 'rules', -500, 'Dépassement', 'high', 'new', 'periode.budget', ['periodId' => $pid]], [$a['kind'], $a['moment'], $a['textSource'], $a['effect']['amount'], $a['effect']['label'], $a['confidence'], $a['status'], $a['action']['screen'], $a['action']['params']]);
        $this->assertSame('Poste Autres frais : attention', $a['title']);
        $this->assertSame(['Prévu', 1000, 'money'], [$a['sources'][0]['label'], $a['sources'][0]['value'], $a['sources'][0]['unit']]);
        $this->assertSame(['pending'], $runs(), 'premier GET du jour : une génération, rédaction demandée');
        $this->get("/api/gestion/conseils?periodId=$pid");
        $this->assertSame(['pending'], $runs(), 'pas deux fois le même jour');

        // Rédaction : texte accepté (chiffres des sources), phrase de synthèse ; aucune donnée personnelle envoyée
        $run = (int) $conn->fetchOne('SELECT MAX(id) FROM gestion_advice_run');
        FakeLandingAiClient::$queue[] = $this->aiResponse(['headline' => 'Période à venir : un poste dépasse déjà.', 'advices' => [['id' => $a['id'], 'title' => 'Autres frais déjà dépassés', 'text' => 'Prévu 10 $, déjà 15,00 $ dépensés : 5 $ de trop.']], 'order' => [$a['id']]]);
        $this->runWorker($run);
        $request = json_encode(FakeLandingAiClient::$requests[0], JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString($this->email, $request, 'aucun utilisateur');
        $this->assertSame(['claude-sonnet-5-5', 'json_schema'], [FakeLandingAiClient::$requests[0]['model'], FakeLandingAiClient::$requests[0]['output_config']['format']['type']]);
        $l2 = $this->get("/api/gestion/conseils?periodId=$pid");
        $this->assertSame(['ok', false, 'Période à venir : un poste dépasse déjà.'], [$l2['ai']['status'], $l2['generating'], $l2['headline']]);
        $this->assertSame(['ai', 'Autres frais déjà dépassés'], [$l2['advices'][0]['textSource'], $l2['advices'][0]['title']]);
        $this->assertSame('rules', $this->get("/api/gestion/conseils?periodId=$pid&locale=en")['advices'][0]['textSource'], 'autre langue : texte de repli');

        // Relance : chiffre inventé (somme) → rejeté, texte de repli ; limite de 3 par jour ; une seule à la fois
        $r1 = $this->send('POST', '/api/gestion/conseils/actualiser', ['periodId' => $pid], 202);
        $this->assertSame(2, $r1['manualRefreshesLeft']);
        $this->request('POST', '/api/gestion/conseils/actualiser', ['periodId' => $pid]);
        $this->assertSame([409, 'ADVICE_GENERATING'], [$this->status(), $this->body()['error']]);
        FakeLandingAiClient::$queue[] = $this->aiResponse(['headline' => 'Total des frais : 25 $.', 'advices' => [['id' => $a['id'], 'title' => 'Frais', 'text' => 'Prévu 10 $ et dépensé 15 $, soit 25 $ en tout.']], 'order' => []]);
        $this->runWorker($r1['runId']);
        $this->assertSame(2, (int) $conn->fetchOne('SELECT rejected FROM gestion_advice_run WHERE id = ?', [$r1['runId']]), 'texte et synthèse rejetés');
        $l3 = $this->get("/api/gestion/conseils?periodId=$pid");
        $this->assertSame(['ai', 'Autres frais déjà dépassés', 'Période à venir : un poste dépasse déjà.'], [$l3['advices'][0]['textSource'], $l3['advices'][0]['title'], $l3['headline']], 'texte rejeté : le dernier texte valide reste (mêmes chiffres)');
        foreach ([1, 0] as $left) {
            $r = $this->send('POST', '/api/gestion/conseils/actualiser', ['periodId' => $pid], 202);
            $this->assertSame($left, $r['manualRefreshesLeft']);
            $conn->executeStatement("UPDATE gestion_advice_run SET status = 'error' WHERE id = ?", [$r['runId']]);
        }
        $this->request('POST', '/api/gestion/conseils/actualiser', ['periodId' => $pid]);
        $this->assertSame([429, 'ADVICE_REFRESH_LIMIT'], [$this->status(), $this->body()['error']]);

        // Sans droit IA : rules_only, rien n'est envoyé
        $conn->executeStatement("UPDATE gestion_advice_run SET status = 'pending' WHERE id = (SELECT MAX(id) FROM gestion_advice_run)");
        $master = static::getContainer()->get(TenantConnectionManager::class)->getPdoMaster();
        $master->prepare("DELETE FROM tenant_entitlements WHERE tenant_code = ? AND feature_key IN ('ai.full', 'ai.help')")->execute([MV_TEST_TENANT_CODE]);
        $calls = count(FakeLandingAiClient::$requests);
        $this->runWorker((int) $conn->fetchOne('SELECT MAX(id) FROM gestion_advice_run'));
        $this->assertSame(['not_included', $calls], [$conn->fetchOne('SELECT status FROM gestion_advice_run WHERE id = (SELECT MAX(id) FROM gestion_advice_run)'), count(FakeLandingAiClient::$requests)]);
        AiRightTestHelper::restoreFullAi(static::getContainer());

        // Fait / écarté : statut du site, auteur gardé ; un conseil traité ne revient pas à la génération suivante
        $done = $this->send('POST', '/api/gestion/conseils/' . $a['id'] . '/fait', ['note' => 'Budget relevé'], 200);
        $this->assertSame('done', $done['status']);
        $this->assertNotNull($done['doneAt']);
        $this->assertSame([$this->email, 'Budget relevé', 'BUDGET_PACE:other'], array_values($conn->fetchAssociative('SELECT status_by, status_note, signature FROM gestion_advice WHERE id = ?', [$a['id']])));
        $this->request('POST', '/api/gestion/conseils/' . $a['id'] . '/ecarter', []);
        $this->assertSame([409, 'ADVICE_CLOSED'], [$this->status(), $this->body()['error']]);
        $this->request('POST', '/api/gestion/conseils/999999999/fait', []);
        $this->assertSame([404, 'ADVICE_NOT_FOUND'], [$this->status(), $this->body()['error']]);
        $conn->executeStatement('DELETE FROM gestion_advice_run');
        $this->assertSame([], $this->get("/api/gestion/conseils?periodId=$pid")['advices'], 'nouvelle génération : le conseil fait reste fait');
        $this->assertSame(['done'], array_column($this->get("/api/gestion/conseils?periodId=$pid&status=all")['advices'], 'status'));
        $this->request('GET', '/api/gestion/conseils?status=pigeon');
        $this->assertSame([422, ['status' => 'INVALID_STATUS']], [$this->status(), $this->body()['fields']]);
    }

    public function testTileWithoutAiAndNumberCheck(): void
    {
        $tiles = $this->get('/api/gestion/accueil/vignettes');
        $current = static::getContainer()->get(PeriodCalendar::class)->findByDate(static::getContainer()->get(PeriodCalendar::class)->today());
        if ($current !== null) {
            $this->assertSame('rules', $this->em()->getConnection()->fetchOne('SELECT status FROM gestion_advice_run WHERE period_id = ? ORDER BY id DESC LIMIT 1', [$current->getId()]), 'vignettes : règles calculées, IA jamais demandée');
        }
        $advice = static::getContainer()->get(GestionAdviceService::class)->tileAdvice('fr');
        $tile = array_values(array_filter($tiles, fn ($t) => $t['id'] === 'advice'))[0] ?? null;
        if ($advice !== null && $tile === null) {
            $this->assertCount(3, $tiles, 'vignette « advice » absente seulement si 3 vignettes plus urgentes passent avant');
        }
        if ($tile !== null) {
            $this->assertSame(['advice', 'conseils', $advice['id']], [$tile['kind'], $tile['action']['screen'], $tile['action']['params']['adviceId']]);
            $this->assertContains($tile['priority'], [60, 64, 68]);
            $this->assertNotContains($advice['kind'], GestionAdviceService::NO_TILE_KINDS);
        }

        // Texte rédigé par l'IA (dernier texte valide) : la vignette le reprend aussi ; conseil inséré en tête de la période en cours, alertes plus
        // urgentes de la base partagée écartées
        $conn = $this->em()->getConnection();
        $conn->executeStatement("INSERT INTO gestion_advice (period_id, signature, moment, kind, subject, action, effect_cents, effect_label, confidence, sources, figures, params, ai_title, ai_text, ai_locale, rank, detected_on, created_at, updated_at)"
            . " VALUES (?, 'TEST:tile', 'selling', 'FAST_SELLER', '{}', '{\"screen\": \"articles\", \"params\": {}}', 100, 'extra_margin', 'high', '[]', '[]', '{\"name\": \"X\"}', 'Titre IA', 'Texte IA', 'fr', 0, CURRENT_DATE, NOW(), NOW())", [$current->getId()]);
        foreach (['low-stock', 'budget-over', 'trip-budget', 'cash-low'] as $id) {
            $this->request('POST', "/api/gestion/accueil/vignettes/$id/ecarter", []);
        }
        $aiTile = array_values(array_filter($this->get('/api/gestion/accueil/vignettes?locale=fr'), fn ($t) => $t['id'] === 'advice'))[0] ?? null;
        $this->assertSame(['Titre IA', 'Texte IA', 68], [$aiTile['title'] ?? null, $aiTile['text'] ?? null, $aiTile['priority'] ?? null], 'vignette : texte de l\'IA');
        foreach ($this->get('/api/gestion/accueil/vignettes?locale=fr') as $t) {
            foreach ($t['sources'] as $src) {
                $this->assertContains($src['unit'] ?? null, ['money', 'percent', 'count', 'days', 'date', 'ratio'], $t['id'] . ' : unit sur chaque source');
            }
        }

        // Vérification des nombres : écritures admises, valeurs recalculées refusées
        $allowed = GestionAdviceService::allowedNumbers([['key' => 'a', 'value' => 123456, 'unit' => 'money'], ['key' => 'p', 'value' => 5, 'unit' => 'percent'], ['key' => 'd', 'value' => '2026-11-12', 'unit' => 'date'], ['key' => 'r', 'value' => 2.35, 'unit' => 'ratio']], -50000);
        foreach (['1 234,56 $', '1 235 $', '1 234 $', "1\u{202F}234,56", '5 %', '5,0 %', 'le 12 novembre 2026', '2,35 $ pour 1 $', 'environ 500 $', 'en 30 jours'] as $ok) {
            $this->assertSame([], GestionAdviceService::unknownNumbers($ok, $allowed), $ok);
        }
        foreach (['1 300 $' => [1300.0], 'soit 1 734,56 $ en tout' => [1734.56], '6 %' => [6.0], 'le 13' => [13.0]] as $bad => $unknown) {
            $this->assertSame($unknown, GestionAdviceService::unknownNumbers($bad, $allowed), $bad);
        }
    }

    public function testMeasureAfterApplication(): void
    {
        $measure = static::getContainer()->get(\App\Services\GestionService\AdviceMeasure::class);
        $advices = static::getContainer()->get(GestionAdviceService::class);
        $today = static::getContainer()->get(PeriodCalendar::class)->today();
        $b = $today->modify('+7200 days');
        $d = fn (int $n) => $b->modify("$n days")->format('Y-m-d');
        $pid = $this->period(7200, 'adv-mes');
        $conn = $this->em()->getConnection();
        // A : coût 10,00 ; 2 ventes à 20,00 avant (B+3), 5 ventes à 21,00 après la hausse du B+10 (B+12). S : jamais vendu avant, 3 ventes après.
        [$a, $av] = $this->variant('Mesure hausse', 20, 1000, $d(-100));
        [$s, $sv] = $this->variant('Mesure promo', 20, 1000, $d(-100));
        $this->sale($av, 2, 2000, $d(3));
        $this->sale($av, 5, 2100, $d(12));
        $this->sale($sv, 3, 1000, $d(15));
        $this->endOfWrite();
        $insert = fn (string $kind, int $product, string $status, ?array $applied, ?string $on) => (int) $conn->fetchOne(
            "INSERT INTO gestion_advice (period_id, signature, moment, kind, subject, action, effect_cents, effect_label, confidence, sources, figures, params, rank, status, applied, applied_on, done_at, detected_on, created_at, updated_at)"
            . " VALUES (?, ?, 'selling', ?, ?, '{}', 300, 'extra_margin', 'low', '[]', '[]', ?, 1, ?, ?, ?, NOW(), CURRENT_DATE, NOW(), NOW()) RETURNING id",
            [$pid, "$kind:p$product:$status", $kind, json_encode(['productIds' => [$product]]), json_encode(['name' => $kind === 'SLOW_STOCK' ? 'Mesure promo' : 'Mesure hausse']), $status, $applied === null ? null : json_encode($applied), $on]
        );
        $row = fn (int $id) => $conn->fetchAssociative('SELECT * FROM gestion_advice WHERE id = ?', [$id]);

        // Hausse appliquée le B+10, mesurée au B+30 : 14 jours complets contre les 14 jours d'avant
        $fast = $insert('FAST_SELLER', $a, 'done', ['pricePercent' => 5], $d(10));
        $m = $measure->measure($row($fast), 'fr', $b->modify('+30 days'));
        $this->assertSame([true, 14, true, $d(10), $d(23), $d(-4), $d(9)], [$m['measurable'], $m['window']['days'], $m['window']['complete'], $m['window']['after']['from'], $m['window']['after']['to'], $m['window']['before']['from'], $m['window']['before']['to']]);
        $this->assertSame([['revenue' => 4000, 'grossMargin' => 2000, 'units' => 2], ['revenue' => 10500, 'grossMargin' => 5500, 'units' => 5], ['revenue' => 6500, 'grossMargin' => 3500, 'units' => 3]], [$m['before'], $m['after'], $m['change']]);
        $this->assertSame(['medium', [], 300], [$m['confidence'], $m['caveats'], $m['estimatedEffect']]);
        $this->assertStringStartsWith('Hausse de 5', $m['text']);
        $this->assertStringContainsString('+35,00', $m['text']);
        $this->assertStringContainsString('(estimé : +3,00', $m['text']);
        // Trop tôt : 4 jours au B+14
        $early = $measure->measure($row($fast), 'fr', $b->modify('+14 days'));
        $this->assertSame([4, false, 'low', ['TOO_EARLY']], [$early['window']['days'], $early['window']['complete'], $early['confidence'], array_column($early['caveats'], 'code')]);
        $this->assertStringContainsString('trop tôt', $early['text']);
        // Promotion sur un article qui ne se vendait pas : le texte parle des ventes obtenues
        $slow = $insert('SLOW_STOCK', $s, 'done', ['promotionPercent' => 20], $d(10));
        $ms = $measure->measure($row($slow), 'fr', $b->modify('+30 days'));
        $this->assertSame([0, 3], [$ms['before']['units'], $ms['after']['units']]);
        $this->assertStringStartsWith('Mesure promo : 3 ventes depuis la promotion', $ms['text']);
        // Leçons et autres genres : non mesurables (C1)
        $lesson = $insert('LESSON_STOCKOUT', $a, 'done', null, null);
        $this->assertSame(['measurable' => false], array_intersect_key($measure->measure($row($lesson), 'fr', $b->modify('+30 days')), ['measurable' => 1]));
        // Saison : même sens, ampleur comparable (± 50 %)
        $this->assertSame([true, false, false, false, false, true], [AdviceMeasure::seasonal(100, 150, 100, 140), AdviceMeasure::seasonal(100, 150, 100, 100), AdviceMeasure::seasonal(100, 150, 100, 60), AdviceMeasure::seasonal(0, 150, 100, 140), AdviceMeasure::seasonal(100, 150, 100, 110), AdviceMeasure::seasonal(100, 150, 100, 170)], 'même sens et ± 50 % : +40 % et +70 % pour +50 % oui ; +10 % non');
        // Vignette advice-result : la dernière mesure complète
        $tile = $advices->resultTile('fr', $b->modify('+30 days'));
        $this->assertSame([$slow, 'SLOW_STOCK'], [$tile['adviceId'], $tile['kind']], 'la plus récente d\'abord (même date : la dernière)');

        // Routes : validation de applied, fait, mesure (409 tant que le conseil n'est pas fait)
        $new = $insert('FAST_SELLER', $a, 'new', null, null);
        $this->request('GET', "/api/gestion/conseils/$new/mesure");
        $this->assertSame([409, 'ADVICE_NOT_DONE'], [$this->status(), $this->body()['error']]);
        $this->request('POST', "/api/gestion/conseils/$new/fait", ['applied' => ['appliedOn' => $today->modify('+1 day')->format('Y-m-d'), 'pricePercent' => 400, 'productIds' => [999999999], 'campaignId' => 999999999]]);
        $this->assertSame([422, ['applied.appliedOn' => 'DATE_IN_FUTURE', 'applied.pricePercent' => 'OUT_OF_RANGE', 'applied.productIds[0]' => 'PRODUCT_NOT_FOUND', 'applied.campaignId' => 'CAMPAIGN_NOT_FOUND']], [$this->status(), $this->body()['fields']]);
        $this->assertSame('new', $conn->fetchOne('SELECT status FROM gestion_advice WHERE id = ?', [$new]), 'rien n\'est écrit');
        $done = $this->send('POST', "/api/gestion/conseils/$new/fait", ['applied' => ['pricePercent' => 7.5]], 200);
        $this->assertSame([['pricePercent' => 7.5], $today->format('Y-m-d')], [$done['applied'], $done['appliedOn']], 'date par défaut : aujourd\'hui');
        $mr = $this->get("/api/gestion/conseils/$new/mesure");
        $this->assertSame([true, 0, ['TOO_EARLY']], [$mr['measurable'], $mr['window']['days'], array_column($mr['caveats'], 'code')]);
        $this->request('GET', '/api/gestion/conseils/999999999/mesure');
        $this->assertSame([404, 'ADVICE_NOT_FOUND'], [$this->status(), $this->body()['error']]);
        $all = $this->get("/api/gestion/conseils?periodId=$pid&status=all");
        $listed = array_column($all['advices'], null, 'id');
        $this->assertSame([false, null], [$listed[$new]['measurement']['complete'], $listed[$lesson]['measurement']], 'résumé de mesure dans la liste ; leçon : aucune');
    }

    // ----- helpers -----

    private function aiResponse(array $out): object
    {
        return json_decode(json_encode([
            'id' => 'msg_' . bin2hex(random_bytes(4)), 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-sonnet-5-5', 'stop_reason' => 'end_turn',
            'content' => [['type' => 'text', 'text' => json_encode($out, JSON_UNESCAPED_UNICODE)]],
            'usage' => ['input_tokens' => 2000, 'output_tokens' => 300, 'cache_read_input_tokens' => 0, 'cache_creation_input_tokens' => 0],
        ]), false);
    }

    private function runWorker(int $runId): void
    {
        static::getContainer()->get(GenerateGestionAdviceHandler::class)(new GenerateGestionAdviceMessage($runId, MV_TEST_TENANT_CODE));
        $this->em()->clear();
        $this->endOfWrite();
    }

    private function period(int $offset, string $tag): int
    {
        $start = static::getContainer()->get(PeriodCalendar::class)->today()->modify("$offset days");
        $id = (int) $this->em()->getConnection()->fetchOne(
            "INSERT INTO collections (id, budget_collection, start_date_collection, end_date_collection, del, nom_collection, is_closed, kind, period_key) VALUES (nextval('collections_id_seq'), 0, ?, ?, false, ?, false, 'period', ?) RETURNING id",
            [$start->format('Y-m-d 00:00:00'), $start->modify('+29 days')->format('Y-m-d 00:00:00'), "Conseils $tag", $tag . '-' . bin2hex(random_bytes(2))]
        );
        $this->periods[] = $id;
        $this->em()->clear();

        return $id;
    }

    /** @return array{0: int, 1: int} article standard (prix 25,00) au coût donné par une ligne d'achat datée, et sa variante au stock donné */
    private function variant(string $name, int $stock, int $cost, string $boughtOn): array
    {
        $conn = $this->em()->getConnection();
        $slug = 'adv-' . bin2hex(random_bytes(4));
        $conn->executeStatement("INSERT INTO product (id, name, price, isbestseller, quantity, created_at, slug, product_type, sale_enabled, is_web) VALUES (nextval('product_id_seq'), :n, 2500, false, :q, :c, :s, 'standard', true, true)", ['n' => $name, 'q' => $stock, 's' => $slug, 'c' => $boughtOn . ' 08:00:00']);
        $id = (int) $conn->fetchOne('SELECT id FROM product WHERE slug = ?', [$slug]);
        $variant = (int) $this->create(ProductVariant::class, ['product' => $this->em()->find(Product::class, $id)])->getId();
        $conn->executeStatement('UPDATE product_variant SET stock_quantity = ?, price = NULL WHERE id = ?', [$stock, $variant]);
        $cid = (int) $conn->fetchOne("INSERT INTO commande (id, date, name, kind, budget) VALUES (nextval('commande_id_seq'), ?, 'Conseil', 'standard', 0) RETURNING id", [$boughtOn . ' 09:00:00']);
        $conn->executeStatement('INSERT INTO commande_line (commande_id, product_id, quantity_ordered, unit_cost_cents, attached_at, created_at, updated_at) VALUES (?, ?, 1, ?, NOW(), NOW(), NOW())', [$cid, $id, $cost]);

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

    private function get(string $route): array
    {
        $this->request('GET', $route);
        $this->assertSame(200, $this->status(), $route . ' : ' . substr((string) $this->client->getResponse()->getContent(), 0, 400));

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
        $email = 'gestion-g1-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $em = $this->em();
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Gestion')->setLastname('Guide')->setRoles(['ROLE_ADMIN'])->setIsVerified(true)->setPassword('inutilisable');
        $em->persist($user);
        $em->flush();
        $this->userId = (int) $user->getId();
        $this->email = $email;
        $this->token = static::getContainer()->get('lexik_jwt_authentication.jwt_manager')->create($user);
        $this->endOfWrite();
    }
}
