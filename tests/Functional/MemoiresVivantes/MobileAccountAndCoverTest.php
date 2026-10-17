<?php

namespace App\Tests\Functional\MemoiresVivantes;

use App\Entity\User;
use App\MemoiresVivantes\Entity\Book;
use App\MemoiresVivantes\Services\BookPdfGeneratorService;
use App\Services\TenantEntityManagerProvider;
use App\Tests\Fake\FakeCheckoutPaymentGateway;
use App\Tests\Fake\FakeRevenueCatClient;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * 17/10/2026 : choix de couverture enregistrés sur le livre, finalisation côté serveur d'une impression payée
 * (webhook Stripe) et suppression du compte depuis l'application.
 */
class MobileAccountAndCoverTest extends BookTypeApiTestCase
{
    private const PASSWORD = 'Mot-de-passe-1!';

    protected function setUp(): void
    {
        parent::setUp();
        FakeRevenueCatClient::reset();
        FakeCheckoutPaymentGateway::reset();
        $this->api('PUT', '/admin/pricing', ['print' => ['eur' => ['firstCopy' => 200, 'nextCopies' => 100]]]);
    }

    public function testCoverChoicesAreStoredOnTheBookAndUsedByDefault(): void
    {
        [$status, $book] = $this->api('POST', '/books', ['title' => 'Livre à couverture', 'type' => 'individuel', 'coverStyle' => 'classic', 'bgColor' => '1A2B3C', 'authorName' => 'Jeanne D.'], 'client');
        $this->assertSame(201, $status, json_encode($book));
        $this->assertSame(['classic', '#1a2b3c', 'Jeanne D.', '#1a2b3c'], [$book['coverStyle'], $book['bgColor'], $book['authorName'], $book['bg_color']]);
        [, $read] = $this->api('GET', "/books/{$book['id']}", null, 'client');
        $this->assertSame(['classic', '#1a2b3c', 'Jeanne D.'], [$read['coverStyle'], $read['bgColor'], $read['authorName']]);
        [, $untouched] = $this->api('PUT', "/books/{$book['id']}", ['title' => 'Titre seul'], 'client');
        $this->assertSame('classic', $untouched['coverStyle'], 'champ absent : inchangé');
        [, $invalid] = $this->api('PUT', "/books/{$book['id']}", ['bgColor' => 'rouge'], 'client');
        $this->assertSame('#1a2b3c', $invalid['bgColor'], 'couleur hors #rrggbb : ignorée');
        [, $cleared] = $this->api('PUT', "/books/{$book['id']}", ['bgColor' => '', 'authorName' => ''], 'client');
        $this->assertSame([null, null, 'classic'], [$cleared['bgColor'], $cleared['authorName'], $cleared['coverStyle']], 'chaîne vide : retour au défaut');
        [, $web] = $this->api('POST', '/books', ['title' => 'Sans choix', 'type' => 'individuel'], 'client');
        $this->assertSame([null, null, null], [$web['coverStyle'], $web['bgColor'], $web['authorName']]);

        // Nom d'auteur enregistré : prioritaire sur le narrateur et le propriétaire, un paramètre fourni le dépasse
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $entity = $provider->getEntityManager()->getRepository(Book::class)->find($book['id']);
        $entity->setAuthorName('Nom enregistré')->setPerson1FirstName('Marie');
        $service = static::getContainer()->get(BookPdfGeneratorService::class);
        $this->assertSame('Nom enregistré', $service->resolveAuthorName($entity));
        $this->assertSame('Essai', $service->resolveAuthorName($entity, 'Essai'));
        $this->assertSame('Marie', $service->resolveAuthorName($entity->setAuthorName(null)));

        // Aperçu de couverture sans paramètre : les choix du livre servent (le rendu aboutit)
        $this->api('PUT', "/books/{$book['id']}", ['coverStyle' => 'classic', 'bgColor' => '#112233', 'authorName' => 'Jeanne D.'], 'client');
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . "/api/memoires/books/{$book['id']}/pdf/preview-cover?locale=fr", [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'HTTP_AUTHORIZATION' => 'Bearer ' . $this->tokenFor('client')]);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertSame('application/pdf', $this->client->getResponse()->headers->get('Content-Type'));

