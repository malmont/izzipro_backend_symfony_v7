<?php

namespace App\Tests\Functional\MemoiresVivantes;

use App\Tests\Fake\FakeAnthropicService;

/**
 * Contrat API « Types de livre » attendu par le front Next.js (console d'administration).
 * Sections numérotées comme la demande du front : 1 formes, 2 mises à jour partielles, 3 scénario,
 * 4 suppressions et archivage, 5 types historiques, plus les conditions communes.
 */
class BookTypeContractTest extends BookTypeApiTestCase
{
    private const SUMMARY_KEYS = ['id', 'code', 'label', 'family', 'speakerCount', 'isActive', 'isSystem', 'promptSource', 'displayOrder', 'chaptersCount', 'rolesCount', 'usage'];
    private const DETAIL_KEYS = ['description', 'speaker1Label', 'speaker2Label', 'subjectsMayBeAbsent', 'defaultRole', 'defaultRoleWhenSubjectsAbsent', 'promptRaw', 'promptOptimized', 'chapters', 'roles'];
    private const CHAPTER_KEYS = ['id', 'code', 'title', 'position', 'speaker', 'isActive', 'promptRaw', 'promptOptimized', 'usage', 'questions'];
    private const QUESTION_KEYS = ['id', 'role', 'questionText', 'tip', 'displayOrder', 'isActive', 'usage'];
    private const ROLE_KEYS = ['id', 'code', 'label', 'displayOrder', 'usage'];
    private const LEGACY_CODES = ['individuel', 'couple', 'famille', 'hommage'];

    // ------------------------------------------------------------------ Conditions communes

    public function testNonAdminAndAnonymousAreRefusedEverywhere(): void
    {
        $type = $this->typeByCode('famille');
        $chapterId = $type['chapters'][0]['id'];
        $roleId = $type['roles'][0]['id'];
        $questionId = $this->questions($type)[0]['id'];
        $routes = [
            ['GET', '/admin/book-types'],
            ['GET', '/admin/book-types/prompt-variables'],
            ['GET', '/admin/book-types/' . $type['id']],
            ['POST', '/admin/book-types'],
            ['PUT', '/admin/book-types/' . $type['id']],
            ['DELETE', '/admin/book-types/' . $type['id']],
            ['POST', '/admin/book-types/' . $type['id'] . '/chapters'],
            ['PUT', '/admin/book-types/' . $type['id'] . '/chapters/order'],
            ['PUT', '/admin/book-type-chapters/' . $chapterId],
            ['DELETE', '/admin/book-type-chapters/' . $chapterId],
            ['POST', '/admin/book-types/' . $type['id'] . '/roles'],
            ['PUT', '/admin/book-type-roles/' . $roleId],
            ['DELETE', '/admin/book-type-roles/' . $roleId],
            ['POST', '/admin/book-type-chapters/' . $chapterId . '/questions'],
            ['PUT', '/admin/book-type-chapters/' . $chapterId . '/questions/order'],
            ['PUT', '/admin/questions/' . $questionId],
            ['DELETE', '/admin/questions/' . $questionId],
            ['POST', '/admin/book-types/' . $type['id'] . '/prompt/optimize'],
            ['POST', '/admin/book-type-chapters/' . $chapterId . '/prompt/optimize'],
            ['POST', '/admin/book-types/' . $type['id'] . '/test'],
        ];

        foreach ($routes as [$method, $path]) {
            [$status, $body] = $this->api($method, $path, $method === 'GET' ? null : [], 'client');
            $this->assertContains($status, [401, 403], "$method $path pour un non-admin");
            $this->assertIsString($body['error'] ?? $body['message'] ?? null, "$method $path : { error } ou { message } pour le front");
            $this->assertArrayNotHasKey('trace', $body, "$method $path : aucune trace d'exécution exposée");
            [$status] = $this->api($method, $path, $method === 'GET' ? null : [], null);
            $this->assertContains($status, [401, 403], "$method $path sans connexion");
        }

        // Rien n'a été modifié par ces tentatives
        $this->assertEquals($type, $this->typeByCode('famille'));
    }

