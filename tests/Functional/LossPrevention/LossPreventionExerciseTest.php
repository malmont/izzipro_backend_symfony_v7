<?php

namespace App\Tests\Functional\LossPrevention;

use App\LossPrevention\Entity\LossFinding;
use App\LossPrevention\Services\LossExerciseService;
use App\Services\Platform\PlatformModeService;

/**
 * Prévention des pertes, lot EX-1 « mode exercice » (décisions du propriétaire du 14/11/2026) : un constat FICTIF que le gérant lance et efface
 * lui-même. Ce que ces tests tiennent : rien n'est lu ni écrit dans la caisse ; aucune personne réelle ; aucun chiffre réel ne compte un
 * exercice ; vrais constats et exercices ne se croisent sur aucune route ; les routes d'exercice s'ouvrent sans le droit du module et rien
 * d'autre de la section ne s'ouvre ; jouable avant la notice aux salariés ; plafond de 30 ; effacement manuel ; et la BASE refuse elle-même
 * d'effacer quoi que ce soit d'un vrai constat.
 */
class LossPreventionExerciseTest extends LossPreventionTestCase
{
    private const EX = self::BASE . '/exercices';
    /** Tables de la caisse : un exercice n'y écrit rien */
    private ?int $entitlement = null;
    private const CASH_TABLES = ['pos_audit_log', '"order"', 'order_items', 'payments', 'transaction_caisse', 'caisse', 'cash_register_report', 'pos_return'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->json($this->admin('DELETE', self::EX)); // aucun exercice laissé par un test précédent
    }

    protected function tearDown(): void
    {
        if ($this->entitlement !== null) { // droit d'usage accordé par un test : retiré, le site de test retrouve son état
            static::getContainer()->get(\App\Services\Platform\EntitlementService::class)->cancel($this->entitlement, 'test', 'PERTES-EXERCICE');
            $this->provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
            $this->entitlement = null;
        }
        $this->flags(true, $this->db->fetchOne('SELECT is_loss_prevention_active FROM entreprise ORDER BY id LIMIT 1') === true);
        $this->admin('DELETE', self::EX); // sans vérification : un test en échec ne doit pas en cacher la cause
        parent::tearDown();
    }

    public function testEveryScenarioGivesAFindingReadLikeARealOneWithoutTouchingTheCashOrNamingARealPerson(): void
    {
        $this->drain();
        $cashier = $this->loginPos(['ROLE_USER_POS']);
        $this->place('US', 'FL'); // les variantes par personne y sont permises
        $cash = fn (): array => array_map(fn (string $t) => (int) $this->db->fetchOne("SELECT COUNT(*) FROM $t"), self::CASH_TABLES);
        $before = $cash();
        $analysis = fn (): array => [$this->db->fetchOne('SELECT last_analysis_at FROM loss_setting'), $this->db->fetchOne('SELECT cursor_sequence FROM loss_setting'),
            (int) $this->db->fetchOne("SELECT COUNT(*) FROM loss_journal WHERE type = 'ANALYSIS_STARTED'")];
        $analysisBefore = $analysis();
        $lastNumber = (int) (json_decode((string) ($this->db->fetchOne("SELECT data FROM loss_journal WHERE type = 'EXERCISE_STARTED' ORDER BY id DESC LIMIT 1") ?: '{}'), true)['number'] ?? 0);

        $overview = $this->json($this->admin('GET', self::EX . '?locale=fr'));
        $this->assertSame(['scenarios', 'findings', 'count', 'limit', 'remaining', 'daily', 'notice', 'screen'], array_keys($overview));
        $this->assertSame(['limit', 'remaining', 'resetAt'], array_keys($overview['daily']));
        $this->assertGreaterThan(gmdate('c'), $overview['daily']['resetAt'], 'le compte du jour repart au début de la journée suivante du magasin');
        $this->assertSame([array_keys(LossExerciseService::SCENARIOS), [], 0, 30, 30, 'pertes.exercices'],
            [array_column($overview['scenarios'], 'code'), $overview['findings'], $overview['count'], $overview['limit'], $overview['remaining'], $overview['screen']]);
        foreach ($overview['scenarios'] as $s) {
            $this->assertSame(['code', 'label', 'description', 'learn', 'rule', 'personPattern', 'available'], array_keys($s));
            $this->assertTrue($s['available'], $s['code']);
            foreach (['label', 'description', 'learn'] as $text) {
                $this->assertStringNotContainsString('exercise.', $s[$text], "{$s['code']}.$text : texte rédigé, pas une clé");
                $this->assertGreaterThan(10, mb_strlen($s[$text]));
            }
        }
        $englishOverview = $this->json($this->admin('GET', self::EX . '?locale=en'));
        $english = array_column($englishOverview['scenarios'], null, 'code');
        $this->assertSame('Cash shortage at closing', $english['cash_shortage']['label']);
        // En anglais (décision du 15/11/2026) : « Practice » pour le mode et l'écran, « exercise » pour l'élément, « Practice cashier » et
        // « Practice manager » pour les deux comptes fictifs ; jamais « drill »
        $this->assertStringStartsWith('An exercise is a fictitious finding', $englishOverview['notice']);
        $translator = static::getContainer()->get('translator');
        $this->assertSame(['Practice cashier', 'Practice manager', '[Exercise] Sujet', 'Exercise (loss prevention)', 'Loss prevention practice'], [
            $translator->trans('exercise.persons.cashier', [], 'pertes', 'en'), $translator->trans('exercise.persons.manager', [], 'pertes', 'en'),
            $translator->trans('loss_digest.exercise_subject', ['%subject%' => 'Sujet'], 'emails', 'en'), $translator->trans('exercice-pertes.term', [], 'lexique', 'en'),
            $translator->trans('console.screens.pertes_exercices', [], 'gestion', 'en')]);
        foreach (['pertes', 'emails', 'ecrans', 'lexique', 'gestion'] as $domain) {
            $this->assertDoesNotMatchRegularExpression('/\bdrills?\b/i', (string) file_get_contents(static::getContainer()->getParameter('kernel.project_dir') . "/translations/$domain.en.yaml"), "$domain.en.yaml : « practice », pas « drill »");
        }
        $this->assertSame(['1 new finding to check.', '3 new findings to check.', '1 new finding is waiting for you to check.'], [
            $translator->trans('tiles.loss_findings_no_amount.text', ['%count%' => 1], 'gestion', 'en'), $translator->trans('tiles.loss_findings_no_amount.text', ['%count%' => 3], 'gestion', 'en'),
            $translator->trans('loss_digest.message_no_amount', ['%count%' => 1], 'emails', 'en')]);

        $fictitious = ['Caissier d\'exercice', 'Responsable d\'exercice'];
        foreach (array_keys(LossExerciseService::SCENARIOS) as $n => $code) {
            $out = $this->json($this->admin('POST', self::EX . '?locale=fr', ['scenario' => $code]), 201);
            $this->assertSame(['finding', 'alertPreview', 'testEmail', 'remaining', 'screen'], array_keys($out), $code);
            $f = $out['finding'];
            $rule = LossExerciseService::SCENARIOS[$code];
            // Le numéro : l'opposé du numéro de l'exercice, qui se suit ; aucun numéro de vrai constat n'est consommé
            $this->assertSame([true, -($lastNumber + $n + 1), 'open', $rule['rule'], $rule['person'], true, false, null, null],
                [$f['exercise'], $f['number'], $f['status'], $f['rule']['code'], $f['personPattern'], $f['isNew'], $f['backfill'], $f['cashRegister'], $f['sessionId']], $code);
            $this->assertNotEmpty($f['evidence'], $code);
            $this->assertTrue($f['evidenceIntact'], $code);
            $this->assertNotSame('', $f['summary']);
            // Aucune personne réelle : seuls les deux comptes fictifs, aux identifiants négatifs. Une règle par poste et par jour ne
            // liste personne (lot L3-b2b)
            // ni une règle de la sortie, par pièce ou par article (lot L4-b)
            match ($code) {
                'unread_sold' => $this->assertSame([[], 'cash_register'], [$f['onDuty'], $f['subject']['type']], $code),
                'exit_without_sale' => $this->assertSame([[], 'piece', null], [$f['onDuty'], $f['subject']['type'], $f['onDutyNotice']], $code),
                'exit_covered' => $this->assertSame([[], 'product_variant', null], [$f['onDuty'], $f['subject']['type'], $f['onDutyNotice']], $code),
                // ni une règle du dépôt, par zone et par jour (lot L4-c)
                'stockroom_not_shelved', 'back_door', 'out_of_hours' => $this->assertSame([[], 'zone', null], [$f['onDuty'], $f['subject']['type'], $f['onDutyNotice']], $code),
                // ni le comptage, par comptage (lot L4-d)
                'piece_missing' => $this->assertSame([[], 'count', null], [$f['onDuty'], $f['subject']['type'], $f['onDutyNotice']], $code),
                default => $this->assertNotEmpty($f['onDuty'], $code),
            };
            foreach ($f['onDuty'] as $p) {
                $this->assertLessThan(0, $p['id'], $code);
                $this->assertContains($p['name'], $fictitious, $code);
            }
            if ($f['subject']['type'] === 'user_window') {
                $this->assertSame([-1, 'Caissier d\'exercice'], [$f['subject']['id'], $f['subject']['name']], $code);
            }
            // Les preuves ne renvoient à aucune opération de la caisse, et tout est rédigé
            foreach ($f['evidence'] as $i => $e) {
                $this->assertSame(['exercise', $i + 1, null, null], [$e['ref']['type'], $e['ref']['id'], $e['ref']['sequence'], $e['ref']['hash']], $code);
                $this->assertNotEmpty($e['lines'], $code);
            }
            $text = (string) json_encode($out, JSON_UNESCAPED_UNICODE);
            foreach (['exercise.', 'evidence.', 'summary.', 'rules.', $this->mark, (string) $cashier->getEmail()] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $text, "$code : ni clé de texte, ni nom, ni adresse d'un compte du site");
            }
            // L'alerte telle qu'elle apparaîtrait, montrée à l'écran ; aucun courriel sans demande
            $this->assertSame(['tile', 'email'], array_keys($out['alertPreview']));
            $this->assertSame('Prévention des pertes', $out['alertPreview']['tile']['title']);
            $this->assertStringStartsWith('1 nouveau constat à vérifier', $out['alertPreview']['tile']['text']);
            $this->assertSame('Prévention des pertes : 1 nouveau constat à vérifier', $out['alertPreview']['email']['subject']);
            // Un constat sans montant (tiroir ouvert sans vente, validations refusées) n'annonce pas « 0,00 $ en jeu »
            if ($f['amountAtStake'] === null) {
                $this->assertSame(['1 nouveau constat à vérifier.', '1 nouveau constat attend votre vérification.'], [$out['alertPreview']['tile']['text'], $out['alertPreview']['email']['message']], $code);
                $this->assertStringNotContainsString('0,00', (string) json_encode($out['alertPreview'], JSON_UNESCAPED_UNICODE), $code);
            } else {
                $this->assertMatchesRegularExpression('/^1 nouveau constat à vérifier, [\d\x{A0}\x{202F} ,]+[\x{A0}\x{202F} ]\$ en jeu\.$/u', $out['alertPreview']['tile']['text'], $code);
                $this->assertStringContainsString('pour un montant en jeu de ', $out['alertPreview']['email']['message'], $code);
            }
            $this->assertSame(['requested' => false, 'sent' => false, 'reason' => null], $out['testEmail']);
            $this->assertSame(30 - $n - 1, $out['remaining']);
        }
        // Les chiffres d'un scénario suivent les seuils du site : un manque juste au-dessus du seuil (5,00 $ par défaut), une ouverture de plus que le maximum
        $byRule = fn (string $scenario) => $this->json($this->admin('GET', self::EX . '/' . $this->db->fetchOne('SELECT id FROM loss_finding WHERE dedup_key LIKE ?', ['EXERCISE:' . $scenario . ':%'])));
        $shortage = $byRule('cash_shortage');
        $this->assertSame([1000, 'z_report'], [$shortage['amountAtStake'], $shortage['evidence'][0]['kind']]);
        $this->assertMatchesRegularExpression('/manque/u', $shortage['summary']);
        $this->assertStringStartsWith('Exercice : ', array_column($shortage['evidence'][0]['lines'], 'value', 'label')['Motif saisi à la fermeture']);
        $this->assertCount(4, $byRule('drawer_no_sale')['evidence'], 'maximum 3 : quatre ouvertures');
        $this->assertSame('Article d\'exercice', array_column($byRule('price_override')['evidence'][0]['lines'], 'value', 'label')['Article']);
        $this->assertSame('Practice cashier', $this->json($this->admin('GET', self::EX . '/' . $shortage['id'] . '?locale=en'))['onDuty'][0]['name']);

