<?php

namespace App\Tests\Functional\LossPrevention;

use App\LossPrevention\Services\LossAnalysisContext;
use App\LossPrevention\Services\Rule\CashDiscrepancyRule;
use App\LossPrevention\Services\Rule\CashShortageSeriesRule;

/**
 * Prévention des pertes, lot L1-a : renforts de tests après la relecture finale (14/11/2026). Chaque test tue une modification du code que
 * les autres tests laissaient passer : le plancher de la notice dans une règle de session, « décider, c'est avoir vu », une opération sautée
 * par deux règles comptée une fois, les compteurs d'une règle annulée, la remarque sur 30 jours, la garde du pays sur les règles par
 * personne, l'empreinte d'une preuve, la borne exacte du délai d'annulation.
 */
class LossPreventionReinforcementTest extends LossPreventionTestCase
{
    public function testASessionRuleNeverCountsWhatPrecedesTheStaffNotice(): void
    {
        $this->drain();
        $this->place('CA', 'QC');
        $this->loginPos(['ROLE_USER_POS', 'ROLE_POS_MANAGER']);
        $session = $this->openSession();
        $cancel = function (): int {
            $sale = $this->sellCash();
            $this->json($this->pos('POST', '/api/order/cancel/' . $sale['orderId'], ['paymentMethod' => 2, 'cashRegisterId' => $this->register]));

            return (int) $sale['orderId'];
        };
        $series = fn () => (int) $this->db->fetchOne('SELECT COUNT(*) FROM loss_finding WHERE dedup_key = ?', ['VOID_AFTER_SALE:session:' . $session]);
        // Deux annulations AVANT la confirmation de la notice, dans une session qui reste ouverte
        $cancel();
        $cancel();
        $this->json($this->admin('PUT', self::BASE . '/reglages', ['staffNoticeConfirmed' => true]));
        // Une annulation après : trois dans la session, mais une seule depuis la notice. La série (3) n'est pas atteinte
        $after = $cancel();
        $run = $this->json($this->admin('POST', self::BASE . '/analyse'));
        $this->assertSame([1, 0], [$run['opened'], $run['skipped']], 'seulement l\'annulation rapide d\'après la notice : ' . json_encode($run));
        $this->assertSame([1, 0], [(int) $this->db->fetchOne('SELECT COUNT(*) FROM loss_finding WHERE dedup_key = ?', ['VOID_AFTER_SALE:order:' . $after]), $series()],
            'les annulations d\'avant la notice ne sont pas relues dans la session');
        // Deux de plus après la notice : trois depuis la notice, la série s'ouvre, avec trois preuves et non cinq
        $cancel();
        $cancel();
        $this->json($this->admin('POST', self::BASE . '/analyse'));
        $this->assertSame(1, $series());
        $this->assertSame(3, (int) $this->db->fetchOne('SELECT COUNT(*) FROM loss_evidence e JOIN loss_finding f ON f.id = e.finding_id WHERE f.dedup_key = ?', ['VOID_AFTER_SALE:session:' . $session]));
        $this->closeWith(0);
    }

    public function testDecidingAFindingMarksItSeen(): void
    {
        $this->drain();
        $this->loginPos(['ROLE_USER_POS']);
        $this->openSession();
        $report = $this->closeWith(-1300, 'Décidé sans ouvrir la fiche');
        $this->assertSame(1, $this->json($this->admin('POST', self::BASE . '/analyse'))['opened']);
        $id = (int) $this->db->fetchOne('SELECT id FROM loss_finding WHERE dedup_key = ?', ['CASH_DISCREPANCY:z_report:' . $report['id']]);
        $today = $this->today();
        $new = fn () => $this->json($this->admin('GET', self::BASE . "?from=$today&to=$today&analyse=0"))['counts']['new'];
        $before = $new();
        $this->assertContains($id, array_column($this->json($this->admin('GET', self::BASE . '/constats?isNew=1&limit=100')), 'id'));
        // Décision SANS avoir ouvert la fiche
        $decided = $this->json($this->admin('PUT', self::BASE . "/constats/$id/decision", ['status' => 'dismissed']));
        $this->assertFalse($decided['isNew'], 'décider, c\'est avoir vu');
        $this->assertNotNull($this->db->fetchOne('SELECT seen_at FROM loss_finding WHERE id = ?', [$id]));
        $this->assertNotContains($id, array_column($this->json($this->admin('GET', self::BASE . '/constats?isNew=1&limit=100')), 'id'));
        $this->assertSame($before - 1, $new(), 'un constat décidé n\'est plus compté « nouveau »');
    }