    public function testCookieAuthenticationRequiresMatchingXsrfToken(): void
    {
        // Le front s'authentifie par cookie (auth_token_<tenant>) et double le jeton XSRF en en-tête
        $type = $this->typeByCode('hommage');
        $jwt = $this->tokenFor('admin');
        $send = function (?string $header) use ($type, $jwt) {
            $this->client->getCookieJar()->clear();
            $this->client->getCookieJar()->set(new \Symfony\Component\BrowserKit\Cookie('auth_token_' . MV_TEST_TENANT_CODE, $jwt));
            $this->client->getCookieJar()->set(new \Symfony\Component\BrowserKit\Cookie('XSRF-TOKEN_' . MV_TEST_TENANT_CODE, 'xsrf-ok'));
            $server = ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
            if ($header !== null) {
                $server['HTTP_X_XSRF_TOKEN'] = $header;
            }
            $this->client->request('PUT', '/api/memoires/admin/book-types/' . $type['id'] . '?locale=fr', [], [], $server, json_encode(['displayOrder' => $type['displayOrder']]));
            return [$this->client->getResponse()->getStatusCode(), json_decode($this->client->getResponse()->getContent(), true)];
        };

        [$status, $body] = $send('xsrf-ok');
        $this->assertSame(200, $status, json_encode($body));
        [$status, $body] = $send('mauvais');
        $this->assertSame(403, $status);
        $this->assertArrayHasKey('message', $body, 'Le front lit « message » à défaut de « error »');
        [$status] = $send(null);
        $this->assertSame(403, $status);
    }

    // ------------------------------------------------------------------ 1. Forme des réponses

    public function testListShape(): void
    {
        [$status, $list] = $this->api('GET', '/admin/book-types');
        $this->assertSame(200, $status);
        $this->assertTrue(array_is_list($list), "tableau attendu");
        $this->assertEmpty(array_diff(self::LEGACY_CODES, array_column($list, 'code')));
        foreach ($list as $row) {
            $this->assertKeys(self::SUMMARY_KEYS, $row, 'ligne de liste');
            $this->assertContains($row['family'], ['direct', 'collectif']);
            $this->assertContains($row['speakerCount'], [1, 2, null]);
            $this->assertContains($row['promptSource'], ['code', 'database']);
            $this->assertIsInt($row['usage']['books']);
            $this->assertIsInt($row['chaptersCount']);
            $this->assertIsInt($row['rolesCount']);
        }
    }

    public function testDetailShapeAndLegacyTypes(): void
    {
        foreach (self::LEGACY_CODES as $code) {
            $type = $this->typeByCode($code);
            $this->assertKeys(array_merge(self::SUMMARY_KEYS, self::DETAIL_KEYS), $type, "détail $code");
            $this->assertTrue($type['isSystem'], "$code est historique");
            $this->assertNotEmpty($type['chapters'], "$code a des chapitres");
            $this->assertNotEmpty($type['promptRaw'], "$code a sa consigne de référence");
            $this->assertSame(count($type['chapters']), $type['chaptersCount']);
            $this->assertSame(count($type['roles']), $type['rolesCount']);
            if ($type['family'] === 'collectif') {
                $this->assertNotEmpty($type['roles'], "$code (collectif) a des rôles");
            }
            foreach ($type['chapters'] as $chapter) {
                $this->assertKeys(self::CHAPTER_KEYS, $chapter, "chapitre de $code");
                $this->assertIsInt($chapter['usage']['bookChapters']);
                foreach ($chapter['questions'] as $question) {
                    $this->assertKeys(self::QUESTION_KEYS, $question, "question de $code");
                    $this->assertIsInt($question['usage']['answeredBooks'], 'usage.answeredBooks est un entier');
                    $this->assertTrue($question['role'] === null || is_string($question['role']));
                }
            }
            foreach ($type['roles'] as $role) {
                $this->assertKeys(self::ROLE_KEYS, $role, "rôle de $code");
                $this->assertIsInt($role['usage']['contributors']);
            }
        }

        // Les livres réels de la copie ont répondu à des questions : le compteur n'est pas toujours nul
        $answered = array_filter($this->questions($this->typeByCode('individuel')), fn ($q) => $q['usage']['answeredBooks'] > 0);
        $this->assertNotEmpty($answered);
    }

    public function testPromptVariablesAndAiModels(): void
    {
        [$status, $variables] = $this->api('GET', '/admin/book-types/prompt-variables');
        $this->assertSame(200, $status);
        $this->assertTrue(array_is_list($variables), "tableau attendu");
        foreach ($variables as $variable) {
            $this->assertKeys(['name', 'description'], $variable, 'variable');
            $this->assertMatchesRegularExpression('/^\{[a-z0-9_]+\}$/', $variable['name'], 'les accolades font partie du nom');
        }
        $this->assertContains('{prenom1}', array_column($variables, 'name'));

        [$status, $models] = $this->api('GET', '/ai-models');
        $this->assertSame(200, $status);
        $this->assertTrue(array_is_list($models), "tableau attendu");
        foreach ($models as $model) {
            $this->assertKeys(['id', 'name'], $model, 'modèle IA');
        }
    }

