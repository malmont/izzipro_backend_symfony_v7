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
        $this->assertStringNotContainsString('Livre web payé', json_encode($ledger), 'les livres web payés par lien ne sont pas inclus par défaut');

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
