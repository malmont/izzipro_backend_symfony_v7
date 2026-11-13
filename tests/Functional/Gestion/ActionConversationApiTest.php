<?php

namespace App\Tests\Functional\Gestion;

use App\Entity\Transporteur;
use App\Entity\TypeFournisseur;
use App\Entity\TypeNoteDeFrais;
use App\Entity\User;
use App\EventSubscriber\CacheInvalidationSubscriber;
use App\Gestion\Message\ReadGestionDocumentMessage;
use App\Gestion\MessageHandler\ReadGestionDocumentHandler;
use App\Gestion\Sequences\SequenceCatalog;
use App\Gestion\Services\Conversation\Action\ActionCatalog;
use App\Gestion\Services\PeriodCalendar;
use App\Services\TenantEntityManagerProvider;
use App\Tests\Fake\FakeLandingAiClient;
use App\Tests\Functional\Cache\CacheTestEntityFactory;
use App\Tests\Functional\Support\AiRightTestHelper;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Actions préparables par le fil de la gestion (lot IA-ACT-1, 13/11/2026, `docs/conversation-gestion.md` § 9) : note de frais,
 * fournisseur, frais de port d'une commande, budget d'une période. Pour chacune : ouverture depuis le catalogue (liste blanche), une
 * question par paramètre, réponses par bouton sans IA, aperçu et empreinte, confirmation par le service de la route existante,
 * annulation d'un bloc sous 24 heures avec chacun de ses refus. La phrase libre ne garde que ce que le gérant a écrit.
 */
class ActionConversationApiTest extends WebTestCase
{
    use CacheTestEntityFactory;