    // ------------------------------------------------------------------ 2. Mises à jour partielles

    public function testPartialUpdatesOnType(): void
    {
        $type = $this->createType(['family' => 'collectif', 'label' => 'Partiel collectif']);
        foreach (['ami', 'collegue'] as $i => $code) {
            $this->api('POST', "/admin/book-types/{$type['id']}/roles", ['code' => $code, 'label' => ucfirst($code), 'displayOrder' => $i + 1]);
        }
        $type = $this->reload($type);

        $this->assertPartialUpdate($type, ['isActive' => true]);
        $this->assertPartialUpdate($this->reload($type), ['isActive' => false]);
        $this->assertPartialUpdate($this->reload($type), ['defaultRole' => 'ami']);
        $this->assertPartialUpdate($this->reload($type), ['defaultRole' => null]);
        $this->assertPartialUpdate($this->reload($type), ['defaultRoleWhenSubjectsAbsent' => null]);
        $after = $this->assertPartialUpdate($this->reload($type), ['promptRaw' => 'Consigne brute', 'promptOptimized' => ''], ['promptOptimized' => null]);
        $this->assertNull($after['promptOptimized'], 'optimisée vide = consigne brute utilisée');

        // Onglet Général d'un type collectif : les libellés des sujets sont enregistrés
        $general = ['label' => 'Collectif renommé', 'description' => 'Desc', 'displayOrder' => 7, 'speaker1Label' => 'La personne honorée', 'speaker2Label' => 'Son conjoint', 'subjectsMayBeAbsent' => true, 'isActive' => false];
        $after = $this->assertPartialUpdate($this->reload($type), $general);
        $this->assertSame('La personne honorée', $after['speaker1Label']);
        $this->assertSame('Son conjoint', $after['speaker2Label']);

        // Type historique : bascule de la source des consignes, puis retour
        $system = $this->typeByCode('individuel');
        $this->assertPartialUpdate($system, ['promptSource' => 'database']);
        $this->assertPartialUpdate($this->reload($system), ['promptSource' => 'code']);
    }

    public function testGeneralTabOnDirectTypes(): void
    {
        // Direct à 1 interlocuteur : speaker2Label vide
        $type = $this->createType(['family' => 'direct', 'speakerCount' => 1, 'label' => 'Direct solo']);
        $body = ['label' => 'Direct solo renommé', 'description' => 'D', 'displayOrder' => 3, 'speaker1Label' => 'Vous', 'speakerCount' => 1, 'speaker2Label' => '', 'isActive' => false];
        $after = $this->assertPartialUpdate($type, $body, ['speaker2Label' => null]);
        $this->assertNull($after['speaker2Label']);

        // Direct utilisé par des livres (couple) : même corps sans speakerCount, sans erreur
        $couple = $this->typeByCode('couple');
        $this->assertGreaterThan(0, $couple['usage']['books']);
        $body = ['label' => $couple['label'], 'description' => 'Deux voix', 'displayOrder' => $couple['displayOrder'], 'speaker1Label' => 'Elle', 'speaker2Label' => 'Lui', 'isActive' => true];
        $this->assertPartialUpdate($couple, $body);
    }