    public function testAnEntrySkippedByTwoRulesCountsOnceAndARolledBackRuleIsNotCounted(): void
    {
        $this->drain();
        $this->place('US', 'FL');
        $this->loginPos(['ROLE_USER_POS']);
        $skippedTotal = fn () => $this->json($this->admin('GET', self::BASE . '?analyse=0'))['lastAnalysis']['skipped']['total'] ?? 0;
        $journal = fn () => $this->db->fetchAssociative("SELECT COUNT(*) AS n, MAX(id) AS last FROM loss_journal WHERE type = 'ENTRIES_SKIPPED'");

        // 1. La règle d'écart écrit son constat PUIS échoue ; la règle par personne échoue aussi : même entrée (la clôture), deux règles
        $this->openSession();
        $report = $this->closeWith(-1700, 'Deux règles en échec');
        $cash = new class extends CashDiscrepancyRule {
            public function handle(array $entry, LossAnalysisContext $ctx): void
            {
                parent::handle($entry, $ctx); // le constat est écrit…
                throw new \RuntimeException('… puis la règle échoue : son point de reprise annule ce constat');
            }
        };
        $series = new class extends CashShortageSeriesRule {
            public function handle(array $entry, LossAnalysisContext $ctx): void
            {
                throw new \RuntimeException('variante par personne en panne');
            }
        };
        $beforeTotal = $skippedTotal();
        $beforeJournal = $journal();
        $run = $this->analysisWith([$cash, $series])->run();
        $this->assertSame(['done', 1, 0, 0], [$run['status'], $run['skipped'], $run['opened'], $run['completed']], 'une opération sautée par deux règles compte une fois ; le constat annulé n\'est pas compté : ' . json_encode($run));
        $this->assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM loss_finding WHERE dedup_key = ?', ['CASH_DISCREPANCY:z_report:' . $report['id']]), 'le constat écrit avant l\'échec est annulé');
        $afterJournal = $journal();
        $this->assertSame((int) $beforeJournal['n'] + 1, (int) $afterJournal['n']);
        $this->assertSame(1, json_decode((string) $this->db->fetchOne('SELECT data FROM loss_journal WHERE id = ?', [$afterJournal['last']]), true)['count'], 'au journal du module : une opération');
        $this->assertSame($beforeTotal + 1, $skippedTotal(), 'dans la synthèse : une opération de plus, pas deux');

