<?php

namespace App\Tests\Functional\LossPrevention;

use App\LossPrevention\Command\LossAnalyseCommand;
use App\LossPrevention\Services\LossAnalysisContext;
use App\LossPrevention\Services\LossAnalysisService;
use App\LossPrevention\Services\Rule\CashShortageSeriesRule;
use App\Services\TenantConnectionManager;
use Doctrine\DBAL\DriverManager;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Prévention des pertes, lot L1-a : le moteur d'analyse aux limites (relecture du 14/11/2026). Curseur arrêté à la dernière entrée lue quand
 * le lot est plein ; seuils au cent près ; règle par personne au cran près et sans confusion de fenêtres au rejeu ; annulations hors du champ
 * de la règle ; verrou ; reprise ; analyse à la lecture de la synthèse ; filtres et comptes ; une règle en échec n'est jamais silencieuse ; un
 * site en erreur n'arrête pas les autres.
 */
class LossPreventionAnalysisTest extends LossPreventionTestCase
{
    public function testAFullBatchStopsTheCursorAtTheLastEntryRead(): void
    {
        $this->drain();
        $this->loginPos(['ROLE_USER_POS']);
        $reports = [];
        foreach ([-1100, -1300, -1500] as $delta) {
            $this->openSession();
            $reports[] = $this->closeWith($delta, 'Lot plein')['id'];
        }
        $found = fn () => array_map(fn (int $id) => (int) $this->db->fetchOne('SELECT COUNT(*) FROM loss_finding WHERE dedup_key = ?', ['CASH_DISCREPANCY:z_report:' . $id]), $reports);
        $analysis = static::getContainer()->get(LossAnalysisService::class);
        $this->tenant();
        // Un passage d'une seule entrée : le lot est plein, le curseur reste sur cette entrée, les deux autres attendent
        $first = $analysis->run(1);
        $this->assertSame(['done', 1, 1, true], [$first['status'], $first['read'], $first['opened'], $first['pending']]);
        $this->assertSame([1, 0, 0], $found(), 'lot plein : rien de ce qui suit n\'est perdu ni sauté');
        $this->assertTrue($analysis->state()['pending']);
        $second = $analysis->run(1);
        $this->assertSame([1, 1, 0], $found());
        $this->assertTrue($second['pending']);
        $third = $analysis->run(1);
        $this->assertSame([1, 1, 1], $found());
        $last = $analysis->run(1);
        $this->assertSame([0, 0, false], [$last['read'], $last['opened'], $last['pending']], 'lot non plein : le curseur rejoint la fin du journal');
        $this->assertFalse($analysis->state()['pending']);
        $this->assertSame(0, $first['skipped'] + $second['skipped'] + $third['skipped'] + $last['skipped']);
    }

    public function testDiscrepancyThresholdsToTheCentAndAClosingWithoutCountOpensNothing(): void
    {
        $this->drain();
        $this->loginPos(['ROLE_USER_POS']);
        $opened = fn () => $this->json($this->admin('POST', self::BASE . '/analyse'))['opened'];
        // Plancher de 500 cents : égalité = rien ; un cent de plus = constat ; même règle pour un excédent
        foreach ([[-500, 0], [-501, 1], [500, 0], [501, 1]] as [$delta, $expected]) {
            $this->openSession();
            $this->closeWith($delta, 'Seuil');
            $this->assertSame($expected, $opened(), "écart de $delta cents");
        }
        // Écart toléré du site au-dessus du plancher : c'est lui qui compte
        $this->db->executeStatement('UPDATE entreprise SET pos_discrepancy_threshold = 2000');
        $this->openSession();
        $this->closeWith(-2000);
        $this->assertSame(0, $opened(), 'égal à l\'écart toléré du site');
        $this->openSession();
        $beyond = $this->closeWith(-2001, 'Au-delà du toléré');
        $this->assertSame(1, $opened());
        $this->assertSame(2000, (int) json_decode((string) $this->db->fetchOne('SELECT facts FROM loss_finding WHERE dedup_key = ?', ['CASH_DISCREPANCY:z_report:' . $beyond['id']]), true)['threshold']);
        $this->db->executeStatement('UPDATE entreprise SET pos_discrepancy_threshold = 0');
        // Session sans heure d'ouverture (donnée ancienne) : le constat d'écart naît quand même, sa fenêtre se réduit à la fermeture ; et la règle
        // du tiroir relit la session sans la borne de l'ouverture
        $session = $this->openSession();
        foreach (range(1, 4) as $i) {
            $this->pos('POST', '/api/caisse/drawer-open', ['cashRegisterId' => $this->register, 'reason' => 'Ouverture nulle ' . $i]);
        }
        $noOpening = $this->closeWith(-1100, 'Session sans heure d\'ouverture');
        $this->db->executeStatement('UPDATE caisse SET created_at = NULL WHERE id = ?', [$session]);
        $run = $this->json($this->admin('POST', self::BASE . '/analyse'));
        $this->assertSame([2, 0], [$run['opened'], $run['skipped']], json_encode($run));
        $window = $this->db->fetchAssociative('SELECT occurred_from, occurred_to FROM loss_finding WHERE dedup_key = ?', ['CASH_DISCREPANCY:z_report:' . $noOpening['id']]);
        $this->assertSame($window['occurred_to'], $window['occurred_from']);
        $this->assertSame(4, (int) $this->db->fetchOne('SELECT COUNT(*) FROM loss_evidence e JOIN loss_finding f ON f.id = e.finding_id WHERE f.dedup_key = ?', ['DRAWER_NO_SALE:session:' . $session]));
        // Clôture sans comptage : pas d'écart, pas de constat
        $this->openSession();
        $report = $this->json($this->pos('POST', '/api/caisse/close', ['cashRegisterId' => $this->register]))['report'];
        $this->assertNull($report['discrepancy']);
        $this->assertSame(0, $opened());
    }

