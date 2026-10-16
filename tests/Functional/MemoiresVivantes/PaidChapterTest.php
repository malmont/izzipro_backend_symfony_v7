<?php

namespace App\Tests\Functional\MemoiresVivantes;

use App\Entity\User;
use App\MemoiresVivantes\Entity\AiUsage;
use App\MemoiresVivantes\Entity\Book;
use App\MemoiresVivantes\Entity\Chapter;
use App\MemoiresVivantes\Services\AiQuotaService;
use App\Services\TenantEntityManagerProvider;
use App\Tests\Fake\FakeAnthropicService;
use App\Tests\Fake\FakeCheckoutPaymentGateway;
use App\Tests\Fake\FakeRevenueCatClient;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rédaction payante par chapitre et livre imprimé (16/10/2026) : 402 CHAPTER_NOT_PAID, déblocage par une transaction
 * RevenueCat (idempotent), achats non affectés, remboursement par webhook (texte masqué, rétabli par un nouvel
 * achat), reprises de rédaction bornées, tarifs par devise, paiement Stripe d'une impression, console d'administration.
 */
class PaidChapterTest extends BookTypeApiTestCase
{
    private const WEBHOOK_SECRET = 'test-webhook-secret';

    protected function setUp(): void
    {
        parent::setUp();
        FakeRevenueCatClient::reset();
        FakeCheckoutPaymentGateway::reset();
        FakeAnthropicService::reset();
        self::db()->exec('DELETE FROM mv_ai_usage');
        [$status] = $this->api('PUT', '/admin/ai-quotas', ['transcriptionsPerDay' => 40, 'improvementsPerDay' => 60, 'regenerationsPerDay' => 3, 'maxAudioSeconds' => 300, 'applyToWebBooks' => false, 'timezone' => 'America/Martinique']);
        $this->assertSame(200, $status);
        [$status] = $this->api('PUT', '/admin/pricing', ['print' => ['eur' => ['firstCopy' => 200, 'nextCopies' => 100], 'cad' => ['firstCopy' => 300, 'nextCopies' => 150]]]);
        $this->assertSame(200, $status);
    }

