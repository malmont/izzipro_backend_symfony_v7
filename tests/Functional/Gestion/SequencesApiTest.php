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
use App\Gestion\Sequences\SequenceCatalog;
use App\Gestion\Sequences\SequenceFacts;
use App\Gestion\Services\GestionFiscalYearService;
use App\Gestion\Services\GestionSequenceService;
use App\Gestion\Services\PeriodCalendar;
use App\Gestion\Services\RepriseHistoriqueService;
use App\Services\Platform\EntitlementService;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use App\Tests\Functional\Cache\CacheTestEntityFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Guide des séquences (lot IA-SEQ, 13/11/2026, `docs/sequences-gestion.md`) : séquences à faire une fois lues du cheminement, séquences
 * qui reviennent calculées depuis les données, étape suivante, instances, et la liste des articles sans coût qu'une étape ouvre.
 * Règle du lot : la lecture n'écrit rien et ne fige rien (nombre de lignes de toutes les tables identique avant et après).
 *
 * La base de test est partagée : le test met de côté, le temps de son exécution, les soldes et mouvements de trésorerie laissés par
 * d'autres tests, et retire ce qu'il crée.
 */
class SequencesApiTest extends WebTestCase
{
    use CacheTestEntityFactory;

    private const ASIDE = ['gestion_cash_balance', 'gestion_cash_movement'];
    private const NOTE = 'IA-SEQ';

