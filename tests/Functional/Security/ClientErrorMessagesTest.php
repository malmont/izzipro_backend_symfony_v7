<?php

namespace App\Tests\Functional\Security;

use App\Entity\Collections;
use App\Entity\Commande;
use App\Entity\Order;
use App\Entity\OrderSource;
use App\Entity\StatusCommande;
use App\Entity\User;
use App\EventSubscriber\CacheInvalidationSubscriber;
use App\Services\AdressService\AddressVerificationService;
use App\Services\Http\ClientSafeError;
use App\Services\TenantEntityManagerProvider;
use App\Tests\Functional\Cache\CacheTestEntityFactory;
use App\UseCase\Booking\CheckAvailabilityUseCase;
use App\UseCase\Booking\GetCalendarAvailabilityUseCase;
use App\UseCase\ProductUseCase\CreateProductByCommandeUseCase;
use App\UseCase\ProductUseCase\GetProductByIdUseCase;
use App\UseCase\ProductUseCase\GetProductBySlugUseCase;
use App\UseCase\ShippingUseCase\PurchaseShippingForCart;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\AbstractLogger;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Lot ERREURS-1 (12/11/2026, `docs/erreurs-client.md`) : sur les routes de la boutique et de la gestion qui attrapaient largement, une
 * panne imprévue ne renvoie plus son message au client. Pour chaque route, le service appelé est remplacé par un double qui lève une
 * exception portant un texte témoin : la réponse garde son statut et ses clés, porte le texte neutre, jamais le témoin ; le journal, lui,
 * reçoit le témoin. Les refus métier des mêmes routes ne changent pas.
 */
class ClientErrorMessagesTest extends WebTestCase
{
    use CacheTestEntityFactory;

    private const WITNESS = 'TEMOIN-SQLSTATE[08006] connexion refusée pour app_user@10.0.0.7';
    private KernelBrowser $client;
    /** @var list<string> messages reçus par le journal */
    private array $logged = [];

    protected function setUp(): void
    {
        $this->fresh();
    }

    public function testProductSheetsKeepTheirStatusAndHideTheFailure(): void
    {
        $this->replace(GetProductByIdUseCase::class);
        $this->assertNeutral($this->call('GET', '/api/productsid/987654'), 404, 'GET /api/productsid/{id}');

        $this->fresh();
        $slug = 'erreurs-' . bin2hex(random_bytes(3));
        $this->em()->getConnection()->executeStatement("INSERT INTO product (id, name, price, isbestseller, quantity, created_at, slug, product_type) VALUES (nextval('product_id_seq'), 'Article ERREURS', 1000, false, 1, NOW(), ?, 'standard')", [$slug]);
        $this->replace(GetProductBySlugUseCase::class);
        $this->assertNeutral($this->call('GET', "/api/products/by-slug/$slug"), 404, 'GET /api/products/by-slug/{slug}');

        // Refus métier inchangés : article inconnu
        $this->fresh();
        [$status, $body] = $this->call('GET', '/api/productsid/987655');
        $this->assertSame([404, 'Product not found for ID: 987655', false], [$status, $body['error'], array_key_exists('code', $body)], 'le message voulu reste, sans code ajouté');
        $this->assertSame([], $this->logged, 'un refus métier ne va pas au journal des pannes');
        [$status, $body] = $this->call('GET', '/api/products/by-slug/aucun-article-' . bin2hex(random_bytes(3)));
        $this->assertSame([404, 'Produit introuvable'], [$status, $body['error']]);
    }

