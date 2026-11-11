<?php

namespace App\Tests\Functional\Gestion;

use App\Entity\Product;
use App\Entity\ProductVariant;
use App\Entity\User;
use App\EventSubscriber\CacheInvalidationSubscriber;
use App\Services\GestionService\GestionConflictException;
use App\Services\GestionService\GestionPreparationAdviceService;
use App\Services\GestionService\GestionPreparationService;
use App\Services\GestionService\PeriodCalendar;
use App\Services\TenantEntityManagerProvider;
use App\Tests\Fake\FakeLandingAiClient;
use App\Tests\Functional\Cache\CacheTestEntityFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Lot P6, préparer une période entière (11/11/2026, `docs/preparation-periode.md`, contrat validé avec C1) : objectif, stock disponible (stock
 * actuel + achats × coefficient), marge au prorata, frais de la période visée (voyage dans une autre période : TRIP_IN_OTHER_PERIOD), prévu par
 * période (achats et voyage dans la période de leur date), trésorerie depuis aujourd'hui et point bas, candidats de l'avis (cashSafe, enoughStock),
 * avis des règles, Valider (budgets remplacés / ajoutés, voyage créé, tout ou rien sur TRIP_OVERLAP), période fermée, validations.
 * Les calculs exacts se font dans une transaction annulée à la fin : stock de la base partagée mis à zéro, périodes insérées loin dans le futur.
 */
class PreparationApiTest extends WebTestCase
{
    use CacheTestEntityFactory;

