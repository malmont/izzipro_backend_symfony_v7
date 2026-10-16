<?php

namespace App\Tests\Functional\MemoiresVivantes;

use App\Entity\User;
use App\Services\TenantEntityManagerProvider;
use App\Tests\Fake\FakeCheckoutPaymentGateway;
use App\Tests\Fake\FakeRevenueCatClient;

/**
 * Écran « Paiements » de la console (16/10/2026), lecture seule : achats de chapitre (avec environnement RevenueCat),
 * impressions payées par Stripe, remboursements (webhook RevenueCat, webhook Stripe charge.refunded), filtres,
 * totaux par devise, export CSV ; les livres web payés par lien n'apparaissent que sur demande.
 */
class AdminPaymentsTest extends BookTypeApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        FakeRevenueCatClient::reset();
        FakeCheckoutPaymentGateway::reset();
        $this->api('PUT', '/admin/pricing', ['print' => ['eur' => ['firstCopy' => 200, 'nextCopies' => 100], 'cad' => ['firstCopy' => 300, 'nextCopies' => 150]]]);
        $this->api('PUT', '/admin/ai-quotas', ['timezone' => 'America/Martinique']);
    }

    public function testTheLedgerListsMobilePaymentsWithRefundsTotalsFiltersAndCsv(): void
    {
        $owner = $this->userWithToken('registre-' . bin2hex(random_bytes(3)) . '@example.invalid');
        [$status, $book] = $this->as('POST', '/api/memoires/mobile/books', ['title' => 'Livre du registre', 'type' => 'famille'], $owner['token']);
        $this->assertSame(201, $status);
        $chapters = [];
        foreach ([2, 3] as $position) {
            [, $chapters[]] = $this->as('POST', "/api/memoires/books/{$book['id']}/chapters", ['title' => "Chapitre $position", 'theme' => 'regards_croises', 'position' => $position, 'answers' => [['index' => 0, 'question' => 'Q', 'answer' => 'R']]], $owner['token']);
        }
        $appUserId = MV_TEST_TENANT_CODE . ':' . $owner['id'];
        FakeRevenueCatClient::addPurchase($appUserId, 'reg-tx1', 'app_store', 'lm_chapter_writing', 4.99, 'eur', 'sandbox');
        FakeRevenueCatClient::addPurchase($appUserId, 'reg-tx2', 'play_store', 'lm_chapter_writing', 6.99, 'cad', 'production');
        $this->assertSame(200, $this->as('POST', "/api/memoires/mobile/chapters/{$chapters[0]['id']}/unlock", ['transactionId' => 'reg-tx1', 'store' => 'app_store'], $owner['token'])[0]);
        $this->assertSame(200, $this->as('POST', "/api/memoires/mobile/chapters/{$chapters[1]['id']}/unlock", ['transactionId' => 'reg-tx2', 'store' => 'play_store'], $owner['token'])[0]);
        // Remboursement du second par RevenueCat
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . '/api/webhooks/revenuecat', [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'test-webhook-secret'], json_encode(['event' => ['type' => 'CANCELLATION', 'app_user_id' => $appUserId, 'store' => 'PLAY_STORE', 'transaction_id' => 'reg-tx2', 'environment' => 'PRODUCTION']]));
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        // Impression payée par Stripe, puis remboursée dans Stripe (webhook charge.refunded)
        self::db()->exec("UPDATE mv_chapter SET content_final = 'Texte' WHERE id = '{$chapters[0]['id']}'");
        $recipient = ['name' => 'Jeanne Dupont', 'street1' => '12 rue des Flamboyants', 'city' => 'Fort-de-France', 'postal_code' => '97200', 'country_code' => 'FR', 'phone_number' => '+596696000000'];
        [, $intent] = $this->as('POST', "/api/memoires/books/{$book['id']}/print/payment-intent", ['quantity' => 1, 'currency' => 'eur', 'recipient' => $recipient], $owner['token']);
        FakeCheckoutPaymentGateway::confirm($intent['paymentIntentId']);
        [$status, $order] = $this->as('POST', "/api/memoires/books/{$book['id']}/print/order", ['paymentIntentId' => $intent['paymentIntentId']] + $recipient, $owner['token']);
        $this->assertSame(200, $status, json_encode($order));
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . '/api/stripe/webhook', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['id' => 'evt_test', 'type' => 'charge.refunded', 'data' => ['object' => ['id' => 'ch_test', 'object' => 'charge', 'payment_intent' => $intent['paymentIntentId'], 'amount_refunded' => 20000, 'refunded' => true, 'metadata' => ['memoires_print' => '1', 'tenant_code' => MV_TEST_TENANT_CODE]]]]));
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());
        $this->assertSame('refunded', json_decode($this->client->getResponse()->getContent(), true)['status']);
        // Livre web payé par lien (hors écran par défaut)
        [, $web] = $this->api('POST', '/books', ['title' => 'Livre web payé', 'type' => 'individuel'], 'client');
        self::db()->exec("UPDATE mv_book SET payment_status = 'paid', payment_amount = 49.9, payment_currency = 'eur', paid_at = NOW() WHERE id = '{$web['id']}'");

        [$status, $ledger] = $this->api('GET', '/admin/payments?search=' . urlencode($owner['email']));
        $this->assertSame(200, $status, json_encode($ledger));
        $this->assertSame(['America/Martinique', 1, 50, 3], [$ledger['timezone'], $ledger['page'], $ledger['perPage'], $ledger['total']]);
        $this->assertSame(['paid' => 1, 'pending' => 0, 'refunded' => 2], $ledger['counts']);
        $byCurrency = array_column($ledger['totals'], null, 'currency');
        $this->assertEquals(['currency' => 'eur', 'paid' => 204.99, 'refunded' => 200.0, 'pending' => 0.0, 'net' => 4.99], $byCurrency['eur']);
        $this->assertEquals(['currency' => 'cad', 'paid' => 6.99, 'refunded' => 6.99, 'pending' => 0.0, 'net' => 0.0], $byCurrency['cad']);
        $byRef = array_column($ledger['payments'], null, 'paymentRef');
        $this->assertSame(['chapter', 'paid', 'app_store', 'sandbox', 'Chapitre 2'], [$byRef['app_store:reg-tx1']['kind'], $byRef['app_store:reg-tx1']['status'], $byRef['app_store:reg-tx1']['store'], $byRef['app_store:reg-tx1']['environment'], $byRef['app_store:reg-tx1']['chapter']['title']]);
        $this->assertSame(['refunded', 'production', 'Chapitre 3'], [$byRef['play_store:reg-tx2']['status'], $byRef['play_store:reg-tx2']['environment'], $byRef['play_store:reg-tx2']['chapter']['title']]);
        $this->assertNotNull($byRef['play_store:reg-tx2']['refundedAt']);
        $print = $byRef[$intent['paymentIntentId']];
        $this->assertSame(['print', 'refunded', 'stripe'], [$print['kind'], $print['status'], $print['store']]);
        $this->assertEquals([200.0, 1, 'created'], [$print['refundedAmount'], $print['print']['quantity'], $print['print']['luluStatus']]);
        $this->assertSame(['userId' => $owner['id'], 'email' => $owner['email'], 'name' => 'Jeanne Dupont'], $print['user']);
        $this->assertSame(['id' => $book['id'], 'title' => 'Livre du registre', 'channel' => 'mobile'], $print['book']);
        $this->assertStringNotContainsString('Livre web payé', json_encode($ledger), 'recherche sur le propriétaire mobile : le livre web d\'un autre compte n\'y est pas');

        // Filtres
        [, $onlyChapters] = $this->api('GET', '/admin/payments?kind=chapter&search=' . urlencode($owner['email']));
        $this->assertSame(['chapter', 'chapter'], array_column($onlyChapters['payments'], 'kind'));
        [, $refunded] = $this->api('GET', '/admin/payments?status=refunded&store=google_play&search=' . urlencode($owner['email']));
        $this->assertSame(['play_store:reg-tx2'], array_column($refunded['payments'], 'paymentRef'), 'google_play accepté comme alias de play_store');
        [, $books] = $this->api('GET', '/admin/payments?kind=book');
        $this->assertContains('Livre web payé', array_column(array_column($books['payments'], 'book'), 'title'));
        [, $paged] = $this->api('GET', '/admin/payments?perPage=2&page=2&search=' . urlencode($owner['email']));
        $this->assertSame([1, 3], [count($paged['payments']), $paged['total']]);
        $yesterday = (new \DateTimeImmutable('-1 day', new \DateTimeZone('America/Martinique')))->format('Y-m-d');
        $this->assertSame(0, $this->api('GET', "/admin/payments?to=$yesterday&search=" . urlencode($owner['email']))[1]['total'], 'fenêtre de dates');
        $this->assertSame(422, $this->api('GET', '/admin/payments?from=2026-13-01')[0]);
        $this->assertSame(422, $this->api('GET', '/admin/payments?kind=cadeau')[0]);
        $this->assertSame(403, $this->api('GET', '/admin/payments', null, 'client')[0]);

        // CSV
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/api/memoires/admin/payments?format=csv&search=' . urlencode($owner['email']) . '&locale=fr', [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'HTTP_AUTHORIZATION' => 'Bearer ' . $this->tokenFor('admin')]);
        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringStartsWith('text/csv', (string) $response->headers->get('Content-Type'));
        $lines = explode("\n", trim((string) $response->getContent()));
        $this->assertCount(4, $lines, 'en-tête + 3 lignes');
        $this->assertStringStartsWith("\xEF\xBB\xBFkind;status;amount;currency;paidAt", $lines[0]);
        $this->assertStringContainsString('app_store:reg-tx1', $response->getContent());
    }

    public function testTheRevenueOverviewCoversEveryBookWebAndMobile(): void
    {
        $owner = $this->userWithToken('revenu-' . bin2hex(random_bytes(3)) . '@example.invalid');
        [, $book] = $this->as('POST', '/api/memoires/mobile/books', ['title' => 'Livre mobile revenu ' . $owner['id'], 'type' => 'famille'], $owner['token']);
        $chapters = [];
        foreach ([2, 3] as $position) {
            [, $chapters[]] = $this->as('POST', "/api/memoires/books/{$book['id']}/chapters", ['title' => "Chapitre $position", 'theme' => 'regards_croises', 'position' => $position, 'answers' => [['index' => 0, 'question' => 'Q', 'answer' => 'R']]], $owner['token']);
        }
        $appUserId = MV_TEST_TENANT_CODE . ':' . $owner['id'];
        FakeRevenueCatClient::addPurchase($appUserId, 'rev-tx1', 'app_store', 'lm_chapter_writing', 4.99, 'eur');
        FakeRevenueCatClient::addPurchase($appUserId, 'rev-tx2', 'app_store', 'lm_chapter_writing', 4.99, 'eur');
        $this->as('POST', "/api/memoires/mobile/chapters/{$chapters[0]['id']}/unlock", ['transactionId' => 'rev-tx1', 'store' => 'app_store'], $owner['token']);
        $this->as('POST', "/api/memoires/mobile/chapters/{$chapters[1]['id']}/unlock", ['transactionId' => 'rev-tx2', 'store' => 'app_store'], $owner['token']);
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . '/api/webhooks/revenuecat', [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'test-webhook-secret'], json_encode(['event' => ['type' => 'CANCELLATION', 'app_user_id' => $appUserId, 'store' => 'APP_STORE', 'transaction_id' => 'rev-tx2']]));
        self::db()->exec("UPDATE mv_chapter SET content_final = 'Texte' WHERE id = '{$chapters[0]['id']}'");
        $recipient = ['name' => 'Jeanne Dupont', 'street1' => '12 rue des Flamboyants', 'city' => 'Fort-de-France', 'postal_code' => '97200', 'country_code' => 'FR', 'phone_number' => '+596696000000'];
        [, $intent] = $this->as('POST', "/api/memoires/books/{$book['id']}/print/payment-intent", ['quantity' => 2, 'currency' => 'eur', 'recipient' => $recipient], $owner['token']);
        FakeCheckoutPaymentGateway::confirm($intent['paymentIntentId']);
        $this->assertSame(200, $this->as('POST', "/api/memoires/books/{$book['id']}/print/order", ['paymentIntentId' => $intent['paymentIntentId']] + $recipient, $owner['token'])[0]);
        [, $web] = $this->api('POST', '/books', ['title' => 'Livre web revenu ' . $owner['id'], 'type' => 'individuel'], 'client');
        self::db()->exec("UPDATE mv_book SET payment_status = 'paid', payment_amount = 49.9, payment_currency = 'eur', paid_at = NOW() WHERE id = '{$web['id']}'");

        [$status, $overview] = $this->api('GET', '/admin/revenue?search=' . urlencode('revenu ' . $owner['id']));
        $this->assertSame(200, $status, json_encode($overview));
        $this->assertSame([null, null, 'America/Martinique', 2], [$overview['from'], $overview['to'], $overview['timezone'], $overview['total']]);
        $this->assertSame(['total' => 2, 'web' => 1, 'mobile' => 1], $overview['summary']['books']);
        $byChannel = array_column($overview['summary']['byChannel'], null, 'channel');
        $this->assertEquals([['currency' => 'eur', 'book' => 0.0, 'chapters' => 9.98, 'prints' => 300.0, 'refunded' => 4.99, 'net' => 304.99]], $byChannel['mobile']['totals']);
        $this->assertSame(['booksPaid' => 0, 'chaptersPaid' => 1, 'printsPaid' => 1, 'refunds' => 1], $byChannel['mobile']['counts']);
        $this->assertEquals([['currency' => 'eur', 'book' => 49.9, 'chapters' => 0.0, 'prints' => 0.0, 'refunded' => 0.0, 'net' => 49.9]], $byChannel['web']['totals']);
        $this->assertSame(['booksPaid' => 1, 'chaptersPaid' => 0, 'printsPaid' => 0, 'refunds' => 0], $byChannel['web']['counts']);
        $this->assertEquals([['currency' => 'eur', 'book' => 49.9, 'chapters' => 9.98, 'prints' => 300.0, 'refunded' => 4.99, 'net' => 354.89]], $overview['summary']['totals']);

        [$mobileLine, $webLine] = $overview['books'];
        $this->assertSame([$book['id'], 'mobile', $web['id'], 'web'], [$mobileLine['id'], $mobileLine['channel'], $webLine['id'], $webLine['channel']], 'tri : net décroissant');
        $this->assertSame(['userId' => $owner['id'], 'email' => $owner['email'], 'name' => 'Jeanne Dupont'], $mobileLine['owner']);
        $this->assertNull($mobileLine['biographer']);
        $this->assertSame(['total' => 2, 'written' => 1, 'paid' => 1], $mobileLine['chapters']);
        $this->assertNull($mobileLine['bookPayment']);
        $this->assertEquals([['currency' => 'eur', 'paid' => 9.98, 'refunded' => 4.99]], $mobileLine['chaptersRevenue']);
        $this->assertEquals(['orders' => 1, 'copies' => 2, 'revenue' => [['currency' => 'eur', 'paid' => 300.0, 'pending' => 0.0, 'refunded' => 0.0]]], $mobileLine['prints']);
        $this->assertEquals([['currency' => 'eur', 'amount' => 304.99]], $mobileLine['net']);
        $this->assertEquals(['status' => 'paid', 'amount' => 49.9, 'currency' => 'eur'], array_diff_key($webLine['bookPayment'], ['paidAt' => 1]));
        $this->assertEquals([['currency' => 'eur', 'amount' => 49.9]], $webLine['net']);

        [, $onlyWeb] = $this->api('GET', '/admin/revenue?channel=web&search=' . urlencode('revenu ' . $owner['id']));
        $this->assertSame(['web'], array_column($onlyWeb['books'], 'channel'));
        $this->assertSame(['web'], array_column($onlyWeb['summary']['byChannel'], 'channel'));
        $yesterday = (new \DateTimeImmutable('-1 day', new \DateTimeZone('America/Martinique')))->format('Y-m-d');
        [, $past] = $this->api('GET', "/admin/revenue?to=$yesterday&search=" . urlencode('revenu ' . $owner['id']));
        $this->assertSame(2, $past['total'], 'tous les livres restent listés');
        $this->assertSame([], $past['summary']['totals'], 'mais aucun paiement dans la période');
        $this->assertEquals([], array_column($past['books'], 'net')[0]);
        [, $paged] = $this->api('GET', '/admin/revenue?perPage=1&page=2&search=' . urlencode('revenu ' . $owner['id']));
        $this->assertSame([1, 2, $web['id']], [count($paged['books']), $paged['total'], $paged['books'][0]['id']]);
        $this->assertSame(422, $this->api('GET', '/admin/revenue?channel=tablette')[0]);
        $this->assertSame(422, $this->api('GET', '/admin/revenue?from=2026-02-30')[0]);
        $this->assertSame(403, $this->api('GET', '/admin/revenue', null, 'client')[0]);

        // Journal : livres web payés par lien inclus par défaut, filtre channel
        [, $ledger] = $this->api('GET', '/admin/payments?channel=web&search=' . urlencode(self::CLIENT_EMAIL));
        $this->assertContains('book-payment-' . $web['id'], array_column($ledger['payments'], 'id'));
        $this->assertSame(['web'], array_values(array_unique(array_map(fn ($p) => $p['book']['channel'], $ledger['payments']))));
        [, $mobileOnly] = $this->api('GET', '/admin/payments?channel=mobile&search=' . urlencode($owner['email']));
        $this->assertSame(['mobile'], array_values(array_unique(array_map(fn ($p) => $p['book']['channel'], $mobileOnly['payments']))));
        $this->assertSame(3, $mobileOnly['total']);
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

    /** @return array{0: int, 1: mixed} */
    private function as(string $method, string $path, ?array $body, string $token): array
    {
        $uri = $path . (str_contains($path, '?') ? '&' : '?') . 'locale=fr';
        $this->client->request($method, 'https://' . MV_TEST_TENANT_HOST . $uri, [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_XSRF_TOKEN' => 'x', 'HTTP_AUTHORIZATION' => 'Bearer ' . $token], $body !== null ? json_encode($body) : null);
        $response = $this->client->getResponse();

        return [$response->getStatusCode(), json_decode((string) $response->getContent(), true)];
    }
}