    public function testVoidRuleIgnoresSalesWithoutCashAndLateCancellations(): void
    {
        $this->drain();
        $this->loginPos(['ROLE_USER_POS', 'ROLE_POS_MANAGER']);
        $this->openSession();
        $key = fn (int $order) => (int) $this->db->fetchOne('SELECT COUNT(*) FROM loss_finding WHERE dedup_key = ?', ['VOID_AFTER_SALE:order:' . $order]);
        // Vente payée par carte, annulée tout de suite : hors du champ (pas d'espèces)
        $quote = $this->json($this->pos('POST', '/api/cart/quote', ['items' => [['productVariantId' => $this->cap, 'quantity' => 1]], 'cashRegisterId' => $this->register]));
        $card = $this->json($this->pos('POST', '/api/order/create-multi-payment', ['items' => [['productVariantId' => $this->cap, 'quantity' => 1]],
            'paymentMethods' => [['type' => 1, 'amount' => $quote['total'] / 100]], 'cashRegisterId' => $this->register]), 201);
        $this->json($this->pos('POST', '/api/order/cancel/' . $card['orderId'], ['paymentMethod' => 1, 'cashRegisterId' => $this->register]));
        // Vente en espèces annulée 30 minutes après : hors délai (10 minutes)
        $late = $this->sellCash();
        $this->db->executeStatement('UPDATE "order" SET order_date = order_date - INTERVAL \'30 minutes\' WHERE id = ?', [$late['orderId']]);
        $this->json($this->pos('POST', '/api/order/cancel/' . $late['orderId'], ['paymentMethod' => 2, 'cashRegisterId' => $this->register]));
        $run = $this->json($this->admin('POST', self::BASE . '/analyse'));
        $this->assertSame([0, 0], [$run['opened'], $run['skipped']], 'deux annulations, aucune dans le champ : ' . json_encode($run));
        $this->assertSame([0, 0], [$key((int) $card['orderId']), $key((int) $late['orderId'])]);
        // À la limite du délai : 10 minutes juste = constat
        $limit = $this->sellCash();
        $this->db->executeStatement('UPDATE "order" SET order_date = order_date - INTERVAL \'9 minutes 50 seconds\' WHERE id = ?', [$limit['orderId']]);
        $this->json($this->pos('POST', '/api/order/cancel/' . $limit['orderId'], ['paymentMethod' => 2, 'cashRegisterId' => $this->register]));
        $run = $this->json($this->admin('POST', self::BASE . '/analyse'));
        $this->assertSame(1, $key((int) $limit['orderId']));
        $this->assertSame(2, $run['opened'], 'l\'annulation dans le délai, et la série : c\'est la troisième annulation de la session');
        $this->closeWith(0);
    }