    private KernelBrowser $client;
    private string $token = '';
    private int $userId = 0;
    private string $tag = '';
    /** @var array<string, list<array<string, mixed>>> */
    private array $aside = [];
    /** @var list<array{0: string, 1: list<mixed>}|\Closure> nettoyage (requête ou fonction), joué du dernier au premier */
    private array $cleanup = [];
    private ?int $grantedId = null;
    private bool $repriseApplied = false;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->tag = 'seq' . bin2hex(random_bytes(3));
        $this->login(['ROLE_ADMIN']);
        $conn = $this->em()->getConnection();
        foreach (self::ASIDE as $table) {
            $this->aside[$table] = $conn->fetchAllAssociative("SELECT * FROM $table ORDER BY id");
            $conn->executeStatement("DELETE FROM $table");
        }
        // Le site de test n'a pas l'application de gestion : le droit est accordé le temps du test (groupe `app.management` du cheminement)
        $entitlements = static::getContainer()->get(EntitlementService::class);
        if (!$entitlements->isEnabled(MV_TEST_TENANT_CODE, 'app.management')) {
            $this->grantedId = (int) $entitlements->grant(MV_TEST_TENANT_CODE, 'app.management', 'manual', null, 'test', self::NOTE, null, null, false)['id'];
        }
        $this->endOfWrite();
    }

    protected function tearDown(): void
    {
        if (static::getContainer()->get(SequenceFacts::class)->reprise() !== null && $this->repriseApplied) {
            static::getContainer()->get(RepriseHistoriqueService::class)->cancel(); // un échec en cours de route ne laisse pas de reprise aux tests suivants
        }
        $conn = $this->em()->getConnection();
        foreach (array_reverse($this->cleanup) as $entry) {
            $entry instanceof \Closure ? $entry($conn) : $conn->executeStatement($entry[0], $entry[1]);
        }
        if ($this->grantedId !== null) {
            static::getContainer()->get(EntitlementService::class)->cancel($this->grantedId, 'test', self::NOTE);
            $master = static::getContainer()->get(TenantConnectionManager::class)->getPdoMaster();
            $master->prepare('DELETE FROM tenant_entitlement_events WHERE entitlement_id = ?')->execute([$this->grantedId]);
            $master->prepare('DELETE FROM tenant_entitlements WHERE id = ?')->execute([$this->grantedId]);
        }
        foreach ($this->aside as $table => $rows) {
            $conn->executeStatement("DELETE FROM $table");
            foreach ($rows as $row) {
                $conn->insert($table, array_map(fn ($v) => is_bool($v) ? ($v ? 't' : 'f') : $v, $row));
            }
        }
        $this->endOfWrite();
        parent::tearDown();
    }

    public function testReadingTheGuideWritesNothingAndFreezesNothing(): void
    {
        // Des périodes fermées jamais lues : leur résultat et leur tableau de bord ne sont pas figés, et doivent le rester
        $calendar = static::getContainer()->get(PeriodCalendar::class);
        $calendar->ensurePeriods($calendar->today()->modify('-830 days'));
        $calendar->current();
        $this->endOfWrite();
        $closed = array_filter($this->em()->getRepository(Collections::class)->findBy(['kind' => Collections::KIND_PERIOD]), fn (Collections $c) => static::getContainer()->get(PeriodCalendar::class)->isClosed($c));
        $this->assertGreaterThanOrEqual(4, count($closed), 'des périodes fermées existent');
        // Une période fermée sûrement jamais lue : ce que d'autres tests y auraient figé est mis de côté le temps du test, puis remis
        $unread = (int) array_values($closed)[0]->getId();
        $conn = $this->em()->getConnection();
        foreach (['period_result', 'collection_statistiques'] as $table) {
            $rows = $conn->fetchAllAssociative("SELECT * FROM $table WHERE collection_id = ?", [$unread]);
            $conn->executeStatement("DELETE FROM $table WHERE collection_id = ?", [$unread]);
            $this->cleanup[] = function ($conn) use ($table, $rows, $unread): void {
                $conn->executeStatement("DELETE FROM $table WHERE collection_id = ?", [$unread]);
                foreach ($rows as $row) {
                    $conn->insert($table, array_map(fn ($v) => is_bool($v) ? ($v ? 't' : 'f') : $v, $row));
                }
            };
        }
        $this->endOfWrite();
        $frozen = fn (): array => [(int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM period_result'), (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM collection_statistiques'),
            (int) $this->em()->getConnection()->fetchOne('SELECT (SELECT COUNT(*) FROM period_result WHERE collection_id = :id) + (SELECT COUNT(*) FROM collection_statistiques WHERE collection_id = :id)', ['id' => $unread])];
        $frozenBefore = $frozen();
        $this->assertSame(0, $frozenBefore[2], 'une période fermée sans résultat ni tableau de bord figé');

        $before = $this->rowCounts();
        $list = $this->get('/api/gestion/sequences');
        $this->assertNotEmpty($list['sequences']);
        foreach ($list['sequences'] as $sequence) {
            $this->get('/api/gestion/sequences/' . $sequence['key']);
            $this->get('/api/gestion/sequences/' . $sequence['key'] . '?locale=en');
            foreach ($sequence['instances'] as $instance) {
                $this->get('/api/gestion/sequences/' . $sequence['key'] . '?instance=' . rawurlencode($instance['id']));
            }
        }
        $this->get('/api/gestion/sequences?locale=en');
        $this->get('/api/gestion/articles?filter=missing-cost');
        $after = $this->rowCounts();

        $this->assertSame($frozenBefore, $frozen(), 'aucun résultat de période, aucun tableau de bord figé par la lecture du guide');
        $this->assertSame([], array_diff_assoc($after, $before) + array_diff_assoc($before, $after), 'nombre de lignes de chaque table du site et de la base maître identique avant et après');
        $this->assertGreaterThan(150, count($before), 'toutes les tables sont comptées');
        $this->assertArrayHasKey('site.period_result', $before);
        $this->assertArrayHasKey('site.collections', $before);
        $this->assertArrayHasKey('site.content_audit_log', $before);
        $this->assertArrayHasKey('master.tenant_entitlements', $before);
    }

    public function testTheOnceSequencesAreTheStepsOfTheSetupPathInBothLanguages(): void
    {
        foreach (['fr' => '', 'en' => '?locale=en'] as $locale => $query) {
            $readiness = $this->get('/api/site/readiness' . $query);
            $list = array_column($this->get('/api/gestion/sequences' . $query)['sequences'], null, 'key');
            $this->assertNotEmpty($readiness['groups']);
            foreach ($readiness['groups'] as $group) {
                $key = SequenceCatalog::onceKey($group['key']);
                $this->assertArrayHasKey($key, $list, "$locale : une séquence par groupe accordé");
                $this->assertSame(['once', $group['key'], $group['label'], in_array($group['key'], SequenceCatalog::TRANSLATED_GROUPS, true), []], [$list[$key]['kind'], $list[$key]['group'], $list[$key]['title'], $list[$key]['translated'], $list[$key]['instances']]);
                $this->assertArrayNotHasKey('steps', $list[$key], 'la liste ne porte pas les étapes');

                $expected = array_values(array_filter($readiness['steps'], fn (array $s) => $s['group'] === $group['key']));
                $this->assertSame([], array_filter($expected, fn (array $s) => str_starts_with($s['key'], 'obtain.')), 'une étape « Obtenir » n\'est jamais dans un groupe accordé');
                $detail = $this->get('/api/gestion/sequences/' . $key . $query);
                $this->assertSame(array_map(fn (array $s) => [$s['key'], $s['title'], $s['description'], $s['status'], $s['blocking']], $expected),
                    array_map(fn (array $s) => [$s['key'], $s['title'], $s['description'], $s['status'], $s['blocking']], $detail['steps']), "$locale $key : les étapes du cheminement, telles quelles");
                $this->assertSame($group['blockingTodo'], $detail['progress']['blockingTodo']);
                $this->assertSame($group['done'], $detail['progress']['done']);
                $this->assertSame($list[$key]['progress'], $detail['progress']);
                $this->assertSame($list[$key]['next'], $detail['next']);
                $this->assertSame($group['blockingTodo'] === 0 ? 'ready' : 'in_progress', $detail['status']);
                $this->assertNull($detail['instance']);
                foreach ($detail['steps'] as $i => $step) {
                    $this->assertSame(['key', 'title', 'description', 'status', 'blocking', 'screen', 'action', 'figures'], array_keys($step));
                    $this->assertSame(['where', 'key', 'params', 'url'], array_keys($step['screen']));
                    $this->assertSame(SequenceCatalog::preparable($step['action']['key']), $step['action']['preparable']);
                    $this->assertNotSame('', $step['action']['key']);
                    if (($expected[$i]['appScreen'] ?? null) !== null) {
                        $this->assertSame(['app', $expected[$i]['appScreen'], null], [$step['screen']['where'], $step['screen']['key'], $step['screen']['url']], $step['key']);
                    } else {
                        $this->assertSame($expected[$i]['where'], $step['screen']['where'], $step['key']);
                    }
                    foreach ($step['figures'] as $figure) {
                        $this->assertSame(['key', 'label', 'value', 'unit'], array_keys($figure));
                    }
                }
                // Étape suivante : la première indispensable à faire, sinon la première amélioration à faire
                $todo = array_values(array_filter($detail['steps'], fn (array $s) => $s['status'] === 'todo'));
                $first = array_values(array_filter($todo, fn (array $s) => $s['blocking']))[0] ?? array_values(array_filter($todo, fn (array $s) => !$s['blocking']))[0] ?? null;
                $this->assertSame($first['key'] ?? null, $detail['next']['stepKey'] ?? null, "$locale $key : étape suivante");
            }
        }
        // Le groupe de la gestion est traduit, celui du site ne l'est pas ; un écran du back-office porte son adresse
        $management = $this->get('/api/gestion/sequences/setup.management?locale=en');
        $this->assertTrue($management['translated']);
        $steps = array_column($management['steps'], null, 'key');
        $this->assertSame('Site short code', $steps['management.short_code']['title']);
        $this->assertSame('backend', $steps['management.short_code']['screen']['where']);
        $this->assertStringContainsString('/admin', (string) $steps['management.short_code']['screen']['url']);
        $this->assertSame('readiness.management.short_code', $steps['management.short_code']['action']['key']);
        $this->assertSame(['supplier.create', 'fournisseurs'], [$steps['management.suppliers']['action']['key'], $steps['management.suppliers']['screen']['key']]);
        // Depuis le lot IA-FACT-1, la conversation sait préparer la confirmation d'une facture ; les autres actions pas encore
        $this->assertSame(['key' => 'document.invoice.confirm', 'preparable' => true, 'params' => []], $steps['management.first_purchase']['action']);
        $this->assertSame(['key' => 'supplier.create', 'preparable' => true, 'params' => []], $steps['management.suppliers']['action'], 'lot IA-ACT-1 : le fil sait créer un fournisseur');
        $this->assertFalse($steps['management.cash']['action']['preparable']);
        $this->assertSame(['filter' => 'missing-cost'], $steps['management.purchase_cost']['screen']['params']);
        $figures = array_column($steps['management.purchase_cost']['figures'], null, 'key');
        $this->assertSame(['Items without a cost', 'count'], [$figures['missing']['label'], $figures['missing']['unit']]);
        $site = $this->get('/api/gestion/sequences/setup.site?locale=en');
        $this->assertFalse($site['translated'], 'groupe non traduit : il reste en français et le dit');

        // Le compteur de l'étape et la liste qu'elle ouvre disent la même chose (une seule règle)
        $this->request('GET', '/api/gestion/articles?filter=missing-cost');
        $this->assertSame((string) $figures['missing']['value'], $this->client->getResponse()->headers->get('X-Total-Count'));
        $this->assertCount($figures['missing']['value'], $this->body());
    }

    public function testPreparingAPeriodFollowsTheData(): void
    {
        $calendar = static::getContainer()->get(PeriodCalendar::class);
        $today = $calendar->today();
        $current = PeriodCalendar::definition($today, $calendar->frequency());
        $next = PeriodCalendar::definition($current['end']->modify('+1 day'), $calendar->frequency());
        $calendar->current();
        $this->endOfWrite();
        $conn = $this->em()->getConnection();
        // Le calendrier crée la période suivante avec celle en cours, à l'ouverture de l'application. Pour un site où elle n'existe pas
        // encore, la ligne est mise de côté le temps du test (sa clé est changée, puis remise) : le guide doit la décrire sans la créer.
        $existing = $conn->fetchOne("SELECT id FROM collections WHERE kind = 'period' AND period_key = ?", [$next['key']]);
        if ($existing !== false) {
            $conn->executeStatement('UPDATE collections SET period_key = ? WHERE id = ?', ["cote-$this->tag", $existing]);
            $this->cleanup[] = ['UPDATE collections SET period_key = ? WHERE id = ?', [$next['key'], $existing]];
            $this->endOfWrite();
        }
        $this->assertFalse($conn->fetchOne("SELECT id FROM collections WHERE kind = 'period' AND period_key = ?", [$next['key']]), 'la période suivante n\'existe pas en base');

        $list = array_column($this->get('/api/gestion/sequences')['sequences'], null, 'key');
        $sequence = $list['period.prepare'];
        $this->assertSame(['recurring', true], [$sequence['kind'], $sequence['translated']]);
        $this->assertSame([[$current['key'], true], [$next['key'], false]], array_map(fn (array $i) => [$i['id'], $i['current']], $sequence['instances']), 'la période en cours et la suivante');
        $this->assertSame([$current['start']->format('Y-m-d'), $current['end']->format('Y-m-d')], [$sequence['instances'][0]['from'], $sequence['instances'][0]['to']]);
        $this->assertIsInt($sequence['instances'][0]['periodId']);
        $this->assertNull($sequence['instances'][1]['periodId'], 'une période qui n\'existe pas en base est décrite sans être créée');
        $this->assertSame([$sequence['instances'][0]['status'], $sequence['instances'][0]['progress'], $sequence['instances'][0]['next']], [$sequence['status'], $sequence['progress'], $sequence['next']], 'dans la liste : statut et avancement de l\'instance courante');
        $this->assertSame($sequence['instances'][0]['id'], $this->get('/api/gestion/sequences/period.prepare')['instance']['id'], 'sans ?instance= : la courante');
        $this->assertFalse($conn->fetchOne("SELECT id FROM collections WHERE kind = 'period' AND period_key = ?", [$next['key']]), 'toujours pas créée après la lecture');

        // La période suivante, sans rien : tout est à faire, le voyage est facultatif
        $url = '/api/gestion/sequences/period.prepare?instance=' . rawurlencode($next['key']);
        $steps = fn (): array => array_column($this->get($url)['steps'], null, 'key');
        $orders = (int) array_column($steps()['purchase_order']['figures'], 'value', 'key')['orders'];
        $trips = (int) array_column($steps()['trip']['figures'], 'value', 'key')['trips'];
        $before = $this->get($url);
        $this->assertSame(['budget', 'trip', 'cash_balance', 'fixed_costs', 'purchase_order'], array_column($before['steps'], 'key'));
        $this->assertSame(['todo', $trips > 0 ? 'done' : 'optional', 'todo', 'todo', $orders > 0 ? 'done' : 'todo'], array_column($before['steps'], 'status'));
        $this->assertSame([true, false, false, false, false], array_column($before['steps'], 'blocking'));
        $this->assertSame(['in_progress', 'budget', true], [$before['status'], $before['next']['stepKey'], $before['next']['blocking']]);
        $this->assertSame(['where' => 'app', 'key' => 'periode.preparation', 'params' => ['periodId' => null, 'periodKey' => $next['key']], 'url' => null], $before['steps'][0]['screen']);
        $this->assertSame(['key' => 'preparation.validate', 'preparable' => false, 'params' => []], $before['steps'][0]['action']);
        $this->assertSame([['key' => 'planned', 'label' => 'Budget prévu', 'value' => 0, 'unit' => 'money']], $before['steps'][0]['figures']);
        $this->assertSame([$next['key'], false, null], [$before['instance']['id'], $before['instance']['current'], $before['instance']['periodId']]);

        // La donnée arrive : la période est créée, son budget validé, un voyage, un solde, un frais fixe, une commande
        $periodId = (int) $conn->fetchOne("INSERT INTO collections (id, budget_collection, start_date_collection, end_date_collection, del, nom_collection, is_closed, kind, period_key) VALUES (nextval('collections_id_seq'), 0, ?, ?, false, ?, false, 'period', ?) RETURNING id",
            [$next['start']->format('Y-m-d'), $next['end']->format('Y-m-d'), $next['name'], $next['key']]);
        $this->cleanup[] = ['DELETE FROM collections WHERE id = ?', [$periodId]];
        $conn->executeStatement("INSERT INTO gestion_budget_line (period_id, category, planned_cents, updated_at) VALUES (?, 'purchases', 250000, NOW())", [$periodId]);
        $this->cleanup[] = ['DELETE FROM gestion_budget_line WHERE period_id = ?', [$periodId]];
        $this->endOfWrite();
        $after = $steps();
        $this->assertSame(['done', 250000], [$after['budget']['status'], $after['budget']['figures'][0]['value']]);
        $this->assertSame($periodId, $after['budget']['screen']['params']['periodId']);
        $this->assertSame('ready', $this->get($url)['status'], 'plus aucune étape indispensable à faire');
        $this->assertSame('cash_balance', $this->get($url)['next']['stepKey'], 'ensuite : la première amélioration à faire');

        $conn->executeStatement("INSERT INTO gestion_trip (name, start_date, end_date, budget_total_cents, budget_scope, created_at, updated_at) VALUES (?, ?, ?, 0, 'total', NOW(), NOW())",
            ["Voyage $this->tag", $next['start']->modify('+2 days')->format('Y-m-d'), $next['start']->modify('+6 days')->format('Y-m-d')]);
        $this->cleanup[] = ['DELETE FROM gestion_trip WHERE name = ?', ["Voyage $this->tag"]];
        $this->endOfWrite();
        $this->assertSame(['done', $trips + 1], [$steps()['trip']['status'], $steps()['trip']['figures'][0]['value']]);
        foreach ([[-420, -410], [900, 910]] as $i => [$a, $b]) { // un voyage avant ou après la période ne la touche pas
            $conn->executeStatement("INSERT INTO gestion_trip (name, start_date, end_date, budget_total_cents, budget_scope, created_at, updated_at) VALUES (?, ?, ?, 0, 'total', NOW(), NOW())",
                ["Ailleurs $i $this->tag", $today->modify("$a days")->format('Y-m-d'), $today->modify("$b days")->format('Y-m-d')]);
            $this->cleanup[] = ['DELETE FROM gestion_trip WHERE name = ?', ["Ailleurs $i $this->tag"]];
        }
        $this->endOfWrite();
        $this->assertSame($trips + 1, $steps()['trip']['figures'][0]['value'], 'seuls les voyages dont les dates touchent la période comptent');

        $conn->executeStatement('INSERT INTO gestion_cash_balance (balance_date, amount_cents, created_at, updated_at) VALUES (?, 500000, NOW(), NOW())', [$today->modify('-31 days')->format('Y-m-d')]);
        $this->endOfWrite();
        $old = $steps()['cash_balance'];
        $this->assertSame(['todo', 31], [$old['status'], array_column($old['figures'], 'value', 'key')['ageDays']], 'un solde de plus de 30 jours ne suffit pas');
        $conn->executeStatement('INSERT INTO gestion_cash_balance (balance_date, amount_cents, created_at, updated_at) VALUES (?, 520000, NOW(), NOW())', [$today->modify('-30 days')->format('Y-m-d')]);
        $this->endOfWrite();
        $recent = $steps()['cash_balance'];
        $this->assertSame(['done', 30, $today->modify('-30 days')->format('Y-m-d')], [$recent['status'], array_column($recent['figures'], 'value', 'key')['ageDays'], array_column($recent['figures'], 'value', 'key')['lastBalanceDate']]);
        $this->assertStringContainsString('30', $recent['description']);

        $this->assertSame('todo', $steps()['fixed_costs']['status']);
        $conn->executeStatement("INSERT INTO gestion_cash_movement (label, category, direction, amount_cents, start_date, recurrence, fixed_cost, created_at, updated_at) VALUES ('Loyer', 'rent', 'out', 120000, ?, 'monthly', true, NOW(), NOW())", [$today->modify('-60 days')->format('Y-m-d')]);
        $this->endOfWrite();
        $fixed = $steps()['fixed_costs'];
        $this->assertSame(['done', 'money'], [$fixed['status'], $fixed['figures'][0]['unit']]);
        $this->assertGreaterThan(0, $fixed['figures'][0]['value']);

        $commandeId = (int) $conn->fetchOne("INSERT INTO commande (id, date, name, kind, budget) VALUES (nextval('commande_id_seq'), ?, ?, 'standard', 0) RETURNING id", [$next['start']->modify('+3 days')->format('Y-m-d') . ' 10:00:00', "Achat $this->tag"]);
        $this->cleanup[] = ['DELETE FROM commande WHERE id = ?', [$commandeId]];
        $repriseId = (int) $conn->fetchOne("INSERT INTO commande (id, date, name, kind, budget) VALUES (nextval('commande_id_seq'), ?, ?, 'reprise', 0) RETURNING id", [$next['start']->modify('+3 days')->format('Y-m-d') . ' 10:00:00', "Reprise $this->tag"]);
        $this->cleanup[] = ['DELETE FROM commande WHERE id = ?', [$repriseId]];
        $this->endOfWrite();
        $this->assertSame(['done', $orders + 1], [$steps()['purchase_order']['status'], $steps()['purchase_order']['figures'][0]['value']], 'la commande de reprise ne compte pas');

        $done = $this->get($url);
        $this->assertSame(['ready', null, 0, 0, 100], [$done['status'], $done['next'], $done['progress']['blockingTodo'], $done['progress']['improvementTodo'], $done['progress']['percent']]);
        $this->assertSame($done['progress']['done'], $done['progress']['total']);

        // Noms d'instance et textes traduits
        $en = $this->get($url . '&locale=en');
        $this->assertSame(['Prepare a period', 'Target and budgets of the period'], [$en['title'], $en['steps'][0]['title']]);
        $this->assertSame(PeriodCalendar::displayNameFor($next['name'], 'period', $next['key'], $next['start'], 'en'), $en['instance']['label']);
        $this->assertSame($next['name'], $done['instance']['label']);
        $this->assertSame('Planned budget', $en['steps'][0]['figures'][0]['label']);

        // Refus
        $this->request('GET', '/api/gestion/sequences/period.prepare?instance=1999-01');
        $this->assertSame([404, 'INSTANCE_NOT_FOUND'], [$this->client->getResponse()->getStatusCode(), $this->body()['code']]);
        $this->request('GET', '/api/gestion/sequences/period.inconnue');
        $this->assertSame([404, 'SEQUENCE_NOT_FOUND'], [$this->client->getResponse()->getStatusCode(), $this->body()['code']]);
        $this->login(['ROLE_USER_INTERNET']);
        foreach (['/api/gestion/sequences', '/api/gestion/sequences/period.prepare', '/api/gestion/articles?filter=missing-cost'] as $route) {
            $this->request('GET', $route);
            $this->assertSame(403, $this->client->getResponse()->getStatusCode(), "$route : réservé à l'administrateur du site");
        }
    }

    public function testClosingTheFiscalYearFollowsTheDataAndItsCountMatchesTheList(): void
    {
        $calendar = static::getContainer()->get(PeriodCalendar::class);
        $today = $calendar->today();
        $calendar->ensurePeriods($today->modify('-830 days'));
        $this->endOfWrite();
        $month = static::getContainer()->get(GestionFiscalYearService::class)->startMonth();
        $year = GestionFiscalYearService::startYearOf($today, $month) - 1;
        [$from, $to] = GestionFiscalYearService::bounds($year, $month);
        $conn = $this->em()->getConnection();

        $sequence = array_column($this->get('/api/gestion/sequences')['sequences'], null, 'key')['fiscal_year.close'];
        $this->assertSame([['id' => (string) $year, 'label' => GestionFiscalYearService::label($year, $month), 'from' => $from, 'to' => $to, 'current' => true]],
            array_map(fn (array $i) => array_intersect_key($i, array_flip(['id', 'label', 'from', 'to', 'current'])), $sequence['instances']), 'le dernier exercice terminé');
        $steps = fn (): array => array_column($this->get('/api/gestion/sequences/fiscal_year.close')['steps'], null, 'key');
        $figure = fn (array $step, string $key) => array_column($step['figures'], 'value', 'key')[$key] ?? null;
        $first = $steps();
        $this->assertSame(['periods_closed', 'purchase_costs', 'reconciliation', 'fixed_costs'], array_keys($first));
        $this->assertSame([true, true, false, false], array_column($first, 'blocking'));

        // Périodes de l'exercice : le constat suit le calendrier, sans rien figer
        $periods = $this->em()->createQuery('SELECT c FROM ' . Collections::class . ' c WHERE c.kind = :k AND (c.del = false OR c.del IS NULL) AND c.startDateCollection >= :f AND c.startDateCollection <= :t')
            ->setParameter('k', 'period')->setParameter('f', $from)->setParameter('t', $to)->getResult();
        $closedCount = count(array_filter($periods, fn (Collections $c) => static::getContainer()->get(PeriodCalendar::class)->isClosed($c)));
        $this->assertGreaterThan(0, count($periods));
        $this->assertSame([count($periods), $closedCount, $closedCount === count($periods) ? 'done' : 'todo'], [$figure($first['periods_closed'], 'periods'), $figure($first['periods_closed'], 'closedPeriods'), $first['periods_closed']['status']]);

        // Une période rouverte : l'étape repasse à « à faire », avec le compte
        $autoClosed = array_values(array_filter($periods, fn (Collections $c) => $c->getClosedAt() === null && static::getContainer()->get(PeriodCalendar::class)->isClosed($c)));
        $this->assertNotEmpty($autoClosed, 'une période de l\'exercice fermée par sa date');
        $reopenedId = (int) $autoClosed[0]->getId();
        $conn->executeStatement('UPDATE collections SET reopened_at = NOW() WHERE id = ?', [$reopenedId]);
        $this->cleanup[] = ['UPDATE collections SET reopened_at = NULL WHERE id = ?', [$reopenedId]];
        $this->endOfWrite();
        $reopened = $steps()['periods_closed'];
        $this->assertSame(['todo', $closedCount - 1, count($periods)], [$reopened['status'], $figure($reopened, 'closedPeriods'), $figure($reopened, 'periods')]);
        $this->assertSame(['in_progress', 'periods_closed'], [$this->get('/api/gestion/sequences/fiscal_year.close')['status'], $this->get('/api/gestion/sequences/fiscal_year.close')['next']['stepKey']]);
        $conn->executeStatement('UPDATE collections SET reopened_at = NULL WHERE id = ?', [$reopenedId]);
        $this->endOfWrite();

        // Coûts d'achat : un article vendu pendant l'exercice sans coût fait passer l'étape à « à faire » ; le nombre égale la liste ouverte
        $missing = (int) $figure($first['purchase_costs'], 'missing');
        $total = (int) $figure($first['purchase_costs'], 'total');
        $soldAt = (new \DateTimeImmutable($from . ' 12:00:00', $today->getTimezone()))->modify('+40 days');
        $withoutCost = $this->product('sans-cout', null);
        $withCost = $this->product('avec-cout', 450);
        $neverSold = $this->product('jamais-vendu', null);
        foreach ([$withoutCost, $withCost] as [, $variantId]) {
            $this->item($this->order(1, 3, 2, $soldAt), $variantId, 2, 3000);
        }
        $this->endOfWrite();
        $costs = $steps()['purchase_costs'];
        $this->assertSame(['todo', $missing + 1, $total + 2], [$costs['status'], $figure($costs, 'missing'), $figure($costs, 'total')], 'seuls les articles vendus dans l\'exercice comptent');
        $this->assertSame(['where' => 'app', 'key' => 'articles', 'params' => ['year' => $year, 'filter' => 'missing-cost', 'from' => $from, 'to' => $to], 'url' => null], $costs['screen']);
        $this->assertStringContainsString((string) ($missing + 1), $costs['description']);
        $listed = $this->articles($costs['screen']['params']);
        $this->assertSame($missing + 1, $listed['total'], 'le nombre de l\'étape et la liste disent la même chose');
        $this->assertCount($missing + 1, $listed['items']);
        $this->assertContains($withoutCost[0], array_column($listed['items'], 'id'));
        $this->assertNotContains($withCost[0], array_column($listed['items'], 'id'));
        $this->assertNotContains($neverSold[0], array_column($listed['items'], 'id'), 'jamais vendu : hors du créneau');
        $mine = array_values(array_filter($listed['items'], fn (array $i) => $i['id'] === $withoutCost[0]))[0];
        $this->assertSame(['id' => $withoutCost[0], 'name' => "sans-cout $this->tag", 'code' => "C-sans-cout-$this->tag", 'image' => null, 'price' => 3000,
            'stock' => (int) $conn->fetchOne('SELECT quantity FROM product WHERE id = ?', [$withoutCost[0]]), 'commandeId' => null, 'periodId' => null, 'costSource' => null], $mine);
        // Le coût arrive, sur la fiche ou par une ligne d'achat chiffrée : l'article sort du compte et de la liste
        $conn->executeStatement('UPDATE product SET purchase_price = 900 WHERE id = ?', [$withoutCost[0]]);
        $this->endOfWrite();
        $this->assertSame($missing, $figure($steps()['purchase_costs'], 'missing'));
        $this->assertSame($missing, $this->articles($costs['screen']['params'])['total']);

        // Rapprochement : un solde dans chaque mois de l'exercice, sans écart resté ouvert
        $this->assertSame(['todo', 0, 12, 0], [$first['reconciliation']['status'], $figure($first['reconciliation'], 'monthsWithBalance'), $figure($first['reconciliation'], 'months'), $figure($first['reconciliation'], 'openGaps')]);
        $start = new \DateTimeImmutable($from);
        for ($m = 0; $m < 11; ++$m) {
            $conn->executeStatement("INSERT INTO gestion_cash_balance (balance_date, amount_cents, gap_decision, created_at, updated_at) VALUES (?, ?, 'explained', NOW(), NOW())", [$start->modify("+$m months")->modify('+10 days')->format('Y-m-d'), 100000 + $m * 50000]);
        }
        $this->endOfWrite();
        $this->assertSame(['todo', 11, 0], [$steps()['reconciliation']['status'], $figure($steps()['reconciliation'], 'monthsWithBalance'), $figure($steps()['reconciliation'], 'openGaps')], 'il manque un mois');
        $conn->executeStatement('INSERT INTO gestion_cash_balance (balance_date, amount_cents, created_at, updated_at) VALUES (?, 9900000, NOW(), NOW())', [$start->modify('+11 months')->modify('+10 days')->format('Y-m-d')]);
        $this->endOfWrite();
        $this->assertSame(['todo', 12, 1], [$steps()['reconciliation']['status'], $figure($steps()['reconciliation'], 'monthsWithBalance'), $figure($steps()['reconciliation'], 'openGaps')], 'un écart resté ouvert');
        $conn->executeStatement("UPDATE gestion_cash_balance SET gap_decision = 'unexplained' WHERE amount_cents = 9900000");
        $this->endOfWrite();
        $this->assertSame(['done', 12, 0], [$steps()['reconciliation']['status'], $figure($steps()['reconciliation'], 'monthsWithBalance'), $figure($steps()['reconciliation'], 'openGaps')]);

        // Frais fixes de l'exercice
        $this->assertSame('todo', $first['fixed_costs']['status']);
        $conn->executeStatement("INSERT INTO gestion_cash_movement (label, category, direction, amount_cents, start_date, recurrence, fixed_cost, created_at, updated_at) VALUES ('Loyer', 'rent', 'out', 120000, ?, 'monthly', true, NOW(), NOW())", [$from]);
        $this->endOfWrite();
        $this->assertSame('done', $steps()['fixed_costs']['status']);

        $en = $this->get('/api/gestion/sequences/fiscal_year.close?instance=' . $year . '&locale=en');
        $this->assertSame(['Close the fiscal year', GestionFiscalYearService::label($year, $month)], [$en['title'], $en['instance']['label']]);
        $this->request('GET', '/api/gestion/sequences/fiscal_year.close?instance=' . ($year - 5));
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testHistoryRecoveryFollowsTheDataAndItsCountMatchesTheList(): void
    {
        $calendar = static::getContainer()->get(PeriodCalendar::class);
        $calendar->ensurePeriods($calendar->today()->modify('-830 days'));
        $this->endOfWrite();
        $facts = static::getContainer()->get(SequenceFacts::class);
        $this->assertNull($facts->reprise(), 'aucune reprise laissée par un autre test');
        // Une vente d'un article sans coût, il y a 400 jours : une vente antérieure à tout achat saisi de ce test
        [$productId, $variantId] = $this->product('historique', null);
        $this->item($this->order(1, 3, 2, $calendar->today()->modify('-400 days')->setTime(12, 0)), $variantId, 3, 3000);
        $this->endOfWrite();
        $firstSale = static::getContainer()->get(SequenceFacts::class)->firstSaleDay();
        $firstPurchase = static::getContainer()->get(SequenceFacts::class)->firstPurchaseDay();
        $needed = $firstPurchase === null || $firstSale < $firstPurchase;
        $eve = PeriodCalendar::definition($calendar->today(), $calendar->frequency())['start']->modify('-1 day')->format('Y-m-d');

        $read = fn (): array => $this->get('/api/gestion/sequences/history.recovery');
        $figure = fn (array $step, string $key) => array_column($step['figures'], 'value', 'key')[$key] ?? null;
        $before = $read();
        $this->assertSame(['site', "Historique du site", true, $firstSale, $eve], [$before['instance']['id'], $before['instance']['label'], $before['instance']['current'], $before['instance']['from'], $before['instance']['to']]);
        $this->assertSame(['needed', 'applied', 'purchase_costs'], array_column($before['steps'], 'key'));
        if (!$needed) {
            $this->assertSame(['not_applicable', ['not_applicable', 'not_applicable', 'not_applicable'], null], [$before['status'], array_column($before['steps'], 'status'), $before['next']], 'aucune vente avant le premier achat saisi : sans objet');
        } else {
            $this->assertSame(['in_progress', 'done', 'todo', 'applied'], [$before['status'], $before['steps'][0]['status'], $before['steps'][1]['status'], $before['next']['stepKey']]);
            $this->assertSame($firstSale, $figure($before['steps'][0], 'firstSaleDate'));
            $missing = (int) $figure($before['steps'][2], 'missing');
            $this->assertGreaterThanOrEqual(1, $missing);
            $this->assertSame('todo', $before['steps'][2]['status']);
            $this->assertSame(['filter' => 'missing-cost', 'from' => $firstSale, 'to' => $eve], $before['steps'][2]['screen']['params']);
            $listed = $this->articles($before['steps'][2]['screen']['params']);
            $this->assertSame($missing, $listed['total'], 'le nombre de l\'étape et la liste disent la même chose');
            $this->assertContains($productId, array_column($listed['items'], 'id'));
        }

        // Reprise appliquée : la séquence a un objet, l'étape est faite, le créneau devient celui de la reprise
        $this->repriseApplied = true;
        $this->post('/api/gestion/reprise/appliquer', ['months' => 20, 'estimateCoefficient' => 2], 201);
        $state = $this->get('/api/gestion/reprise');
        $applied = $read();
        $this->assertSame(['done', 'done'], [$applied['steps'][0]['status'], $applied['steps'][1]['status']]);
        $this->assertSame([20, $state['from'], $state['to']], [$figure($applied['steps'][1], 'months'), $figure($applied['steps'][1], 'from'), $figure($applied['steps'][1], 'to')]);
        $this->assertSame([$state['from'], $state['to']], [$applied['instance']['from'], $applied['instance']['to']]);
        $this->assertStringContainsString('20', $applied['steps'][1]['description']);
        $missing = (int) $figure($applied['steps'][2], 'missing');
        $this->assertSame($missing === 0 ? 'done' : 'todo', $applied['steps'][2]['status']);
        $listed = $this->articles($applied['steps'][2]['screen']['params']);
        $this->assertSame($missing, $listed['total'], 'le nombre de l\'étape et la liste disent la même chose, sur le créneau de la reprise');
        $this->assertNotContains($productId, array_column($listed['items'], 'id'), 'la reprise a donné un coût à l\'article');
        $this->assertSame('ready', $applied['status'], 'plus aucune étape indispensable à faire');
        $this->assertSame('History recovery', $this->get('/api/gestion/sequences/history.recovery?locale=en')['title']);

        $this->post('/api/gestion/reprise/annuler', [], 200);
        $this->repriseApplied = false;
        $this->assertSame($needed ? 'todo' : 'not_applicable', $read()['steps'][1]['status'], 'reprise annulée : l\'étape redevient à faire');

        // Sans objet : un achat saisi avant la toute première vente, aucune reprise ; toutes les étapes le disent, plus rien à faire ensuite
        $conn = $this->em()->getConnection();
        $oldId = (int) $conn->fetchOne("INSERT INTO commande (id, date, name, kind, budget) VALUES (nextval('commande_id_seq'), '2001-01-01 10:00:00', ?, 'standard', 0) RETURNING id", ["Ancien achat $this->tag"]);
        $this->cleanup[] = ['DELETE FROM commande WHERE id = ?', [$oldId]];
        $this->endOfWrite();
        $none = $read();
        $this->assertSame(['not_applicable', ['not_applicable', 'not_applicable', 'not_applicable'], null, 0], [$none['status'], array_column($none['steps'], 'status'), $none['next'], $none['progress']['total']]);
        $this->assertSame('2001-01-01', $figure($none['steps'][0], 'firstPurchaseDate'));
        $listed = array_column($this->get('/api/gestion/sequences')['sequences'], null, 'key')['history.recovery'];
        $this->assertSame(['not_applicable', null], [$listed['status'], $listed['next']]);
    }

    public function testTheGlobalSummaryAddsUpTheSequencesAndNamesTheNextThingToDo(): void
    {
        $list = $this->get('/api/gestion/sequences');
        $this->assertSame(['summary', 'sequences'], array_keys($list));
        $this->assertSame(['blockingTodo', 'improvementTodo', 'sequencesInProgress', 'next'], array_keys($list['summary']));
        $this->assertSame(array_sum(array_map(fn (array $s) => $s['progress']['blockingTodo'], $list['sequences'])), $list['summary']['blockingTodo'], 'somme des séquences, instance courante pour celles qui reviennent');
        $this->assertSame(array_sum(array_map(fn (array $s) => $s['progress']['improvementTodo'], $list['sequences'])), $list['summary']['improvementTodo']);
        $this->assertSame(count(array_filter($list['sequences'], fn (array $s) => $s['status'] === 'in_progress')), $list['summary']['sequencesInProgress']);
        $this->assertGreaterThan(0, $list['summary']['blockingTodo'], 'le site de test a des étapes indispensables à faire');
        // La prochaine chose à faire : la première étape indispensable, dans l'ordre des séquences (à faire une fois d'abord)
        $first = array_values(array_filter($list['sequences'], fn (array $s) => ($s['next']['blocking'] ?? false) === true))[0];
        $this->assertSame(['sequenceKey' => $first['key'], 'instance' => null] + $first['next'], $list['summary']['next']);
        $this->assertSame('once', $first['kind']);
        $this->assertSame(['sequenceKey', 'instance', 'stepKey', 'title', 'blocking', 'screen', 'action'], array_keys($list['summary']['next']));
        $kinds = array_column($list['sequences'], 'kind');
        $this->assertSame($kinds, array_merge(array_fill(0, count(array_keys($kinds, 'once')), 'once'), array_fill(0, 3, 'recurring')), 'les séquences à faire une fois d\'abord, puis celles qui reviennent');
        $this->assertSame(['period.prepare', 'fiscal_year.close', 'history.recovery'], array_slice(array_column($list['sequences'], 'key'), -3));

        // La règle, sur des séquences construites : indispensable avant amélioration, quel que soit le rang ; instance courante ; null quand tout est prêt
        $step = fn (string $key, bool $blocking): array => ['stepKey' => $key, 'title' => $key, 'blocking' => $blocking, 'screen' => [], 'action' => []];
        $sequence = fn (string $key, string $status, ?array $next, array $progress, array $instances = []): array => ['key' => $key, 'status' => $status, 'next' => $next, 'instances' => $instances,
            'progress' => $progress + ['done' => 0, 'total' => 0, 'blockingTodo' => 0, 'improvementTodo' => 0, 'percent' => 0]];
        $ready = $sequence('setup.a', 'ready', null, []);
        $improvement = $sequence('setup.b', 'ready', $step('b1', false), ['improvementTodo' => 2]);
        $blocking = $sequence('period.prepare', 'in_progress', $step('budget', true), ['blockingTodo' => 1, 'improvementTodo' => 3], [['id' => '2026-q3', 'current' => false], ['id' => '2026-q4', 'current' => true]]);
        $summary = GestionSequenceService::summarize([$ready, $improvement, $blocking]);
        $this->assertSame([1, 5, 1], [$summary['blockingTodo'], $summary['improvementTodo'], $summary['sequencesInProgress']]);
        $this->assertSame(['period.prepare', '2026-q4', 'budget', true], [$summary['next']['sequenceKey'], $summary['next']['instance'], $summary['next']['stepKey'], $summary['next']['blocking']], 'une étape indispensable passe avant une amélioration d\'une séquence placée avant');
        $summary = GestionSequenceService::summarize([$ready, $improvement, $sequence('setup.c', 'ready', $step('c1', false), ['improvementTodo' => 1])]);
        $this->assertSame(['setup.b', null, 'b1', false], [$summary['next']['sequenceKey'], $summary['next']['instance'], $summary['next']['stepKey'], $summary['next']['blocking']], 'sinon la première amélioration');
        $this->assertSame(['blockingTodo' => 0, 'improvementTodo' => 0, 'sequencesInProgress' => 0, 'next' => null], GestionSequenceService::summarize([$ready, $sequence('setup.d', 'not_applicable', null, [])]), 'tout est prêt : rien à faire ensuite');
    }

    public function testTheArticlesListValidatesItsQuery(): void
    {
        [$a] = $this->product('alpha', null);
        [$b] = $this->product('beta', null);
        $this->endOfWrite();
        foreach (['' => 'filter', '?filter=stock-bas' => 'filter', '?filter=missing-cost&from=2026-01-01' => 'to', '?filter=missing-cost&to=2026-01-31' => 'from',
            '?filter=missing-cost&from=2026-02-30&to=2026-03-01' => 'from', '?filter=missing-cost&from=janvier&to=2026-03-01' => 'from', '?filter=missing-cost&from=2026-03-02&to=2026-03-01' => 'to'] as $query => $field) {
            $this->request('GET', '/api/gestion/articles' . $query);
            $this->assertSame([422, 'VALIDATION_FAILED'], [$this->client->getResponse()->getStatusCode(), $this->body()['code']], $query);
            $this->assertArrayHasKey($field, $this->body()['fields'] ?? [], $query . ' : ' . json_encode($this->body()));
        }
        // Recherche et pagination, convention des listes de la gestion
        $found = $this->articles(['filter' => 'missing-cost', 'q' => strtoupper($this->tag)]);
        $this->assertSame([2, [$a, $b]], [$found['total'], array_column($found['items'], 'id')], 'par nom, sans casse');
        $this->request('GET', '/api/gestion/articles?filter=missing-cost&q=' . $this->tag . '&page=2&limit=1');
        $headers = $this->client->getResponse()->headers;
        $this->assertSame(['2', '2', '1', [$b]], [$headers->get('X-Total-Count'), $headers->get('X-Page'), $headers->get('X-Limit'), array_column($this->body(), 'id')]);
        $byCode = $this->articles(['filter' => 'missing-cost', 'q' => "C-beta-$this->tag"]);
        $this->assertSame([$b], array_column($byCode['items'], 'id'));
        // Article rattaché à une commande (sans coût chiffré) : la ligne rend la commande et sa période, pour l'ouvrir sans lire un tableau de bord
        $conn = $this->em()->getConnection();
        $periodId = (int) static::getContainer()->get(PeriodCalendar::class)->current()->getId();
        $attachedTo = (int) $conn->fetchOne("INSERT INTO commande (id, date, name, kind, budget, collections_id) VALUES (nextval('commande_id_seq'), NOW(), ?, 'standard', 0, ?) RETURNING id", ["Rattachée $this->tag", $periodId]);
        $this->cleanup[] = ['DELETE FROM commande WHERE id = ?', [$attachedTo]];
        $loose = (int) $conn->fetchOne("INSERT INTO commande (id, date, name, kind, budget) VALUES (nextval('commande_id_seq'), NOW(), ?, 'standard', 0) RETURNING id", ["Sans période $this->tag"]);
        $this->cleanup[] = ['DELETE FROM commande WHERE id = ?', [$loose]];
        $conn->executeStatement('UPDATE product SET commande_id = ? WHERE id = ?', [$attachedTo, $a]);
        $this->endOfWrite();
        $rows = array_column($this->articles(['filter' => 'missing-cost', 'q' => $this->tag])['items'], null, 'id');
        $this->assertSame([[$attachedTo, $periodId], [null, null]], [[$rows[$a]['commandeId'], $rows[$a]['periodId']], [$rows[$b]['commandeId'], $rows[$b]['periodId']]]);
        $conn->executeStatement('UPDATE product SET commande_id = ? WHERE id = ?', [$loose, $a]);
        $this->endOfWrite();
        $row = array_column($this->articles(['filter' => 'missing-cost', 'q' => $this->tag])['items'], null, 'id')[$a];
        $this->assertSame([$loose, null], [$row['commandeId'], $row['periodId']], 'commande sans période : periodId null');
        $conn->executeStatement('UPDATE product SET commande_id = NULL WHERE id = ?', [$a]);
        $this->endOfWrite();

        // Une ligne d'achat chiffrée vaut un coût
        $conn = $this->em()->getConnection();
        $commandeId = (int) $conn->fetchOne("INSERT INTO commande (id, date, name, kind, budget) VALUES (nextval('commande_id_seq'), NOW(), ?, 'standard', 0) RETURNING id", ["Achat $this->tag"]);
        $this->cleanup[] = ['DELETE FROM commande WHERE id = ?', [$commandeId]];
        $conn->executeStatement("INSERT INTO commande_line (commande_id, product_id, quantity_ordered, quantity_received, unit_cost_cents, estimated, attached_at, source, created_at, updated_at) VALUES (?, ?, 1, 0, 700, false, NOW(), 'manual', NOW(), NOW())", [$commandeId, $a]);
        $this->cleanup[] = ['DELETE FROM commande_line WHERE commande_id = ?', [$commandeId]];
        $this->endOfWrite();
        $this->assertSame([$b], array_column($this->articles(['filter' => 'missing-cost', 'q' => $this->tag])['items'], 'id'));
    }

    public function testEveryStepOfTheCatalogueHasACriterionAScreenAnActionAndItsTexts(): void
    {
        $translator = static::getContainer()->get(TranslatorInterface::class);
        $has = fn (string $id, string $locale): bool => $translator->trans($id, [], 'gestion', $locale) !== $id;
        foreach (SequenceCatalog::RECURRING as $key => $sequence) {
            foreach (['title', 'description'] as $text) {
                $this->assertTrue($has("sequences.$key.$text", 'fr') && $has("sequences.$key.$text", 'en'), "$key : $text dans les deux langues");
            }
            $this->assertNotEmpty($sequence['steps']);
            foreach ($sequence['steps'] as $stepKey => $step) {
                $this->assertTrue(method_exists(SequenceFacts::class, $step['fact']), "$key.$stepKey : critère « {$step['fact']} » absent de SequenceFacts");
                $this->assertContains($step['screen'], SequenceCatalog::APP_SCREENS, "$key.$stepKey : écran inconnu");
                $this->assertMatchesRegularExpression('/^[a-z_]+(\.[a-z_]+)+$/', $step['action'], "$key.$stepKey : référence d'action");
                $this->assertIsBool($step['blocking']);
                $this->assertFalse(($step['optional'] ?? false) && $step['blocking'], "$key.$stepKey : une étape facultative n'est pas indispensable");
                foreach (['title', 'done', 'todo'] as $text) {
                    $this->assertTrue($has("sequences.$key.steps.$stepKey.$text", 'fr') && $has("sequences.$key.steps.$stepKey.$text", 'en'), "$key.$stepKey : texte « $text » dans les deux langues");
                }
            }
        }
        // Les étapes du cheminement citées par le catalogue existent, et chaque groupe a une clé de séquence distincte
        $readiness = $this->get('/api/site/readiness');
        $known = array_column($readiness['steps'], 'key');
        foreach (array_keys(SequenceCatalog::ONCE_ACTIONS + SequenceCatalog::ONCE_SCREEN_PARAMS) as $stepKey) {
            $this->assertContains($stepKey, $known, "$stepKey : étape absente du cheminement");
        }
        $keys = array_map(fn (array $g) => SequenceCatalog::onceKey($g['key']), $readiness['groups']);
        $this->assertSame($keys, array_unique($keys));
        // Chaque chiffre rendu a son libellé dans les deux langues
        foreach ($this->get('/api/gestion/sequences')['sequences'] as $sequence) {
            foreach ($this->get('/api/gestion/sequences/' . $sequence['key'])['steps'] as $step) {
                foreach ($step['figures'] as $figure) {
                    $this->assertTrue($has('sequences.figures.' . $figure['key'], 'fr') && $has('sequences.figures.' . $figure['key'], 'en'), "{$sequence['key']} {$step['key']} : libellé du chiffre « {$figure['key']} »");
                    $this->assertContains($figure['unit'], ['money', 'percent', 'count', 'days', 'date', 'ratio', 'text']);
                }
            }
        }
    }

    // ----- utilitaires -----

    /** @return array{0: int, 1: int} article standard et sa variante ; retirés à la fin du test */
    private function product(string $name, ?int $purchasePrice): array
    {
        $conn = $this->em()->getConnection();
        $slug = "$name-$this->tag";
        $conn->executeStatement("INSERT INTO product (id, name, price, isbestseller, quantity, created_at, slug, product_type, purchase_price, code) VALUES (nextval('product_id_seq'), :n, 3000, false, 5, NOW(), :s, 'standard', :pp, :c)",
            ['n' => "$name $this->tag", 's' => $slug, 'pp' => $purchasePrice, 'c' => "C-$slug"]);
        $productId = (int) $conn->fetchOne('SELECT id FROM product WHERE slug = ?', [$slug]);
        $variantId = (int) $this->create(ProductVariant::class, ['product' => $this->em()->find(Product::class, $productId)])->getId();
        $this->cleanup[] = ['DELETE FROM product WHERE id = ?', [$productId]];
        $this->cleanup[] = ['DELETE FROM product_variant WHERE id = ?', [$variantId]];
        $this->cleanup[] = ['DELETE FROM order_items WHERE product_variant_id = ?', [$variantId]];
        $this->cleanup[] = ['DELETE FROM commande_line WHERE product_id = ?', [$productId]];
        $this->cleanup[] = ['UPDATE product SET commande_id = NULL WHERE id = ?', [$productId]];

        return [$productId, $variantId];
    }

    /** @param array<string, mixed> $params paramètres de l'écran d'une étape, repassés tels quels @return array{total: int, items: list<array<string, mixed>>} */
    private function articles(array $params): array
    {
        unset($params['year']);
        $this->request('GET', '/api/gestion/articles?' . http_build_query($params));
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), substr((string) $this->client->getResponse()->getContent(), 0, 300));

        return ['total' => (int) $this->client->getResponse()->headers->get('X-Total-Count'), 'items' => $this->body()];
    }

    /** @return array<string, int> nombre de lignes de chaque table de la base du site et de la base maître */
    private function rowCounts(): array
    {
        $counts = [];
        $conn = $this->em()->getConnection();
        foreach ($conn->fetchFirstColumn("SELECT tablename FROM pg_tables WHERE schemaname = 'public' ORDER BY 1") as $table) {
            $counts["site.$table"] = (int) $conn->fetchOne('SELECT COUNT(*) FROM "' . $table . '"');
        }
        $master = static::getContainer()->get(TenantConnectionManager::class)->getPdoMaster();
        foreach ($master->query("SELECT tablename FROM pg_tables WHERE schemaname = 'public' ORDER BY 1")->fetchAll(\PDO::FETCH_COLUMN) as $table) {
            $counts["master.$table"] = (int) $master->query('SELECT COUNT(*) FROM "' . $table . '"')->fetchColumn();
        }

        return $counts;
    }

    private function order(int $type, int $status, int $source, \DateTimeImmutable $at): Order
    {
        $order = $this->create(Order::class, ['totalAmount' => 1.0], function (Order $o, EntityManagerInterface $em) use ($type, $status, $source, $at): void {
            $o->setOrderDate(\DateTime::createFromInterface($at))->setStatus($em->find(StatusCommande::class, $status))->setOrderType($em->find(OrderType::class, $type))
                ->setOrderSource($em->find(OrderSource::class, $source))->setUserId($em->find(User::class, $this->userId));
        });
        $this->cleanup[] = ['DELETE FROM "order" WHERE id = ?', [$order->getId()]];
        $this->cleanup[] = ['DELETE FROM order_items WHERE order_associated_id = ?', [$order->getId()]];

        return $order;
    }

    private function item(Order $order, int $variantId, int $quantity, int $unitPrice): void
    {
        $this->create(OrderItems::class, ['quantity' => $quantity, 'unitPrice' => (float) $unitPrice, 'totalPrice' => (float) ($unitPrice * $quantity)], function (OrderItems $i, EntityManagerInterface $em) use ($order, $variantId): void {
            $i->setOrderAssociated($em->find(Order::class, $order->getId()))->setProductVariant($em->find(ProductVariant::class, $variantId));
        });
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
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), $route . ' : ' . substr((string) $this->client->getResponse()->getContent(), 0, 300));

        return $this->body();
    }

    private function post(string $route, array $body, int $expected): array
    {
        $this->request('POST', $route, $body);
        $this->assertSame($expected, $this->client->getResponse()->getStatusCode(), $route . ' : ' . substr((string) $this->client->getResponse()->getContent(), 0, 300));

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

    private function login(array $roles): void
    {
        $email = 'gestion-seq-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $em = $this->em();
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Gestion')->setLastname('Seq')->setRoles($roles)->setIsVerified(true)->setPassword('inutilisable');
        $em->persist($user);
        $em->flush();
        $this->userId = (int) $user->getId();
        $this->token = static::getContainer()->get('lexik_jwt_authentication.jwt_manager')->create($user);
        $this->endOfWrite();
    }
}