    public function testPartialUpdatesOnChapterQuestionAndRole(): void
    {
        $type = $this->createType(['family' => 'collectif']);
        $this->api('POST', "/admin/book-types/{$type['id']}/roles", ['code' => 'ami', 'label' => 'Ami', 'displayOrder' => 1]);
        [, $r] = $this->api('POST', "/admin/book-types/{$type['id']}/chapters", ['code' => 'souvenirs', 'title' => 'Souvenirs', 'speaker' => 'contributors', 'isActive' => true]);
        $chapter = $r['bookType']['chapters'][0];

        foreach ([['isActive' => false], ['title' => 'Nouveaux souvenirs', 'speaker' => 'synthesis'], ['promptRaw' => 'Brute', 'promptOptimized' => 'Optimisée']] as $body) {
            $before = $this->chapter($this->reload($type), 'souvenirs');
            [$status, $res] = $this->api('PUT', "/admin/book-type-chapters/{$chapter['id']}", $body);
            $this->assertSame(200, $status, json_encode($res));
            $after = $this->chapter($res['bookType'], 'souvenirs');
            $this->assertSame(array_merge($before, $body), $after, 'seuls les champs envoyés changent');
        }

        [, $r] = $this->api('POST', "/admin/book-type-chapters/{$chapter['id']}/questions", ['questionText' => 'Une question ?', 'tip' => 'Un conseil', 'role' => 'ami']);
        $question = $this->questions($r['bookType'])[0];
        [$status, $r] = $this->api('PUT', "/admin/questions/{$question['id']}", ['isActive' => false]);
        $this->assertSame(200, $status);
        [$status, $r] = $this->api('PUT', "/admin/questions/{$question['id']}", ['isActive' => true]);
        $this->assertSame(200, $status);
        $this->assertSame(array_merge($question, ['isActive' => true]), $this->question($r['bookType'], $question['id']));
        [$status, $r] = $this->api('PUT', "/admin/questions/{$question['id']}", ['questionText' => 'Question reformulée ?', 'tip' => '', 'role' => null]);
        $this->assertSame(200, $status, json_encode($r));
        $updated = $this->question($r['bookType'], $question['id']);
        $this->assertSame('Question reformulée ?', $updated['questionText']);
        $this->assertNull($updated['tip']);
        $this->assertNull($updated['role']);

        $role = $this->role($this->reload($type), 'ami');
        [$status, $r] = $this->api('PUT', "/admin/book-type-roles/{$role['id']}", ['label' => 'Ami(e)', 'displayOrder' => 5]);
        $this->assertSame(200, $status);
        $this->assertSame(array_merge($role, ['label' => 'Ami(e)', 'displayOrder' => 5]), $this->role($r['bookType'], 'ami'));
    }

    // ------------------------------------------------------------------ 3. Scénario complet