    public function testCreatingAnArticleInAnOrderHidesTheFailure(): void
    {
        $collection = $this->create(Collections::class);
        $commande = $this->create(Commande::class, [], fn (Commande $c, EntityManagerInterface $em) => $c->setCollections($em->find(Collections::class, $collection->getId())));
        $this->endOfWrite();
        $token = $this->token(['ROLE_ADMIN']);
        $this->replace(CreateProductByCommandeUseCase::class);
        $this->client->request('POST', '/api/commandes/' . $commande->getId() . '/products', ['name' => 'Article', 'purchasePrice' => '1000', 'coefficientMultiplier' => '2'], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        $this->assertNeutral([$this->client->getResponse()->getStatusCode(), json_decode((string) $this->client->getResponse()->getContent(), true) ?? []], 500, 'POST /api/commandes/{id}/products');
    }

    public function testBuyingALabelNoLongerAnswersADebugMessage(): void
    {
        $order = $this->create(Order::class, [], function (Order $o, EntityManagerInterface $em): void {
            $o->setStatus($em->getRepository(StatusCommande::class)->findOneBy([]));
            $o->setOrderSource($em->find(OrderSource::class, 2));
        });
        $this->endOfWrite();
        $token = $this->token(['ROLE_ADMIN']);
        $this->replace(PurchaseShippingForCart::class);
        [$status, $body] = $this->call('POST', '/api/shipping/buy', ['orderId' => $order->getId(), 'cartItems' => [], 'to' => [], 'from' => [], 'carrierAccountId' => 'ca_x', 'service' => 'Ground'], $token);
        $this->assertNeutral([$status, $body], 500, 'POST /api/shipping/buy');
        $this->assertStringNotContainsString('DEBUG', json_encode($body), 'avant : « DEBUG ERROR: » suivi du message');
    }

    public function testBookingReadsHideTheFailureAndRefuseABadDate(): void
    {
        // Disponibilité : une panne répond 500 neutre, quel que soit le code porté par l'exception (avant : ce code servait de statut)
        $this->replace(CheckAvailabilityUseCase::class, new \RuntimeException(self::WITNESS, 404));
        $this->assertNeutral($this->call('GET', '/api/booking/check/1?start=2026-12-01%2010:00&end=2026-12-01%2012:00&quantity=1'), 500, 'GET /api/booking/check/{productId}');

        $this->fresh();
        $this->replace(GetCalendarAvailabilityUseCase::class);
        $this->assertNeutral($this->call('GET', '/api/booking/calendar/1?start=2026-12-01&end=2026-12-02'), 400, 'GET /api/booking/calendar/{productId}');

        $this->fresh();
        [$status, $body] = $this->call('GET', '/api/booking/calendar/1?start=pas-une-date&end=2026-12-02');
        $this->assertSame([400, ['error' => 'Format de date invalide (attendu : YYYY-MM-DD)']], [$status, $body], 'avant : le message d\'analyse de PHP');
        [$status, $body] = $this->call('GET', '/api/booking/calendar/1?start=2026-12-01');
        $this->assertSame([400, 'Paramètres start et end requis'], [$status, $body['error']], 'refus métier inchangé');
    }

    public function testAnAddressServiceFailureIsNotShownToTheCustomer(): void
    {
        $token = $this->token(['ROLE_USER_INTERNET']);
        $verifier = $this->createMock(AddressVerificationService::class);
        $verifier->method('verifyOrKeep')->willThrowException(new \RuntimeException(self::WITNESS));
        static::getContainer()->set(AddressVerificationService::class, $verifier);
        [$status, $body] = $this->call('POST', '/api/adresses', ['firstname' => 'Marie', 'lastname' => 'Tremblay', 'addressLineOne' => '1234 rue Sainte-Catherine O', 'addressLineTwo' => 'app. 5', 'city' => 'Montréal',
            'province' => 'Québec', 'zipCode' => 'H3G 1P1', 'country' => 'CA', 'contactNumber' => '+1 514 555 0100', 'isPrimary' => false], $token);
        $this->assertSame([400, 'ADDRESS_UNDELIVERABLE', ClientSafeError::NEUTRAL, ['address' => ClientSafeError::NEUTRAL]], [$status, $body['code'], $body['message'], $body['error']], 'statut, code et clés d\'aujourd\'hui ; texte neutre');
        $this->assertStringNotContainsString('TEMOIN', json_encode($body));
        $this->assertStringContainsString(self::WITNESS, implode("\n", $this->logged), 'le détail est au journal');
    }

    // ----- outils -----

    /**
     * ERREURS-3, part Mémoires Vivantes (13/11/2026) : lien de paiement, devis et commande d'impression, transcription et amélioration
     * de texte. Une panne du service appelé rend le texte neutre avec INTERNAL_ERROR, statut inchangé ; une erreur Stripe autre qu'une
     * carte refusée rend le texte neutre du paiement, sans le message de Stripe.
     */
    public function testMemoiresVivantesRoutesHideTheFailure(): void
    {
        [$token, $bookId, $chapterId] = $this->memoiresFixtures();

        $this->replace(\App\MemoiresVivantes\UseCase\Payment\GenerateBookPaymentLinkUseCase::class);
        $this->assertNeutral($this->call('POST', "/api/memoires/books/$bookId/payment-link", ['recipient_email' => 'client@example.invalid'], $token), 500, 'POST /api/memoires/books/{id}/payment-link');

        $this->fresh();
        $this->replace(\App\MemoiresVivantes\UseCase\Payment\GenerateBookPaymentLinkUseCase::class, new \Stripe\Exception\InvalidRequestException('TEMOIN : No such account: acct_1TEMOIN (clé sk_test_TEMOIN)'));
        [$status, $body] = $this->call('POST', "/api/memoires/books/$bookId/payment-link", ['recipient_email' => 'client@example.invalid'], $token);
        $this->assertSame([500, 'Stripe : ' . ClientSafeError::PAYMENT_NEUTRAL, 'STRIPE_ERROR'], [$status, $body['error'] ?? null, $body['code'] ?? null], json_encode($body));
        $this->assertStringNotContainsString('TEMOIN', json_encode($body), 'le message de Stripe ne sort pas');
        $this->assertStringContainsString('TEMOIN', implode("\n", $this->logged), 'mais il est au journal');

        $this->fresh();
        $this->replace(\App\MemoiresVivantes\UseCase\Print\EstimateBookPrintUseCase::class);
        $this->assertNeutral($this->call('POST', "/api/memoires/books/$bookId/print/estimate", ['shipping_address' => ['country_code' => 'FR'], 'quantity' => 1], $token), 400, 'POST /api/memoires/books/{id}/print/estimate');

        $this->fresh();
        $this->replace(\App\MemoiresVivantes\UseCase\Print\CreateBookPrintOrderUseCase::class);
        [$status, $body] = $this->call('POST', "/api/memoires/books/$bookId/print/order", ['shipping_address' => ['country_code' => 'FR'], 'quantity' => 1], $token);
        $this->assertNeutral([$status, $body], 400, 'POST /api/memoires/books/{id}/print/order');
        $this->assertFalse($body['success'], 'la clé success de la réponse est gardée');

        $this->fresh();
        $this->replace(\App\MemoiresVivantes\UseCase\ImproveAnswerUseCase::class);
        $this->assertNeutral($this->call('POST', "/api/memoires/chapters/$chapterId/improve-answer", ['question' => 'Où êtes-vous né ?', 'answer' => 'Mon enfance à la campagne.', 'index' => 0], $token), 500, 'POST /api/memoires/chapters/{id}/improve-answer');

        $this->fresh();
        $this->replace(\App\MemoiresVivantes\UseCase\TranscribeAudioUseCase::class);
        $audio = tempnam(sys_get_temp_dir(), 'erreurs') . '.webm';
        file_put_contents($audio, str_repeat('0', 2048));
        $this->client->request('POST', "/api/memoires/chapters/$chapterId/audio", ['questionIndex' => 'q1'], ['file' => new \Symfony\Component\HttpFoundation\File\UploadedFile($audio, 'reponse.webm', 'audio/webm', null, true)],
            ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        $this->assertNeutral([$this->client->getResponse()->getStatusCode(), json_decode((string) $this->client->getResponse()->getContent(), true) ?? []], 500, 'POST /api/memoires/chapters/{id}/audio');
        @unlink($audio);
    }

    /** @return array{0: string, 1: string, 2: string} jeton d'un administrateur, livre et chapitre à lui */
    private function memoiresFixtures(): array
    {
        $em = $this->em();
        $email = 'erreurs-mv-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Erreurs')->setLastname('Mémoires')->setRoles(['ROLE_ADMIN'])->setIsVerified(true)->setPassword('inutilisable');
        $em->persist($user);
        $book = (new \App\MemoiresVivantes\Entity\Book())->setTitle('Livre ERREURS-3')->setType('individuel')->setPerson1FirstName('Jeanne')->setUser($user);
        $em->persist($book);
        $chapter = (new \App\MemoiresVivantes\Entity\Chapter())->setBook($book)->setTheme('enfance')->setTitle('Enfance')->setPosition(1)->setAnswers([]);
        $em->persist($chapter);
        $em->flush();
        $this->endOfWrite();

        return [static::getContainer()->get('lexik_jwt_authentication.jwt_manager')->create($user), (string) $book->getId(), (string) $chapter->getId()];
    }

    /** Nouveau noyau (un double ne se pose que sur un service pas encore construit) et journal espion */
    private function fresh(): void
    {
        static::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->logged = [];
        $logged = &$this->logged;
        $logger = new class($logged) extends AbstractLogger {
            public function __construct(private array &$records)
            {
            }

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = (string) $message;
            }
        };
        static::getContainer()->set(ClientSafeError::class, new ClientSafeError($logger, (string) static::getContainer()->getParameter('kernel.project_dir')));
    }

