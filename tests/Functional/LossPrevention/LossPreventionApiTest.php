<?php

namespace App\Tests\Functional\LossPrevention;

use App\Services\Platform\PlatformModeService;
use App\Services\PosAuditService\PosAuditService;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Prévention des pertes, lot L1-a (`docs/prevention-pertes.md`) : les constats de la vague 1 naissent de journées de caisse jouées par les
 * vraies routes de la caisse ; rejouer l'analyse ne crée aucun doublon ; la caisse et son journal ne sont jamais modifiés ; aucun nom dans
 * les résumés, la liste et la synthèse ; interrupteurs par pays ; garde du module ; rôles.
 */
class LossPreventionApiTest extends LossPreventionTestCase
{
    public function testCashDiscrepancyOpensOneFindingPerClosingAndReplayingCreatesNothing(): void
    {
        $this->drain();
        $cashier = $this->loginPos(['ROLE_USER_POS', 'ROLE_POS_MANAGER']);
        // Écart de −300 : sous le plancher de 500 cents, aucun constat
        $this->openSession();
        $this->sellCash();
        $small = $this->closeWith(-300, 'Petit écart');
        // Écart de −1 200 : un constat
        $this->openSession();
        $this->sellCash();
        $short = $this->closeWith(-1200, 'Erreur de rendu de monnaie');

        $auditBefore = (int) $this->db->fetchOne('SELECT COUNT(*) FROM pos_audit_log');
        $run = $this->json($this->admin('POST', self::BASE . '/analyse'));
        $this->assertSame(['done', 1, 0, false], [$run['status'], $run['opened'], $run['skipped'], $run['pending']], json_encode($run));

        $list = $this->admin('GET', self::BASE . '/constats?rule=CASH_DISCREPANCY&sort=date&page=1&limit=50');
        $rows = $this->json($list);
        $this->assertNotNull($list->headers->get('X-Total-Count'));
        $mine = array_values(array_filter($rows, fn ($r) => str_contains($r['summary'], (string) $short['number']) && $r['amountAtStake'] === 1200));
        $this->assertCount(1, $mine, 'un constat pour la clôture à −1 200, aucun pour celle à −300 : ' . json_encode(array_column($rows, 'summary'), JSON_UNESCAPED_UNICODE));
        $row = $mine[0];
        $this->assertSame(['CASH_DISCREPANCY', false, 'open', true, false, false, 'CAD', $this->register], [$row['rule']['code'], $row['personPattern'], $row['status'], $row['isNew'],
            $row['newEvidenceSinceDecision'], $row['backfill'], $row['currency'], $row['cashRegister']['id']]);
        $this->assertMatchesRegularExpression('/il manque 12,00/u', $row['summary']);
        $this->assertStringNotContainsString($this->mark, (string) $list->getContent(), 'la liste ne nomme personne');

        // Fiche : preuve en lignes prêtes à afficher, personnes en poste nommées, consultation journalisée, « nouveau » éteint
        $detail = $this->json($this->admin('GET', self::BASE . '/constats/' . $row['id']));
        $this->assertSame(['z_report', true, null, 'pertes.constat'], [$detail['evidence'][0]['kind'], $detail['evidenceIntact'], $detail['opinion'], $detail['screen']]);
        $lines = array_column($detail['evidence'][0]['lines'], 'value', 'label');
        $this->assertSame('Erreur de rendu de monnaie', $lines['Motif saisi à la fermeture'], 'le motif est lu dans le rapport de clôture, à l\'affichage');
        $this->assertStringNotContainsString('Erreur de rendu', implode(' ', $this->db->fetchFirstColumn('SELECT snapshot FROM loss_evidence WHERE finding_id = ?', [$row['id']])), 'un texte libre du caissier n\'entre pas dans une preuve inaltérable');
        $this->assertMatchesRegularExpression('/^-12,00/u', $lines['Écart (compté − attendu)']);
        $this->assertStringContainsString($this->mark, $lines['Fermée par']);
        $this->assertSame(['type' => 'z_report', 'id' => $short['id']], array_intersect_key($detail['evidence'][0]['ref'], ['type' => 1, 'id' => 1]));
        $this->assertSame(64, strlen((string) $detail['evidence'][0]['ref']['hash']), 'renvoi à l\'entrée du journal de caisse (séquence, empreinte)');
        $this->assertSame([['id' => $cashier->getId(), 'role' => 'closer'], ['id' => $cashier->getId(), 'role' => 'seller']], array_map(fn ($p) => ['id' => $p['id'], 'role' => $p['role']], $detail['onDuty']), 'qui était en poste : liste exacte, sans doublon');
        $this->assertStringNotContainsString($this->mark, $detail['summary']);
        $this->assertStringNotContainsString('@', json_encode($detail), 'aucun courriel dans la fiche');
        $this->assertFalse($detail['isNew']);
        $views = fn () => (int) $this->db->fetchOne("SELECT COUNT(*) FROM loss_journal WHERE finding_id = ? AND type = 'FINDING_VIEWED'", [$row['id']]);
        $this->assertSame(1, $views());
        $this->json($this->admin('GET', self::BASE . '/constats/' . $row['id']));
        $this->assertSame(1, $views(), 'consultation journalisée une fois par administrateur et par jour : relire la fiche n\'ajoute rien');
        $second = static::getContainer()->get('lexik_jwt_authentication.jwt_manager')->create($this->user(['ROLE_ADMIN'], 'Associe'));
        $this->json($this->call('GET', self::BASE . '/constats/' . $row['id'] . '?locale=fr', null, $second));
        $this->assertSame(2, $views(), 'un autre administrateur : sa propre entrée');
        // D'un jour sur l'autre : une consultation de la veille n'empêche pas celle d'aujourd'hui
        $third = $this->user(['ROLE_ADMIN'], 'Veille');
        $this->db->insert('loss_journal', ['type' => 'FINDING_VIEWED', 'occurred_at' => (new \DateTimeImmutable('-2 days', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'), 'user_id' => $third->getId(), 'finding_id' => $row['id'], 'data' => '{}']);
        $token = static::getContainer()->get('lexik_jwt_authentication.jwt_manager')->create($third);
        $this->json($this->call('GET', self::BASE . '/constats/' . $row['id'] . '?locale=fr', null, $token));
        $this->json($this->call('GET', self::BASE . '/constats/' . $row['id'] . '?locale=fr', null, $token));
        $this->assertSame(4, $views(), 'la veille et aujourd\'hui : deux entrées pour ce compte, pas plus');
        $this->assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM loss_finding WHERE dedup_key = ?', ['CASH_DISCREPANCY:z_report:' . $small['id']]), 'aucun constat pour la clôture à −300');
        $this->assertSame(0, (int) $this->db->fetchOne("SELECT COUNT(*) FROM loss_journal WHERE type = 'LIST_VIEWED'"), 'la liste, sans nom, n\'est pas journalisée');
        $seen = array_column($this->json($this->admin('GET', self::BASE . '/constats?rule=CASH_DISCREPANCY&isNew=0&limit=100')), 'isNew', 'id');
        $this->assertArrayHasKey($row['id'], $seen, 'consulté : il sort avec isNew=0');
        $this->assertFalse($seen[$row['id']]);
        $this->assertNotContains($row['id'], array_column($this->json($this->admin('GET', self::BASE . '/constats?rule=CASH_DISCREPANCY&isNew=1&limit=100')), 'id'), 'et plus avec isNew=1');

        // Rejouer : rien de nouveau, ni au passage suivant, ni en reprenant le journal depuis le début
        $counts = fn () => [(int) $this->db->fetchOne('SELECT COUNT(*) FROM loss_finding'), (int) $this->db->fetchOne('SELECT COUNT(*) FROM loss_evidence')];
        $before = $counts();
        $this->assertSame(0, $this->json($this->admin('POST', self::BASE . '/analyse'))['opened']);
        $this->db->executeStatement('UPDATE loss_setting SET cursor_sequence = 0');
        $this->drain();
        $this->assertSame($before, $counts(), 'analyse rejouée depuis le début : aucun constat ni preuve en double');

        // La caisse n'est jamais modifiée : même journal, chaîne intacte
        $this->assertSame($auditBefore, (int) $this->db->fetchOne('SELECT COUNT(*) FROM pos_audit_log'), 'l\'analyse n\'écrit rien au journal de caisse');
        $this->assertSame('intact', static::getContainer()->get(PosAuditService::class)->verify()['status']);

        // Synthèse : sans nom, et mêmes totaux de clôture que le bloc cashDesk des statistiques, au cent près
        $today = $this->today();
        $summary = $this->admin('GET', self::BASE . "?from=$today&to=$today&analyse=0");
        $s = $this->json($summary);
        $this->assertStringNotContainsString($this->mark, (string) $summary->getContent());
        $this->assertSame(['pertes.home', $today, 'CAD'], [$s['screen'], $s['period']['to'], $s['currency']]);
        $this->assertGreaterThanOrEqual(1, $s['counts']['active']);
        $stats = $this->json($this->admin('GET', "/api/gestion/statistiques?from=$today&to=$today&sections=payments"))['sections']['payments']['cashDesk'];
        $this->assertSame([$stats['closings']['value'], $stats['discrepancy']['value'], $stats['discrepancyCount']], [$s['cashDesk']['closings'], $s['cashDesk']['discrepancy'], $s['cashDesk']['discrepancyCount']]);
        $this->assertNotSame($small['id'], $short['id']);
    }

    public function testDrawerOpenedWithoutSaleIsCompletedAndNeverReopensADecidedFinding(): void
    {
        $this->drain();
        $cashier = $this->loginPos(['ROLE_USER_POS']);
        $session = $this->openSession();
        foreach (['Monnaie', 'Monnaie', 'Contrôle'] as $reason) {
            $this->pos('POST', '/api/caisse/drawer-open', ['cashRegisterId' => $this->register, 'reason' => $reason]);
        }
        $this->assertSame(0, $this->json($this->admin('POST', self::BASE . '/analyse'))['opened'], 'trois ouvertures : toléré');
        $this->pos('POST', '/api/caisse/drawer-open', ['cashRegisterId' => $this->register, 'reason' => 'Billet coincé']);
        $this->assertSame(1, $this->json($this->admin('POST', self::BASE . '/analyse'))['opened'], 'la quatrième ouvre le constat');
        $id = (int) $this->db->fetchOne("SELECT id FROM loss_finding WHERE dedup_key = ?", ['DRAWER_NO_SALE:session:' . $session]);
        $detail = $this->json($this->admin('GET', self::BASE . '/constats/' . $id));
        $this->assertCount(4, $detail['evidence']);
        $this->assertSame([['id' => $cashier->getId(), 'role' => 'operator']], array_map(fn ($p) => ['id' => $p['id'], 'role' => $p['role']], $detail['onDuty']), 'quatre ouvertures du même compte : une seule ligne');
        $this->assertSame(['DRAWER_NO_SALE', null, $session], [$detail['rule']['code'], $detail['amountAtStake'], $detail['sessionId']]);
        $this->assertMatchesRegularExpression('/^4 ouvertures du tiroir/u', $detail['summary']);
        $this->assertSame('Billet coincé', array_column($detail['evidence'][3]['lines'], 'value', 'label')['Motif saisi'], 'lu dans le journal de caisse, à l\'affichage');
        $this->assertStringNotContainsString('Billet', implode(' ', $this->db->fetchFirstColumn('SELECT snapshot FROM loss_evidence WHERE finding_id = ?', [$id])));

        // Décidé, puis une cinquième ouverture : même constat, jamais rouvert, marqué « nouvelles preuves depuis la décision »
        $decided = $this->json($this->admin('PUT', self::BASE . "/constats/$id/decision", ['status' => 'dismissed']));
        $this->assertSame(['dismissed', false], [$decided['status'], $decided['newEvidenceSinceDecision']]);
        sleep(1); // dates à la seconde
        $this->pos('POST', '/api/caisse/drawer-open', ['cashRegisterId' => $this->register, 'reason' => 'Encore']);
        $run = $this->json($this->admin('POST', self::BASE . '/analyse'));
        $this->assertSame([0, 1], [$run['opened'], $run['completed']]);
        $after = $this->json($this->admin('GET', self::BASE . '/constats/' . $id));
        $this->assertSame(['dismissed', true, 5], [$after['status'], $after['newEvidenceSinceDecision'], count($after['evidence'])]);
        $this->assertMatchesRegularExpression('/^5 ouvertures/u', $after['summary']);
        $this->closeWith(0);
    }

    public function testVoidAfterSaleQuickCancellationAndSeriesInASession(): void
    {
        $this->drain();
        $this->loginPos(['ROLE_USER_POS', 'ROLE_POS_MANAGER']);
        $session = $this->openSession();
        $orders = [];
        $total = 0;
        foreach ([1, 2, 3] as $n) {
            $sale = $this->sellCash();
            $orders[] = (int) $sale['orderId'];
            $total += (int) round($sale['totalAmount'] * 100);
            $this->json($this->pos('POST', '/api/order/cancel/' . $sale['orderId'], ['paymentMethod' => 2, 'cashRegisterId' => $this->register]));
            $run = $this->json($this->admin('POST', self::BASE . '/analyse'));
            // chaque annulation rapide d'une vente en espèces ouvre son constat ; la troisième ouvre en plus celui de la série
            $this->assertSame($n === 3 ? 2 : 1, $run['opened'], "annulation $n : " . json_encode($run));
        }
        $quick = $this->db->fetchAssociative('SELECT * FROM loss_finding WHERE dedup_key = ?', ['VOID_AFTER_SALE:order:' . $orders[0]]);
        $this->assertSame(['order', $orders[0], 'open'], [$quick['subject_type'], (int) $quick['subject_id'], $quick['status']]);
        $detail = $this->json($this->admin('GET', self::BASE . '/constats/' . $quick['id']));
        $this->assertSame(['order', 'credit_note'], array_column($detail['evidence'], 'kind'));
        $this->assertMatchesRegularExpression('/annulée 0 min après/u', $detail['summary']);
        $this->assertSame((int) round($total / 3), $detail['amountAtStake']);
        $series = $this->json($this->admin('GET', self::BASE . '/constats/' . $this->db->fetchOne('SELECT id FROM loss_finding WHERE dedup_key = ?', ['VOID_AFTER_SALE:session:' . $session])));
        $this->assertSame([3, $total], [count($series['evidence']), $series['amountAtStake']]);
        $this->assertMatchesRegularExpression('/^3 ventes annulées/u', $series['summary']);
        $this->assertSame(['credit_note'], array_values(array_unique(array_column($series['evidence'], 'kind'))));
        // Les ventes gardent leurs montants et leur statut d'annulation : le module n'a rien écrit dans la caisse
        $this->assertSame([7, 7, 7], array_map('intval', $this->db->fetchFirstColumn('SELECT status_id FROM "order" WHERE id IN (?, ?, ?) ORDER BY id', $orders)));
        $this->closeWith(0);
    }

    public function testDecisionNeedsANoteWhenConfirmedKeepsItsHistoryAndCanReturnToNew(): void
    {
        $this->drain();
        $this->loginPos(['ROLE_USER_POS']);
        $this->openSession();
        $this->sellCash();
        $report = $this->closeWith(800, 'Billet en trop');
        $this->json($this->admin('POST', self::BASE . '/analyse'));
        $id = (int) $this->db->fetchOne('SELECT id FROM loss_finding WHERE dedup_key = ?', ['CASH_DISCREPANCY:z_report:' . $report['id']]);
        $this->assertMatchesRegularExpression('/8,00.*de trop/u', $this->json($this->admin('GET', self::BASE . '/constats/' . $id))['summary']);
        $url = self::BASE . "/constats/$id/decision";

        $r = $this->json($this->admin('PUT', $url, ['status' => 'confirmed']), 422);
        $this->assertSame(['VALIDATION_FAILED', ['note' => 'REQUIRED']], [$r['error'], $r['fields']]);
        $this->assertSame(['status' => 'INVALID_VALUE'], $this->json($this->admin('PUT', $url, ['status' => 'guilty']), 422)['fields']);
        $this->assertSame('FINDING_NOT_FOUND', $this->json($this->admin('PUT', self::BASE . '/constats/999999999/decision', ['status' => 'watch']), 404)['error']);
        $this->assertSame('FINDING_NOT_FOUND', $this->json($this->admin('GET', self::BASE . '/constats/999999999'), 404)['error']);
        // Entrées illisibles : 422 avec le champ, jamais une erreur interne
        $this->assertSame(['to' => 'INVALID_DATE'], $this->json($this->admin('GET', self::BASE . '?analyse=0&to=demain'), 422)['fields']);
        $this->assertSame(['from' => 'INVALID_DATE'], $this->json($this->admin('GET', self::BASE . '?analyse=0&from=2026-02-31'), 422)['fields']);
        $this->assertSame(['to' => 'BEFORE_FROM'], $this->json($this->admin('GET', self::BASE . '?analyse=0&from=2026-11-10&to=2026-11-01'), 422)['fields']);
        $this->assertSame(['status' => 'INVALID_VALUE', 'from' => 'INVALID_DATE'], $this->json($this->admin('GET', self::BASE . '/constats?status[]=open&from=hier'), 422)['fields']);
        $this->assertSame(['cashRegisterId' => 'INVALID_VALUE'], $this->json($this->admin('GET', self::BASE . '/constats?cashRegisterId=un'), 422)['fields']);
        $far = $this->admin('GET', self::BASE . '/constats?page=99999999999999999999&limit=99999999999999999999');
        $this->assertSame([200, '100000', '100', '[]'], [$far->getStatusCode(), $far->headers->get('X-Page'), $far->headers->get('X-Limit'), $far->getContent()], 'page et limite démesurées : ramenées à leurs bornes');
        $this->assertSame(['page' => 'INVALID_VALUE', 'limit' => 'INVALID_VALUE'], $this->json($this->admin('GET', self::BASE . '/constats?page=abc&limit=-1'), 422)['fields']);
        $this->assertSame(['from' => 'INVALID_DATE', 'to' => 'INVALID_DATE'], $this->json($this->admin('GET', self::BASE . '?analyse=0&from[]=2026-11-01&to[]=2026-11-02'), 422)['fields'], 'un paramètre en tableau n\'est pas une date');

        $watch = $this->json($this->admin('PUT', $url, ['status' => 'watch']));
        $this->assertSame(['watch', 'watch', null], [$watch['status'], $watch['decision']['status'], $watch['decision']['note']]);
        $confirmed = $this->json($this->admin('PUT', $url, ['status' => 'confirmed', 'note' => 'Vérifié avec le comptage du lendemain']));
        $this->assertSame(['confirmed', 'Vérifié avec le comptage du lendemain'], [$confirmed['status'], $confirmed['decision']['note']]);
        $this->assertStringContainsString($this->mark, $confirmed['decision']['by']['name']);
        $back = $this->json($this->admin('PUT', $url, ['status' => 'open']));
        $this->assertSame(['open', null], [$back['status'], $back['decision']]);
        $this->assertSame([['open', 'watch', false], ['watch', 'confirmed', true], ['confirmed', 'open', false]], array_map(fn ($h) => [$h['previous'], $h['status'], $h['hasNote']], $back['history']), 'chaque changement est gardé : qui, quand, avant, après, avec ou sans note');
        $this->assertArrayNotHasKey('note', $back['history'][1], 'le texte de la note n\'est pas dans l\'historique');
        $this->assertStringNotContainsString('Vérifié', implode(' ', $this->db->fetchFirstColumn("SELECT data FROM loss_journal WHERE finding_id = ? AND type = 'DECISION_CHANGED'", [$id])), 'ni au journal inaltérable');
        // La note ne vit que sur le constat, dans sa dernière version : elle se corrige
        $this->json($this->admin('PUT', $url, ['status' => 'explained', 'note' => 'Monnaie pour Julie T.']));
        $fixed = $this->json($this->admin('PUT', $url, ['status' => 'explained', 'note' => 'Monnaie rendue à une cliente']));
        $this->assertSame('Monnaie rendue à une cliente', $fixed['decision']['note']);
        $this->assertStringNotContainsString('Julie', (string) json_encode($fixed) . implode(' ', $this->db->fetchFirstColumn('SELECT data FROM loss_journal WHERE finding_id = ?', [$id])), 'une note corrigée ne laisse aucune trace de l\'ancienne');
        $this->assertCount(1, $back['evidence'], 'une décision ne touche pas aux preuves');
    }

    public function testRulesHaveBoundsAndAThresholdChangeOnlyAppliesToLaterAnalyses(): void
    {
        $this->drain();
        $rules = $this->json($this->admin('GET', self::BASE . '/regles'));
        $this->assertSame(['CASH_DISCREPANCY', 'DRAWER_NO_SALE', 'VOID_AFTER_SALE', 'REFUND_PATTERN', 'DISCOUNT_PATTERN', 'PRICE_OVERRIDE', 'APPROVAL_PATTERN', 'UNREAD_SOLD', 'EXIT_WITHOUT_SALE', 'EXIT_COVERED',
            'STOCKROOM_NOT_SHELVED', 'BACK_DOOR', 'OUT_OF_HOURS', 'PIECE_MISSING'], array_column($rules['rules'], 'code'));
        $this->assertSame([1, 1, 1, 2, 2, 2, 2, 3, 4, 4, 4, 4, 4, 4], array_column($rules['rules'], 'wave'));
        $cash = $rules['rules'][0];
        $this->assertSame(['key' => 'minAmount', 'type' => 'cents', 'value' => 500, 'default' => 500, 'min' => 0, 'max' => 1000000], array_diff_key($cash['params'][0], ['label' => 1]));
        $this->assertSame([false, false, [3, 30]], [$cash['personVariant']['allowed'], $cash['personVariant']['enabled'], array_column($cash['personVariant']['params'], 'value')]);
        $this->assertNull($rules['rules'][1]['personVariant']);
        $english = $this->admin('GET', self::BASE . '/regles?locale=en');
        $this->assertSame('Drawer opened without a sale', $this->json($english)['rules'][1]['label']);
        foreach ([$this->admin('GET', self::BASE . '/regles'), $english] as $answer) {
            $this->assertStringNotContainsString('rules.', (string) $answer->getContent(), 'aucun libellé en clé brute');
        }
        $this->assertSame(['Nombre de manques', 'Sur combien de jours'], array_column($cash['personVariant']['params'], 'label'));

        $url = self::BASE . '/regles/CASH_DISCREPANCY';
        $this->assertSame(['params.minAmount' => 'PARAM_OUT_OF_RANGE'], $this->json($this->admin('PUT', $url, ['params' => ['minAmount' => 99999999]]), 422)['fields']);
        $this->assertSame(['enabled' => 'MUST_BE_BOOLEAN', 'params.nope' => 'UNKNOWN_PARAM'], $this->json($this->admin('PUT', $url, ['enabled' => 'oui', 'params' => ['nope' => 1]]), 422)['fields']);
        $this->assertSame(['personVariant.enabled' => 'NOT_ALLOWED_IN_COUNTRY'], $this->json($this->admin('PUT', $url, ['personVariant' => ['enabled' => true]]), 422)['fields'], 'Ontario : règles par personne non permises');
        $this->assertSame('RULE_NOT_FOUND', $this->json($this->admin('PUT', self::BASE . '/regles/NOPE', ['enabled' => false]), 404)['error']);

        // Un constat avec le seuil par défaut ; puis seuil à 2 000 : la clôture suivante à −1 200 n'ouvre rien, le premier constat reste
        $this->loginPos(['ROLE_USER_POS']);
        $this->openSession();
        $first = $this->closeWith(-1200, 'Premier écart');
        $this->assertSame(1, $this->json($this->admin('POST', self::BASE . '/analyse'))['opened']);
        // Le journal du module (inaltérable) ne garde que les valeurs validées, jamais le corps reçu
        $this->assertSame(2000, $this->json($this->admin('PUT', $url, ['params' => ['minAmount' => 2000], 'personVariant' => ['note' => 'Julie T., texte libre']]))['params'][0]['value']);
        $kept = (string) $this->db->fetchOne("SELECT data FROM loss_journal WHERE type = 'RULE_CHANGED' ORDER BY id DESC LIMIT 1");
        $this->assertSame(['code' => 'CASH_DISCREPANCY', 'params' => ['minAmount' => 2000]], json_decode($kept, true));
        $this->openSession();
        $second = $this->closeWith(-1200, 'Second écart');
        $this->assertSame(0, $this->json($this->admin('POST', self::BASE . '/analyse'))['opened']);
        $this->assertSame([1, 0], [(int) $this->db->fetchOne('SELECT COUNT(*) FROM loss_finding WHERE dedup_key = ?', ['CASH_DISCREPANCY:z_report:' . $first['id']]),
            (int) $this->db->fetchOne('SELECT COUNT(*) FROM loss_finding WHERE dedup_key = ?', ['CASH_DISCREPANCY:z_report:' . $second['id']])], 'changer un seuil ne ferme ni ne supprime aucun constat');

        // Règle éteinte : rien
        $this->assertFalse($this->json($this->admin('PUT', self::BASE . '/regles/drawer_no_sale', ['enabled' => false]))['enabled']);
        $this->openSession();
        foreach (range(1, 5) as $i) {
            $this->pos('POST', '/api/caisse/drawer-open', ['cashRegisterId' => $this->register, 'reason' => 'Essai ' . $i]);
        }
        $this->assertSame(0, $this->json($this->admin('POST', self::BASE . '/analyse'))['opened']);
        $this->assertGreaterThanOrEqual(2, (int) $this->db->fetchOne("SELECT COUNT(*) FROM loss_journal WHERE type = 'RULE_CHANGED'"), 'chaque changement de règle est gardé au journal du module');
        $this->closeWith(0);
    }

    public function testStaffNoticeBlocksTheAnalysisInQuebecAndPersonPatternsFollowTheRegion(): void
    {
        $this->drain();
        // Québec : notice exigée, analyse arrêtée tant qu'elle n'est pas confirmée ; règles par personne non permises
        $this->place('CA', 'QC');
        $settings = $this->json($this->admin('GET', self::BASE . '/reglages'));
        $this->assertSame(['CA', 'QC', true, null, false, false], [$settings['country'], $settings['region'], $settings['staffNotice']['required'], $settings['staffNotice']['confirmedAt'],
            $settings['personPatterns']['allowed'], $settings['personPatterns']['enabled']]);
        $this->assertSame(['faces', 'audio', 'fitting_room_camera', 'gesture_analysis'], array_column($settings['never'], 'code'));
        // Noms du pays et de la région, dans la langue demandée, à côté des codes (qui ne changent pas)
        $this->assertSame(['Canada', 'Québec'], [$settings['countryLabel'], $settings['regionLabel']]);
        $english = $this->json($this->admin('GET', self::BASE . '/reglages?locale=en'));
        $this->assertSame(['CA', 'QC', 'Canada', 'Quebec'], [$english['country'], $english['region'], $english['countryLabel'], $english['regionLabel']]);
        $this->assertSame(['none', 24, 60], [$settings['retention']['purge'], $settings['retention']['findingsMonths'], $settings['retention']['confirmedMonths']]);
        $this->assertNotSame('', $settings['staffNotice']['template']['text']);
        $this->assertSame('STAFF_NOTICE_REQUIRED', $this->json($this->admin('POST', self::BASE . '/analyse'), 409)['error']);
        $blocked = $this->json($this->admin('GET', self::BASE));
        $this->assertSame(['STAFF_NOTICE_REQUIRED', 'STAFF_NOTICE_REQUIRED'], [$blocked['lastAnalysis']['blocked'], $blocked['caveats'][0]['code']]);
        $this->assertSame(['personPatterns.enabled' => 'NOT_ALLOWED_IN_COUNTRY', 'retention.findingsMonths' => 'PARAM_OUT_OF_RANGE'],
            $this->json($this->admin('PUT', self::BASE . '/reglages', ['personPatterns' => ['enabled' => true], 'retention' => ['findingsMonths' => 0]]), 422)['fields']);
        // Activité de caisse AVANT la confirmation : une clôture avec un écart, et trois ouvertures du tiroir dans une session encore ouverte
        $this->loginPos(['ROLE_USER_POS']);
        $this->openSession();
        $beforeNotice = $this->closeWith(-1500, 'Avant la notice');
        $straddling = $this->openSession();
        foreach (range(1, 3) as $i) {
            $this->pos('POST', '/api/caisse/drawer-open', ['cashRegisterId' => $this->register, 'reason' => 'Avant la notice ' . $i]);
        }
        $found = fn (string $key) => (int) $this->db->fetchOne('SELECT COUNT(*) FROM loss_finding WHERE dedup_key = ?', [$key]);
        $confirmed = $this->json($this->admin('PUT', self::BASE . '/reglages', ['staffNoticeConfirmed' => true, 'retention' => ['findingsMonths' => 12]]));
        $this->assertNotNull($confirmed['staffNotice']['confirmedAt']);
        $this->assertStringContainsString($this->mark, $confirmed['staffNotice']['confirmedBy']['name']);
        $this->assertSame(12, $confirmed['retention']['findingsMonths']);
        $first = $this->json($this->admin('POST', self::BASE . '/analyse'));
        $this->assertSame(['done', 0, 0, false], [$first['status'], $first['read'], $first['opened'], $first['pending']], 'rien de ce qui précède la notice n\'est lu, pas même par la reprise');
        $this->assertSame(0, $found('CASH_DISCREPANCY:z_report:' . $beforeNotice['id']));
        // Après la confirmation : deux ouvertures de plus dans la session à cheval (5 en tout, 2 depuis la notice : toléré), une clôture avec un écart
        foreach (range(4, 5) as $i) {
            $this->pos('POST', '/api/caisse/drawer-open', ['cashRegisterId' => $this->register, 'reason' => 'Après la notice ' . $i]);
        }
        $afterNotice = $this->closeWith(-1600, 'Après la notice');
        $after = $this->json($this->admin('POST', self::BASE . '/analyse'));
        $this->assertSame([1, 0], [$after['opened'], $after['skipped']], 'seule la clôture postérieure à la notice ouvre un constat');
        $this->assertSame([1, 0], [$found('CASH_DISCREPANCY:z_report:' . $afterNotice['id']), $found('DRAWER_NO_SALE:session:' . $straddling)], 'les ouvertures d\'avant la notice ne sont pas relues dans la session');
        $this->assertFalse((bool) $this->db->fetchOne('SELECT backfill FROM loss_finding WHERE dedup_key = ?', ['CASH_DISCREPANCY:z_report:' . $afterNotice['id']]));
        $unblocked = $this->json($this->admin('GET', self::BASE . '?analyse=0'));
        $this->assertNull($unblocked['lastAnalysis']['blocked']);
        $this->assertNotNull($unblocked['staffNotice']['confirmedAt']);
        $this->assertSame(['required', 'confirmedAt'], array_keys($unblocked['staffNotice']), 'synthèse : la date de la confirmation, jamais le nom');
        $this->assertStringNotContainsString($this->mark, (string) $this->client->getResponse()->getContent(), 'la synthèse ne nomme pas celui qui a confirmé la notice');
        $this->assertNotContains('STAFF_NOTICE_REQUIRED', array_column($unblocked['caveats'], 'code'));
        // Notice retirée : l'analyse s'arrête de nouveau
        $this->assertNull($this->json($this->admin('PUT', self::BASE . '/reglages', ['staffNoticeConfirmed' => false]))['staffNotice']['confirmedAt']);
        $this->assertSame('STAFF_NOTICE_REQUIRED', $this->json($this->admin('POST', self::BASE . '/analyse'), 409)['error']);
        // Une période sans notice n'est jamais relue : clôture pendant le retrait, puis notice reconfirmée
        $this->openSession();
        $withoutNotice = $this->closeWith(-1700, 'Sans notice');
        $this->json($this->admin('PUT', self::BASE . '/reglages', ['staffNoticeConfirmed' => true]));
        $this->assertSame([0, 0], array_values(array_intersect_key($this->json($this->admin('POST', self::BASE . '/analyse')), ['read' => 1, 'opened' => 1])));
        $this->assertSame(0, $found('CASH_DISCREPANCY:z_report:' . $withoutNotice['id']));
        $this->json($this->admin('PUT', self::BASE . '/reglages', ['staffNoticeConfirmed' => false]));
        $this->db->executeStatement('UPDATE loss_setting SET retention_findings_months = 24');

        // Dans le doute, la notice est exigée : pays vide, pays mal écrit, Canada sans province reconnue
        foreach ([['', ''], ['FRA', ''], ['Martinique', ''], ['CA', ''], ['CA', 'Quelque part']] as [$country, $state]) {
            $this->place($country, $state);
            $doubt = $this->json($this->admin('GET', self::BASE . '/reglages'));
            $this->assertTrue($doubt['staffNotice']['required'], "pays « $country », région « $state » : notice exigée dans le doute");
            $this->assertNull($doubt['regionLabel'], "pays « $country », région « $state » : région inconnue, pas de nom inventé");
            if ($country === 'FRA' || $country === '') {
                $this->assertNull($doubt['countryLabel'], "pays « $country » : pas de nom pour un code inconnu");
            }
            $this->assertFalse($doubt['personPatterns']['allowed']);
            $this->assertSame('STAFF_NOTICE_REQUIRED', $this->json($this->admin('POST', self::BASE . '/analyse'), 409)['error']);
        }

        // France : notice exigée aussi ; Ontario : simple rappel
        $this->place('FR', '');
        $this->assertTrue($this->json($this->admin('GET', self::BASE . '/reglages'))['staffNotice']['required']);
        $this->place('CA', 'ON');
        $this->assertFalse($this->json($this->admin('GET', self::BASE . '/reglages'))['staffNotice']['required']);

        // Floride : règles par personne permises et allumées d'office ; trois manques du même compte ouvrent un constat « par personne »
        $this->place('US', 'FL');
        $fl = $this->json($this->admin('GET', self::BASE . '/reglages'));
        $this->assertSame([true, true, true, false], [$fl['personPatterns']['allowed'], $fl['personPatterns']['enabled'], $fl['personPatterns']['default'], $fl['staffNotice']['required']]);
        $this->assertSame(['États-Unis', 'Floride'], [$fl['countryLabel'], $fl['regionLabel']]);
        $this->assertSame(['United States', 'Florida'], array_values(array_intersect_key($this->json($this->admin('GET', self::BASE . '/reglages?locale=en')), ['countryLabel' => 1, 'regionLabel' => 1])));
        $this->place('US', 'TX');
        $this->assertSame([true, false], array_values(array_intersect_key($this->json($this->admin('GET', self::BASE . '/reglages'))['personPatterns'], ['allowed' => 1, 'enabled' => 1])), 'ailleurs aux États-Unis : permises, éteintes par défaut');
        $this->place('US', 'FL');
        $closer = $this->loginPos(['ROLE_USER_POS']);
        $shortages = [];
        foreach ([-900, -1100, -700] as $delta) {
            $this->openSession();
            $shortages[] = $this->closeWith($delta, 'Manque');
        }
        $run = $this->json($this->admin('POST', self::BASE . '/analyse'));
        $this->assertSame(4, $run['opened'], 'trois constats de clôture et un constat par personne : ' . json_encode($run));
        $person = $this->db->fetchAssociative("SELECT * FROM loss_finding WHERE variant = 'person' AND subject_type = 'user_window' AND subject_id = ?", [$closer->getId()]);
        $list = $this->admin('GET', self::BASE . '/constats?rule=CASH_DISCREPANCY&limit=100');
        $line = array_values(array_filter($this->json($list), fn ($r) => $r['id'] === (int) $person['id']))[0];
        $this->assertTrue($line['personPattern']);
        $this->assertSame(2700, $line['amountAtStake']);
        $this->assertMatchesRegularExpression('/^3 clôtures avec un manque en 30 jours pour un même compte/u', $line['summary']);
        $this->assertStringNotContainsString($this->mark, (string) $list->getContent(), 'même pour une règle par personne, la liste ne nomme personne');
        $detail = $this->json($this->admin('GET', self::BASE . '/constats/' . $person['id']));
        $this->assertSame(['user_window', $closer->getId()], [$detail['subject']['type'], $detail['subject']['id']]);
        $this->assertStringContainsString($this->mark, $detail['subject']['name'], 'le sujet d\'une règle par personne est nommé dans la fiche seulement');
        $this->assertCount(3, $detail['evidence']);
        // Éteintes par le site : la clôture suivante n'ajoute rien au constat par personne
        $this->assertFalse($this->json($this->admin('PUT', self::BASE . '/reglages', ['personPatterns' => ['enabled' => false]]))['personPatterns']['enabled']);
        $this->openSession();
        $this->closeWith(-600, 'Manque');
        $this->assertSame([1, 0], array_values(array_intersect_key($this->json($this->admin('POST', self::BASE . '/analyse')), ['opened' => 1, 'completed' => 1])));
    }

    public function testOnlyTheSiteAdministratorAndBothModuleGuardsInLiveMode(): void
    {
        // Rôles : ni anonyme, ni caissier, ni responsable de caisse
        $this->assertSame(401, $this->call('GET', self::BASE, null, null)->getStatusCode());
        $this->loginPos(['ROLE_USER_POS', 'ROLE_POS_MANAGER']);
        foreach ([['GET', ''], ['GET', '/constats'], ['GET', '/constats/1'], ['PUT', '/constats/1/decision'], ['GET', '/regles'], ['PUT', '/regles/CASH_DISCREPANCY'], ['GET', '/reglages'], ['PUT', '/reglages'], ['POST', '/analyse']] as [$method, $path]) {
            $this->assertSame(403, $this->call($method, self::BASE . $path, $method === 'GET' ? null : [], $this->posToken)->getStatusCode(), "$method $path : responsable de caisse refusé");
            $this->assertSame(401, $this->call($method, self::BASE . $path, $method === 'GET' ? null : [], null)->getStatusCode(), "$method $path : anonyme refusé");
        }

        // Mode demo de la plateforme : rien n'est gardé
        $this->flags(false, false);
        $this->assertSame(200, $this->admin('GET', self::BASE . '?analyse=0')->getStatusCode());
        // Mode live : la gestion d'abord, puis le module, chacun avec SA clé dans `feature`
        static::getContainer()->get(PlatformModeService::class)->setMode('live', 'test');
        $this->flags(true, false);
        $r = $this->json($this->admin('GET', self::BASE . '?analyse=0'), 403);
        $this->assertSame(['MODULE_INACTIVE', 'MODULE_INACTIVE', 'loss_prevention', 'module.loss_prevention'], [$r['error'], $r['code'], $r['module'], $r['feature']]);
        $this->assertArrayHasKey('requestable', $r);
        $this->assertStringContainsString('Prévention des pertes', $r['message']);
        $this->assertSame(403, $this->call('POST', self::BASE . '/analyse', [], null)->getStatusCode(), 'avant le pare-feu : même refus sans jeton');
        foreach (['/api/gestion/%70ertes/constats', '/api/gestion/pertes%2Fconstats', '/api/gestion/%70%65rtes'] as $encoded) {
            $answer = $this->call('GET', $encoded, null, $this->adminToken);
            $this->assertNotSame(200, $answer->getStatusCode(), "$encoded : un chemin encodé ne contourne pas la garde");
            if ($answer->getStatusCode() === 403) {
                $this->assertSame('module.loss_prevention', json_decode((string) $answer->getContent(), true)['feature'] ?? null, $encoded);
            }
        }
        $this->assertSame([403, 'module.loss_prevention'], [$this->call('GET', '/api/gestion/%70ertes/constats', null, $this->adminToken)->getStatusCode(),
            json_decode((string) $this->client->getResponse()->getContent(), true)['feature'] ?? null], 'la lettre encodée est décodée comme par le routeur');
        $this->assertSame(200, $this->admin('GET', '/api/gestion/ia')->getStatusCode(), 'le reste de la gestion n\'est pas fermé par ce module');
        $this->flags(false, true);
        $this->assertSame('app.management', $this->json($this->admin('GET', self::BASE . '?analyse=0'), 403)['feature'], 'sans la gestion, c\'est la gestion qui refuse');
        $this->flags(true, true);
        $this->assertSame(200, $this->admin('GET', self::BASE . '?analyse=0')->getStatusCode());
        // Un refus n'écrit RIEN : ni ligne de réglages, ni journal du module, ni constat (le web lit ce refus en fond pour connaître l'essai offert)
        $this->flags(true, false);
        $settingsRow = $this->db->fetchAssociative('SELECT * FROM loss_setting ORDER BY id LIMIT 1');
        $this->db->executeStatement('DELETE FROM loss_setting');
        $written = fn () => [(int) $this->db->fetchOne('SELECT COUNT(*) FROM loss_setting'), (int) $this->db->fetchOne('SELECT COUNT(*) FROM loss_journal'), (int) $this->db->fetchOne('SELECT COUNT(*) FROM loss_finding'),
            (int) $this->db->fetchOne('SELECT COUNT(*) FROM loss_evidence'), (int) $this->db->fetchOne('SELECT COUNT(*) FROM loss_rule')];
        $before = $written();
        foreach ([['GET', '?analyse=0'], ['GET', ''], ['GET', '/constats'], ['GET', '/reglages'], ['POST', '/analyse'], ['PUT', '/reglages']] as [$method, $path]) {
            $this->assertSame(403, $this->admin($method, self::BASE . $path, $method === 'GET' ? null : ['staffNoticeConfirmed' => true])->getStatusCode(), "$method $path");
        }
        $this->assertSame($before, $written(), 'un 403 MODULE_INACTIVE ne crée ni ligne de réglages, ni entrée du journal, ni constat');
        // En mode demo sans le droit, les écrans répondent : une LECTURE n'écrit rien non plus (pas même la ligne de réglages par défaut)
        static::getContainer()->get(PlatformModeService::class)->setMode('demo', 'test');
        foreach (['?analyse=0', '', '/constats', '/regles', '/reglages'] as $path) {
            $this->assertSame(200, $this->admin('GET', self::BASE . $path)->getStatusCode(), "GET $path");
        }
        $this->assertSame($before, $written(), 'une lecture sans le droit du module n\'écrit rien');
        static::getContainer()->get(PlatformModeService::class)->setMode('live', 'test');
        if ($settingsRow !== false) { // la ligne de réglages du site de test est remise telle quelle
            $this->db->insert('loss_setting', $settingsRow, array_map(fn ($v) => is_bool($v) ? \Doctrine\DBAL\ParameterType::BOOLEAN : ($v === null ? \Doctrine\DBAL\ParameterType::NULL : \Doctrine\DBAL\ParameterType::STRING), $settingsRow));
        }
        $this->flags(true, true);
        // Le registre du site porte la clé
        $features = $this->json($this->admin('GET', '/api/site/features'));
        $this->assertStringContainsString('module.loss_prevention', json_encode($features));
        $this->flags(false, false);
    }

    public function testEvidenceAndJournalCannotBeAlteredAndTheTimerCommandOnlyRunsWhereTheModuleIsGranted(): void
    {
        $this->drain();
        $this->loginPos(['ROLE_USER_POS']);
        $this->openSession();
        $this->closeWith(-1500, 'Pour la preuve');
        $this->json($this->admin('POST', self::BASE . '/analyse'));
        foreach (['UPDATE loss_evidence SET snapshot = \'{}\'', 'DELETE FROM loss_evidence', 'TRUNCATE loss_evidence', "UPDATE loss_journal SET type = 'X'", 'DELETE FROM loss_journal', 'TRUNCATE loss_journal'] as $sql) {
            try {
                $this->db->executeStatement($sql);
                $this->fail("$sql aurait dû être refusé");
            } catch (\Doctrine\DBAL\Exception $e) {
                $this->assertStringContainsString('inaltérable', $e->getMessage());
            }
        }

        // Commande du minuteur : rien sur un site sans le module, même en mode demo ; un passage quand le module est accordé
        $command = new CommandTester((new Application(static::$kernel))->find('app:pertes:analyser'));
        $this->flags(true, false);
        // `--sans-courriel` : ce test ne porte pas sur le courriel du jour, qui part ou non selon l'heure du magasin ; sans l'option, ses
        // écritures (réservation du jour, ligne au journal, courriel) dépendraient de l'heure à laquelle la suite tourne
        $command->execute(['--tenant' => MV_TEST_TENANT_CODE, '--sans-courriel' => true]);
        $this->assertStringContainsString('0 site(s) analysé(s)', $command->getDisplay());
        $this->openSession();
        $report = $this->closeWith(-2500, 'Pour la commande');
        $this->flags(true, true);
        $digests = fn (): array => [(string) $this->db->fetchOne('SELECT COALESCE(last_digest_day::text, \'\') FROM loss_setting'), (int) $this->db->fetchOne("SELECT COUNT(*) FROM loss_journal WHERE type = 'DIGEST_SENT'")];
        $digestsBefore = $digests();
        $command->execute(['--tenant' => MV_TEST_TENANT_CODE, '--sans-courriel' => true]);
        $this->assertStringContainsString('1 site(s) analysé(s), 1 constat(s) ouvert(s), 0 site(s) en erreur', $command->getDisplay());
        $this->assertSame($digestsBefore, $digests(), 'avec --sans-courriel : ni réservation du jour, ni courriel, quelle que soit l\'heure');
        $this->assertEmailCount(0);
        $this->provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $this->assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM loss_finding WHERE dedup_key = ?', ['CASH_DISCREPANCY:z_report:' . $report['id']]));
        $this->flags(false, false);
    }
}