    private KernelBrowser $client;
    private string $token = '';
    private string $tag = '';
    private int $usageMark = 0;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        FakeLandingAiClient::reset();
        AiRightTestHelper::restoreFullAi(static::getContainer());
        $this->tag = bin2hex(random_bytes(3));
        $this->login(['ROLE_ADMIN']);
        $this->usageMark = (int) $this->em()->getConnection()->fetchOne('SELECT COALESCE(MAX(id), 0) FROM ai_usage');
    }

    /** Base de test partagée : les unités d'IA consommées ici sont rendues */
    protected function tearDown(): void
    {
        $this->em()->getConnection()->executeStatement('DELETE FROM ai_usage WHERE id > ?', [$this->usageMark]);
        parent::tearDown();
    }

    public function testAnExpenseIsPreparedByButtonsConfirmedThenUndoneOnce(): void
    {
        $calendar = static::getContainer()->get(PeriodCalendar::class);
        $period = $calendar->current();
        $pid = (int) $period->getId();
        $periodName = PeriodCalendar::displayName($period, 'fr');
        $today = $calendar->today()->format('Y-m-d');
        $type = $this->noteType();
        $conn = fn () => $this->em()->getConnection();
        $notes = fn (): int => (int) $conn()->fetchOne('SELECT COUNT(*) FROM note_de_frais');
        $units = fn (): int => (int) $conn()->fetchOne("SELECT COUNT(*) FROM ai_usage WHERE status = 'success'");
        [$notesBefore, $unitsBefore] = [$notes(), $units()];

        // Ouverture : un fil sans document, une question par paramètre, rien d'écrit hors du fil
        $thread = $this->post('/api/gestion/conversations', ['action' => 'expense.create'], 201);
        $id = (int) $thread['id'];
        $this->assertSame(['id', 'kind', 'state', 'locale', 'createdAt', 'updatedAt', 'document', 'action', 'summary', 'messages', 'questions', 'openQuestions', 'issues', 'preview', 'previewHash', 'undo', 'credits'], array_keys($thread));
        $this->assertSame(['expense.create', 'questions', null, 4, [], null, null, null], [$thread['kind'], $thread['state'], $thread['document'], $thread['openQuestions'], $thread['issues'], $thread['preview'], $thread['previewHash'], $thread['undo']]);
        $this->assertSame(['key' => 'expense.create', 'title' => 'Saisir une note de frais', 'available' => true, 'result' => null], $thread['action']);
        $this->assertSame(['text' => 'Saisir une note de frais : Il me manque 4 réponse(s) avant de vous montrer ce qui sera créé.', 'openQuestions' => 4], $thread['summary']);
        $this->assertSame(['amount', 'date', 'typeNoteDeFraisId', 'description'], array_column($thread['questions'], 'id'));
        $this->assertSame(['expense.create.amount', 'expense.create.date', 'expense.create.typeNoteDeFraisId', 'expense.create.description'], array_column($thread['questions'], 'kind'));
        $this->assertSame(['money', 'date', 'choice', 'text'], array_map(fn (array $q) => $q['input']['type'], $thread['questions']));
        $this->assertSame([['Montant', 'Quel est le montant de la note de frais ?', true, null, false, 'CAD']], [[$thread['questions'][0]['title'], $thread['questions'][0]['text'], $thread['questions'][0]['required'], $thread['questions'][0]['answer'], $thread['questions'][0]['proposed'], $thread['questions'][0]['input']['currency']]]);
        $this->assertContains(['value' => $type, 'label' => 'Déplacement'], $thread['questions'][2]['options']);
        $this->assertSame([['user', 'action', ['action' => 'expense.create', 'params' => []]]], array_map(fn (array $m) => [$m['role'], $m['kind'], $m['content']], $thread['messages']));
        $this->assertSame('Enter an expense', $this->get("/api/gestion/conversations/$id?locale=en")['action']['title']);
        $this->assertSame('Amount', $this->get("/api/gestion/conversations/$id?locale=en")['questions'][0]['title']);

        // Confirmer trop tôt, réponses refusées : rien ne bouge
        $refused = $this->post("/api/gestion/conversations/$id/confirm", ['previewHash' => str_repeat('0', 64)], 409);
        $this->assertSame(['CONVERSATION_NOT_READY', 'questions', 4, []], [$refused['error'], $refused['state'], $refused['openQuestions'], $refused['issues']]);
        $closedDay = \DateTimeImmutable::createFromInterface($period->getStartDateCollection())->modify('-1 year');
        $calendar->ensurePeriods($closedDay);
        $this->assertTrue($calendar->isClosed($calendar->findByDate($closedDay)));
        foreach ([
            [['questionId' => 'amount', 'value' => 0], ['answers[0].value' => 'MUST_BE_POSITIVE']],
            [['questionId' => 'amount', 'value' => '12'], ['answers[0].value' => 'INVALID_AMOUNT']],
            [['questionId' => 'amount', 'value' => -5], ['answers[0].value' => 'INVALID_AMOUNT']],
            [['questionId' => 'date', 'value' => '2026-13-45'], ['answers[0].value' => 'INVALID_DATE']],
            [['questionId' => 'date', 'value' => $closedDay->format('Y-m-d')], ['answers[0].value' => 'PERIOD_CLOSED']],
            [['questionId' => 'typeNoteDeFraisId', 'value' => 999999], ['answers[0].value' => 'TYPE_NOTE_DE_FRAIS_NOT_FOUND']],
            [['questionId' => 'description', 'value' => '   '], ['answers[0].value' => 'REQUIRED']],
            [['questionId' => 'pigeon', 'value' => 1], ['answers[0].questionId' => 'UNKNOWN_QUESTION']],
        ] as [$answer, $fields]) {
            $this->assertSame($fields, $this->post("/api/gestion/conversations/$id/answers", ['answers' => [$answer]], 422)['fields'], json_encode($answer));
        }
        $this->assertSame(4, $this->get("/api/gestion/conversations/$id")['openQuestions']);

        // Réponses par bouton : gratuites, aucun appel d'IA ; l'aperçu et son empreinte arrivent quand tout est répondu
        $partial = $this->post("/api/gestion/conversations/$id/answers", ['answers' => [['questionId' => 'amount', 'value' => 4550], ['questionId' => 'date', 'value' => $today], ['questionId' => 'typeNoteDeFraisId', 'value' => $type]]], 200);
        $this->assertSame(['questions', 1, null, null], [$partial['state'], $partial['openQuestions'], $partial['preview'], $partial['previewHash']]);
        $ready = $this->post("/api/gestion/conversations/$id/answers", ['answers' => [['questionId' => 'description', 'value' => '  Taxi aéroport ']]], 200);
        $this->assertSame(['ready', 0, []], [$ready['state'], $ready['openQuestions'], $ready['issues']]);
        $this->assertSame(['amount' => 4550, 'currency' => 'CAD', 'date' => $today, 'description' => 'Taxi aéroport', 'type' => ['id' => $type, 'name' => 'Déplacement'], 'period' => ['id' => $pid, 'name' => $periodName]], $ready['preview']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $ready['previewHash']);
        $this->assertSame('Saisir une note de frais : Tout est renseigné : vérifiez l\'aperçu, puis confirmez.', $ready['summary']['text']);
        $this->assertSame([4550, $today, $type, 'Taxi aéroport'], array_column($ready['questions'], 'answer'));
        // Une réponse changée change l'empreinte ; retirer une réponse rouvre la question
        $changed = $this->post("/api/gestion/conversations/$id/answers", ['answers' => [['questionId' => 'amount', 'value' => 4600]]], 200);
        $this->assertNotSame($ready['previewHash'], $changed['previewHash']);
        $reopened = $this->post("/api/gestion/conversations/$id/answers", ['answers' => [['questionId' => 'amount', 'value' => null]]], 200);
        $this->assertSame(['questions', 1, null], [$reopened['state'], $reopened['openQuestions'], $reopened['questions'][0]['answer']]);
        $again = $this->post("/api/gestion/conversations/$id/answers", ['answers' => [['questionId' => 'amount', 'value' => 4550]]], 200);
        $this->assertSame($ready['previewHash'], $again['previewHash'], 'mêmes réponses, même aperçu : même empreinte');
        $this->assertSame([$notesBefore, $unitsBefore, 0], [$notes(), $units(), count(FakeLandingAiClient::$requests)], 'rien d\'écrit, aucune unité, aucun appel d\'IA');

        // Confirmation : empreinte exigée et vérifiée ; la note est créée par le service de la route existante
        $this->assertSame(['previewHash' => 'REQUIRED'], $this->post("/api/gestion/conversations/$id/confirm", [], 422)['fields']);
        $stale = $this->post("/api/gestion/conversations/$id/confirm", ['previewHash' => $changed['previewHash']], 409);
        $this->assertSame(['PREVIEW_CHANGED', $ready['preview'], $ready['previewHash']], [$stale['error'], $stale['preview'], $stale['previewHash']]);
        $this->assertSame($notesBefore, $notes());
        $done = $this->post("/api/gestion/conversations/$id/confirm", ['previewHash' => $ready['previewHash']], 201);
        $this->assertSame(['result', 'undo', 'thread'], array_keys($done));
        $noteId = (int) $done['result']['noteDeFraisId'];
        $this->assertSame(['noteDeFraisId' => $noteId, 'periodId' => $pid], $done['result']);
        $this->assertSame(['available', 'until'], array_keys($done['undo']));
        $this->assertTrue($done['undo']['available']);
        $this->assertEqualsWithDelta(time() + 24 * 3600, strtotime($done['undo']['until']), 120);
        $row = $conn()->fetchAssociative('SELECT montant, description, date, type_note_de_frais_id, collection_id FROM note_de_frais WHERE id = ?', [$noteId]);
        $this->assertSame([4550, 'Taxi aéroport', $today, $type, $pid], [(int) $row['montant'], $row['description'], $row['date'], (int) $row['type_note_de_frais_id'], (int) $row['collection_id']]);
        $this->assertSame($notesBefore + 1, $notes());
        $confirmed = $done['thread'];
        $this->assertSame(['confirmed', [], 0, null, null, ['noteDeFraisId' => $noteId, 'periodId' => $pid]], [$confirmed['state'], $confirmed['questions'], $confirmed['openQuestions'], $confirmed['preview'], $confirmed['previewHash'], $confirmed['action']['result']]);
        $this->assertSame(['available' => true, 'until' => $done['undo']['until'], 'blockers' => []], $confirmed['undo']);
        $last = $confirmed['messages'][count($confirmed['messages']) - 1];
        $this->assertSame(['assistant', 'confirmed', ['action' => 'expense.create', 'result' => ['noteDeFraisId' => $noteId, 'periodId' => $pid], 'previewHash' => $ready['previewHash']]], [$last['role'], $last['kind'], $last['content']], 'ce qui sert à défaire reste au serveur');
        $this->assertSame('Saisir une note de frais : C\'est fait. Vous pouvez l\'annuler pendant 24 heures.', $confirmed['summary']['text']);
        // Un fil confirmé n'attend plus rien
        foreach ([["/api/gestion/conversations/$id/answers", ['answers' => [['questionId' => 'amount', 'value' => 1]]]], ["/api/gestion/conversations/$id/confirm", ['previewHash' => $ready['previewHash']]],
            ["/api/gestion/conversations/$id/abandon", []], ["/api/gestion/conversations/$id/messages", ['text' => 'mets 10']]] as [$route, $body]) {
            $this->assertSame(['CONVERSATION_NOT_OPEN', 'confirmed'], array_values(array_intersect_key($this->post($route, $body, 409), ['error' => 1, 'state' => 1])), $route);
        }
        $this->assertSame($notesBefore + 1, $notes());

        // Annulation : refusée si la note a changé, si sa période est fermée, ou passé 24 heures ; rien n'est défait à moitié
        $conn()->executeStatement('UPDATE note_de_frais SET montant = 9999 WHERE id = ?', [$noteId]);
        $this->assertSame([['code' => 'EXPENSE_CHANGED', 'message' => 'La note de frais a été modifiée depuis sa création.']], $this->get("/api/gestion/conversations/$id")['undo']['blockers']);
        $blocked = $this->post("/api/gestion/conversations/$id/undo", [], 409);
        $this->assertSame(['UNDO_BLOCKED', ['EXPENSE_CHANGED']], [$blocked['error'], array_column($blocked['reasons'], 'code')]);
        $conn()->executeStatement('UPDATE note_de_frais SET montant = 4550, description = ? WHERE id = ?', ['Autre texte', $noteId]);
        $this->assertSame(['EXPENSE_CHANGED'], array_column($this->post("/api/gestion/conversations/$id/undo", [], 409)['reasons'], 'code'));
        $conn()->executeStatement('UPDATE note_de_frais SET description = ?, date = ? WHERE id = ?', ['Taxi aéroport', $closedDay->format('Y-m-d'), $noteId]);
        $this->assertSame(['EXPENSE_CHANGED'], array_column($this->post("/api/gestion/conversations/$id/undo", [], 409)['reasons'], 'code'));
        $conn()->executeStatement('UPDATE note_de_frais SET date = ?, collection_id = ? WHERE id = ?', [$today, $calendar->findByDate($closedDay)->getId(), $noteId]);
        $this->assertSame(['PERIOD_CLOSED'], array_column($this->post("/api/gestion/conversations/$id/undo", [], 409)['reasons'], 'code'));
        $conn()->executeStatement('UPDATE note_de_frais SET collection_id = ? WHERE id = ?', [$pid, $noteId]);
        $messageId = (int) $last['id'];
        $conn()->executeStatement("UPDATE gestion_conversation_message SET created_at = created_at - INTERVAL '25 hours' WHERE id = ?", [$messageId]);
        $expired = $this->post("/api/gestion/conversations/$id/undo", [], 409);
        $this->assertSame('UNDO_EXPIRED', $expired['error']);
        $this->assertFalse($this->get("/api/gestion/conversations/$id")['undo']['available']);
        $conn()->executeStatement("UPDATE gestion_conversation_message SET created_at = created_at + INTERVAL '25 hours' WHERE id = ?", [$messageId]);
        $this->assertSame($notesBefore + 1, $notes(), 'aucun refus n\'a rien défait');

        // Annulation d'un bloc : la note disparaît, le fil revient à son aperçu ; une seule fois par confirmation
        $undone = $this->post("/api/gestion/conversations/$id/undo", [], 200);
        $this->assertSame(['undone' => ['removed' => ['expense' => $noteId]]], array_diff_key($undone, ['thread' => 1]));
        $this->assertSame([$notesBefore, false], [$notes(), $conn()->fetchOne('SELECT id FROM note_de_frais WHERE id = ?', [$noteId])]);
        $this->assertSame(['ready', $ready['previewHash'], null, null], [$undone['thread']['state'], $undone['thread']['previewHash'], $undone['thread']['undo'], $undone['thread']['action']['result']]);
        $this->assertSame(['undone', ['removed' => ['expense' => $noteId], 'confirmedMessageId' => $messageId]], [$undone['thread']['messages'][count($undone['thread']['messages']) - 1]['kind'], $undone['thread']['messages'][count($undone['thread']['messages']) - 1]['content']]);
        $this->assertSame('ALREADY_UNDONE', $this->post("/api/gestion/conversations/$id/undo", [], 409)['error']);
        // Le fil peut confirmer de nouveau, puis annuler cette nouvelle confirmation
        $second = $this->post("/api/gestion/conversations/$id/confirm", ['previewHash' => $ready['previewHash']], 201);
        $this->assertNotSame($noteId, $second['result']['noteDeFraisId']);
        $this->assertSame($notesBefore + 1, $notes());
        $this->assertSame(['removed' => ['expense' => $second['result']['noteDeFraisId']]], $this->post("/api/gestion/conversations/$id/undo", [], 200)['undone']);
        $this->assertSame($notesBefore, $notes());

        // Abandon : le fil se ferme, rien n'a été créé
        $abandoned = $this->post("/api/gestion/conversations/$id/abandon", [], 200);
        $this->assertSame(['abandoned', [], null, null, 'Saisir une note de frais : Abandonné : rien n\'a été créé.'], [$abandoned['state'], $abandoned['questions'], $abandoned['preview'], $abandoned['previewHash'], $abandoned['summary']['text']]);
        foreach (['answers' => ['answers' => [['questionId' => 'amount', 'value' => 1]]], 'confirm' => ['previewHash' => $ready['previewHash']], 'abandon' => []] as $route => $body) {
            $this->assertSame(['CONVERSATION_NOT_OPEN', 'abandoned'], array_values(array_intersect_key($this->post("/api/gestion/conversations/$id/$route", $body, 409), ['error' => 1, 'state' => 1])), $route);
        }
        $fresh = $this->post('/api/gestion/conversations', ['action' => 'expense.create'], 201);
        $this->assertSame('NOTHING_TO_UNDO', $this->post('/api/gestion/conversations/' . $fresh['id'] . '/undo', [], 409)['error']);
        $this->post('/api/gestion/conversations/' . $fresh['id'] . '/abandon', [], 200);
        $this->assertSame([$notesBefore, $unitsBefore, 0], [$notes(), $units(), count(FakeLandingAiClient::$requests)]);
    }

    public function testASupplierAShippingCostAndABudgetAreEachPreparedAndUndone(): void
    {
        $tag = $this->tag;
        $calendar = static::getContainer()->get(PeriodCalendar::class);
        $period = $calendar->current();
        $pid = (int) $period->getId();
        $today = $calendar->today()->format('Y-m-d');
        $conn = fn () => $this->em()->getConnection();
        $other = (int) ($this->em()->getRepository(TypeFournisseur::class)->findOneBy(['name' => 'Autre']) ?? $this->create(TypeFournisseur::class, ['name' => 'Autre']))->getId();
        $workshop = (int) $this->create(TypeFournisseur::class, ['name' => "Atelier $tag"])->getId();
        $threads = fn (): int => (int) $conn()->fetchOne('SELECT COUNT(*) FROM gestion_conversation');
        $threadsBefore = $threads();

        // ---- Ouverture : liste blanche, paramètres contrôlés comme des réponses --------------------------------------------------------
        foreach ([[['action' => 'pigeon.create'], ['action' => 'UNKNOWN_ACTION']], [['action' => 'document.invoice.confirm'], ['action' => 'UNKNOWN_ACTION']], [['action' => ['supplier.create']], ['action' => 'UNKNOWN_ACTION']],
            [['action' => 'supplier.create', 'documentId' => 1], ['action' => 'NOT_WITH_DOCUMENT']], [['action' => 'supplier.create', 'params' => 'x'], ['params' => 'MUST_BE_OBJECT']],
            [['action' => 'supplier.create', 'params' => ['name' => ' ', 'pigeon' => 1, 'currency' => 'XXX', 'typeFournisseurId' => 999999]], ['params.name' => 'REQUIRED', 'params.pigeon' => 'UNKNOWN_PARAM', 'params.currency' => 'INVALID_CURRENCY', 'params.typeFournisseurId' => 'TYPE_FOURNISSEUR_NOT_FOUND']],
        ] as [$body, $fields]) {
            $this->assertSame($fields, $this->post('/api/gestion/conversations', $body, 422)['fields'], json_encode($body));
        }
        $this->assertSame($threadsBefore, $threads(), 'un refus n\'ouvre aucun fil');

        // ---- supplier.create : le nom donné à l'ouverture suffit, type « Autre » et devise du site proposés -----------------------------
        $supplier = $this->post('/api/gestion/conversations', ['action' => 'supplier.create', 'params' => ['name' => "Faïencerie $tag"], 'locale' => 'fr'], 201);
        $sid = (int) $supplier['id'];
        $this->assertSame(['supplier.create', 'ready', 0], [$supplier['kind'], $supplier['state'], $supplier['openQuestions']]);
        $this->assertSame([['name', "Faïencerie $tag", false], ['typeFournisseurId', $other, true], ['currency', 'CAD', true]], array_map(fn (array $q) => [$q['id'], $q['answer'], $q['proposed']], $supplier['questions']));
        $this->assertSame(['name' => "Faïencerie $tag", 'type' => ['id' => $other, 'name' => 'Autre'], 'currency' => 'CAD'], $supplier['preview']);
        $this->assertSame(['action' => 'supplier.create', 'params' => ['name' => "Faïencerie $tag"]], $supplier['messages'][0]['content']);
        $chosen = $this->post("/api/gestion/conversations/$sid/answers", ['answers' => [['questionId' => 'typeFournisseurId', 'value' => $workshop], ['questionId' => 'currency', 'value' => 'eur']]], 200);
        $this->assertSame([[$workshop, false], ['EUR', false]], [[$chosen['questions'][1]['answer'], $chosen['questions'][1]['proposed']], [$chosen['questions'][2]['answer'], $chosen['questions'][2]['proposed']]]);
        $this->assertSame(['answers[0].value' => 'INVALID_CURRENCY'], $this->post("/api/gestion/conversations/$sid/answers", ['answers' => [['questionId' => 'currency', 'value' => 'ZZZ']]], 422)['fields']);
        $created = $this->post("/api/gestion/conversations/$sid/confirm", ['previewHash' => $chosen['previewHash']], 201);
        $supplierId = (int) $created['result']['fournisseurId'];
        $row = $conn()->fetchAssociative('SELECT name, currency, type_fournisseur_id FROM fournisseur WHERE id = ?', [$supplierId]);
        $this->assertSame(["Faïencerie $tag", 'EUR', $workshop], [$row['name'], $row['currency'], (int) $row['type_fournisseur_id']]);
        // Annulation bloquée dès qu'une commande l'utilise, ou s'il a été modifié
        $commande = $this->post("/api/collections/$pid/commandes", ['budget' => 100000, 'date' => "$today 10:00:00", 'name' => "Commande $tag", 'fournisseur' => ['id' => $supplierId], 'exchangeRate' => 1.5], 201);
        $commandeId = (int) $commande['id'];
        $this->assertSame(['SUPPLIER_USED'], array_column($this->post("/api/gestion/conversations/$sid/undo", [], 409)['reasons'], 'code'));
        $conn()->executeStatement('UPDATE fournisseur SET name = ? WHERE id = ?', ['Renommé', $supplierId]);
        $this->assertSame(['SUPPLIER_USED', 'SUPPLIER_CHANGED'], array_column($this->get("/api/gestion/conversations/$sid")['undo']['blockers'], 'code'));
        $conn()->executeStatement('UPDATE fournisseur SET name = ?, currency = ? WHERE id = ?', ["Faïencerie $tag", 'USD', $supplierId]);
        $this->assertSame(['SUPPLIER_USED', 'SUPPLIER_CHANGED'], array_column($this->get("/api/gestion/conversations/$sid")['undo']['blockers'], 'code'));
        $conn()->executeStatement('UPDATE fournisseur SET currency = ?, type_fournisseur_id = ? WHERE id = ?', ['EUR', $other, $supplierId]);
        $this->assertSame(['SUPPLIER_USED', 'SUPPLIER_CHANGED'], array_column($this->get("/api/gestion/conversations/$sid")['undo']['blockers'], 'code'));
        $conn()->executeStatement('UPDATE fournisseur SET type_fournisseur_id = ? WHERE id = ?', [$workshop, $supplierId]);

        // ---- shipping.create : LE frais de port d'une commande ; une commande qui en a déjà un est refusée -----------------------------
        $carrier = (int) $this->create(Transporteur::class, ['name' => "Transport $tag"])->getId();
        $shipping = $this->post('/api/gestion/conversations', ['action' => 'shipping.create'], 201);
        $hid = (int) $shipping['id'];
        $this->assertSame([['commandeId', true, 'choice'], ['transporteurId', true, 'choice'], ['amount', true, 'money'], ['tracknumber', false, 'text']], array_map(fn (array $q) => [$q['id'], $q['required'], $q['input']['type']], $shipping['questions']));
        $this->assertSame(3, $shipping['openQuestions']);
        $this->assertContains(['value' => $commandeId, 'label' => "Commande $tag", 'date' => $today, 'supplier' => "Faïencerie $tag"], $shipping['questions'][0]['options']);
        $this->assertContains(['value' => $carrier, 'label' => "Transport $tag"], $shipping['questions'][1]['options']);
        foreach ([[['questionId' => 'commandeId', 'value' => 999999], 'COMMANDE_NOT_FOUND'], [['questionId' => 'commandeId', 'value' => 'x'], 'COMMANDE_NOT_FOUND'], [['questionId' => 'transporteurId', 'value' => 999999], 'TRANSPORTEUR_NOT_FOUND'],
            [['questionId' => 'amount', 'value' => 0], 'MUST_BE_POSITIVE'], [['questionId' => 'tracknumber', 'value' => ' '], 'REQUIRED']] as [$answer, $code]) {
            $this->assertSame(['answers[0].value' => $code], $this->post("/api/gestion/conversations/$hid/answers", ['answers' => [$answer]], 422)['fields'], json_encode($answer));
        }
        $shipReady = $this->post("/api/gestion/conversations/$hid/answers", ['answers' => [['questionId' => 'commandeId', 'value' => $commandeId], ['questionId' => 'transporteurId', 'value' => $carrier], ['questionId' => 'amount', 'value' => 3200]]], 200);
        $this->assertSame(['ready', ['commande' => ['id' => $commandeId, 'name' => "Commande $tag", 'date' => $today], 'transporteur' => ['id' => $carrier, 'name' => "Transport $tag"], 'amount' => 3200, 'currency' => 'EUR', 'tracknumber' => null]], [$shipReady['state'], $shipReady['preview']]);
        $this->assertSame('EUR', $shipReady['questions'][2]['input']['currency'], 'devise de la commande');
        $tracked = $this->post("/api/gestion/conversations/$hid/answers", ['answers' => [['questionId' => 'tracknumber', 'value' => 'T-42']]], 200);
        $this->assertSame(['T-42', true], [$tracked['preview']['tracknumber'], $tracked['previewHash'] !== $shipReady['previewHash']]);
        // Entre l'aperçu et la confirmation, la commande reçoit un frais de port par son écran : le fil le voit, ne confirme pas
        $this->post("/api/commandes/$commandeId/frais-de-port", ['name' => 'Par l\'écran', 'price' => 500, 'transporteur' => ['id' => $carrier]], 201);
        $seen = $this->get("/api/gestion/conversations/$hid");
        $this->assertSame(['questions', 0, [['path' => 'commandeId', 'code' => 'SHIPPING_COST_ALREADY_SET']], null, null], [$seen['state'], $seen['openQuestions'], $seen['issues'], $seen['preview'], $seen['previewHash']]);
        $this->assertSame('Ajouter un frais de port à une commande : Un point empêche de préparer l\'aperçu : voyez les questions.', $seen['summary']['text']);
        $notReady = $this->post("/api/gestion/conversations/$hid/confirm", ['previewHash' => $tracked['previewHash']], 409);
        $this->assertSame(['CONVERSATION_NOT_READY', [['path' => 'commandeId', 'code' => 'SHIPPING_COST_ALREADY_SET']]], [$notReady['error'], $notReady['issues']]);
        $elsewhere = $this->post('/api/gestion/conversations', ['action' => 'shipping.create'], 201);
        $this->assertNotContains($commandeId, array_column($elsewhere['questions'][0]['options'], 'value'), 'une commande qui a déjà son frais de port n\'est plus proposée');
        $this->assertSame(['answers[0].value' => 'SHIPPING_COST_ALREADY_SET'], $this->post('/api/gestion/conversations/' . $elsewhere['id'] . '/answers', ['answers' => [['questionId' => 'commandeId', 'value' => $commandeId]]], 422)['fields']);
        $this->assertSame(['params.commandeId' => 'SHIPPING_COST_ALREADY_SET'], $this->post('/api/gestion/conversations', ['action' => 'shipping.create', 'params' => ['commandeId' => $commandeId]], 422)['fields']);
        $this->post('/api/gestion/conversations/' . $elsewhere['id'] . '/abandon', [], 200);
        $this->request('DELETE', "/api/commandes/$commandeId/frais-de-port");
        $this->assertContains($this->client->getResponse()->getStatusCode(), [200, 204]);
        $this->assertSame(['ready', $tracked['previewHash']], array_values(array_intersect_key($this->get("/api/gestion/conversations/$hid"), ['state' => 1, 'previewHash' => 1])));
        $shipped = $this->post("/api/gestion/conversations/$hid/confirm", ['previewHash' => $tracked['previewHash']], 201);
        $shippingId = (int) $shipped['result']['fraisDePortId'];
        $this->assertSame(['fraisDePortId' => $shippingId, 'commandeId' => $commandeId], $shipped['result']);
        $row = $conn()->fetchAssociative('SELECT price, tracknumber, transporteur_id, commande_id FROM frais_de_port WHERE id = ?', [$shippingId]);
        $this->assertSame([3200, 'T-42', $carrier, $commandeId], [(int) $row['price'], $row['tracknumber'], (int) $row['transporteur_id'], (int) $row['commande_id']]);
        // Annulation : bloquée si le frais de port a changé ; sinon il disparaît et la commande peut en recevoir un autre
        foreach (['price = 3300' => 'price = 3200', "tracknumber = 'T-43'" => "tracknumber = 'T-42'"] as $change => $restore) {
            $conn()->executeStatement("UPDATE frais_de_port SET $change WHERE id = ?", [$shippingId]);
            $this->assertSame(['SHIPPING_CHANGED'], array_column($this->post("/api/gestion/conversations/$hid/undo", [], 409)['reasons'], 'code'), $change);
            $conn()->executeStatement("UPDATE frais_de_port SET $restore WHERE id = ?", [$shippingId]);
        }
        $conn()->executeStatement('UPDATE commande SET is_closed = true WHERE id = ?', [$commandeId]);
        $this->assertSame(['COMMANDE_CLOSED'], array_column($this->post("/api/gestion/conversations/$hid/undo", [], 409)['reasons'], 'code'));
        $conn()->executeStatement('UPDATE commande SET is_closed = false WHERE id = ?', [$commandeId]);
        $this->assertSame(['removed' => ['shipping' => $shippingId]], $this->post("/api/gestion/conversations/$hid/undo", [], 200)['undone']);
        $this->assertFalse($conn()->fetchOne('SELECT id FROM frais_de_port WHERE commande_id = ?', [$commandeId]));
        $this->post("/api/gestion/conversations/$hid/abandon", [], 200);
        // La commande retirée, le fournisseur peut être annulé
        $conn()->executeStatement('DELETE FROM commande WHERE id = ?', [$commandeId]);
        $this->assertSame(['removed' => ['supplier' => $supplierId]], $this->post("/api/gestion/conversations/$sid/undo", [], 200)['undone']);
        $this->assertFalse($conn()->fetchOne('SELECT id FROM fournisseur WHERE id = ?', [$supplierId]));

        // ---- budget.put : la période en cours est proposée ; l'empreinte couvre le prévu actuel de chaque poste -------------------------
        $conn()->executeStatement('DELETE FROM gestion_budget_line WHERE period_id = ?', [$pid]);
        $planned = fn (): array => array_column($this->get("/api/periodes/$pid/budget")['lines'], 'planned', 'category');
        $this->put("/api/periodes/$pid/budget", ['lines' => [['category' => 'other', 'planned' => 777], ['category' => 'advertising', 'planned' => 111]]]);
        $budget = $this->post('/api/gestion/conversations', ['action' => 'budget.put'], 201);
        $bid = (int) $budget['id'];
        $this->assertSame(['periodId', 'planned:purchases', 'planned:trip', 'planned:shipping', 'planned:advertising', 'planned:other'], array_column($budget['questions'], 'id'));
        $this->assertSame([$pid, true, true], [$budget['questions'][0]['answer'], $budget['questions'][0]['proposed'], in_array($pid, array_column($budget['questions'][0]['options'], 'value'), true)]);
        $this->assertSame(['Période', 'Stock', 'Voyage', 'Frais de port', 'Publicité', 'Autres frais'], array_column($budget['questions'], 'title'));
        $this->assertSame(['budget.put.planned', 'shipping', false, 'Montant prévu pour le poste « Frais de port » (laisser vide pour ne pas le changer, 0 pour l\'effacer).'], [$budget['questions'][3]['kind'], $budget['questions'][3]['category'], $budget['questions'][3]['required'], $budget['questions'][3]['text']]);
        $this->assertSame(['questions', 0, [['path' => 'planned', 'code' => 'REQUIRED']], null], [$budget['state'], $budget['openQuestions'], $budget['issues'], $budget['preview']], 'aucun poste donné : rien à confirmer');
        $closed = $calendar->findByDate(\DateTimeImmutable::createFromInterface($period->getStartDateCollection())->modify('-1 year'));
        foreach ([[['questionId' => 'planned:advertising', 'value' => -5], 'MUST_BE_INTEGER_CENTS'], [['questionId' => 'planned:advertising', 'value' => '50'], 'MUST_BE_INTEGER_CENTS'],
            [['questionId' => 'periodId', 'value' => 999999], 'PERIOD_NOT_FOUND'], [['questionId' => 'periodId', 'value' => (int) $closed?->getId()], $closed === null ? 'PERIOD_NOT_FOUND' : 'PERIOD_CLOSED']] as [$answer, $code]) {
            $this->assertSame(['answers[0].value' => $code], $this->post("/api/gestion/conversations/$bid/answers", ['answers' => [$answer]], 422)['fields'], json_encode($answer));
        }
        $budgetReady = $this->post("/api/gestion/conversations/$bid/answers", ['answers' => [['questionId' => 'planned:advertising', 'value' => 50000], ['questionId' => 'planned:shipping', 'value' => 20000], ['questionId' => 'planned:trip', 'value' => 5], ['questionId' => 'planned:trip', 'value' => null]]], 200);
        $this->assertSame(['ready', []], [$budgetReady['state'], $budgetReady['issues']]);
        $this->assertSame(['period' => ['id' => $pid, 'name' => PeriodCalendar::displayName($period, 'fr')], 'currency' => 'CAD',
            'lines' => [['category' => 'shipping', 'label' => 'Frais de port', 'before' => 0, 'after' => 20000], ['category' => 'advertising', 'label' => 'Publicité', 'before' => 111, 'after' => 50000]],
            'totalBefore' => 888, 'totalAfter' => 70777], $budgetReady['preview']);
        // Le budget change par son écran entre l'aperçu et la confirmation : l'empreinte ne vaut plus
        $this->put("/api/periodes/$pid/budget", ['lines' => [['category' => 'advertising', 'planned' => 222]]]);
        $moved = $this->post("/api/gestion/conversations/$bid/confirm", ['previewHash' => $budgetReady['previewHash']], 409);
        $this->assertSame(['PREVIEW_CHANGED', 222, 999, 70777], [$moved['error'], $moved['preview']['lines'][1]['before'], $moved['preview']['totalBefore'], $moved['preview']['totalAfter']]);
        $this->assertSame(['purchases' => 0, 'trip' => 0, 'shipping' => 0, 'advertising' => 222, 'other' => 777], $planned());
        $this->assertSame(['periodId' => $pid], $this->post("/api/gestion/conversations/$bid/confirm", ['previewHash' => $moved['previewHash']], 201)['result']);
        $this->assertSame(['purchases' => 0, 'trip' => 0, 'shipping' => 20000, 'advertising' => 50000, 'other' => 777], $planned(), 'les postes non donnés gardent leur prévu');
        // Annulation : bloquée si la période est fermée (la confirmation est pointée, le temps du constat, sur une période fermée)
        $confirmedId = (int) $conn()->fetchOne("SELECT MAX(id) FROM gestion_conversation_message WHERE conversation_id = ? AND kind = 'confirmed'", [$bid]);
        $conn()->executeStatement("UPDATE gestion_conversation_message SET content = jsonb_set(content::jsonb, '{undo,periodId}', ?::jsonb)::json WHERE id = ?", [(string) $closed->getId(), $confirmedId]);
        $this->assertContains('PERIOD_CLOSED', array_column($this->post("/api/gestion/conversations/$bid/undo", [], 409)['reasons'], 'code'));
        $conn()->executeStatement("UPDATE gestion_conversation_message SET content = jsonb_set(content::jsonb, '{undo,periodId}', ?::jsonb)::json WHERE id = ?", [(string) $pid, $confirmedId]);
        $this->assertSame([], $this->get("/api/gestion/conversations/$bid")['undo']['blockers']);
        // Annulation : bloquée si un poste posé a changé depuis ; sinon les montants d'avant reviennent
        $this->put("/api/periodes/$pid/budget", ['lines' => [['category' => 'shipping', 'planned' => 1]]]);
        $this->assertSame(['BUDGET_CHANGED'], array_column($this->post("/api/gestion/conversations/$bid/undo", [], 409)['reasons'], 'code'));
        $this->put("/api/periodes/$pid/budget", ['lines' => [['category' => 'shipping', 'planned' => 20000], ['category' => 'other', 'planned' => 778]]]);
        $this->assertSame(['restored' => ['budgetLines' => 2]], $this->post("/api/gestion/conversations/$bid/undo", [], 200)['undone']);
        $this->assertSame(['purchases' => 0, 'trip' => 0, 'shipping' => 0, 'advertising' => 222, 'other' => 778], $planned(), 'seuls les postes posés par le fil reviennent à leur valeur d\'avant');
        $this->post("/api/gestion/conversations/$bid/abandon", [], 200);
        $conn()->executeStatement('DELETE FROM gestion_budget_line WHERE period_id = ?', [$pid]);

        // ---- Liste filtrée par kind -----------------------------------------------------------------------------------------------------
        $this->request('GET', '/api/gestion/conversations?kind=supplier.create&limit=5');
        $this->assertSame([200, ['supplier.create'], $sid, 'questions'], [$this->client->getResponse()->getStatusCode(), array_values(array_unique(array_column($this->body(), 'kind'))), $this->body()[0]['id'], $this->body()[0]['state']]);
        $this->assertSame((string) $conn()->fetchOne("SELECT COUNT(*) FROM gestion_conversation WHERE kind = 'supplier.create'"), $this->client->getResponse()->headers->get('X-Total-Count'));
        $this->assertSame(['abandoned', null], [$this->get('/api/gestion/conversations?kind=budget.put&limit=1')[0]['state'], $this->get('/api/gestion/conversations?kind=budget.put&limit=1')[0]['document']]);
        $this->assertNotContains('supplier.create', array_column($this->get('/api/gestion/conversations?kind=invoice'), 'kind'));
        $this->assertContains($bid, array_column($this->get('/api/gestion/conversations'), 'id'), 'sans filtre : tous les fils');
        $this->request('GET', '/api/gestion/conversations?kind=pigeon');
        $this->assertSame([422, ['kind' => 'UNKNOWN_KIND']], [$this->client->getResponse()->getStatusCode(), $this->body()['fields']]);
    }

    public function testAFreeSentenceOnlyKeepsWhatTheManagerWrote(): void
    {
        $tag = $this->tag;
        $calendar = static::getContainer()->get(PeriodCalendar::class);
        $calendar->current();
        $today = $calendar->today()->format('Y-m-d');
        $type = $this->noteType();
        $this->create(TypeNoteDeFrais::class, ['name' => "Restauration $tag"]);
        $this->create(TypeNoteDeFrais::class, ['name' => "Frais de bureau $tag"]);
        $conn = fn () => $this->em()->getConnection();
        $units = fn (): int => (int) $conn()->fetchOne("SELECT COUNT(*) FROM ai_usage WHERE status = 'success'");
        $notes = fn (): int => (int) $conn()->fetchOne('SELECT COUNT(*) FROM note_de_frais');
        [$unitsBefore, $notesBefore] = [$units(), $notes()];
        $say = fn (array $answers, string $reply = 'C\'est noté.') => FakeLandingAiClient::$queue[] = $this->reading(['reply' => $reply, 'answers' => $answers]);
        $thread = $this->post('/api/gestion/conversations', ['action' => 'expense.create', 'text' => 'Je veux saisir un taxi'], 201);
        $id = (int) $thread['id'];
        $this->assertSame([['user', 'action'], ['user', 'text']], array_map(fn (array $m) => [$m['role'], $m['kind']], $thread['messages']));
        $this->assertSame([0, $unitsBefore], [count(FakeLandingAiClient::$requests), $units()], 'la phrase d\'ouverture est gardée, pas lue par l\'IA');
        $options = array_column($thread['questions'][2]['options'], 'label');
        [$travel, $food, $office] = [array_search('Déplacement', $options, true), array_search("Restauration $tag", $options, true), array_search("Frais de bureau $tag", $options, true)];

        // Garde-fous : un montant, un texte ou une option que le gérant n'a pas écrits sont écartés ; une réponse refusée par le contrôle aussi
        $sentence = 'Une note de 0 pour le taxi';
        $say([
            ['questionId' => 'amount', 'optionIndex' => -1, 'value' => '45.50', 'name' => ''],
            ['questionId' => 'description', 'optionIndex' => -1, 'value' => 'Taxi aéroport', 'name' => ''],
            ['questionId' => 'typeNoteDeFraisId', 'optionIndex' => $food, 'value' => '', 'name' => ''],
            ['questionId' => 'typeNoteDeFraisId', 'optionIndex' => 99, 'value' => '', 'name' => ''],
            ['questionId' => 'date', 'optionIndex' => -1, 'value' => $today, 'name' => ''],
            ['questionId' => 'supplier.create', 'optionIndex' => -1, 'value' => 'Taxi', 'name' => ''],
            ['questionId' => 'amount', 'optionIndex' => -1, 'value' => '0', 'name' => ''],
        ]);
        $out = $this->post("/api/gestion/conversations/$id/messages", ['text' => $sentence], 200);
        $this->assertSame([], $out['applied']);
        $this->assertSame([['amount', 'VALUE_NOT_IN_SENTENCE'], ['description', 'VALUE_NOT_IN_SENTENCE'], ['typeNoteDeFraisId', 'OPTION_NOT_IN_SENTENCE'], ['typeNoteDeFraisId', 'NOT_AN_OPTION'], ['date', 'VALUE_NOT_IN_SENTENCE'],
            ['supplier.create', 'UNKNOWN_QUESTION'], ['amount', 'MUST_BE_POSITIVE']], array_map(fn (array $i) => [$i['questionId'], $i['reason']], $out['ignored']));
        $this->assertSame(['questions', 4, [null, null, null, null]], [$out['thread']['state'], $out['thread']['openQuestions'], array_column($out['thread']['questions'], 'answer')]);
        $this->assertSame([$unitsBefore + 1, 1], [$units(), count(FakeLandingAiClient::$requests)], '1 unité pour la réponse de l\'IA, même sans rien de gardé');
        $request = FakeLandingAiClient::$requests[0];
        $this->assertSame(['json_schema', false, 'claude-sonnet-5-5'], [$request['output_config']['format']['type'], array_key_exists('tool_choice', $request), $request['model']]);
        $this->assertStringContainsString('préparer une action de gestion (« Saisir une note de frais »)', $request['system']);
        $this->assertStringContainsString('recopié mot pour mot', $request['system']);
        $this->assertStringContainsString('"questionId":"description"', $request['messages'][0]['content'][0]['text']);
        $this->assertStringContainsString('"inputType":"text"', $request['messages'][0]['content'][0]['text']);

        // Un mot vide ne choisit rien (« frais » est dans la phrase et dans le libellé) ; deux options citées : aucun choix
        $say([['questionId' => 'typeNoteDeFraisId', 'optionIndex' => $office, 'value' => '', 'name' => '']]);
        $out = $this->post("/api/gestion/conversations/$id/messages", ['text' => 'C\'est une note de frais pour les clients'], 200);
        $this->assertSame([[], [['questionId' => 'typeNoteDeFraisId', 'reason' => 'OPTION_NOT_IN_SENTENCE']]], [$out['applied'], $out['ignored']]);
        $say([['questionId' => 'typeNoteDeFraisId', 'optionIndex' => $travel, 'value' => '', 'name' => '']]);
        $out = $this->post("/api/gestion/conversations/$id/messages", ['text' => 'Un déplacement, ou plutôt de la restauration'], 200);
        $this->assertSame([[], [['questionId' => 'typeNoteDeFraisId', 'reason' => 'AMBIGUOUS_OPTION']], null], [$out['applied'], $out['ignored'], $out['thread']['questions'][2]['answer']]);

        // Une phrase complète : montant, date, type nommé et description écrits par le gérant → aperçu prêt, rien de créé
        $sentence = 'Note de frais de 45,50 aujourd\'hui, en déplacement : Taxi aéroport';
        $say([
            ['questionId' => 'amount', 'optionIndex' => -1, 'value' => '45.50', 'name' => ''],
            ['questionId' => 'date', 'optionIndex' => -1, 'value' => $today, 'name' => ''],
            ['questionId' => 'typeNoteDeFraisId', 'optionIndex' => $travel, 'value' => '', 'name' => ''],
            ['questionId' => 'description', 'optionIndex' => -1, 'value' => 'taxi aéroport', 'name' => ''],
        ], 'J\'ai tout noté.');
        $out = $this->post("/api/gestion/conversations/$id/messages", ['text' => $sentence], 200);
        $this->assertSame([['questionId' => 'amount', 'value' => 4550], ['questionId' => 'date', 'value' => $today], ['questionId' => 'typeNoteDeFraisId', 'value' => $type], ['questionId' => 'description', 'value' => 'taxi aéroport']], $out['applied']);
        $this->assertSame([[], 'J\'ai tout noté.', 'ready'], [$out['ignored'], $out['reply'], $out['thread']['state']]);
        $this->assertSame(4550, $out['thread']['preview']['amount']);
        $this->assertSame([['user', 'text'], ['assistant', 'answers'], ['assistant', 'text']], array_map(fn (array $m) => [$m['role'], $m['kind']], array_slice($out['thread']['messages'], -3)));
        $this->assertSame([$unitsBefore + 4, $notesBefore], [$units(), $notes()]);

        // L'IA ne confirme pas et n'abandonne pas : « confirme » ne crée rien, le fil reste prêt
        $say([], '');
        $out = $this->post("/api/gestion/conversations/$id/messages", ['text' => 'Confirme et abandonne'], 200);
        $this->assertSame([[], 'ready', 'Je n\'ai trouvé dans votre phrase aucune réponse aux questions ouvertes. Répondez par les boutons, ou reformulez.'], [$out['applied'], $out['thread']['state'], $out['reply']]);
        $this->assertSame($notesBefore, $notes());
        // Dans un fil d'action, le mot « abandon » écrit comme description reste une description
        $kept = $this->post("/api/gestion/conversations/$id/answers", ['answers' => [['questionId' => 'description', 'value' => 'abandon']]], 200);
        $this->assertSame(['ready', 'abandon'], [$kept['state'], $kept['questions'][3]['answer']]);
        $say([['questionId' => 'description', 'optionIndex' => -1, 'value' => 'abandon', 'name' => '']]);
        $out = $this->post("/api/gestion/conversations/$id/messages", ['text' => 'La description est : abandon'], 200);
        $this->assertSame([[['questionId' => 'description', 'value' => 'abandon']], 'ready'], [$out['applied'], $out['thread']['state']]);
        // Refus : phrase vide ou trop longue, sans appel ni unité
        $requests = count(FakeLandingAiClient::$requests);
        $this->assertSame(['text' => 'REQUIRED'], $this->post("/api/gestion/conversations/$id/messages", ['text' => '  '], 422)['fields']);
        $this->assertSame(['text' => 'TOO_LONG'], $this->post("/api/gestion/conversations/$id/messages", ['text' => str_repeat('a', 1001)], 422)['fields']);
        $this->assertSame([$requests, $unitsBefore + 6], [count(FakeLandingAiClient::$requests), $units()]);
        $this->post("/api/gestion/conversations/$id/abandon", [], 200);
    }

    public function testTheCatalogTheGuideAccessAndTheAbandonOfAnInvoiceThread(): void
    {
        // Catalogue : la constante et le service disent la même liste ; chaque clé tient dans la colonne `kind` et a ses textes fr et en
        $this->em();
        $catalog = static::getContainer()->get(ActionCatalog::class);
        $this->assertSame(['expense.create', 'supplier.create', 'shipping.create', 'budget.put'], ActionCatalog::KEYS);
        $this->assertSame(ActionCatalog::KEYS, $catalog->keys());
        $translator = static::getContainer()->get('translator');
        foreach (ActionCatalog::KEYS as $key) {
            $this->assertTrue($catalog->has($key));
            $this->assertSame($key, $catalog->get($key)::key());
            $this->assertLessThanOrEqual(20, strlen($key), "$key : la colonne gestion_conversation.kind fait 20 caractères");
            $this->assertTrue(SequenceCatalog::preparable($key), "$key : préparable dans le guide");
            foreach (['fr', 'en'] as $locale) {
                $title = 'conversation.actions.' . str_replace('.', '_', $key) . '.title';
                $this->assertNotSame($title, $translator->trans($title, [], 'gestion', $locale), "$title ($locale)");
                foreach ($catalog->get($key)->build([], $locale)['questions'] as $question) {
                    $this->assertStringNotContainsString('conversation.actions.', $question['title'] . $question['text'], "$key, {$question['id']} ($locale)");
                    $this->assertSame($key . '.' . explode(':', $question['id'])[0], $question['kind']);
                }
            }
        }
        // Un choix n'est gardé d'une phrase libre que si elle nomme l'option : un mot du libellé (3 lettres ou plus, ou un nombre), sans casse ni accents
        // Un choix n'est gardé d'une phrase libre que si elle désigne UNE option : mots significatifs du libellé (3 caractères au moins, hors
        // mots vides, hors mots communs à toutes les options), sans casse ni accents ; à égalité, plusieurs rangs = phrase ambiguë
        $named = fn (string $text, array $labels): array => \App\Gestion\Services\Conversation\ConversationInterpreter::named($text, $labels);
        $periods = ['Novembre 2026', 'Décembre 2026', 'Novembre 2025'];
        $this->assertSame([[0], [], [0], [0, 2], [1], [0, 1]], [$named('le budget de fevrier', ['Février 2027', 'Mars 2027']), $named('pour 2027', ['Février 2027', 'Mars 2027']),
            $named('pour novembre 2026', $periods), $named('en NOVEMBRE.', $periods), $named('decembre', $periods), $named('pour 2026', $periods)]);
        $this->assertSame([[], [], [0], [1], [], [], [0], []], [$named('frais de port pour les bureaux', ['Frais de bureau', 'Note de service']), $named('ce mois-ci', $periods), $named('avec PUROLATOR.', ['Purolator Express', 'UPS']),
            $named('par purolator express', ['Purolator', 'Purolator Express']), $named('purolator', ['Purolator', 'Purolator Express']), $named('', ['Autre']), $named('un autre', ['Autre']), $named('la tv du salon', ['TV 4K', 'Radio'])]);
        $this->assertFalse($catalog->has('document.invoice.confirm'));
        $this->assertTrue(SequenceCatalog::preparable('document.invoice.confirm'));
        $this->assertFalse(SequenceCatalog::preparable('period.close'));

        // Le guide des séquences rend `action: { key, preparable, params }` (vérifié par SequencesApiTest) : la clé et les paramètres ouvrent le fil
        $opened = $this->post('/api/gestion/conversations', ['action' => 'supplier.create', 'params' => []], 201);
        $this->assertSame(['supplier.create', 'questions', 1], [$opened['kind'], $opened['state'], $opened['openQuestions']]);

        // Abandon d'un fil de facture par la route : le document reste, un autre fil peut le reprendre
        $this->em()->getConnection()->executeStatement("UPDATE gestion_document SET status = 'failed' WHERE status = 'reading'");
        $this->client->request('POST', '/api/gestion/conversations', [], ['files' => [$this->image('f.png')]], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $this->token]);
        $invoice = $this->body();
        $this->assertSame([201, 'invoice', 'reading'], [$this->client->getResponse()->getStatusCode(), $invoice['kind'], $invoice['state']]);
        FakeLandingAiClient::$queue[] = $this->reading(['supplierName' => 'Inconnu ' . $this->tag, 'invoiceNumber' => 'AB-' . $this->tag, 'invoiceDate' => '', 'currency' => 'CAD', 'amountsMode' => 'ht',
            'lines' => [['baseLabel' => 'Objet inconnu', 'quantity' => 1, 'unitCost' => 1000, 'total' => 1000]], 'taxes' => [], 'shipping' => null, 'subtotal' => null, 'taxTotal' => null, 'total' => null,
            'confidence' => ['overall' => 0.7, 'number' => 0.9, 'date' => 0.1, 'lines' => 0.7, 'totals' => 0.5], 'notes' => []]);
        static::getContainer()->get(ReadGestionDocumentHandler::class)(new ReadGestionDocumentMessage((int) $invoice['document']['id'], MV_TEST_TENANT_CODE));
        $this->em()->clear();
        $this->endOfWrite();
        $left = $this->post('/api/gestion/conversations/' . $invoice['id'] . '/abandon', [], 200);
        $this->assertSame(['abandoned', 'extracted', 'abandoned'], [$left['state'], $left['document']['status'], $left['messages'][count($left['messages']) - 1]['kind']]);
        $this->assertSame('CONVERSATION_NOT_OPEN', $this->post('/api/gestion/conversations/' . $invoice['id'] . '/abandon', [], 409)['error']);
        $retaken = $this->post('/api/gestion/conversations', ['documentId' => $invoice['document']['id']], 201);
        $this->assertNotSame($invoice['id'], $retaken['id']);
        $this->assertSame('questions', $retaken['state']);
        $this->post('/api/gestion/conversations/' . $retaken['id'] . '/abandon', [], 200);
        $this->assertSame(['error' => 'CONVERSATION_NOT_FOUND'], array_intersect_key($this->post('/api/gestion/conversations/999999/abandon', [], 404), ['error' => 1]));

        // Accès : la gestion est réservée à l'administrateur
        $this->login(['ROLE_USER']);
        foreach ([['POST', '/api/gestion/conversations', ['action' => 'expense.create']], ['POST', '/api/gestion/conversations/' . $opened['id'] . '/abandon', []], ['GET', '/api/gestion/conversations?kind=budget.put', null]] as [$method, $route, $body]) {
            $this->request($method, $route, $body);
            $this->assertSame(403, $this->client->getResponse()->getStatusCode(), "$method $route");
        }
        $this->login(['ROLE_ADMIN']);
        $this->post('/api/gestion/conversations/' . $opened['id'] . '/abandon', [], 200);
    }

    // ----- utilitaires -----

    private function noteType(): int
    {
        return (int) ($this->em()->getRepository(TypeNoteDeFrais::class)->findOneBy(['name' => 'Déplacement']) ?? $this->create(TypeNoteDeFrais::class, ['name' => 'Déplacement']))->getId();
    }

    /** Réponse du faux modèle, en sortie structurée */
    private function reading(array $input): object
    {
        return json_decode(json_encode([
            'id' => 'msg_' . bin2hex(random_bytes(4)), 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-sonnet-5-5', 'stop_reason' => 'end_turn',
            'content' => [['type' => 'text', 'text' => json_encode($input)]],
            'usage' => ['input_tokens' => 1200, 'output_tokens' => 200, 'cache_read_input_tokens' => 0, 'cache_creation_input_tokens' => 0],
        ]), false);
    }

    private function image(string $name): UploadedFile
    {
        $tmp = tempnam(sys_get_temp_dir(), 'img');
        copy(__DIR__ . '/../../../public/assets/arkanoa-favicon.png', $tmp);

        return new UploadedFile($tmp, $name, 'image/png', null, true);
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
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), $route . ' : ' . substr((string) $this->client->getResponse()->getContent(), 0, 400));

        return $this->body();
    }

    private function post(string $route, array $body, int $expected): array
    {
        $this->request('POST', $route, $body);
        $this->assertSame($expected, $this->client->getResponse()->getStatusCode(), $route . ' : ' . substr((string) $this->client->getResponse()->getContent(), 0, 600));

        return $this->body();
    }

    private function put(string $route, array $body): array
    {
        $this->request('PUT', $route, $body);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), $route . ' : ' . substr((string) $this->client->getResponse()->getContent(), 0, 400));

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
        $email = 'gestion-action-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $em = $this->em();
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Gestion')->setLastname('Action')->setRoles($roles)->setIsVerified(true)->setPassword('inutilisable');
        $em->persist($user);
        $em->flush();
        $this->token = static::getContainer()->get('lexik_jwt_authentication.jwt_manager')->create($user);
        $this->endOfWrite();
    }
}