    public function testFullScenario(): void
    {
        // 1. Création d'un type collectif
        $create = ['code' => 'depart_retraite', 'label' => 'Départ en retraite', 'family' => 'collectif', 'description' => '', 'speaker1Label' => 'La personne honorée', 'speaker2Label' => '', 'subjectsMayBeAbsent' => true, 'displayOrder' => 0];
        [$status, $r] = $this->api('POST', '/admin/book-types', $create);
        $this->assertSame(201, $status, json_encode($r));
        $type = $r['bookType'];
        $this->assertFalse($type['isActive']);
        $this->assertSame('database', $type['promptSource']);
        $this->assertKeys(array_merge(self::SUMMARY_KEYS, self::DETAIL_KEYS), $type, 'réponse de création');
        $id = $type['id'];

        // 2. Doublon et code invalide
        [$status, $r] = $this->api('POST', '/admin/book-types', $create);
        $this->assertSame(409, $status);
        $this->assertIsString($r['error']);
        [$status, $r] = $this->api('POST', '/admin/book-types', ['code' => '2abc'] + $create);
        $this->assertSame(422, $status);
        $this->assertIsString($r['error']);

        // 3. Rôles
        foreach ([['collegue', 'Collègue', 1], ['ami', 'Ami(e)', 2]] as [$code, $label, $order]) {
            [$status, $r] = $this->api('POST', "/admin/book-types/$id/roles", ['code' => $code, 'label' => $label, 'displayOrder' => $order]);
            $this->assertSame(201, $status, json_encode($r));
            $this->assertArrayHasKey('bookType', $r);
        }

        // 4. Chapitres ajoutés en fin, positions croissantes ; interlocuteur incompatible refusé
        [$status, $r] = $this->api('POST', "/admin/book-types/$id/chapters", ['code' => 'carriere', 'title' => 'Carrière', 'speaker' => 'contributors', 'isActive' => true]);
        $this->assertSame(201, $status, json_encode($r));
        [$status, $r] = $this->api('POST', "/admin/book-types/$id/chapters", ['code' => 'synthese', 'title' => 'Synthèse', 'speaker' => 'synthesis', 'isActive' => true]);
        $this->assertSame(201, $status);
        $this->assertSame(['carriere', 'synthese'], array_column($r['bookType']['chapters'], 'code'));
        $this->assertLessThan($r['bookType']['chapters'][1]['position'], $r['bookType']['chapters'][0]['position']);
        [$status, $r] = $this->api('POST', "/admin/book-types/$id/chapters", ['code' => 'x', 'title' => 'X', 'speaker' => 'person1']);
        $this->assertSame(422, $status);
        $solo = $this->createType(['family' => 'direct', 'speakerCount' => 1]);
        [$status] = $this->api('POST', "/admin/book-types/{$solo['id']}/chapters", ['code' => 'x', 'title' => 'X', 'speaker' => 'both']);
        $this->assertSame(422, $status);

        // 5. Questions
        $type = $this->reload(['id' => $id]);
        $carriere = $this->chapter($type, 'carriere');
        [$status, $r] = $this->api('POST', "/admin/book-type-chapters/{$carriere['id']}/questions", ['questionText' => 'Q commune', 'tip' => '', 'role' => null]);
        $this->assertSame(201, $status);
        // ÉCART DOCUMENTÉ : le même texte pour le rôle « collegue » est refusé (409). Un collègue recevrait
        // déjà « Q commune » (question commune) : deux questions identiques seraient confondues, les réponses
        // étant reliées à leur question par le texte. Le front doit utiliser un texte différent.
        [$status, $r] = $this->api('POST', "/admin/book-type-chapters/{$carriere['id']}/questions", ['questionText' => 'Q commune', 'tip' => '', 'role' => 'collegue']);
        $this->assertSame(409, $status);
        $this->assertIsString($r['error']);
        foreach ([['Q collègue 1', 'collegue'], ['Q collègue 2', 'collegue'], ['Q ami', 'ami']] as [$text, $role]) {
            [$status, $r] = $this->api('POST', "/admin/book-type-chapters/{$carriere['id']}/questions", ['questionText' => $text, 'tip' => '', 'role' => $role]);
            $this->assertSame(201, $status, json_encode($r));
        }
        [$status] = $this->api('POST', "/admin/book-type-chapters/{$carriere['id']}/questions", ['questionText' => 'Q ami', 'tip' => '', 'role' => 'ami']);
        $this->assertSame(409, $status, 'même texte pour le même public');

        // 6. Ordre des chapitres inversé
        $type = $this->reload(['id' => $id]);
        $ids = array_reverse(array_column($type['chapters'], 'id'));
        [$status, $r] = $this->api('PUT', "/admin/book-types/$id/chapters/order", ['ids' => $ids]);
        $this->assertSame(200, $status);
        $this->assertSame($ids, array_column($r['bookType']['chapters'], 'id'));

        // 7. Réordonnancement du seul groupe « collegue », question archivée incluse
        $questions = $this->chapter($r['bookType'], 'carriere')['questions'];
        $byText = array_column($questions, null, 'questionText');
        $c1 = $byText['Q collègue 1'];
        $c2 = $byText['Q collègue 2'];
        [$status] = $this->api('PUT', "/admin/questions/{$c1['id']}", ['isActive' => false]);
        $this->assertSame(200, $status);
        [$status, $r] = $this->api('PUT', "/admin/book-type-chapters/{$carriere['id']}/questions/order", ['ids' => [$c2['id'], $c1['id']]]);
        $this->assertSame(200, $status, json_encode($r));
        $after = array_column($this->chapter($r['bookType'], 'carriere')['questions'], null, 'questionText');
        $this->assertSame($c1['displayOrder'], $after['Q collègue 2']['displayOrder'], 'les deux questions échangent leur place');
        $this->assertSame($c2['displayOrder'], $after['Q collègue 1']['displayOrder']);
        $this->assertSame($byText['Q commune']['displayOrder'], $after['Q commune']['displayOrder'], 'question commune immobile');
        $this->assertSame($byText['Q ami']['displayOrder'], $after['Q ami']['displayOrder'], 'question « ami » immobile');

        // 8. Test sans IA (dryRun)
        [$status, $r] = $this->api('POST', "/admin/book-types/$id/test", ['chapterCode' => 'carriere', 'tone' => 'intime et chaleureux', 'subjectsAbsent' => true, 'dryRun' => true]);
        $this->assertSame(200, $status, json_encode($r));
        $this->assertKeys(['chapterCode', 'model', 'sample', 'prompt', 'text'], $r, 'réponse du test');
        $this->assertIsString($r['prompt']);
        $this->assertEmpty($r['text']);
        $this->assertSame([], FakeAnthropicService::$calls, 'dryRun : aucun appel à l\'IA');

        // 9. Test avec IA (simulée) : chapitre de contributeurs...
        [$status, $r] = $this->api('POST', "/admin/book-types/$id/test", ['chapterCode' => 'carriere', 'tone' => 'intime et chaleureux', 'subjectsAbsent' => true]);
        $this->assertSame(200, $status, json_encode($r));
        $this->assertIsString($r['text']);
        $this->assertNotEmpty($r['text']);
        $this->assertSame([], $r['sample']['answers']);
        $this->assertNotEmpty($r['sample']['contributorAnswers']);
        foreach ($r['sample']['contributorAnswers'] as $contributor) {
            $this->assertKeys(['id', 'firstName', 'contributorName', 'role', 'answers'], $contributor, 'contributeur fictif');
            foreach ($contributor['answers'] as $answer) {
                $this->assertKeys(['index', 'question', 'answer', 'skipped'], $answer, 'réponse fictive');
            }
        }
        // ... et chapitre « person1 » d'un type direct
        [, $rr] = $this->api('POST', "/admin/book-types/{$solo['id']}/chapters", ['code' => 'enfance', 'title' => 'Enfance', 'speaker' => 'person1']);
        $enfance = $this->chapter($rr['bookType'], 'enfance');
        $this->api('POST', "/admin/book-type-chapters/{$enfance['id']}/questions", ['questionText' => 'Où êtes-vous né ?', 'tip' => '', 'role' => null]);
        [$status, $r] = $this->api('POST', "/admin/book-types/{$solo['id']}/test", ['chapterCode' => 'enfance']);
        $this->assertSame(200, $status, json_encode($r));
        $this->assertSame([], $r['sample']['contributorAnswers']);
        $this->assertNotEmpty($r['sample']['answers']);
        foreach ($r['sample']['answers'] as $answer) {
            $this->assertKeys(['index', 'question', 'answer', 'skipped'], $answer, 'réponse fictive');
        }

        // 10. Amélioration de consigne, avec et sans modèle : rien n'est enregistré
        $before = $this->reload(['id' => $id]);
        foreach ([['promptRaw' => 'Une consigne à améliorer', 'model' => 'claude-sonnet-5'], ['promptRaw' => 'Une consigne à améliorer']] as $body) {
            [$status, $r] = $this->api('POST', "/admin/book-types/$id/prompt/optimize", $body);
            $this->assertSame(200, $status, json_encode($r));
            $this->assertIsString($r['suggestion']);
            $this->assertNotEmpty($r['suggestion']);
        }
        [$status, $r] = $this->api('POST', "/admin/book-type-chapters/{$carriere['id']}/prompt/optimize", ['promptRaw' => 'Consigne de chapitre']);
        $this->assertSame(200, $status);
        $this->assertIsString($r['suggestion']);
        $this->assertEquals($this->withoutUpdatedAt($before), $this->withoutUpdatedAt($this->reload(['id' => $id])), 'aucune modification enregistrée');

        // 11. Activation
        [$status, $r] = $this->api('PUT', "/admin/book-types/$id", ['isActive' => true]);
        $this->assertSame(200, $status, json_encode($r));
        $this->assertTrue($r['bookType']['isActive']);
    }