    /** Remplace un cas d'usage par un double dont `execute` lève une panne (née ici, donc hors de `src/`) */
    private function replace(string $class, ?\Throwable $failure = null): void
    {
        $double = $this->createMock($class);
        $double->method('execute')->willThrowException($failure ?? new \RuntimeException(self::WITNESS));
        static::getContainer()->set($class, $double);
    }

    /** @return array{0: int, 1: array<string, mixed>} */
    private function call(string $method, string $path, ?array $body = null, ?string $token = null): array
    {
        $this->client->request($method, $path, [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json']
            + ($token !== null ? ['HTTP_AUTHORIZATION' => 'Bearer ' . $token] : []), $body !== null ? json_encode($body) : null);

        return [$this->client->getResponse()->getStatusCode(), json_decode((string) $this->client->getResponse()->getContent(), true) ?? []];
    }

    /** @param array{0: int, 1: array<string, mixed>} $response */
    private function assertNeutral(array $response, int $status, string $route): void
    {
        [$actual, $body] = $response;
        $this->assertSame([$status, ClientSafeError::NEUTRAL, 'INTERNAL_ERROR'], [$actual, $body['error'] ?? null, $body['code'] ?? null], "$route : statut gardé, texte neutre, code ajouté ; reçu " . json_encode($body, JSON_UNESCAPED_UNICODE));
        $this->assertStringNotContainsString('TEMOIN', json_encode($body), "$route : le message de la panne ne sort pas");
        $this->assertStringContainsString(self::WITNESS, implode("\n", $this->logged), "$route : le détail est au journal");
        $this->assertStringContainsString($route, implode("\n", $this->logged), "$route : la route est nommée au journal");
    }

    private function token(array $roles): string
    {
        $em = $this->em();
        $email = 'erreurs-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Erreurs')->setLastname('Test')->setRoles($roles)->setIsVerified(true)->setPassword('inutilisable');
        $em->persist($user);
        $em->flush();
        $this->endOfWrite();

        return static::getContainer()->get('lexik_jwt_authentication.jwt_manager')->create($user);
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
}
