<?php

namespace App\Tests\Functional\Gestion;

use App\Entity\Fournisseur;
use App\Entity\TypeFournisseur;
use App\Entity\User;
use App\EventSubscriber\CacheInvalidationSubscriber;
use App\Gestion\Message\ReadGestionDocumentMessage;
use App\Gestion\MessageHandler\ReadGestionDocumentHandler;
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
 * Facture par conversation (lot IA-FACT-1, 13/11/2026, `docs/conversation-gestion.md`) : fil gardé par le serveur, un seul fil actif par
 * document, attente de la lecture, résumé écrit par le serveur, questions calculées à partir des trous connus, réponses par bouton sans
 * aucun appel d'IA ni unité, aperçu et empreinte, confirmation vers une nouvelle commande, annulation d'un bloc (dans le fil et hors du
 * fil) avec chacun de ses refus. Règle du propriétaire : aucune écriture préparée sans annulation possible.
 */
class ConversationApiTest extends WebTestCase
{
    use CacheTestEntityFactory;

    private KernelBrowser $client;
    private string $token = '';
    private string $email = '';
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
        $this->em()->getConnection()->executeStatement("UPDATE gestion_document SET status = 'failed' WHERE status = 'reading'"); // base partagée : une lecture à la fois par site
    }

    /** Base de test partagée : les unités d'IA consommées ici sont rendues, pour ne pas vider le forfait du mois sous les tests suivants */
    protected function tearDown(): void
    {
        $this->em()->getConnection()->executeStatement('DELETE FROM ai_usage WHERE id > ?', [$this->usageMark]);
        parent::tearDown();
    }

    public function testAnInvoiceGoesFromTheAttachmentToTheOrderThenIsUndoneAsAWhole(): void
    {
        $tag = $this->tag;
        $conn = $this->em()->getConnection();
        $today = static::getContainer()->get(PeriodCalendar::class)->today()->format('Y-m-d');
        $conn->executeStatement("INSERT INTO product (id, name, price, isbestseller, quantity, created_at, slug, product_type, code, purchase_price) VALUES (nextval('product_id_seq'), :n, 2500, false, 5, NOW(), :s, 'standard', :c, 900)", ['n' => "Tuque fil $tag", 's' => "tuque-fil-$tag", 'c' => "TF-$tag"]);
        $tuque = (int) $conn->fetchOne('SELECT id FROM product WHERE slug = ?', ["tuque-fil-$tag"]);
        $this->endOfWrite();
        $usage = fn (): int => (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM ai_usage');

        // Le fil s'ouvre avec la pièce jointe : même chemin que l'envoi d'un document, lecture en attente dans le fil
        [$status, $thread] = $this->multipart('/api/gestion/conversations', ['text' => 'Voici la facture du jour', 'locale' => 'fr'], ['files' => [$this->image('facture.png')]]);
        $this->assertSame([201, 'reading', 'invoice', 'reading', [], null, null, null], [$status, $thread['state'], $thread['kind'], $thread['document']['status'], $thread['questions'], $thread['preview'], $thread['previewHash'], $thread['summary']], json_encode($thread));
        $id = (int) $thread['id'];
        $documentId = (int) $thread['document']['id'];
        $this->assertSame([['user', 'document'], ['user', 'text']], array_map(fn (array $m) => [$m['role'], $m['kind']], $thread['messages']));
        $this->assertSame('Voici la facture du jour', $thread['messages'][1]['content']['text']);
        $this->assertArrayHasKey('remaining', $thread['credits']);
        // Un seul fil actif par document : le même document rend le même fil
        $this->request('POST', '/api/gestion/conversations', ['documentId' => $documentId]);
        $this->assertSame([200, $id], [$this->client->getResponse()->getStatusCode(), $this->body()['id']]);
        $this->assertSame(1, (int) $conn->fetchOne('SELECT COUNT(*) FROM gestion_conversation WHERE document_id = ?', [$documentId]));
        // Pendant la lecture, on ne répond pas et on ne confirme pas
        $this->request('POST', "/api/gestion/conversations/$id/answers", ['answers' => [['questionId' => 'supplier', 'value' => ['create' => ['name' => 'X']]]]]);
        $this->assertSame([409, 'CONVERSATION_NOT_OPEN'], [$this->client->getResponse()->getStatusCode(), $this->body()['code']]);

        // La lecture finit : résumé écrit par le serveur, questions calculées à partir de ce qui manque
        FakeLandingAiClient::$queue[] = $this->reading([
            'supplierName' => "Maison Inconnue $tag", 'invoiceNumber' => "FI-$tag", 'invoiceDate' => $today, 'currency' => 'CAD', 'amountsMode' => 'ht',
            'lines' => [
                ['baseLabel' => 'Tuque fil', 'supplierCode' => "TF-$tag", 'quantity' => 3, 'unitCost' => 1000, 'total' => 3000],
                ['baseLabel' => "Écharpe mystère $tag", 'quantity' => 2, 'unitCost' => 1500, 'total' => 3000],
            ],
            'taxes' => [['label' => 'TPS', 'rate' => 5, 'amount' => 400]], 'shipping' => ['label' => 'Livraison', 'amount' => 2000], 'subtotal' => 6000, 'taxTotal' => 400, 'total' => 8400,
            'confidence' => ['overall' => 0.9, 'number' => 0.9, 'date' => 0.9, 'lines' => 0.9, 'totals' => 0.9], 'notes' => [],
        ]);
        $this->runWorker($documentId);
        $this->assertCount(1, FakeLandingAiClient::$requests, 'la lecture du document : le seul appel d\'IA de tout le parcours');
        $unitsAfterReading = $usage();
        $thread = $this->get("/api/gestion/conversations/$id");
        $this->assertSame(['questions', 'extracted', 2], [$thread['state'], $thread['document']['status'], $thread['openQuestions']], json_encode($thread['questions']));
        $this->assertSame(["Maison Inconnue $tag", "FI-$tag", $today, 2, 2], [$thread['summary']['supplier'], $thread['summary']['number'], $thread['summary']['date'], $thread['summary']['lines'], $thread['summary']['openQuestions']]);
        $this->assertStringContainsString("Maison Inconnue $tag", $thread['summary']['text']);
        $this->assertStringContainsString('2 réponse(s)', $thread['summary']['text']);
        $questions = array_column($thread['questions'], null, 'id');
        $this->assertSame(['supplier', 'line:n2'], array_keys($questions), 'le fournisseur inconnu et la ligne sans article reconnu ; la tuque, reconnue par son code, ne pose pas de question');
        $this->assertSame(['id', 'kind', 'path', 'title', 'text', 'required', 'input', 'options', 'answer'], array_slice(array_keys($questions['supplier']), 0, 9));
        $this->assertSame(['supplier', 'supplier', true, ['type' => 'choice'], null], [$questions['supplier']['kind'], $questions['supplier']['path'], $questions['supplier']['required'], $questions['supplier']['input'], $questions['supplier']['answer']]);
        $this->assertSame(['create' => ['name' => "Maison Inconnue $tag"]], end($questions['supplier']['options'])['value']);
        $this->assertSame(['line.product', 'lines[2]', [2]], [$questions['line:n2']['kind'], $questions['line:n2']['path'], $questions['line:n2']['lines']]);
        $this->assertStringContainsString("Écharpe mystère $tag", $questions['line:n2']['text']);
        $this->assertNull($thread['previewHash'], 'pas d\'empreinte tant qu\'il manque une réponse');
        $this->assertSame(['existing', 'unresolved'], array_column($thread['preview']['lines'], 'kind'));
        $this->assertContains(['path' => 'lines[2]', 'code' => 'LINE_UNRESOLVED'], $thread['preview']['issues']);
        $this->request('POST', "/api/gestion/conversations/$id/confirm", ['previewHash' => 'x']);
        $this->assertSame([409, 'CONVERSATION_NOT_READY', 2], [$this->client->getResponse()->getStatusCode(), $this->body()['code'], $this->body()['openQuestions']]);

        // Réponses par bouton : contrôlées comme la confirmation, gratuites, sans aucun appel d'IA
        $refused = [
            [[['questionId' => 'inconnue', 'value' => 1]], 'answers[0].questionId', 'UNKNOWN_QUESTION'],
            [[['questionId' => 'supplier', 'value' => ['id' => 2147483000]]], 'answers[0].value', 'FOURNISSEUR_NOT_FOUND'],
            [[['questionId' => 'line:n2', 'value' => ['productId' => 2147483000]]], 'answers[0].value', 'PRODUCT_NOT_FOUND'],
            [[['questionId' => 'line:n2', 'value' => ['create' => ['name' => 'X', 'coefficient' => 0]]]], 'answers[0].value', 'MUST_BE_POSITIVE'],
            [[], 'answers', 'REQUIRED'],
        ];
        foreach ($refused as [$answers, $field, $code]) {
            $this->request('POST', "/api/gestion/conversations/$id/answers", ['answers' => $answers]);
            $this->assertSame([422, 'VALIDATION_FAILED', $code], [$this->client->getResponse()->getStatusCode(), $this->body()['code'], $this->body()['fields'][$field] ?? null], json_encode($answers));
        }
        $thread = $this->post("/api/gestion/conversations/$id/answers", ['answers' => [['questionId' => 'supplier', 'value' => ['create' => ['name' => "Maison Inconnue $tag"]]]]], 200);
        $this->assertSame(['questions', 1, ['create' => ['name' => "Maison Inconnue $tag"]]], [$thread['state'], $thread['openQuestions'], array_column($thread['questions'], 'answer', 'id')['supplier']]);
        // Toutes les réponses sont données, mais l'aperçu signale un refus (l'article choisi a disparu entre-temps) : le fil n'est pas prêt
        $conn->executeStatement("INSERT INTO product (id, name, price, isbestseller, quantity, created_at, slug, product_type) VALUES (nextval('product_id_seq'), :n, 1000, false, 0, NOW(), :s, 'standard')", ['n' => "Éphémère $tag", 's' => "ephemere-$tag"]);
        $gone = (int) $conn->fetchOne('SELECT id FROM product WHERE slug = ?', ["ephemere-$tag"]);
        $this->endOfWrite();
        $thread = $this->post("/api/gestion/conversations/$id/answers", ['answers' => [['questionId' => 'line:n2', 'value' => ['productId' => $gone]]]], 200);
        $this->assertSame(['ready', 0], [$thread['state'], $thread['openQuestions']]);
        $this->em()->getConnection()->executeStatement('DELETE FROM product WHERE id = ?', [$gone]);
        $this->endOfWrite();
        $thread = $this->get("/api/gestion/conversations/$id");
        $this->assertSame(['questions', 0, null, [['path' => 'lines[2].productId', 'code' => 'PRODUCT_NOT_FOUND']]], [$thread['state'], $thread['openQuestions'], $thread['previewHash'], $thread['preview']['issues']], 'aucune question ouverte, mais un refus de l\'aperçu : pas d\'empreinte');
        $this->request('POST', "/api/gestion/conversations/$id/confirm", ['previewHash' => 'x']);
        $this->assertSame([409, 'CONVERSATION_NOT_READY', 0, 'PRODUCT_NOT_FOUND'], [$this->client->getResponse()->getStatusCode(), $this->body()['code'], $this->body()['openQuestions'], $this->body()['issues'][0]['code']]);
        $thread = $this->post("/api/gestion/conversations/$id/answers", ['answers' => [['questionId' => 'line:n2', 'value' => ['create' => ['name' => "Écharpe $tag", 'coefficient' => 3]]]]], 200);
        $this->assertSame(['ready', 0, []], [$thread['state'], $thread['openQuestions'], $thread['preview']['issues']]);
        $this->assertSame(['existing', 'create'], array_column($thread['preview']['lines'], 'kind'));
        $this->assertSame([4500, 2500], [$thread['preview']['lines'][1]['plannedPrice'], $thread['preview']['lines'][0]['currentPrice']], 'coût 15,00 au coefficient 3 ; prix actuel de la tuque');
        $this->assertSame([6000, 2000], [$thread['preview']['totals']['lines'], $thread['preview']['totals']['shipping']]);
        $this->assertStringContainsString('vérifiez l\'aperçu', $thread['summary']['text']);
        $hash = $thread['previewHash'];
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $hash);
        $this->assertSame([1, $unitsAfterReading], [count(FakeLandingAiClient::$requests), $usage()], 'aucun appel d\'IA et aucune unité pour les questions et les réponses');
        $this->assertSame(['document', 'text', 'answers', 'answers', 'answers'], array_column($thread['messages'], 'kind'));

        // L'empreinte : si l'aperçu a changé depuis son affichage (ici le prix de la tuque), la confirmation est refusée avec le nouvel aperçu
        $counts = fn (): array => array_map(fn (string $t) => (int) $this->em()->getConnection()->fetchOne("SELECT COUNT(*) FROM $t"), ['commande' => 'commande', 'commande_line' => 'commande_line', 'product' => 'product', 'fournisseur' => 'fournisseur', 'frais_de_port' => 'frais_de_port']);
        $before = $counts();
        $conn->executeStatement('UPDATE product SET price = 2600 WHERE id = ?', [$tuque]);
        $this->endOfWrite();
        $this->request('POST', "/api/gestion/conversations/$id/confirm", ['previewHash' => $hash]);
        $changed = $this->body();
        $this->assertSame([409, 'PREVIEW_CHANGED', 2600], [$this->client->getResponse()->getStatusCode(), $changed['code'], $changed['preview']['lines'][0]['currentPrice']]);
        $this->assertNotSame($hash, $changed['previewHash']);
        $this->assertSame($before, $counts(), 'refus : rien n\'est créé');
        $this->request('POST', "/api/gestion/conversations/$id/confirm", []);
        $this->assertSame([422, 'REQUIRED'], [$this->client->getResponse()->getStatusCode(), $this->body()['fields']['previewHash']]);

        // Confirmation : celle d'une facture, vers une NOUVELLE commande, annulable
        $result = $this->post("/api/gestion/conversations/$id/confirm", ['previewHash' => $changed['previewHash']], 201);
        $commandeId = (int) $result['commande']['id'];
        $createdProduct = (int) $result['created'][0]['productId'];
        $this->assertSame([[1, $tuque]], array_map(fn (array $a) => [$a['lineN'], $a['productId']], $result['attached']));
        $this->assertSame([true, 'confirmed', $commandeId, null, null, 0], [$result['undo']['available'], $result['thread']['state'], $result['thread']['document']['commandeId'], $result['thread']['preview'], $result['thread']['previewHash'], $result['thread']['openQuestions']]);
        $this->assertSame([true, []], [$result['thread']['undo']['available'], $result['thread']['undo']['blockers']]);
        $this->assertSame('confirmed', end($result['thread']['messages'])['kind']);
        $supplierId = (int) $conn->fetchOne('SELECT fournisseur_id FROM commande WHERE id = ?', [$commandeId]);
        $after = $counts();
        $this->assertSame(['commande' => 1, 'commande_line' => 2, 'product' => 1, 'fournisseur' => 1, 'frais_de_port' => 1], array_combine(array_keys($after), array_map(fn ($a, $b) => $a - $b, $after, $before)));
        $document = $this->get("/api/gestion/documents/$documentId");
        $this->assertSame(['confirmed', true], [$document['status'], $document['undo']['available']]);
        $this->assertArrayNotHasKey('undo', $document['confirmed'], 'ce que la confirmation a créé reste interne');
        $this->assertNotNull($document['undo']['until']);

        // Ce qui a bougé depuis bloque l'annulation, raison par raison ; rien n'est retiré
        $blocked = function (string $sql, array $params, string $restore, array $restoreParams, string $reason) use ($documentId, $counts, $after): void {
            $conn = $this->em()->getConnection();
            $conn->executeStatement($sql, $params);
            $this->endOfWrite();
            $this->request('POST', "/api/gestion/documents/$documentId/annuler-confirmation");
            $body = $this->body();
            $this->assertSame([409, 'UNDO_BLOCKED'], [$this->client->getResponse()->getStatusCode(), $body['code'] ?? null], $reason . ' : ' . json_encode($body));
            $this->assertContains($reason, array_column($body['reasons'], 'code'), json_encode($body['reasons']));
            $this->assertSame($after, $counts(), "$reason : tout ou rien, rien n'est retiré");
            $this->em()->getConnection()->executeStatement($restore, $restoreParams);
            $this->endOfWrite();
        };
        $blocked('UPDATE commande_line SET quantity_received = 1 WHERE commande_id = ? AND product_id = ?', [$commandeId, $tuque], 'UPDATE commande_line SET quantity_received = 0 WHERE commande_id = ?', [$commandeId], 'LINE_RECEIVED');
        $blocked('UPDATE commande_line SET quantity_ordered = quantity_ordered + 1 WHERE commande_id = ? AND product_id = ?', [$commandeId, $tuque], 'UPDATE commande_line SET quantity_ordered = quantity_ordered - 1 WHERE commande_id = ? AND product_id = ?', [$commandeId, $tuque], 'COMMANDE_CHANGED');
        $blocked('UPDATE commande SET is_closed = true WHERE id = ?', [$commandeId], 'UPDATE commande SET is_closed = false WHERE id = ?', [$commandeId], 'COMMANDE_CLOSED');
        $blocked('UPDATE frais_de_port SET price = price + 100 WHERE commande_id = ?', [$commandeId], 'UPDATE frais_de_port SET price = price - 100 WHERE commande_id = ?', [$commandeId], 'SHIPPING_CHANGED');
        $blocked('UPDATE product SET is_web = true WHERE id = ?', [$createdProduct], 'UPDATE product SET is_web = false WHERE id = ?', [$createdProduct], 'PRODUCT_CHANGED');
        $blocked('UPDATE product SET price = price + 100 WHERE id = ?', [$createdProduct], 'UPDATE product SET price = price - 100 WHERE id = ?', [$createdProduct], 'PRODUCT_CHANGED');
        $this->assertSame([], $this->get("/api/gestion/conversations/$id")['undo']['blockers'], 'tout est remis : plus rien ne bloque');
        // Hors délai
        $conn = $this->em()->getConnection();
        $conn->executeStatement("UPDATE gestion_document SET confirmed_at = confirmed_at - INTERVAL '25 hours' WHERE id = ?", [$documentId]);
        $this->endOfWrite();
        $this->request('POST', "/api/gestion/conversations/$id/undo");
        $this->assertSame([409, 'UNDO_EXPIRED'], [$this->client->getResponse()->getStatusCode(), $this->body()['code']]);
        $this->assertFalse($this->get("/api/gestion/documents/$documentId")['undo']['available']);
        $this->em()->getConnection()->executeStatement("UPDATE gestion_document SET confirmed_at = confirmed_at + INTERVAL '25 hours' WHERE id = ?", [$documentId]);
        $this->endOfWrite();

        // Annulation d'un bloc, depuis le fil : exactement ce que la confirmation a créé disparaît, le reste est intact, le fil revient à l'aperçu
        $tuqueBefore = $this->em()->getConnection()->fetchAssociative('SELECT name, price, purchase_price, quantity, code FROM product WHERE id = ?', [$tuque]);
        $undone = $this->post("/api/gestion/conversations/$id/undo", [], 200);
        $this->assertSame(['commandeId' => $commandeId, 'removed' => ['lines' => 2, 'products' => 1, 'shipping' => true, 'supplier' => true], 'kept' => []], $undone['undone']);
        $this->assertSame($before, $counts(), 'tout ce que la confirmation avait créé est retiré');
        $conn = $this->em()->getConnection();
        $this->assertSame([0, 0, 0, 0, 0], [(int) $conn->fetchOne('SELECT COUNT(*) FROM commande WHERE id = ?', [$commandeId]), (int) $conn->fetchOne('SELECT COUNT(*) FROM commande_line WHERE commande_id = ?', [$commandeId]),
            (int) $conn->fetchOne('SELECT COUNT(*) FROM product WHERE id = ?', [$createdProduct]), (int) $conn->fetchOne('SELECT COUNT(*) FROM fournisseur WHERE id = ?', [$supplierId]), (int) $conn->fetchOne('SELECT COUNT(*) FROM frais_de_port WHERE commande_id = ?', [$commandeId])]);
        $this->assertSame($tuqueBefore, $conn->fetchAssociative('SELECT name, price, purchase_price, quantity, code FROM product WHERE id = ?', [$tuque]), 'l\'article déjà au catalogue n\'a pas bougé');
        $this->assertSame(['ready', 'extracted', null, null, 'undone'], [$undone['thread']['state'], $undone['thread']['document']['status'], $undone['thread']['document']['commandeId'], $undone['thread']['undo'], end($undone['thread']['messages'])['kind']]);
        $this->assertNotNull($undone['thread']['previewHash'], 'le fil revient à l\'aperçu, avec les mêmes réponses');
        $document = $this->get("/api/gestion/documents/$documentId");
        $this->assertSame(['extracted', null, null, false], [$document['status'], $document['confirmed'], $document['commandeId'], $document['undo']['available']]);
        $this->assertNotNull($document['expiresAt']);
        $this->assertSame(['undo', 'confirm'], array_slice($conn->fetchFirstColumn("SELECT action FROM content_audit_log WHERE resource = 'gestion-documents' AND resource_id = ? ORDER BY id DESC", [(string) $documentId]), 0, 2), 'confirmation et annulation journalisées');
        // Une seule fois
        $this->request('POST', "/api/gestion/conversations/$id/undo");
        $this->assertSame([409, 'ALREADY_UNDONE'], [$this->client->getResponse()->getStatusCode(), $this->body()['code']]);

        // On peut confirmer de nouveau, puis annuler hors du fil ; un fournisseur créé qui sert ailleurs est gardé, sans bloquer
        $again = $this->post("/api/gestion/conversations/$id/confirm", ['previewHash' => $undone['thread']['previewHash']], 201);
        $newSupplier = (int) $this->em()->getConnection()->fetchOne('SELECT fournisseur_id FROM commande WHERE id = ?', [$again['commande']['id']]);
        $otherId = (int) $this->em()->getConnection()->fetchOne("INSERT INTO commande (id, date, name, kind, budget, fournisseur_id) VALUES (nextval('commande_id_seq'), NOW(), ?, 'standard', 0, ?) RETURNING id", ["Autre achat $tag", $newSupplier]);
        $this->endOfWrite();
        $outside = $this->post("/api/gestion/documents/$documentId/annuler-confirmation", [], 200);
        $this->assertSame([false, ['supplier'], 'extracted'], [$outside['undone']['removed']['supplier'], $outside['undone']['kept'], $outside['document']['status']]);
        $this->assertSame(1, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM fournisseur WHERE id = ?', [$newSupplier]));
        $this->em()->getConnection()->executeStatement('DELETE FROM commande WHERE id = ?', [$otherId]);
        $this->em()->getConnection()->executeStatement('DELETE FROM fournisseur WHERE id = ?', [$newSupplier]);
        $this->em()->getConnection()->executeStatement('DELETE FROM product WHERE id = ?', [$tuque]);
        $this->endOfWrite();
    }

    public function testEveryKindOfQuestionComesFromAKnownGapAndAnAbandonedThreadCreatesNothing(): void
    {
        $tag = $this->tag;
        $calendar = static::getContainer()->get(PeriodCalendar::class);
        $today = $calendar->today();
        $calendar->ensurePeriods($today->modify('-400 days'));
        $supplier = $this->create(Fournisseur::class, ['name' => "Atelier Euro $tag", 'currency' => 'EUR'], fn (Fournisseur $f, EntityManagerInterface $em) => $f->setTypeFournisseur($em->find(TypeFournisseur::class, 1) ?? $this->create(TypeFournisseur::class, ['name' => 'Autre'])));
        $conn = $this->em()->getConnection();
        $conn->executeStatement("INSERT INTO product (id, name, price, isbestseller, quantity, created_at, slug, product_type, code) VALUES (nextval('product_id_seq'), :n, 5000, false, 5, NOW(), :s, 'standard', :c)", ['n' => "Sac questions $tag", 's' => "sac-q-$tag", 'c' => "SQ-$tag"]);
        $bag = (int) $conn->fetchOne('SELECT id FROM product WHERE slug = ?', ["sac-q-$tag"]);
        $this->endOfWrite();

        // Une facture pleine de trous : date illisible, prix dont on ne sait pas s'ils sont hors taxes, devise étrangère, coût illisible, total qui ne tombe pas juste
        $start = function (array $reading): array {
            [$status, $thread] = $this->multipart('/api/gestion/conversations', [], ['files' => [$this->image('f.png')]]);
            $this->assertSame(201, $status, json_encode($thread));
            FakeLandingAiClient::$queue[] = $this->reading($reading);
            $this->runWorker((int) $thread['document']['id']);

            return [(int) $thread['id'], (int) $thread['document']['id']];
        };
        $reading = ['supplierName' => "Atelier Euro $tag", 'invoiceNumber' => "FE-$tag", 'invoiceDate' => '', 'currency' => 'EUR', 'amountsMode' => 'unknown',
            'lines' => [['baseLabel' => 'Sac questions', 'supplierCode' => "SQ-$tag", 'quantity' => 2, 'unitCost' => 2300, 'total' => 4600], ['baseLabel' => 'Sac questions bis', 'supplierCode' => "SQ-$tag", 'quantity' => 1]],
            'taxes' => [['label' => 'TVA', 'rate' => 15, 'amount' => 600]], 'shipping' => null, 'subtotal' => 4000, 'taxTotal' => 600, 'total' => 4700,
            'confidence' => ['overall' => 0.6, 'number' => 0.9, 'date' => 0.1, 'lines' => 0.6, 'totals' => 0.6], 'notes' => []];
        [$id, $documentId] = $start($reading);
        $thread = $this->get("/api/gestion/conversations/$id?locale=en");
        $questions = array_column($thread['questions'], null, 'id');
        $this->assertSame('questions', $thread['state']);
        foreach (['invoice.date' => ['invoice.date', 'date'], 'amountsMode' => ['amountsMode', 'choice'], 'unitCost:2' => ['line.unitCost', 'money'], 'exchangeRate' => ['exchangeRate', 'number']] as $questionId => [$kind, $input]) {
            $this->assertArrayHasKey($questionId, $questions, json_encode(array_keys($questions)));
            $this->assertSame([$kind, $input, true, null], [$questions[$questionId]['kind'], $questions[$questionId]['input']['type'], $questions[$questionId]['required'], $questions[$questionId]['answer']], $questionId);
        }
        $this->assertSame([['id' => $supplier->getId()], true, ['id' => $supplier->getId()]], [$questions['supplier']['answer'], $questions['supplier']['proposed'], $questions['supplier']['options'][0]['value']], 'fournisseur reconnu : proposé comme réponse, il ne compte pas parmi les questions ouvertes et peut être changé');
        $this->assertSame(count(array_filter($thread['questions'], fn (array $q) => $q['answer'] === null)), $thread['openQuestions']);
        $this->assertSame(['Invoice date', 'EUR'], [$questions['invoice.date']['title'], $questions['unitCost:2']['input']['currency']]);
        $this->assertStringContainsString('1 EUR', $questions['exchangeRate']['text']);
        $this->assertSame(['ht', 'ttc'], array_column($questions['amountsMode']['options'], 'value'));
        $this->assertStringContainsString('more answer(s)', $thread['summary']['text']);

        // Les réponses changent ce que la confirmation recevra : taxes incluses → coûts ramenés hors taxes ; taux ; coût saisi ; date
        foreach ([['invoice.date', '2026-13-45', 'INVALID_DATE'], ['amountsMode', 'peut-être', 'INVALID_VALUE'], ['exchangeRate', 0, 'MUST_BE_POSITIVE'], ['unitCost:2', -5, 'INVALID_AMOUNT'], ['unitCost:2', '12', 'INVALID_AMOUNT']] as [$questionId, $value, $code]) {
            $this->request('POST', "/api/gestion/conversations/$id/answers", ['answers' => [['questionId' => $questionId, 'value' => $value]]]);
            $this->assertSame([422, $code], [$this->client->getResponse()->getStatusCode(), $this->body()['fields']['answers[0].value'] ?? null], "$questionId");
        }
        $old = $today->modify('-200 days')->format('Y-m-d');
        $thread = $this->post("/api/gestion/conversations/$id/answers", ['answers' => [['questionId' => 'amountsMode', 'value' => 'ttc'], ['questionId' => 'exchangeRate', 'value' => 1.5], ['questionId' => 'unitCost:2', 'value' => 1000], ['questionId' => 'invoice.date', 'value' => $old]]], 200);
        $this->assertSame([2006, 1000, 1.5, 3009], [$thread['preview']['lines'][0]['unitCost'], $thread['preview']['lines'][1]['unitCost'], $thread['preview']['exchangeRate'], $thread['preview']['lines'][0]['unitCostConverted']], 'taxes incluses : 23,00 lus, ramenés hors taxes au taux du document (600 de taxes sur 4700 − 600) = 20,06 ; un coût saisi n\'est pas reconverti');
        // La date donnée tombe dans une période fermée : une nouvelle question naît de la réponse
        $questions = array_column($thread['questions'], null, 'id');
        $this->assertSame(['questions', 'period', 'classificationDate', ['today', 'abandon']], [$thread['state'], $questions['period']['kind'], $questions['period']['path'], array_column($questions['period']['options'], 'value')]);
        $this->assertStringContainsString($old, $questions['period']['text']);
        // Retirer une réponse la rouvre
        $thread = $this->post("/api/gestion/conversations/$id/answers", ['answers' => [['questionId' => 'exchangeRate', 'value' => null]]], 200);
        $this->assertNull(array_column($thread['questions'], 'answer', 'id')['exchangeRate']);
        $thread = $this->post("/api/gestion/conversations/$id/answers", ['answers' => [['questionId' => 'exchangeRate', 'value' => 1.5], ['questionId' => 'period', 'value' => 'today']]], 200);
        $open = array_values(array_filter($thread['questions'], fn (array $q) => $q['answer'] === null));
        $this->assertSame(['difference'], array_column($open, 'id'), 'il ne reste que l\'écart sur le total, à reconnaître');
        $this->assertSame(['difference', [['accept']]], [$open[0]['kind'], [array_column($open[0]['options'], 'value')]]);
        $thread = $this->post("/api/gestion/conversations/$id/answers", ['answers' => [['questionId' => 'difference', 'value' => 'accept']]], 200);
        $this->assertSame(['ready', 0], [$thread['state'], $thread['openQuestions']]);
        $result = $this->post("/api/gestion/conversations/$id/confirm", ['previewHash' => $thread['previewHash']], 201);
        $commande = $this->em()->getConnection()->fetchAssociative('SELECT date, currency, exchange_rate, fournisseur_id FROM commande WHERE id = ?', [$result['commande']['id']]);
        $this->assertSame([$today->format('Y-m-d'), 'EUR', 1.5, $supplier->getId()], [substr((string) $commande['date'], 0, 10), $commande['currency'], (float) $commande['exchange_rate'], (int) $commande['fournisseur_id']], 'classée à aujourd\'hui, dans la devise du fournisseur, au taux donné');
        $this->assertSame([[$bag, 3, 1671]], array_map(fn (array $l) => [(int) $l['product_id'], (int) $l['quantity_ordered'], (int) $l['unit_cost_cents']],
            $this->em()->getConnection()->fetchAllAssociative('SELECT product_id, quantity_ordered, unit_cost_cents FROM commande_line WHERE commande_id = ?', [$result['commande']['id']])), 'deux lignes du même article : une ligne d\'achat, coût moyen pondéré');

        // Doublon : même fournisseur, même numéro qu'une facture confirmée ; abandonner ferme le fil sans rien créer
        $reading['invoiceDate'] = $today->format('Y-m-d');
        $reading['amountsMode'] = 'ht';
        $reading['lines'] = [['baseLabel' => 'Sac questions', 'supplierCode' => "SQ-$tag", 'quantity' => 1, 'unitCost' => 2000, 'total' => 2000]];
        $reading = ['subtotal' => 2000, 'taxTotal' => 300, 'total' => 2300] + $reading;
        [$id2, $documentId2] = $start($reading);
        // ce fournisseur a maintenant une commande ouverte (celle de la première facture) : la cible est demandée ; ici, une nouvelle commande
        $thread = $this->post("/api/gestion/conversations/$id2/answers", ['answers' => [['questionId' => 'target', 'value' => 'new'], ['questionId' => 'exchangeRate', 'value' => 1.4]]], 200);
        $questions = array_column($thread['questions'], null, 'id');
        $this->assertSame(['questions', 'duplicate', 'force', $documentId, ['force', 'abandon']], [$thread['state'], $questions['duplicate']['kind'], $questions['duplicate']['path'], $questions['duplicate']['duplicate']['documentId'], array_column($questions['duplicate']['options'], 'value')]);
        $commandes = (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM commande');
        $thread = $this->post("/api/gestion/conversations/$id2/answers", ['answers' => [['questionId' => 'duplicate', 'value' => 'abandon']]], 200);
        $this->assertSame(['abandoned', null, 'abandoned'], [$thread['state'], $thread['previewHash'], end($thread['messages'])['kind']]);
        $this->assertSame($commandes, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM commande'));
        foreach ([["/api/gestion/conversations/$id2/answers", ['answers' => [['questionId' => 'duplicate', 'value' => 'force']]]], ["/api/gestion/conversations/$id2/confirm", ['previewHash' => 'x']]] as [$route, $body]) {
            $this->request('POST', $route, $body);
            $this->assertSame([409, 'CONVERSATION_NOT_OPEN', 'abandoned'], [$this->client->getResponse()->getStatusCode(), $this->body()['code'], $this->body()['state']], $route);
        }
        // Le document reste utilisable : un nouveau fil s'ouvre pour lui (l'abandonné n'est plus actif), et « confirmer quand même » passe
        $this->request('POST', '/api/gestion/conversations', ['documentId' => $documentId2, 'text' => 'Finalement oui']);
        $new = $this->body();
        $this->assertSame(201, $this->client->getResponse()->getStatusCode());
        $this->assertNotSame($id2, $new['id']);
        $thread = $this->post("/api/gestion/conversations/{$new['id']}/answers", ['answers' => [['questionId' => 'target', 'value' => 'new'], ['questionId' => 'exchangeRate', 'value' => 1.4], ['questionId' => 'duplicate', 'value' => 'force']]], 200);
        $this->assertSame('ready', $thread['state']);
        $forced = $this->post("/api/gestion/conversations/{$new['id']}/confirm", ['previewHash' => $thread['previewHash']], 201);
        $this->assertNotSame($result['commande']['id'], $forced['commande']['id']);

        // Liste des fils
        $this->request('GET', '/api/gestion/conversations?page=1&limit=2');
        $list = $this->body();
        $headers = $this->client->getResponse()->headers;
        $this->assertSame(['1', '2', (int) $new['id'], 'confirmed', $id2, 'abandoned'], [$headers->get('X-Page'), $headers->get('X-Limit'), $list[0]['id'], $list[0]['state'], $list[1]['id'], $list[1]['state']]);
        $this->assertGreaterThanOrEqual(3, (int) $headers->get('X-Total-Count'));

        // Nettoyage : les deux confirmations sont annulées par la route hors conversation, puis les objets du test retirés
        foreach ([$documentId2, $documentId] as $doc) {
            $undone = $this->post("/api/gestion/documents/$doc/annuler-confirmation", [], 200)['undone'];
            $this->assertSame([false, []], [$undone['removed']['supplier'], $undone['kept']], 'un fournisseur qui existait avant la confirmation n\'est ni retiré ni compté');
        }
        $conn = $this->em()->getConnection();
        $this->assertSame(1, (int) $conn->fetchOne('SELECT COUNT(*) FROM fournisseur WHERE id = ?', [$supplier->getId()]), 'le fournisseur existant est toujours là après les deux annulations');
        $conn->executeStatement('DELETE FROM product WHERE id = ?', [$bag]);
        $conn->executeStatement('DELETE FROM fournisseur WHERE id = ?', [$supplier->getId()]);
        $this->endOfWrite();
    }

    /**
     * Lot IA-FACT-2-A : la facture s'ajoute à une commande ouverte du même fournisseur. Un article n'a qu'une ligne par commande : la ligne
     * existante grossit (quantités additionnées, coût moyen pondéré). L'ajout s'annule d'un bloc, dans l'ordre inverse des ajouts.
     */
    public function testAnInvoiceIsAddedToAnOpenOrderOfTheSameSupplierAndUndoneInReverseOrder(): void
    {
        $tag = $this->tag;
        $calendar = static::getContainer()->get(PeriodCalendar::class);
        $today = $calendar->today();
        $calendar->ensurePeriods($today->modify('-400 days'));
        $supplier = $this->create(Fournisseur::class, ['name' => "Atelier Ajout $tag", 'currency' => 'EUR'], fn (Fournisseur $f, EntityManagerInterface $em) => $f->setTypeFournisseur($em->find(TypeFournisseur::class, 1) ?? $this->create(TypeFournisseur::class, ['name' => 'Autre'])));
        $other = $this->create(Fournisseur::class, ['name' => "Autre maison $tag"], fn (Fournisseur $f, EntityManagerInterface $em) => $f->setTypeFournisseur($em->find(TypeFournisseur::class, 1)));
        $conn = $this->em()->getConnection();
        $conn->executeStatement("INSERT INTO product (id, name, price, isbestseller, quantity, created_at, slug, product_type, code) VALUES (nextval('product_id_seq'), :n, 5000, false, 5, NOW(), :s, 'standard', :c)", ['n' => "Veste ajout $tag", 's' => "veste-a-$tag", 'c' => "VA-$tag"]);
        $jacket = (int) $conn->fetchOne('SELECT id FROM product WHERE slug = ?', ["veste-a-$tag"]);
        $otherCommande = (int) $conn->fetchOne("INSERT INTO commande (id, date, name, kind, budget, fournisseur_id) VALUES (nextval('commande_id_seq'), NOW(), ?, 'standard', 0, ?) RETURNING id", ["Commande d'un autre $tag", $other->getId()]);
        $this->endOfWrite();
        $reading = fn (string $number, string $date, array $lines, int $shipping) => ['supplierName' => "Atelier Ajout $tag", 'invoiceNumber' => $number, 'invoiceDate' => $date, 'currency' => 'EUR', 'amountsMode' => 'ht',
            'lines' => $lines, 'taxes' => [], 'shipping' => ['label' => 'Port', 'amount' => $shipping], 'subtotal' => null, 'taxTotal' => null, 'total' => null,
            'confidence' => ['overall' => 0.9, 'number' => 0.9, 'date' => 0.9, 'lines' => 0.9, 'totals' => 0.9], 'notes' => []];

        // Première facture : aucune commande ouverte chez ce fournisseur, donc aucune question de cible ; nouvelle commande, comme avant
        [$id1, $doc1] = $this->startThread($reading("A1-$tag", $today->format('Y-m-d'), [['baseLabel' => 'Veste ajout', 'supplierCode' => "VA-$tag", 'quantity' => 3, 'unitCost' => 1000, 'total' => 3000]], 800));
        $thread = $this->post("/api/gestion/conversations/$id1/answers", ['answers' => [['questionId' => 'exchangeRate', 'value' => 1.5]]], 200);
        $this->assertSame(['ready', ['supplier', 'exchangeRate'], null], [$thread['state'], array_column($thread['questions'], 'id'), $thread['preview']['target']], 'pas de commande ouverte : pas de question de cible');
        $first = $this->post("/api/gestion/conversations/$id1/confirm", ['previewHash' => $thread['previewHash']], 201);
        $commandeId = (int) $first['commande']['id'];
        $lineId = (int) $this->em()->getConnection()->fetchOne('SELECT id FROM commande_line WHERE commande_id = ? AND product_id = ?', [$commandeId, $jacket]);
        $this->em()->getConnection()->executeStatement("UPDATE gestion_document SET confirmed_at = confirmed_at - INTERVAL '1 minute' WHERE id = ?", [$doc1]);
        $this->endOfWrite();
        $state = fn (): array => [
            $this->em()->getConnection()->fetchAllAssociative('SELECT product_id, quantity_ordered, quantity_received, unit_cost_cents FROM commande_line WHERE commande_id = ? ORDER BY id', [$commandeId]),
            (int) $this->em()->getConnection()->fetchOne('SELECT budget FROM commande WHERE id = ?', [$commandeId]), (int) $this->em()->getConnection()->fetchOne('SELECT price FROM frais_de_port WHERE commande_id = ?', [$commandeId]),
            (int) $this->em()->getConnection()->fetchOne('SELECT document_id FROM commande WHERE id = ?', [$commandeId]),
        ];
        $afterFirst = $state();
        $this->assertSame([[['product_id' => $jacket, 'quantity_ordered' => 3, 'quantity_received' => 0, 'unit_cost_cents' => 1000]], 3800, 800, $doc1], $afterFirst);

        // Seconde facture, datée dans une période fermée : la cible est demandée juste après le fournisseur
        $old = $today->modify('-200 days')->format('Y-m-d');
        [$id2, $doc2] = $this->startThread($reading("A2-$tag", $old, [['baseLabel' => 'Veste ajout', 'supplierCode' => "VA-$tag", 'quantity' => 2, 'unitCost' => 1500, 'total' => 3000], ['baseLabel' => 'Poncho zébré réversible', 'quantity' => 1, 'unitCost' => 2000, 'total' => 2000]], 1200));
        $thread = $this->get("/api/gestion/conversations/$id2");
        $this->assertSame(['supplier', 'target', 'line:n2', 'exchangeRate', 'period'], array_column($thread['questions'], 'id'), 'la cible juste après le fournisseur ; sans réponse, les questions d\'une nouvelle commande sont là');
        $target = $thread['questions'][1];
        $this->assertSame(['target', 'target', true, ['type' => 'choice'], null], [$target['kind'], $target['path'], $target['required'], $target['input'], $target['answer']]);
        $this->assertSame(['new', ['commandeId' => $commandeId]], array_column($target['options'], 'value'), 'la commande d\'un autre fournisseur n\'est pas proposée');
        $this->assertSame([$today->format('Y-m-d'), 3800, 'EUR', 1], [$target['options'][1]['date'], $target['options'][1]['budget'], $target['options'][1]['currency'], $target['options'][1]['linesCount']]);
        $this->assertStringContainsString("Atelier Ajout $tag", $target['text']);
        foreach ([['commandeId' => $otherCommande], ['commandeId' => 2147483000], 'ailleurs'] as $bad) {
            $this->request('POST', "/api/gestion/conversations/$id2/answers", ['answers' => [['questionId' => 'target', 'value' => $bad]]]);
            $this->assertSame([422, 'INVALID_VALUE'], [$this->client->getResponse()->getStatusCode(), $this->body()['fields']['answers[0].value'] ?? null], json_encode($bad));
        }

        // Cible = la commande ouverte : plus de question de période ni de taux (ceux de la commande s'appliquent), le port est demandé
        $thread = $this->post("/api/gestion/conversations/$id2/answers", ['answers' => [['questionId' => 'target', 'value' => ['commandeId' => $commandeId]]]], 200);
        $this->assertSame(['supplier', 'target', 'line:n2', 'shipping'], array_column($thread['questions'], 'id'));
        $shipping = $thread['questions'][3];
        $this->assertSame(['shipping', ['add', 'skip'], ['current' => 800, 'added' => 1200, 'total' => 2000]], [$shipping['kind'], array_column($shipping['options'], 'value'), $shipping['shipping']]);
        $this->assertSame('Ajouter 12,00 EUR au frais de port actuel (8,00 EUR) → 20,00 EUR', $shipping['options'][0]['label']);
        $this->assertSame('Ne pas compter les 12,00 EUR de port de cette facture', $shipping['options'][1]['label']);
        $this->assertSame([1.5, 'EUR', $commandeId, 3800, 3800 + 3000 + 2000 + 1200], [$thread['preview']['exchangeRate'], $thread['preview']['invoiceCurrency'], $thread['preview']['target']['commandeId'], $thread['preview']['target']['budgetBefore'], $thread['preview']['target']['budgetAfter']]);
        $this->assertSame([['quantity' => 3, 'unitCost' => 1000], ['quantity' => 5, 'unitCost' => 1200]], [$thread['preview']['lines'][0]['lineBefore'], $thread['preview']['lines'][0]['lineAfter']], 'la ligne existante grossit : 3 à 10,00 + 2 à 15,00 = 5 à 12,00');
        $this->assertContains(['path' => 'shipping', 'code' => 'SHIPPING_COST_ALREADY_SET'], $thread['preview']['issues']);
        // Revenir à une nouvelle commande fait réapparaître ses questions ; changer de fournisseur retire la cible
        $back = $this->post("/api/gestion/conversations/$id2/answers", ['answers' => [['questionId' => 'target', 'value' => 'new']]], 200);
        $this->assertSame([['supplier', 'target', 'line:n2', 'exchangeRate', 'period'], null], [array_column($back['questions'], 'id'), $back['preview']['target']]);
        $changed = $this->post("/api/gestion/conversations/$id2/answers", ['answers' => [['questionId' => 'target', 'value' => ['commandeId' => $commandeId]], ['questionId' => 'supplier', 'value' => ['id' => $other->getId()]]]], 200);
        $ids = array_column($changed['questions'], 'id');
        $this->assertSame(['target', ['commandeId' => $otherCommande], null, false, false], [$changed['questions'][1]['id'], $changed['questions'][1]['options'][1]['value'], $changed['questions'][1]['answer'], in_array('shipping', $ids, true), $changed['questions'][0]['proposed']], 'autre fournisseur : la cible est recalculée, la réponse donnée pour l\'ancien est oubliée');
        $this->assertNull($changed['preview']['target']);
        // « Ne pas compter » : le port de la facture sort de l'aperçu et du budget ; « ajouter » l'y remet
        $skipped = $this->post("/api/gestion/conversations/$id2/answers", ['answers' => [['questionId' => 'supplier', 'value' => null], ['questionId' => 'shipping', 'value' => 'skip']]], 200);
        $this->assertSame([['current' => 800, 'added' => 0, 'total' => 800], 3800 + 3000 + 2000, 0, false], [$skipped['preview']['target']['shipping'], $skipped['preview']['target']['budgetAfter'], $skipped['preview']['totals']['shipping'],
            in_array(['path' => 'shipping', 'code' => 'SHIPPING_COST_ALREADY_SET'], $skipped['preview']['issues'], true)]);
        $thread = $this->post("/api/gestion/conversations/$id2/answers", ['answers' => [['questionId' => 'line:n2', 'value' => ['create' => ['name' => "Poncho $tag"]]], ['questionId' => 'shipping', 'value' => 'add']]], 200);
        $this->assertSame(['ready', ['commandeId' => $commandeId], 'add', []], [$thread['state'], array_column($thread['questions'], 'answer', 'id')['target'], array_column($thread['questions'], 'answer', 'id')['shipping'], $thread['preview']['issues']], 'le fournisseur d\'origine revient : la réponse de cible vaut de nouveau');
        $this->assertSame(['current' => 800, 'added' => 1200, 'total' => 2000], $thread['preview']['target']['shipping']);

        // Confirmation : la même commande ; la ligne grossit, une ligne naît pour l'article créé, budget et port augmentent
        // L'empreinte suit la commande cible : si elle a bougé depuis l'affichage (ici son budget), la confirmation est refusée
        $this->em()->getConnection()->executeStatement('UPDATE commande SET budget = budget + 100 WHERE id = ?', [$commandeId]);
        $this->endOfWrite();
        $this->request('POST', "/api/gestion/conversations/$id2/confirm", ['previewHash' => $thread['previewHash']]);
        $this->assertSame([409, 'PREVIEW_CHANGED', 3900], [$this->client->getResponse()->getStatusCode(), $this->body()['code'], $this->body()['preview']['target']['budgetBefore']]);
        $this->em()->getConnection()->executeStatement('UPDATE commande SET budget = budget - 100 WHERE id = ?', [$commandeId]);
        $this->endOfWrite();
        $commandes = (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM commande');
        $second = $this->post("/api/gestion/conversations/$id2/confirm", ['previewHash' => $thread['previewHash']], 201);
        $this->assertSame(1.5, json_decode((string) $this->em()->getConnection()->fetchOne('SELECT confirmed FROM gestion_document WHERE id = ?', [$doc2]), true)['invoice']['exchangeRate'], 'le taux noté est celui de la commande');
        $created = (int) $second['created'][0]['productId'];
        $this->assertSame([$commandeId, $commandes, $commandeId], [(int) $second['commande']['id'], (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM commande'), (int) $this->em()->getConnection()->fetchOne('SELECT commande_id FROM gestion_document WHERE id = ?', [$doc2])], 'aucune commande créée');
        $this->assertSame([[['product_id' => $jacket, 'quantity_ordered' => 5, 'quantity_received' => 0, 'unit_cost_cents' => 1200], ['product_id' => $created, 'quantity_ordered' => 1, 'quantity_received' => 0, 'unit_cost_cents' => 2000]], 10000, 2000, $doc1], $state(), 'le justificatif d\'origine de la commande reste le premier');
        $this->assertSame(substr((string) $this->em()->getConnection()->fetchOne('SELECT date FROM commande WHERE id = ?', [$commandeId]), 0, 10), $today->format('Y-m-d'), 'la date de la commande ne change pas');

        // Les factures de la commande, avec l'apport de chacune
        $documents = $this->get("/api/commandes/$commandeId/documents");
        $this->assertSame([[$doc1, "A1-$tag", false], [$doc2, "A2-$tag", true]], array_map(fn (array $d) => [$d['id'], $d['invoiceNumber'], $d['added']], $documents));
        $this->assertSame([['lineId' => $lineId, 'productId' => $jacket, 'variantId' => null, 'quantity' => 3, 'unitCost' => 1000]], $documents[0]['lines']);
        $this->assertSame([['lineId' => $lineId, 'productId' => $jacket, 'variantId' => null, 'quantity' => 2, 'unitCost' => 1500], [$created, 1, 2000]], [$documents[1]['lines'][0], [$documents[1]['lines'][1]['productId'], $documents[1]['lines'][1]['quantity'], $documents[1]['lines'][1]['unitCost']]]);
        $this->assertTrue($documents[1]['undo']['available']);

        // Ordre inverse : la première facture ne s'annule plus tant que la seconde est là ; le blocage la nomme et donne son document
        $this->request('POST', "/api/gestion/documents/$doc1/annuler-confirmation");
        $blocked = $this->body();
        $this->assertSame([409, 'UNDO_BLOCKED', ['LATER_DOCUMENT']], [$this->client->getResponse()->getStatusCode(), $blocked['code'], array_column($blocked['reasons'], 'code')], json_encode($blocked));
        $this->assertSame([$doc2, "A2-$tag"], [$blocked['reasons'][0]['documentId'], $blocked['reasons'][0]['invoiceNumber']]);
        $this->assertStringContainsString("n° A2-$tag", $blocked['reasons'][0]['message']);
        $this->assertSame('LATER_DOCUMENT', $this->get("/api/gestion/conversations/$id1")['undo']['blockers'][0]['code']);
        // L'ajout lui-même est bloqué si une ligne qu'il a touchée a été reçue ou modifiée depuis
        foreach ([['UPDATE commande_line SET quantity_received = 1 WHERE id = ?', 'UPDATE commande_line SET quantity_received = 0 WHERE id = ?', 'LINE_RECEIVED'],
            ['UPDATE commande_line SET unit_cost_cents = 1300 WHERE id = ?', 'UPDATE commande_line SET unit_cost_cents = 1200 WHERE id = ?', 'COMMANDE_CHANGED']] as [$sql, $restore, $reason]) {
            $this->em()->getConnection()->executeStatement($sql, [$lineId]);
            $this->endOfWrite();
            $this->request('POST', "/api/gestion/conversations/$id2/undo");
            $this->assertSame([409, [$reason]], [$this->client->getResponse()->getStatusCode(), array_column($this->body()['reasons'] ?? [], 'code')], $reason);
            $this->em()->getConnection()->executeStatement($restore, [$lineId]);
            $this->endOfWrite();
        }

        // Annulation de l'ajout : la commande revient exactement à son état d'après la première facture
        $undone = $this->post("/api/gestion/conversations/$id2/undo", [], 200);
        $this->assertSame(['commandeId' => $commandeId, 'mode' => 'add', 'removed' => ['lines' => 1, 'products' => 1, 'shipping' => false, 'supplier' => false], 'restored' => ['lines' => 1, 'shipping' => true, 'budget' => 6200], 'kept' => []], $undone['undone']);
        $this->assertSame($afterFirst, $state(), 'ligne ramenée à 3 à 10,00, ligne créée retirée, budget et port remis');
        $this->assertSame([0, 'extracted', 'ready'], [(int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM product WHERE id = ?', [$created]), $undone['thread']['document']['status'], $undone['thread']['state']]);
        $this->assertSame([[$doc1]], [array_column($this->get("/api/commandes/$commandeId/documents"), 'id')]);
        // Puis la première facture s'annule à son tour : la commande disparaît
        $this->post("/api/gestion/documents/$doc1/annuler-confirmation", [], 200);
        $this->assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM commande WHERE id = ?', [$commandeId]));

        // La même cible par l'écran de vérification : refus nommés, sans rien créer
        $again = $this->post("/api/gestion/conversations/$id1/confirm", ['previewHash' => $this->get("/api/gestion/conversations/$id1")['previewHash']], 201);
        $commandeId = (int) $again['commande']['id'];
        $body = ['supplier' => ['id' => $supplier->getId()], 'invoice' => ['number' => "A2-$tag", 'date' => $today->format('Y-m-d')], 'lines' => [['n' => 1, 'quantity' => 2, 'unitCost' => 1500, 'productId' => $jacket]], 'shipping' => ['amount' => 1200]];
        $linesBefore = (int) $this->em()->getConnection()->fetchOne('SELECT SUM(quantity_ordered) FROM commande_line WHERE commande_id = ?', [$commandeId]);
        foreach ([[['commandeId' => 2147483000], 404, 'COMMANDE_NOT_FOUND', null], [['commandeId' => $otherCommande], 409, 'TARGET_NOT_ALLOWED', 'SUPPLIER_MISMATCH'], [['commandeId' => $commandeId], 409, 'SHIPPING_COST_ALREADY_SET', null]] as [$targetValue, $status, $code, $why]) {
            $this->request('POST', "/api/gestion/documents/$doc2/confirmer", $body + ['target' => $targetValue]);
            $this->assertSame([$status, $code, $why], [$this->client->getResponse()->getStatusCode(), $this->body()['code'] ?? null, $this->body()['reason'] ?? null], json_encode($targetValue));
        }
        $this->em()->getConnection()->executeStatement('UPDATE commande SET is_closed = true WHERE id = ?', [$commandeId]);
        $this->endOfWrite();
        $this->request('POST', "/api/gestion/documents/$doc2/confirmer", ['shipping' => ['amount' => 1200, 'mode' => 'add']] + $body + ['target' => ['commandeId' => $commandeId]]);
        $this->assertSame([409, 'TARGET_NOT_ALLOWED', 'COMMANDE_CLOSED'], [$this->client->getResponse()->getStatusCode(), $this->body()['code'], $this->body()['reason']]);
        $preview = $this->post("/api/gestion/documents/$doc2/apercu", $body + ['target' => ['commandeId' => $commandeId]], 200);
        $this->assertSame([['path' => 'target', 'code' => 'TARGET_NOT_ALLOWED'], 'COMMANDE_CLOSED'], [array_values(array_filter($preview['issues'], fn (array $i) => $i['path'] === 'target'))[0] ?? null, $preview['target']['refused'] ?? null]);
        $this->assertSame($linesBefore, (int) $this->em()->getConnection()->fetchOne('SELECT SUM(quantity_ordered) FROM commande_line WHERE commande_id = ?', [$commandeId]), 'aucun de ces refus n\'a rien écrit');
        $this->assertNotContains('target', array_column($this->get("/api/gestion/conversations/$id2")['questions'], 'id'), 'commande figée : elle n\'est plus proposée');

        // Nettoyage
        $conn = $this->em()->getConnection();
        $conn->executeStatement('UPDATE commande SET is_closed = false WHERE id = ?', [$commandeId]);
        $this->endOfWrite();
        $this->post("/api/gestion/documents/$doc1/annuler-confirmation", [], 200);
        $conn = $this->em()->getConnection();
        $conn->executeStatement('DELETE FROM commande WHERE id = ?', [$otherCommande]);
        $conn->executeStatement('DELETE FROM product WHERE id = ?', [$jacket]);
        $conn->executeStatement('DELETE FROM fournisseur WHERE id IN (?, ?)', [$supplier->getId(), $other->getId()]);
        $this->endOfWrite();
    }

    /**
     * Lot IA-FACT-2-B : la phrase libre. L'IA traduit la phrase du gérant en réponses aux questions du fil ; le serveur écarte tout ce
     * qui ne vient ni des options, ni de la phrase, ni du document, et recontrôle chaque réponse comme un bouton. 1 unité par réponse de
     * l'IA, aucune si elle échoue ; plafond par jour ; l'IA ne confirme, n'annule et n'abandonne jamais.
     */
    public function testAFreeSentenceIsTurnedIntoCheckedAnswersForOneUnit(): void
    {
        $tag = $this->tag;
        $today = static::getContainer()->get(PeriodCalendar::class)->today();
        $supplier = $this->create(Fournisseur::class, ['name' => "Atelier Phrase $tag", 'currency' => 'EUR'], fn (Fournisseur $f, EntityManagerInterface $em) => $f->setTypeFournisseur($em->find(TypeFournisseur::class, 1) ?? $this->create(TypeFournisseur::class, ['name' => 'Autre'])));
        [$id, $documentId] = $this->startThread(['supplierName' => "Atelier Phrase $tag", 'invoiceNumber' => "PH-$tag", 'invoiceDate' => '', 'currency' => 'EUR', 'amountsMode' => 'ht',
            'lines' => [['baseLabel' => 'Lampe torsadée inconnue', 'quantity' => 2, 'unitCost' => 1500, 'total' => 3000], ['baseLabel' => 'Abat-jour inconnu', 'quantity' => 1]],
            'taxes' => [], 'shipping' => null, 'subtotal' => null, 'taxTotal' => null, 'total' => null, 'confidence' => ['overall' => 0.7, 'number' => 0.9, 'date' => 0.1, 'lines' => 0.7, 'totals' => 0.5], 'notes' => []]);
        $conn = $this->em()->getConnection();
        $units = fn (): int => (int) $this->em()->getConnection()->fetchOne("SELECT COUNT(*) FROM ai_usage WHERE status = 'success'");
        $unitsBefore = $units();
        $requestsBefore = count(FakeLandingAiClient::$requests);
        $questions = array_column($this->get("/api/gestion/conversations/$id")['questions'], null, 'id');
        $this->assertSame(['supplier', 'invoice.date', 'line:n1', 'line:n2', 'unitCost:2', 'exchangeRate'], array_keys($questions));
        $createIndex = count($questions['line:n1']['options']) - 1;
        $say = fn (array $answers, string $reply = 'C\'est noté.') => FakeLandingAiClient::$queue[] = $this->reading(['reply' => $reply, 'answers' => $answers]);

        // Une phrase, plusieurs réponses : la date, le taux, le coût, et un article à créer sous un nom donné par le gérant
        $day = $today->modify('-3 days');
        $sentence = sprintf('La facture est du %d/%d, le taux est de 1,52. L\'abat-jour coûte 12,50 et la première ligne est un nouvel article, appelle-le Lampe Brise.', (int) $day->format('j'), (int) $day->format('n'));
        $say([
            ['questionId' => 'invoice.date', 'optionIndex' => -1, 'value' => $day->format('Y-m-d'), 'name' => ''],
            ['questionId' => 'exchangeRate', 'optionIndex' => -1, 'value' => '1.52', 'name' => ''],
            ['questionId' => 'unitCost:2', 'optionIndex' => -1, 'value' => '12.50', 'name' => ''],
            ['questionId' => 'line:n1', 'optionIndex' => $createIndex, 'value' => '', 'name' => 'Lampe Brise'],
        ], 'J\'ai noté la date, le taux, le coût de l\'abat-jour et le nouvel article.');
        $out = $this->post("/api/gestion/conversations/$id/messages", ['text' => $sentence], 200);
        $this->assertSame(['reply', 'applied', 'ignored', 'thread'], array_keys($out));
        $this->assertSame('J\'ai noté la date, le taux, le coût de l\'abat-jour et le nouvel article.', $out['reply']);
        $this->assertSame([['questionId' => 'invoice.date', 'value' => $day->format('Y-m-d')], ['questionId' => 'exchangeRate', 'value' => 1.52], ['questionId' => 'unitCost:2', 'value' => 1250],
            ['questionId' => 'line:n1', 'value' => ['create' => ['name' => 'Lampe Brise', 'coefficient' => 2]]]], $out['applied']);
        $this->assertSame([], $out['ignored']);
        $answers = array_column($out['thread']['questions'], 'answer', 'id');
        $this->assertSame([$day->format('Y-m-d'), 1.52, 1250], [$answers['invoice.date'], $answers['exchangeRate'], $answers['unitCost:2']]);
        $this->assertSame(['questions', 1], [$out['thread']['state'], $out['thread']['openQuestions']], 'il reste l\'article de la seconde ligne');
        $this->assertSame([['user', 'text'], ['assistant', 'answers'], ['assistant', 'text']], array_map(fn (array $m) => [$m['role'], $m['kind']], array_slice($out['thread']['messages'], -3)));
        $last = array_slice($out['thread']['messages'], -3);
        $this->assertSame([$sentence, 'ai', ['invoice.date', 'exchangeRate', 'unitCost:2', 'line:n1']], [$last[0]['content']['text'], $last[1]['content']['source'], $last[2]['content']['applied']]);
        $this->assertSame([$unitsBefore + 1, $requestsBefore + 1], [$units(), count(FakeLandingAiClient::$requests)], '1 unité pour la réponse de l\'IA');
        // Ce que le modèle reçoit : la phrase, les questions avec leurs options numérotées, une sortie structurée, aucun outil imposé
        $request = FakeLandingAiClient::$requests[count(FakeLandingAiClient::$requests) - 1];
        $this->assertSame(['json_schema', false, 'claude-sonnet-5-5'], [$request['output_config']['format']['type'], array_key_exists('tool_choice', $request), $request['model']]);
        $sent = $request['messages'][0]['content'][0]['text'];
        $this->assertStringContainsString($sentence, $sent);
        $this->assertStringContainsString('"questionId":"line:n1"', $sent);
        $this->assertStringContainsString('"index":' . $createIndex, $sent);
        $this->assertStringContainsString('ne confirmes rien', $request['system']);

        // Garde-fous du serveur : un chiffre, une date ou un nom que le gérant n'a pas écrit est écarté ; une option hors liste aussi
        $say([
            ['questionId' => 'exchangeRate', 'optionIndex' => -1, 'value' => '1.60', 'name' => ''],
            ['questionId' => 'invoice.date', 'optionIndex' => -1, 'value' => $today->modify('-40 days')->format('Y-m-d'), 'name' => ''],
            ['questionId' => 'line:n2', 'optionIndex' => count($questions['line:n2']['options']) - 1, 'value' => '', 'name' => 'Nom Inventé Par Le Modèle'],
            ['questionId' => 'supplier', 'optionIndex' => 99, 'value' => '', 'name' => ''],
            ['questionId' => 'question.inconnue', 'optionIndex' => 0, 'value' => '', 'name' => ''],
        ]);
        $out = $this->post("/api/gestion/conversations/$id/messages", ['text' => 'Je ne sais plus trop, regarde ce que tu peux faire.'], 200);
        $this->assertSame([], $out['applied']);
        $this->assertSame([['questionId' => 'exchangeRate', 'reason' => 'VALUE_NOT_IN_SENTENCE'], ['questionId' => 'invoice.date', 'reason' => 'VALUE_NOT_IN_SENTENCE'], ['questionId' => 'line:n2', 'reason' => 'NAME_NOT_IN_SENTENCE'],
            ['questionId' => 'supplier', 'reason' => 'NOT_AN_OPTION'], ['questionId' => 'question.inconnue', 'reason' => 'UNKNOWN_QUESTION']], $out['ignored']);
        $this->assertSame($answers, array_column($out['thread']['questions'], 'answer', 'id'), 'rien n\'a changé dans le fil');
        $this->assertSame(['assistant', 'text'], [end($out['thread']['messages'])['role'], end($out['thread']['messages'])['kind']]);
        $this->assertSame($unitsBefore + 2, $units(), 'une réponse de l\'IA coûte 1 unité, même si elle ne change rien');

        // Un nom lu sur le document est admis ; le contrôle d'un bouton s'applique encore (article inexistant refusé) ; l'IA n'abandonne pas
        $say([['questionId' => 'line:n2', 'optionIndex' => count($questions['line:n2']['options']) - 1, 'value' => '', 'name' => 'Lampe torsadée inconnue'],
            ['questionId' => 'exchangeRate', 'optionIndex' => -1, 'value' => '0', 'name' => '']], '');
        $out = $this->post("/api/gestion/conversations/$id/messages?locale=en", ['text' => 'Crée la seconde ligne sous le nom de la première, et mets le taux à 0.'], 200);
        $this->assertSame([['questionId' => 'line:n2', 'value' => ['create' => ['name' => 'Lampe torsadée inconnue', 'coefficient' => 2]]]], $out['applied'], 'un nom lu sur le document est admis, même s\'il n\'est pas dans la phrase');
        $this->assertSame([['questionId' => 'exchangeRate', 'reason' => 'MUST_BE_POSITIVE']], $out['ignored'], 'une valeur écrite par le gérant repasse par le contrôle d\'un bouton');
        $this->assertSame(1.52, array_column($out['thread']['questions'], 'answer', 'id')['exchangeRate']);
        $this->assertSame(['C\'est noté : 1 réponse(s) enregistrée(s).', 'ready'], [$out['reply'], $out['thread']['state']], 'sans phrase du modèle : le texte du serveur, dans la langue du fil');
        $hash = $out['thread']['previewHash'];
        $commandes = (int) $conn->fetchOne('SELECT COUNT(*) FROM commande');
        $say([['questionId' => 'line:n1', 'optionIndex' => $createIndex, 'value' => '', 'name' => '']], 'Je confirme la facture.');
        $out = $this->post("/api/gestion/conversations/$id/messages", ['text' => 'Confirme la facture maintenant.'], 200);
        $this->assertSame(['ready', $commandes], [$out['thread']['state'], (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM commande')], 'l\'IA ne confirme jamais : rien n\'est créé');

        // Échec de l'IA : 503, aucune unité, rien dans le fil
        $messages = count($this->get("/api/gestion/conversations/$id")['messages']);
        $unitsNow = $units();
        FakeLandingAiClient::$queue[] = new \RuntimeException('réseau');
        $this->request('POST', "/api/gestion/conversations/$id/messages", ['text' => 'Le taux est 1,55.']);
        $this->assertSame([503, 'AI_UNAVAILABLE'], [$this->client->getResponse()->getStatusCode(), $this->body()['code']]);
        $this->assertSame([$unitsNow, $messages], [$units(), count($this->get("/api/gestion/conversations/$id")['messages'])]);

        // Validations, palier, plafond du jour
        foreach ([[[], 'REQUIRED'], [['text' => '   '], 'REQUIRED'], [['text' => str_repeat('a', 1001)], 'TOO_LONG']] as [$body, $code]) {
            $this->request('POST', "/api/gestion/conversations/$id/messages", $body);
            $this->assertSame([422, $code], [$this->client->getResponse()->getStatusCode(), $this->body()['fields']['text'] ?? null]);
        }
        $requests = count(FakeLandingAiClient::$requests);
        $conn = $this->em()->getConnection();
        $conversationId = $id;
        for ($i = 0; $i < 100; ++$i) {
            $conn->executeStatement("INSERT INTO gestion_conversation_message (conversation_id, role, kind, content, created_at) VALUES (?, 'assistant', 'text', '{\"text\": \"plafond $tag\"}', NOW())", [$conversationId]);
        }
        $this->endOfWrite();
        $this->request('POST', "/api/gestion/conversations/$id/messages", ['text' => 'Le taux est 1,55.']);
        $limit = $this->body();
        $this->assertSame([429, 'AI_DAILY_LIMIT', 100], [$this->client->getResponse()->getStatusCode(), $limit['code'], $limit['limit']]);
        $this->assertGreaterThan(new \DateTimeImmutable(), new \DateTimeImmutable($limit['resetAt']), 'l\'heure de remise à zéro : la fin du jour du magasin');
        $this->assertLessThanOrEqual(new \DateTimeImmutable('+24 hours'), new \DateTimeImmutable($limit['resetAt']));
        $this->assertSame([$requests, $unitsNow], [count(FakeLandingAiClient::$requests), $units()], 'plafond atteint : aucun appel, aucune unité');
        $this->em()->getConnection()->executeStatement("DELETE FROM gestion_conversation_message WHERE content::text LIKE ?", ['%plafond ' . $tag . '%']);
        // Les réponses par bouton restent possibles et gratuites
        $thread = $this->post("/api/gestion/conversations/$id/answers", ['answers' => [['questionId' => 'exchangeRate', 'value' => 1.55]]], 200);
        $this->assertSame(1.55, array_column($thread['questions'], 'answer', 'id')['exchangeRate']);

        // Sans le droit d'IA : 403, aucun appel
        $master = static::getContainer()->get(\App\Services\TenantConnectionManager::class)->getPdoMaster();
        $rights = $master->query("SELECT id FROM tenant_entitlements WHERE tenant_code = '" . MV_TEST_TENANT_CODE . "' AND feature_key IN ('ai.full', 'ai.help') AND status IN ('active', 'grace')")->fetchAll(\PDO::FETCH_COLUMN);
        foreach ($rights as $rightId) {
            static::getContainer()->get(\App\Services\Platform\EntitlementService::class)->cancel((int) $rightId, 'test', 'IA-FACT-2-B');
        }
        $this->request('POST', "/api/gestion/conversations/$id/messages", ['text' => 'Le taux est 1,55.']);
        $this->assertSame([403, 'AI_NOT_INCLUDED', $requests], [$this->client->getResponse()->getStatusCode(), $this->body()['code'], count(FakeLandingAiClient::$requests)]);
        AiRightTestHelper::restoreFullAi(static::getContainer());

        // Fil qui n'attend pas de réponse (abandonné) : 409, aucun appel
        $this->post("/api/gestion/conversations/$id/answers", ['answers' => [['questionId' => 'invoice.date', 'value' => $today->modify('-200 days')->format('Y-m-d')]]], 200);
        static::getContainer()->get(PeriodCalendar::class)->ensurePeriods($today->modify('-400 days'));
        $this->endOfWrite();
        $closed = $this->get("/api/gestion/conversations/$id");
        if (in_array('period', array_column($closed['questions'], 'id'), true)) {
            $say([['questionId' => 'period', 'optionIndex' => 1, 'value' => '', 'name' => '']]);
            $out = $this->post("/api/gestion/conversations/$id/messages", ['text' => 'Laisse tomber, abandonne.'], 200);
            $this->assertSame([[], [['questionId' => 'period', 'reason' => 'ABANDON_BY_BUTTON_ONLY']], 'questions'], [$out['applied'], $out['ignored'], $out['thread']['state']], 'abandonner reste un geste du gérant');
            $this->post("/api/gestion/conversations/$id/answers", ['answers' => [['questionId' => 'period', 'value' => 'abandon']]], 200);
            $before = count(FakeLandingAiClient::$requests);
            $this->request('POST', "/api/gestion/conversations/$id/messages", ['text' => 'Encore là ?']);
            $this->assertSame([409, 'CONVERSATION_NOT_OPEN', $before], [$this->client->getResponse()->getStatusCode(), $this->body()['code'], count(FakeLandingAiClient::$requests)]);
        } else {
            $this->fail('la période d\'il y a 200 jours devrait être fermée');
        }
        $this->em()->getConnection()->executeStatement('DELETE FROM fournisseur WHERE id = ?', [$supplier->getId()]);
        $this->endOfWrite();
    }

    public function testRefusalsAccessAndFailedReading(): void
    {
        $this->request('GET', '/api/gestion/conversations/2147483000');
        $this->assertSame([404, 'CONVERSATION_NOT_FOUND'], [$this->client->getResponse()->getStatusCode(), $this->body()['code']]);
        $this->request('POST', '/api/gestion/conversations', []);
        $this->assertSame([422, 'REQUIRED'], [$this->client->getResponse()->getStatusCode(), $this->body()['fields']['files'] ?? null]);
        $this->request('POST', '/api/gestion/conversations', ['documentId' => 2147483000]);
        $this->assertSame([404, 'DOCUMENT_NOT_FOUND'], [$this->client->getResponse()->getStatusCode(), $this->body()['code']]);
        // Un autre type de document n'entre pas dans un fil (ce lot : la facture fournisseur), et ne s'annule pas d'un bloc
        $this->client->request('POST', '/api/gestion/documents', ['type' => 'note'], ['files' => [$this->image('recu.png')]], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $this->token]);
        $note = (int) $this->body()['id'];
        $this->request('POST', '/api/gestion/conversations', ['documentId' => $note]);
        $this->assertSame([422, 'DOCUMENT_TYPE'], [$this->client->getResponse()->getStatusCode(), $this->body()['code']]);
        $this->request('POST', "/api/gestion/documents/$note/annuler-confirmation");
        $this->assertSame([409, 'DOCUMENT_NOT_CONFIRMED'], [$this->client->getResponse()->getStatusCode(), $this->body()['code']]);
        $this->em()->getConnection()->executeStatement("UPDATE gestion_document SET status = 'failed' WHERE id = ?", [$note]);

        // Lecture en échec : le fil le dit, sans question ni aperçu
        [$status, $thread] = $this->multipart('/api/gestion/conversations', [], ['files' => [$this->image('f.png')]]);
        $this->assertSame(201, $status);
        FakeLandingAiClient::$queue[] = new \RuntimeException('réseau');
        try {
            $this->runWorker((int) $thread['document']['id']);
        } catch (\Throwable) {
            // l'échec de lecture est porté par le document
        }
        $failed = $this->get('/api/gestion/conversations/' . $thread['id']);
        $this->assertSame(['failed', 'failed', [], null, null], [$failed['state'], $failed['document']['status'], $failed['questions'], $failed['preview'], $failed['previewHash']]);
        $this->assertNotNull($failed['document']['error']);
        $this->request('POST', '/api/gestion/conversations/' . $thread['id'] . '/answers', ['answers' => [['questionId' => 'supplier', 'value' => null]]]);
        $this->assertSame([409, 'CONVERSATION_NOT_OPEN', 'failed'], [$this->client->getResponse()->getStatusCode(), $this->body()['code'], $this->body()['state']]);
        $this->request('POST', '/api/gestion/conversations/' . $thread['id'] . '/undo');
        $this->assertSame([409, 'DOCUMENT_NOT_CONFIRMED'], [$this->client->getResponse()->getStatusCode(), $this->body()['code']]);

        // Une confirmation d'avant ce lot (rien de noté) ne s'annule pas d'un bloc
        $documentId = (int) $thread['document']['id'];
        $conn = $this->em()->getConnection();
        $conn->executeStatement("UPDATE gestion_document SET status = 'confirmed', confirmed_at = NOW(), confirmed = '{\"supplier\": {\"id\": 1}}' WHERE id = ?", [$documentId]);
        $this->endOfWrite();
        $this->request('POST', "/api/gestion/documents/$documentId/annuler-confirmation");
        $this->assertSame([409, 'UNDO_UNAVAILABLE'], [$this->client->getResponse()->getStatusCode(), $this->body()['code']]);
        $this->assertSame(['available' => false, 'until' => null], $this->get("/api/gestion/documents/$documentId")['undo']);
        $conn = $this->em()->getConnection();
        $conn->executeStatement("UPDATE gestion_document SET status = 'failed', confirmed_at = NULL, confirmed = NULL WHERE id = ?", [$documentId]);
        $this->endOfWrite();

        // Réservé à l'administrateur du site
        $this->login(['ROLE_USER_INTERNET']);
        foreach ([['GET', '/api/gestion/conversations'], ['GET', '/api/gestion/conversations/' . $thread['id']], ['POST', '/api/gestion/conversations/' . $thread['id'] . '/answers'], ['POST', '/api/gestion/conversations/' . $thread['id'] . '/confirm'],
            ['POST', '/api/gestion/conversations/' . $thread['id'] . '/undo'], ['POST', "/api/gestion/documents/$documentId/annuler-confirmation"]] as [$method, $route]) {
            $this->request($method, $route, $method === 'POST' ? [] : null);
            $this->assertSame(403, $this->client->getResponse()->getStatusCode(), "$method $route");
        }
    }

    // ----- utilitaires -----

    /** @return array{0: int, 1: int} fil et document d'une facture envoyée puis lue par le faux modèle */
    private function startThread(array $reading): array
    {
        [$status, $thread] = $this->multipart('/api/gestion/conversations', [], ['files' => [$this->image('f.png')]]);
        $this->assertSame(201, $status, json_encode($thread));
        FakeLandingAiClient::$queue[] = $this->reading($reading);
        $this->runWorker((int) $thread['document']['id']);

        return [(int) $thread['id'], (int) $thread['document']['id']];
    }

    private function runWorker(int $documentId): void
    {
        static::getContainer()->get(ReadGestionDocumentHandler::class)(new ReadGestionDocumentMessage($documentId, MV_TEST_TENANT_CODE));
        $this->em()->clear();
        $this->endOfWrite();
    }

    /** Réponse du faux modèle : la lecture d'une facture, en sortie structurée */
    private function reading(array $input): object
    {
        return json_decode(json_encode([
            'id' => 'msg_' . bin2hex(random_bytes(4)), 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-sonnet-5-5', 'stop_reason' => 'end_turn',
            'content' => [['type' => 'text', 'text' => json_encode($input)]],
            'usage' => ['input_tokens' => 4000, 'output_tokens' => 600, 'cache_read_input_tokens' => 0, 'cache_creation_input_tokens' => 0],
        ]), false);
    }

    private function image(string $name): UploadedFile
    {
        $tmp = tempnam(sys_get_temp_dir(), 'img');
        copy(__DIR__ . '/../../../public/assets/arkanoa-favicon.png', $tmp);

        return new UploadedFile($tmp, $name, 'image/png', null, true);
    }

    /** @return array{0: int, 1: array} statut et corps */
    private function multipart(string $route, array $fields, array $files): array
    {
        $this->client->request('POST', $route, $fields, $files, ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $this->token]);

        return [$this->client->getResponse()->getStatusCode(), $this->body()];
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
        $this->email = 'gestion-fil-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $em = $this->em();
        $user = (new User())->setEmail($this->email)->setUsername($this->email)->setFirstname('Gestion')->setLastname('Fil')->setRoles($roles)->setIsVerified(true)->setPassword('inutilisable');
        $em->persist($user);
        $em->flush();
        $this->token = static::getContainer()->get('lexik_jwt_authentication.jwt_manager')->create($user);
        $this->endOfWrite();
    }
}