    public function testAnUnpaidMobileChapterIsLockedUntilAStorePurchaseUnlocksIt(): void
    {
        $owner = $this->userWithToken('acheteur-' . bin2hex(random_bytes(3)) . '@example.invalid');
        [$book, $chapter] = $this->mobileBookWithChapter($owner['token']);
        [, $chapter2] = [null, $this->as('POST', "/api/memoires/books/{$book['id']}/chapters", ['title' => 'Suite', 'theme' => 'regards_croises', 'position' => 3, 'answers' => [['index' => 0, 'question' => 'Q', 'answer' => 'R']]], $owner['token'])[1]];

        [, $view] = $this->as('GET', "/api/memoires/books/{$book['id']}", null, $owner['token']);
        $this->assertSame([null, true, false], [$view['chapters'][0]['paid'], $view['chapters'][0]['writingLocked'], $view['chapters'][0]['hiddenAfterRefund']]);
        $this->assertSame('completed', $view['chapters'][0]['generationStatus'], 'créé avec des réponses mais non payé : aucune rédaction lancée');
        [$status, $put] = $this->as('PUT', "/api/memoires/chapters/{$chapter['id']}", ['answers' => [['index' => 0, 'question' => 'Un souvenir ?', 'answer' => 'Réponse modifiée']], 'triggerGenerate' => true], $owner['token']);
        $this->assertSame([200, 'completed'], [$status, $put['generationStatus']], 'modification avec demande de rédaction : ignorée tant que non payé');
        [$status, $r] = $this->as('POST', "/api/memoires/chapters/{$chapter['id']}/generate", [], $owner['token']);
        $this->assertSame([402, 'CHAPTER_NOT_PAID', 'lm_chapter_writing'], [$status, $r['error'], $r['product']], json_encode($r));
        $this->assertSame([], $this->as('GET', '/api/memoires/mobile/purchases/unassigned', null, $owner['token'])[1]['purchases']);
        [$status, $r] = $this->as('POST', "/api/memoires/mobile/chapters/{$chapter['id']}/unlock", ['transactionId' => 'tx-inconnue', 'store' => 'app_store'], $owner['token']);
        $this->assertSame([404, 'PURCHASE_NOT_FOUND'], [$status, $r['error']]);

        $appUserId = MV_TEST_TENANT_CODE . ':' . $owner['id'];
        FakeRevenueCatClient::addPurchase($appUserId, 'tx1');
        [, $r] = $this->as('GET', '/api/memoires/mobile/purchases/unassigned', null, $owner['token']);
        $this->assertEquals([['transactionId' => 'tx1', 'store' => 'app_store', 'product' => 'lm_chapter_writing', 'amount' => 4.99, 'currency' => 'eur']], array_map(fn ($p) => array_diff_key($p, ['purchasedAt' => 1]), $r['purchases']));
        $this->assertContains($appUserId, FakeRevenueCatClient::$calls, 'identifiant RevenueCat = <site>:<userId>');

        [$status, $unlocked] = $this->as('POST', "/api/memoires/mobile/chapters/{$chapter['id']}/unlock", ['transactionId' => 'tx1', 'store' => 'ios'], $owner['token']);
        $this->assertSame(200, $status, json_encode($unlocked));
        $this->assertSame(['amount' => 4.99, 'currency' => 'eur', 'paymentRef' => 'app_store:tx1'], array_diff_key($unlocked['paid'], ['paidAt' => 1]));
        $this->assertNotNull($unlocked['paid']['paidAt']);
        $this->assertFalse($unlocked['writingLocked']);
        $this->assertSame(200, $this->as('POST', "/api/memoires/mobile/chapters/{$chapter['id']}/unlock", ['transactionId' => 'tx1', 'store' => 'app_store'], $owner['token'])[0], 'même transaction, même chapitre : idempotent');
        [$status, $r] = $this->as('POST', "/api/memoires/mobile/chapters/{$chapter2['id']}/unlock", ['transactionId' => 'tx1', 'store' => 'app_store'], $owner['token']);
        $this->assertSame([409, 'PURCHASE_ALREADY_USED'], [$status, $r['error']]);
        FakeRevenueCatClient::addPurchase($appUserId, 'tx2', 'play_store', 'lm_chapter_writing', 6.99, 'cad');
        [$status, $r] = $this->as('POST', "/api/memoires/mobile/chapters/{$chapter['id']}/unlock", ['transactionId' => 'tx2', 'store' => 'play_store'], $owner['token']);
        $this->assertSame([409, 'CHAPTER_ALREADY_PAID'], [$status, $r['error']]);
        $this->assertCount(1, $this->as('GET', '/api/memoires/mobile/purchases/unassigned', null, $owner['token'])[1]['purchases'], 'tx2 reste disponible');
        $this->assertSame(200, $this->as('POST', "/api/memoires/mobile/chapters/{$chapter2['id']}/unlock", ['transactionId' => 'tx2', 'store' => 'play_store'], $owner['token'])[0]);
        $this->assertSame([], $this->as('GET', '/api/memoires/mobile/purchases/unassigned', null, $owner['token'])[1]['purchases']);

        [$status, $r] = $this->as('POST', "/api/memoires/chapters/{$chapter['id']}/generate", [], $owner['token']);
        $this->assertSame([200, 'Generation started'], [$status, $r['status']], 'chapitre payé : rédaction lancée');

        // Vus par un administrateur et sur un livre web : jamais verrouillés
        [, $adminView] = $this->api('GET', "/books/{$book['id']}");
        $this->assertSame(['app_store:tx1', false], [$adminView['chapters'][0]['paid']['paymentRef'], $adminView['chapters'][0]['writingLocked']]);
        [, $web] = $this->api('POST', '/books', ['title' => 'Livre web', 'type' => 'famille'], 'client');
        [, $webChapter] = $this->api('POST', "/books/{$web['id']}/chapters", ['title' => 'Paroles', 'theme' => 'regards_croises', 'position' => 2, 'answers' => []], 'client');
        $this->assertSame([null, false, false], [$webChapter['paid'], $webChapter['writingLocked'], $webChapter['hiddenAfterRefund']], 'livre web : champs présents, neutres');

        // Remboursement par le webhook RevenueCat : texte masqué, chapitre de nouveau verrouillé
        self::db()->exec("UPDATE mv_chapter SET content_final = 'Texte rédigé du chapitre', generation_status = 'completed' WHERE id = '{$chapter['id']}'");
        $this->assertSame(401, $this->webhook(['type' => 'CANCELLATION', 'app_user_id' => $appUserId, 'store' => 'APP_STORE', 'transaction_id' => 'tx1'], 'mauvais-secret')->getStatusCode());
        $response = $this->webhook(['type' => 'CANCELLATION', 'app_user_id' => $appUserId, 'store' => 'APP_STORE', 'transaction_id' => 'tx1', 'product_id' => 'lm_chapter_writing', 'price_in_purchased_currency' => 4.99, 'currency' => 'EUR']);
        $this->assertSame(200, $response->getStatusCode(), $response->getContent());
        $this->assertSame('refunded', json_decode($response->getContent(), true)['action']);
        [, $refunded] = $this->as('GET', "/api/memoires/chapters/{$chapter['id']}", null, $owner['token']);
        $this->assertSame([null, true, true, null], [$refunded['paid'], $refunded['writingLocked'], $refunded['hiddenAfterRefund'], $refunded['contentFinal']], 'texte masqué, conservé en base');
        $this->assertSame('Texte rédigé du chapitre', self::db()->query("SELECT content_final FROM mv_chapter WHERE id = '{$chapter['id']}'")->fetchColumn());
        $this->assertSame(402, $this->as('POST', "/api/memoires/chapters/{$chapter['id']}/generate", [], $owner['token'])[0]);
        $this->assertSame(200, $this->webhook(['type' => 'CANCELLATION', 'app_user_id' => $appUserId, 'store' => 'APP_STORE', 'transaction_id' => 'tx1'])->getStatusCode(), 'rejoué : sans effet');

        // Achat annoncé par le webhook avant que l'application appelle /unlock : disponible, puis rétablit le chapitre
        $response = $this->webhook(['type' => 'NON_RENEWING_PURCHASE', 'app_user_id' => $appUserId, 'store' => 'APP_STORE', 'transaction_id' => 'tx3', 'product_id' => 'lm_chapter_writing', 'price_in_purchased_currency' => 4.99, 'currency' => 'EUR', 'purchased_at_ms' => time() * 1000]);
        $this->assertSame('recorded', json_decode($response->getContent(), true)['action']);
        $this->assertSame(['tx3'], array_column($this->as('GET', '/api/memoires/mobile/purchases/unassigned', null, $owner['token'])[1]['purchases'], 'transactionId'));
        [$status, $restored] = $this->as('POST', "/api/memoires/mobile/chapters/{$chapter['id']}/unlock", ['transactionId' => 'tx3', 'store' => 'app_store'], $owner['token']);
        $this->assertSame(200, $status, json_encode($restored));
        $this->assertSame(['Texte rédigé du chapitre', false, 'app_store:tx3'], [$restored['contentFinal'], $restored['hiddenAfterRefund'], $restored['paid']['paymentRef']]);

        // Console d'administration : paiements de chapitre, remboursement, chapitres payés
        [$status, $detail] = $this->api('GET', '/admin/mobile-users/' . $owner['id']);
        $this->assertSame(200, $status, json_encode($detail));
        $byRef = array_column(array_filter($detail['payments'], fn ($p) => $p['object']['kind'] === 'chapter'), null, 'paymentRef');
        $this->assertEquals(['refunded', 4.99], [$byRef['app_store:tx1']['status'], $byRef['app_store:tx1']['refundedAmount']]);
        $this->assertNotNull($byRef['app_store:tx1']['refundedAt']);
        $this->assertEquals(['paid', 'cad', 6.99], [$byRef['play_store:tx2']['status'], $byRef['play_store:tx2']['currency'], $byRef['play_store:tx2']['amount']]);
        $this->assertSame('Suite', $byRef['play_store:tx2']['object']['title']);
        $chapters = array_column($detail['books'][0]['chapters'], null, 'id');
        $this->assertSame(['app_store:tx3', false], [$chapters[$chapter['id']]['paid']['paymentRef'], $chapters[$chapter['id']]['hiddenAfterRefund']]);
        [, $list] = $this->api('GET', '/admin/mobile-users?search=' . urlencode($owner['email']));
        $this->assertSame(2, $list['users'][0]['books']['chaptersPaid']);
        $this->assertEqualsCanonicalizing([['currency' => 'cad', 'amount' => 6.99], ['currency' => 'eur', 'amount' => 4.99]], $list['users'][0]['payments']['totals'], 'totaux par devise, remboursé exclu');
        $this->assertEquals(4.99, $list['users'][0]['payments']['totalAmount'], 'repli : total de la devise par défaut');

        // PDF : un livre mobile sans chapitre payé et rédigé est vide
        [$empty] = $this->mobileBookWithChapter($owner['token']);
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . "/api/memoires/books/{$empty['id']}/pdf/preview-interior?locale=fr", [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'HTTP_AUTHORIZATION' => 'Bearer ' . $owner['token']]);
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
        $this->assertSame('BOOK_EMPTY', json_decode($this->client->getResponse()->getContent(), true)['error']);
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . "/api/memoires/books/{$book['id']}/pdf/preview-interior?locale=fr", [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'HTTP_AUTHORIZATION' => 'Bearer ' . $owner['token']]);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), 'un chapitre payé et rédigé : PDF rendu');
    }

    public function testRegenerationsAreCappedPerChapterAndPerDayExceptForAdmins(): void
    {
        [$status, $quotas] = $this->api('PUT', '/admin/ai-quotas', ['regenerationsPerDay' => 1]);
        $this->assertSame([200, 1], [$status, $quotas['regenerationsPerDay']]);
        [$status, $r] = $this->api('PUT', '/admin/ai-quotas', ['regenerationsPerDay' => -2]);
        $this->assertSame([422, 'regenerationsPerDay'], [$status, $r['errors'][0]['path']]);
        $this->assertSame('entier entre 0 (illimité) et 100 000 attendu', $r['errors'][0]['message']);

        $owner = $this->userWithToken('reprise-' . bin2hex(random_bytes(3)) . '@example.invalid');
        [$book, $chapter] = $this->mobileBookWithChapter($owner['token']);
        FakeRevenueCatClient::addPurchase(MV_TEST_TENANT_CODE . ':' . $owner['id'], 'tx-reprise');
        $this->assertSame(200, $this->as('POST', "/api/memoires/mobile/chapters/{$chapter['id']}/unlock", ['transactionId' => 'tx-reprise', 'store' => 'app_store'], $owner['token'])[0]);
        $this->assertSame(200, $this->as('POST', "/api/memoires/chapters/{$chapter['id']}/generate", [], $owner['token'])[0], 'première rédaction : pas une reprise');

        // Chapitre rédigé une fois aujourd'hui (cumul enregistré par le handler) : la reprise suivante est refusée
        self::db()->exec("UPDATE mv_chapter SET content_final = 'Première version', generation_status = 'completed' WHERE id = '{$chapter['id']}'");
        $em = static::getContainer()->get(TenantEntityManagerProvider::class);
        $em->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $user = $em->getEntityManager()->getRepository(User::class)->find($owner['id']);
        $chapterEntity = $em->getEntityManager()->getRepository(Chapter::class)->find($chapter['id']);
        static::getContainer()->get(AiQuotaService::class)->record($user, AiUsage::KIND_GENERATION, $em->getEntityManager()->getRepository(Book::class)->find($book['id']), $chapterEntity);
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . "/api/memoires/chapters/{$chapter['id']}/generate?locale=fr", [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json', 'HTTP_X_XSRF_TOKEN' => 'x', 'HTTP_AUTHORIZATION' => 'Bearer ' . $owner['token']], '{}');
        $response = $this->client->getResponse();
        $this->assertSame(429, $response->getStatusCode(), $response->getContent());
        $body = json_decode($response->getContent(), true);
        $this->assertSame(['QUOTA_EXCEEDED', 'regeneration', 1, 1], [$body['error'], $body['quota']['kind'], $body['quota']['limit'], $body['quota']['used']]);
        $this->assertStringContainsString('reprise de rédaction', $body['message']);
        $this->assertNotEmpty($body['quota']['resetsAt']);
        $this->assertGreaterThan(0, (int) $response->headers->get('Retry-After'));
        $this->assertSame(200, $this->api('POST', "/chapters/{$chapter['id']}/generate", [])[0], 'administrateur : ni 402 ni quota de reprises');
        $this->api('PUT', '/admin/ai-quotas', ['regenerationsPerDay' => 0]);
        $this->assertSame(200, $this->as('POST', "/api/memoires/chapters/{$chapter['id']}/generate", [], $owner['token'])[0], '0 = illimité');
    }

    public function testPrintedBookIsBilledByTheGridAndPaidWithStripeFromTheApp(): void
    {
        $owner = $this->userWithToken('impression-' . bin2hex(random_bytes(3)) . '@example.invalid');
        [$book, $chapter] = $this->mobileBookWithChapter($owner['token']);
        FakeRevenueCatClient::addPurchase(MV_TEST_TENANT_CODE . ':' . $owner['id'], 'tx-print');
        $this->as('POST', "/api/memoires/mobile/chapters/{$chapter['id']}/unlock", ['transactionId' => 'tx-print', 'store' => 'app_store'], $owner['token']);
        self::db()->exec("UPDATE mv_chapter SET content_final = 'Un chapitre à imprimer.', generation_status = 'completed' WHERE id = '{$chapter['id']}'");

        [, $pricing] = $this->as('GET', '/api/memoires/mobile/pricing', null, $owner['token']);
        $this->assertSame(['eur', 'cad'], $pricing['currencies']);
        $this->assertEquals(['eur', 'lm_chapter_writing', 300.0], [$pricing['defaultCurrency'], $pricing['chapterProduct'], $pricing['print']['cad']['firstCopy']]);

        [$status, $estimate] = $this->as('GET', "/api/memoires/books/{$book['id']}/print/estimate?quantity=2&currency=eur", null, $owner['token']);
        $this->assertSame(200, $status, json_encode($estimate));
        $this->assertEquals(['eur', 300.0, 0, true], [$estimate['currency'], $estimate['total'], $estimate['alreadyPrinted'], $estimate['paymentRequired']]);
        $this->assertEquals([[1, 'Premier exemplaire', 1, 200.0], [2, 'Exemplaire suivant', 1, 100.0]], array_map(fn ($l) => array_values($l), $estimate['lines']));
        $this->assertFalse($this->api('GET', "/books/{$book['id']}/print/estimate?quantity=2&currency=eur")[1]['paymentRequired'], 'biographe : grille indicative, pas de paiement');
        $this->assertSame([422, 'CURRENCY_NOT_SUPPORTED'], [$this->as('GET', "/api/memoires/books/{$book['id']}/print/estimate?currency=xxx", null, $owner['token'])[0], $this->as('GET', "/api/memoires/books/{$book['id']}/print/estimate?currency=xxx", null, $owner['token'])[1]['error']]);

        $recipient = ['name' => 'Jeanne Dupont', 'street1' => '12 rue des Flamboyants', 'city' => 'Fort-de-France', 'postal_code' => '97200', 'country_code' => 'FR', 'phone_number' => '+596696000000'];
        [$status, $r] = $this->as('POST', "/api/memoires/books/{$book['id']}/print/order", ['quantity' => 2, 'recipient' => $recipient] + $recipient, $owner['token']);
        $this->assertSame([402, 'PRINT_NOT_PAID'], [$status, $r['error']], 'commande sans paiement refusée');

        [$status, $intent] = $this->as('POST', "/api/memoires/books/{$book['id']}/print/payment-intent", ['quantity' => 2, 'currency' => 'cad', 'recipient' => $recipient], $owner['token']);
        $this->assertSame(200, $status, json_encode($intent));
        $this->assertEquals([450.0, 'cad', true], [$intent['amount'], $intent['currency'], $intent['paymentRequired']]);
        $this->assertNotEmpty($intent['clientSecret']);
        $intentId = $intent['paymentIntentId'];
        $this->assertSame(45000, FakeCheckoutPaymentGateway::$intents[$intentId]['amount']);
        $this->assertSame('cad', $this->as('GET', "/api/memoires/books/{$book['id']}/print/estimate?quantity=1&currency=eur", null, $owner['token'])[1]['currency'], 'le livre garde la devise de son premier exemplaire');

        [, $detail] = $this->api('GET', '/admin/mobile-users/' . $owner['id']);
        $pending = array_values(array_filter($detail['payments'], fn ($p) => $p['object']['kind'] === 'print'));
        $this->assertEquals(['pending', 450.0, 'cad'], [$pending[0]['status'], $pending[0]['amount'], $pending[0]['currency']]);

        [$status, $r] = $this->as('POST', "/api/memoires/books/{$book['id']}/print/order", ['paymentIntentId' => $intentId, 'recipient' => $recipient] + $recipient, $owner['token']);
        $this->assertSame([402, 'PRINT_NOT_PAID'], [$status, $r['error']], 'paiement pas encore confirmé');
        FakeCheckoutPaymentGateway::confirm($intentId);
        [$status, $order] = $this->as('POST', "/api/memoires/books/{$book['id']}/print/order", ['paymentIntentId' => $intentId, 'recipient' => $recipient] + $recipient, $owner['token']);
        $this->assertSame(200, $status, json_encode($order));
        $this->assertEquals(['paid', 450.0, 'cad', 2], [$order['paymentStatus'], $order['chargedAmount'], $order['chargedCurrency'], $order['quantity']]);
        $this->assertStringStartsWith('SIM-', (string) $order['lulu_print_job_id'], 'Lulu en simulation dans les tests');
        $this->assertSame('succeeded', FakeCheckoutPaymentGateway::$intents[$intentId]['status'] ?? 'succeeded', 'paiement capturé');
        [$status, $r] = $this->as('POST', "/api/memoires/books/{$book['id']}/print/order", ['paymentIntentId' => $intentId] + $recipient, $owner['token']);
        $this->assertSame([409, 'PRINT_ALREADY_ORDERED'], [$status, $r['error']]);

        [, $after] = $this->as('GET', "/api/memoires/books/{$book['id']}/print/estimate?quantity=1", null, $owner['token']);
        $this->assertEquals([2, 'cad', 150.0], [$after['alreadyPrinted'], $after['currency'], $after['total']], 'exemplaire suivant au tarif « suivant »');
        [, $list] = $this->api('GET', '/admin/mobile-users?search=' . urlencode($owner['email']));
        $this->assertContainsEquals(['currency' => 'cad', 'amount' => 450.0], $list['users'][0]['payments']['totals']);
        $this->assertSame(1, count(array_filter($this->api('GET', '/admin/mobile-users/' . $owner['id'])[1]['payments'], fn ($p) => $p['object']['kind'] === 'print' && $p['status'] === 'paid')));

        // Estimation web (POST, avec adresse) : forme inchangée, facturation ajoutée
        [$status, $webEstimate] = $this->api('POST', "/books/{$book['id']}/print/estimate", ['quantity' => 1, 'shipping_address' => $recipient]);
        $this->assertSame(200, $status, json_encode($webEstimate));
        foreach (['total_cost', 'currency', 'shipping_options', 'billing', 'lines', 'total', 'paymentRequired'] as $key) {
            $this->assertArrayHasKey($key, $webEstimate);
        }
        $this->assertFalse($webEstimate['paymentRequired']);
    }

    public function testPricingAdministrationValidatesAndReplacesTheGrid(): void
    {
        [$status, $r] = $this->api('GET', '/admin/pricing');
        $this->assertSame(200, $status);
        $this->assertSame(['eur', 'cad', 'usd', 'chf', 'gbp'], $r['allowedCurrencies']);
        $this->assertSame(['eur', false, 'lm_chapter_writing'], [$r['defaultCurrency'], $r['isDefault'], $r['chapterProduct']]);
        [$status, $r] = $this->api('PUT', '/admin/pricing', ['print' => ['eur' => ['firstCopy' => 210.5, 'nextCopies' => 99], 'usd' => ['firstCopy' => 250, 'nextCopies' => 120]], 'chapterProduct' => 'ignoré']);
        $this->assertSame(200, $status, json_encode($r));
        $this->assertEquals(['eur' => ['firstCopy' => 210.5, 'nextCopies' => 99.0], 'usd' => ['firstCopy' => 250.0, 'nextCopies' => 120.0]], $r['print'], 'remplacement complet : cad retirée');
        $this->assertSame('lm_chapter_writing', $r['chapterProduct']);
        $this->assertNotNull($r['updatedAt']);
        [$status, $r] = $this->api('PUT', '/admin/pricing', ['print' => ['eur' => ['firstCopy' => -1, 'nextCopies' => 100], 'xyz' => ['firstCopy' => 1, 'nextCopies' => 1], 'cad' => ['firstCopy' => 1.234, 'nextCopies' => 'dix']]]);
        $this->assertSame(422, $status);
        $this->assertSame('VALIDATION_FAILED', $r['error']);
        $this->assertSame(['print.eur.firstCopy', 'print.xyz', 'print.cad.firstCopy', 'print.cad.nextCopies'], array_column($r['errors'], 'path'));
        $this->assertSame(['print'], array_column($this->api('PUT', '/admin/pricing', ['print' => []])[1]['errors'], 'path'), 'au moins une devise');
        $this->assertSame(['print'], array_column($this->api('PUT', '/admin/pricing', ['print' => 'x'])[1]['errors'], 'path'));
        $this->assertSame(['eur', 'usd'], array_keys($this->api('GET', '/admin/pricing')[1]['print']), 'grille inchangée après des envois refusés');
        $this->assertSame(403, $this->api('GET', '/admin/pricing', null, 'client')[0]);
        $this->assertSame(403, $this->api('PUT', '/admin/pricing', ['print' => []], 'client')[0]);
        $this->assertSame(401, $this->as('GET', '/api/memoires/mobile/pricing', null, 'jeton-invalide')[0]);
    }

    /** @return array{0: array, 1: array} livre mobile (famille) et son chapitre avec une réponse */
    private function mobileBookWithChapter(string $token): array
    {
        [$status, $book] = $this->as('POST', '/api/memoires/mobile/books', ['title' => 'Livre mobile payant', 'type' => 'famille'], $token);
        $this->assertSame(201, $status, json_encode($book));
        [$status, $chapter] = $this->as('POST', "/api/memoires/books/{$book['id']}/chapters", ['title' => 'Paroles', 'theme' => 'regards_croises', 'position' => 2, 'answers' => [['index' => 0, 'question' => 'Un souvenir ?', 'answer' => 'Oui, un très beau.']]], $token);
        $this->assertSame(201, $status, json_encode($chapter));

        return [$book, $chapter];
    }

    /** @return array{id: int, email: string, token: string} */
    private function userWithToken(string $email): array
    {
        $container = static::getContainer();
        $provider = $container->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Jeanne')->setLastname('Dupont')->setRoles(['ROLE_USER_INTERNET'])->setPassword('inutilisable')->setIsVerified(true)->setSignupChannel(User::SIGNUP_MOBILE);
        $provider->getEntityManager()->persist($user);
        $provider->getEntityManager()->flush();

        return ['id' => (int) $user->getId(), 'email' => $email, 'token' => $container->get('lexik_jwt_authentication.jwt_manager')->create($user)];
    }

    /** @return array{0: int, 1: mixed} appel avec un jeton donné */
    private function as(string $method, string $path, ?array $body, string $token): array
    {
        $uri = $path . (str_contains($path, '?') ? '&' : '?') . 'locale=fr';
        $this->client->request($method, 'https://' . MV_TEST_TENANT_HOST . $uri, [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_XSRF_TOKEN' => 'x', 'HTTP_AUTHORIZATION' => 'Bearer ' . $token], $body !== null ? json_encode($body) : null);
        $response = $this->client->getResponse();

        return [$response->getStatusCode(), json_decode((string) $response->getContent(), true)];
    }

    private function webhook(array $event, string $secret = self::WEBHOOK_SECRET): Response
    {
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . '/api/webhooks/revenuecat', [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => $secret], json_encode(['event' => $event]));

        return $this->client->getResponse();
    }
}