    // ------------------------------------------------------------------ 4. Suppressions et archivage

    public function testQuestionDeletionAndArchiving(): void
    {
        // Question sans réponse : supprimée
        $type = $this->createType(['family' => 'direct', 'speakerCount' => 1]);
        [, $r] = $this->api('POST', "/admin/book-types/{$type['id']}/chapters", ['code' => 'c1', 'title' => 'C1', 'speaker' => 'person1']);
        $chapterId = $r['bookType']['chapters'][0]['id'];
        [, $r] = $this->api('POST', "/admin/book-type-chapters/$chapterId/questions", ['questionText' => 'Sans réponse ?', 'tip' => '', 'role' => null]);
        $question = $this->questions($r['bookType'])[0];
        $this->assertSame(0, $question['usage']['answeredBooks']);
        [$status, $r] = $this->api('DELETE', "/admin/questions/{$question['id']}");
        $this->assertSame(200, $status);
        $this->assertSame('deleted', $r['result']);
        $this->assertArrayHasKey('bookType', $r);
        $this->assertEmpty($this->questions($r['bookType']));

        // Question avec réponses (livres réels de la copie) : archivée puis restaurée
        $individuel = $this->typeByCode('individuel');
        $answered = array_values(array_filter($this->questions($individuel), fn ($q) => $q['usage']['answeredBooks'] > 0 && $q['isActive']))[0];
        $this->assertSame($this->countAnsweredBooks('individuel', $answered['questionText']), $answered['usage']['answeredBooks'], 'nombre de livres distincts');
        [$status, $r] = $this->api('DELETE', "/admin/questions/{$answered['id']}");
        $this->assertSame(200, $status);
        $this->assertSame('archived', $r['result']);
        $this->assertFalse($this->question($r['bookType'], $answered['id'])['isActive']);
        [$status, $r] = $this->api('PUT', "/admin/questions/{$answered['id']}", ['isActive' => true]);
        $this->assertSame(200, $status);
        $this->assertTrue($this->question($r['bookType'], $answered['id'])['isActive']);
    }

