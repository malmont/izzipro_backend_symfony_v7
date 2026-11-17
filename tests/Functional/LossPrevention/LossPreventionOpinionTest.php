<?php

namespace App\Tests\Functional\LossPrevention;

use App\LossPrevention\Message\WriteLossOpinionMessage;
use App\LossPrevention\MessageHandler\WriteLossOpinionHandler;
use App\LossPrevention\Services\LossOpinionService;
use App\Services\TenantConnectionManager;
use App\Tests\Fake\FakeLandingAiClient;
use App\Tests\Functional\Support\AiRightTestHelper;
use Doctrine\DBAL\ArrayParameterType;

/**
 * Lot L5-c (17/11/2026, `docs/prevention-pertes.md` § 18.3 et § 18.10) : l'avis de l'IA sur un constat. Le pourcentage est calculé par le
 * serveur ; l'entrée du modèle ne porte aucune personne ; la sortie est vérifiée ; 1 unité, à la demande, rendue en cas d'échec.
 */
class LossPreventionOpinionTest extends LossPreventionTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        FakeLandingAiClient::reset();
        AiRightTestHelper::restoreFullAi(static::getContainer());
    }

    /**
     * Les exemples JSON de l'avis, pour le web (`tests/Fixtures/pertes/avis/*.json`) : réécrits avec `UPDATE_LOSS_EXAMPLES=1`, sinon la forme
     * (les clés) est comparée à celle du jour.
     *
     * @param array<string, mixed>|null $body
     */
    private function example(string $name, string $method, string $path, int $status, ?array $body): void
    {
        $file = dirname(__DIR__, 2) . "/Fixtures/pertes/avis/$name.json";
        $opinion = $body['opinion'] ?? null;
        $kept = ['request' => ['method' => $method, 'path' => $path], 'status' => $status, 'body' => $opinion !== null ? ['id' => $body['id'], 'opinion' => $opinion] : $body];
        if (getenv('UPDATE_LOSS_EXAMPLES') === '1') {
            file_put_contents($file, json_encode($kept, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");

            return;
        }
        $stored = json_decode((string) file_get_contents($file), true);
        $this->assertSame(array_keys($stored['body']['opinion'] ?? $stored['body']), array_keys($kept['body']['opinion'] ?? $kept['body']), "forme de l'exemple $name");
    }

    /**
     * Un compte de l'équipe laissé par un autre essai de la suite ne porte aucun mot du texte type : le serveur refuserait ce texte, à bon
     * droit, comme nommant une personne (train 97 : l'administrateur « Session Client » d'un essai de la boutique, joué avant, et « la même
     * session » dans le texte type ; l'avis échouait en AI_UNAVAILABLE, la file du faux modèle étant vide au second essai). Ces comptes sont
     * renommés sur la base d'essai ; ceux de cet essai (`$keep`) restent tels quels, le refus d'un nom de la boutique est vérifié plus bas.
     *
     * @param array<string, mixed> $out
     * @param list<int>            $keep
     */
    private function noStaffNamedLike(array $out, array $keep): void
    {
        preg_match_all('/\p{L}{3,}/u', mb_strtolower(json_encode($out, JSON_UNESCAPED_UNICODE)), $found);
        $words = array_values(array_unique($found[0]));
        $this->db->executeStatement('UPDATE "user" SET firstname = CASE WHEN LOWER(TRIM(firstname)) IN (:w) THEN \'Zqx\' ELSE firstname END,'
            . ' lastname = CASE WHEN LOWER(TRIM(lastname)) IN (:w) THEN \'Zqx\' ELSE lastname END'
            . ' WHERE id NOT IN (:keep) AND (LOWER(TRIM(firstname)) IN (:w) OR LOWER(TRIM(lastname)) IN (:w))',
            ['w' => $words, 'keep' => $keep], ['w' => ArrayParameterType::STRING, 'keep' => ArrayParameterType::INTEGER]);
    }

    protected function tearDown(): void
    {
        FakeLandingAiClient::reset();
        parent::tearDown();
    }

    public function testTheServerComputesThePercentAndTheModelWritesWithoutEverReadingAPerson(): void
    {
        $this->drain();
        $cashier = $this->loginPos(['ROLE_USER_POS']);
        $session = $this->openSession();
        foreach (range(1, 4) as $i) {
            $this->pos('POST', '/api/caisse/drawer-open', ['cashRegisterId' => $this->register, 'reason' => "Monnaie pour Julie $this->mark $i"]);
        }
        $this->assertSame(1, $this->json($this->admin('POST', self::BASE . '/analyse'))['opened']);
        $id = (int) $this->db->fetchOne('SELECT id FROM loss_finding WHERE dedup_key = ?', ['DRAWER_NO_SALE:session:' . $session]);
        $usageMark = (int) $this->db->fetchOne('SELECT COALESCE(MAX(id), 0) FROM ai_usage');
        $usage = fn (): array => array_map('intval', array_column($this->db->fetchAllAssociative('SELECT status, COUNT(*) AS n FROM ai_usage WHERE id > ? GROUP BY status ORDER BY status', [$usageMark]), 'n', 'status'));
        $detail = fn (): array => $this->json($this->admin('GET', self::BASE . "/constats/$id?locale=fr"));
        $ask = fn (int $status = 202): array => $this->json($this->admin('POST', self::BASE . "/constats/$id/avis?locale=fr"), $status);
        $worker = function () use ($id): void {
            static::getContainer()->get(WriteLossOpinionHandler::class)(new WriteLossOpinionMessage($id, MV_TEST_TENANT_CODE, 'fr'));
            $this->provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        };
        $reply = fn (array $out): object => json_decode(json_encode(['id' => 'msg_' . bin2hex(random_bytes(4)), 'type' => 'message', 'role' => 'assistant', 'model' => LossOpinionService::MODEL,
            'stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => json_encode($out)]], 'usage' => ['input_tokens' => 1500, 'output_tokens' => 300, 'cache_read_input_tokens' => 0, 'cache_creation_input_tokens' => 0]]), false);
        $good = ['summary' => 'Les 4 ouvertures du tiroir se suivent dans la même session.', 'factors' => [['label' => 'Plus que le seuil réglé.', 'effect' => 'up'], ['label' => 'Une seule session.', 'effect' => 'down']],
            'alternatives' => ['De la monnaie à rendre en début de journée.']];
        $this->assertNull($detail()['opinion'], 'jamais demandé : rien');

        // Le pourcentage du serveur : 20 semblables tranchés au moins (même règle, même variante, ce site, 12 mois, sans l'exercice ni le constat)
        $columns = array_values(array_diff($this->db->fetchFirstColumn("SELECT column_name FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = 'loss_finding' ORDER BY ordinal_position"),
            ['id', 'number', 'dedup_key', 'status', 'decided_at', 'decided_by', 'decision_note', 'seen_at', 'seen_by', 'decided_count', 'grew_at']));
        $cols = implode(', ', $columns);
        $this->db->executeStatement("INSERT INTO loss_finding ($cols, number, dedup_key, status, decided_at, decided_by, decision_note) SELECT $cols, (SELECT MAX(number) FROM loss_finding) + g,"
            . " dedup_key || ':semblable:' || g, CASE WHEN g <= 6 THEN 'confirmed' WHEN g <= 21 THEN 'dismissed' ELSE 'open' END, NOW(), ?, CASE WHEN g <= 6 THEN 'Vu' ELSE NULL END"
            . ' FROM loss_finding, generate_series(1, 23) g WHERE id = ?', [$this->adminId, $id]);
        $this->db->executeStatement("INSERT INTO loss_finding ($cols, number, dedup_key, status, decided_at, decided_by, decision_note) SELECT $cols, (SELECT MAX(number) FROM loss_finding) + 1,"
            . " dedup_key || ':ancien', 'confirmed', NOW(), ?, 'Vu' FROM loss_finding WHERE id = ?", [$this->adminId, $id]);
        $this->db->executeStatement("UPDATE loss_finding SET created_at = NOW() - INTERVAL '2 years' WHERE dedup_key = ?", ['DRAWER_NO_SALE:session:' . $session . ':ancien']);
        $since = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('-12 months')->format('Y-m-d H:i:s');
        $expected = array_map('intval', $this->db->fetchAssociative("SELECT COUNT(*) AS similar, COUNT(*) FILTER (WHERE status IN ('confirmed', 'explained', 'dismissed')) AS decided,"
            . " COUNT(*) FILTER (WHERE status = 'confirmed') AS confirmed FROM loss_finding WHERE rule_code = 'DRAWER_NO_SALE' AND variant = 'rule' AND id <> ? AND NOT exercise AND created_at >= ?", [$id, $since]));
        $this->assertGreaterThanOrEqual(20, $expected['decided']);
        $percent = (int) round($expected['confirmed'] * 100 / $expected['decided']);

        // La demande : 202, l'avis en préparation, une unité réservée, aucun appel au modèle dans la requête
        $asked = $ask();
        $this->example('avis-en-preparation', 'POST', '/api/gestion/pertes/constats/{id}/avis', 202, $asked);
        $pending = $asked['opinion'];
        $this->assertSame(['state', 'percent', 'calibrated', 'calibrationNotice', 'measures', 'factors', 'summary', 'alternatives', 'generatedAt', 'creditsUsed', 'stale', 'example', 'error', 'notice'], array_keys($pending));
        $this->assertSame(['pending', $percent, false, $expected, null, 0, false], [$pending['state'], $pending['percent'], $pending['calibrated'], $pending['measures'], $pending['summary'], $pending['creditsUsed'], $pending['example']]);
        $this->assertSame([['reserved' => 1], 0], [$usage(), count(FakeLandingAiClient::$requests)]);
        // La lecture légère, relue toutes les 3 s par le web : l'avis seul ; aucune ligne de journal, « nouveau » n'est pas éteint
        $this->db->executeStatement('UPDATE loss_finding SET seen_at = NULL, seen_by = NULL WHERE id = ?', [$id]);
        $journalBefore = (int) $this->db->fetchOne('SELECT COUNT(*) FROM loss_journal');
        foreach (range(1, 3) as $ignored) {
            $light = $this->json($this->admin('GET', self::BASE . "/constats/$id/avis?locale=fr"));
            $this->assertSame(['opinion'], array_keys($light));
            $this->assertSame('pending', $light['opinion']['state']);
        }
        $this->assertSame([$journalBefore, null], [(int) $this->db->fetchOne('SELECT COUNT(*) FROM loss_journal'), $this->db->fetchOne('SELECT seen_at FROM loss_finding WHERE id = ?', [$id])],
            'aucune ligne de journal, toujours « nouveau »');
        $this->assertSame('FINDING_NOT_FOUND', $this->json($this->admin('GET', self::BASE . '/constats/999999999/avis'), 404)['code']);
        $this->assertSame('OPINION_PENDING', $ask(409)['code'], 'un avis à la fois');

        // Le worker : le modèle rédige, le serveur vérifie, l'unité est décomptée
        $this->noStaffNamedLike($good, [(int) $cashier->getId(), $this->adminId]);
        FakeLandingAiClient::$queue[] = $reply($good);
        $worker();
        $this->example('avis-redige', 'GET', '/api/gestion/pertes/constats/{id}', 200, $detail());
        $done = $detail()['opinion'];
        $this->assertSame(['done', $percent, $good['summary'], $good['factors'], $good['alternatives'], 1, false, null], [$done['state'], $done['percent'], $done['summary'], $done['factors'], $done['alternatives'], $done['creditsUsed'], $done['stale'], $done['error']],
            'avis : ' . json_encode(['state' => $done['state'], 'error' => $done['error']]));
        $this->assertNotNull($done['generatedAt']);
        $this->assertSame(['success' => 1], $usage());
        $this->assertStringContainsString('la décision vous appartient', $done['notice']);
        $this->assertArrayNotHasKey('opinion', $this->json($this->admin('GET', self::BASE . '/constats?rule=DRAWER_NO_SALE&limit=5'))[0], 'jamais dans la liste');

        // L'entrée du modèle : par liste blanche, sans personne. Ni nom, ni courriel, ni identifiant de compte, ni motif saisi, ni « en poste »
        $request = FakeLandingAiClient::$requests[0];
        $this->assertSame([LossOpinionService::MODEL, 'json_schema', false], [$request['model'], $request['output_config']['format']['type'], array_key_exists('tool_choice', $request)]);
        $sent = (string) $request['messages'][0]['content'][0]['text'];
        $input = json_decode($sent, true);
        $this->assertSame(['sources', 'rule', 'finding', 'evidence', 'measures', 'numbers'], array_keys($input));
        $this->assertSame(['finding', 'evidence'], $input['sources'], 'l\'extrait vidéo n\'est pas une source');
        $this->assertCount(4, $input['evidence']);
        $this->assertSame(['kind', 'minutesFromFirst', 'numbers'], array_keys($input['evidence'][0]));
        $this->assertSame(0, $input['evidence'][0]['minutesFromFirst']);
        $this->assertDoesNotMatchRegularExpression('/\d{4}-\d{2}-\d{2}|\d{1,2}:\d{2}/', $sent, 'ni date ni heure');
        foreach ([...array_keys($input['finding']['facts']), ...array_merge(...array_map(fn ($e) => array_keys($e['numbers']), $input['evidence']))] as $key) {
            $this->assertContains($key, [...LossOpinionService::NUMBER_KEYS, ...LossOpinionService::TEXT_KEYS], "« $key » est en liste blanche");
        }
        foreach ([$cashier->getFirstname(), $cashier->getLastname(), $cashier->getEmail(), $this->mark, 'Julie', 'onDuty', 'operator', '"userId"', '"closedById"', 'Monnaie pour'] as $never) {
            $this->assertStringNotContainsString((string) $never, $sent . json_encode($request['system']), "« $never » n'entre jamais dans l'avis");
        }
        $keys = [];
        array_walk_recursive($input, function ($v, $k) use (&$keys) { $keys[] = (string) $k; });
        $this->assertSame([], array_values(array_filter($keys, fn (string $k) => preg_match('/(id|Id|ID|number|Number|sequence|hash)$/', $k) === 1)), 'aucun identifiant');
        $has = fn (float $n): bool => in_array($n, array_map('floatval', $input['numbers']), true); // le JSON rend 29.0 comme 29
        $this->assertTrue($has((float) $percent));
        $this->assertTrue($has((float) (100 - $percent)), 'les dérivés évidents sont listés');
        $this->assertTrue($has(4.0));
        // Au journal du module : la demande et l'avis, avec le pourcentage et les unités ; jamais le texte
        $journal = $this->db->fetchAllAssociative("SELECT type, data FROM loss_journal WHERE finding_id = ? AND type IN ('OPINION_REQUESTED', 'OPINION_WRITTEN') ORDER BY id", [$id]);
        $this->assertSame([['OPINION_REQUESTED', ['percent' => $percent, 'credits' => 1]], ['OPINION_WRITTEN', ['percent' => $percent, 'credits' => 1]]],
            array_map(fn (array $j) => [$j['type'], json_decode((string) $j['data'], true)], $journal));

        // Actualiser : un nombre inventé est refusé, un seul nouvel essai avec la remarque, puis l'avis
        $ask();
        FakeLandingAiClient::$queue[] = $reply(['summary' => 'Les 17 ouvertures du tiroir.'] + $good);
        FakeLandingAiClient::$queue[] = $reply($good);
        $worker();
        $this->assertSame('done', $detail()['opinion']['state']);
        $this->assertCount(3, FakeLandingAiClient::$requests);
        $this->assertStringContainsString('17', json_decode((string) FakeLandingAiClient::$requests[2]['messages'][0]['content'][0]['text'], true)['correction']);
        // Un mot qui accuse, deux fois : l'avis échoue, l'unité est rendue
        $ask();
        FakeLandingAiClient::$queue[] = $reply(['summary' => 'Le caissier est suspect.'] + $good);
        FakeLandingAiClient::$queue[] = $reply(['summary' => 'Une sanction s\'impose.'] + $good);
        $worker();
        $this->assertSame(['failed', 'OPINION_REJECTED', 0], [($failed = $detail()['opinion'])['state'], $failed['error'], $failed['creditsUsed']]);
        // refusé par le serveur : l'IA a répondu, rien n'est décompté et ce n'est pas un échec de l'IA (le plafond d'échecs du site n'en voit rien)
        $this->assertSame(['success' => 3], $usage());
        $this->assertSame(0, (int) $this->db->fetchOne('SELECT credits FROM ai_usage WHERE id > ? ORDER BY id DESC LIMIT 1', [$usageMark]));
        // Le nom d'un compte de la boutique : refusé aussi
        $ask();
        FakeLandingAiClient::$queue[] = $reply(['summary' => 'Les ouvertures de ' . $cashier->getFirstname() . ' se suivent.'] + $good);
        FakeLandingAiClient::$queue[] = $reply(['summary' => 'Nom' . $this->mark . ' a ouvert le tiroir.'] + $good);
        $worker();
        $this->assertSame('OPINION_REJECTED', $detail()['opinion']['error']);
        // Le modèle en panne : échec, unité rendue
        $ask();
        FakeLandingAiClient::$queue[] = new \RuntimeException('panne');
        $worker();
        $this->assertSame(['failed', 'AI_UNAVAILABLE'], [($down = $detail()['opinion'])['state'], $down['error']]);
        // Le délai dépassé : échec, unité rendue, sans appel
        $ask();
        $this->db->executeStatement("UPDATE loss_opinion SET requested_at = NOW() AT TIME ZONE 'UTC' - INTERVAL '10 minutes' WHERE finding_id = ?", [$id]);
        $this->assertSame(['failed', 'OPINION_TIMEOUT'], [($late = $detail()['opinion'])['state'], $late['error']]);
        $this->assertSame(['failed' => 2, 'success' => 4], $usage());
        FakeLandingAiClient::$queue[] = $reply($good);
        $worker();
        $this->assertSame('failed', $detail()['opinion']['state'], 'une réponse tardive n\'écrase pas l\'échec');

        // Une preuve arrivée après l'avis : « Actualiser »
        $ask();
        FakeLandingAiClient::$queue[] = $reply($good);
        $worker();
        sleep(1);
        $this->pos('POST', '/api/caisse/drawer-open', ['cashRegisterId' => $this->register, 'reason' => 'Encore']);
        $this->json($this->admin('POST', self::BASE . '/analyse'));
        $this->assertTrue($detail()['opinion']['stale']);

        // Jamais sur une règle « par personne »
        $person = (int) $this->db->fetchOne("SELECT id FROM loss_finding WHERE dedup_key = ?", ['DRAWER_NO_SALE:session:' . $session . ':semblable:22']);
        $this->db->executeStatement("UPDATE loss_finding SET subject_type = 'user_window' WHERE id = ?", [$person]);
        $this->example('refus-avis-personne', 'POST', '/api/gestion/pertes/constats/{id}/avis', 422, $notAllowed = $this->json($this->admin('POST', self::BASE . "/constats/$person/avis"), 422));
        $this->assertSame('OPINION_NOT_ALLOWED', $notAllowed['code']);
        // La même garde au moment d'écrire : un avis en préparation sur un tel constat (posé en base) échoue sans appel au modèle
        $calls = count(FakeLandingAiClient::$requests);
        $this->db->executeStatement("INSERT INTO loss_opinion (finding_id, state, measures, requested_at) VALUES (?, 'pending', '{}', NOW() AT TIME ZONE 'UTC')", [$person]);
        static::getContainer()->get(WriteLossOpinionHandler::class)(new WriteLossOpinionMessage($person, MV_TEST_TENANT_CODE, 'fr'));
        $this->provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $this->assertSame(['failed', 'OPINION_NOT_ALLOWED', $calls], [(string) $this->db->fetchOne('SELECT state FROM loss_opinion WHERE finding_id = ?', [$person]),
            (string) $this->db->fetchOne('SELECT error FROM loss_opinion WHERE finding_id = ?', [$person]), count(FakeLandingAiClient::$requests)]);
        // Constat inconnu, ou d'exercice par la route des vrais : 404
        $this->assertSame('FINDING_NOT_FOUND', $this->json($this->admin('POST', self::BASE . '/constats/999999999/avis'), 404)['code']);
        $this->closeWith(0);
    }

    public function testTheServerRefusesNumbersInWordsProportionsPersonsAndNamesButNotItsOwnWords(): void
    {
        $ok = ['summary' => 'Les 4 ouvertures se suivent.', 'factors' => [], 'alternatives' => []];
        $check = fn (string $summary, array $names = []): ?string => LossOpinionService::rejected(['summary' => $summary] + $ok, [4.0, 30.0], $names);
        $this->assertNull($check('Les 4 ouvertures se suivent.'));
        $this->assertNotNull($check('Les quatre ouvertures se suivent dans trente minutes et deux autres.'), '« deux » n\'est pas dans les nombres admis');
        $this->assertNull($check('Les quatre ouvertures se suivent en trente minutes.'), 'en lettres, mais admis');
        $this->assertNull($check('4 articles sur 30, vendus sans lecture.'), 'la tournure du serveur lui-même');
        $this->assertNull($check('Un article neuf, une demi-heure.'), '« neuf » n\'est pas lu comme un nombre');
        foreach (['Trente pour cent des cas.', 'La moitié des ouvertures.', 'Half of the openings.', 'Un employé a ouvert le tiroir.', 'Le caissier a ouvert.',
            'A cashier opened it.', 'Le client est parti.', 'Une mise à pied est possible.', 'Un vol est probable.', 'Shoplifting.'] as $wrong) {
            $this->assertNotNull($check($wrong), $wrong);
        }
        $this->assertNull($check('Un accusé de réception de la caisse.'), '« accusé de réception » n\'accuse personne');
        $this->assertNull($check('Le volume de la caisse.'), '« volume » n\'est pas un vol');
        // les noms : quelles que soient leurs majuscules, en tête de phrase comme ailleurs ; seul un mot que le serveur emploie lui-même passe
        $this->assertNotNull($check('Les ouvertures de Martin se suivent.', ['Martin']));
        $this->assertNotNull($check('Dupont a ouvert 4 fois.', ['DUPONT']), 'en tête de phrase et en capitales dans la base');
        $this->assertNotNull($check('Les 4 ouvertures de DUPONT.', ['Dupont']));
        $this->assertNotNull($check('Porte arrière : 4 passages.', ['Porte']), 'un mot que le serveur n\'a pas employé');
        $this->assertNull(LossOpinionService::rejected(['summary' => 'Porte arrière : 4 passages.'] + $ok, [4.0], ['Porte'], ['porte', 'arrière']), 'un mot de l\'entrée');
    }

    public function testARefreshedOpinionNeverReceivesTheTextOfTheOldRequest(): void
    {
        $this->drain();
        $this->loginPos(['ROLE_USER_POS']);
        $session = $this->openSession();
        foreach (range(1, 4) as $i) {
            $this->pos('POST', '/api/caisse/drawer-open', ['cashRegisterId' => $this->register, 'reason' => "Monnaie $i"]);
        }
        $this->json($this->admin('POST', self::BASE . '/analyse'));
        $id = (int) $this->db->fetchOne('SELECT id FROM loss_finding WHERE dedup_key = ?', ['DRAWER_NO_SALE:session:' . $session]);
        $this->closeWith(0);
        $repo = $this->provider->getEntityManager()->getRepository(\App\LossPrevention\Entity\LossOpinion::class);
        // la demande A, dépassée par le délai ; puis la demande B (« Actualiser ») : le texte tardif de A ne s'écrit pas sur B
        $this->assertTrue($repo->begin($id, false, null, ['similar' => 0, 'decided' => 0, 'confirmed' => 0], $this->adminId, null, null));
        $first = (int) $this->db->fetchOne('SELECT attempt FROM loss_opinion WHERE finding_id = ?', [$id]);
        $this->assertTrue($repo->fail($id, 'OPINION_TIMEOUT', $first));
        $this->assertFalse($repo->finish($id, ['summary' => 'tardif'], 1, false, $first), 'A, en échec, ne devient pas un avis rédigé, même avec son numéro');
        $this->assertSame(['failed', 'OPINION_TIMEOUT'], array_values($this->db->fetchAssociative('SELECT state, error FROM loss_opinion WHERE finding_id = ?', [$id])));
        $this->assertTrue($repo->begin($id, false, null, ['similar' => 0, 'decided' => 0, 'confirmed' => 0], $this->adminId, null, null));
        $this->assertSame($first + 1, (int) $this->db->fetchOne('SELECT attempt FROM loss_opinion WHERE finding_id = ?', [$id]));
        $this->assertFalse($repo->finish($id, ['summary' => 'ancien'], 1, false, $first), 'la réponse tardive de A');
        $this->assertFalse($repo->fail($id, 'AI_UNAVAILABLE', $first));
        $this->assertSame('pending', (string) $this->db->fetchOne('SELECT state FROM loss_opinion WHERE finding_id = ?', [$id]));
        $this->assertTrue($repo->finish($id, ['summary' => 'nouveau'], 1, false, $first + 1));
        $this->assertSame('nouveau', json_decode((string) $this->db->fetchOne('SELECT content FROM loss_opinion WHERE finding_id = ?', [$id]), true)['summary']);
    }

    public function testAnHourRuleGivesTheGapToTheOpeningHoursNeverTheHour(): void
    {
        // ouvert de 9 h à 18 h du lundi au samedi, fermé le dimanche
        $hours = ['days' => [1 => ['open' => 540, 'close' => 1080], 2 => ['open' => 540, 'close' => 1080], 3 => ['open' => 540, 'close' => 1080], 4 => ['open' => 540, 'close' => 1080],
            5 => ['open' => 540, 'close' => 1080], 6 => ['open' => 540, 'close' => 1080], 7 => null], 'margin' => 60];
        $zone = new \DateTimeZone('America/Toronto');
        $at = fn (string $local): \DateTimeImmutable => new \DateTimeImmutable($local, $zone);
        $outside = fn (string $local): ?array => \App\LossPrevention\Services\LossDepotService::outside($hours, $at($local), $zone);
        $this->assertNull($outside('2026-11-17 10:00'), 'mardi 10 h : ouvert');
        $this->assertSame(['side' => 'before_opening', 'minutes' => 75], $outside('2026-11-17 07:45'));
        $this->assertSame(['side' => 'after_closing', 'minutes' => 150], $outside('2026-11-17 20:30'));
        $this->assertSame(['side' => 'after_closing', 'minutes' => 360], $outside('2026-11-18 00:00'), 'minuit : plus près de la fermeture de la veille (6 h) que de l\'ouverture (9 h)');
        $this->assertSame(['side' => 'after_closing', 'minutes' => 420], $outside('2026-11-18 01:00'), '1 h : 7 h après la fermeture de la veille, 8 h avant l\'ouverture : la plus proche');
        $this->assertSame(['side' => 'before_opening', 'minutes' => 540], $outside('2026-11-23 00:00'), 'lundi minuit, dimanche fermé : 9 h avant l\'ouverture');
        $this->assertSame(['side' => 'after_closing', 'minutes' => 420], $outside('2026-11-22 01:00'), 'dimanche 1 h, fermé : 7 h après la fermeture du samedi');
    }

    public function testTheRefusalsAndTheExampleOpinionOfTheExercise(): void
    {
        $this->drain();
        $this->loginPos(['ROLE_USER_POS']);
        $session = $this->openSession();
        foreach (range(1, 4) as $i) {
            $this->pos('POST', '/api/caisse/drawer-open', ['cashRegisterId' => $this->register, 'reason' => "Monnaie $i"]);
        }
        $this->json($this->admin('POST', self::BASE . '/analyse'));
        $id = (int) $this->db->fetchOne('SELECT id FROM loss_finding WHERE dedup_key = ?', ['DRAWER_NO_SALE:session:' . $session]);
        $this->closeWith(0);

        // L'exercice : un avis d'exemple, écrit par le serveur, sans le modèle, à 0 unité
        $exercise = (int) $this->json($this->admin('POST', self::BASE . '/exercices', ['scenario' => 'drawer_no_sale']), 201)['finding']['id'];
        $this->example('avis-exercice', 'POST', '/api/gestion/pertes/exercices/{id}/avis', 202, $exerciseBody = $this->json($this->admin('POST', self::BASE . "/exercices/$exercise/avis?locale=fr"), 202));
        $example = $exerciseBody['opinion'];
        $this->assertSame(['done', true, 0, null], [$example['state'], $example['example'], $example['creditsUsed'], $example['percent']]);
        $this->assertStringStartsWith('Exemple d\'avis', $example['summary']);
        $this->assertSame(0, count(FakeLandingAiClient::$requests));
        $this->assertSame('FINDING_NOT_FOUND', $this->json($this->admin('POST', self::BASE . "/constats/$exercise/avis"), 404)['code'], 'les vrais et les exercices ne se croisent pas');
        $this->assertSame('FINDING_NOT_FOUND', $this->json($this->admin('POST', self::BASE . "/exercices/$id/avis"), 404)['code']);
        $this->assertSame('FINDING_NOT_FOUND', $this->json($this->admin('GET', self::BASE . "/constats/$exercise/avis"), 404)['code'], 'la lecture légère : vrais constats seulement');
        $service = static::getContainer()->get(LossOpinionService::class);
        $real = $this->db->fetchAssociative('SELECT * FROM loss_finding WHERE id = ?', [$id]);
        $since = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('-12 months')->format('Y-m-d H:i:s');
        $withoutExercise = array_map('intval', $this->db->fetchAssociative("SELECT COUNT(*) AS similar, COUNT(*) FILTER (WHERE status IN ('confirmed', 'explained', 'dismissed')) AS decided,"
            . " COUNT(*) FILTER (WHERE status = 'confirmed') AS confirmed FROM loss_finding WHERE rule_code = 'DRAWER_NO_SALE' AND variant = 'rule' AND id <> ? AND NOT exercise AND created_at >= ?", [$id, $since]));
        $this->assertSame($withoutExercise, $service->measure($real)[1], 'le constat d\'exercice n\'est pas un semblable');
        // Le seuil : 19 tranchés, pas de pourcentage ; 20, un pourcentage (une variante d'essai, pour être seuls)
        $columns = array_values(array_diff($this->db->fetchFirstColumn("SELECT column_name FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = 'loss_finding' ORDER BY ordinal_position"),
            ['id', 'number', 'dedup_key', 'status', 'variant', 'decided_at', 'decided_by', 'decision_note', 'seen_at', 'seen_by', 'decided_count', 'grew_at']));
        $cols = implode(', ', $columns);
        $copies = fn (int $from, int $to, string $status) => $this->db->executeStatement("INSERT INTO loss_finding ($cols, number, dedup_key, status, variant, decided_at, decided_by, decision_note)"
            . " SELECT $cols, (SELECT MAX(number) FROM loss_finding) + g - $from + 1, dedup_key || ':seuil:' || g, '$status', 'essai', NOW(), ?, 'Vu' FROM loss_finding, generate_series($from, $to) g WHERE id = ?", [$this->adminId, $id]);
        $copies(1, 4, 'confirmed');
        $copies(5, 19, 'dismissed');
        $probe = ['id' => 0, 'rule_code' => 'DRAWER_NO_SALE', 'variant' => 'essai'];
        $this->assertSame([null, ['similar' => 19, 'decided' => 19, 'confirmed' => 4]], $service->measure($probe));
        $copies(20, 20, 'confirmed');
        $this->assertSame([25, ['similar' => 20, 'decided' => 20, 'confirmed' => 5]], $service->measure($probe), '5 confirmés sur 20 tranchés');
        $this->json($this->admin('DELETE', self::BASE . '/exercices'));
        $this->assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM loss_opinion WHERE exercise'), 'effacé avec l\'exercice');

        // Sans le palier d'IA : 403, rien de réservé
        $master = static::getContainer()->get(TenantConnectionManager::class)->getPdoMaster();
        try {
            $master->prepare("DELETE FROM tenant_entitlements WHERE tenant_code = ? AND feature_key IN ('ai.full', 'ai.help')")->execute([MV_TEST_TENANT_CODE]);
            static::getContainer()->get(\App\Services\TenantCacheService::class)->invalidateTenant();
            $this->assertSame('AI_NOT_INCLUDED', $this->json($this->admin('POST', self::BASE . "/constats/$id/avis"), 403)['code']);
            $this->assertNull($this->db->fetchOne('SELECT state FROM loss_opinion WHERE finding_id = ?', [$id]) ?: null);
        } finally {
            AiRightTestHelper::restoreFullAi(static::getContainer());
        }
        // Plus d'unités : 402
        $monthly = $this->db->fetchOne('SELECT monthly_credits FROM ai_credit_setting ORDER BY id LIMIT 1');
        try {
            $monthly === false ? $this->db->executeStatement('INSERT INTO ai_credit_setting (monthly_credits) VALUES (0)') : $this->db->executeStatement('UPDATE ai_credit_setting SET monthly_credits = 0');
            static::getContainer()->get(\App\Services\TenantCacheService::class)->invalidateTenant();
            $this->assertSame('AI_CREDITS_EXHAUSTED', $this->json($this->admin('POST', self::BASE . "/constats/$id/avis"), 402)['code']);
            $this->assertNull($this->db->fetchOne('SELECT state FROM loss_opinion WHERE finding_id = ?', [$id]) ?: null);
        } finally {
            $monthly === false ? $this->db->executeStatement('DELETE FROM ai_credit_setting') : $this->db->executeStatement('UPDATE ai_credit_setting SET monthly_credits = ?', [$monthly]);
            static::getContainer()->get(\App\Services\TenantCacheService::class)->invalidateTenant();
        }
        // 30 avis par jour et par site : le 31e est refusé (lignes du journal posées en base, comme 30 demandes du jour)
        $done = (int) $this->db->fetchOne("SELECT COUNT(*) FROM loss_journal WHERE type = 'OPINION_REQUESTED' AND occurred_at >= NOW() AT TIME ZONE 'UTC' - INTERVAL '1 day'");
        foreach (range(1, max(0, LossOpinionService::DAILY_LIMIT - $done)) as $ignored) {
            $this->db->insert('loss_journal', ['type' => 'OPINION_REQUESTED', 'occurred_at' => gmdate('Y-m-d H:i:s'), 'user_id' => $this->adminId, 'finding_id' => $id, 'data' => '{}']);
        }
        $limit = $this->json($this->admin('POST', self::BASE . "/constats/$id/avis"), 429);
        $this->assertSame(['AI_DAILY_LIMIT', LossOpinionService::DAILY_LIMIT], [$limit['code'], $limit['limit']]);
    }
}