        // Commande d'impression (web, biographe) sans paramètre de couverture : ceux du livre
        $recipient = ['name' => 'Client Test', 'street1' => '1 rue Test', 'city' => 'Montréal', 'postal_code' => 'H2X 1Y4', 'country_code' => 'CA', 'state_code' => 'QC', 'phone_number' => '5145550000'];
        [$status, $order] = $this->api('POST', "/books/{$book['id']}/print/order", ['shipping_address' => $recipient, 'quantity' => 1]);
        $this->assertSame(200, $status, json_encode($order));
        $this->assertSame(['classic', '#112233'], [$order['cover_style'], $order['bg_color']]);
    }

    public function testAPaidPrintIsCompletedServerSideWhenTheAppNeverCallsOrder(): void
    {
        $owner = $this->userWithPassword('webhook-print-' . bin2hex(random_bytes(3)) . '@example.invalid');
        [, $book] = $this->as('POST', '/api/memoires/mobile/books', ['title' => 'Livre payé puis téléphone fermé', 'type' => 'famille'], $owner['token']);
        [, $chapter] = $this->as('POST', "/api/memoires/books/{$book['id']}/chapters", ['title' => 'Paroles', 'theme' => 'regards_croises', 'position' => 2, 'answers' => [['index' => 0, 'question' => 'Q', 'answer' => 'R']]], $owner['token']);
        FakeRevenueCatClient::addPurchase(MV_TEST_TENANT_CODE . ':' . $owner['id'], 'wh-tx1');
        $this->as('POST', "/api/memoires/mobile/chapters/{$chapter['id']}/unlock", ['transactionId' => 'wh-tx1', 'store' => 'app_store'], $owner['token']);
        self::db()->exec("UPDATE mv_chapter SET content_final = 'Texte' WHERE id = '{$chapter['id']}'");
        $recipient = ['name' => 'Jeanne Dupont', 'street1' => '12 rue des Flamboyants', 'city' => 'Fort-de-France', 'postal_code' => '97200', 'country_code' => 'FR', 'phone_number' => '+596696000000'];
        [$status, $intent] = $this->as('POST', "/api/memoires/books/{$book['id']}/print/payment-intent", ['quantity' => 1, 'currency' => 'eur', 'recipient' => $recipient, 'cover_style' => 'classic', 'bg_color' => '#445566'], $owner['token']);
        $this->assertSame(200, $status, json_encode($intent));
        $this->assertSame(['pending_payment', 'classic', '#445566'], array_values(self::db()->query("SELECT status, cover_style, bg_color FROM mv_book_print_order WHERE stripe_payment_intent_id = '{$intent['paymentIntentId']}'")->fetch()));

        // Avant paiement : le webhook ne complète rien
        $this->assertSame('not_paid', $this->stripeWebhook($intent['paymentIntentId'])['status']);
        FakeCheckoutPaymentGateway::confirm($intent['paymentIntentId']);
        $result = $this->stripeWebhook($intent['paymentIntentId']);
        $this->assertSame('completed', $result['status'], json_encode($result));
        $row = self::db()->query("SELECT status, payment_status, cover_style, lulu_print_job_id FROM mv_book_print_order WHERE stripe_payment_intent_id = '{$intent['paymentIntentId']}'")->fetch();
        $this->assertSame(['created', 'paid', 'classic'], [$row['status'], $row['payment_status'], $row['cover_style']]);
        $this->assertStringStartsWith('SIM-', (string) $row['lulu_print_job_id']);
        $this->assertSame('already_ordered', $this->stripeWebhook($intent['paymentIntentId'])['status'], 'rejoué : sans effet');
        [$status, $r] = $this->as('POST', "/api/memoires/books/{$book['id']}/print/order", ['paymentIntentId' => $intent['paymentIntentId']] + $recipient, $owner['token']);
        $this->assertSame([409, 'PRINT_ALREADY_ORDERED'], [$status, $r['error']], 'l\'application qui rappelle ensuite apprend que c\'est fait');
        $this->assertSame(1, (int) self::db()->query("SELECT COUNT(*) FROM mv_book_print_order WHERE book_id = '{$book['id']}'")->fetchColumn(), 'une seule commande');
    }

    public function testAUserDeletesTheirAccountAndOnlyAnonymizedAccountingRemains(): void
    {
        $owner = $this->userWithPassword('suppression-' . bin2hex(random_bytes(3)) . '@example.invalid');
        [, $book] = $this->as('POST', '/api/memoires/mobile/books', ['title' => 'Livre à supprimer', 'type' => 'famille'], $owner['token']);
        [, $chapter] = $this->as('POST', "/api/memoires/books/{$book['id']}/chapters", ['title' => 'Paroles', 'theme' => 'regards_croises', 'position' => 2, 'answers' => [['index' => 0, 'question' => 'Q', 'answer' => 'R']]], $owner['token']);
        FakeRevenueCatClient::addPurchase(MV_TEST_TENANT_CODE . ':' . $owner['id'], 'del-tx1');
        $this->as('POST', "/api/memoires/mobile/chapters/{$chapter['id']}/unlock", ['transactionId' => 'del-tx1', 'store' => 'app_store'], $owner['token']);
        $recipient = ['name' => 'Jeanne Dupont', 'street1' => '12 rue des Flamboyants', 'city' => 'Fort-de-France', 'postal_code' => '97200', 'country_code' => 'FR', 'phone_number' => '+596696000000'];
        $this->as('POST', "/api/memoires/books/{$book['id']}/print/payment-intent", ['quantity' => 1, 'currency' => 'eur', 'recipient' => $recipient], $owner['token']);
        // Une vraie connexion, pour avoir un jeton de rafraîchissement à révoquer
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . '/api/login', [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json'], json_encode(['username' => $owner['email'], 'password' => self::PASSWORD, 'platform' => 'mobile']));
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertGreaterThan(0, (int) self::db()->query("SELECT COUNT(*) FROM refresh_tokens WHERE username = '{$owner['email']}'")->fetchColumn());

        [$status, $r] = $this->as('DELETE', '/api/memoires/mobile/account', [], $owner['token']);
        $this->assertSame([401, 'PASSWORD_INVALID'], [$status, $r['error']], 'mot de passe absent');
        [$status, $r] = $this->as('DELETE', '/api/memoires/mobile/account', ['password' => 'faux'], $owner['token']);
        $this->assertSame([401, 'PASSWORD_INVALID'], [$status, $r['error']]);
        $this->assertNotEmpty($r['message']);
        $this->assertSame([403, 'ADMIN_ACCOUNT'], [$this->api('DELETE', '/mobile/account', ['password' => 'x'])[0], $this->api('DELETE', '/mobile/account', ['password' => 'x'])[1]['error']], 'administrateur : refusé');

        [$status, $r] = $this->as('DELETE', '/api/memoires/mobile/account', ['password' => self::PASSWORD], $owner['token']);
        $this->assertSame(200, $status, json_encode($r));
        $this->assertSame(['deleted' => true, 'booksDeleted' => 1, 'printOrdersAnonymized' => 1], $r);

        $user = self::db()->query("SELECT email, firstname, lastname, roles::text AS roles, is_verified, deleted_at FROM \"user\" WHERE id = {$owner['id']}")->fetch();
        $this->assertSame(["supprime-{$owner['id']}@compte-supprime.invalid", 'Compte', 'supprimé', '[]', false], [$user['email'], $user['firstname'], $user['lastname'], $user['roles'], (bool) $user['is_verified']]);
        $this->assertNotNull($user['deleted_at']);
        $this->assertSame(['Livre supprimé', null], array_values(self::db()->query("SELECT title, person1_first_name FROM mv_book WHERE id = '{$book['id']}'")->fetch()), 'livre avec commande : coquille anonyme conservée');
        $this->assertSame(0, (int) self::db()->query("SELECT COUNT(*) FROM mv_chapter WHERE book_id = '{$book['id']}'")->fetchColumn(), 'contenu effacé');
        $this->assertSame(0, (int) self::db()->query("SELECT COUNT(*) FROM mv_chapter WHERE id = '{$chapter['id']}'")->fetchColumn());
        $this->assertSame(0, (int) self::db()->query("SELECT COUNT(*) FROM refresh_tokens WHERE username = '{$owner['email']}'")->fetchColumn(), 'sessions révoquées');
        $this->assertSame(['Compte supprimé', '-', null], array_values(self::db()->query("SELECT recipient_name, street1, phone_number FROM mv_book_print_order WHERE user_id = {$owner['id']}")->fetch()), 'commande anonymisée, conservée');
        $this->assertSame(1, (int) self::db()->query("SELECT COUNT(*) FROM mv_chapter_payment WHERE user_id = {$owner['id']}")->fetchColumn(), 'achat conservé pour la comptabilité');

        // Plus aucune connexion ni accès avec l'ancien jeton
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . '/api/login', [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json'], json_encode(['username' => $owner['email'], 'password' => self::PASSWORD, 'platform' => 'mobile']));
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
        $this->assertSame(401, $this->as('GET', '/api/memoires/books?scope=my', null, $owner['token'])[0], 'l\'ancien jeton ne désigne plus personne');
        // Le registre garde la trace, sous l'identité anonymisée
        [, $ledger] = $this->api('GET', '/admin/payments?search=' . urlencode("supprime-{$owner['id']}"));
        $this->assertSame(['chapter', 'print'], array_values(array_unique(array_column($ledger['payments'], 'kind'))));
    }

    /** @return array{status: string, detail: string} */
    private function stripeWebhook(string $intentId): array
    {
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . '/api/stripe/webhook', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['id' => 'evt_' . bin2hex(random_bytes(4)), 'type' => 'payment_intent.amount_capturable_updated', 'data' => ['object' => ['id' => $intentId, 'object' => 'payment_intent', 'metadata' => ['memoires_print' => '1', 'tenant_code' => MV_TEST_TENANT_CODE]]]]));
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());

        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    /** @return array{id: int, email: string, token: string} utilisateur mobile avec un vrai mot de passe */
    private function userWithPassword(string $email): array
    {
        $container = static::getContainer();
        $provider = $container->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Jeanne')->setLastname('Dupont')->setRoles(['ROLE_USER_INTERNET'])->setIsVerified(true)->setSignupChannel(User::SIGNUP_MOBILE);
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD));
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