    public function testRoleDeletionRules(): void
    {
        $type = $this->createType(['family' => 'collectif']);
        foreach (['collegue', 'ami', 'voisin', 'libre'] as $i => $code) {
            $this->api('POST', "/admin/book-types/{$type['id']}/roles", ['code' => $code, 'label' => ucfirst($code), 'displayOrder' => $i + 1]);
        }
        [, $r] = $this->api('POST', "/admin/book-types/{$type['id']}/chapters", ['code' => 'c1', 'title' => 'C1', 'speaker' => 'contributors']);
        $chapterId = $r['bookType']['chapters'][0]['id'];
        [, $r] = $this->api('POST', "/admin/book-type-chapters/$chapterId/questions", ['questionText' => 'Pour collègue ?', 'tip' => '', 'role' => 'collegue']);
        $questionId = $this->questions($r['bookType'])[0]['id'];
        $this->api('PUT', "/admin/questions/$questionId", ['isActive' => false]); // archivée
        $this->api('PUT', "/admin/book-types/{$type['id']}", ['defaultRole' => 'ami', 'defaultRoleWhenSubjectsAbsent' => 'voisin']);
        $type = $this->reload($type);

        foreach (['collegue' => 'questions archivées', 'ami' => 'rôle par défaut', 'voisin' => 'rôle quand les sujets sont absents'] as $code => $reason) {
            [$status, $r] = $this->api('DELETE', '/admin/book-type-roles/' . $this->role($type, $code)['id']);
            $this->assertSame(409, $status, "rôle « $code » : $reason");
            $this->assertIsString($r['error']);
        }
        // Rôle attribué à des contributeurs (famille / enfant)
        $famille = $this->typeByCode('famille');
        $this->assertGreaterThan(0, $this->role($famille, 'enfant')['usage']['contributors']);
        [$status] = $this->api('DELETE', '/admin/book-type-roles/' . $this->role($famille, 'enfant')['id']);
        $this->assertSame(409, $status);
        // Rôle libre : supprimé
        [$status, $r] = $this->api('DELETE', '/admin/book-type-roles/' . $this->role($type, 'libre')['id']);
        $this->assertSame(200, $status);
        $this->assertNotContains('libre', array_column($r['bookType']['roles'], 'code'));
    }

    public function testChapterAndTypeDeletionRules(): void
    {
        // Chapitre utilisé par des livres
        $individuel = $this->typeByCode('individuel');
        $enfance = $this->chapter($individuel, 'enfance');
        $this->assertGreaterThan(0, $enfance['usage']['bookChapters']);
        [$status, $r] = $this->api('DELETE', "/admin/book-type-chapters/{$enfance['id']}");
        $this->assertSame(409, $status);
        $this->assertIsString($r['error']);

        // Type historique
        [$status, $r] = $this->api('DELETE', "/admin/book-types/{$individuel['id']}");
        $this->assertSame(409, $status);
        $this->assertIsString($r['error']);

        // Type utilisé par un livre
        $used = $this->createType(['family' => 'direct', 'speakerCount' => 1]);
        $this->attachBook($used['code']);
        $this->assertSame(1, $this->reload($used)['usage']['books']);
        [$status] = $this->api('DELETE', "/admin/book-types/{$used['id']}");
        $this->assertSame(409, $status);

        // Type libre : supprimé
        $free = $this->createType(['family' => 'direct', 'speakerCount' => 1]);
        [$status, $r] = $this->api('DELETE', "/admin/book-types/{$free['id']}");
        $this->assertSame(200, $status);
        $this->assertSame(['deleted' => true], $r);

        // Source « code » réservée aux types historiques
        [$status, $r] = $this->api('PUT', "/admin/book-types/{$used['id']}", ['promptSource' => 'code']);
        $this->assertSame(422, $status);
        $this->assertIsString($r['error']);
    }