        // 2. La remarque ne porte que sur les 30 derniers jours : une entrée plus ancienne n'y change rien
        $this->db->insert('loss_journal', ['type' => 'ENTRIES_SKIPPED', 'occurred_at' => (new \DateTimeImmutable('-40 days', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
            'user_id' => null, 'finding_id' => null, 'data' => '{"count":7,"sequences":[1,2,3,4,5,6,7]}']);
        $this->assertSame($beforeTotal + 1, $skippedTotal(), 'une opération sautée il y a 40 jours ne compte plus');
    }

    public function testPersonPatternsTurnedOnInTheUnitedStatesStopWhenTheSiteMovesToQuebec(): void
    {
        $this->drain();
        $this->place('US', 'TX');
        $on = $this->json($this->admin('PUT', self::BASE . '/reglages', ['personPatterns' => ['enabled' => true]]));
        $this->assertSame([true, true], [$on['personPatterns']['allowed'], $on['personPatterns']['enabled']], 'allumées par le site, au Texas');
        // L'adresse du site passe au Québec : le choix du site reste en base, mais le pays ne le permet plus
        $this->place('CA', 'QC');
        $this->assertTrue((bool) $this->db->fetchOne('SELECT person_patterns_enabled FROM loss_setting'));
        $qc = $this->json($this->admin('GET', self::BASE . '/reglages'));
        $this->assertSame([false, false], [$qc['personPatterns']['allowed'], $qc['personPatterns']['enabled']]);
        $this->assertSame([false, false], array_values(array_intersect_key($this->json($this->admin('GET', self::BASE . '/regles'))['rules'][0]['personVariant'], ['allowed' => 1, 'enabled' => 1])));
        $this->json($this->admin('PUT', self::BASE . '/reglages', ['staffNoticeConfirmed' => true]));
        $closer = $this->loginPos(['ROLE_USER_POS']);
        foreach ([1, 2, 3] as $ignored) {
            $this->openSession();
            $this->closeWith(-900, 'Manque');
        }
        $run = $this->json($this->admin('POST', self::BASE . '/analyse'));
        $this->assertSame([3, 0], [$run['opened'], $run['skipped']], 'trois constats de clôture, aucun constat par personne : ' . json_encode($run));
        $this->assertSame(0, (int) $this->db->fetchOne("SELECT COUNT(*) FROM loss_finding WHERE variant = 'person' AND subject_id = ?", [$closer->getId()]));
    }

    public function testATamperedEvidenceIsReportedByTheFingerprint(): void
    {
        $this->drain();
        $this->loginPos(['ROLE_USER_POS']);
        $this->openSession();
        $report = $this->closeWith(-1400, 'Empreinte');
        $this->json($this->admin('POST', self::BASE . '/analyse'));
        $id = (int) $this->db->fetchOne('SELECT id FROM loss_finding WHERE dedup_key = ?', ['CASH_DISCREPANCY:z_report:' . $report['id']]);
        $this->assertTrue($this->json($this->admin('GET', self::BASE . '/constats/' . $id))['evidenceIntact']);
        // Une preuve dont la copie ne correspond pas à son empreinte (insérée ainsi : la base refuse toute modification d'une preuve existante)
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $this->db->insert('loss_evidence', ['finding_id' => $id, 'kind' => 'z_report', 'occurred_at' => $now, 'ref_type' => 'z_report', 'ref_id' => 999999999,
            'snapshot' => '{"discrepancy":-1,"reportNumber":0}', 'fingerprint' => hash('sha256', '{"discrepancy":-999999,"reportNumber":0}'), 'created_at' => $now]);
        $tampered = $this->json($this->admin('GET', self::BASE . '/constats/' . $id));
        $this->assertFalse($tampered['evidenceIntact'], 'une copie qui ne correspond plus à son empreinte est signalée');
        $this->assertCount(2, $tampered['evidence']);
    }

    public function testQuickCancellationAtTheExactLimitAndOneSecondLater(): void
    {
        $this->drain();
        $this->loginPos(['ROLE_USER_POS', 'ROLE_POS_MANAGER']);
        $this->openSession();
        $orders = [];
        foreach ([600, 601] as $seconds) { // délai de la règle : 10 minutes
            $sale = $this->sellCash();
            $this->json($this->pos('POST', '/api/order/cancel/' . $sale['orderId'], ['paymentMethod' => 2, 'cashRegisterId' => $this->register]));
            // la vente est datée exactement N secondes avant son annulation (la règle relit les deux dates au moment de l'analyse)
            $this->db->executeStatement('UPDATE "order" SET order_date = canceled_at - make_interval(secs => ?) WHERE id = ?', [$seconds, $sale['orderId']]);
            $orders[$seconds] = (int) $sale['orderId'];
        }
        $run = $this->json($this->admin('POST', self::BASE . '/analyse'));
        $found = fn (int $order) => (int) $this->db->fetchOne('SELECT COUNT(*) FROM loss_finding WHERE dedup_key = ?', ['VOID_AFTER_SALE:order:' . $order]);
        $this->assertSame([1, 0], [$found($orders[600]), $found($orders[601])], 'à 10 minutes juste : constat ; une seconde après : rien');
        $this->assertSame([1, 0], [$run['opened'], $run['skipped']]);
        $this->assertSame(10, json_decode((string) $this->db->fetchOne('SELECT facts FROM loss_finding WHERE dedup_key = ?', ['VOID_AFTER_SALE:order:' . $orders[600]]), true)['delayMinutes']);
        $this->closeWith(0);
    }
}