    public function testPersonRuleCountsToTheNotchAndAReplayNeverTouchesALaterWindow(): void
    {
        $this->drain();
        $this->place('US', 'FL');
        $persons = fn (int $userId) => $this->db->fetchAllAssociative("SELECT id, dedup_key, facts, occurred_from, occurred_to FROM loss_finding WHERE variant = 'person' AND subject_id = ? ORDER BY id", [$userId]);
        $evidence = fn (int $findingId) => (int) $this->db->fetchOne('SELECT COUNT(*) FROM loss_evidence WHERE finding_id = ?', [$findingId]);

        // Au cran près : deux manques, rien ; le troisième ouvre le constat (trois passages séparés)
        $notch = $this->loginPos(['ROLE_USER_POS']);
        foreach ([1, 2, 3] as $n) {
            $this->openSession();
            $this->closeWith(-900, 'Manque');
            $run = $this->json($this->admin('POST', self::BASE . '/analyse'));
            $this->assertSame([$n === 3 ? 2 : 1, 0], [$run['opened'], $run['skipped']], "manque $n");
            $this->assertCount($n === 3 ? 1 : 0, $persons($notch->getId()));
        }
        // Un quatrième manque complète le même constat au lieu d'en ouvrir un second
        $this->openSession();
        $this->closeWith(-900, 'Manque');
        $run = $this->json($this->admin('POST', self::BASE . '/analyse'));
        $this->assertSame([1, 1], [$run['opened'], $run['completed']]);
        $this->assertSame([1, 4], [count($persons($notch->getId())), $evidence((int) $persons($notch->getId())[0]['id'])]);

        // Né de la reprise, puis complété par un manque récent : il cesse d'être une reprise
        $this->db->executeStatement('UPDATE loss_setting SET cursor_sequence = NULL, backfill_until_sequence = NULL');
        $reprise = $this->loginPos(['ROLE_USER_POS']);
        foreach ([1, 2, 3] as $ignored) {
            $this->openSession();
            $this->closeWith(-700, 'Manque');
        }
        $this->drain();
        $this->assertSame([true], array_column($this->db->fetchAllAssociative("SELECT backfill FROM loss_finding WHERE variant = 'person' AND subject_id = ?", [$reprise->getId()]), 'backfill'));
        $this->openSession();
        $this->closeWith(-700, 'Manque');
        $this->assertSame(1, $this->json($this->admin('POST', self::BASE . '/analyse'))['completed']);
        $this->assertSame([false], array_column($this->db->fetchAllAssociative("SELECT backfill FROM loss_finding WHERE variant = 'person' AND subject_id = ?", [$reprise->getId()]), 'backfill'));

        // Deux fenêtres pour un même compte (jours 1, 15, 30 puis 100, 105, 110) : deux constats ; rejouer n'en mélange rien
        $closer = $this->loginPos(['ROLE_USER_POS']);
        foreach ([109, 95, 80, 10, 5, 0] as $daysAgo) {
            $this->openSession();
            $report = $this->closeWith(-800, 'Manque');
            $this->db->executeStatement("UPDATE cash_register_report SET closed_at = closed_at - make_interval(days => ?) WHERE id = ?", [$daysAgo, $report['id']]);
        }
        $this->drain();
        $before = $persons($closer->getId());
        $this->assertCount(2, $before, 'une fenêtre ancienne et une fenêtre récente');
        $this->assertSame([3, 3], [$evidence((int) $before[0]['id']), $evidence((int) $before[1]['id'])]);
        $this->assertSame([2400, 2400], array_map(fn ($f) => json_decode((string) $f['facts'], true)['total'], $before));
        $this->db->executeStatement('UPDATE loss_setting SET cursor_sequence = 0');
        $this->drain();
        $this->assertSame($before, $persons($closer->getId()), 'rejeu depuis le début : le constat de la fenêtre récente n\'est pas réécrit par une clôture ancienne');
        $this->assertSame([3, 3], [$evidence((int) $before[0]['id']), $evidence((int) $before[1]['id'])]);
    }