    public function testNotFoundUsesErrorKey(): void
    {
        foreach ([['GET', '/admin/book-types/999999'], ['PUT', '/admin/book-type-chapters/999999'], ['DELETE', '/admin/questions/999999']] as [$method, $path]) {
            [$status, $r] = $this->api($method, $path, $method === 'GET' ? null : []);
            $this->assertSame(404, $status, "$method $path");
            $this->assertIsString($r['error'] ?? null, "$method $path renvoie { error }");
            $this->assertStringContainsString('introuvable', $r['error'], 'message en français');
        }
    }

    // ------------------------------------------------------------------ Outils

    private function createType(array $overrides): array
    {
        $body = $overrides + ['code' => self::uniqueCode('t'), 'label' => 'Type de test'];
        [$status, $r] = $this->api('POST', '/admin/book-types', $body);
        $this->assertSame(201, $status, json_encode($r));
        return $r['bookType'];
    }

    private function reload(array $type): array
    {
        [$status, $r] = $this->api('GET', '/admin/book-types/' . $type['id']);
        $this->assertSame(200, $status);
        return $r['bookType'];
    }

    /**
     * Envoie une mise à jour partielle et vérifie que seuls les champs envoyés ont changé.
     *
     * @param array $expected valeurs attendues quand elles diffèrent de celles envoyées (ex. "" → null)
     */
    private function assertPartialUpdate(array $before, array $body, array $expected = []): array
    {
        [$status, $r] = $this->api('PUT', '/admin/book-types/' . $before['id'], $body);
        $this->assertSame(200, $status, 'PUT ' . json_encode($body) . ' → ' . json_encode($r));
        $after = $r['bookType'];
        $this->assertKeys(array_merge(self::SUMMARY_KEYS, self::DETAIL_KEYS), $after, 'réponse de mise à jour');
        foreach (array_merge($body, $expected) as $key => $value) {
            $this->assertSame($value, $after[$key], "champ $key");
        }
        $this->assertEquals(
            array_diff_key($this->withoutUpdatedAt($before), $body),
            array_diff_key($this->withoutUpdatedAt($after), $body),
            'les champs non envoyés sont inchangés'
        );
        return $after;
    }

    private function withoutUpdatedAt(array $type): array
    {
        unset($type['updatedAt']);
        return $type;
    }

    private function assertKeys(array $keys, array $data, string $what): void
    {
        $missing = array_diff($keys, array_keys($data));
        $this->assertSame([], array_values($missing), "$what : champs manquants");
    }

    private function countAnsweredBooks(string $type, string $questionText): int
    {
        $rows = self::db()->prepare('SELECT c.book_id, c.answers, c.contributor_answers FROM mv_chapter c JOIN mv_book b ON b.id = c.book_id WHERE b.type = ?');
        $rows->execute([$type]);
        $books = [];
        foreach ($rows as $row) {
            // Calcul indépendant du backend : une réponse compte si l'un de ses champs de contenu est rempli
            $entries = json_decode($row['answers'] ?? '[]', true) ?: [];
            foreach (json_decode($row['contributor_answers'] ?? 'null', true) ?: [] as $contributor) {
                $entries = array_merge($entries, $contributor['answers'] ?? []);
            }
            $texts = [];
            foreach ($entries as $entry) {
                $filled = array_filter(['answer', 'improvedAnswer', 'improved_answer', 'audioUrl', 'audioUrl1', 'audioUrl2'], fn ($f) => trim((string) ($entry[$f] ?? '')) !== '');
                if (($entry['question'] ?? null) !== null && $filled) {
                    $texts[] = $entry['question'];
                }
            }
            if (in_array($questionText, $texts, true)) {
                $books[$row['book_id']] = true;
            }
        }
        return count($books);
    }

    /** Crée un livre du type donné, à partir d'un livre existant de la copie */
    private function attachBook(string $typeCode): void
    {
        $db = self::db();
        $db->exec('CREATE TEMP TABLE book_copy AS SELECT * FROM mv_book LIMIT 1');
        $db->prepare("UPDATE book_copy SET id = gen_random_uuid(), type = ?, title = 'Livre de test'")->execute([$typeCode]);
        $db->exec('INSERT INTO mv_book SELECT * FROM book_copy');
        $db->exec('DROP TABLE book_copy');
    }
}
