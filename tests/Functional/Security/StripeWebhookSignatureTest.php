<?php

namespace App\Tests\Functional\Security;

use App\Controller\StripeController\StripeWebhookController;
use App\Entity\User;
use App\MemoiresVivantes\Entity\Book;
use App\MemoiresVivantes\Services\BookCheckoutSessionReader;
use App\MemoiresVivantes\Services\BookPaymentService;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Lot WEBHOOK-SIG (20/11/2026, faille de sécurité) :
 * - hors environnement des essais (la production tourne en APP_ENV=dev), un événement Stripe SANS signature est refusé (400
 *   SIGNATURE_REQUIRED) avant tout traitement, sur TOUS les chemins ; le refus est compté dans les incidents de la plateforme ;
 * - un livre de Mémoires Vivantes n'est marqué payé que si Stripe, relu, dit sa session payée (jamais d'après le contenu de l'événement),
 *   et seulement la session de CE livre ; un livre déjà payé ne reçoit pas de deuxième facture.
 */
class StripeWebhookSignatureTest extends KernelTestCase
{
    private const SESSION = 'cs_test_webhook_sig';

    private TenantEntityManagerProvider $provider;
    private ?Book $book = null;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $this->provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $em = $this->provider->getEntityManager();
        $email = 'webhook-sig-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Sig')->setLastname('Test')->setRoles(['ROLE_USER_INTERNET'])->setPassword('x')->setIsVerified(true);
        $em->persist($user);
        $this->book = (new Book())->setTitle('Livre webhook-sig')->setUser($user)->setStripeSessionId(self::SESSION)->setPaymentStatus('pending');
        $em->persist($this->book);
        $em->flush();
    }

    protected function tearDown(): void
    {
        $em = $this->provider->getEntityManager();
        $em->getConnection()->executeStatement("DELETE FROM mv_book WHERE title LIKE 'Livre webhook-sig%'");
        $em->getConnection()->executeStatement("DELETE FROM \"user\" WHERE email LIKE 'webhook-sig-%@example.invalid'");
        parent::tearDown();
    }

    public function testUnsignedEventsAreRefusedOnEveryPathOutsideTheTestEnvironment(): void
    {
        $controller = $this->controller('dev');
        static::getContainer()->get('cache.state')->deleteItem('stripe_webhook_refusal_w_stripe_webhook_unsigned');
        $before = $this->incidentCount('stripe_webhook.unsigned');
        $types = ['checkout.session.completed', 'payment_intent.succeeded', 'payment_intent.amount_capturable_updated', 'charge.refunded',
            'customer.subscription.updated', 'invoice.paid', 'account.updated', 'terminal.reader.action_succeeded', 'type.inconnu'];
        foreach ($types as $type) {
            $payload = json_encode(['id' => 'evt_sig_' . $type, 'type' => $type, 'data' => ['object' => ['id' => self::SESSION, 'payment_status' => 'paid',
                'metadata' => ['book_id' => (string) $this->book->getId(), 'tenant_code' => MV_TEST_TENANT_CODE, 'memoires_print' => '1'], 'payment_intent' => 'pi_x', 'amount_refunded' => 100]]]);
            $response = $controller->handleWebhook(Request::create('/api/stripe/webhook', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], $payload));
            $this->assertSame(400, $response->getStatusCode(), "$type sans signature : refusé");
            $this->assertSame('SIGNATURE_REQUIRED', json_decode((string) $response->getContent(), true)['code'] ?? null, $type);
        }
        $this->provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $this->provider->getEntityManager()->clear();
        $this->assertSame('pending', $this->provider->getEntityManager()->find(Book::class, $this->book->getId())->getPaymentStatus(), 'rien d\'écrit : le livre n\'est pas payé');
        $this->assertSame($before + 1, $this->incidentCount('stripe_webhook.unsigned'), 'refus compté dans les incidents, une fois par minute (pas d\'inondation de la base maître)');
    }

    public function testABookIsPaidOnlyWhenStripeSaysItsOwnSessionIsPaid(): void
    {
        $forged = ['id' => self::SESSION, 'payment_status' => 'paid', 'metadata' => ['book_id' => (string) $this->book->getId()]];
        $status = fn (): string => $this->reload()->getPaymentStatus();

        // Stripe ne connaît pas la session, ou ne la dit pas payée, ou rend une autre session : jamais « payé »
        foreach ([null, ['id' => self::SESSION, 'payment_status' => 'unpaid'], ['id' => 'cs_autre', 'payment_status' => 'paid']] as $stripe) {
            $this->assertNull($this->bookService($stripe)->handleCheckoutCompleted(self::SESSION, $forged));
            $this->assertSame('pending', $status(), 'le contenu de l\'événement n\'est jamais cru');
        }
        // Stripe en panne : rien d'écrit, et l'erreur remonte pour que Stripe renvoie l'événement
        try {
            $this->bookService(new \RuntimeException('Stripe injoignable'))->handleCheckoutCompleted(self::SESSION, $forged);
            $this->fail('la panne doit remonter');
        } catch (\App\MemoiresVivantes\Services\BookPaymentRetryLater) {
        }
        $this->assertSame('pending', $status());

        // Lien régénéré : le livre porte un NOUVEAU lien, le client a payé l'ANCIEN, que Stripe rattache à ce livre : payé (l'argent est encaissé)
        $this->provider->getEntityManager()->getConnection()->executeStatement('UPDATE mv_book SET stripe_session_id = ? WHERE id = ?', ['cs_test_nouveau_lien', (string) $this->book->getId()]);
        $paid = ['id' => self::SESSION, 'payment_status' => 'paid', 'metadata' => ['book_id' => (string) $this->book->getId()], 'amount_total' => 14900, 'currency' => 'cad'];
        $this->assertNotNull($this->bookService($paid)->handleCheckoutCompleted(self::SESSION, []));
        $this->assertSame('paid', $status());

        // Déjà payé : rien ne change (ni deuxième facture ni nouvelle date de paiement), même événement livré deux fois
        $paidAt = $this->reload()->getPaidAt();
        $this->assertNotNull($this->bookService($paid)->handleCheckoutCompleted(self::SESSION, []));
        $this->assertEquals($paidAt, $this->reload()->getPaidAt(), 'la seconde livraison n\'écrit rien');
    }

    public function testTheSynchronisationThatJustReadTheSessionIsTrusted(): void
    {
        // syncPaymentStatus vient de relire la session chez Stripe : `verified` évite une seconde relecture
        $paid = ['id' => self::SESSION, 'payment_status' => 'paid', 'metadata' => ['book_id' => (string) $this->book->getId()]];
        $this->assertNotNull($this->bookService(null)->handleCheckoutCompleted(self::SESSION, $paid, true));
        $this->assertSame('paid', $this->reload()->getPaymentStatus());
    }

    public function testASignedEventIsAcceptedWithEitherSecretAndAWrongSignatureIsRefused(): void
    {
        $saved = [$_ENV['STRIPE_WEBHOOK_SECRET'] ?? null, $_ENV['STRIPE_CONNECT_WEBHOOK_SECRET'] ?? null];
        $_ENV['STRIPE_WEBHOOK_SECRET'] = 'whsec_essai_plateforme';
        $_ENV['STRIPE_CONNECT_WEBHOOK_SECRET'] = 'whsec_essai_connect';
        try {
            $controller = $this->controller('dev');
            $payload = (string) json_encode(['id' => 'evt_signe', 'object' => 'event', 'type' => 'type.inconnu', 'data' => ['object' => ['id' => 'obj_1', 'object' => 'thing']]]);
            $sign = static fn (string $secret): string => 't=' . time() . ',v1=' . hash_hmac('sha256', time() . '.' . $payload, $secret);
            foreach (['whsec_essai_plateforme', 'whsec_essai_connect'] as $secret) {
                $ok = $controller->handleWebhook(Request::create('/api/stripe/webhook', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => $sign($secret)], $payload));
                $this->assertSame(200, $ok->getStatusCode(), "signé avec $secret : accepté");
            }
            $before = $this->incidentCount('stripe_webhook.invalid_signature');
            static::getContainer()->get('cache.state')->deleteItem('stripe_webhook_refusal_w_stripe_webhook_invalid_signature');
            $bad = $controller->handleWebhook(Request::create('/api/stripe/webhook', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => $sign('whsec_autre')], $payload));
            $this->assertSame([400, 'SIGNATURE_INVALID'], [$bad->getStatusCode(), json_decode((string) $bad->getContent(), true)['code'] ?? null]);
            $this->assertSame($before + 1, $this->incidentCount('stripe_webhook.invalid_signature'), 'le refus est compté');
            // un second refus dans la minute n'écrit plus dans la base maître
            $controller->handleWebhook(Request::create('/api/stripe/webhook', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => $sign('whsec_autre')], $payload));
            $this->assertSame($before + 1, $this->incidentCount('stripe_webhook.invalid_signature'), 'au plus un incident par minute');
        } finally {
            [$_ENV['STRIPE_WEBHOOK_SECRET'], $_ENV['STRIPE_CONNECT_WEBHOOK_SECRET']] = $saved;
        }
    }

    /** Le contrôleur réel, avec l'environnement voulu (la production : « dev ») */
    private function controller(string $environment): StripeWebhookController
    {
        return self::rebuild(static::getContainer()->get(StripeWebhookController::class), ['environment' => $environment]);
    }

    /** Le service réel, dont la relecture chez Stripe rend $stripe (tableau, null, ou une exception levée) */
    private function bookService(mixed $stripe): BookPaymentService
    {
        $reader = new class ($stripe) extends BookCheckoutSessionReader {
            public function __construct(private readonly mixed $answer)
            {
            }

            public function retrieve(string $sessionId): ?array
            {
                if ($this->answer instanceof \Throwable) {
                    throw $this->answer;
                }

                return $this->answer;
            }
        };

        return self::rebuild(static::getContainer()->get(BookPaymentService::class), ['sessions' => $reader]);
    }

    /**
     * Une copie de l'objet dont certaines propriétés (même en lecture seule) sont remplacées, initialisée dans la portée de sa classe.
     *
     * @template T of object
     * @param T $real
     * @return T
     */
    private static function rebuild(object $real, array $overrides): object
    {
        $copy = (new \ReflectionClass($real))->newInstanceWithoutConstructor();
        (function () use ($real, $overrides): void {
            foreach ((new \ReflectionClass($real))->getProperties() as $property) {
                if ($property->isStatic() || !$property->isInitialized($real) && !array_key_exists($property->getName(), $overrides)) {
                    continue;
                }
                $name = $property->getName();
                $this->$name = array_key_exists($name, $overrides) ? $overrides[$name] : $property->getValue($real);
            }
        })->call($copy);

        return $copy;
    }

    private function reload(): Book
    {
        $this->provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $em = $this->provider->getEntityManager();
        $em->clear();

        return $em->find(Book::class, $this->book->getId());
    }

    private function incidentCount(string $route): int
    {
        return (int) static::getContainer()->get(TenantConnectionManager::class)->getPdoMaster()->query("SELECT COALESCE(SUM(count), 0) FROM platform_incident WHERE route = '" . $route . "'")->fetchColumn();
    }
}
