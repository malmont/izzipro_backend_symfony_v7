<?php

namespace App\Tests\Functional\Gestion;

use App\Entity\Order;
use App\Entity\OrderItems;
use App\Entity\OrderSource;
use App\Entity\OrderType;
use App\Entity\Product;
use App\Entity\ProductVariant;
use App\Entity\StatusCommande;
use App\Entity\Transporteur;
use App\Entity\TypeFournisseur;
use App\Entity\TypeNoteDeFrais;
use App\Entity\User;
use App\EventSubscriber\CacheInvalidationSubscriber;
use App\Gestion\Message\ReadGestionDocumentMessage;
use App\Gestion\MessageHandler\ReadGestionDocumentHandler;
use App\Gestion\Sequences\SequenceCatalog;
use App\Gestion\Services\Conversation\Action\ActionCatalog;
use App\Gestion\Services\GestionFiscalYearService;
use App\Gestion\Services\PeriodCalendar;
use App\Gestion\Services\PeriodResultCalculator;
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
        $this->assertSame(['amount' => 4550, 'currency' => 'CAD', 'date' => $today, 'description' => 'Taxi aéroport', 'type' => ['id' => $type, 'name' => 'Déplacement'], 'period' => ['id' => $pid, 'name' => $periodName]], array_diff_key($ready['preview'], ['rows' => 1]));
        // forme commune de l'aperçu : une ligne par chose écrite, que l'application affiche sans connaître l'action
        $this->assertSame([['label' => 'Montant', 'value' => 4550, 'unit' => 'money', 'currency' => 'CAD'], ['label' => 'Date', 'value' => $today, 'unit' => 'date'], ['label' => 'Type de frais', 'value' => 'Déplacement', 'unit' => 'text'],
            ['label' => 'Description', 'value' => 'Taxi aéroport', 'unit' => 'text'], ['label' => 'Période', 'value' => $periodName, 'unit' => 'text']], $ready['preview']['rows']);
        // dans l'autre langue : les libellés changent, l'empreinte non (elle est celle de l'aperçu dans la langue du fil)
        $english = $this->get("/api/gestion/conversations/$id?locale=en");
        $this->assertSame([['Amount', 'Date', 'Expense type', 'Description', 'Period'], $ready['previewHash']], [array_column($english['preview']['rows'], 'label'), $english['previewHash']]);
        $listed = fn (): ?array => array_column($this->get('/api/gestion/conversations?kind=expense.create&limit=100'), 'undo', 'id')[$id];
        $this->assertNull($listed(), 'rien de confirmé : rien à annuler dans la liste');
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
        $this->assertSame($done['undo'], $listed(), 'la liste montre la confirmation encore annulable, sans les blocages');
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
        $this->assertFalse($listed()['available']);
        $conn()->executeStatement("UPDATE gestion_conversation_message SET created_at = created_at + INTERVAL '25 hours' WHERE id = ?", [$messageId]);
        $this->assertSame($notesBefore + 1, $notes(), 'aucun refus n\'a rien défait');

        // Annulation d'un bloc : la note disparaît, le fil revient à son aperçu ; une seule fois par confirmation
        $undone = $this->post("/api/gestion/conversations/$id/undo", [], 200);
        $this->assertSame(['undone' => ['removed' => ['expense' => $noteId]]], array_diff_key($undone, ['thread' => 1]));
        $this->assertSame([$notesBefore, false], [$notes(), $conn()->fetchOne('SELECT id FROM note_de_frais WHERE id = ?', [$noteId])]);
        $this->assertSame(['ready', $ready['previewHash'], null, null], [$undone['thread']['state'], $undone['thread']['previewHash'], $undone['thread']['undo'], $undone['thread']['action']['result']]);
        $this->assertSame(['undone', ['removed' => ['expense' => $noteId], 'confirmedMessageId' => $messageId]], [$undone['thread']['messages'][count($undone['thread']['messages']) - 1]['kind'], $undone['thread']['messages'][count($undone['thread']['messages']) - 1]['content']]);
        $this->assertSame('ALREADY_UNDONE', $this->post("/api/gestion/conversations/$id/undo", [], 409)['error']);
        $this->assertNull($listed(), 'confirmation annulée : plus rien à annuler');
        // Le fil peut confirmer de nouveau, puis annuler cette nouvelle confirmation
        $second = $this->post("/api/gestion/conversations/$id/confirm", ['previewHash' => $ready['previewHash']], 201);
        $this->assertNotSame($noteId, $second['result']['noteDeFraisId']);
        $this->assertSame($notesBefore + 1, $notes());
        $this->assertSame(['removed' => ['expense' => $second['result']['noteDeFraisId']]], $this->post("/api/gestion/conversations/$id/undo", [], 200)['undone']);
        $this->assertSame($notesBefore, $notes());

        // Abandon : le fil se ferme, rien n'a été créé
        $abandoned = $this->post("/api/gestion/conversations/$id/abandon", [], 200);
        $this->assertSame(['abandoned', [], null, null, 'Saisir une note de frais : Abandonné : rien n\'a été créé.'], [$abandoned['state'], $abandoned['questions'], $abandoned['preview'], $abandoned['previewHash'], $abandoned['summary']['text']]);
        $this->assertNull($listed());
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
        $this->assertSame(['name' => "Faïencerie $tag", 'type' => ['id' => $other, 'name' => 'Autre'], 'currency' => 'CAD'], array_diff_key($supplier['preview'], ['rows' => 1]));
        $this->assertSame([['label' => 'Nom', 'value' => "Faïencerie $tag", 'unit' => 'text'], ['label' => 'Type de fournisseur', 'value' => 'Autre', 'unit' => 'text'], ['label' => 'Devise d\'achat', 'value' => 'CAD', 'unit' => 'text']], $supplier['preview']['rows']);
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
        $this->assertSame(['ready', ['commande' => ['id' => $commandeId, 'name' => "Commande $tag", 'date' => $today], 'transporteur' => ['id' => $carrier, 'name' => "Transport $tag"], 'amount' => 3200, 'currency' => 'EUR', 'tracknumber' => null]], [$shipReady['state'], array_diff_key($shipReady['preview'], ['rows' => 1])]);
        $this->assertSame([['label' => 'Commande', 'value' => "Commande $tag", 'unit' => 'text'], ['label' => 'Transporteur', 'value' => "Transport $tag", 'unit' => 'text'], ['label' => 'Montant', 'value' => 3200, 'unit' => 'money', 'currency' => 'EUR']], $shipReady['preview']['rows'], 'le numéro de suivi, non donné, n\'a pas de ligne');
        $this->assertSame('EUR', $shipReady['questions'][2]['input']['currency'], 'devise de la commande');
        $tracked = $this->post("/api/gestion/conversations/$hid/answers", ['answers' => [['questionId' => 'tracknumber', 'value' => 'T-42']]], 200);
        $this->assertSame(['T-42', true], [$tracked['preview']['tracknumber'], $tracked['previewHash'] !== $shipReady['previewHash']]);
        $this->assertSame(['label' => 'Numéro de suivi', 'value' => 'T-42', 'unit' => 'text'], $tracked['preview']['rows'][3]);
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
            'totalBefore' => 888, 'totalAfter' => 70777], array_diff_key($budgetReady['preview'], ['rows' => 1]));
        $this->assertSame([['label' => 'Période', 'value' => PeriodCalendar::displayName($period, 'fr'), 'unit' => 'text'], ['label' => 'Frais de port', 'value' => 20000, 'unit' => 'money', 'currency' => 'CAD', 'before' => 0],
            ['label' => 'Publicité', 'value' => 50000, 'unit' => 'money', 'currency' => 'CAD', 'before' => 111]], $budgetReady['preview']['rows'], 'chaque poste remplace son prévu : before toujours présent');
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

    public function testACashBalanceAPlannedMovementAndATripAreEachPreparedAndUndone(): void
    {
        $tag = $this->tag;
        $calendar = static::getContainer()->get(PeriodCalendar::class);
        $period = $calendar->current();
        $today = $calendar->today();
        $conn = fn () => $this->em()->getConnection();

        // ---- cash.balance.create : la date d'aujourd'hui est proposée, le montant peut être négatif ---------------------------------------
        $day = $today->modify('-' . random_int(1200, 2400) . ' days')->format('Y-m-d');
        $conn()->executeStatement('DELETE FROM gestion_cash_balance WHERE balance_date IN (?, ?)', [$day, $today->modify('-1100 days')->format('Y-m-d')]);
        $balance = $this->post('/api/gestion/conversations', ['action' => 'cash.balance.create'], 201);
        $cid = (int) $balance['id'];
        $this->assertSame([['date', $today->format('Y-m-d'), true, 'date'], ['amount', null, false, 'money'], ['note', null, false, 'text']], array_map(fn (array $q) => [$q['id'], $q['answer'], $q['proposed'], $q['input']['type']], $balance['questions']));
        $this->assertSame([$today->format('Y-m-d'), true, 'CAD', 1, 'Saisir un solde de trésorerie'], [$balance['questions'][0]['input']['max'], $balance['questions'][1]['input']['signed'], $balance['questions'][1]['input']['currency'], $balance['openQuestions'], $balance['action']['title']]);
        foreach ([[['questionId' => 'date', 'value' => $today->modify('+1 day')->format('Y-m-d')], 'DATE_IN_FUTURE'], [['questionId' => 'date', 'value' => 'hier'], 'INVALID_DATE'],
            [['questionId' => 'amount', 'value' => '12'], 'INVALID_AMOUNT'], [['questionId' => 'amount', 'value' => 1.5], 'INVALID_AMOUNT'], [['questionId' => 'note', 'value' => ' '], 'REQUIRED']] as [$answer, $code]) {
            $this->assertSame(['answers[0].value' => $code], $this->post("/api/gestion/conversations/$cid/answers", ['answers' => [$answer]], 422)['fields'], json_encode($answer));
        }
        // sans réponse à la date, c'est aujourd'hui qui serait écrit : l'aperçu le dit
        $bare = $this->post("/api/gestion/conversations/$cid/answers", ['answers' => [['questionId' => 'amount', 'value' => 100]]], 200)['preview'];
        $this->assertSame([$today->format('Y-m-d'), [['label' => 'Date du solde', 'value' => $today->format('Y-m-d'), 'unit' => 'date'], ['label' => 'Solde', 'value' => 100, 'unit' => 'money', 'currency' => 'CAD']]], [$bare['date'], $bare['rows']], 'sans note ni solde remplacé : deux lignes, sans before');
        $ready = $this->post("/api/gestion/conversations/$cid/answers", ['answers' => [['questionId' => 'date', 'value' => $day], ['questionId' => 'amount', 'value' => -25000], ['questionId' => 'note', 'value' => 'Compte courant']]], 200);
        $this->assertSame(['ready', ['date' => $day, 'amount' => -25000, 'currency' => 'CAD', 'note' => 'Compte courant', 'replaces' => null]], [$ready['state'], array_diff_key($ready['preview'], ['rows' => 1])]);
        $this->assertSame([['label' => 'Date du solde', 'value' => $day, 'unit' => 'date'], ['label' => 'Solde', 'value' => -25000, 'unit' => 'money', 'currency' => 'CAD'], ['label' => 'Note', 'value' => 'Compte courant', 'unit' => 'text']], $ready['preview']['rows']);
        $saved = $this->post("/api/gestion/conversations/$cid/confirm", ['previewHash' => $ready['previewHash']], 201);
        $balanceId = (int) $saved['result']['balanceId'];
        $this->assertSame(['balanceId' => $balanceId, 'replaced' => false], $saved['result']);
        $row = $conn()->fetchAssociative('SELECT balance_date, amount_cents, note FROM gestion_cash_balance WHERE id = ?', [$balanceId]);
        $this->assertSame([$day, -25000, 'Compte courant'], [substr((string) $row['balance_date'], 0, 10), (int) $row['amount_cents'], $row['note']]);
        foreach (['amount_cents = 1' => 'amount_cents = -25000', "note = 'Autre'" => "note = 'Compte courant'", "balance_date = balance_date - 1" => "balance_date = balance_date + 1"] as $change => $restore) {
            $conn()->executeStatement("UPDATE gestion_cash_balance SET $change WHERE id = ?", [$balanceId]);
            $this->assertSame(['BALANCE_CHANGED'], array_column($this->post("/api/gestion/conversations/$cid/undo", [], 409)['reasons'], 'code'), $change);
            $conn()->executeStatement("UPDATE gestion_cash_balance SET $restore WHERE id = ?", [$balanceId]);
        }
        $this->assertSame(['removed' => ['balance' => $balanceId]], $this->post("/api/gestion/conversations/$cid/undo", [], 200)['undone']);
        $this->assertFalse($conn()->fetchOne('SELECT id FROM gestion_cash_balance WHERE id = ?', [$balanceId]));
        // Supprimé hors du fil après une nouvelle confirmation : l'annulation le constate
        $again = $this->post("/api/gestion/conversations/$cid/confirm", ['previewHash' => $ready['previewHash']], 201);
        $conn()->executeStatement('DELETE FROM gestion_cash_balance WHERE id = ?', [$again['result']['balanceId']]);
        $this->assertSame([['code' => 'BALANCE_CHANGED', 'message' => 'Le solde saisi par cette action n\'existe plus.']], $this->post("/api/gestion/conversations/$cid/undo", [], 409)['reasons']);
        // Un solde déjà saisi à cette date est remplacé : l'aperçu le dit, l'empreinte le couvre, l'annulation remet l'ancien
        $day2 = $today->modify('-1100 days')->format('Y-m-d');
        $existingId = (int) $conn()->fetchOne("INSERT INTO gestion_cash_balance (balance_date, amount_cents, note, created_by, created_at, updated_at) VALUES (?, 1000, 'avant', 'test', NOW(), NOW()) RETURNING id", [$day2]);
        $replace = $this->post('/api/gestion/conversations', ['action' => 'cash.balance.create', 'params' => ['date' => $day2, 'amount' => 5000]], 201);
        $rid = (int) $replace['id'];
        $this->assertSame(['ready', ['id' => $existingId, 'amount' => 1000, 'note' => 'avant'], [false, false]], [$replace['state'], $replace['preview']['replaces'], [$replace['questions'][0]['proposed'], $replace['questions'][1]['proposed']]]);
        $this->assertSame([['label' => 'Date du solde', 'value' => $day2, 'unit' => 'date'], ['label' => 'Solde', 'value' => 5000, 'unit' => 'money', 'currency' => 'CAD', 'before' => 1000], ['label' => 'Note', 'value' => null, 'unit' => 'text', 'before' => 'avant']],
            $replace['preview']['rows'], 'solde remplacé : before sur le montant et sur la note, même remplacée par rien');
        $conn()->executeStatement('UPDATE gestion_cash_balance SET amount_cents = 2000 WHERE id = ?', [$existingId]);
        $moved = $this->post("/api/gestion/conversations/$rid/confirm", ['previewHash' => $replace['previewHash']], 409);
        $this->assertSame(['PREVIEW_CHANGED', 2000], [$moved['error'], $moved['preview']['replaces']['amount']]);
        $this->assertSame(['balanceId' => $existingId, 'replaced' => true], $this->post("/api/gestion/conversations/$rid/confirm", ['previewHash' => $moved['previewHash']], 201)['result']);
        $this->assertSame([5000, null], array_values($conn()->fetchAssociative('SELECT amount_cents, note FROM gestion_cash_balance WHERE id = ?', [$existingId])));
        $this->assertSame(['restored' => ['balance' => $existingId]], $this->post("/api/gestion/conversations/$rid/undo", [], 200)['undone']);
        $this->assertSame([2000, 'avant'], array_values($conn()->fetchAssociative('SELECT amount_cents, note FROM gestion_cash_balance WHERE id = ?', [$existingId])));
        $conn()->executeStatement('DELETE FROM gestion_cash_balance WHERE id = ?', [$existingId]);
        $this->assertSame('confirmed', $this->get("/api/gestion/conversations/$cid")['state'], 'un fil dont l\'objet a disparu hors du fil reste confirmé, annulation bloquée');
        $this->post("/api/gestion/conversations/$rid/abandon", [], 200);

        // ---- cash.movement.create : ouvert comme depuis le guide (frais fixe mensuel) ; l'aperçu dit la règle du frais fixe -------------
        $movement = $this->post('/api/gestion/conversations', ['action' => 'cash.movement.create', 'params' => ['fixedCost' => true, 'recurrence' => 'monthly']], 201);
        $mid = (int) $movement['id'];
        $this->assertSame(['label', 'direction', 'category', 'amount', 'startDate', 'recurrence', 'endDate', 'fixedCost', 'notes'], array_column($movement['questions'], 'id'));
        $q = array_column($movement['questions'], null, 'id');
        $this->assertSame([['out', true], ['monthly', false], [true, false], 4], [[$q['direction']['answer'], $q['direction']['proposed']], [$q['recurrence']['answer'], $q['recurrence']['proposed']], [$q['fixedCost']['answer'], $q['fixedCost']['proposed']], $movement['openQuestions']]);
        $this->assertSame([[['value' => 'out', 'label' => 'Sortie'], ['value' => 'in', 'label' => 'Entrée']], ['Une seule fois', 'Chaque semaine', 'Chaque mois', 'Chaque trimestre', 'Chaque année'], [['value' => true, 'label' => 'Oui'], ['value' => false, 'label' => 'Non']],
            ['value' => 'rent', 'label' => 'Loyer']], [$q['direction']['options'], array_column($q['recurrence']['options'], 'label'), $q['fixedCost']['options'], $q['category']['options'][0]]);
        $start = $today->format('Y-m-d');
        foreach ([[['questionId' => 'direction', 'value' => 'x'], 'INVALID_DIRECTION'], [['questionId' => 'category', 'value' => 'pigeon'], 'INVALID_CATEGORY'], [['questionId' => 'amount', 'value' => 0], 'MUST_BE_POSITIVE'],
            [['questionId' => 'recurrence', 'value' => 'daily'], 'INVALID_RECURRENCE'], [['questionId' => 'fixedCost', 'value' => 'oui'], 'MUST_BE_BOOLEAN'], [['questionId' => 'startDate', 'value' => '13/11/2026'], 'INVALID_DATE'],
            [['questionId' => 'endDate', 'value' => 'x'], 'INVALID_DATE']] as [$answer, $code]) {
            $this->assertSame(['answers[0].value' => $code], $this->post("/api/gestion/conversations/$mid/answers", ['answers' => [$answer]], 422)['fields'], json_encode($answer));
        }
        $fixed = fn (array $answers): array => $this->post("/api/gestion/conversations/$mid/answers", ['answers' => array_map(fn ($id, $v) => ['questionId' => $id, 'value' => $v], array_keys($answers), $answers)], 200)['preview']['fixedCost'];
        $this->assertSame(['value' => true, 'source' => 'choice', 'text' => 'Compté comme frais fixe : oui (votre choix).'], $fixed(['label' => "Loyer $tag", 'category' => 'rent', 'amount' => 120000, 'startDate' => $start]));
        $this->assertSame(['value' => false, 'source' => 'choice', 'text' => 'Compté comme frais fixe : non (votre choix).'], $fixed(['fixedCost' => false]));
        $this->assertSame(['value' => true, 'source' => 'rule', 'text' => 'Compté comme frais fixe : oui (règle de la catégorie Loyer).'], $fixed(['fixedCost' => null]));
        $this->assertSame(['value' => false, 'source' => 'rule', 'text' => 'Compté comme frais fixe : non (règle de la catégorie Prêt).'], $fixed(['category' => 'loan']));
        $this->assertSame(['value' => true, 'source' => 'choice', 'text' => 'Compté comme frais fixe : oui (votre choix).'], $fixed(['fixedCost' => true]), 'un prêt compté comme frais fixe par choix');
        $this->assertSame(['value' => false, 'source' => 'never', 'text' => 'Compté comme frais fixe : non (une entrée ou un mouvement ponctuel n\'est jamais un frais fixe).'], $fixed(['recurrence' => 'none']));
        $this->assertSame(['value' => false, 'source' => 'never'], array_slice($fixed(['recurrence' => 'monthly', 'direction' => 'in']), 0, 2));
        $this->assertSame('Counted as a fixed cost: yes (rule of the Rent category).', $this->post("/api/gestion/conversations/$mid/answers?locale=en", ['answers' => [['questionId' => 'direction', 'value' => 'out'], ['questionId' => 'category', 'value' => 'rent'], ['questionId' => 'fixedCost', 'value' => null]]], 200)['preview']['fixedCost']['text']);
        // date de fin avant le début : refusée à la réponse ; si c'est le début qui passe après la fin, le fil le signale
        $end = $today->modify('+1 year')->format('Y-m-d');
        $this->assertSame(['answers[0].value' => 'BEFORE_START'], $this->post("/api/gestion/conversations/$mid/answers", ['answers' => [['questionId' => 'endDate', 'value' => $today->modify('-1 day')->format('Y-m-d')]]], 422)['fields']);
        $this->post("/api/gestion/conversations/$mid/answers", ['answers' => [['questionId' => 'endDate', 'value' => $end]]], 200);
        $late = $this->post("/api/gestion/conversations/$mid/answers", ['answers' => [['questionId' => 'startDate', 'value' => $today->modify('+2 years')->format('Y-m-d')]]], 200);
        $this->assertSame(['questions', [['path' => 'endDate', 'code' => 'BEFORE_START']], null], [$late['state'], $late['issues'], $late['preview']]);
        $planned = $this->post("/api/gestion/conversations/$mid/answers", ['answers' => [['questionId' => 'startDate', 'value' => $start], ['questionId' => 'notes', 'value' => 'Bail de 3 ans']]], 200);
        $this->assertSame(['label' => "Loyer $tag", 'direction' => 'out', 'category' => 'rent', 'categoryLabel' => 'Loyer', 'amount' => 120000, 'currency' => 'CAD', 'startDate' => $start, 'recurrence' => 'monthly', 'endDate' => $end,
            'notes' => 'Bail de 3 ans', 'monthlyEquivalent' => 120000], array_diff_key($planned['preview'], ['fixedCost' => 1, 'rows' => 1]));
        $this->assertSame([['Libellé', "Loyer $tag", 'text'], ['Sortie ou entrée', 'Sortie', 'text'], ['Catégorie', 'Loyer', 'text'], ['Montant', 120000, 'money'], ['Date de début', $start, 'date'], ['Récurrence', 'Chaque mois', 'text'],
            ['Date de fin', $end, 'date'], ['Frais fixe', 'Compté comme frais fixe : oui (règle de la catégorie Loyer).', 'text'], ['Notes', 'Bail de 3 ans', 'text']], array_map(fn (array $r) => [$r['label'], $r['value'], $r['unit']], $planned['preview']['rows']));
        $this->assertSame(['label' => 'Montant', 'value' => 120000, 'unit' => 'money', 'currency' => 'CAD'], $planned['preview']['rows'][3]);
        $created = $this->post("/api/gestion/conversations/$mid/confirm", ['previewHash' => $planned['previewHash']], 201);
        $movementId = (int) $created['result']['movementId'];
        $this->assertSame(['movementId' => $movementId, 'fixedCost' => true], $created['result']);
        $row = $conn()->fetchAssociative('SELECT label, category, direction, amount_cents, recurrence, notes, fixed_cost FROM gestion_cash_movement WHERE id = ?', [$movementId]);
        $this->assertSame(["Loyer $tag", 'rent', 'out', 120000, 'monthly', 'Bail de 3 ans', null], [$row['label'], $row['category'], $row['direction'], (int) $row['amount_cents'], $row['recurrence'], $row['notes'], $row['fixed_cost']], 'sans réponse : la règle, rien d\'écrit dans fixed_cost');
        $this->assertContains($movementId, array_column($this->get('/api/gestion/tresorerie/mouvements'), 'id'));
        foreach (["label = 'Autre'" => "label = 'Loyer $tag'", 'amount_cents = 1' => 'amount_cents = 120000', 'fixed_cost = false' => 'fixed_cost = NULL', 'end_date = NULL' => "end_date = '$end'"] as $change => $restore) {
            $conn()->executeStatement("UPDATE gestion_cash_movement SET $change WHERE id = ?", [$movementId]);
            $this->assertSame(['MOVEMENT_CHANGED'], array_column($this->post("/api/gestion/conversations/$mid/undo", [], 409)['reasons'], 'code'), $change);
            $conn()->executeStatement("UPDATE gestion_cash_movement SET $restore WHERE id = ?", [$movementId]);
        }
        $this->assertSame(['removed' => ['movement' => $movementId]], $this->post("/api/gestion/conversations/$mid/undo", [], 200)['undone']);
        $this->assertFalse($conn()->fetchOne('SELECT id FROM gestion_cash_movement WHERE id = ?', [$movementId]));
        // un mouvement ponctuel n'a pas de date de fin : l'aperçu ne la montre pas, le service ne l'écrit pas
        $once = $this->post("/api/gestion/conversations/$mid/answers", ['answers' => [['questionId' => 'recurrence', 'value' => 'none']]], 200);
        $this->assertSame([null, 0], [$once['preview']['endDate'], $once['preview']['monthlyEquivalent']]);
        $this->assertSame(['Libellé', 'Sortie ou entrée', 'Catégorie', 'Montant', 'Date de début', 'Récurrence', 'Frais fixe', 'Notes'], array_column($once['preview']['rows'], 'label'), 'pas de ligne pour une date de fin sans objet');
        $this->assertSame('Une seule fois', $once['preview']['rows'][5]['value']);
        $this->post("/api/gestion/conversations/$mid/abandon", [], 200);

        // ---- trip.create : l'aperçu compte ce qui sera rattaché, et dit que l'annulation le rendra libre ---------------------------------
        $conn()->executeStatement('UPDATE note_de_frais SET trip_id = NULL, trip_source = NULL WHERE trip_id IS NOT NULL OR trip_source IS NOT NULL');
        $conn()->executeStatement('UPDATE commande SET trip_id = NULL, trip_source = NULL WHERE trip_id IS NOT NULL OR trip_source IS NOT NULL');
        $conn()->executeStatement('DELETE FROM gestion_trip'); // table propre à ces tests, comme dans BudgetAndTripsApiTest
        [$from, $to] = [$today->format('Y-m-d'), $today->modify('+2 days')->format('Y-m-d')];
        $noteId = (int) $this->post('/api/notes-de-frais', ['description' => "Taxi $tag", 'montant' => 4000, 'date' => $from, 'typeNoteDeFraisId' => $this->noteType()], 201)['id'];
        $free = fn (): array => ['notes' => (int) $conn()->fetchOne('SELECT COUNT(*) FROM note_de_frais WHERE trip_id IS NULL AND trip_source IS NULL AND date BETWEEN ? AND ?', [$from, $to]),
            'commandes' => (int) $conn()->fetchOne("SELECT COUNT(*) FROM commande WHERE kind <> 'reprise' AND trip_id IS NULL AND trip_source IS NULL AND CAST(date AS date) BETWEEN ? AND ?", [$from, $to])];
        // deux commandes datées dans le voyage : une ordinaire, qui sera rattachée, et une commande de reprise, qui reste hors voyage
        $typeId = (int) ($this->em()->getRepository(TypeFournisseur::class)->findOneBy(['name' => 'Autre']) ?? $this->create(TypeFournisseur::class, ['name' => 'Autre']))->getId();
        $supplierId = (int) $this->post('/api/fournisseurs', ['name' => "Voyage $tag", 'typeFournisseur' => $typeId], 201)['id'];
        $orderId = (int) $this->post('/api/collections/' . $period->getId() . '/commandes', ['budget' => 1000, 'date' => "$from 10:00:00", 'name' => "Achat $tag", 'fournisseur' => ['id' => $supplierId]], 201)['id'];
        $recoveryId = (int) $this->post('/api/collections/' . $period->getId() . '/commandes', ['budget' => 1000, 'date' => "$from 11:00:00", 'name' => "Reprise $tag", 'fournisseur' => ['id' => $supplierId]], 201)['id'];
        $conn()->executeStatement("UPDATE commande SET kind = 'reprise', trip_id = NULL, trip_source = NULL WHERE id = ?", [$recoveryId]);
        $expected = $free();
        $this->assertGreaterThanOrEqual(1, $expected['notes']);
        $this->assertGreaterThanOrEqual(1, $expected['commandes']);
        $trip = $this->post('/api/gestion/conversations', ['action' => 'trip.create'], 201);
        $tid = (int) $trip['id'];
        $this->assertSame(['name', 'place', 'startDate', 'endDate', 'budgetTotal', 'budgetDaily', 'currency', 'notes'], array_column($trip['questions'], 'id'), 'devise du site : pas de question de taux');
        $this->assertSame([['CAD', true], 4], [[$trip['questions'][6]['answer'], $trip['questions'][6]['proposed']], $trip['openQuestions']]);
        $foreign = $this->post("/api/gestion/conversations/$tid/answers", ['answers' => [['questionId' => 'currency', 'value' => 'eur']]], 200);
        $this->assertSame([['name', 'place', 'startDate', 'endDate', 'budgetTotal', 'budgetDaily', 'currency', 'exchangeRate', 'notes'], 5, 'EUR', 'Le voyage est en EUR et votre site en CAD. Combien vaut 1 EUR en CAD ?', 'number'],
            [array_column($foreign['questions'], 'id'), $foreign['openQuestions'], $foreign['questions'][4]['input']['currency'], $foreign['questions'][7]['text'], $foreign['questions'][7]['input']['type']]);
        foreach ([[['questionId' => 'exchangeRate', 'value' => 0], 'MUST_BE_POSITIVE'], [['questionId' => 'exchangeRate', 'value' => '1.5'], 'MUST_BE_POSITIVE'], [['questionId' => 'budgetTotal', 'value' => -1], 'INVALID_AMOUNT'],
            [['questionId' => 'currency', 'value' => 'XXX'], 'INVALID_CURRENCY'], [['questionId' => 'startDate', 'value' => 'x'], 'INVALID_DATE'], [['questionId' => 'name', 'value' => ''], 'REQUIRED']] as [$answer, $code]) {
            $this->assertSame(['answers[0].value' => $code], $this->post("/api/gestion/conversations/$tid/answers", ['answers' => [$answer]], 422)['fields'], json_encode($answer));
        }
        $this->assertSame(['answers[1].value' => 'BEFORE_START'], $this->post("/api/gestion/conversations/$tid/answers", ['answers' => [['questionId' => 'startDate', 'value' => $to], ['questionId' => 'endDate', 'value' => $from]]], 422)['fields']);
        $tripReady = $this->post("/api/gestion/conversations/$tid/answers", ['answers' => [['questionId' => 'name', 'value' => "Salon $tag"], ['questionId' => 'place', 'value' => 'Paris'], ['questionId' => 'startDate', 'value' => $from],
            ['questionId' => 'endDate', 'value' => $to], ['questionId' => 'budgetTotal', 'value' => 300000], ['questionId' => 'exchangeRate', 'value' => 1.5]]], 200);
        $some = sprintf('À la création, %d note(s) de frais et %d commande(s) libres datées dans le voyage y seront rattachées. Annuler le voyage rendra ces éléments libres.', $expected['notes'], $expected['commandes']);
        $this->assertSame(['ready', ['name' => "Salon $tag", 'place' => 'Paris', 'startDate' => $from, 'endDate' => $to, 'days' => 3, 'budgetTotal' => 300000, 'budgetDaily' => null, 'currency' => 'EUR', 'exchangeRate' => 1.5, 'notes' => null,
            'attaches' => $expected + ['text' => $some]]], [$tripReady['state'], array_diff_key($tripReady['preview'], ['rows' => 1])]);
        $this->assertSame([['label' => 'Nom du voyage', 'value' => "Salon $tag", 'unit' => 'text'], ['label' => 'Lieu', 'value' => 'Paris', 'unit' => 'text'], ['label' => 'Départ', 'value' => $from, 'unit' => 'date'], ['label' => 'Retour', 'value' => $to, 'unit' => 'date'],
            ['label' => 'Budget du voyage', 'value' => 300000, 'unit' => 'money', 'currency' => 'EUR'], ['label' => 'Devise', 'value' => 'EUR', 'unit' => 'text'], ['label' => 'Taux de change', 'value' => 1.5, 'unit' => 'number'],
            ['label' => 'Rattachements', 'value' => $some, 'unit' => 'text']], $tripReady['preview']['rows']);
        // retour à la devise du site : plus de question de taux, plus de taux dans l'aperçu
        $home = $this->post("/api/gestion/conversations/$tid/answers", ['answers' => [['questionId' => 'currency', 'value' => 'CAD'], ['questionId' => 'budgetDaily', 'value' => 90000]]], 200);
        $this->assertSame([['Budget par jour', 90000, 'CAD'], false], [array_values(array_intersect_key($home['preview']['rows'][5], ['label' => 1, 'value' => 1, 'currency' => 1])), in_array('Taux de change', array_column($home['preview']['rows'], 'label'), true)]);
        $this->assertSame(['ready', false, 'CAD', null, 90000], [$home['state'], in_array('exchangeRate', array_column($home['questions'], 'id'), true), $home['preview']['currency'], $home['preview']['exchangeRate'], $home['preview']['budgetDaily']]);
        // un voyage sans rien à rattacher le dit
        $empty = $this->post('/api/gestion/conversations', ['action' => 'trip.create', 'params' => ['name' => 'Loin', 'startDate' => $today->modify('+400 days')->format('Y-m-d'), 'endDate' => $today->modify('+401 days')->format('Y-m-d'), 'budgetTotal' => 0]], 201);
        $this->assertSame(['ready', ['notes' => 0, 'commandes' => 0, 'text' => 'Aucune note de frais ni commande libre n\'est datée dans ce voyage : rien ne sera rattaché.']], [$empty['state'], $empty['preview']['attaches']]);
        // en devise étrangère, avec un taux entier : créé avec sa devise et son taux, puis annulé sans faux « modifié depuis »
        $abroad = $this->post('/api/gestion/conversations/' . $empty['id'] . '/answers', ['answers' => [['questionId' => 'currency', 'value' => 'USD'], ['questionId' => 'exchangeRate', 'value' => 2]]], 200);
        $abroadId = (int) $this->post('/api/gestion/conversations/' . $empty['id'] . '/confirm', ['previewHash' => $abroad['previewHash']], 201)['result']['tripId'];
        $row = $conn()->fetchAssociative('SELECT currency, exchange_rate FROM gestion_trip WHERE id = ?', [$abroadId]);
        $this->assertSame(['USD', 2.0, []], [$row['currency'], (float) $row['exchange_rate'], $this->get('/api/gestion/conversations/' . $empty['id'])['undo']['blockers']]);
        // un budget journalier passé de « non saisi » à 0 est une modification
        $conn()->executeStatement('UPDATE gestion_trip SET budget_daily_cents = 0 WHERE id = ?', [$abroadId]);
        $this->assertSame(['TRIP_CHANGED'], array_column($this->post('/api/gestion/conversations/' . $empty['id'] . '/undo', [], 409)['reasons'], 'code'));
        $conn()->executeStatement('UPDATE gestion_trip SET budget_daily_cents = NULL WHERE id = ?', [$abroadId]);
        $this->assertSame(['removed' => ['trip' => $abroadId], 'freed' => ['notes' => 0, 'commandes' => 0]], $this->post('/api/gestion/conversations/' . $empty['id'] . '/undo', [], 200)['undone']);
        $this->post('/api/gestion/conversations/' . $empty['id'] . '/abandon', [], 200);
        // Un autre voyage posé sur ces dates entre-temps : le fil le voit, ne confirme pas
        $otherTrip = (int) $this->post('/api/gestion/voyages', ['name' => 'Autre', 'startDate' => $to, 'endDate' => $today->modify('+5 days')->format('Y-m-d'), 'budgetTotal' => 1], 201)['id'];
        $overlap = $this->get("/api/gestion/conversations/$tid");
        $this->assertSame(['questions', [['path' => 'startDate', 'code' => 'TRIP_OVERLAP', 'tripId' => $otherTrip]], null], [$overlap['state'], $overlap['issues'], $overlap['preview']]);
        $this->assertSame('CONVERSATION_NOT_READY', $this->post("/api/gestion/conversations/$tid/confirm", ['previewHash' => $home['previewHash']], 409)['error']);
        $this->request('DELETE', "/api/gestion/voyages/$otherTrip");
        $this->assertContains($this->client->getResponse()->getStatusCode(), [200, 204]);
        $expected = $free();
        $now = $this->get("/api/gestion/conversations/$tid");
        $this->assertSame(['ready', $expected], [$now['state'], array_diff_key($now['preview']['attaches'], ['text' => 1])]);
        $made = $this->post("/api/gestion/conversations/$tid/confirm", ['previewHash' => $now['previewHash']], 201);
        $tripId = (int) $made['result']['tripId'];
        $this->assertSame(['tripId' => $tripId], $made['result']);
        $this->assertSame(["Salon $tag", 300000, 90000, null], array_values($conn()->fetchAssociative('SELECT name, budget_total_cents, budget_daily_cents, currency FROM gestion_trip WHERE id = ?', [$tripId])));
        $this->assertSame([$tripId, 'auto'], array_values($conn()->fetchAssociative('SELECT trip_id, trip_source FROM note_de_frais WHERE id = ?', [$noteId])), 'la note libre datée dans le voyage y est rattachée');
        $this->assertSame([$tripId, null], [$conn()->fetchOne('SELECT trip_id FROM commande WHERE id = ?', [$orderId]), $conn()->fetchOne('SELECT trip_id FROM commande WHERE id = ?', [$recoveryId])], 'la commande ordinaire est rattachée, la commande de reprise reste hors voyage');
        // Annulation : bloquée si le voyage a changé, si un élément y a été rattaché à la main, ou si un élément rattaché est en période fermée
        $conn()->executeStatement("UPDATE gestion_trip SET name = 'Autre nom' WHERE id = ?", [$tripId]);
        $this->assertSame(['TRIP_CHANGED'], array_column($this->post("/api/gestion/conversations/$tid/undo", [], 409)['reasons'], 'code'));
        $conn()->executeStatement('UPDATE gestion_trip SET name = ?, budget_total_cents = 1 WHERE id = ?', ["Salon $tag", $tripId]);
        $this->assertSame(['TRIP_CHANGED'], array_column($this->post("/api/gestion/conversations/$tid/undo", [], 409)['reasons'], 'code'));
        $conn()->executeStatement('UPDATE gestion_trip SET budget_total_cents = 300000 WHERE id = ?', [$tripId]);
        $conn()->executeStatement("UPDATE note_de_frais SET trip_source = 'manual' WHERE id = ?", [$noteId]);
        $this->assertSame(['TRIP_USED'], array_column($this->post("/api/gestion/conversations/$tid/undo", [], 409)['reasons'], 'code'));
        $closedDay = \DateTimeImmutable::createFromInterface($period->getStartDateCollection())->modify('-1 year');
        $calendar->ensurePeriods($closedDay);
        $conn()->executeStatement("UPDATE note_de_frais SET trip_source = 'auto', date = ? WHERE id = ?", [$closedDay->format('Y-m-d'), $noteId]);
        $this->assertSame(['PERIOD_CLOSED'], array_column($this->post("/api/gestion/conversations/$tid/undo", [], 409)['reasons'], 'code'));
        $conn()->executeStatement('UPDATE note_de_frais SET date = ? WHERE id = ?', [$from, $noteId]);
        $attached = ['notes' => (int) $conn()->fetchOne('SELECT COUNT(*) FROM note_de_frais WHERE trip_id = ?', [$tripId]), 'commandes' => (int) $conn()->fetchOne('SELECT COUNT(*) FROM commande WHERE trip_id = ?', [$tripId])];
        $this->assertSame($expected, $attached, 'ce que l\'aperçu annonçait est ce qui a été rattaché');
        $this->assertSame(['removed' => ['trip' => $tripId], 'freed' => $attached], $this->post("/api/gestion/conversations/$tid/undo", [], 200)['undone']);
        $this->assertSame([false, [null, null]], [$conn()->fetchOne('SELECT id FROM gestion_trip WHERE id = ?', [$tripId]), array_values($conn()->fetchAssociative('SELECT trip_id, trip_source FROM note_de_frais WHERE id = ?', [$noteId]))], 'la note redevient libre');
        $this->assertSame([[$orderId, null], [$recoveryId, null]], array_map(fn (array $r) => [(int) $r['id'], $r['trip_id']], $conn()->fetchAllAssociative('SELECT id, trip_id FROM commande WHERE id IN (?, ?) ORDER BY id', [$orderId, $recoveryId])));
        $this->post("/api/gestion/conversations/$tid/abandon", [], 200);
        $conn()->executeStatement('DELETE FROM note_de_frais WHERE id = ?', [$noteId]);
        $conn()->executeStatement('DELETE FROM commande WHERE id IN (?, ?)', [$orderId, $recoveryId]);
        $conn()->executeStatement('DELETE FROM fournisseur WHERE id = ?', [$supplierId]);
    }

    public function testAFiscalYearAPeriodSplitAndAPurchaseCostAreEachPreparedAndUndone(): void
    {
        $tag = $this->tag;
        $calendar = static::getContainer()->get(PeriodCalendar::class);
        $period = $calendar->current();
        $today = $calendar->today();
        $conn = fn () => $this->em()->getConnection();
        $saved = $conn()->fetchAssociative('SELECT id, fiscal_year_start_month, purchase_period_frequency, prices_include_tax FROM entreprise ORDER BY id LIMIT 1');
        $setting = function (string $column, mixed $value) use ($conn, $saved): void {
            $conn()->executeStatement("UPDATE entreprise SET $column = ? WHERE id = ?", [$value, $saved['id']]);
            $this->endOfWrite();
        };
        $stored = fn (string $column) => $conn()->fetchOne("SELECT $column FROM entreprise WHERE id = ?", [$saved['id']]);
        $journal = fn (string $resource): int => (int) $conn()->fetchOne('SELECT COUNT(*) FROM content_audit_log WHERE resource = ?', [$resource]);
        $months = [1 => 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
        $long = fn (string $day): string => (($d = new \DateTimeImmutable($day))->format('j') === '1' ? '1er' : $d->format('j')) . ' ' . $months[(int) $d->format('n')] . ' ' . $d->format('Y');
        try {
            $setting('fiscal_year_start_month', null);
            $setting('purchase_period_frequency', null);

            // ---- fiscal_year.set : exercice civil (janvier) et trimestres saisonniers au départ --------------------------------------------
            $fiscal = $this->post('/api/gestion/conversations', ['action' => 'fiscal_year.set'], 201);
            $fid = (int) $fiscal['id'];
            $options = $fiscal['questions'][0]['options'];
            $this->assertSame([12, ['value' => 1, 'label' => 'Janvier', 'aligned' => false, 'current' => true], ['value' => 3, 'label' => 'Mars', 'aligned' => true, 'current' => false], [3, 6, 9, 12]],
                [count($options), $options[0], $options[2], array_column(array_filter($options, fn (array $o) => $o['aligned']), 'value')]);
            $this->assertSame([null, false, 1], [$fiscal['questions'][0]['answer'], $fiscal['questions'][0]['proposed'], $fiscal['openQuestions']], 'le gérant choisit : rien n\'est proposé');
            foreach ([[13, 'OUT_OF_RANGE'], [0, 'OUT_OF_RANGE'], ['3', 'MUST_BE_INTEGER']] as [$value, $code]) {
                $this->assertSame(['answers[0].value' => $code], $this->post("/api/gestion/conversations/$fid/answers", ['answers' => [['questionId' => 'startMonth', 'value' => $value]]], 422)['fields']);
            }
            // Site jamais réglé : choisir janvier est une vraie écriture (lot IA-ACT-4), pas « rien à confirmer »
            $january = $this->post("/api/gestion/conversations/$fid/answers", ['answers' => [['questionId' => 'startMonth', 'value' => 1]]], 200);
            $this->assertSame(['ready', [], ['startMonth' => 1, 'label' => 'Janvier', 'confirmed' => false], ['startMonth' => 1, 'label' => 'Janvier']], [$january['state'], $january['issues'], $january['preview']['before'], $january['preview']['after']]);
            // mars : aligné sur les trimestres saisonniers, aucun avertissement
            $march = $this->post("/api/gestion/conversations/$fid/answers", ['answers' => [['questionId' => 'startMonth', 'value' => 3]]], 200);
            $year = GestionFiscalYearService::startYearOf($today, 3);
            $this->assertSame(['ready', ['startMonth' => 1, 'label' => 'Janvier', 'confirmed' => false], ['startMonth' => 3, 'label' => 'Mars'], true, null], [$march['state'], $march['preview']['before'], $march['preview']['after'], $march['preview']['aligned'], $march['preview']['warning']]);
            $exercise = sprintf('Exercice %d-%d : du 1er mars %d au %s.', $year, $year + 1, $year, $long(sprintf('%d-03-01', $year + 1) . ' -1 day'));
            $this->assertSame(['label' => sprintf('%d-%d', $year, $year + 1), 'from' => "$year-03-01", 'to' => (new \DateTimeImmutable(($year + 1) . '-03-01 -1 day'))->format('Y-m-d'), 'text' => $exercise], $march['preview']['exercise']);
            $this->assertSame([['label' => 'Mois de début d\'exercice', 'value' => 'Mars', 'unit' => 'text', 'before' => 'Janvier'], ['label' => 'Exercice', 'value' => $exercise, 'unit' => 'text']], $march['preview']['rows']);
            // février : au milieu de l'hiver, l'aperçu dit quelle période serait coupée et à quelle date
            $february = $this->post("/api/gestion/conversations/$fid/answers", ['answers' => [['questionId' => 'startMonth', 'value' => 2]]], 200);
            $y2 = GestionFiscalYearService::startYearOf($today, 2);
            $winter = PeriodCalendar::definition(new \DateTimeImmutable("$y2-02-01"), 'quarterly');
            $warning = $february['preview']['warning'];
            $this->assertSame([false, 'NOT_ALIGNED', "$y2-02-01", ['key' => $winter['key'], 'name' => $winter['name'], 'from' => ($y2 - 1) . '-12-01', 'to' => $winter['end']->format('Y-m-d')]], [$february['preview']['aligned'], $warning['code'], $warning['cutDate'], $warning['period']]);
            $this->assertSame(sprintf('L\'exercice commencerait le 1er février %d, au milieu de la période « %s » (du 1er décembre %d au %s) : cette période serait coupée en deux, une partie sur chaque exercice.', $y2, $winter['name'], $y2 - 1, $long($winter['end']->format('Y-m-d'))), $warning['text']);
            $this->assertStringStartsWith('Hiver ', $winter['name']);
            $this->assertSame([['Attention', $warning['text']], 3], [[$february['preview']['rows'][2]['label'], $february['preview']['rows'][2]['value']], count($february['preview']['rows'])]);
            $english = $this->get("/api/gestion/conversations/$fid?locale=en");
            $this->assertSame([$february['previewHash'], 'February', 'Warning', true], [$english['previewHash'], $english['preview']['after']['label'], $english['preview']['rows'][2]['label'], str_contains($english['preview']['warning']['text'], 'would be split in two')]);
            $this->assertStringContainsString("February 1, $y2", $english['preview']['warning']['text']);
            // confirmation de mars, par le service du réglage ; annulation par le même service, les deux au journal
            $march = $this->post("/api/gestion/conversations/$fid/answers", ['answers' => [['questionId' => 'startMonth', 'value' => 3]]], 200);
            $logged = $journal('gestion-reglages-exercice');
            $this->assertSame(['startMonth' => 3], $this->post("/api/gestion/conversations/$fid/confirm", ['previewHash' => $march['previewHash']], 201)['result']);
            $this->assertSame([3, 3, $logged + 1], [(int) $stored('fiscal_year_start_month'), $this->get('/api/gestion/reglages/exercice')['startMonth'], $journal('gestion-reglages-exercice')]);
            $setting('fiscal_year_start_month', 6);
            $this->assertSame(['FISCAL_YEAR_CHANGED'], array_column($this->post("/api/gestion/conversations/$fid/undo", [], 409)['reasons'], 'code'));
            $setting('fiscal_year_start_month', 3);
            // le mois enregistré, lui, n'a rien à confirmer
            $rechosenMonth = $this->post('/api/gestion/conversations', ['action' => 'fiscal_year.set', 'params' => ['startMonth' => 3]], 201);
            $this->assertSame(['questions', [['path' => 'startMonth', 'code' => 'UNCHANGED']], null], [$rechosenMonth['state'], $rechosenMonth['issues'], $rechosenMonth['preview']], 'le mois déjà enregistré : rien à confirmer');
            $this->post('/api/gestion/conversations/' . $rechosenMonth['id'] . '/abandon', [], 200);
            $this->assertSame(['restored' => ['startMonth' => null]], $this->post("/api/gestion/conversations/$fid/undo", [], 200)['undone'], 'l\'annulation remet « jamais réglé »');
            $this->assertSame([null, 1, $logged + 2], [$stored('fiscal_year_start_month'), $this->get('/api/gestion/reglages/exercice')['startMonth'], $journal('gestion-reglages-exercice')], 'l\'annulation est au journal aussi');
            // Janvier confirmé sur un site jamais réglé : enregistré 1, l'étape du cheminement passe à « fait » ; l'annulation la remet « à faire »
            $entitlements = static::getContainer()->get(\App\Services\Platform\EntitlementService::class);
            $granted = $entitlements->isEnabled(MV_TEST_TENANT_CODE, 'app.management') ? null : (int) $entitlements->grant(MV_TEST_TENANT_CODE, 'app.management', 'manual', null, 'test', 'IA-ACT-4', null, null, false)['id'];
            $this->endOfWrite();
            try {
                $step = fn (): string => array_column($this->get('/api/gestion/sequences/setup.management')['steps'], 'status', 'key')['management.fiscal_year'];
                $this->assertSame('todo', $step());
                $january = $this->post("/api/gestion/conversations/$fid/answers", ['answers' => [['questionId' => 'startMonth', 'value' => 1]]], 200);
                $this->assertSame(['startMonth' => 1], $this->post("/api/gestion/conversations/$fid/confirm", ['previewHash' => $january['previewHash']], 201)['result']);
                $this->assertSame([1, 'done'], [(int) $stored('fiscal_year_start_month'), $step()], 'janvier confirmé se distingue d\'un réglage jamais fait');
                $this->assertSame(['questions', [['path' => 'startMonth', 'code' => 'UNCHANGED']]], array_values(array_intersect_key($this->post('/api/gestion/conversations', ['action' => 'fiscal_year.set', 'params' => ['startMonth' => 1]], 201), ['state' => 1, 'issues' => 1])));
                $this->assertSame(['restored' => ['startMonth' => null]], $this->post("/api/gestion/conversations/$fid/undo", [], 200)['undone']);
                $this->assertSame([null, 'todo'], [$stored('fiscal_year_start_month'), $step()]);
            } finally {
                if ($granted !== null) {
                    $entitlements->cancel($granted, 'test', 'IA-ACT-4');
                    $master = static::getContainer()->get(\App\Services\TenantConnectionManager::class)->getPdoMaster();
                    $master->prepare('DELETE FROM tenant_entitlement_events WHERE entitlement_id = ?')->execute([$granted]);
                    $master->prepare('DELETE FROM tenant_entitlements WHERE id = ?')->execute([$granted]);
                }
            }
            $this->post("/api/gestion/conversations/$fid/abandon", [], 200);

            // ---- periods.calendar.set : rien d'enregistré au départ (trimestres par défaut) -------------------------------------------------
            $split = $this->post('/api/gestion/conversations', ['action' => 'periods.calendar.set'], 201);
            $pid = (int) $split['id'];
            $this->assertSame([['value' => 'quarterly', 'label' => 'Trimestres saisonniers', 'current' => true], ['value' => 'semester', 'label' => 'Semestres', 'current' => false], ['value' => 'monthly', 'label' => 'Mois', 'current' => false]], $split['questions'][0]['options']);
            $this->assertSame(['answers[0].value' => 'INVALID_FREQUENCY'], $this->post("/api/gestion/conversations/$pid/answers", ['answers' => [['questionId' => 'frequency', 'value' => 'weekly']]], 422)['fields']);
            // confirmer le découpage par défaut est une vraie écriture ; l'aperçu dit que rien ne change
            $default = $this->post("/api/gestion/conversations/$pid/answers", ['answers' => [['questionId' => 'frequency', 'value' => 'quarterly']]], 200);
            $this->assertSame(['ready', ['frequency' => 'quarterly', 'label' => 'Trimestres saisonniers', 'confirmed' => false], null, 'Le découpage ne change pas : il est maintenant confirmé.'],
                [$default['state'], $default['preview']['before'], $default['preview']['appliesFrom'], $default['preview']['text']]);
            // passer aux mois : les périodes créées ne bougent pas, le nouveau découpage commence le lendemain de la fin de la dernière
            $monthly = $this->post("/api/gestion/conversations/$pid/answers", ['answers' => [['questionId' => 'frequency', 'value' => 'monthly']]], 200);
            $appliesFrom = (new \DateTimeImmutable(substr((string) $conn()->fetchOne("SELECT MAX(end_date_collection) FROM collections WHERE kind = 'period'"), 0, 10)))->modify('+1 day')->format('Y-m-d');
            $text = 'Les périodes déjà créées ne changent pas. Le nouveau découpage s\'applique à partir du ' . $long($appliesFrom) . '.';
            $this->assertSame(['frequency' => 'monthly', 'label' => 'Mois'], $monthly['preview']['after']);
            $this->assertSame([$appliesFrom, $text], [$monthly['preview']['appliesFrom'], $monthly['preview']['text']]);
            $this->assertSame([['label' => 'Découpage des périodes', 'value' => 'Mois', 'unit' => 'text', 'before' => 'Trimestres saisonniers'], ['label' => 'Application', 'value' => $text, 'unit' => 'text']], $monthly['preview']['rows']);
            $logged = $journal('entreprise');
            $periods = (int) $conn()->fetchOne("SELECT COUNT(*) FROM collections WHERE kind = 'period'");
            $this->assertSame(['frequency' => 'monthly'], $this->post("/api/gestion/conversations/$pid/confirm", ['previewHash' => $monthly['previewHash']], 201)['result']);
            $this->assertSame(['monthly', $logged + 1, $periods], [$stored('purchase_period_frequency'), $journal('entreprise'), (int) $conn()->fetchOne("SELECT COUNT(*) FROM collections WHERE kind = 'period'")], 'écrit par le chemin de la fiche, au journal ; aucune période créée ni modifiée');
            // refus d'annulation : réglage changé depuis ; une période déjà créée avec le nouveau découpage
            $setting('purchase_period_frequency', 'semester');
            $this->assertSame(['CALENDAR_CHANGED'], array_column($this->post("/api/gestion/conversations/$pid/undo", [], 409)['reasons'], 'code'));
            $setting('purchase_period_frequency', 'monthly');
            $conn()->executeStatement("INSERT INTO collections (id, budget_collection, start_date_collection, end_date_collection, del, nom_collection, is_closed, kind, period_key) VALUES (nextval('collections_id_seq'), 0, '2999-01-01', '2999-01-31', false, ?, false, 'period', ?)", ["Essai $tag", "T-$tag"]);
            $this->assertSame(['PERIODS_CREATED'], array_column($this->post("/api/gestion/conversations/$pid/undo", [], 409)['reasons'], 'code'));
            $conn()->executeStatement('DELETE FROM collections WHERE period_key = ?', ["T-$tag"]);
            $this->assertSame(['restored' => ['frequency' => null]], $this->post("/api/gestion/conversations/$pid/undo", [], 200)['undone']);
            $this->assertSame([null, $logged + 2, 'quarterly'], [$stored('purchase_period_frequency'), $journal('entreprise'), $calendar->frequency()]);
            // confirmer le découpage par défaut puis l'annuler : une période créée entre-temps a le même format, elle ne bloque pas
            $default = $this->get("/api/gestion/conversations/$pid");
            $this->post("/api/gestion/conversations/$pid/answers", ['answers' => [['questionId' => 'frequency', 'value' => 'quarterly']]], 200);
            $this->post("/api/gestion/conversations/$pid/confirm", ['previewHash' => $this->get("/api/gestion/conversations/$pid")['previewHash']], 201);
            $this->assertSame('quarterly', $stored('purchase_period_frequency'));
            $rechosen = $this->post('/api/gestion/conversations', ['action' => 'periods.calendar.set', 'params' => ['frequency' => 'quarterly']], 201);
            $this->assertSame(['questions', [['path' => 'frequency', 'code' => 'UNCHANGED']]], [$rechosen['state'], $rechosen['issues']], 'la valeur déjà enregistrée : rien à confirmer');
            $this->post('/api/gestion/conversations/' . $rechosen['id'] . '/abandon', [], 200);
            $conn()->executeStatement("INSERT INTO collections (id, budget_collection, start_date_collection, end_date_collection, del, nom_collection, is_closed, kind, period_key) VALUES (nextval('collections_id_seq'), 0, '2999-01-01', '2999-03-31', false, ?, false, 'period', ?)", ["Essai $tag", "T-$tag"]);
            $this->assertSame([], $this->get("/api/gestion/conversations/$pid")['undo']['blockers']);
            $conn()->executeStatement('DELETE FROM collections WHERE period_key = ?', ["T-$tag"]);
            $this->assertSame(['restored' => ['frequency' => null]], $this->post("/api/gestion/conversations/$pid/undo", [], 200)['undone']);
            $this->post("/api/gestion/conversations/$pid/abandon", [], 200);

            // ---- product.cost.set : le coût seul, le prix de vente n'est pas touché ; l'aperçu dit l'effet sur les chiffres --------------------
            $slug = "vase-$tag";
            $conn()->executeStatement("INSERT INTO product (id, name, price, isbestseller, quantity, created_at, slug, product_type, purchase_price, coefficient_multiplier, code) VALUES (nextval('product_id_seq'), ?, 5000, false, 5, NOW(), ?, 'standard', NULL, 2, ?)", ["Vase $tag", $slug, "C-$slug"]);
            $productId = (int) $conn()->fetchOne('SELECT id FROM product WHERE slug = ?', [$slug]);
            $variantId = (int) $this->create(ProductVariant::class, ['product' => $this->em()->find(Product::class, $productId)])->getId();
            $userId = (int) $conn()->fetchOne('SELECT id FROM "user" ORDER BY id LIMIT 1');
            $sell = function (\DateTimeImmutable $at, int $quantity) use ($variantId, $userId): int {
                $order = $this->create(Order::class, ['totalAmount' => 1.0], function (Order $o, EntityManagerInterface $em) use ($at, $userId): void {
                    $o->setOrderDate(\DateTime::createFromInterface($at))->setStatus($em->find(StatusCommande::class, 2))->setOrderType($em->find(OrderType::class, 1))->setOrderSource($em->find(OrderSource::class, 2))->setUserId($em->find(User::class, $userId));
                });
                $this->create(OrderItems::class, ['quantity' => $quantity, 'unitPrice' => 5000.0, 'totalPrice' => (float) (5000 * $quantity)], function (OrderItems $i, EntityManagerInterface $em) use ($order, $variantId): void {
                    $i->setOrderAssociated($em->find(Order::class, $order->getId()))->setProductVariant($em->find(ProductVariant::class, $variantId));
                });

                return (int) $order->getId();
            };
            $closedDay = \DateTimeImmutable::createFromInterface($period->getStartDateCollection())->modify('-1 year');
            $calendar->ensurePeriods($closedDay);
            $orders = [$sell(new \DateTimeImmutable('-1 hour'), 3), $sell($closedDay->modify('+2 days'), 2)]; // 3 unités dans la période en cours, 2 dans une période fermée
            $this->endOfWrite();
            $calculator = static::getContainer()->get(PeriodResultCalculator::class);
            [$from, $to] = [\DateTimeImmutable::createFromInterface($period->getStartDateCollection()), \DateTimeImmutable::createFromInterface($period->getEndDateCollection())];
            $this->em();
            $cogsAll = $calculator->costFigures($from, $to)['cogs'];
            $this->assertSame(['cogs' => 0, 'unknownItems' => 3], $calculator->costFigures($from, $to, [$productId]), 'avant : 3 unités vendues sans coût connu');
            $includesTax = in_array($saved['prices_include_tax'], [true, 't', 1, '1'], true);
            $cost = $this->post('/api/gestion/conversations', ['action' => 'product.cost.set'], 201);
            $kid = (int) $cost['id'];
            $this->assertSame([['productId', 'choice'], ['cost', 'money']], array_map(fn (array $q) => [$q['id'], $q['input']['type']], $cost['questions']));
            $this->assertContains(['value' => $productId, 'label' => "Vase $tag", 'code' => "C-$slug", 'price' => 5000], $cost['questions'][0]['options'], 'les articles sans coût sont proposés');
            foreach ([[['questionId' => 'productId', 'value' => 999999999], 'PRODUCT_NOT_FOUND'], [['questionId' => 'productId', 'value' => 'x'], 'PRODUCT_NOT_FOUND'], [['questionId' => 'cost', 'value' => 0], 'MUST_BE_POSITIVE'], [['questionId' => 'cost', 'value' => 12.5], 'INVALID_AMOUNT']] as [$answer, $code]) {
                $this->assertSame(['answers[0].value' => $code], $this->post("/api/gestion/conversations/$kid/answers", ['answers' => [$answer]], 422)['fields'], json_encode($answer));
            }
            $ready = $this->post("/api/gestion/conversations/$kid/answers", ['answers' => [['questionId' => 'productId', 'value' => $productId], ['questionId' => 'cost', 'value' => 2000]]], 200);
            $periodName = PeriodCalendar::displayName($period, 'fr');
            $money = fn (int $cents): string => number_format($cents / 100, 2, ',', ' ') . ' CAD';
            $effect = '3 unité(s) vendue(s) dans les périodes ouvertes passeront de « vente sans coût connu » à une vente valorisée. Coût des articles vendus de la période « ' . $periodName . ' » : ' . $money($cogsAll) . ' avant, ' . $money($cogsAll + 6000)
                . ' après. Les périodes fermées sont figées : leurs chiffres ne changent pas.';
            $this->assertSame(['ready', ['id' => $productId, 'name' => "Vase $tag", 'code' => "C-$slug"], 'CAD', ['before' => null, 'after' => 2000], 5000, ['amount' => 3000, 'rate' => 60, 'pricesIncludeTax' => $includesTax]],
                [$ready['state'], $ready['preview']['product'], $ready['preview']['currency'], $ready['preview']['cost'], $ready['preview']['price'], $ready['preview']['margin']]);
            $this->assertSame([3, ['id' => (int) $period->getId(), 'name' => $periodName, 'cogsBefore' => $cogsAll, 'cogsAfter' => $cogsAll + 6000], true, $effect],
                [$ready['preview']['effect']['salesValued'], $ready['preview']['effect']['currentPeriod'], $ready['preview']['effect']['closedPeriodsFrozen'], $ready['preview']['effect']['text']], 'la vente de la période fermée ne compte pas');
            $openStarted = count(array_filter($this->em()->getRepository(\App\Entity\Collections::class)->findBy(['kind' => 'period']),
                fn ($p) => !$p->isDel() && !$calendar->isClosed($p) && $p->getStartDateCollection()->format('Y-m-d') <= $today->format('Y-m-d')));
            $this->assertSame([$openStarted, true], [$ready['preview']['effect']['openPeriods'], $openStarted >= 1], 'périodes ouvertes déjà commencées : ni fermées, ni à venir');
            $this->assertSame([['label' => 'Article', 'value' => "Vase $tag", 'unit' => 'text'], ['label' => 'Coût d\'achat', 'value' => 2000, 'unit' => 'money', 'currency' => 'CAD'], ['label' => 'Prix de vente (inchangé)', 'value' => 5000, 'unit' => 'money', 'currency' => 'CAD'],
                ['label' => 'Marge par unité', 'value' => 3000, 'unit' => 'money', 'currency' => 'CAD'], ['label' => 'Taux de marge sur le prix de vente', 'value' => '60,00 %' . ($includesTax ? ' (prix taxes comprises : la marge comprend la taxe)' : ''), 'unit' => 'text'],
                ['label' => 'Effet sur vos chiffres', 'value' => $effect, 'unit' => 'text']], $ready['preview']['rows']);
            // confirmation : le coût est posé, le prix de vente reste 50,00 (et non coût × coefficient = 40,00), le journal le note
            $logged = $journal('products');
            $this->assertSame(['productId' => $productId, 'cost' => 2000], $this->post("/api/gestion/conversations/$kid/confirm", ['previewHash' => $ready['previewHash']], 201)['result']);
            $row = $conn()->fetchAssociative('SELECT purchase_price, price, coefficient_multiplier FROM product WHERE id = ?', [$productId]);
            $this->assertSame([2000, 5000, 2, $logged + 1], [(int) $row['purchase_price'], (int) $row['price'], (int) $row['coefficient_multiplier'], $journal('products')], 'prix de vente intact');
            $this->em();
            $this->assertSame(['cogs' => 6000, 'unknownItems' => 0], $calculator->costFigures($from, $to, [$productId]), 'ce que l\'aperçu annonçait est ce que le résultat calcule');
            $this->assertNotContains($productId, array_column($this->post('/api/gestion/conversations', ['action' => 'product.cost.set'], 201)['questions'][0]['options'], 'value'), 'l\'article a un coût : il n\'est plus proposé');
            // annulation : refusée si le coût a changé depuis ; sinon le coût d'avant revient (vide), prix intact, au journal
            $conn()->executeStatement('UPDATE product SET purchase_price = 2500 WHERE id = ?', [$productId]);
            $this->assertSame(['COST_CHANGED'], array_column($this->post("/api/gestion/conversations/$kid/undo", [], 409)['reasons'], 'code'));
            $conn()->executeStatement('UPDATE product SET purchase_price = 2000 WHERE id = ?', [$productId]);
            $this->assertSame(['restored' => ['productId' => $productId, 'cost' => null]], $this->post("/api/gestion/conversations/$kid/undo", [], 200)['undone']);
            $row = $conn()->fetchAssociative('SELECT purchase_price, price FROM product WHERE id = ?', [$productId]);
            $this->assertSame([null, 5000, $logged + 2], [$row['purchase_price'], (int) $row['price'], $journal('products')]);
            $this->post("/api/gestion/conversations/$kid/abandon", [], 200);
            // un article qui a déjà un coût de fiche : l'aperçu montre l'ancien, aucune vente ne change de statut, le coût des ventes bouge de l'écart
            $conn()->executeStatement('UPDATE product SET purchase_price = 1000 WHERE id = ?', [$productId]);
            $this->endOfWrite();
            $replace = $this->post('/api/gestion/conversations', ['action' => 'product.cost.set', 'params' => ['productId' => $productId, 'cost' => 1500]], 201);
            $this->assertSame(['ready', ['before' => 1000, 'after' => 1500], 0, $cogsAll + 3000, $cogsAll + 4500, ['label' => 'Coût d\'achat', 'value' => 1500, 'unit' => 'money', 'currency' => 'CAD', 'before' => 1000]],
                [$replace['state'], $replace['preview']['cost'], $replace['preview']['effect']['salesValued'], $replace['preview']['effect']['currentPeriod']['cogsBefore'], $replace['preview']['effect']['currentPeriod']['cogsAfter'], $replace['preview']['rows'][1]]);
            $this->assertStringStartsWith('Aucune vente des périodes ouvertes ne change de valeur.', $replace['preview']['effect']['text']);
            $this->post('/api/gestion/conversations/' . $replace['id'] . '/confirm', ['previewHash' => $replace['previewHash']], 201);
            $this->assertSame(['restored' => ['productId' => $productId, 'cost' => 1000]], $this->post('/api/gestion/conversations/' . $replace['id'] . '/undo', [], 200)['undone']);
            $this->assertSame([1000, 5000], array_map('intval', array_values($conn()->fetchAssociative('SELECT purchase_price, price FROM product WHERE id = ?', [$productId]))));
            // un coût de ligne d'achat garde la priorité sur le coût de la fiche : poser un coût de fiche ne change alors aucun chiffre
            $typeId = (int) ($this->em()->getRepository(TypeFournisseur::class)->findOneBy(['name' => 'Autre']) ?? $this->create(TypeFournisseur::class, ['name' => 'Autre']))->getId();
            $supplierId = (int) $this->post('/api/fournisseurs', ['name' => "Potier $tag", 'typeFournisseur' => $typeId], 201)['id'];
            $commandeId = (int) $this->post('/api/collections/' . $period->getId() . '/commandes', ['budget' => 1000, 'date' => $today->format('Y-m-d') . ' 00:10:00', 'name' => "Achat $tag", 'fournisseur' => ['id' => $supplierId]], 201)['id'];
            $this->request('POST', "/api/commandes/$commandeId/products/rattacher", ['products' => [['productId' => $productId, 'purchasePrice' => 900, 'quantity' => 5]]]);
            $this->assertContains($this->client->getResponse()->getStatusCode(), [200, 201], substr((string) $this->client->getResponse()->getContent(), 0, 300));
            $this->em();
            $this->assertSame(['cogs' => 2700, 'unknownItems' => 0], $calculator->costFigures($from, $to, [$productId]), '3 unités au coût de la ligne d\'achat (9,00)');
            $lined = $this->post('/api/gestion/conversations', ['action' => 'product.cost.set', 'params' => ['productId' => $productId, 'cost' => 1700]], 201);
            $this->assertSame([0, true], [$lined['preview']['effect']['salesValued'], $lined['preview']['effect']['currentPeriod']['cogsBefore'] === $lined['preview']['effect']['currentPeriod']['cogsAfter']], 'le coût de la ligne d\'achat l\'emporte : aucun chiffre ne bouge');
            $this->post('/api/gestion/conversations/' . $lined['id'] . '/abandon', [], 200);
            // l'article supprimé entre-temps : un fil en cours le voit, et une confirmation ne peut plus être annulée
            $this->post('/api/gestion/conversations/' . $replace['id'] . '/confirm', ['previewHash' => $this->get('/api/gestion/conversations/' . $replace['id'])['previewHash']], 201);
            $gone = $this->post('/api/gestion/conversations', ['action' => 'product.cost.set', 'params' => ['productId' => $productId, 'cost' => 1800]], 201);
            $this->assertSame('ready', $gone['state']);
            $conn()->executeStatement('DELETE FROM commande_line WHERE product_id = ?', [$productId]);
            $conn()->executeStatement('UPDATE product SET commande_id = NULL WHERE id = ?', [$productId]);
            $conn()->executeStatement('DELETE FROM commande WHERE id = ?', [$commandeId]);
            $conn()->executeStatement('DELETE FROM fournisseur WHERE id = ?', [$supplierId]);
            $conn()->executeStatement('DELETE FROM order_items WHERE product_variant_id = ?', [$variantId]);
            $conn()->executeStatement('DELETE FROM "order" WHERE id IN (?, ?)', $orders);
            $conn()->executeStatement('DELETE FROM product_variant WHERE id = ?', [$variantId]);
            $conn()->executeStatement('DELETE FROM product WHERE id = ?', [$productId]);
            $this->endOfWrite();
            $seen = $this->get('/api/gestion/conversations/' . $gone['id']);
            $this->assertSame(['questions', [['path' => 'productId', 'code' => 'PRODUCT_NOT_FOUND']], null], [$seen['state'], $seen['issues'], $seen['preview']]);
            $this->assertSame([['code' => 'COST_CHANGED', 'message' => 'L\'article n\'existe plus.']], $this->get('/api/gestion/conversations/' . $replace['id'])['undo']['blockers']);
            $this->assertSame('UNDO_BLOCKED', $this->post('/api/gestion/conversations/' . $replace['id'] . '/undo', [], 409)['error']);
            $this->post('/api/gestion/conversations/' . $gone['id'] . '/abandon', [], 200);
        } finally {
            $conn()->executeStatement('UPDATE entreprise SET fiscal_year_start_month = ?, purchase_period_frequency = ? WHERE id = ?', [$saved['fiscal_year_start_month'], $saved['purchase_period_frequency'], $saved['id']]);
            $conn()->executeStatement('DELETE FROM collections WHERE period_key = ?', ["T-$tag"]);
            $this->endOfWrite();
        }
    }

    public function testAFinishedPeriodIsClosedThroughTheThreadAndUndoneExactly(): void
    {
        $tag = $this->tag;
        $calendar = static::getContainer()->get(PeriodCalendar::class);
        $calculator = static::getContainer()->get(PeriodResultCalculator::class);
        $current = $calendar->current();
        $today = $calendar->today();
        $conn = fn () => $this->em()->getConnection();
        // Une période terminée et rouverte (la précédente, fermée par la date) : c'est ce que le fil sait clôturer ; une plus ancienne reste fermée
        $lastDay = \DateTimeImmutable::createFromInterface($current->getStartDateCollection())->modify('-1 day');
        $calendar->ensurePeriods($lastDay->modify('-200 days'));
        $previous = $calendar->findByDate($lastDay);
        $older = $calendar->findByDate(\DateTimeImmutable::createFromInterface($previous->getStartDateCollection())->modify('-1 day'));
        $pid = (int) $previous->getId();
        $original = $conn()->fetchAssociative('SELECT is_closed, closed_at, reopened_at FROM collections WHERE id = ?', [$pid]);
        $day = \DateTimeImmutable::createFromInterface($previous->getEndDateCollection())->modify('-10 days');
        $journal = fn (): int => (int) $conn()->fetchOne("SELECT COUNT(*) FROM content_audit_log WHERE resource = 'gestion-periodes-cloture'");
        $created = ['orders' => [], 'commandes' => [], 'products' => [], 'variants' => [], 'supplier' => null];
        try {
            $conn()->executeStatement("UPDATE collections SET is_closed = false, closed_at = NULL, reopened_at = NOW() - INTERVAL '1 hour' WHERE id = ?", [$pid]);
            $conn()->executeStatement('DELETE FROM period_result WHERE collection_id = ?', [$pid]);
            $this->endOfWrite();
            $this->assertFalse($calendar->isClosed($this->em()->find(\App\Entity\Collections::class, $pid)));

            // Données de la période : une commande ouverte avec deux articles, une commande déjà fermée, une vente d'un article sans coût
            $typeId = (int) ($this->em()->getRepository(TypeFournisseur::class)->findOneBy(['name' => 'Autre']) ?? $this->create(TypeFournisseur::class, ['name' => 'Autre']))->getId();
            $created['supplier'] = (int) $this->post('/api/fournisseurs', ['name' => "Clôture $tag", 'typeFournisseur' => $typeId], 201)['id'];
            $commande = fn (string $name): int => (int) $this->post("/api/collections/$pid/commandes", ['budget' => 1000, 'date' => $day->format('Y-m-d') . ' 10:00:00', 'name' => $name, 'fournisseur' => ['id' => $created['supplier']]], 201)['id'];
            $created['commandes'] = [$open = $commande("Ouverte $tag"), $alreadyClosed = $commande("Déjà fermée $tag")];
            $product = function (string $name, ?int $frozen) use ($conn, $tag, &$created): array {
                $slug = strtolower($name) . "-$tag";
                $conn()->executeStatement("INSERT INTO product (id, name, price, isbestseller, quantity, created_at, slug, product_type, purchase_price, freeze_quantity, code) VALUES (nextval('product_id_seq'), ?, 4000, false, 6, NOW(), ?, 'standard', NULL, ?, ?)", ["$name $tag", $slug, $frozen, "C-$slug"]);
                $id = (int) $conn()->fetchOne('SELECT id FROM product WHERE slug = ?', [$slug]);
                $variant = (int) $this->create(ProductVariant::class, ['product' => $this->em()->find(Product::class, $id)])->getId();
                $created['products'][] = $id;
                $created['variants'][] = $variant;

                return [$id, $variant];
            };
            [$lampe, $lampeVariant] = $product('Lampe', null);
            [$tapis] = $product('Tapis', 7);
            [$vendu, $venduVariant] = $product('Vendu', null);
            $this->request('POST', "/api/commandes/$open/products/rattacher", ['products' => [['productId' => $lampe, 'purchasePrice' => 1500, 'quantity' => 6], ['productId' => $tapis, 'purchasePrice' => 2500, 'quantity' => 6]]]);
            $this->assertContains($this->client->getResponse()->getStatusCode(), [200, 201], substr((string) $this->client->getResponse()->getContent(), 0, 300));
            $conn()->executeStatement('UPDATE product SET freeze_quantity = NULL WHERE id = ?', [$lampe]);
            $conn()->executeStatement('UPDATE product SET freeze_quantity = 7 WHERE id = ?', [$tapis]);
            $conn()->executeStatement('UPDATE commande SET is_closed = true WHERE id = ?', [$alreadyClosed]);
            $userId = (int) $conn()->fetchOne('SELECT id FROM "user" ORDER BY id LIMIT 1');
            $sell = function (int $variantId, int $quantity, \DateTimeImmutable $at) use ($userId, &$created): void {
                $order = $this->create(Order::class, ['totalAmount' => 1.0], function (Order $o, EntityManagerInterface $em) use ($at, $userId): void {
                    $o->setOrderDate(\DateTime::createFromInterface($at))->setStatus($em->find(StatusCommande::class, 2))->setOrderType($em->find(OrderType::class, 1))->setOrderSource($em->find(OrderSource::class, 2))->setUserId($em->find(User::class, $userId));
                });
                $created['orders'][] = (int) $order->getId();
                $this->create(OrderItems::class, ['quantity' => $quantity, 'unitPrice' => 4000.0, 'totalPrice' => (float) (4000 * $quantity)], function (OrderItems $i, EntityManagerInterface $em) use ($order, $variantId): void {
                    $i->setOrderAssociated($em->find(Order::class, $order->getId()))->setProductVariant($em->find(ProductVariant::class, $variantId));
                });
            };
            $sell($venduVariant, 2, $day->setTime(15, 0));
            $this->endOfWrite();
            $state = fn (): array => [
                'period' => $conn()->fetchAssociative('SELECT is_closed, closed_at, reopened_at FROM collections WHERE id = ?', [$pid]),
                'commandes' => $conn()->fetchAllAssociative('SELECT id, is_closed FROM commande WHERE id IN (?, ?) ORDER BY id', [$open, $alreadyClosed]),
                'products' => $conn()->fetchAllAssociative('SELECT id, freeze_quantity, quantity FROM product WHERE id IN (?, ?, ?) ORDER BY id', [$lampe, $tapis, $vendu]),
                'lines' => $conn()->fetchAllAssociative('SELECT id, quantity_ordered, quantity_received FROM commande_line WHERE commande_id = ? ORDER BY id', [$open]),
                'collectionStats' => (int) $conn()->fetchOne('SELECT COUNT(*) FROM collection_statistiques WHERE collection_id = ?', [$pid]),
                'commandeStats' => (int) $conn()->fetchOne('SELECT COUNT(*) FROM commande_statistiques WHERE commande_id IN (?, ?)', [$open, $alreadyClosed]),
                'periodResults' => (int) $conn()->fetchOne('SELECT COUNT(*) FROM period_result WHERE collection_id = ?', [$pid]),
            ];
            $before = $state();
            $logged = $journal();

            // Ouverture : seules les périodes terminées et encore ouvertes sont proposées ; la période en cours et une période fermée sont refusées
            $thread = $this->post('/api/gestion/conversations', ['action' => 'period.close'], 201);
            $id = (int) $thread['id'];
            $offered = array_column($thread['questions'][0]['options'], 'value');
            $this->assertSame([true, false, false], [in_array($pid, $offered, true), in_array((int) $current->getId(), $offered, true), in_array((int) $older->getId(), $offered, true)]);
            $this->assertSame('Clôturer une période', $thread['action']['title']);
            foreach ([[(int) $current->getId(), 'PERIOD_NOT_ENDED'], [(int) $older->getId(), 'ALREADY_CLOSED'], [999999999, 'PERIOD_NOT_FOUND'], ['x', 'PERIOD_NOT_FOUND']] as [$value, $code]) {
                $this->assertSame(['answers[0].value' => $code], $this->post("/api/gestion/conversations/$id/answers", ['answers' => [['questionId' => 'periodId', 'value' => $value]]], 422)['fields'], json_encode($value));
            }
            $this->assertSame(['params.periodId' => 'PERIOD_NOT_ENDED'], $this->post('/api/gestion/conversations', ['action' => 'period.close', 'params' => ['periodId' => (int) $current->getId()]], 422)['fields']);

            // Aperçu : le résultat qui sera figé, les commandes qui seront fermées, les alertes, la conséquence
            $ready = $this->post("/api/gestion/conversations/$id/answers", ['answers' => [['questionId' => 'periodId', 'value' => $pid]]], 200);
            $this->em();
            $live = $calculator->compute($this->em()->find(\App\Entity\Collections::class, $pid), null);
            $this->assertSame('ready', $ready['state']);
            $this->assertSame(['id' => $pid, 'name' => PeriodCalendar::displayName($previous, 'fr'), 'from' => $previous->getStartDateCollection()->format('Y-m-d'), 'to' => $previous->getEndDateCollection()->format('Y-m-d')], $ready['preview']['period']);
            $this->assertSame(['revenue' => $live['sales']['revenue'], 'unknownCostRevenue' => $live['sales']['unknownCostRevenue'], 'cogs' => $live['cogs']['amount'], 'unknownCostItems' => $live['cogs']['unknownCostItems'],
                'expenses' => $live['expenses']['total'], 'gross' => $live['result']['gross'], 'net' => $live['result']['net']], $ready['preview']['result']);
            $this->assertGreaterThanOrEqual(2, $ready['preview']['result']['unknownCostItems']);
            $this->assertSame([['id' => $open, 'name' => "Ouverte $tag", 'date' => $day->format('Y-m-d')]], array_values(array_filter($ready['preview']['commandes'], fn (array $c) => in_array($c['id'], [$open, $alreadyClosed], true))), 'la commande déjà fermée n\'est pas de celles que la clôture fermera');
            $alerts = array_column($ready['preview']['alerts'], null, 'code');
            $this->assertSame(['UNKNOWN_COST', 'ORDERS_WITHOUT_SHIPPING'], array_keys($alerts));
            $this->assertSame([$ready['preview']['result']['unknownCostItems'], count($ready['preview']['commandes'])], [$alerts['UNKNOWN_COST']['count'], $alerts['ORDERS_WITHOUT_SHIPPING']['count']]);
            $this->assertStringContainsString('resteront sans coût dans les chiffres figés', $alerts['UNKNOWN_COST']['text']);
            $this->assertSame('Après la clôture, aucune écriture datée dans cette période ne sera acceptée (commandes, frais de port, notes de frais), et ses chiffres seront figés.', $ready['preview']['consequence']);
            $rows = $ready['preview']['rows'];
            $this->assertSame(['Période', 'Ventes de la période', 'Coût des articles vendus', 'Frais', 'Résultat qui sera figé', 'Commandes fournisseurs qui seront fermées', 'À savoir avant de clôturer', 'À savoir avant de clôturer', 'Après la clôture'], array_column($rows, 'label'));
            $this->assertSame([['value' => $live['sales']['revenue'], 'unit' => 'money', 'currency' => 'CAD'], ['value' => count($ready['preview']['commandes']), 'unit' => 'number']], [array_diff_key($rows[1], ['label' => 1]), array_diff_key($rows[5], ['label' => 1])]);
            $this->assertSame($ready['previewHash'], $this->get("/api/gestion/conversations/$id?locale=en")['previewHash']);

            // L'empreinte est recalculée sans le cache du résultat : une vente faite après l'aperçu, résultat encore en cache → PREVIEW_CHANGED
            $cached = $this->get("/api/periodes/$pid/resultat")['sales']['revenue'];
            $this->assertSame($live['sales']['revenue'], $cached);
            $sell($venduVariant, 1, $day->setTime(16, 0)); // écrite sans vider le cache du site
            $this->assertSame($cached, $this->get("/api/periodes/$pid/resultat")['sales']['revenue'], 'la route du résultat sert encore sa réponse en cache');
            $stale = $this->post("/api/gestion/conversations/$id/confirm", ['previewHash' => $ready['previewHash']], 409);
            $this->assertSame(['PREVIEW_CHANGED', $cached + 4000], [$stale['error'], $stale['preview']['result']['revenue']], 'la vente de la dernière minute est vue malgré le cache');
            $this->assertSame($before, $state(), 'refus : rien n\'est clôturé');

            // Confirmation : la clôture de la route existante ; l'annulation exacte est notée
            $done = $this->post("/api/gestion/conversations/$id/confirm", ['previewHash' => $stale['previewHash']], 201);
            $this->assertSame([$pid, count($stale['preview']['commandes'])], [$done['result']['periodId'], $done['result']['commandesClosed']]);
            $after = $state();
            $this->assertSame([true, null, $done['result']['closedAt']], [(bool) $after['period']['is_closed'], $after['period']['reopened_at'], substr((string) $after['period']['closed_at'], 0, 19)]);
            $this->assertSame([[$open, true], [$alreadyClosed, true]], array_map(fn (array $c) => [(int) $c['id'], (bool) $c['is_closed']], $after['commandes']));
            $this->assertSame([6, 6, null], array_column($after['products'], 'freeze_quantity'), 'la clôture note la quantité figée des articles de la commande qu\'elle ferme');
            $this->assertSame([$before['collectionStats'] + 1, $before['commandeStats'] + 1, $logged + 1], [$after['collectionStats'], $after['commandeStats'], $journal()]);
            $this->assertTrue($calendar->isClosed($this->em()->find(\App\Entity\Collections::class, $pid)));
            $this->request('POST', "/api/collections/$pid/commandes", ['budget' => 1, 'date' => $day->format('Y-m-d') . ' 11:00:00', 'name' => 'Refusée', 'fournisseur' => ['id' => $created['supplier']]]);
            $this->assertSame([409, 'PERIOD_CLOSED'], [$this->client->getResponse()->getStatusCode(), $this->body()['error']], 'après la clôture, aucune écriture datée dans la période');
            $this->assertTrue($this->get("/api/periodes/$pid/resultat")['frozen']);
            $this->assertSame(1, $state()['periodResults'], 'le résultat est figé à sa première lecture');
            $confirmed = $this->get("/api/gestion/conversations/$id");
            $this->assertSame(['confirmed', []], [$confirmed['state'], $confirmed['undo']['blockers']]);

            // Refus d'annulation, un par cause : la période a changé ; une commande fermée par la clôture a été rouverte, ou a reçu du stock
            $blockers = fn (): array => array_column($this->post("/api/gestion/conversations/$id/undo", [], 409)['reasons'], 'code');
            $conn()->executeStatement("UPDATE collections SET closed_at = closed_at + INTERVAL '1 minute' WHERE id = ?", [$pid]);
            $this->assertSame(['PERIOD_CHANGED'], $blockers(), 'clôturée autrement depuis');
            $conn()->executeStatement("UPDATE collections SET closed_at = closed_at - INTERVAL '1 minute', reopened_at = NOW() WHERE id = ?", [$pid]);
            $this->assertSame(['PERIOD_CHANGED'], $blockers(), 'rouverte depuis');
            $conn()->executeStatement('UPDATE collections SET reopened_at = NULL WHERE id = ?', [$pid]);
            $conn()->executeStatement('UPDATE commande SET is_closed = false WHERE id = ?', [$open]);
            $this->assertSame(['ORDER_CHANGED'], $blockers(), 'commande rouverte depuis');
            $conn()->executeStatement('UPDATE commande SET is_closed = true WHERE id = ?', [$open]);
            $lineId = (int) $after['lines'][0]['id'];
            $this->request('POST', "/api/commandes/$open/lignes/$lineId/reception", ['quantity' => 1]);
            $received = in_array($this->client->getResponse()->getStatusCode(), [200, 201], true);
            if (!$received) { // la réception par la route est refusée sur une commande fermée : le stock reçu est alors posé en base, comme le ferait un autre chemin
                $conn()->executeStatement('UPDATE commande_line SET quantity_received = quantity_received + 1 WHERE id = ?', [$lineId]);
            }
            $this->assertSame(['ORDER_CHANGED'], $blockers(), 'réception de stock depuis');
            $blocked = $this->post("/api/gestion/conversations/$id/undo", [], 409);
            $this->assertSame(['UNDO_BLOCKED', $open], [$blocked['error'], $blocked['reasons'][0]['commandeId']]);
            if ($received) {
                $this->request('POST', "/api/commandes/$open/lignes/$lineId/reception/annuler", ['quantity' => 1]);
                $this->assertContains($this->client->getResponse()->getStatusCode(), [200, 201], substr((string) $this->client->getResponse()->getContent(), 0, 300));
            } else {
                $conn()->executeStatement('UPDATE commande_line SET quantity_received = quantity_received - 1 WHERE id = ?', [$lineId]);
            }
            $this->endOfWrite();
            $this->assertSame([], $this->get("/api/gestion/conversations/$id")['undo']['blockers']);

            // Annulation exacte : l'état d'avant, table par table (période, commandes, quantités figées, chiffres figés), et rien d'autre
            $undone = $this->post("/api/gestion/conversations/$id/undo", [], 200);
            $this->assertSame(['restored' => ['periodId' => $pid, 'commandesReopened' => $done['result']['commandesClosed']]], array_diff_key($undone, ['thread' => 1]) === [] ? [] : ['restored' => $undone['undone']['restored']]);
            $restored = $state();
            $this->assertSame($before['period'], $restored['period'], 'la période retrouve ses dates de clôture et de réouverture');
            $this->assertSame($before['commandes'], $restored['commandes'], 'seule la commande fermée par la clôture est rouverte ; celle fermée avant le reste');
            $this->assertSame($before['products'], $restored['products'], 'quantités figées d\'avant (vide et 7), stock inchangé');
            $this->assertSame($before['lines'], $restored['lines']);
            $this->assertSame([$before['collectionStats'], $before['commandeStats'], 0, $logged + 2], [$restored['collectionStats'], $restored['commandeStats'], $restored['periodResults'], $journal()], 'chiffres figés créés par la clôture retirés ; résultat figé depuis retiré ; au journal');
            $this->assertFalse($calendar->isClosed($this->em()->find(\App\Entity\Collections::class, $pid)));
            $this->assertSame(['ready', null], [$undone['thread']['state'], $undone['thread']['undo']]);
            $this->assertSame('ALREADY_UNDONE', $this->post("/api/gestion/conversations/$id/undo", [], 409)['error']);
            // les écritures datées dans la période sont de nouveau acceptées
            $created['commandes'][] = (int) $this->post("/api/collections/$pid/commandes", ['budget' => 1, 'date' => $day->format('Y-m-d') . ' 11:00:00', 'name' => "Après $tag", 'fournisseur' => ['id' => $created['supplier']]], 201)['id'];
            $this->post("/api/gestion/conversations/$id/abandon", [], 200);
        } finally {
            $c = $conn();
            $ids = fn (array $v) => implode(',', array_map('intval', $v)) ?: '0';
            $c->executeStatement('DELETE FROM order_items WHERE order_associated_id IN (' . $ids($created['orders']) . ')');
            $c->executeStatement('DELETE FROM "order" WHERE id IN (' . $ids($created['orders']) . ')');
            $c->executeStatement('DELETE FROM commande_statistiques WHERE commande_id IN (' . $ids($created['commandes']) . ')');
            $c->executeStatement('DELETE FROM commande_line WHERE commande_id IN (' . $ids($created['commandes']) . ')');
            $c->executeStatement('UPDATE product SET commande_id = NULL WHERE id IN (' . $ids($created['products']) . ')');
            $c->executeStatement('DELETE FROM commande WHERE id IN (' . $ids($created['commandes']) . ')');
            $c->executeStatement('DELETE FROM product_variant WHERE id IN (' . $ids($created['variants']) . ')');
            $c->executeStatement('DELETE FROM product WHERE id IN (' . $ids($created['products']) . ')');
            if ($created['supplier'] !== null) {
                $c->executeStatement('DELETE FROM fournisseur WHERE id = ?', [$created['supplier']]);
            }
            $c->executeStatement('DELETE FROM period_result WHERE collection_id = ?', [$pid]);
            $c->executeStatement('UPDATE collections SET is_closed = ?, closed_at = ?, reopened_at = ? WHERE id = ?', [$original['is_closed'] ? 't' : 'f', $original['closed_at'], $original['reopened_at'], $pid]);
            $this->endOfWrite();
        }
    }

    public function testANegativeBalanceIsOnlyReadFromAnExplicitSign(): void
    {
        $say = fn (string $value) => FakeLandingAiClient::$queue[] = $this->reading(['reply' => 'Noté.', 'answers' => [['questionId' => 'amount', 'optionIndex' => -1, 'value' => $value, 'name' => '']]]);
        $thread = $this->post('/api/gestion/conversations', ['action' => 'cash.balance.create'], 201);
        $id = (int) $thread['id'];
        $send = function (string $sentence, string $modelValue) use ($id, $say): array {
            $say($modelValue);
            $out = $this->post("/api/gestion/conversations/$id/messages", ['text' => $sentence], 200);

            return [array_column($out['applied'], 'value', 'questionId'), array_column($out['ignored'], 'reason', 'questionId'), $out['thread']['questions'][1]['answer']];
        };
        // Les trois formes d'un signe explicite, juste devant le nombre
        $this->assertSame([['amount' => -25000], [], -25000], $send('Le solde est de -250 ce matin', '-250'));
        $this->assertSame([['amount' => -25050], [], -25050], $send('Le solde est de −250,50', '−250.50'), 'signe moins typographique');
        $this->assertSame([['amount' => -30000], [], -30000], $send('Je suis à moins 300 sur le compte', '-300'));
        // Aucun mot n'est interprété : « découvert de 250 » ne donne pas -250
        $this->assertSame([[], ['amount' => 'VALUE_NOT_IN_SENTENCE'], -30000], $send('J\'ai un découvert de 250', '-250'));
        $this->assertSame([['amount' => 25000], [], 25000], $send('J\'ai un découvert de 250', '250'), 'sans signe explicite, le montant lu est positif');
        // Le signe ne se perd pas non plus : « -250 » ne devient pas 250 ; le tiret d'une date n'est pas un signe ; « moins de » non plus
        $this->assertSame([[], ['amount' => 'VALUE_NOT_IN_SENTENCE'], 25000], $send('Le solde est de -250', '250'));
        $this->assertSame([[], ['amount' => 'VALUE_NOT_IN_SENTENCE'], 25000], $send('Au 2026-11-13 il reste 40', '-11'));
        $this->assertSame([[], ['amount' => 'VALUE_NOT_IN_SENTENCE'], 25000], $send('Il reste moins de 250', '-250'));
        $this->assertStringContainsString('juste devant le nombre', FakeLandingAiClient::$requests[0]['system']);
        // Une question de montant sans signe (note de frais) ne lit jamais un négatif
        $expense = $this->post('/api/gestion/conversations', ['action' => 'expense.create'], 201);
        FakeLandingAiClient::$queue[] = $this->reading(['reply' => '', 'answers' => [['questionId' => 'amount', 'optionIndex' => -1, 'value' => '-250', 'name' => '']]]);
        $out = $this->post('/api/gestion/conversations/' . $expense['id'] . '/messages', ['text' => 'Une note de -250'], 200);
        $this->assertSame([[], [['questionId' => 'amount', 'reason' => 'VALUE_NOT_IN_SENTENCE']]], [$out['applied'], $out['ignored']]);
        $this->post("/api/gestion/conversations/$id/abandon", [], 200);
        $this->post('/api/gestion/conversations/' . $expense['id'] . '/abandon', [], 200);
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
        $this->assertSame(['expense.create', 'supplier.create', 'shipping.create', 'budget.put', 'cash.balance.create', 'cash.movement.create', 'trip.create', 'fiscal_year.set', 'periods.calendar.set', 'product.cost.set', 'period.close'], ActionCatalog::KEYS);
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
                    foreach ($question['options'] as $option) {
                        $this->assertDoesNotMatchRegularExpression('/^(conversation|cash|budget)\./', (string) $option['label'], "$key, {$question['id']} ($locale) : libellé d'option");
                    }
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