    public function testLockBackfillMarkAndAnalysisTriggeredByReadingTheSummary(): void
    {
        $this->drain();
        $this->loginPos(['ROLE_USER_POS']);
        // Un passage déjà en cours (verrou tenu par une autre connexion) : le second rend « busy » sans attendre et n'écrit rien
        $other = DriverManager::getConnection($this->db->getParams());
        $other->beginTransaction();
        $other->executeQuery('SELECT pg_advisory_xact_lock(7461077)');
        try {
            $busy = $this->json($this->admin('POST', self::BASE . '/analyse'));
            $this->assertSame(['busy', 0, 0], [$busy['status'], $busy['read'], $busy['opened']]);
        } finally {
            $other->rollBack();
            $other->close();
        }

        // Reprise : à la première analyse, les constats nés de l'historique sont marqués « reprise » et ne sont pas « nouveaux »
        $session = $this->openSession();
        foreach (range(1, 4) as $i) {
            $this->pos('POST', '/api/caisse/drawer-open', ['cashRegisterId' => $this->register, 'reason' => 'Avant la première analyse ' . $i]);
        }
        $this->db->executeStatement('UPDATE loss_setting SET cursor_sequence = NULL, backfill_until_sequence = NULL');
        $this->drain();
        $drawer = fn () => $this->db->fetchAssociative('SELECT id, backfill, seen_at FROM loss_finding WHERE dedup_key = ?', ['DRAWER_NO_SALE:session:' . $session]);
        $this->assertTrue($drawer()['backfill'], 'né de la reprise');
        // Un fait postérieur à la première analyse le complète : il cesse d'être une reprise et redevient « nouveau »
        $this->pos('POST', '/api/caisse/drawer-open', ['cashRegisterId' => $this->register, 'reason' => 'Après la première analyse']);
        $this->assertSame(1, $this->json($this->admin('POST', self::BASE . '/analyse'))['completed']);
        $this->assertSame([false, null], [$drawer()['backfill'], $drawer()['seen_at']]);
        $this->assertTrue(array_column($this->json($this->admin('GET', self::BASE . '/constats?rule=DRAWER_NO_SALE&limit=100')), 'isNew', 'id')[$drawer()['id']]);
        $old = $this->closeWith(-1300, 'Avant la première analyse');
        $this->db->executeStatement('UPDATE loss_setting SET cursor_sequence = NULL, backfill_until_sequence = NULL');
        $started = (int) $this->db->fetchOne("SELECT COUNT(*) FROM loss_journal WHERE type = 'ANALYSIS_STARTED'");
        $this->drain();
        $this->assertSame($started + 1, (int) $this->db->fetchOne("SELECT COUNT(*) FROM loss_journal WHERE type = 'ANALYSIS_STARTED'"));
        $this->openSession();
        $recent = $this->closeWith(-1400, 'Après la première analyse');
        $this->assertSame(1, $this->json($this->admin('POST', self::BASE . '/analyse'))['opened']);
        $rows = array_column($this->json($this->admin('GET', self::BASE . '/constats?rule=CASH_DISCREPANCY&limit=100')), null, 'id');
        $id = fn (array $report) => (int) $this->db->fetchOne('SELECT id FROM loss_finding WHERE dedup_key = ?', ['CASH_DISCREPANCY:z_report:' . $report['id']]);
        $this->assertSame([true, false], [$rows[$id($old)]['backfill'], $rows[$id($old)]['isNew']], 'reprise : pas « nouveau »');
        $this->assertSame([false, true], [$rows[$id($recent)]['backfill'], $rows[$id($recent)]['isNew']]);
        $today = $this->today();
        $new = array_column($this->json($this->admin('GET', self::BASE . "/constats?isNew=1&from=$today&to=$today&limit=100")), 'id');
        $this->assertContains($id($recent), $new);
        $this->assertNotContains($id($old), $new);
        $this->assertSame(count($new), $this->json($this->admin('GET', self::BASE . "?from=$today&to=$today&analyse=0"))['counts']['new'], 'le compte des nouveaux de la synthèse est celui de la liste');

        // Analyse à la lecture de la synthèse : seulement si la dernière a plus de dix minutes, et jamais avec analyse=0
        $this->openSession();
        $pending = $this->closeWith(-1600, 'À la lecture de la synthèse');
        $this->assertNull($this->json($this->admin('GET', self::BASE))['lastAnalysis']['ran'], 'analyse récente : la lecture n\'en lance pas');
        // heure calculée en UTC par PHP, pas par NOW() de la base : le test ne dépend pas du fuseau de la session PostgreSQL
        $this->db->executeStatement('UPDATE loss_setting SET last_analysis_at = ?', [(new \DateTimeImmutable('-1 hour', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s')]);
        $still = $this->json($this->admin('GET', self::BASE . '?analyse=0'));
        $this->assertSame([null, true, 0], [$still['lastAnalysis']['ran'], $still['lastAnalysis']['pending'], $id($pending)], 'analyse=0 : rien ne tourne');
        $ran = $this->json($this->admin('GET', self::BASE))['lastAnalysis'];
        $this->assertSame([1, 0, false, false], [$ran['ran']['opened'], $ran['ran']['skipped'], $ran['pending'], $ran['failed']]);
        $this->assertGreaterThan(0, $id($pending));
    }

    public function testListFiltersSortPaginationAndSummaryCounts(): void
    {
        $this->drain();
        $this->loginPos(['ROLE_USER_POS']);
        $session = $this->openSession();
        foreach (range(1, 4) as $i) {
            $this->pos('POST', '/api/caisse/drawer-open', ['cashRegisterId' => $this->register, 'reason' => 'Filtre ' . $i]);
        }
        $report = $this->closeWith(-1700, 'Filtres');
        $this->assertSame(2, $this->json($this->admin('POST', self::BASE . '/analyse'))['opened']);
        $drawer = (int) $this->db->fetchOne('SELECT id FROM loss_finding WHERE dedup_key = ?', ['DRAWER_NO_SALE:session:' . $session]);
        $cash = (int) $this->db->fetchOne('SELECT id FROM loss_finding WHERE dedup_key = ?', ['CASH_DISCREPANCY:z_report:' . $report['id']]);
        $this->json($this->admin('PUT', self::BASE . "/constats/$drawer/decision", ['status' => 'watch']));
        $this->assertNotNull($this->db->fetchOne('SELECT seen_at FROM loss_finding WHERE id = ?', [$drawer]), 'décidé sans avoir ouvert la fiche : il n\'est plus « nouveau »');
        $this->assertNotContains($drawer, array_column($this->json($this->admin('GET', self::BASE . '/constats?isNew=1&limit=100')), 'id'));
        $today = $this->today();
        $list = fn (string $query) => $this->json($this->admin('GET', self::BASE . "/constats?limit=100&from=$today&to=$today&$query"));

        $byRule = $list('rule=DRAWER_NO_SALE');
        $this->assertSame(['DRAWER_NO_SALE'], array_values(array_unique(array_map(fn ($r) => $r['rule']['code'], $byRule))));
        $this->assertContains($drawer, array_column($byRule, 'id'));
        $watch = array_column($list('status=watch'), 'status', 'id');
        $this->assertSame(['watch'], array_values(array_unique($watch)));
        $this->assertArrayHasKey($drawer, $watch);
        $this->assertArrayNotHasKey($cash, $watch);
        $both = array_column($list('status=open,watch'), 'id');
        $this->assertContains($drawer, $both);
        $this->assertContains($cash, $both);
        $this->assertSame([$this->register], array_values(array_unique(array_map(fn ($r) => $r['cashRegister']['id'], $list('cashRegisterId=' . $this->register)))));
        $none = $this->admin('GET', self::BASE . '/constats?cashRegisterId=999999');
        $this->assertSame([[], '0'], [$this->json($none), $none->headers->get('X-Total-Count')]);
        $tomorrow = (new \DateTimeImmutable($today))->modify('+1 day')->format('Y-m-d');
        $this->assertSame([], $this->json($this->admin('GET', self::BASE . "/constats?from=$tomorrow")));
        // Tri par montant : décroissant, les constats sans montant à la fin
        $amounts = array_column($list('sort=amount'), 'amountAtStake');
        $withAmount = array_values(array_filter($amounts, fn ($a) => $a !== null));
        $sorted = $withAmount;
        rsort($sorted);
        $this->assertSame($sorted, $withAmount);
        $this->assertSame([], array_filter(array_slice($amounts, count($withAmount)), fn ($a) => $a !== null), 'sans montant : à la fin');
        // Pagination : en-têtes de la gestion, pages disjointes
        $page1 = $this->admin('GET', self::BASE . "/constats?from=$today&to=$today&limit=1&page=1");
        $page2 = $this->admin('GET', self::BASE . "/constats?from=$today&to=$today&limit=1&page=2");
        $total = (int) $page1->headers->get('X-Total-Count');
        $this->assertSame(['1', '1', '2'], [$page1->headers->get('X-Limit'), $page1->headers->get('X-Page'), $page2->headers->get('X-Page')]);
        $this->assertCount(1, $this->json($page1));
        $this->assertNotSame($this->json($page1)[0]['id'], $this->json($page2)[0]['id']);
        // Synthèse : ses comptes sont ceux de la liste sur la même période
        $s = $this->json($this->admin('GET', self::BASE . "?from=$today&to=$today&analyse=0"));
        $this->assertSame($total, array_sum(array_column($s['counts']['byStatus'], 'count')));
        $this->assertSame([count($list('status=open')), count($list('status=watch'))], [$s['counts']['open'], $s['counts']['watch']]);
        $this->assertSame(array_sum(array_map(fn ($r) => (int) $r['amountAtStake'], $list('status=open,watch'))), $s['amountAtStake'], 'montant en jeu : les constats nouveaux ou à suivre');
        $byRuleCounts = array_column($s['counts']['byRule'], 'total', 'code');
        $this->assertSame(count($list('rule=DRAWER_NO_SALE')), $byRuleCounts['DRAWER_NO_SALE']);
        $this->assertSame($total, (int) array_sum(array_column($s['counts']['byRegister'], 'total')) + count(array_filter($list(''), fn ($r) => $r['cashRegister'] === null)));
    }

    public function testNothingIsAnalysedWithoutTheModuleRightEvenInThePlatformDemoMode(): void
    {
        $this->drain();
        $this->loginPos(['ROLE_USER_POS']);
        $this->openSession();
        $report = $this->closeWith(-2100, 'Sans le droit du module');
        $found = fn () => (int) $this->db->fetchOne('SELECT COUNT(*) FROM loss_finding WHERE dedup_key = ?', ['CASH_DISCREPANCY:z_report:' . $report['id']]);
        $findings = fn () => (int) $this->db->fetchOne('SELECT COUNT(*) FROM loss_finding');
        // Plateforme en mode demo (rien n'est gardé : les écrans répondent), droit du module NON accordé : la synthèse ne lance aucune analyse
        $this->flags(false, false);
        $this->db->executeStatement('UPDATE loss_setting SET last_analysis_at = ?', [(new \DateTimeImmutable('-1 hour', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s')]);
        $before = $findings();
        $s = $this->json($this->admin('GET', self::BASE));
        $this->assertSame(['MODULE_NOT_GRANTED', null, true, 'MODULE_NOT_GRANTED'], [$s['lastAnalysis']['blocked'], $s['lastAnalysis']['ran'], $s['lastAnalysis']['pending'], $s['caveats'][0]['code']]);
        $this->assertSame('MODULE_NOT_GRANTED', $this->json($this->admin('POST', self::BASE . '/analyse'), 409)['error']);
        $this->assertSame([$before, 0], [$findings(), $found()], 'sans le droit : aucun constat écrit, ni par la synthèse ni à la demande');
        $this->assertSame(200, $this->admin('GET', self::BASE . '/constats')->getStatusCode(), 'les écrans restent visibles');
        // Droit accordé : la même lecture analyse
        $this->flags(false, true);
        $ran = $this->json($this->admin('GET', self::BASE))['lastAnalysis'];
        $this->assertSame([null, 1], [$ran['blocked'], $ran['ran']['opened']]);
        $this->assertSame(1, $found());
        $this->flags(false, false);
    }

    public function testAFailingRuleIsSkippedLoudlyAndNeverCancelsWhatAnotherRuleWrote(): void
    {
        $this->drain();
        $this->place('US', 'FL');
        $this->loginPos(['ROLE_USER_POS']);
        $this->openSession();
        $this->pos('POST', '/api/caisse/drawer-open', ['cashRegisterId' => $this->register, 'reason' => 'Règle en échec']);
        $report = $this->closeWith(-1800, 'Règle en échec');
        $series = new class extends CashShortageSeriesRule {
            public function handle(array $entry, LossAnalysisContext $ctx): void
            {
                throw new \RuntimeException('variante par personne en panne');
            }
        };
        $c = static::getContainer();
        $analysis = $this->analysisWith([self::failingDrawerRule(), $series]);
        $journal = fn () => (int) $this->db->fetchOne("SELECT COUNT(*) FROM loss_journal WHERE type = 'ENTRIES_SKIPPED'");
        $before = $journal();
        $run = $analysis->run();
        // L'ouverture du tiroir et la variante par personne ont échoué ; le constat de la clôture, écrit par une autre règle, est gardé et compté
        $this->assertSame(['done', 2, 1, false], [$run['status'], $run['skipped'], $run['opened'], $run['pending']], json_encode($run));
        $this->assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM loss_finding WHERE dedup_key = ?', ['CASH_DISCREPANCY:z_report:' . $report['id']]));
        $this->assertSame($before + 1, $journal(), 'les entrées sautées sont gardées au journal du module');
        $this->assertFalse($analysis->state()['pending'], 'le curseur a avancé : ces entrées ne seront plus présentées');
        $this->assertGreaterThanOrEqual(2, $analysis->skipped()['total'], 'sur les 30 derniers jours, chaque opération une fois');
        // La synthèse le dit
        $s = $this->json($this->admin('GET', self::BASE . '?analyse=0'));
        $this->assertGreaterThanOrEqual(2, $s['lastAnalysis']['skipped']['total']);
        $this->assertSame('ENTRIES_SKIPPED', $s['caveats'][0]['code']);
        // La commande du minuteur l'affiche et sort en échec
        $this->flags(true, true);
        $this->openSession();
        $this->pos('POST', '/api/caisse/drawer-open', ['cashRegisterId' => $this->register, 'reason' => 'Règle en échec, commande']);
        $failing = $this->analysisWith([self::failingDrawerRule()]);
        // L'aperçu d'un seuil n'écrit rien : une règle en échec saute ses entrées, l'aperçu aboutit quand même et le dit
        $preview = $failing->preview('DRAWER_NO_SALE', static::getContainer()->get(\App\LossPrevention\Services\LossRuleService::class)->proposed('DRAWER_NO_SALE', ['params' => ['maxPerSession' => 0]]));
        $this->assertSame(['done', 0], [$preview['status'], $preview['wouldOpen']]);
        $this->assertGreaterThanOrEqual(1, $preview['skipped'], 'les entrées sautées par l\'aperçu sont comptées');
        $provider = $this->tenant();
        $tester = new CommandTester(new LossAnalyseCommand($c->get(TenantConnectionManager::class), $provider, $failing, new NullLogger()));
        $this->assertSame(Command::FAILURE, $tester->execute(['--tenant' => MV_TEST_TENANT_CODE]));
        $this->assertStringContainsString('1 entrée(s) sautée(s)', $tester->getDisplay());
        $this->tenant();
        $this->flags(false, false);
        $this->closeWith(0);
    }

    public function testTheTimerCommandKeepsGoingWhenOneSiteFails(): void
    {
        $this->drain();
        $this->loginPos(['ROLE_USER_POS']);
        $this->openSession();
        $report = $this->closeWith(-1900, 'Un site en erreur');
        $this->flags(true, true);
        $c = static::getContainer();
        $master = $c->get(TenantConnectionManager::class)->getPdoMaster();
        // Un site dont la base n'existe pas, classé avant le site de test : il échoue, les suivants sont analysés quand même
        $master->prepare('INSERT INTO tenants (code, name, dbname, custom_domain, short_code) VALUES (?, ?, ?, ?, ?)')->execute(['aa-pertes-en-erreur', 'Site en erreur', 'db_pertes_absente', 'aa-pertes.test', 'AAP001']);
        try {
            $provider = $this->tenant();
            $tester = new CommandTester(new LossAnalyseCommand($c->get(TenantConnectionManager::class), $provider, $c->get(LossAnalysisService::class), new NullLogger()));
            $this->assertSame(Command::FAILURE, $tester->execute([]));
            $display = $tester->getDisplay();
            $this->assertStringContainsString('aa-pertes-en-erreur : non analysé', $display);
            $this->assertStringContainsString('1 site(s) en erreur', $display);
            $this->assertStringContainsString(MV_TEST_TENANT_CODE . ' : done', $display);
            $this->assertStringNotContainsString('db_pertes_absente', $display, 'le détail de l\'erreur va au journal du serveur, pas à la sortie');
        } finally {
            $master->prepare('DELETE FROM tenants WHERE code = ?')->execute(['aa-pertes-en-erreur']);
            $this->tenant();
            $this->flags(false, false);
        }
        $this->assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM loss_finding WHERE dedup_key = ?', ['CASH_DISCREPANCY:z_report:' . $report['id']]));
    }
}
