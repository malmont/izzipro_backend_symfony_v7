<?php

namespace App\Tests\Functional\Gestion;

use App\Entity\Commande;
use App\Entity\Fournisseur;
use App\Entity\GestionDocument;
use App\Entity\Product;
use App\Entity\Transporteur;
use App\Entity\TypeFournisseur;
use App\Entity\TypeNoteDeFrais;
use App\Entity\User;
use App\EventSubscriber\CacheInvalidationSubscriber;
use App\MessageHandler\ReadGestionDocumentHandler;
use App\Message\ReadGestionDocumentMessage;
use App\Services\GestionDocumentService\GestionDocumentReader;
use App\Services\GestionService\PeriodCalendar;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use App\Tests\Functional\Support\AiRightTestHelper;
use App\UseCase\GestionUseCase\ConfirmGestionDocumentUseCase;
use App\Tests\Fake\FakeLandingAiClient;
use App\Tests\Functional\Cache\CacheTestEntityFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Lecture de documents par IA (lot L1, 10/11/2026, `docs/lecture-documents-gestion.md`, contrat validé par l'application) : envoi
 * asynchrone (202, worker simulé), extraction d'une facture TTC ramenée hors taxes avec correspondances et regroupement taille /
 * couleur, brouillon, photo de ligne, fichiers privés, confirmation (période clôturée → date de classement, article créé avec
 * variantes et image, article existant rattaché sans effet sur la vente, frais de port sans transporteur), doublon, note de frais,
 * facture de transport (PDF), validations, échec de lecture et relecture, expiration et purge.
 */
class GestionDocumentsApiTest extends WebTestCase
{
    use CacheTestEntityFactory;

    private KernelBrowser $client;
    private string $token = '';

    protected function setUp(): void
    {
        $this->client = static::createClient();
        FakeLandingAiClient::reset();
        ConfirmGestionDocumentUseCase::$invoiceConfirmationEnabled = true; // en sommeil en production jusqu'au lot L0 : le code reste éprouvé
        AiRightTestHelper::restoreFullAi(static::getContainer()); // droits partagés : un test précédent peut les avoir vidés
        $this->login();
        $this->em()->getConnection()->executeStatement("UPDATE gestion_document SET status = 'failed' WHERE status = 'reading'"); // base partagée : une lecture à la fois par site
    }

    protected function tearDown(): void
    {
        ConfirmGestionDocumentUseCase::$invoiceConfirmationEnabled = false;
        parent::tearDown();
    }

    public function testInvoiceConfirmationIsDormantUntilPurchaseLines(): void
    {
        ConfirmGestionDocumentUseCase::$invoiceConfirmationEnabled = false;
        [$status, $doc] = $this->multipartBody('/api/gestion/documents', ['type' => 'facture'], ['files' => [$this->image('f.png')]]);
        $this->assertSame(202, $status, json_encode($doc));
        FakeLandingAiClient::$queue[] = $this->toolResponse('lire_facture_fournisseur', ['supplierName' => 'X', 'invoiceNumber' => 'A1', 'amountsMode' => 'ht', 'lines' => [['baseLabel' => 'Ceinture', 'quantity' => 1, 'unitCost' => 900]], 'confidence' => ['overall' => 0.8]]);
        $this->runWorker((int) $doc['id']);
        $this->assertSame('extracted', $this->get('/api/gestion/documents/' . $doc['id'])['status'], 'la lecture d\'une facture reste disponible');
        $this->patch('/api/gestion/documents/' . $doc['id'] . '/brouillon', ['lines' => ['1' => ['validated' => true]]]);
        $commandes = (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM commande');
        $this->request('POST', '/api/gestion/documents/' . $doc['id'] . '/confirmer', ['lines' => []]);
        $this->assertSame([409, 'INVOICE_CONFIRMATION_PENDING'], [$this->client->getResponse()->getStatusCode(), $this->body()['error']]);
        $this->assertSame($commandes, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM commande'), 'rien n\'est créé');
        $this->assertSame('extracted', $this->get('/api/gestion/documents/' . $doc['id'])['status'], 'document et brouillon conservés');
    }

    public function testReadingRequiresAiRightAndLostReadingsDoNotBlockTheSite(): void
    {
        $master = static::getContainer()->get(TenantConnectionManager::class)->getPdoMaster();
        $master->prepare("DELETE FROM tenant_entitlements WHERE tenant_code = ? AND feature_key IN ('ai.full', 'ai.help')")->execute([MV_TEST_TENANT_CODE]);
        try {
            $this->assertSame([403, 'AI_NOT_INCLUDED'], $this->multipart('/api/gestion/documents', ['type' => 'note'], ['files' => [$this->image('a.png')]], 'error'), 'sans option IA : refus, même en démonstration');
            $this->assertSame(0, (int) $this->em()->getConnection()->fetchOne("SELECT COUNT(*) FROM gestion_document WHERE status = 'reading'"), 'rien n\'est gardé');
            $master->prepare("INSERT INTO tenant_entitlements (tenant_code, feature_key, source, status, granted_by, note) VALUES (?, 'ai.help', 'manual', 'active', 'test', 'palier Aide IA')")->execute([MV_TEST_TENANT_CODE]);
            [$status, $doc] = $this->multipartBody('/api/gestion/documents', ['type' => 'note'], ['files' => [$this->image('b.png')]]);
            $this->assertSame([202, 'reading'], [$status, $doc['status']], '« Aide IA » suffit pour lire');
        } finally {
            $master->prepare("DELETE FROM tenant_entitlements WHERE tenant_code = ? AND feature_key = 'ai.help'")->execute([MV_TEST_TENANT_CODE]);
            AiRightTestHelper::restoreFullAi(static::getContainer());
        }

        // Lecture perdue (worker arrêté) : réservation échue → échec, crédit libéré, le site n'est plus bloqué
        $conn = $this->em()->getConnection();
        $usageId = (int) $conn->fetchOne('SELECT ai_usage_id FROM gestion_document WHERE id = ?', [$doc['id']]);
        $this->assertSame([409, 'DOCUMENT_IN_PROGRESS'], $this->multipart('/api/gestion/documents', ['type' => 'note'], ['files' => [$this->image('c.png')]], 'error'));
        $conn->executeStatement("UPDATE ai_usage SET reserved_until = NOW() - INTERVAL '1 minute' WHERE id = ?", [$usageId]);
        $this->em()->clear();
        $lost = $this->get('/api/gestion/documents/' . $doc['id']);
        $this->assertSame(['failed', 'AI_UNAVAILABLE'], [$lost['status'], $lost['error']]);
        $this->assertNotSame('reserved', $conn->fetchOne('SELECT status FROM ai_usage WHERE id = ?', [$usageId]), 'crédit libéré');
        $this->runWorker((int) $doc['id']); // un message arrivé après coup n'y change rien
        $this->assertSame('failed', $this->get('/api/gestion/documents/' . $doc['id'])['status']);
        $this->assertCount(0, FakeLandingAiClient::$requests);
        [$status] = $this->multipartBody('/api/gestion/documents', ['type' => 'note'], ['files' => [$this->image('d.png')]]);
        $this->assertSame(202, $status, 'le site peut envoyer à nouveau');
    }

    public function testPhonePhotoOrientationIsAppliedBeforeReduction(): void
    {
        ob_start();
        imagejpeg(imagecreatetruecolor(3000, 1500), null, 80);
        $jpeg = (string) ob_get_clean();
        $tiff = "II*\0" . pack('V', 8) . pack('v', 1) . pack('vvVvv', 0x0112, 3, 1, 6, 0) . pack('V', 0); // Orientation = 6 (photo tenue debout)
        $app1 = "Exif\0\0" . $tiff;
        $withExif = substr($jpeg, 0, 2) . "\xFF\xE1" . pack('n', strlen($app1) + 2) . $app1 . substr($jpeg, 2);
        $this->assertSame(6, GestionDocumentReader::exifOrientation($withExif));
        $this->assertSame(1, GestionDocumentReader::exifOrientation($jpeg), 'sans EXIF');
        $tmp = tempnam(sys_get_temp_dir(), 'tel');
        file_put_contents($tmp, $withExif);
        [$status, $doc] = $this->multipartBody('/api/gestion/documents', ['type' => 'note'], ['files' => [new UploadedFile($tmp, 'tel.jpg', 'image/jpeg', null, true)]]);
        $this->assertSame(202, $status, json_encode($doc));
        FakeLandingAiClient::$queue[] = $this->toolResponse('lire_note_de_frais', ['merchant' => 'X', 'amount' => 100, 'confidence' => ['overall' => 0.5]]);
        $this->runWorker((int) $doc['id']);
        $image = array_values(array_filter(FakeLandingAiClient::$requests[0]['messages'][0]['content'], fn ($b) => $b['type'] === 'image'))[0];
        $this->assertSame([1200, 2400], array_slice(getimagesizefromstring(base64_decode($image['source']['data'])), 0, 2), 'redressée puis réduite');
    }

    public function testLargePhotoIsReducedForTheModelButKeptIntact(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'grande');
        imagepng(imagecreatetruecolor(3600, 1800), $tmp);
        $original = (int) filesize($tmp);
        [$status, $doc] = $this->multipartBody('/api/gestion/documents', ['type' => 'note'], ['files' => [new UploadedFile($tmp, 'grande.png', 'image/png', null, true)]]);
        $this->assertSame(202, $status);
        FakeLandingAiClient::$queue[] = $this->toolResponse('lire_note_de_frais', ['merchant' => 'X', 'amount' => 100, 'confidence' => ['overall' => 0.5]]);
        $this->runWorker((int) $doc['id']);
        $image = array_values(array_filter(FakeLandingAiClient::$requests[0]['messages'][0]['content'], fn ($b) => $b['type'] === 'image'))[0];
        $this->assertSame('image/jpeg', $image['source']['media_type']);
        $this->assertSame([2400, 1200], array_slice(getimagesizefromstring(base64_decode($image['source']['data'])), 0, 2), 'côté le plus long ramené à 2400 px');
        $this->request('GET', '/api/gestion/documents/' . $doc['id'] . '/fichier/1');
        $this->assertSame(['image/png', $original], [$this->client->getResponse()->headers->get('Content-Type'), (int) filesize($this->client->getResponse()->getFile()->getPathname())], 'justificatif d\'origine intact');
    }

    public function testInvoiceFlowFromUploadToConfirmation(): void
    {
        $tag = bin2hex(random_bytes(2));
        $calendar = static::getContainer()->get(PeriodCalendar::class);
        $today = $calendar->today();
        $em = $this->em();
        $supplier = $this->create(Fournisseur::class, ['name' => "Atelier Nord $tag"], fn (Fournisseur $f, EntityManagerInterface $em) => $f->setTypeFournisseur($em->find(TypeFournisseur::class, 1) ?? $this->create(TypeFournisseur::class, ['name' => 'Autre'])));
        $conn = $this->em()->getConnection();
        $conn->executeStatement("INSERT INTO product (id, name, price, isbestseller, quantity, created_at, slug, product_type, code) VALUES (nextval('product_id_seq'), :n, 2500, false, 5, NOW(), :s, 'standard', :c)", ['n' => "Tuque en tricot torsadé $tag", 's' => "tuque-$tag", 'c' => "TQ-$tag"]);
        $tuque = (int) $conn->fetchOne('SELECT id FROM product WHERE slug = :s', ['s' => "tuque-$tag"]);
        $this->endOfWrite();

        // Validations d'envoi : rien n'est gardé
        $documentsBefore = (int) $conn->fetchOne('SELECT COUNT(*) FROM gestion_document');
        $this->assertSame([422, ['files' => 'REQUIRED']], $this->multipart('/api/gestion/documents', ['type' => 'facture'], []), 'sans fichier');
        $this->assertSame([422, ['type' => 'INVALID_TYPE']], $this->multipart('/api/gestion/documents', ['type' => 'devis'], ['files' => [$this->image('a.png')]]));
        $this->assertSame([422, ['files' => 'MIXED']], $this->multipart('/api/gestion/documents', ['type' => 'facture'], ['files' => [$this->image('a.png'), $this->pdf(1)]]));
        $this->assertSame([422, ['files' => 'TOO_MANY']], $this->multipart('/api/gestion/documents', ['type' => 'facture'], ['files' => array_map(fn ($i) => $this->image("p$i.png"), range(1, 7))]));
        $this->assertSame($documentsBefore, (int) $conn->fetchOne('SELECT COUNT(*) FROM gestion_document'), 'requêtes invalides : rien n\'est gardé');

        // Envoi : 202 en lecture, un seul à la fois, puis lecture par le worker (faux modèle)
        [$status, $doc] = $this->multipartBody('/api/gestion/documents', ['type' => 'facture', 'locale' => 'fr'], ['files' => [$this->image('page1.png'), $this->image('page2.png')]]);
        $this->assertSame([202, 'reading', 'facture', 'CAD', 2], [$status, $doc['status'], $doc['type'], $doc['currency'], count($doc['files'])], json_encode($doc));
        $id = (int) $doc['id'];
        $this->assertNotNull($doc['expiresAt']);
        $this->assertSame(['reading', null], [$this->get("/api/gestion/documents/$id")['status'], $this->get("/api/gestion/documents/$id")['extraction']]);
        $this->assertSame([409, 'DOCUMENT_IN_PROGRESS'], $this->multipart('/api/gestion/documents', ['type' => 'note'], ['files' => [$this->image('b.png')]], 'error'), 'une lecture à la fois');
        $invoiceDate = $today->modify('-200 days')->format('Y-m-d'); // période clôturée
        FakeLandingAiClient::$queue[] = $this->toolResponse('lire_facture_fournisseur', [
            'supplierName' => "Atelier Nord $tag", 'invoiceNumber' => "F-$tag", 'invoiceDate' => $invoiceDate, 'currency' => 'CAD', 'amountsMode' => 'ttc',
            'lines' => [
                ['baseLabel' => 'Robe en lin', 'size' => 'S', 'color' => 'Écru', 'quantity' => 2, 'unitCost' => 2300, 'total' => 4600],
                ['baseLabel' => 'Robe en lin', 'size' => 'M', 'color' => 'Écru', 'quantity' => 1, 'unitCost' => 2300, 'total' => 2300],
                ['baseLabel' => 'Tuque torsadée', 'supplierCode' => "TQ-$tag", 'quantity' => 3, 'unitCost' => 1150, 'total' => 3450],
            ],
            'taxes' => [['label' => 'TPS', 'rate' => 5, 'amount' => 450], ['label' => 'TVQ', 'rate' => 9.975, 'amount' => 898]],
            'shipping' => null, 'subtotal' => 9002, 'taxTotal' => 1348, 'total' => 10350,
            'confidence' => ['overall' => 0.9, 'number' => 0.95, 'date' => 0.9, 'lines' => 0.85, 'totals' => 0.9], 'notes' => [],
        ]);
        $this->runWorker($id);
        $this->assertCount(1, FakeLandingAiClient::$requests);
        $request = FakeLandingAiClient::$requests[0];
        $this->assertSame(['type' => 'auto'], $request['tool_choice'], 'Sonnet 5.5 refuse l\'outil forcé : auto + consigne');
        $this->assertStringContainsString('lire_facture_fournisseur', json_encode($request['messages'][0]['content'], JSON_UNESCAPED_UNICODE) ?: '', 'la consigne nomme l\'outil');
        $this->assertCount(2, array_filter($request['messages'][0]['content'], fn ($b) => $b['type'] === 'image'), 'les deux pages partent en base64');
        $read = $this->get("/api/gestion/documents/$id");
        $this->assertSame('extracted', $read['status']);
        $x = $read['extraction'];
        $this->assertSame([$supplier->getId(), "F-$tag", $invoiceDate, 'ttc'], [$x['supplier']['id'], $x['invoice']['number'], $x['invoice']['date'], $x['amountsMode']], 'fournisseur reconnu par son nom');
        $this->assertSame([2000, 4000, 2300, 'robe-en-lin', 2, 'S', 'Écru'], [$x['lines'][0]['unitCost'], $x['lines'][0]['total'], $x['lines'][0]['unitCostRead'], $x['lines'][0]['groupKey'], $x['lines'][0]['groupSize'], $x['lines'][0]['size'], $x['lines'][0]['color']], 'TTC → HT au taux du document (1348 / 9002)');
        $this->assertSame($x['lines'][0]['groupKey'], $x['lines'][1]['groupKey']);
        $this->assertSame([$tuque, 1.0, 1000, 3000], [$x['lines'][2]['product']['id'], (float) $x['lines'][2]['product']['score'], $x['lines'][2]['unitCost'], $x['lines'][2]['total']], 'article existant proposé par son code');
        $this->assertSame([9000, 10348, 2, 10350], [$x['computed']['linesTotal'], $x['computed']['total'], $x['computed']['difference'], $x['totals']['total']], 'écart entre le total lu et le total recalculé');
        $this->assertNull($x['duplicate']);
        $this->assertSame('success', $conn->fetchOne('SELECT status FROM ai_usage WHERE id = ?', [$read['aiUsageId'] ?? $conn->fetchOne('SELECT ai_usage_id FROM gestion_document WHERE id = ?', [$id])]), 'crédit facturé');

        // Brouillon idempotent, photo de ligne, fichiers privés
        $draft = ['lines' => ['1' => ['validated' => true, 'unitCost' => 2000], '3' => ['validated' => true]]];
        $d1 = $this->patch("/api/gestion/documents/$id/brouillon", $draft);
        $this->assertSame($draft, $d1['draft']);
        $this->assertSame($d1['draftUpdatedAt'], $this->patch("/api/gestion/documents/$id/brouillon", $draft)['draftUpdatedAt'], 'même brouillon : rien ne change');
        [$status, $photo] = $this->multipartBody("/api/gestion/documents/$id/lignes/1/photo", [], ['file' => $this->image('robe.png')]);
        $this->assertSame(200, $status, json_encode($photo));
        $this->assertSame([1, 'image/png'], [$photo['n'], $photo['photo']['mime']]);
        $this->assertArrayHasKey('1', $this->get("/api/gestion/documents/$id")['linePhotos']);
        $this->request('GET', "/api/gestion/documents/$id/lignes/1/photo");
        $this->assertSame([200, 'image/png'], [$this->client->getResponse()->getStatusCode(), $this->client->getResponse()->headers->get('Content-Type')]);
        $this->request('GET', "/api/gestion/documents/$id/fichier/2");
        $this->assertSame([200, 'image/png', 'no-store, private'], [$this->client->getResponse()->getStatusCode(), $this->client->getResponse()->headers->get('Content-Type'), $this->client->getResponse()->headers->get('Cache-Control')]);
        $this->request('GET', "/api/gestion/documents/$id/fichier/9");
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());

        // Confirmation : période clôturée → 409, puis date de classement du jour ; tout ou rien
        $tuqueBefore = $conn->fetchAssociative('SELECT price, purchase_price, quantity, is_web, is_pos FROM product WHERE id = ?', [$tuque]);
        $body = ['supplier' => ['id' => $supplier->getId()], 'invoice' => ['number' => "F-$tag", 'date' => $invoiceDate], 'lines' => [
            ['n' => 1, 'quantity' => 2, 'unitCost' => 2000, 'size' => 'S', 'color' => 'Écru', 'create' => ['name' => "Robe en lin $tag", 'coefficient' => 2.5, 'groupKey' => 'robe-en-lin']],
            ['n' => 2, 'quantity' => 1, 'unitCost' => 2000, 'size' => 'M', 'color' => 'Écru', 'create' => ['name' => "Robe en lin $tag", 'groupKey' => 'robe-en-lin']],
            ['n' => 3, 'quantity' => 3, 'unitCost' => 1000, 'productId' => $tuque],
        ], 'shipping' => ['amount' => 500]];
        $this->request('POST', "/api/gestion/documents/$id/confirmer", $body);
        $this->assertSame([409, 'PERIOD_CLOSED'], [$this->client->getResponse()->getStatusCode(), $this->body()['error']], 'facture datée dans une période clôturée');
        $this->request('POST', "/api/gestion/documents/$id/confirmer", array_merge($body, ['classificationDate' => $today->format('Y-m-d'), 'lines' => [['n' => 1, 'quantity' => 2, 'unitCost' => 2000]]]));
        $this->assertSame([422, ['lines[1]' => 'LINE_UNRESOLVED']], [$this->client->getResponse()->getStatusCode(), $this->body()['fields']], 'ligne sans article : rien n\'est écrit');
        $this->assertSame(0, (int) $conn->fetchOne('SELECT COUNT(*) FROM commande WHERE document_id = ?', [$id]));
        $confirmed = $this->post("/api/gestion/documents/$id/confirmer", $body + ['classificationDate' => $today->format('Y-m-d')], 201);
        $commande = $confirmed['commande'];
        $this->assertSame([9500, $today->format('Y-m-d'), 'standard', $id, "F-$tag", $invoiceDate], [$commande['budget'], substr($commande['date'], 0, 10), $commande['kind'], $commande['document']['id'], $commande['document']['invoiceNumber'], $commande['document']['invoiceDate']], 'commande classée au jour, budget = lignes HT + port, justificatif avec la vraie date de facture');
        $this->assertSame([[['lineN' => 3, 'productId' => $tuque]], 2], [$confirmed['attached'], count($confirmed['created'])]);
        $this->assertSame($confirmed['created'][0]['productId'], $confirmed['created'][1]['productId'], 'un seul article pour les deux tailles');
        $robeId = (int) $confirmed['created'][0]['productId'];
        $robe = $conn->fetchAssociative('SELECT name, price, purchase_price, coefficient_multiplier, quantity, is_web, is_pos, commande_id, commande_cost_cents, commande_quantity, image FROM product WHERE id = ?', [$robeId]);
        $this->assertSame(["Robe en lin $tag", 5000.0, 2000.0, 2.5, 3, false, false, $commande['id'], 2000, 3], [$robe['name'], (float) $robe['price'], (float) $robe['purchase_price'], (float) $robe['coefficient_multiplier'], (int) $robe['quantity'], (bool) $robe['is_web'], (bool) $robe['is_pos'], (int) $robe['commande_id'], (int) $robe['commande_cost_cents'], (int) $robe['commande_quantity']], 'article créé : prix = achat × coefficient, stock = lignes, invisible');
        $this->assertNotNull($robe['image'], 'photo de ligne devenue image de l\'article');
        $this->assertSame(['M', 'S'], $conn->fetchFirstColumn('SELECT s.name FROM product_variant v JOIN size s ON s.id = v.size_id WHERE v.product_id = ? ORDER BY s.name', [$robeId]), 'variantes par taille');
        $tuqueAfter = $conn->fetchAssociative('SELECT price, purchase_price, quantity, is_web, is_pos, commande_id, commande_cost_cents, commande_quantity FROM product WHERE id = ?', [$tuque]);
        $this->assertSame($tuqueBefore, array_intersect_key($tuqueAfter, $tuqueBefore), 'rattachement : ni prix, ni stock, ni visibilité changés');
        $this->assertSame([$commande['id'], 1000, 3], [(int) $tuqueAfter['commande_id'], (int) $tuqueAfter['commande_cost_cents'], (int) $tuqueAfter['commande_quantity']]);
        $this->assertSame([500, 'Sur facture fournisseur'], [$confirmed['shipping']['price'], $confirmed['shipping']['transporteur']['name']], 'frais de port sans transporteur');
        $this->assertSame(['confirmed', $commande['id']], [$confirmed['document']['status'], $confirmed['document']['commandeId']]);
        $this->request('POST', "/api/gestion/documents/$id/confirmer", $body);
        $this->assertSame([409, 'DOCUMENT_CONFIRMED'], [$this->client->getResponse()->getStatusCode(), $this->body()['error']]);
        $this->request('DELETE', "/api/gestion/documents/$id");
        $this->assertSame(409, $this->client->getResponse()->getStatusCode());
        $listed = $this->get('/api/collections/' . $commande['collectionId'] . '/commandes');
        $this->assertSame("F-$tag", $listed[array_search($commande['id'], array_column($listed, 'id'), true)]['document']['invoiceNumber'], 'justificatif dans la liste des commandes');

        // Doublon signalé dès la lecture, refusé à la confirmation sauf force
        [, $doc2] = $this->multipartBody('/api/gestion/documents', ['type' => 'facture'], ['files' => [$this->image('dup.png')]]);
        FakeLandingAiClient::$queue[] = $this->toolResponse('lire_facture_fournisseur', ['supplierName' => "Atelier Nord $tag", 'invoiceNumber' => "F-$tag", 'invoiceDate' => $today->format('Y-m-d'), 'amountsMode' => 'ht', 'lines' => [['baseLabel' => 'Ceinture', 'quantity' => 1, 'unitCost' => 900, 'total' => 900]], 'total' => 900, 'confidence' => ['overall' => 0.8]]);
        $this->runWorker((int) $doc2['id']);
        $x2 = $this->get('/api/gestion/documents/' . $doc2['id'])['extraction'];
        $this->assertSame([$id, $commande['id'], 'confirmed'], [$x2['duplicate']['documentId'], $x2['duplicate']['commandeId'], $x2['duplicate']['status']]);
        $this->assertNotContains('Montants supposés hors taxes : à vérifier.', $x2['notes'], 'montants lus hors taxes : pas d\'avertissement');
        $this->assertSame([900, 900, 'ht'], [$x2['lines'][0]['unitCost'], $x2['lines'][0]['unitCostRead'], $x2['amountsMode']]);
        $dupBody = ['supplier' => ['id' => $supplier->getId()], 'invoice' => ['number' => "F-$tag", 'date' => $today->format('Y-m-d')], 'lines' => [['n' => 1, 'quantity' => 1, 'unitCost' => 900, 'create' => ['name' => "Ceinture $tag"]]]];
        $this->request('POST', '/api/gestion/documents/' . $doc2['id'] . '/confirmer', $dupBody);
        $this->assertSame([409, 'INVOICE_ALREADY_CONFIRMED', $id], [$this->client->getResponse()->getStatusCode(), $this->body()['error'], $this->body()['documentId']]);
        $forced = $this->post('/api/gestion/documents/' . $doc2['id'] . '/confirmer', $dupBody + ['force' => true], 201);
        $this->assertSame([900, 'Ceinture ' . $tag], [$forced['commande']['budget'], $forced['created'][0]['name']]);
        $this->assertSame(0, (int) $conn->fetchOne('SELECT COUNT(*) FROM product_variant v JOIN product p ON p.id = v.product_id WHERE p.id = ?', [$forced['created'][0]['productId']]), 'ligne seule sans taille : pas de variante');
    }

    public function testNoteTransportFailureRereadAndPurge(): void
    {
        $tag = bin2hex(random_bytes(2));
        $calendar = static::getContainer()->get(PeriodCalendar::class);
        $today = $calendar->today();
        $conn = $this->em()->getConnection();
        $type = $this->em()->getRepository(TypeNoteDeFrais::class)->findOneBy(['name' => 'Déplacement']) ?? $this->create(TypeNoteDeFrais::class, ['name' => 'Déplacement']);
        $carrier = $this->create(Transporteur::class, ['name' => "Purolator $tag"]);
        $this->endOfWrite();

        // Note de frais
        [, $note] = $this->multipartBody('/api/gestion/documents', ['type' => 'note'], ['files' => [$this->image('recu.png')]]);
        FakeLandingAiClient::$queue[] = $this->toolResponse('lire_note_de_frais', ['merchant' => 'Station Essence', 'date' => $today->format('Y-m-d'), 'currency' => 'CAD', 'amount' => 6500, 'taxes' => [['label' => 'TPS', 'rate' => 5, 'amount' => 283]], 'kind' => 'déplacement', 'description' => 'Essence pour la tournée', 'confidence' => ['overall' => 0.9, 'amount' => 0.95, 'date' => 0.9], 'notes' => []]);
        $this->runWorker((int) $note['id']);
        $xn = $this->get('/api/gestion/documents/' . $note['id'])['extraction'];
        $this->assertSame([6500, $type->getId(), 'Déplacement', 'Essence pour la tournée'], [$xn['amount'], $xn['typeNoteDeFrais']['id'], $xn['typeNoteDeFrais']['name'], $xn['description']], 'type proposé par son nom');
        $confirmedNote = $this->post('/api/gestion/documents/' . $note['id'] . '/confirmer', ['montant' => 6500, 'date' => $today->format('Y-m-d'), 'description' => 'Essence', 'typeNoteDeFraisId' => $type->getId()], 201);
        $this->assertSame([6500, 'Essence', (int) $note['id'], $today->format('Y-m-d')], [$confirmedNote['noteDeFrais']['montant'], $confirmedNote['noteDeFrais']['description'], $confirmedNote['noteDeFrais']['document']['id'], $confirmedNote['noteDeFrais']['document']['invoiceDate']]);
        $this->assertSame((int) $confirmedNote['noteDeFrais']['id'], (int) $conn->fetchOne('SELECT note_de_frais_id FROM gestion_document WHERE id = ?', [$note['id']]));

        // Facture de transport (PDF de 2 pages) pour une commande du jour
        $supplierId = (int) $this->post('/api/fournisseurs', ['name' => "Fournisseur $tag", 'typeFournisseur' => ($this->em()->find(TypeFournisseur::class, 1) ?? $this->create(TypeFournisseur::class, ['name' => 'Autre']))->getId()], 201)['id'];
        $commande = $this->post('/api/commandes', ['budget' => 50000, 'date' => $today->format('Y-m-d') . ' 10:00:00', 'name' => "Commande $tag", 'fournisseur' => ['id' => $supplierId]], 201);
        [$status, $transport] = $this->multipartBody('/api/gestion/documents', ['type' => 'transport'], ['files' => [$this->pdf(2)]]);
        $this->assertSame([202, 'application/pdf', 2], [$status, $transport['files'][0]['mime'], $transport['files'][0]['pages']], 'PDF : pages comptées');
        FakeLandingAiClient::$queue[] = $this->toolResponse('lire_facture_transport', ['carrierName' => "Purolator $tag", 'invoiceNumber' => "T-$tag", 'invoiceDate' => $today->format('Y-m-d'), 'currency' => 'CAD', 'trackingNumber' => "PUR$tag", 'amountsMode' => 'ht', 'amount' => 1500, 'taxes' => [['label' => 'TPS', 'rate' => 5, 'amount' => 75]], 'subtotal' => 1500, 'taxTotal' => 75, 'total' => 1575, 'confidence' => ['overall' => 0.9], 'notes' => []]);
        $this->runWorker((int) $transport['id']);
        $xt = $this->get('/api/gestion/documents/' . $transport['id'])['extraction'];
        $this->assertSame([$carrier->getId(), 1500, "PUR$tag", 0, 'document' === 'document'], [$xt['carrier']['id'], $xt['amount'], $xt['trackingNumber'], $xt['computed']['difference'], FakeLandingAiClient::$requests[count(FakeLandingAiClient::$requests) - 1]['messages'][0]['content'][1]['type'] === 'document'], 'transporteur reconnu, PDF envoyé en bloc document');
        $this->assertSame((int) $commande['id'], $xt['commande']['id'] ?? null, 'commande du jour sans frais de port suggérée');
        $tr = $this->post('/api/gestion/documents/' . $transport['id'] . '/confirmer', ['commandeId' => $commande['id'], 'transporteurId' => $carrier->getId(), 'price' => 1500, 'tracknumber' => "PUR$tag"], 201);
        $this->assertSame([1500, "PUR$tag", "T-$tag", (int) $transport['id']], [$tr['fraisDePort']['price'], $tr['fraisDePort']['tracknumber'], $tr['fraisDePort']['facture'], $tr['fraisDePort']['document']['id']]);
        $this->assertSame((int) $transport['id'], $this->get('/api/commandes/' . $commande['id'] . '/frais-de-port')['document']['id'], 'justificatif sur le frais de port');
        // une seconde facture de transport sur la même commande : refusée (un frais de port par commande)
        [, $transport2] = $this->multipartBody('/api/gestion/documents', ['type' => 'transport'], ['files' => [$this->image('t2.png')]]);
        FakeLandingAiClient::$queue[] = $this->toolResponse('lire_facture_transport', ['carrierName' => "Purolator $tag", 'invoiceNumber' => "T-$tag-2", 'amount' => 100, 'amountsMode' => 'ht', 'confidence' => ['overall' => 0.5]]);
        $this->runWorker((int) $transport2['id']);
        $this->request('POST', '/api/gestion/documents/' . $transport2['id'] . '/confirmer', ['commandeId' => $commande['id'], 'transporteurId' => $carrier->getId(), 'price' => 100]);
        $this->assertSame([409, 'SHIPPING_COST_ALREADY_SET'], [$this->client->getResponse()->getStatusCode(), $this->body()['error']]);

        // Échec de lecture : failed sans crédit retenu, relecture → extrait ; liste et suppression
        [, $failing] = $this->multipartBody('/api/gestion/documents', ['type' => 'note'], ['files' => [$this->image('flou.png')]]);
        $usageId = (int) $conn->fetchOne('SELECT ai_usage_id FROM gestion_document WHERE id = ?', [$failing['id']]);
        FakeLandingAiClient::$queue[] = new \RuntimeException('réseau');
        $this->runWorker((int) $failing['id']);
        $failed = $this->get('/api/gestion/documents/' . $failing['id']);
        $this->assertSame(['failed', 'AI_UNAVAILABLE'], [$failed['status'], $failed['error']]);
        $this->assertNotSame('success', $conn->fetchOne('SELECT status FROM ai_usage WHERE id = ?', [$usageId]), 'crédit libéré');
        $this->request('POST', '/api/gestion/documents/' . $failing['id'] . '/relire');
        $this->assertSame([202, 'reading'], [$this->client->getResponse()->getStatusCode(), $this->body()['status']]);
        FakeLandingAiClient::$queue[] = $this->toolResponse('lire_note_de_frais', ['merchant' => 'Resto', 'date' => $today->format('Y-m-d'), 'amount' => 2400, 'kind' => 'repas', 'description' => 'Repas', 'confidence' => ['overall' => 0.7]]);
        $this->runWorker((int) $failing['id']);
        $this->assertSame('extracted', $this->get('/api/gestion/documents/' . $failing['id'])['status']);
        $this->request('GET', '/api/gestion/documents?type=note&status=extracted&limit=5');
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertGreaterThanOrEqual(1, (int) $this->client->getResponse()->headers->get('X-Total-Count'));
        $this->assertArrayNotHasKey('extraction', $this->body()[0], 'liste sans le détail');
        // Expiration : 410 puis purge paresseuse (fichiers compris)
        $conn->executeStatement("UPDATE gestion_document SET expires_at = NOW() - INTERVAL '1 day' WHERE id = ?", [$failing['id']]);
        $this->request('PATCH', '/api/gestion/documents/' . $failing['id'] . '/brouillon', ['x' => 1]);
        $this->assertSame([410, 'DOCUMENT_EXPIRED'], [$this->client->getResponse()->getStatusCode(), $this->body()['error']]);
        $dir = static::getContainer()->get(\App\Services\GestionDocumentService\GestionDocumentStorage::class)->directory($this->em()->find(GestionDocument::class, (int) $failing['id']));
        $this->assertDirectoryExists($dir);
        $this->get('/api/gestion/documents');
        $this->assertSame(0, (int) $conn->fetchOne('SELECT COUNT(*) FROM gestion_document WHERE id = ?', [$failing['id']]), 'purgé à la liste suivante');
        $this->assertDirectoryDoesNotExist($dir);
        // Suppression d'un document non confirmé
        $this->request('DELETE', '/api/gestion/documents/' . $transport2['id']);
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
    }

    // ----- fabrique -----

    private function runWorker(int $documentId): void
    {
        static::getContainer()->get(ReadGestionDocumentHandler::class)(new ReadGestionDocumentMessage($documentId, MV_TEST_TENANT_CODE));
        $this->em()->clear();
        $this->endOfWrite();
    }

    private function toolResponse(string $tool, array $input): object
    {
        return json_decode(json_encode([
            'id' => 'msg_' . bin2hex(random_bytes(4)), 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-sonnet-5-5', 'stop_reason' => 'tool_use',
            'content' => [['type' => 'tool_use', 'id' => 'toolu_' . bin2hex(random_bytes(4)), 'name' => $tool, 'input' => $input]],
            'usage' => ['input_tokens' => 4000, 'output_tokens' => 600, 'cache_read_input_tokens' => 0, 'cache_creation_input_tokens' => 0],
        ]), false);
    }

    private function image(string $name): UploadedFile
    {
        $tmp = tempnam(sys_get_temp_dir(), 'img');
        copy(__DIR__ . '/../../../public/assets/arkanoa-favicon.png', $tmp);

        return new UploadedFile($tmp, $name, 'image/png', null, true);
    }

    /** PDF minimal (en-tête valide, n pages déclarées) */
    private function pdf(int $pages): UploadedFile
    {
        $tmp = tempnam(sys_get_temp_dir(), 'pdf');
        $body = "%PDF-1.4\n1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n2 0 obj << /Type /Pages /Count $pages >> endobj\n";
        for ($i = 0; $i < $pages; $i++) {
            $body .= (3 + $i) . " 0 obj << /Type /Page /Parent 2 0 R >> endobj\n";
        }
        file_put_contents($tmp, $body . "trailer << /Root 1 0 R >>\n%%EOF\n");

        return new UploadedFile($tmp, 'facture.pdf', 'application/pdf', null, true);
    }

    // ----- helpers -----

    /** @return array{0: int, 1: array} statut et corps */
    private function multipartBody(string $route, array $fields, array $files): array
    {
        $this->client->request('POST', $route, $fields, $files, ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $this->token]);

        return [$this->client->getResponse()->getStatusCode(), $this->body()];
    }

    /** @return array{0: int, 1: mixed} statut et champ du corps (fields par défaut) */
    private function multipart(string $route, array $fields, array $files, string $key = 'fields'): array
    {
        [$status, $body] = $this->multipartBody($route, $fields, $files);

        return [$status, $body[$key] ?? $body];
    }

    private function body(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    private function headers(): array
    {
        return ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $this->token];
    }

    private function request(string $method, string $route, ?array $body = null): void
    {
        $this->client->request($method, $route, [], [], $this->headers(), $body === null ? null : json_encode($body));
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
        $this->assertSame($expected, $this->client->getResponse()->getStatusCode(), $route . ' : ' . substr((string) $this->client->getResponse()->getContent(), 0, 400));

        return $this->body();
    }

    private function patch(string $route, array $body): array
    {
        $this->request('PATCH', $route, $body);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), $route . ' : ' . substr((string) $this->client->getResponse()->getContent(), 0, 300));

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

    private function login(): void
    {
        $email = 'gestion-l1-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $em = $this->em();
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Gestion')->setLastname('L1')->setRoles(['ROLE_ADMIN'])->setIsVerified(true)->setPassword('inutilisable');
        $em->persist($user);
        $em->flush();
        $this->token = static::getContainer()->get('lexik_jwt_authentication.jwt_manager')->create($user);
        $this->endOfWrite();
    }
}