        // Rien dans la caisse, ni dans son journal ; l'analyse réelle n'a pas été lancée
        $this->assertSame($before, $cash(), 'aucune ligne écrite dans les tables de la caisse');
        $this->assertSame($analysisBefore, $analysis(), 'ni analyse, ni curseur déplacé');
        // La liste de l'écran : les dix-neuf (un scénario par règle ou variante ; le treizième est venu avec le lot L3-b2b, les deux
        // suivants avec le lot L4-b, les trois suivants avec le lot L4-c, le dernier avec le lot L4-d), sans aucun nom
        $after = $this->json($this->admin('GET', self::EX));
        $this->assertSame([19, LossExerciseService::DAILY_LIMIT - 19, 19], [$after['count'], $after['remaining'], count($after['findings'])]); // `remaining` : lancements restants du jour
        $this->assertStringNotContainsString('exercice"', (string) json_encode($after['findings'], JSON_UNESCAPED_UNICODE), 'la liste ne porte pas les noms fictifs non plus');
        $this->assertSame(array_fill(0, 19, true), array_column($after['findings'], 'exercise'));
    }

    public function testNoRealFigureEverCountsAnExerciseAndTheTwoKindsNeverCrossOnARoute(): void
    {
        $this->drain();
        $this->loginPos(['ROLE_USER_POS']);
        $this->place('US', 'FL');
        $this->flags(true, true);
        // le droit d'usage du module, accordé le temps du test : sans lui le cheminement ne porte aucune étape du module à comparer
        $this->entitlement = (int) static::getContainer()->get(\App\Services\Platform\EntitlementService::class)->grant(MV_TEST_TENANT_CODE, 'module.loss_prevention', 'manual', null, 'test', 'PERTES-EXERCICE', null, null, false)['id'];
        $this->provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        // Un exercice existe déjà quand naît le vrai constat : son numéro négatif ne compte pas dans la suite des vrais numéros
        $this->json($this->admin('POST', self::EX, ['scenario' => 'refund_cash_for_card']), 201);
        $lastRealNumber = (int) $this->db->fetchOne('SELECT COALESCE(MAX(number), 0) FROM loss_finding WHERE exercise = false');
        // Un VRAI constat : un manque à la fermeture
        $this->openSession();
        $report = $this->closeWith(-1300, 'Vrai constat');
        $this->assertSame(1, $this->json($this->admin('POST', self::BASE . '/analyse'))['opened']);
        $real = (int) $this->db->fetchOne('SELECT id FROM loss_finding WHERE dedup_key = ?', ['CASH_DISCREPANCY:z_report:' . $report['id']]);
        $realNumber = (int) $this->db->fetchOne('SELECT number FROM loss_finding WHERE id = ?', [$real]);
        $this->assertSame($lastRealNumber + 1, $realNumber, 'le vrai constat prend le numéro qui suit le dernier VRAI constat, même après un exercice');

        $findings = $this->provider->getEntityManager()->getRepository(LossFinding::class);
        $figures = function () use ($findings): array {
            $summary = $this->json($this->admin('GET', self::BASE . '?analyse=0'));
            $this->admin('GET', self::BASE . '/constats?limit=100');
            $total = (int) $this->client->getResponse()->headers->get('X-Total-Count');
            $tile = array_values(array_filter($this->json($this->call('GET', '/api/gestion/accueil/vignettes?locale=fr', null, $this->adminToken)), fn (array $t) => $t['id'] === 'loss-findings'))[0] ?? null;
            $preview = $this->json($this->admin('POST', self::BASE . '/regles/apercu', ['code' => 'CASH_DISCREPANCY', 'params' => ['minAmount' => 100]]));
            $readiness = array_column(array_values(array_filter($this->json($this->call('GET', '/api/site/readiness?locale=fr', null, $this->adminToken))['steps'], fn (array $s) => str_starts_with((string) $s['key'], 'loss.'))), 'status', 'key');

            return [
                'synthèse : comptes' => $summary['counts'], 'synthèse : montant en jeu' => $summary['amountAtStake'],
                'liste par défaut' => $total,
                'vignette' => $tile === null ? null : [$tile['text'], $tile['sources'][0]['value'], $tile['sources'][1]['value'], $tile['signature'] ?? null],
                'courriel du jour : nés depuis le début' => $findings->bornAfter(0),
                'constats à traiter' => $findings->activeCount(),
                'aperçu d\'un seuil' => [$preview['current'], $preview['wouldOpen']],
                'cheminement' => $readiness,
                'règle par personne : fenêtre du compte fictif' => $findings->latestForPerson('CASH_DISCREPANCY', -1, new \DateTimeImmutable('-90 days'), new \DateTimeImmutable('+1 day')),
            ];
        };
        $before = $figures();
        $this->assertSame(1, $before['liste par défaut'] >= 1 ? 1 : 0);
        $this->assertNotNull($before['vignette'], 'la vignette existe : la comparer avant / après vérifie bien son filtre');
        $this->assertNotSame([], $before['cheminement']);

        // Six exercices, dont un par personne et un sur la même règle que le vrai constat
        $exercises = [];
        foreach (['cash_shortage', 'drawer_no_sale', 'void_series', 'refund_no_receipt', 'price_override', 'cash_shortage_series'] as $code) {
            $exercises[$code] = (int) $this->json($this->admin('POST', self::EX, ['scenario' => $code]), 201)['finding']['id'];
        }
        $after = $figures();
        foreach ($before as $name => $value) {
            $this->assertSame($value, $after[$name], "$name : inchangé par six constats d'exercice");
        }
        // Le numéro des vrais constats ne bouge pas : le suivant est le numéro du dernier vrai constat plus un
        $this->openSession();
        $second = $this->closeWith(-1400, 'Second vrai constat');
        $this->json($this->admin('POST', self::BASE . '/analyse'));
        $this->assertSame($realNumber + 1, (int) $this->db->fetchOne('SELECT number FROM loss_finding WHERE dedup_key = ?', ['CASH_DISCREPANCY:z_report:' . $second['id']]));

        // Les quatre croisements : un exercice est inconnu des routes ordinaires, un vrai constat est inconnu des routes d'exercice
        $exercise = $exercises['cash_shortage'];
        $this->assertSame('FINDING_NOT_FOUND', $this->json($this->admin('GET', self::BASE . '/constats/' . $exercise), 404)['error']);
        $this->assertSame('FINDING_NOT_FOUND', $this->json($this->admin('PUT', self::BASE . '/constats/' . $exercise . '/decision', ['status' => 'watch']), 404)['error']);
        $this->assertSame('FINDING_NOT_FOUND', $this->json($this->admin('GET', self::EX . '/' . $real), 404)['error']);
        $this->assertSame('FINDING_NOT_FOUND', $this->json($this->admin('PUT', self::EX . '/' . $real . '/decision', ['status' => 'watch']), 404)['error']);
        $this->assertSame(['open', 'open'], [$this->db->fetchOne('SELECT status FROM loss_finding WHERE id = ?', [$exercise]), $this->db->fetchOne('SELECT status FROM loss_finding WHERE id = ?', [$real])], 'aucune des deux décisions croisées n\'a rien écrit');
        $this->admin('GET', self::BASE . '/constats?limit=100');
        $this->assertSame([], array_values(array_intersect($exercises, array_column($this->json($this->client->getResponse()), 'id'))), 'la liste ne mêle jamais un exercice aux vrais constats');

        // Un exercice se lit et se décide comme un vrai, par ses propres routes : note exigée, historique gardé
        $this->assertSame('REQUIRED', $this->json($this->admin('PUT', self::EX . '/' . $exercise . '/decision', ['status' => 'explained']), 422)['fields']['note']);
        $decided = $this->json($this->admin('PUT', self::EX . '/' . $exercise . '/decision', ['status' => 'explained', 'note' => 'Erreur de monnaie, vue avec la personne.']));
        $this->assertSame([true, 'explained', 'Expliqué', false, 'Erreur de monnaie, vue avec la personne.', 1], [$decided['exercise'], $decided['status'], $decided['statusLabel'], $decided['isNew'], $decided['decision']['note'], count($decided['history'])]);
        $this->assertSame('pertes.exercices', $decided['screen'], 'la fiche d\'un exercice renvoie à l\'écran des exercices, jamais à la fiche ordinaire');

        // Tout effacer : les exercices partent avec leurs preuves et leurs lignes de journal, les vrais constats restent entiers
        $realEvidence = (int) $this->db->fetchOne('SELECT COUNT(*) FROM loss_evidence e JOIN loss_finding f ON f.id = e.finding_id WHERE f.exercise = false');
        $realJournal = (int) $this->db->fetchOne('SELECT COUNT(*) FROM loss_journal j JOIN loss_finding f ON f.id = j.finding_id WHERE f.exercise = false');
        $this->assertSame(['deleted' => 7], $this->json($this->admin('DELETE', self::EX)));
        $this->assertSame(['deleted' => 0], $this->json($this->admin('DELETE', self::EX)), 'rien à effacer : rien ne se passe');
        $this->assertSame([0, 0, 0], [(int) $this->db->fetchOne('SELECT COUNT(*) FROM loss_finding WHERE exercise'),
            (int) $this->db->fetchOne('SELECT COUNT(*) FROM loss_evidence WHERE finding_id IN (' . implode(',', $exercises) . ')'),
            (int) $this->db->fetchOne('SELECT COUNT(*) FROM loss_journal WHERE finding_id IN (' . implode(',', $exercises) . ')')]);
        $this->assertSame([$realEvidence, $realJournal], [(int) $this->db->fetchOne('SELECT COUNT(*) FROM loss_evidence e JOIN loss_finding f ON f.id = e.finding_id WHERE f.exercise = false'),
            (int) $this->db->fetchOne('SELECT COUNT(*) FROM loss_journal j JOIN loss_finding f ON f.id = j.finding_id WHERE f.exercise = false')]);
        $this->assertSame(200, $this->admin('GET', self::BASE . '/constats/' . $real)->getStatusCode());
        // Le journal du module garde pour toujours « exercice lancé » et « exercices effacés » : des nombres et un code, sans constat rattaché
        $kept = $this->db->fetchAllAssociative("SELECT type, finding_id, data FROM loss_journal WHERE type IN ('EXERCISE_STARTED', 'EXERCISES_DELETED') ORDER BY id DESC LIMIT 7");
        $this->assertSame(['EXERCISES_DELETED', null, ['count' => 7]], [$kept[0]['type'], $kept[0]['finding_id'], json_decode((string) $kept[0]['data'], true)]);
        $this->assertSame(['number', 'scenario'], array_keys(json_decode((string) $kept[1]['data'], true)));
    }

    public function testExerciseRoutesOpenWithoutTheModuleRightBeforeTheNoticeAndNothingElseOpens(): void
    {
        $this->drain();
        $this->loginPos(['ROLE_USER_POS']);
        // Québec, notice NON confirmée, mode « live », gestion accordée, module NON accordé, et aucune ligne de réglages
        $this->place('CA', 'QC');
        static::getContainer()->get(PlatformModeService::class)->setMode('live', 'test');
        $this->flags(true, false);
        $this->db->executeStatement('DELETE FROM loss_setting');
        try {
            $overview = $this->json($this->admin('GET', self::EX));
            // Les variantes par personne ne sont pas proposées là où la notice est exigée
            $available = array_column($overview['scenarios'], 'available', 'code');
            $this->assertSame([true, false, false, false], [$available['cash_shortage'], $available['cash_shortage_series'], $available['refund_series'], $available['discount_series']]);
            $refused = $this->json($this->admin('POST', self::EX, ['scenario' => 'refund_series']), 409);
            $this->assertSame(['EXERCISE_SCENARIO_NOT_AVAILABLE', 'refund_series'], [$refused['error'], $refused['scenario']]);
            // L'exercice se joue : sans le droit, avant la notice
            $started = $this->json($this->admin('POST', self::EX, ['scenario' => 'cash_shortage']), 201);
            $id = (int) $started['finding']['id'];
            $this->assertTrue($started['finding']['exercise']);
            $this->assertSame($id, $this->json($this->admin('GET', self::EX . '/' . $id))['id']);
            $this->assertSame('watch', $this->json($this->admin('PUT', self::EX . '/' . $id . '/decision', ['status' => 'watch']))['status']);
            // le même chemin, encodé : la même route, la même ouverture
            $this->assertSame(200, $this->admin('GET', '/api/gestion/pertes/%65xercices')->getStatusCode());
            $this->assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM loss_setting'), 'aucune ligne de réglages créée sur un site qui n\'a pas le module');

            // RIEN d'autre de la section ne s'ouvre, y compris pour le constat d'exercice par les routes ordinaires
            $closed = [['GET', ''], ['GET', '/constats'], ['GET', '/constats/' . $id], ['PUT', '/constats/' . $id . '/decision'], ['GET', '/regles'], ['POST', '/regles/apercu'],
                ['PUT', '/regles/CASH_DISCREPANCY'], ['GET', '/reglages'], ['PUT', '/reglages'], ['POST', '/analyse']];
            foreach ($closed as [$method, $path]) {
                $response = $this->admin($method, self::BASE . $path, $method === 'GET' ? null : ['status' => 'watch', 'staffNoticeConfirmed' => true]);
                $this->assertSame(403, $response->getStatusCode(), "$method $path : fermé sans le droit du module");
                $refusal = $this->json($response, 403);
                $this->assertSame(['MODULE_INACTIVE', 'module.loss_prevention'], [$refusal['code'], $refusal['feature']], "$method $path");
            }
            // Ce qui ressemble à une route d'exercice sans en être une n'ouvre rien : la liste des routes ouvertes est exacte (méthode et
            // chemin), ce n'est pas un préfixe ; le routeur ou la garde refuse, et rien n'est écrit
            $state = fn (): array => [(int) $this->db->fetchOne('SELECT COUNT(*) FROM loss_finding'), (string) $this->db->fetchOne('SELECT status FROM loss_finding WHERE id = ?', [$id])];
            $before = $state();
            foreach ([['GET', '/exercices/'], ['GET', '/exercices/' . $id . '/decision'], ['POST', '/exercices/' . $id], ['PUT', '/exercices'], ['DELETE', '/exercices/' . $id], ['GET', '/exercices/abc'],
                ['PATCH', '/exercices'], ['GET', '/exercices-anciens'], ['GET', '/exercices%0A'], ['POST', '/exercices%0A'], ['PUT', '/exercices/' . $id . '/decision/encore'], ['POST', '/exercices/' . $id . '/decision']] as [$method, $path]) {
                $status = $this->admin($method, self::BASE . $path, $method === 'GET' ? null : ['status' => 'explained', 'note' => 'x', 'scenario' => 'cash_shortage'])->getStatusCode();
                $this->assertContains($status, [403, 404, 405], "$method $path : ni servi, ni exécuté");
                $this->assertSame($before, $state(), "$method $path : rien n'est écrit");
            }
            foreach (\App\LossPrevention\LossPreventionModuleSubscriber::OPEN_ROUTES as $method => $patterns) {
                foreach ($patterns as $pattern) {
                    $this->assertMatchesRegularExpression('/^#\^\/api\/gestion\/pertes\/exercices[^#$]*\\\\z#$/', $pattern, "$method : chaque route ouverte est ancrée au début et à la fin (\\z, jamais \$), sous /exercices");
                    $this->assertSame(0, preg_match($pattern, "/api/gestion/pertes/exercices\n"), "$method : un saut de ligne final ne passe pas");
                }
            }
            $this->assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM loss_setting'));

            // La garde de la gestion et le rôle d'administrateur restent exigés
            $this->assertSame(401, $this->call('GET', self::EX, null, null)->getStatusCode(), 'sans jeton');
            $this->assertSame(403, $this->call('POST', self::EX, ['scenario' => 'cash_shortage'], $this->posToken)->getStatusCode(), 'un caissier');
            $this->flags(false, false);
            $noManagement = $this->admin('GET', self::EX);
            $this->assertSame(403, $noManagement->getStatusCode());
            $this->assertSame('app.management', $this->json($noManagement, 403)['feature'], 'sans la gestion : la garde de la gestion répond, avec sa clé');
            $this->flags(true, false);
            $this->assertSame(['deleted' => 1], $this->json($this->admin('DELETE', self::EX)));
        } finally {
            $this->db->executeStatement('INSERT INTO loss_setting (retention_findings_months, retention_confirmed_months) VALUES (24, 60) ON CONFLICT DO NOTHING');
        }
    }

    public function testTheCeilingOfThirtyAndNumbersThatNeverComeBack(): void
    {
        $last = fn (): int => (int) (json_decode((string) ($this->db->fetchOne("SELECT data FROM loss_journal WHERE type = 'EXERCISE_STARTED' ORDER BY id DESC LIMIT 1") ?: '{}'), true)['number'] ?? 0);
        $start = $last();
        // Corps refusés : rien n'est écrit
        foreach ([[[], 'REQUIRED'], [['scenario' => 'inconnu'], 'INVALID_VALUE'], [['scenario' => ['cash_shortage']], 'REQUIRED']] as [$body, $code]) {
            $this->assertSame($code, $this->json($this->admin('POST', self::EX, $body), 422)['fields']['scenario'], json_encode($body));
        }
        $this->assertSame('MUST_BE_BOOLEAN', $this->json($this->admin('POST', self::EX, ['scenario' => 'cash_shortage', 'sendTestEmail' => 'oui']), 422)['fields']['sendTestEmail']);
        $this->assertSame([0, $start], [(int) $this->db->fetchOne('SELECT COUNT(*) FROM loss_finding WHERE exercise'), $last()]);

        foreach (range(1, 30) as $n) {
            $this->assertSame(30 - $n, $this->json($this->admin('POST', self::EX, ['scenario' => 'drawer_no_sale']), 201)['remaining'], "exercice $n");
        }
        $full = $this->json($this->admin('POST', self::EX, ['scenario' => 'drawer_no_sale']), 409);
        $this->assertSame(['EXERCISE_LIMIT_REACHED', 30], [$full['error'], $full['limit']]);
        $this->assertSame([30, $start + 30, 0], [(int) $this->db->fetchOne('SELECT COUNT(*) FROM loss_finding WHERE exercise'), $last(), $this->json($this->admin('GET', self::EX))['remaining']]);
        $this->assertSame(range(-($start + 1), -($start + 30), -1), array_map('intval', $this->db->fetchFirstColumn('SELECT number FROM loss_finding WHERE exercise ORDER BY id')), 'un numéro par exercice, dans l\'ordre');

        // Effacés, puis un de plus : son numéro continue, il ne reprend jamais celui d'un exercice effacé
        $this->assertSame(['deleted' => 30], $this->json($this->admin('DELETE', self::EX)));
        $this->assertSame(-($start + 31), $this->json($this->admin('POST', self::EX, ['scenario' => 'drawer_no_sale']), 201)['finding']['number']);
    }

    public function testTheDatabaseItselfRefusesToDeleteAnythingOfARealFinding(): void
    {
        $this->drain();
        $this->loginPos(['ROLE_USER_POS']);
        $this->flags(true, true);
        $this->openSession();
        $report = $this->closeWith(-1300, 'Vrai constat, la base');
        $this->json($this->admin('POST', self::BASE . '/analyse'));
        $real = (int) $this->db->fetchOne('SELECT id FROM loss_finding WHERE dedup_key = ?', ['CASH_DISCREPANCY:z_report:' . $report['id']]);
        $this->json($this->admin('GET', self::BASE . '/constats/' . $real)); // une ligne de journal rattachée au vrai constat
        $exercise = (int) $this->json($this->admin('POST', self::EX, ['scenario' => 'cash_shortage']), 201)['finding']['id'];
        $this->json($this->admin('GET', self::EX . '/' . $exercise)); // une ligne de journal rattachée à l'exercice
        $startedLine = (int) $this->db->fetchOne("SELECT id FROM loss_journal WHERE type = 'EXERCISE_STARTED' ORDER BY id DESC LIMIT 1");
        $count = fn (string $table, string $where = 'true'): int => (int) $this->db->fetchOne("SELECT COUNT(*) FROM $table WHERE $where");
        $state = fn (): array => [$count('loss_finding'), $count('loss_evidence'), $count('loss_journal'), (bool) $this->db->fetchOne('SELECT exercise FROM loss_finding WHERE id = ?', [$real])];
        $before = $state();
        $this->assertGreaterThan(0, $count('loss_journal', "finding_id = $real"));
        $this->assertGreaterThan(0, $count('loss_journal', "finding_id = $exercise"));

        /** Une instruction, sans passer par le code du module, dans une transaction avec la porte donnée : doit être REFUSÉE par la base */
        $refused = function (string $sql, ?string $door, string $what) use ($state, $before): void {
            $this->db->beginTransaction();
            try {
                if ($door !== null) {
                    $this->db->executeStatement("SET LOCAL loss.purge = '$door'");
                }
                $this->db->executeStatement($sql);
                $this->db->rollBack();
                $this->fail("$what : la base aurait dû refuser");
            } catch (\Doctrine\DBAL\Exception $e) {
                $this->db->rollBack();
                $this->assertStringContainsString('prévention des pertes', $e->getMessage(), $what);
            }
            $this->assertSame($before, $state(), "$what : rien n'a changé");
        };
        // Avec la porte des exercices : rien d'un VRAI constat ne se supprime
        $refused("DELETE FROM loss_evidence WHERE finding_id = $real", 'exercise', 'preuve d\'un vrai constat');
        $refused("DELETE FROM loss_journal WHERE finding_id = $real", 'exercise', 'ligne de journal d\'un vrai constat');
        $refused("DELETE FROM loss_finding WHERE id = $real", 'exercise', 'vrai constat');
        // On ne marque pas un vrai constat pour le supprimer, et on ne démarque pas un exercice
        $refused("UPDATE loss_finding SET exercise = true WHERE id = $real", 'exercise', 'marquer un vrai constat');
        $refused("UPDATE loss_finding SET exercise = false WHERE id = $exercise", 'exercise', 'démarquer un exercice');
        // A2 (relecture). L'identifiant d'un constat est figé comme sa marque : sinon on déplacerait le vrai constat, on donnerait son
        // identifiant à un exercice, et la porte laisserait effacer ses preuves et son journal
        $refused("UPDATE loss_finding SET id = 900000 WHERE id = $real", null, 'déplacer l\'identifiant d\'un vrai constat');
        $refused("UPDATE loss_finding SET id = 900001 WHERE id = $exercise", 'exercise', 'déplacer l\'identifiant d\'un exercice');
        $refused("UPDATE loss_finding SET id = $real + 900000, exercise = true WHERE id = $real", 'exercise', 'déplacer et marquer d\'un coup');
        $this->assertSame([$real, $exercise], [(int) $this->db->fetchOne('SELECT id FROM loss_finding WHERE id = ?', [$real]), (int) $this->db->fetchOne('SELECT id FROM loss_finding WHERE id = ?', [$exercise])]);
        // Une ligne du journal sans constat (« exercice lancé ») ne se supprime jamais
        $refused("DELETE FROM loss_journal WHERE id = $startedLine", 'exercise', 'ligne de journal sans constat');
        // Sans la porte, ou avec l'ancienne porte de L1-a : même un exercice ne se supprime pas
        foreach ([null, 'on'] as $door) {
            $refused("DELETE FROM loss_evidence WHERE finding_id = $exercise", $door, 'preuve d\'un exercice, porte ' . var_export($door, true));
            $refused("DELETE FROM loss_journal WHERE finding_id = $exercise", $door, 'journal d\'un exercice, porte ' . var_export($door, true));
            $refused("DELETE FROM loss_finding WHERE id = $exercise", $door, 'exercice, porte ' . var_export($door, true));
        }
        // Le mauvais ordre : le constat d'exercice AVANT ses preuves et ses lignes de journal
        $refused("DELETE FROM loss_finding WHERE id = $exercise", 'exercise', 'exercice supprimé avant ses preuves');
        // Modifier ou vider : jamais
        $refused("UPDATE loss_evidence SET snapshot = '{}' WHERE finding_id = $exercise", 'exercise', 'modifier une preuve d\'exercice');
        foreach (['loss_finding', 'loss_evidence', 'loss_journal'] as $table) {
            $refused("TRUNCATE $table", 'exercise', "vider $table");
        }

        // Le bon ordre, par la seule méthode du module : l'exercice part, le vrai constat est intact
        $this->assertSame(['deleted' => 1], $this->json($this->admin('DELETE', self::EX)));
        $this->assertSame([$before[0] - 1, false], [$count('loss_finding'), (bool) $this->db->fetchOne('SELECT exercise FROM loss_finding WHERE id = ?', [$real])]);
        $this->assertTrue($this->json($this->admin('GET', self::BASE . '/constats/' . $real))['evidenceIntact']);
    }

    public function testTheExerciseEmailGoesOnlyToTheAdministratorWhoAskedAndNeverCountsAsTheDailyDigest(): void
    {
        $this->db->executeStatement("UPDATE entreprise SET locale = 'fr'");
        $this->provider->getEntityManager()->clear();
        $this->user(['ROLE_ADMIN'], 'Associe'); // un autre administrateur : il ne reçoit rien
        $adminEmail = (string) $this->db->fetchOne('SELECT email FROM "user" WHERE id = ?', [$this->adminId]);
        $this->db->executeStatement("UPDATE loss_setting SET last_digest_day = '2020-01-01', last_digest_finding_id = 4");
        $out = $this->json($this->admin('POST', self::EX . '?locale=fr', ['scenario' => 'void_quick', 'sendTestEmail' => true]), 201);
        $this->assertSame(['requested' => true, 'sent' => true, 'reason' => null], $out['testEmail']);
        $mails = $this->getMailerMessages();
        $this->assertCount(1, $mails, 'un seul courriel');
        $this->assertSame([$adminEmail], array_map(fn ($a) => $a->getAddress(), $mails[0]->getTo()), 'au seul administrateur qui lance');
        $this->assertSame('[Exercice] Prévention des pertes : 1 nouveau constat à vérifier', (string) $mails[0]->getSubject());
        $body = html_entity_decode((string) $mails[0]->getHtmlBody(), ENT_QUOTES);
        $this->assertStringContainsString('EXERCICE : ce courriel montre l\'alerte', $body, 'le bandeau « Exercice »');
        $this->assertStringContainsString('Montant en jeu', $body);
        $this->assertStringNotContainsString($this->mark, $body, 'aucun nom');
        // Une seule adresse : le lien vers l'écran des exercices de l'espace d'administration
        $link = $this->adminLink('pertes.exercices');
        $this->assertMatchesRegularExpression('#^https://[^/]+/admin/ouvrir\?screen=pertes\.exercices$#', (string) $link);
        $this->assertSame([$link], self::addressesOf((string) $mails[0]->getHtmlBody()));
        $this->assertStringContainsString('Ouvrir les exercices', $body);
        // Il n'entre pas dans le « un par jour » du courriel du jour
        $this->assertSame(['2020-01-01', 4], [(string) $this->db->fetchOne('SELECT last_digest_day FROM loss_setting'), (int) $this->db->fetchOne('SELECT last_digest_finding_id FROM loss_setting')]);
        $line = $this->db->fetchAssociative("SELECT user_id, finding_id, data FROM loss_journal WHERE type = 'EXERCISE_EMAIL_SENT' ORDER BY id DESC LIMIT 1");
        $this->assertSame([$this->adminId, null, ['number']], [(int) $line['user_id'], $line['finding_id'], array_keys(json_decode((string) $line['data'], true))], 'au journal : un numéro, aucune adresse');
        // Sans demande : aucun courriel
        $this->json($this->admin('POST', self::EX, ['scenario' => 'void_quick', 'sendTestEmail' => false]), 201);
        $this->assertEmailCount(0);
    }

    /** Le service du mode exercice monté à la main (noyau en cours, site de test), avec un envoi et des plafonds par jour choisis */
    private function exercises(?\App\Services\EmailConfigurationService\EmailSenderService $mailer = null, int $dailyLimit = 1000000, int $dailyEmailLimit = 1000000): LossExerciseService
    {
        $this->tenant();
        $c = static::getContainer();

        return new LossExerciseService($c->get(\App\Services\TenantEntityManagerProvider::class), $c->get(\App\LossPrevention\Services\LossFindingReader::class),
            $c->get(\App\LossPrevention\Services\LossRuleService::class), $c->get(\App\LossPrevention\Services\LossSite::class), $c->get(\App\LossPrevention\Services\LossTexts::class),
            $mailer ?? $c->get(\App\Services\EmailConfigurationService\EmailSenderService::class), $c->get('translator'), $c->get(\App\Services\TenantConnectionManager::class),
            $c->get(\App\Services\TenantPublicHostResolver::class), new \Psr\Log\NullLogger(), $c->get(\App\Services\Site\AdminSpaceUrl::class), $dailyLimit, $dailyEmailLimit);
    }

    /**
     * Relecture du lot : le plafond de 30 compte les exercices PRÉSENTS ; sans plafond de lancements, « effacer puis relancer » serait
     * illimité sur des routes ouvertes sans le droit du module (chaque lancement laisse une ligne définitive au journal). Décision du chef :
     * 30 lancements par jour du magasin et par site, 409 `EXERCISE_DAILY_LIMIT` avec `resetAt` ; et un plafond de courriels d'exercice.
     */
    public function testLaunchesAndExerciseEmailsAreCappedPerStoreDay(): void
    {
        $this->assertSame([30, 10], [LossExerciseService::DAILY_LIMIT, LossExerciseService::DAILY_EMAIL_LIMIT], 'les plafonds de la production');
        // Hors des tests, le service reçoit ces plafonds-là : ce sont les valeurs par défaut de son constructeur, et AUCUNE configuration
        // autre que celle des tests ne les règle (un plafond relevé ailleurs passerait inaperçu, les tests tournant avec des valeurs relevées)
        $defaults = [];
        foreach ((new \ReflectionClass(LossExerciseService::class))->getConstructor()->getParameters() as $parameter) {
            if (in_array($parameter->getName(), ['dailyLimit', 'dailyEmailLimit'], true)) {
                $defaults[$parameter->getName()] = $parameter->getDefaultValue();
            }
        }
        $this->assertSame(['dailyLimit' => 30, 'dailyEmailLimit' => 10], $defaults);
        $configDir = static::getContainer()->getParameter('kernel.project_dir') . '/config';
        $elsewhere = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($configDir, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if (preg_match('#/(jwt|secrets)(/|$)#', $file->getPathname())) {
                continue; // clés et secrets : jamais lus, même par un test
            }
            if ($file->isFile() && in_array($file->getExtension(), ['yaml', 'yml', 'php', 'xml'], true) && $file->getFilename() !== 'services_test.yaml'
                && preg_match('/dailyLimit|dailyEmailLimit|LossExerciseService/', (string) file_get_contents($file->getPathname()))) {
                $elsewhere[] = $file->getFilename();
            }
        }
        $this->assertSame([], $elsewhere, 'seul config/services_test.yaml règle les plafonds par jour du mode exercice');
        $testConfig = \Symfony\Component\Yaml\Yaml::parseFile($configDir . '/services_test.yaml')['services'][LossExerciseService::class]['arguments'] ?? [];
        $this->assertSame(['$dailyLimit', '$dailyEmailLimit'], array_keys($testConfig), 'et il ne règle rien d\'autre');
        // Le service du conteneur de test porte bien les valeurs relevées (sinon les autres tests buteraient sur le plafond du jour)
        $this->assertGreaterThan(1000, $this->exercises(null, 1000000)->overview('fr')['daily']['limit']);
        $this->assertSame(1000000, $this->json($this->admin('GET', self::EX))['daily']['limit']);
        [$dayStart, $dayEnd] = static::getContainer()->get(\App\Services\CaisseService\StoreClock::class)->range(null, null);
        $launched = fn (): int => (int) $this->db->fetchOne("SELECT COUNT(*) FROM loss_journal WHERE type = 'EXERCISE_STARTED' AND occurred_at >= ?", [$dayStart->format('Y-m-d H:i:s')]);
        $today = $launched();
        // Deux lancements encore permis aujourd'hui, pas un de plus ; effacer ne rend AUCUN lancement
        $limited = fn (): LossExerciseService => $this->exercises(null, $today + 2);
        $this->assertSame(['limit' => $today + 2, 'remaining' => 2, 'resetAt' => $dayEnd->format('c')], $limited()->overview('fr')['daily']);
        $limited()->start(['scenario' => 'drawer_no_sale'], $this->adminId, 'fr');
        $limited()->start(['scenario' => 'drawer_no_sale'], $this->adminId, 'fr');
        $this->assertSame(['deleted' => 2], $limited()->deleteAll($this->adminId));
        $this->assertSame(0, $limited()->overview('fr')['daily']['remaining'], 'effacer puis relancer n\'est pas illimité');
        try {
            $limited()->start(['scenario' => 'drawer_no_sale'], $this->adminId, 'fr');
            $this->fail('le plafond du jour devait refuser');
        } catch (\App\Gestion\Services\GestionConflictException $e) {
            $this->assertSame(['EXERCISE_DAILY_LIMIT', 409, ['limit' => $today + 2, 'resetAt' => $dayEnd->format('c')]], [$e->errorCode, $e->status, $e->details]);
            $this->assertStringNotContainsString($this->mark, $e->getMessage());
        }
        $this->assertSame([$today + 2, 0], [$launched(), (int) $this->db->fetchOne('SELECT COUNT(*) FROM loss_finding WHERE exercise')], 'le refus n\'écrit rien');

        // Courriels d'exercice : un seul encore permis aujourd'hui ; au-delà, l'exercice est lancé mais le courriel ne part pas
        $sentToday = (int) $this->db->fetchOne("SELECT COUNT(*) FROM loss_journal WHERE type = 'EXERCISE_EMAIL_SENT' AND occurred_at >= ?", [$dayStart->format('Y-m-d H:i:s')]);
        $calls = 0;
        $mailer = $this->createMock(\App\Services\EmailConfigurationService\EmailSenderService::class);
        $mailer->method('sendTemplatedEmail')->willReturnCallback(function () use (&$calls): bool {
            ++$calls;

            return true;
        });
        $mailing = fn (): LossExerciseService => $this->exercises($mailer, 1000000, $sentToday + 1);
        $this->assertSame(['requested' => true, 'sent' => true, 'reason' => null], $mailing()->start(['scenario' => 'void_quick', 'sendTestEmail' => true], $this->adminId, 'fr')['testEmail']);
        $second = $mailing()->start(['scenario' => 'void_quick', 'sendTestEmail' => true], $this->adminId, 'fr');
        $this->assertSame([['requested' => true, 'sent' => false, 'reason' => 'DAILY_LIMIT_REACHED'], true, 1], [$second['testEmail'], $second['finding']['exercise'], $calls]);

        // Les deux autres raisons : l'envoi commun ne livre pas (pas de configuration d'envoi) ; le compte n'a pas d'adresse utilisable
        $silent = $this->createMock(\App\Services\EmailConfigurationService\EmailSenderService::class);
        $silent->method('sendTemplatedEmail')->willReturn(false);
        $emails = fn (): int => (int) $this->db->fetchOne("SELECT COUNT(*) FROM loss_journal WHERE type = 'EXERCISE_EMAIL_SENT'");
        $before = $emails();
        $this->assertSame(['requested' => true, 'sent' => false, 'reason' => 'NOT_DELIVERED'], $this->exercises($silent)->start(['scenario' => 'void_quick', 'sendTestEmail' => true], $this->adminId, 'fr')['testEmail']);
        $address = (string) $this->db->fetchOne('SELECT email FROM "user" WHERE id = ?', [$this->adminId]);
        $this->db->executeStatement('UPDATE "user" SET email = ? WHERE id = ?', ['sans-arobase-' . $this->adminId, $this->adminId]);
        try {
            $never = $this->createMock(\App\Services\EmailConfigurationService\EmailSenderService::class);
            $never->expects($this->never())->method('sendTemplatedEmail');
            $this->assertSame(['requested' => true, 'sent' => false, 'reason' => 'NO_RECIPIENT_ADDRESS'], $this->exercises($never)->start(['scenario' => 'void_quick', 'sendTestEmail' => true], $this->adminId, 'fr')['testEmail']);
        } finally {
            $this->db->executeStatement('UPDATE "user" SET email = ? WHERE id = ?', [$address, $this->adminId]);
        }
        $this->assertSame($before, $emails(), 'un courriel non parti n\'est pas noté au journal');
    }

    /**
     * Relecture du lot (A3) : tout ce qui écrit pour un constat d'exercice (lancement, fiche, décision, effacement) tient le verrou des
     * exercices dans une transaction. Pendant qu'une autre connexion le tient, rien ne passe ni n'écrit ; l'effacement ne peut donc pas
     * laisser une ligne de journal d'un exercice disparu.
     */
    public function testEveryWriteForAnExerciseWaitsForTheExerciseLock(): void
    {
        $id = (int) $this->json($this->admin('POST', self::EX, ['scenario' => 'cash_shortage']), 201)['finding']['id'];
        $state = fn (): array => [(int) $this->db->fetchOne('SELECT COUNT(*) FROM loss_finding WHERE exercise'), (int) $this->db->fetchOne('SELECT COUNT(*) FROM loss_journal'),
            (string) $this->db->fetchOne('SELECT status FROM loss_finding WHERE id = ?', [$id])];
        $before = $state();
        $other = \Doctrine\DBAL\DriverManager::getConnection($this->db->getParams()); // une autre connexion tient le verrou, comme un effacement en cours
        try {
            $other->executeQuery('SELECT pg_advisory_lock(' . \App\LossPrevention\Repository\LossFindingRepository::EXERCISE_LOCK . ')');
            $service = $this->exercises();
            // la connexion du SERVICE (celle du noyau en cours, pas celle du test) : sans délai d'attente, l'appel resterait bloqué
            $serviceDb = static::getContainer()->get(\App\Services\TenantEntityManagerProvider::class)->getEntityManager()->getConnection();
            $serviceDb->executeStatement("SET lock_timeout = '300ms'");
            foreach ([
                'fiche' => fn () => $service->detail($id, 'fr', $this->adminId),
                'décision' => fn () => $service->decide($id, ['status' => 'watch'], $this->adminId, 'fr'),
                'lancement' => fn () => $service->start(['scenario' => 'drawer_no_sale'], $this->adminId, 'fr'),
                'effacement' => fn () => $service->deleteAll($this->adminId),
            ] as $what => $write) {
                try {
                    $write();
                    $this->fail("$what : devait attendre le verrou des exercices");
                } catch (\Doctrine\DBAL\Exception $e) {
                    $this->assertStringContainsString('lock timeout', strtolower($e->getMessage()), $what);
                }
                $this->assertSame($before, $state(), "$what : rien n'est écrit sans le verrou");
            }
        } finally {
            if (isset($serviceDb)) {
                if ($serviceDb->isTransactionActive()) {
                    $serviceDb->rollBack();
                }
                $serviceDb->executeStatement('SET lock_timeout = 0');
            }
            $other->executeQuery('SELECT pg_advisory_unlock_all()');
            $other->close();
        }
        // Le verrou relâché : la fiche se lit (une ligne de journal), puis l'effacement emporte tout, sans ligne orpheline
        $this->assertSame($id, $this->exercises()->detail($id, 'fr', $this->adminId)['id']);
        $this->assertSame(['deleted' => 1], $this->exercises()->deleteAll($this->adminId));
        $this->assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM loss_journal j WHERE j.finding_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM loss_finding f WHERE f.id = j.finding_id)'), 'aucune ligne de journal d\'un constat disparu');
        $this->assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM loss_evidence e WHERE NOT EXISTS (SELECT 1 FROM loss_finding f WHERE f.id = e.finding_id)'));
    }

    /**
     * Relecture du lot (A4) : la migration se rejoue sans rien changer ; son retour arrière PURGE les exercices par la porte avant de retirer
     * la colonne (sans la colonne, un exercice resté en base deviendrait un vrai constat) ; et la commande à jouer avant de remettre en
     * ligne un code d'avant EX-1 efface les exercices de chaque site sans toucher à un vrai constat.
     */
    public function testTheMigrationReplaysItsRollbackPurgesTheExercisesAndTheCommandDoesItSiteBySite(): void
    {
        $this->drain();
        $this->loginPos(['ROLE_USER_POS']);
        $this->flags(true, true);
        $this->openSession();
        $report = $this->closeWith(-1300, 'Vrai constat, retour arrière');
        $this->json($this->admin('POST', self::BASE . '/analyse'));
        $real = (int) $this->db->fetchOne('SELECT id FROM loss_finding WHERE dedup_key = ?', ['CASH_DISCREPANCY:z_report:' . $report['id']]);
        $counts = fn (): array => [(int) $this->db->fetchOne('SELECT COUNT(*) FROM loss_finding WHERE exercise = false'),
            (int) $this->db->fetchOne('SELECT COUNT(*) FROM loss_evidence e JOIN loss_finding f ON f.id = e.finding_id WHERE f.exercise = false'),
            (int) $this->db->fetchOne('SELECT COUNT(*) FROM loss_journal j JOIN loss_finding f ON f.id = j.finding_id WHERE f.exercise = false')];
        $realBefore = $counts();
        $schema = fn (): array => [
            // déclencheurs des tables de L1-a et d'EX-1 seulement (les pièces et les puces du lot L2-a ont les leurs, vérifiés dans leur test)
            $this->db->fetchFirstColumn("SELECT t.tgname FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid WHERE c.relname IN ('loss_finding', 'loss_evidence', 'loss_journal') AND NOT t.tgisinternal ORDER BY t.tgname"),
            $this->db->fetchFirstColumn("SELECT indexname FROM pg_indexes WHERE indexname IN ('idx_loss_finding_exercise', 'idx_loss_journal_type') ORDER BY indexname"),
            (int) $this->db->fetchOne("SELECT COUNT(*) FROM information_schema.columns WHERE table_name = 'loss_finding' AND column_name = 'exercise'"),
        ];
        $schemaBefore = $schema();
        $this->assertSame([['loss_evidence_no_change', 'loss_evidence_no_truncate', 'loss_finding_exercise_frozen', 'loss_finding_no_delete', 'loss_finding_no_truncate', 'loss_journal_no_change', 'loss_journal_no_truncate'],
            ['idx_loss_finding_exercise', 'idx_loss_journal_type'], 1], $schemaBefore);

        // 1. La migration se rejoue : rien ne bouge, ni le schéma ni les données
        require_once dirname(__DIR__, 3) . '/migrations/Version20270105100000.php';
        foreach ([1, 2] as $pass) {
            foreach (\DoctrineMigrations\Version20270105100000::STATEMENTS as $sql) {
                $this->db->executeStatement($sql);
            }
            $this->assertSame([$schemaBefore, $realBefore], [$schema(), $counts()], "rejeu $pass");
        }

        // 2. Le retour arrière, joué dans une transaction puis annulé (la base de test retrouve la migration) : les exercices sont purgés
        //    par la porte AVANT que la colonne ne parte, les vrais constats sont intacts
        foreach (['cash_shortage', 'drawer_no_sale', 'void_series'] as $scenario) {
            $this->json($this->admin('POST', self::EX, ['scenario' => $scenario]), 201);
        }
        $this->json($this->admin('GET', self::EX . '/' . $this->db->fetchOne('SELECT MAX(id) FROM loss_finding WHERE exercise')));
        $this->db->beginTransaction();
        try {
            $beforeColumnDrop = null;
            foreach (\DoctrineMigrations\Version20270105100000::DOWN_STATEMENTS as $sql) {
                if (str_contains($sql, 'DROP COLUMN')) {
                    $beforeColumnDrop = [(int) $this->db->fetchOne('SELECT COUNT(*) FROM loss_finding WHERE exercise'), $counts()];
                }
                $this->db->executeStatement($sql);
            }
            $this->assertSame([0, $realBefore], $beforeColumnDrop, 'avant de retirer la colonne : plus aucun exercice, vrais constats intacts');
            $this->assertSame(0, (int) $this->db->fetchOne("SELECT COUNT(*) FROM information_schema.columns WHERE table_name = 'loss_finding' AND column_name = 'exercise'"));
            $this->assertSame($realBefore[0], (int) $this->db->fetchOne('SELECT COUNT(*) FROM loss_finding'), 'il ne reste que les vrais constats');
            $this->assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM loss_evidence e WHERE NOT EXISTS (SELECT 1 FROM loss_finding f WHERE f.id = e.finding_id)'), 'aucune preuve orpheline');
            $this->assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM loss_journal j WHERE j.finding_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM loss_finding f WHERE f.id = j.finding_id)'));
        } finally {
            $this->db->rollBack();
        }
        $this->assertSame([$schemaBefore, 3], [$schema(), (int) $this->db->fetchOne('SELECT COUNT(*) FROM loss_finding WHERE exercise')], 'transaction annulée : la base de test est comme avant');

        // 3. La commande du retour arrière : compter, puis effacer, sur le site demandé seulement ; jamais un vrai constat
        $command = function (array $options): array {
            $tester = new \Symfony\Component\Console\Tester\CommandTester((new \Symfony\Bundle\FrameworkBundle\Console\Application(static::$kernel))->find('app:pertes:exercices:effacer'));
            $code = $tester->execute($options);
            $this->provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);

            return [$code, $tester->getDisplay()];
        };
        [$code, $display] = $command([]);
        $this->assertSame(\Symfony\Component\Console\Command\Command::INVALID, $code, 'ni --tenant ni --all : rien n\'est effacé');
        $this->assertSame(\Symfony\Component\Console\Command\Command::INVALID, $command(['--tenant' => MV_TEST_TENANT_CODE, '--all' => true])[0]);
        [$code, $display] = $command(['--tenant' => MV_TEST_TENANT_CODE, '--dry-run' => true]);
        $this->assertSame([\Symfony\Component\Console\Command\Command::SUCCESS, 3], [$code, (int) $this->db->fetchOne('SELECT COUNT(*) FROM loss_finding WHERE exercise')]);
        $this->assertStringContainsString('3 constat(s) d\'exercice présent(s)', $display);
        $this->assertSame(\Symfony\Component\Console\Command\Command::FAILURE, $command(['--tenant' => 'site-qui-n-existe-pas'])[0]);
        [$code, $display] = $command(['--tenant' => MV_TEST_TENANT_CODE]);
        $this->assertSame(\Symfony\Component\Console\Command\Command::SUCCESS, $code);
        $this->assertStringContainsString(MV_TEST_TENANT_CODE . ' : 3 constat(s) d\'exercice effacé(s)', $display);
        $this->assertSame([0, $realBefore], [(int) $this->db->fetchOne('SELECT COUNT(*) FROM loss_finding WHERE exercise'), $counts()], 'les exercices sont partis, les vrais constats sont intacts');
        $this->assertSame(200, $this->admin('GET', self::BASE . '/constats/' . $real)->getStatusCode());
        $this->assertStringContainsString('0 constat(s) d\'exercice effacé(s)', $command(['--tenant' => MV_TEST_TENANT_CODE])[1], 'rejouée : rien à effacer');
    }

    /** Relecture du lot (R4) : avec un seuil élevé (jusqu'à 10 000 $), les chiffres d'un scénario de manque restent cohérents */
    public function testACashShortageScenarioStaysCoherentWithAVeryHighThreshold(): void
    {
        $this->place('US', 'FL');
        $this->flags(true, true);
        $this->json($this->admin('PUT', self::BASE . '/regles/CASH_DISCREPANCY', ['params' => ['minAmount' => 1000000]]));
        foreach (['cash_shortage', 'cash_shortage_series'] as $scenario) {
            $finding = $this->json($this->admin('POST', self::EX . '?locale=fr', ['scenario' => $scenario]), 201)['finding'];
            $this->assertGreaterThan(1000000, $finding['amountAtStake'], $scenario);
            foreach ($this->db->fetchFirstColumn('SELECT snapshot FROM loss_evidence WHERE finding_id = ?', [$finding['id']]) as $snapshot) {
                $s = json_decode((string) $snapshot, true);
                $this->assertGreaterThan(0, $s['countedCash'], "$scenario : espèces comptées positives");
                $this->assertGreaterThan(0, $s['bankDeposit'], "$scenario : dépôt en banque positif");
                $this->assertSame($s['expectedCash'] + $s['discrepancy'], $s['countedCash'], "$scenario : compté = attendu + écart");
                $this->assertLessThan(-1000000, $s['discrepancy'], "$scenario : le manque dépasse le seuil du site");
            }
            $this->assertStringNotContainsString('-', (string) preg_replace('/Écart \(compté − attendu\)[^}]*/u', '', (string) json_encode(array_column($finding['evidence'][0]['lines'], 'value', 'label'), JSON_UNESCAPED_UNICODE)), "$scenario : aucun montant négatif hors de l'écart");
        }
    }

    public function testTheExerciseScreenHasItsGuide(): void
    {
        $fr = $this->json($this->call('GET', '/api/gestion/ecrans/pertes.exercices/guide?locale=fr', null, $this->adminToken));
        $en = $this->json($this->call('GET', '/api/gestion/ecrans/pertes.exercices/guide?locale=en', null, $this->adminToken));
        $this->assertSame(['pertes.exercices', 'Exercices de la prévention des pertes', 'Loss prevention practice', 4, 4], [$fr['screen'], $fr['title'], $en['title'], count($fr['points']), count($en['points'])]);
        $this->assertStringNotContainsString('pertes_', (string) json_encode([$fr, $en]));
    }
}