    private KernelBrowser $client;
    private string $token = '';

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->login();
    }

    public function testPlanAdviceAndValidateInIsolation(): void
    {
        $prep = static::getContainer()->get(GestionPreparationService::class);
        $advice = static::getContainer()->get(GestionPreparationAdviceService::class);
        $today = static::getContainer()->get(PeriodCalendar::class)->today();
        $b = $today->modify('+9000 days');
        $d = fn (int $n) => $b->modify("$n days")->format('Y-m-d');
        $t = $b->modify('-20 days');
        $conn = $this->em()->getConnection();
        $savepoints = $conn->getNestTransactionsWithSavepoints();
        $conn->setNestTransactionsWithSavepoints(true); // « Valider » s'annule pour de vrai à l'intérieur de la transaction du test
        $conn->beginTransaction();
        try {
            foreach (['UPDATE product_variant SET stock_quantity = 0', 'UPDATE product SET quantity = 0', 'DELETE FROM gestion_cash_balance', 'DELETE FROM gestion_cash_movement', 'DELETE FROM gestion_cash_setting', 'DELETE FROM gestion_trip'] as $sql) {
                $conn->executeStatement($sql);
            }
            $target = $this->period($b, 30, 'prep-t');
            $before = $this->period($b->modify('-30 days'), 30, 'prep-b');
            // Stock : 10 × 20,00 $ au coût de 10,00 $
            $pid = (int) $conn->fetchOne("INSERT INTO product (id, name, price, isbestseller, isfeatured, isnewarrival, isspecialoffer, is_accessory, is_landing_page, quantity, created_at, slug, product_type, sale_enabled, is_web, is_pos) VALUES (nextval('product_id_seq'), 'Prépa', 2000, false, false, false, false, false, false, 10, NOW(), 'p6-stock', 'standard', true, true, true) RETURNING id");
            $conn->executeStatement("INSERT INTO product_variant (id, product_id, stock_quantity) VALUES (nextval('product_variant_id_seq'), ?, 10)", [$pid]);
            $cid = (int) $conn->fetchOne("INSERT INTO commande (id, date, name, kind, budget) VALUES (nextval('commande_id_seq'), ?, 'Prépa', 'standard', 0) RETURNING id", [$t->modify('-100 days')->format('Y-m-d') . ' 09:00:00']);
            $conn->executeStatement('INSERT INTO commande_line (commande_id, product_id, quantity_ordered, unit_cost_cents, attached_at, created_at, updated_at) VALUES (?, ?, 10, 1000, NOW(), NOW(), NOW())', [$cid, $pid]);
            $conn->executeStatement('INSERT INTO gestion_cash_balance (balance_date, amount_cents, created_at, updated_at) VALUES (?, 100000, NOW(), NOW())', [$t->format('Y-m-d')]);

            $body = ['periodId' => $target, 'sales' => ['method' => 'custom', 'amount' => 50000],
                'purchases' => [['date' => $d(-10), 'amount' => 10000, 'coefficient' => 2], ['date' => $d(5), 'amount' => 5000, 'coefficient' => 3]],
                'trip' => ['startDate' => $d(-15), 'endDate' => $d(-11), 'place' => 'Lyon', 'budgetTotal' => 4000],
                'planned' => ['shipping' => 500, 'advertising' => 1000, 'other' => 2000]];
            $s = $prep->simulate($body, $t);
            $plan = $s['plan'];
            $this->assertSame([50000, false, 27273, 54.55], [$plan['sales']['planned'], $plan['sales']['capped'], $plan['grossMargin'], $plan['tauxMarque']], 'marge au prorata : 18 182 × 50 % + 31 818 × (1 − 15 000 / 35 000)');
            $this->assertSame([3500, true, 23773, ['revenue' => 6417, 'reached' => true]], [$plan['expenses']['total'], $plan['expenses']['tripInOtherPeriod'], $plan['result'], $plan['breakEven']], 'voyage dans la période d\'avant : hors du résultat visé');
            $this->assertSame([['costValue' => 10000, 'saleValue' => 20000], 15000, 35000, 5000], [$plan['stockStart'], $plan['purchases']['total'], $plan['purchases']['saleValue'], $plan['stockEnd']['saleValue']]);
            $planned = array_column($s['planned'], null, 'mode');
            $this->assertSame([$before, 10000, 4000, 'add'], [$planned['add']['period']['id'], $planned['add']['purchases'], $planned['add']['trip'], $planned['add']['mode']]);
            $this->assertSame([$target, 5000, 0, 500, 1000, 2000], [$planned['replace']['period']['id'], $planned['replace']['purchases'], $planned['replace']['trip'], $planned['replace']['shipping'], $planned['replace']['advertising'], $planned['replace']['other']]);
            $this->assertSame([['date' => $d(-10), 'amount' => 86000], 'TAXES_EXCLUDED'], [$plan['cash']['low'], in_array('TAXES_EXCLUDED', array_column($s['caveats'], 'code'), true) ? 'TAXES_EXCLUDED' : null], 'point bas avant la période : voyage 40,00 $ puis achat 100,00 $');
            $this->assertContains('TRIP_IN_OTHER_PERIOD', array_column($s['caveats'], 'code'));
            // Objectif au-delà du disponible : plafonné
            $capped = $prep->simulate(['sales' => ['method' => 'custom', 'amount' => 90000]] + $body, $t);
            $this->assertSame([55000, true, 'TARGET_EXCEEDS_STOCK'], [$capped['plan']['sales']['planned'], $capped['plan']['sales']['capped'], in_array('TARGET_EXCEEDS_STOCK', array_column($capped['caveats'], 'code'), true) ? 'TARGET_EXCEEDS_STOCK' : null]);

            // Candidats : seuil 900,00 $ → achats d'avant le point bas réduits de 40,00 $ ; enoughStock : disponible = objectif × 1,2
            $conn->executeStatement('INSERT INTO gestion_cash_setting (id, threshold_cents, include_estimated_sales, updated_at) VALUES (1, 90000, false, NOW())');
            $cands = array_column($advice->candidates($body, $prep->simulate($body, $t), $t, 'fr'), null, 'key');
            $this->assertSame(['myPlan', 'cashSafe', 'enoughStock'], array_keys($cands));
            // achats réduits : point bas au seuil ; le disponible (470,00 $) ne couvre plus l'objectif, ventes plafonnées : marge 26 000, résultat − 12,73 $
            $this->assertSame([-4000, -1273, ['date' => $d(-10), 'amount' => 90000], 47000], [$cands['cashSafe']['delta']['purchases'], $cands['cashSafe']['delta']['result'], $cands['cashSafe']['cashLow'], $cands['cashSafe']['sales']]);
            $this->assertSame(17143, $cands['enoughStock']['purchases'], '40 000 de valeur de vente à acheter, aux coefficients du plan');
            // Stock actuel suffisant (objectif 100,00 $ × 1,2 sous les 200,00 $ en stock) : aucun achat ; texte propre au cas (sans « 0,00 $ d'achats »)
            $small = ['sales' => ['method' => 'custom', 'amount' => 10000]] + $body;
            $smallSim = $prep->simulate($small, $t);
            $smallCands = array_values(array_filter($advice->candidates($small, $smallSim, $t, 'fr'), fn ($c) => $c['key'] !== 'cashSafe'));
            $this->assertSame(0, array_column($smallCands, null, 'key')['enoughStock']['purchases']);
            $rule = (new \ReflectionMethod($advice, 'fallback'))->invoke($advice, $smallSim, $smallCands, 'fr');
            $this->assertSame(['enoughStock', 'Votre stock actuel couvre déjà l’objectif avec une marge de sécurité : aucun achat n’est nécessaire pour cette période.'], [$rule['recommended'], $rule['text']]);
            // Avis : texte de l'IA rejeté (chiffre inventé) → avis des règles cashSafe
            FakeLandingAiClient::reset();
            FakeLandingAiClient::$queue[] = json_decode(json_encode(['type' => 'message', 'role' => 'assistant', 'model' => 'claude-sonnet-5-5', 'stop_reason' => 'end_turn',
                'content' => [['type' => 'text', 'text' => json_encode(['recommended' => 'myPlan', 'title' => 'Gardez', 'text' => 'Vous gagnerez 999,00 $.'])]], 'usage' => ['input_tokens' => 10, 'output_tokens' => 10]]), false);
            $a = $advice->advise($body, 'fr', null, $t);
            $this->assertSame(['cashSafe', 'rules', true], [$a['comment']['recommended'], $a['comment']['textSource'], $a['comment']['rejected'] ?? false]);
            $this->assertTrue($advice->advise($body, 'fr', null, $t)['cached'], 'même plan, même jour : même avis');
            $conn->executeStatement('DELETE FROM gestion_cash_setting');

            // Valider : période visée remplacée ; période d'avant (C1) : achats et voyage AJOUTÉS au prévu existant (300,00 $ de stock, 15,00 $ de
            // voyage déjà prévus), ses autres postes (frais de port 3,00 $, autre 7,77 $) intacts ; voyage créé
            $conn->executeStatement("INSERT INTO gestion_budget_line (period_id, category, planned_cents, updated_at) VALUES (?, 'purchases', 30000, NOW()), (?, 'trip', 1500, NOW()), (?, 'shipping', 300, NOW()), (?, 'other', 777, NOW())", [$before, $before, $before, $before]);
            $sim = $prep->simulate($body, $t);
            $this->expectNoWriteBeforeValidate($conn, $target);
            $v = $prep->validate($body + ['previewHash' => $sim['previewHash']], 'gerant@example.invalid', $t);
            $lines = fn (int $p) => array_column($conn->fetchAllAssociative('SELECT category, planned_cents FROM gestion_budget_line WHERE period_id = ? ORDER BY category', [$p]), 'planned_cents', 'category');
            $this->assertSame(['advertising' => 1000, 'other' => 2000, 'purchases' => 5000, 'shipping' => 500], array_map('intval', $lines($target)), 'période visée : remplacée');
            $this->assertSame(['other' => 777, 'purchases' => 40000, 'shipping' => 300, 'trip' => 5500], array_map('intval', $lines($before)), 'période d\'avant : ancien prévu + achats et voyage, autres postes intacts');
            $this->assertSame(['Lyon', $d(-15), 4000], [$v['trip']['place'], $v['trip']['startDate'], $v['trip']['budgetTotal']]);
            // Tout ou rien : le même voyage chevauche → 409, aucun budget de plus
            try {
                $prep->validate($body + ['previewHash' => $prep->simulate($body, $t)['previewHash']], null, $t);
                $this->fail('TRIP_OVERLAP attendu');
            } catch (GestionConflictException $e) {
                $this->assertSame('TRIP_OVERLAP', $e->errorCode);
            }
            $this->assertSame(40000, (int) $lines($before)['purchases'], 'rien n\'a été ajouté une seconde fois');
            // Empreinte périmée
            try {
                $prep->validate($body + ['previewHash' => 'x'], null, $t);
                $this->fail('PRICES_CHANGED attendu');
            } catch (GestionConflictException $e) {
                $this->assertSame('PRICES_CHANGED', $e->errorCode);
            }
            // Dates : avant aujourd'hui ou après la période → OUT_OF_RANGE ; période fermée → 409
            try {
                $prep->simulate(['purchases' => [['date' => $d(-25), 'amount' => 100]]] + $body, $t);
                $this->fail('OUT_OF_RANGE attendu');
            } catch (\App\Services\GestionService\GestionValidationException $e) {
                $this->assertSame(['purchases[0].date' => 'OUT_OF_RANGE'], $e->fields);
            }
            $closed = $this->period($today->modify('-900 days'), 30, 'prep-c');
            try {
                $prep->simulate(['periodId' => $closed] + $body, $t);
                $this->fail('PERIOD_CLOSED attendu');
            } catch (GestionConflictException $e) {
                $this->assertSame('PERIOD_CLOSED', $e->errorCode);
            }
        } finally {
            $conn->rollBack();
            $conn->setNestTransactionsWithSavepoints($savepoints);
            $this->em()->clear();
        }
    }

    public function testRoutesAndEstimations(): void
    {
        $this->request('GET', '/api/gestion/preparation/estimations');
        $this->assertSame([422, ['periodId' => 'REQUIRED']], [$this->status(), $this->body()['fields']]);
        $current = static::getContainer()->get(PeriodCalendar::class)->current();
        $this->request('GET', '/api/gestion/preparation/estimations?periodId=' . $current->getId());
        $this->assertSame(200, $this->status());
        $e = $this->body();
        $this->assertSame([$current->getId(), true], [$e['period']['id'], in_array($e['recommended']['method'], ['lastYear', 'average', 'pace'], true)]);
        $this->assertArrayHasKey('reason', $e['recommended']);
        $this->request('POST', '/api/gestion/preparation/simulation', ['periodId' => $current->getId(), 'sales' => ['method' => 'pigeon'], 'purchases' => [['date' => 'x', 'amount' => 0, 'coefficient' => 50]], 'planned' => ['other' => -1]]);
        $this->assertSame([422, ['sales.method' => 'INVALID_METHOD', 'purchases[0].date' => 'INVALID_DATE', 'purchases[0].amount' => 'MUST_BE_POSITIVE', 'purchases[0].coefficient' => 'OUT_OF_RANGE', 'planned.other' => 'MUST_BE_INTEGER_CENTS']],
            [$this->status(), $this->body()['fields']]);
        $before = (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM gestion_budget_line');
        $this->request('POST', '/api/gestion/preparation/simulation', ['periodId' => $current->getId(), 'sales' => ['method' => 'custom', 'amount' => 1000]]);
        $this->assertSame(200, $this->status());
        $this->assertSame($before, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM gestion_budget_line'), 'simulation : aucune écriture');
    }

    // ----- helpers -----

    private function expectNoWriteBeforeValidate(\Doctrine\DBAL\Connection $conn, int $target): void
    {
        $this->assertSame(0, (int) $conn->fetchOne('SELECT COUNT(*) FROM gestion_budget_line WHERE period_id = ?', [$target]), 'simulations et avis : aucune écriture');
    }

    private function period(\DateTimeImmutable $start, int $days, string $tag): int
    {
        return (int) $this->em()->getConnection()->fetchOne(
            "INSERT INTO collections (id, budget_collection, start_date_collection, end_date_collection, del, nom_collection, is_closed, kind, period_key) VALUES (nextval('collections_id_seq'), 0, ?, ?, false, ?, false, 'period', ?) RETURNING id",
            [$start->format('Y-m-d 00:00:00'), $start->modify('+' . ($days - 1) . ' days')->format('Y-m-d 00:00:00'), "Période $tag", $tag . '-' . bin2hex(random_bytes(2))]
        );
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

    protected function em(): EntityManagerInterface
    {
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);

        return $provider->getEntityManager();
    }

    private function login(): void
    {
        $email = 'gestion-p6-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $em = $this->em();
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Gestion')->setLastname('Prépa')->setRoles(['ROLE_ADMIN'])->setIsVerified(true)->setPassword('inutilisable');
        $em->persist($user);
        $em->flush();
        $this->token = static::getContainer()->get('lexik_jwt_authentication.jwt_manager')->create($user);
        static::getContainer()->get(CacheInvalidationSubscriber::class)->invalidateWrittenTenants();
    }
}
