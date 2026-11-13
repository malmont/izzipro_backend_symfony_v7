<?php

namespace App\Tests\Functional\Gestion;

use App\Entity\Commande;
use App\Entity\Fournisseur;
use App\Gestion\Entity\GestionDocument;
use App\Entity\Product;
use App\Entity\Transporteur;
use App\Entity\TypeFournisseur;
use App\Entity\TypeNoteDeFrais;
use App\Entity\User;
use App\EventSubscriber\CacheInvalidationSubscriber;
use App\Gestion\MessageHandler\ReadGestionDocumentHandler;
use App\Gestion\Message\ReadGestionDocumentMessage;
use App\Gestion\Services\Document\GestionDocumentReader;
use App\Gestion\Services\PeriodCalendar;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use App\Tests\Functional\Support\AiRightTestHelper;
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
        AiRightTestHelper::restoreFullAi(static::getContainer()); // droits partagés : un test précédent peut les avoir vidés
        $this->login();
        $this->em()->getConnection()->executeStatement("UPDATE gestion_document SET status = 'failed' WHERE status = 'reading'"); // base partagée : une lecture à la fois par site
        $this->em()->getConnection()->executeStatement('UPDATE entreprise SET gestion_price_rounding = NULL, gestion_default_coefficient = NULL, gestion_min_coefficient = NULL'); // réglages de prix par défaut du site
    }

    protected function tearDown(): void
    {
        $this->em()->getConnection()->executeStatement('UPDATE entreprise SET gestion_price_rounding = NULL');
        parent::tearDown();
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
        $this->assertSame('Lecture de document (note, 1 fichier)', $conn->fetchOne('SELECT prompt_excerpt FROM ai_usage WHERE id = ?', [$usageId]), 'libellé du journal d\'IA, accordé avec le nombre de fichiers (13/11/2026)');
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

    public function testInvoiceWithShippingAndSubtotalWrittenBeforeShippingHasNoFalseGap(): void
    {
        // Retour du 11/11/2026 : lignes 378,00 + livraison 20,00 ; sous-total écrit avant la livraison ; TPS 19,90 + TVQ 39,70 ; total 457,60
        [$status, $doc] = $this->multipartBody('/api/gestion/documents', ['type' => 'facture'], ['files' => [$this->image('nord.png')]]);
        $this->assertSame(202, $status);
        FakeLandingAiClient::$queue[] = $this->toolResponse('lire_facture_fournisseur', [
            'supplierName' => 'Atelier Nord Textile', 'invoiceNumber' => 'AN-1', 'invoiceDate' => '', 'dueDate' => '', 'currency' => '', 'amountsMode' => 'ht',
            'lines' => [['baseLabel' => 'Parka', 'size' => '', 'color' => '', 'supplierCode' => '', 'quantity' => 1, 'unitCost' => 37800, 'total' => 37800, 'taxable' => true]],
            'taxes' => [['label' => 'TPS', 'rate' => 5, 'amount' => 1990], ['label' => 'TVQ', 'rate' => 9.975, 'amount' => 3970]],
            'shipping' => ['label' => 'Livraison', 'amount' => 2000], 'subtotal' => 37800, 'taxTotal' => null, 'total' => 45760,
            'confidence' => ['overall' => 0.9, 'number' => 0.9, 'date' => null, 'lines' => 0.9, 'totals' => 0.9], 'notes' => [],
        ]);
        $this->runWorker((int) $doc['id']);
        $request = FakeLandingAiClient::$requests[count(FakeLandingAiClient::$requests) - 1];
        $this->assertLessThanOrEqual(FakeLandingAiClient::MAX_UNION_PARAMETERS, FakeLandingAiClient::unionParameters($request['output_config']['format']['schema']), 'limite de l\'API sur les unions');
        $x = $this->get('/api/gestion/documents/' . $doc['id'])['extraction'];
        $this->assertSame([37800, 2000, 0.14975, 45760, 0], [$x['computed']['linesTotal'], $x['computed']['shipping'], $x['computed']['taxRate'], $x['computed']['total'], $x['computed']['difference']], 'taux sur total − taxes : la livraison est dans la base');
        $this->assertSame([null, null, null, null, 'Livraison'], [$x['invoice']['date'], $x['invoice']['dueDate'], $x['invoice']['currency'], $x['lines'][0]['size'], $x['shipping']['label']], 'chaîne vide = inconnu');
        // Sans livraison, l'objet est toujours présent avec un montant null : aucun frais de port
        [, $doc2] = $this->multipartBody('/api/gestion/documents', ['type' => 'facture'], ['files' => [$this->image('nord2.png')]]);
        FakeLandingAiClient::$queue[] = $this->toolResponse('lire_facture_fournisseur', ['supplierName' => 'Atelier Nord Textile', 'amountsMode' => 'ht', 'lines' => [], 'taxes' => [], 'shipping' => ['label' => '', 'amount' => null], 'confidence' => ['overall' => 0.5], 'notes' => []]);
        $this->runWorker((int) $doc2['id']);
        $this->assertNull($this->get('/api/gestion/documents/' . $doc2['id'])['extraction']['shipping']);
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
        $this->assertSame('Lecture de document (facture, 2 fichiers)', $conn->fetchOne('SELECT u.prompt_excerpt FROM ai_usage u JOIN gestion_document d ON d.ai_usage_id = u.id WHERE d.id = ?', [$doc['id']]), 'deux pages : pluriel');
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
        $this->assertSame(['json_schema', false], [$request['output_config']['format']['type'], array_key_exists('tool_choice', $request)], 'sortie structurée, aucun outil imposé (refusé par l\'API depuis Sonnet 5.5)');
        $this->assertSame(false, $request['output_config']['format']['schema']['additionalProperties']);
        $this->assertArrayHasKey('lines', $request['output_config']['format']['schema']['properties']);
        $this->assertStringNotContainsString('"minimum"', json_encode($request['output_config']['format']['schema']), 'contraintes numériques non prises en charge');
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
        // Réassort (lot L0) : la tuque est déjà achetée dans une autre commande ; la facture lui ajoute une ligne, sans refus
        $conn->executeStatement("INSERT INTO commande (id, budget, date, name, is_closed) VALUES (nextval('commande_id_seq'), 1000, NOW(), :n, false)", ['n' => "Achat précédent $tag"]);
        $previous = (int) $conn->fetchOne('SELECT id FROM commande WHERE name = ?', ["Achat précédent $tag"]);
        $conn->executeStatement("INSERT INTO commande_line (commande_id, product_id, quantity_ordered, quantity_received, unit_cost_cents, attached_at, source, created_at, updated_at) VALUES (?, ?, 5, 5, 900, NOW() - INTERVAL '1 day', 'manual', NOW(), NOW())", [$previous, $tuque]);
        $this->endOfWrite();
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
        $robe = $conn->fetchAssociative('SELECT name, price, purchase_price, coefficient_multiplier, quantity, is_web, is_pos, commande_id, image FROM product WHERE id = ?', [$robeId]);
        $this->assertSame(["Robe en lin $tag", 5099.0, 2000.0, 2.5, 0, false, false, $commande['id']], [$robe['name'], (float) $robe['price'], (float) $robe['purchase_price'], (float) $robe['coefficient_multiplier'], (int) $robe['quantity'], (bool) $robe['is_web'], (bool) $robe['is_pos'], (int) $robe['commande_id']], 'article créé : prix = achat × coefficient (50,00), arrondi par défaut du site (lot IA-ACT-5) : 50,99 ; STOCK 0 (il n\'entre qu\'à la réception), invisible');
        $this->assertSame([0, 0], array_map('intval', $conn->fetchFirstColumn('SELECT stock_quantity FROM product_variant WHERE product_id = ? ORDER BY id', [$robeId])), 'variantes à stock 0');
        $byProduct = [];
        foreach ($confirmed['lines'] as $l) {
            $byProduct[$l['productId']][] = [$l['variant'] !== null, $l['quantityOrdered'], $l['quantityReceived'], $l['remaining'], $l['unitCost'], $l['source']];
        }
        $this->assertSame([[true, 2, 0, 2, 2000, 'document'], [true, 1, 0, 1, 2000, 'document']], $byProduct[$robeId], 'une ligne par variante, à recevoir');
        $this->assertSame([[false, 3, 0, 3, 1000, 'document']], $byProduct[$tuque], 'réassort : une ligne de plus, à recevoir');
        $this->assertSame(2, (int) $conn->fetchOne('SELECT COUNT(*) FROM commande_line WHERE product_id = ?', [$tuque]), 'la tuque a désormais deux achats');
        $this->assertSame('none', $confirmed['commande']['receptionStatus'], 'rien reçu');
        // « Marquer comme reçue maintenant » (lot R1) : le stock n'entre qu'ici
        $received = $this->post('/api/commandes/' . $commande['id'] . '/reception', [], 200);
        $this->assertSame([3, [], 'complete'], [count($received['received']), $received['needsVariant'], $received['commande']['receptionStatus']]);
        $this->assertSame([2, 1], array_map('intval', $conn->fetchFirstColumn('SELECT stock_quantity FROM product_variant WHERE product_id = ? ORDER BY id', [$robeId])), 'robe : S 2, M 1');
        $this->assertSame([3, (int) $tuqueBefore['quantity'] + 3], [(int) $conn->fetchOne('SELECT quantity FROM product WHERE id = ?', [$robeId]), (int) $conn->fetchOne('SELECT quantity FROM product WHERE id = ?', [$tuque])], 'stocks reçus');
        $tuqueBefore['quantity'] = $conn->fetchOne('SELECT quantity FROM product WHERE id = ?', [$tuque]); // la suite compare la fiche hors stock
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
        $this->assertNotContains(GestionDocumentReader::NOTE_AMOUNTS_UNKNOWN, $x2['notes'], 'montants lus hors taxes : pas d\'avertissement');
        $this->assertStringContainsString('notes est lu tel quel par le commerçant', FakeLandingAiClient::$requests[count(FakeLandingAiClient::$requests) - 1]['system'], 'remarques destinées au commerçant');
        $this->assertSame([900, 900, 'ht'], [$x2['lines'][0]['unitCost'], $x2['lines'][0]['unitCostRead'], $x2['amountsMode']]);
        $dupBody = ['supplier' => ['id' => $supplier->getId()], 'invoice' => ['number' => "F-$tag", 'date' => $today->format('Y-m-d')], 'lines' => [['n' => 1, 'quantity' => 1, 'unitCost' => 900, 'create' => ['name' => "Ceinture $tag"]]]];
        $this->request('POST', '/api/gestion/documents/' . $doc2['id'] . '/confirmer', $dupBody);
        $this->assertSame([409, 'INVOICE_ALREADY_CONFIRMED', $id], [$this->client->getResponse()->getStatusCode(), $this->body()['error'], $this->body()['documentId']]);
        $forced = $this->post('/api/gestion/documents/' . $doc2['id'] . '/confirmer', $dupBody + ['force' => true], 201);
        $this->assertSame([900, 'Ceinture ' . $tag], [$forced['commande']['budget'], $forced['created'][0]['name']]);
        $this->assertSame(0, (int) $conn->fetchOne('SELECT COUNT(*) FROM product_variant v JOIN product p ON p.id = v.product_id WHERE p.id = ?', [$forced['created'][0]['productId']]), 'ligne seule sans taille : pas de variante');
    }

    /**
     * Lot APERÇU-FACT (12/11/2026) : POST /api/gestion/documents/{id}/apercu rend, sans rien écrire, ce que donnerait la confirmation
     * de la facture telle que le gérant l'a corrigée. Facture en EUR sur un site en CAD : le taux entre dans le coût converti, le prix
     * de vente prévu et la marge ; l'aperçu puis la confirmation du même corps donnent le même prix à chaque article créé (groupe à
     * variantes compris) ; la ligne d'achat garde son coût en EUR. Totaux sur les lignes corrigées, refus de la confirmation en `issues`.
     */
    public function testInvoicePreviewGivesThePricesOfTheConfirmationAndWritesNothing(): void
    {
        $tag = bin2hex(random_bytes(2));
        $today = static::getContainer()->get(PeriodCalendar::class)->today();
        $typeOf = fn (EntityManagerInterface $em) => $em->find(TypeFournisseur::class, 1) ?? $this->create(TypeFournisseur::class, ['name' => 'Autre']);
        $euro = $this->create(Fournisseur::class, ['name' => "Maison Lyon $tag"], fn (Fournisseur $f, EntityManagerInterface $em) => $f->setTypeFournisseur($typeOf($em))->setCurrency('eur'));
        $local = $this->create(Fournisseur::class, ['name' => "Atelier local $tag"], fn (Fournisseur $f, EntityManagerInterface $em) => $f->setTypeFournisseur($typeOf($em)));
        $conn = $this->em()->getConnection();
        foreach (['tuque' => 2500, 'autre' => 900] as $slug => $price) {
            $conn->executeStatement("INSERT INTO product (id, name, price, isbestseller, quantity, created_at, slug, product_type) VALUES (nextval('product_id_seq'), :n, :p, false, 5, NOW(), :s, 'standard')", ['n' => ucfirst($slug) . " $tag", 'p' => $price, 's' => "$slug-$tag"]);
        }
        $tuque = (int) $conn->fetchOne('SELECT id FROM product WHERE slug = ?', ["tuque-$tag"]);
        $other = (int) $conn->fetchOne('SELECT id FROM product WHERE slug = ?', ["autre-$tag"]);
        $em = $this->em();
        $variant = (new \App\Entity\ProductVariant())->setStockQuantity(0)->setPrice(2700);
        $variant->setProduct($em->find(Product::class, $tuque));
        $em->persist($variant);
        $em->flush();
        $variantId = (int) $variant->getId();
        $this->endOfWrite();
        $conn = $this->em()->getConnection();

        // Facture lue : EUR, hors taxes, 5 lignes (10 899) + port 500, TVA 2 280, total 13 679 → écart 0 à la lecture
        [, $doc] = $this->multipartBody('/api/gestion/documents', ['type' => 'facture'], ['files' => [$this->image('lyon.png')]]);
        $id = (int) $doc['id'];
        $this->request('POST', "/api/gestion/documents/$id/apercu", ['lines' => [['n' => 1, 'quantity' => 1, 'unitCost' => 100]]]);
        $this->assertSame([409, 'DOCUMENT_IN_PROGRESS'], [$this->client->getResponse()->getStatusCode(), $this->body()['error']], 'lecture en cours');
        FakeLandingAiClient::$queue[] = $this->toolResponse('lire_facture_fournisseur', [
            'supplierName' => "Maison Lyon $tag", 'invoiceNumber' => "L-$tag", 'invoiceDate' => $today->format('Y-m-d'), 'currency' => 'EUR', 'amountsMode' => 'ht',
            'lines' => [
                ['baseLabel' => 'Robe en lin', 'size' => 'S', 'quantity' => 2, 'unitCost' => 2000, 'total' => 4000],
                ['baseLabel' => 'Robe en lin', 'size' => 'M', 'quantity' => 1, 'unitCost' => 2000, 'total' => 2000],
                ['baseLabel' => 'Tuque', 'quantity' => 3, 'unitCost' => 1000, 'total' => 3000],
                ['baseLabel' => 'Ceinture', 'quantity' => 1, 'unitCost' => 999, 'total' => 999],
                ['baseLabel' => 'Gants', 'quantity' => 2, 'unitCost' => 450, 'total' => 900],
            ],
            'taxes' => [['label' => 'TVA', 'rate' => 20, 'amount' => 2280]], 'shipping' => ['label' => 'Port', 'amount' => 500], 'subtotal' => 10899, 'taxTotal' => 2280, 'total' => 13679,
            'confidence' => ['overall' => 0.9], 'notes' => [],
        ]);
        $this->runWorker($id);
        $x = $this->get("/api/gestion/documents/$id")['extraction'];
        $this->assertSame([10899, 13679, 0, 'EUR'], [$x['computed']['linesTotal'], $x['computed']['total'], $x['computed']['difference'], $x['invoice']['currency']]);

        $audit = $this->em()->getClassMetadata(\App\Entity\ContentAuditLog::class)->getTableName();
        $state = fn (): array => $conn->fetchAssociative(
            "SELECT (SELECT COUNT(*) FROM product) AS products, (SELECT COUNT(*) FROM product_variant) AS variants, (SELECT COUNT(*) FROM commande) AS commandes, (SELECT COUNT(*) FROM commande_line) AS lines,"
            . " (SELECT COUNT(*) FROM fournisseur) AS suppliers, (SELECT COUNT(*) FROM frais_de_port) AS shipping, (SELECT COUNT(*) FROM $audit) AS audit,"
            . " (SELECT md5(CAST(row_to_json(d) AS text)) FROM gestion_document d WHERE d.id = ?) AS document,"
            . " (SELECT md5(string_agg(CAST(price AS text) || '/' || COALESCE(CAST(purchase_price AS text), ''), ',' ORDER BY id)) FROM product) AS prices", [$id]);
        $before = $state();
        $preview = fn (array $body): array => $this->post("/api/gestion/documents/$id/apercu", $body, 200);
        $issues = fn (array $r): array => array_column($r['issues'], 'code', 'path');
        $pick = fn (array $line, array $keys): array => array_map(fn ($k) => $line[$k], $keys);

        // Lignes telles que lues, sans fournisseur : devise lue (EUR), taux manquant → signalé, aucun prix ; totaux = ceux de la lecture
        $asRead = array_map(fn ($l) => ['n' => $l['n'], 'quantity' => $l['quantity'], 'unitCost' => $l['unitCost'], 'create' => ['name' => $l['baseLabel'], 'groupKey' => $l['groupKey']]], $x['lines']);
        $r = $preview(['lines' => $asRead, 'shipping' => ['amount' => 500]]);
        $this->assertSame(['CAD', 'EUR', null, false], [$r['currency'], $r['invoiceCurrency'], $r['exchangeRate'], $r['pricesIncludeTax']]);
        $this->assertSame(['exchangeRate' => 'EXCHANGE_RATE_REQUIRED'], $issues($r));
        $this->assertSame([[null, null, null, null, 'create', 4000]], array_values(array_unique(array_map(fn ($l) => $pick($l, ['unitCostConverted', 'plannedPrice', 'plannedMargin', 'plannedMarginRate', 'kind', 'total']), array_slice($r['lines'], 0, 1)), SORT_REGULAR)), 'sans taux : ni coût converti, ni prix');
        $this->assertSame(['lines' => 10899, 'shipping' => 500, 'taxRate' => $x['computed']['taxRate'], 'taxes' => 2280, 'total' => 13679, 'read' => ['subtotal' => 10899, 'taxes' => 2280, 'total' => 13679], 'difference' => 0], $r['totals'], 'lignes lues : exactement extraction.computed');

        // Taux de taxe exact de la lecture (2280 ÷ 11399), pas son arrondi affiché (0,20002 donnerait 400 040 sur une grosse facture)
        $this->assertSame(400035, $preview(['lines' => [['n' => 1, 'quantity' => 1000, 'unitCost' => 2000, 'create' => ['name' => 'Gros lot']]]])['totals']['taxes']);

        // Lignes corrigées par le gérant, fournisseur en EUR, taux 1,48
        $lines = [
            ['n' => 1, 'quantity' => 2, 'unitCost' => 2000, 'size' => 'S', 'create' => ['name' => "Robe $tag", 'coefficient' => 2.5, 'groupKey' => 'robe']],
            ['n' => 2, 'quantity' => 1, 'unitCost' => 2300, 'size' => 'M', 'create' => ['name' => "Robe $tag", 'groupKey' => 'robe']],
            ['n' => 3, 'quantity' => 3, 'unitCost' => 1000, 'productId' => $tuque, 'variantId' => $variantId],
            ['n' => 4, 'quantity' => 1, 'unitCost' => 999, 'create' => ['name' => "Ceinture $tag"]],
        ];
        $body = ['supplier' => ['id' => $euro->getId()], 'invoice' => ['number' => "L-$tag", 'date' => $today->format('Y-m-d'), 'exchangeRate' => 1.48], 'classificationDate' => $today->format('Y-m-d'), 'lines' => $lines, 'shipping' => ['amount' => 500], 'champEnTrop' => true];
        $r = $preview(['lines' => array_merge($lines, [['n' => 5, 'quantity' => 2, 'unitCost' => 450]])] + $body);
        $this->assertSame(['CAD', 'EUR', 1.48], [$r['currency'], $r['invoiceCurrency'], $r['exchangeRate']]);
        $keys = ['n', 'quantity', 'unitCost', 'total', 'unitCostConverted', 'kind', 'coefficient', 'plannedPrice', 'currentPrice', 'plannedMargin', 'plannedMarginRate', 'groupKey', 'publishPos', 'publishWeb', 'mentions', 'price']; // quatre clés de plus au lot IA-ACT-5
        $this->assertSame($keys, array_keys($r['lines'][0]));
        $this->assertSame([
            // groupe « robe » : coût moyen (4000 + 2300) ÷ 3 = 2100 EUR → 3108 CAD, × 2,5 (coefficient de la première ligne) = 7770, arrondi par
            // défaut du site (lot IA-ACT-5, prix psychologique) : 7799, sur les deux lignes ; la marge et son taux suivent le prix arrondi
            // lot IA-ACT-5, quatre valeurs de plus par ligne : mise en ligne en caisse, sur la boutique, mentions, prix avant / après d'un article existant
            [1, 2, 2000, 4000, 2960, 'create', 2.5, 7799, null, 4839, 62, 'robe', 'no', 'no', [], null],
            [2, 1, 2300, 2300, 3404, 'create', 2.5, 7799, null, 4395, 56.4, 'robe', 'no', 'no', [], null],
            [3, 3, 1000, 3000, 1480, 'existing', null, null, 2700, null, null, null, null, null, [], // prix actuel de la variante ; sans décision, la confirmation ne le change pas
                ['mode' => 'keep', 'coefficient' => null, 'newPrice' => 2700, 'currentPrice' => 2700, 'changed' => false, 'belowCost' => false]],
            [4, 1, 999, 999, 1479, 'create', 2, 2999, null, 1520, 50.7, null, 'no', 'no', [], null], // coefficient par défaut du site, 2 (JSON : 2.0 s'écrit 2) : 29,58 → 29,99
            [5, 2, 450, 900, 666, 'unresolved', null, null, null, null, null, null, null, null, [], null],
        ], array_map('array_values', $r['lines']), 'taux de marge à une décimale');
        // Lot IA-ACT-5 : une décision de prix portée par la ligne d'un article existant (coût 14,80 × 2 = 29,60 → 29,99) et une mise en ligne
        // se lisent dans l'aperçu ; sans arrondi (réglage « none » du site), les prix sont au cent : 77,70 et 29,58
        $decided = $lines;
        $decided[0]['create'] += ['publishPos' => 'now', 'publishWeb' => 'reception'];
        $decided[2]['price'] = ['mode' => 'coefficient', 'coefficient' => 2];
        $rounded = $preview(['lines' => $decided] + $body);
        $this->assertSame([[7799, 4839], [7799, 4395], [2999, 1519], [2999, 1520]], array_map(fn (array $l) => [$l['plannedPrice'], $l['plannedMargin']], $rounded['lines']));
        $this->assertSame([['now', 'reception', ['STOCK_ZERO', 'NO_PHOTO']], ['now', 'reception', ['STOCK_ZERO', 'NO_PHOTO']], [null, null, []], ['no', 'no', []]], array_map(fn (array $l) => [$l['publishPos'], $l['publishWeb'], $l['mentions']], $rounded['lines']), 'la mise en ligne d\'un groupe est celle de sa première ligne qui la demande');
        $this->assertSame(['mode' => 'coefficient', 'coefficient' => 2, 'newPrice' => 2999, 'currentPrice' => 2700, 'changed' => true, 'belowCost' => false], $rounded['lines'][2]['price']);
        $this->assertSame([], $issues($rounded));
        $bad = $lines;
        $bad[0]['create']['publishWeb'] = 'un jour';
        $bad[2]['price'] = ['mode' => 'gratuit'];
        $bad[3]['create']['publishPos'] = 1;
        $this->assertSame(['lines[1].create.publishWeb' => 'INVALID_VALUE', 'lines[3].price.mode' => 'INVALID_MODE', 'lines[4].create.publishPos' => 'INVALID_VALUE'], array_intersect_key($issues($preview(['lines' => $bad] + $body)), array_flip(['lines[1].create.publishWeb', 'lines[3].price.mode', 'lines[4].create.publishPos'])));
        $this->assertSame(['lines[3].price.coefficient' => 'REQUIRED'], array_intersect_key($issues($preview(['lines' => array_replace($lines, [2 => $lines[2] + ['price' => ['mode' => 'coefficient']]])] + $body)), ['lines[3].price.coefficient' => 1]));
        $this->assertSame(['lines[3].price.price' => 'INVALID_AMOUNT'], array_intersect_key($issues($preview(['lines' => array_replace($lines, [2 => $lines[2] + ['price' => ['mode' => 'fixed', 'price' => 0]]])] + $body)), ['lines[3].price.price' => 1]));
        $this->em()->getConnection()->executeStatement("UPDATE entreprise SET gestion_price_rounding = 'none'");
        $this->endOfWrite();
        $this->assertSame([7770, 7770, null, 2958], array_column($preview(['lines' => $lines] + $body)['lines'], 'plannedPrice'), 'réglage « sans arrondi » : le prix est coût × coefficient, au cent');
        // Le coefficient par défaut du SITE vaut pour une ligne créée sans coefficient (14,79 × 3 = 44,37), jamais pour une ligne qui a le sien
        $this->em()->getConnection()->executeStatement('UPDATE entreprise SET gestion_default_coefficient = 3');
        $this->endOfWrite();
        $siteDefault = $preview(['lines' => $lines] + $body)['lines'];
        $this->assertSame([[2.5, 7770], [3, 4437]], [[$siteDefault[0]['coefficient'], $siteDefault[0]['plannedPrice']], [$siteDefault[3]['coefficient'], $siteDefault[3]['plannedPrice']]]);
        $this->em()->getConnection()->executeStatement('UPDATE entreprise SET gestion_price_rounding = NULL, gestion_default_coefficient = NULL');
        $this->endOfWrite();
        $this->assertSame(['lines[5]' => 'LINE_UNRESOLVED'], $issues($r), 'le refus de la confirmation, sans bloquer l\'aperçu');
        $this->assertSame(['lines' => 11199, 'shipping' => 500, 'taxes' => 2340, 'total' => 14039, 'difference' => -360], array_intersect_key($r['totals'], array_flip(['lines', 'shipping', 'taxes', 'total', 'difference'])), 'totaux des lignes corrigées, en EUR, au taux de taxe du document ; écart = total lu − total recalculé');

        // Devise : celle du fournisseur du corps, comme à la confirmation
        $r = $preview(['supplier' => ['id' => $local->getId()]] + $body);
        $this->assertSame(['CAD', 1, 5299, 2000], [$r['invoiceCurrency'], $r['exchangeRate'], $r['lines'][0]['plannedPrice'], $r['lines'][0]['unitCostConverted']], 'fournisseur sans devise : devise du site, taux envoyé ignoré ; 21,00 × 2,5 = 52,50, arrondi par défaut du site : 52,99');
        $r = $preview(['supplier' => ['create' => ['name' => 'Nouveau', 'currency' => 'usd']], 'invoice' => ['exchangeRate' => 1.35]] + $body);
        $this->assertSame(['USD', 1.35, 2700], [$r['invoiceCurrency'], $r['exchangeRate'], $r['lines'][0]['unitCostConverted']]);
        $r = $preview(['supplier' => ['create' => ['name' => 'Nouveau', 'currency' => 'XXX']]] + $body);
        $this->assertSame(['EUR', ['supplier.create.currency' => 'INVALID_CURRENCY']], [$r['invoiceCurrency'], $issues($r)]);
        $r = $preview(['supplier' => ['id' => 99999999], 'invoice' => ['exchangeRate' => 'x']] + $body);
        $this->assertSame([null, ['invoice.exchangeRate' => 'MUST_BE_NUMBER', 'supplier.id' => 'FOURNISSEUR_NOT_FOUND', 'exchangeRate' => 'EXCHANGE_RATE_REQUIRED']], [$r['exchangeRate'], $issues($r)]);
        $r = $preview(['invoice' => ['exchangeRate' => 0]] + $body);
        $this->assertSame([null, ['exchangeRate' => 'EXCHANGE_RATE_REQUIRED']], [$r['exchangeRate'], $issues($r)], 'taux nul : requis');

        // Champs que la confirmation refuserait : signalés, le reste est calculé
        $r = $preview(['lines' => [
            ['n' => 1, 'quantity' => 0, 'unitCost' => 2000, 'create' => ['name' => 'Robe', 'groupKey' => 'robe']],
            ['n' => 2, 'quantity' => 1, 'unitCost' => 2300, 'create' => ['name' => 'Robe', 'groupKey' => 'robe']],
            ['n' => 3, 'quantity' => 1, 'unitCost' => 1000, 'productId' => 99999999],
            ['n' => 4, 'quantity' => 1, 'unitCost' => 1000, 'productId' => $other, 'variantId' => $variantId],
            ['n' => 5, 'quantity' => 1, 'unitCost' => 1000, 'productId' => $tuque, 'create' => ['name' => 'Les deux']],
            ['n' => 6, 'quantity' => 1, 'unitCost' => 1000, 'create' => ['name' => 'Coefficient nul', 'coefficient' => 0]],
            ['n' => 7, 'quantity' => 1, 'unitCost' => 12.5, 'create' => ['name' => 'Coût décimal']],
            ['n' => 8, 'quantity' => 4, 'unitCost' => 250, 'productId' => $tuque],
            ['n' => 9, 'quantity' => 1, 'unitCost' => 1000, 'create' => ['name' => 'Coefficient illisible', 'coefficient' => 'deux']],
            ['n' => 10, 'quantity' => 1, 'unitCost' => 1000, 'create' => ['name' => '']],
        ], 'shipping' => ['amount' => 'x']] + $body);
        $this->assertSame([
            'lines[1].quantity' => 'MUST_BE_POSITIVE', 'lines[5]' => 'PRODUCT_OR_CREATE', 'lines[6].create.coefficient' => 'MUST_BE_POSITIVE', 'lines[7].unitCost' => 'MUST_BE_INTEGER_CENTS',
            'lines[9].create.coefficient' => 'MUST_BE_NUMBER', 'lines[10].create.name' => 'REQUIRED', 'lines[3].productId' => 'PRODUCT_NOT_FOUND', 'lines[4].variantId' => 'VARIANT_NOT_IN_PRODUCT', 'shipping.amount' => 'MUST_BE_INTEGER_CENTS',
        ], $issues($r));
        $this->assertSame(
            [['create', null], ['create', null], ['existing', null], ['existing', null], ['unresolved', null], ['create', null], ['create', null], ['existing', 2500], ['create', null], ['create', null]],
            array_map(fn ($l) => [$l['kind'], $l['plannedPrice'] ?? $l['currentPrice']], $r['lines']),
            'article à créer dont une ligne est refusée (groupe compris) : aucun prix ; article existant sans variante : son prix'
        );
        $this->assertSame([0 + 2300 + 1000 + 1000 + 1000 + 1000 + 1000 + 1000 + 1000, 0], [$r['totals']['lines'], $r['totals']['shipping']], 'lignes au coût et à la quantité lisibles seulement');

        // Site qui vend taxes comprises : signalé (le prix prévu contient alors la taxe)
        $conn->executeStatement('UPDATE entreprise SET prices_include_tax = true');
        try {
            $this->assertTrue($preview($body)['pricesIncludeTax']);
        } finally {
            $conn->executeStatement('UPDATE entreprise SET prices_include_tax = false');
        }

        // Montants lus sans savoir s'ils sont hors taxes ou taxes comprises : aucune taxe recalculée, comme à la lecture
        $stored = (string) $conn->fetchOne('SELECT extraction FROM gestion_document WHERE id = ?', [$id]);
        $conn->executeStatement('UPDATE gestion_document SET extraction = ? WHERE id = ?', [json_encode(['amountsMode' => 'unknown'] + json_decode($stored, true)), $id]);
        $this->em()->clear();
        try {
            $unknown = $preview($body)['totals'];
            $this->assertSame([0, 10799, 13679 - 10799], [$unknown['taxes'], $unknown['total'], $unknown['difference']]);
        } finally {
            $conn->executeStatement('UPDATE gestion_document SET extraction = ? WHERE id = ?', [$stored, $id]);
            $this->em()->clear();
        }

        // Refus
        $this->request('POST', "/api/gestion/documents/$id/apercu", ['shipping' => ['amount' => 500]]);
        $this->assertSame([422, 'VALIDATION_FAILED', ['lines' => 'REQUIRED']], [$this->client->getResponse()->getStatusCode(), $this->body()['error'], $this->body()['fields']]);
        $this->client->request('POST', "/api/gestion/documents/$id/apercu", [], [], $this->headers(), 'pas du json');
        $this->assertSame([400, 'INVALID_JSON'], [$this->client->getResponse()->getStatusCode(), $this->body()['error']]);
        $this->request('POST', '/api/gestion/documents/99999999/apercu', $body);
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
        $this->assertSame($before, $state(), 'aucune écriture : ni article, ni commande, ni fournisseur, ni brouillon, ni journal');

        // Aperçu puis confirmation du même corps : mêmes prix
        $r = $preview($body);
        $this->assertSame([], $r['issues']);
        $confirmed = $this->post("/api/gestion/documents/$id/confirmer", $body, 201);
        $this->assertSame(['EUR', 1.48, 10799], [$confirmed['commande']['currency'], $confirmed['commande']['exchangeRate'], $confirmed['commande']['budget']], 'commande en EUR : lignes + port');
        $createdBy = array_column($confirmed['created'], 'productId', 'lineN');
        $this->assertSame($createdBy[1], $createdBy[2], 'un article pour le groupe');
        foreach ([1 => [3108.0, 2.5], 4 => [1479.0, 2.0]] as $n => [$purchasePrice, $coefficient]) {
            $row = $conn->fetchAssociative('SELECT price, purchase_price, coefficient_multiplier FROM product WHERE id = ?', [$createdBy[$n]]);
            $planned = $r['lines'][$n - 1]['plannedPrice'];
            $this->assertSame([(float) $planned, $purchasePrice, $coefficient], [(float) $row['price'], (float) $row['purchase_price'], (float) $row['coefficient_multiplier']], "ligne $n : prix de vente de l'aperçu, prix d'achat converti dans la devise du site");
        }
        $this->assertSame([7799, 7799], array_map('intval', $conn->fetchFirstColumn('SELECT price FROM product_variant WHERE product_id = ? ORDER BY id', [$createdBy[1]])), 'variantes au prix du groupe, arrondi par défaut du site (lot IA-ACT-5)');
        $this->assertSame([7799, 2999], [$r['lines'][0]['plannedPrice'], $r['lines'][3]['plannedPrice']], '77,70 → 77,99 ; 29,58 → 29,99 : l\'aperçu et la confirmation rendent le même prix arrondi');
        $this->assertSame([1000, 2000, 2300, 999], array_map('intval', $conn->fetchFirstColumn('SELECT unit_cost_cents FROM commande_line WHERE commande_id = ? ORDER BY id', [$confirmed['commande']['id']])), 'lignes d\'achat (l\'article existant d\'abord) : coût dans la devise de la commande, sans conversion (lot G6)');
        $this->assertSame([2500.0, 2700], [(float) $conn->fetchOne('SELECT price FROM product WHERE id = ?', [$tuque]), (int) $conn->fetchOne('SELECT price FROM product_variant WHERE id = ?', [$variantId])], 'article existant : prix inchangé');
        $this->request('POST', "/api/gestion/documents/$id/apercu", $body);
        $this->assertSame([409, 'DOCUMENT_CONFIRMED'], [$this->client->getResponse()->getStatusCode(), $this->body()['error']]);

        // Factures seulement ; document expiré
        [, $note] = $this->multipartBody('/api/gestion/documents', ['type' => 'note'], ['files' => [$this->image('recu.png')]]);
        FakeLandingAiClient::$queue[] = $this->toolResponse('lire_note_de_frais', ['merchant' => 'Station', 'date' => $today->format('Y-m-d'), 'currency' => 'CAD', 'amount' => 6500, 'taxes' => [], 'kind' => 'déplacement', 'description' => 'Essence', 'confidence' => ['overall' => 0.9], 'notes' => []]);
        $this->runWorker((int) $note['id']);
        $this->request('POST', '/api/gestion/documents/' . $note['id'] . '/apercu', $body);
        $this->assertSame([422, 'DOCUMENT_TYPE'], [$this->client->getResponse()->getStatusCode(), $this->body()['error']]);
        $conn->executeStatement("UPDATE gestion_document SET expires_at = NOW() - INTERVAL '1 day' WHERE id = ?", [$note['id']]);
        $this->endOfWrite();
        $this->request('POST', '/api/gestion/documents/' . $note['id'] . '/apercu', $body);
        $this->assertSame([410, 'DOCUMENT_EXPIRED'], [$this->client->getResponse()->getStatusCode(), $this->body()['error']]);
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
        $this->assertNotNull($xt['commande']['id'] ?? null, 'une commande ouverte sans frais de port, proche de la date, est suggérée (base partagée : pas forcément celle de ce test)');
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
        $dir = static::getContainer()->get(\App\Gestion\Services\Document\GestionDocumentStorage::class)->directory($this->em()->find(GestionDocument::class, (int) $failing['id']));
        $this->assertDirectoryExists($dir);
        $this->get('/api/gestion/documents');
        $this->assertSame(0, (int) $conn->fetchOne('SELECT COUNT(*) FROM gestion_document WHERE id = ?', [$failing['id']]), 'purgé à la liste suivante');
        $this->assertDirectoryDoesNotExist($dir);
        // Suppression d'un document non confirmé
        $this->request('DELETE', '/api/gestion/documents/' . $transport2['id']);
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
    }

    /**
     * DOC-ORPH (contrat validé le 12/11/2026) : un document confirmé est purgé, avec ses fichiers, quand toutes ses cibles ont disparu
     * (existence réelle de la ligne cible) ; aussitôt à la suppression de la cible, et par la purge paresseuse en filet. Avant, un document
     * dont la note avait été supprimée restait dans la liste, sans pouvoir être supprimé (409 DOCUMENT_CONFIRMED).
     */
    public function testAConfirmedDocumentIsPurgedWithItsFilesOnceAllItsTargetsAreGone(): void
    {
        $tag = bin2hex(random_bytes(2));
        $calendar = static::getContainer()->get(PeriodCalendar::class);
        $today = $calendar->today();
        $conn = $this->em()->getConnection();
        $storage = static::getContainer()->get(\App\Gestion\Services\Document\GestionDocumentStorage::class);
        $type = $this->em()->getRepository(TypeNoteDeFrais::class)->findOneBy(['name' => 'Déplacement']) ?? $this->create(TypeNoteDeFrais::class, ['name' => 'Déplacement']);
        $carrier = $this->create(Transporteur::class, ['name' => "Transport orphelin $tag"]);
        $this->endOfWrite();
        $listed = fn () => array_column($this->get('/api/gestion/documents?limit=100'), 'id');
        $dirOf = fn (int $id) => $storage->directory($this->em()->find(GestionDocument::class, $id));
        $purges = fn (int $id) => array_map(fn ($b) => json_decode((string) $b, true), $conn->fetchFirstColumn("SELECT before_state FROM content_audit_log WHERE resource = 'gestion-documents' AND action = 'purge' AND resource_id = ?", [(string) $id]));

        // Note de frais lue par l'IA puis confirmée
        [, $note] = $this->multipartBody('/api/gestion/documents', ['type' => 'note'], ['files' => [$this->image('recu-orphelin.png')]]);
        FakeLandingAiClient::$queue[] = $this->toolResponse('lire_note_de_frais', ['merchant' => 'Station', 'date' => $today->format('Y-m-d'), 'currency' => 'CAD', 'amount' => 6500, 'kind' => 'déplacement', 'description' => 'Essence', 'confidence' => ['overall' => 0.9]]);
        $this->runWorker((int) $note['id']);
        $noteId = (int) $this->post('/api/gestion/documents/' . $note['id'] . '/confirmer', ['montant' => 6500, 'date' => $today->format('Y-m-d'), 'description' => 'Essence', 'typeNoteDeFraisId' => $type->getId()], 201)['noteDeFrais']['id'];
        $docId = (int) $note['id'];
        $dir = $dirOf($docId);
        $file = glob($dir . '/page-*')[0];
        $trace = ['name' => 'recu-orphelin.png', 'size' => filesize($file), 'sha256' => hash_file('sha256', $file)];
        $this->request('DELETE', "/api/gestion/documents/$docId");
        $this->assertSame([409, 'DOCUMENT_CONFIRMED'], [$this->client->getResponse()->getStatusCode(), $this->body()['error']], 'tant que la note existe, le justificatif la suit');

        // Suppression refusée (période fermée) : rien n'est purgé
        $this->get('/api/periodes?status=all');
        $conn->executeStatement('UPDATE note_de_frais SET date = ? WHERE id = ?', [$today->modify('-200 days')->format('Y-m-d'), $noteId]);
        $this->em()->clear();
        $this->request('DELETE', "/api/notes-de-frais/$noteId");
        $this->assertSame([409, 'PERIOD_CLOSED'], [$this->client->getResponse()->getStatusCode(), $this->body()['error']]);
        $this->assertContains($docId, $listed(), 'refus : le document reste');
        $this->assertFileExists($file);
        $this->assertSame([], $purges($docId));
        $conn->executeStatement('UPDATE note_de_frais SET date = ? WHERE id = ?', [$today->format('Y-m-d'), $noteId]);
        $this->em()->clear();

        // Suppression de la note : le document part aussitôt, de la base, de la liste et du stockage ; la purge est journalisée
        $this->request('DELETE', "/api/notes-de-frais/$noteId");
        $this->assertSame(204, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
        $this->assertSame(0, (int) $conn->fetchOne('SELECT COUNT(*) FROM gestion_document WHERE id = ?', [$docId]), 'purgé dès la suppression de la note');
        $this->assertDirectoryDoesNotExist($dir);
        $this->assertNotContains($docId, $listed());
        $this->request('GET', "/api/gestion/documents/$docId");
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
        $log = $purges($docId);
        $this->assertCount(1, $log);
        $this->assertSame(['note', 'confirmed', 'target_deleted', [['type' => 'note_de_frais', 'id' => $noteId]], [$trace]], [$log[0]['type'], $log[0]['status'], $log[0]['reason'], $log[0]['targets'], $log[0]['files']], 'trace : motif, cible, nom, taille et empreinte du fichier');

        // Facture de transport : deux cibles (frais de port et commande) ; le document reste tant qu'une cible existe
        $supplierId = (int) $this->post('/api/fournisseurs', ['name' => "Fournisseur orphelin $tag", 'typeFournisseur' => ($this->em()->find(TypeFournisseur::class, 1) ?? $this->create(TypeFournisseur::class, ['name' => 'Autre']))->getId()], 201)['id'];
        $commande = $this->post('/api/commandes', ['budget' => 50000, 'date' => $today->format('Y-m-d') . ' 10:00:00', 'name' => "Commande orpheline $tag", 'fournisseur' => ['id' => $supplierId]], 201);
        [, $transport] = $this->multipartBody('/api/gestion/documents', ['type' => 'transport'], ['files' => [$this->image('transport-orphelin.png')]]);
        FakeLandingAiClient::$queue[] = $this->toolResponse('lire_facture_transport', ['carrierName' => "Transport orphelin $tag", 'invoiceNumber' => "TO-$tag", 'invoiceDate' => $today->format('Y-m-d'), 'currency' => 'CAD', 'amountsMode' => 'ht', 'amount' => 1500, 'confidence' => ['overall' => 0.9]]);
        $this->runWorker((int) $transport['id']);
        $this->post('/api/gestion/documents/' . $transport['id'] . '/confirmer', ['commandeId' => $commande['id'], 'transporteurId' => $carrier->getId(), 'price' => 1500], 201);
        $tId = (int) $transport['id'];
        $tDir = $dirOf($tId);
        $targets = fn () => $conn->fetchAssociative('SELECT commande_id, frais_de_port_id FROM gestion_document WHERE id = ?', [$tId]);
        $this->assertNotNull($targets()['frais_de_port_id']);
        $this->request('DELETE', '/api/commandes/' . $commande['id'] . '/frais-de-port');
        $this->assertSame(204, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
        $this->assertSame(['commande_id' => $commande['id'], 'frais_de_port_id' => null], $targets(), 'frais de port supprimé : le document reste (la commande existe), identifiant remis à null');
        $this->assertDirectoryExists($tDir);
        $this->assertSame([], $purges($tId));
        $this->request('DELETE', '/api/commandes/' . $commande['id']);
        $this->assertSame(204, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
        $this->assertFalse($targets(), 'commande supprimée : plus aucune cible, document purgé');
        $this->assertDirectoryDoesNotExist($tDir);
        $this->assertSame([['type' => 'commande', 'id' => $commande['id']]], $purges($tId)[0]['targets']);

        // Filet : cible disparue hors de ces chemins (cascade, EasyAdmin) ; purgé à la liste suivante. Campagne : même règle.
        $orphan = fn (string $column) => (int) $conn->fetchOne("INSERT INTO gestion_document (id, type, status, locale, files, $column, created_at, confirmed_at) VALUES (nextval('gestion_document_id_seq'), 'facture', 'confirmed', 'fr', '{\"pages\":[]}', 999999999, NOW(), NOW()) RETURNING id");
        $viaCommande = $orphan('commande_id');
        $viaCampaign = $orphan('campaign_id');
        $ids = $listed();
        $this->assertNotContains($viaCommande, $ids);
        $this->assertNotContains($viaCampaign, $ids);
        $this->assertSame(0, (int) $conn->fetchOne('SELECT COUNT(*) FROM gestion_document WHERE id IN (?, ?)', [$viaCommande, $viaCampaign]));
        $this->assertSame('marketing_campaign', $purges($viaCampaign)[0]['targets'][0]['type']);
    }

    // ----- fabrique -----

    /**
     * Correctif du 12/11/2026 (défaut du lot DOC-ORPH, trouvé par le test du lot EDIT) : supprimer un frais de port alors qu'un justificatif
     * est à purger au même moment répondait 204 mais le frais de port revenait, sous un nouvel identifiant. La purge écrit une seconde fois
     * dans la même requête, et la commande encore en mémoire le recréait par cascade.
     */
    public function testAShippingCostDeletedWhileADocumentIsPurgedStaysDeleted(): void
    {
        $tag = bin2hex(random_bytes(3));
        $today = static::getContainer()->get(\App\Gestion\Services\PeriodCalendar::class)->today();
        $conn = $this->em()->getConnection();
        $carrier = $this->create(Transporteur::class, ['name' => "Transport recréé $tag"]);
        $this->endOfWrite();
        $supplierId = (int) $this->post('/api/fournisseurs', ['name' => "Fournisseur recréé $tag", 'typeFournisseur' => ($this->em()->find(TypeFournisseur::class, 1) ?? $this->create(TypeFournisseur::class, ['name' => 'Autre']))->getId()], 201)['id'];
        $commandeId = (int) $this->post('/api/commandes', ['budget' => 50000, 'date' => $today->format('Y-m-d') . ' 10:00:00', 'name' => "Commande $tag", 'fournisseur' => ['id' => $supplierId]], 201)['id'];
        $rows = fn (): int => (int) $conn->fetchOne('SELECT COUNT(*) FROM frais_de_port WHERE commande_id = ?', [$commandeId]);
        $documents = fn (array $ids): int => (int) $conn->fetchOne('SELECT COUNT(*) FROM gestion_document WHERE id IN (?)', [$ids], [\Doctrine\DBAL\ArrayParameterType::INTEGER]);
        foreach (['confirmé sans cible' => ['confirmed', 'NULL'], 'lecture expirée' => ['extracted', "NOW() - INTERVAL '1 day'"]] as $case => [$status, $expires]) {
            $this->post("/api/commandes/$commandeId/frais-de-port", ['name' => 'Fret', 'price' => 1500, 'transporteur' => ['id' => $carrier->getId()]], 201);
            $purgeable = (int) $conn->fetchOne("INSERT INTO gestion_document (id, type, status, locale, files, created_at, expires_at) VALUES (nextval('gestion_document_id_seq'), 'transport', '$status', 'fr', '{\"pages\":[]}', NOW(), $expires) RETURNING id");
            $this->assertSame([1, 1], [$rows(), $documents([$purgeable])], $case);
            $this->request('DELETE', "/api/commandes/$commandeId/frais-de-port");
            $this->assertSame(204, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
            $this->assertSame([0, 0], [$rows(), $documents([$purgeable])], "$case : le frais de port est supprimé pour de bon, le justificatif est purgé");
            $this->request('GET', "/api/commandes/$commandeId/frais-de-port");
            $this->assertSame([200, 'null'], [$this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent()], "$case : la lecture ne le rend plus");
        }
    }

    private function runWorker(int $documentId): void
    {
        static::getContainer()->get(ReadGestionDocumentHandler::class)(new ReadGestionDocumentMessage($documentId, MV_TEST_TENANT_CODE));
        $this->em()->clear();
        $this->endOfWrite();
    }

    /** Réponse du faux modèle : sortie structurée, bloc texte JSON ($kind ne sert qu'à la lecture du test) */
    private function toolResponse(string $kind, array $input): object
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
