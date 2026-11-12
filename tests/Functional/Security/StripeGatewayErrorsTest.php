<?php

namespace App\Tests\Functional\Security;

use App\Services\CaisseService\CashRegisterException;
use App\Services\Http\ClientSafeError;
use App\Services\PosStripeService\StripePosAccountGateway;
use App\Services\PosTerminalService\PosTerminalGatewayException;
use App\Services\PosTerminalService\StripeTerminalGateway;
use App\Services\StripeService\StripeAccountResolver;
use App\Services\StripeService\StripeConnectSetupService;
use App\Services\SubscriptionService\SubscriptionException;
use App\Services\SubscriptionService\SubscriptionStripeGateway;
use App\Services\TenantConnectionProvider;
use App\Services\TenantEntityManagerProvider;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Stripe\Exception\AuthenticationException;
use Stripe\Exception\CardException;
use Stripe\Exception\InvalidRequestException;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Lot ERREURS-1 (12/11/2026) : les passerelles Stripe ne renvoient plus le message brut d'une erreur de l'API. Une carte refusée garde
 * le texte de Stripe et son code de refus ; toute autre erreur (ici une clé refusée, dont le message cite un fragment de clé) donne un
 * texte neutre, et le détail part au journal.
 */
class StripeGatewayErrorsTest extends TestCase
{
    private const KEY_LEAK = 'Invalid API Key provided: sk_live_********************abcd';
    /** @var list<string> */
    private array $logged = [];
    private AbstractLogger $logger;
    private ClientSafeError $safe;

    protected function setUp(): void
    {
        $logged = &$this->logged;
        $this->logger = new class($logged) extends AbstractLogger {
            public function __construct(private array &$records)
            {
            }

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = (string) $message;
            }
        };
        $this->safe = new ClientSafeError($this->logger, dirname(__DIR__, 3));
    }

    public function testCashRegisterAccountGateway(): void
    {
        $accounts = $this->createMock(StripeAccountResolver::class);
        $accounts->method('secretKey')->willReturn('sk_test_fictif');
        $gateway = new StripePosAccountGateway($accounts, $this->safe);
        $call = fn (\Throwable $failure) => $this->thrownBy(fn () => $this->invoke($gateway, 'call', ['test', fn () => throw $failure]));

        $e = $call($this->authentication());
        $this->assertInstanceOf(CashRegisterException::class, $e);
        $this->assertSame(['STRIPE_ERROR', 502, 'Stripe : ' . ClientSafeError::PAYMENT_NEUTRAL], [$e->errorCode, $e->status, $e->getMessage()]);
        $this->assertStringNotContainsString('sk_live', json_encode($e->toBody()));
        $this->assertArrayNotHasKey('declineCode', $e->toBody());
        $this->assertStringContainsString('sk_live_', implode("\n", $this->logged), 'détail au journal');

        $e = $call($this->card());
        $this->assertSame(['STRIPE_ERROR', 'Stripe : Your card was declined.', 'insufficient_funds'], [$e->errorCode, $e->getMessage(), $e->toBody()['declineCode'] ?? null], 'carte refusée : texte de Stripe et code de refus');
    }

    public function testSubscriptionGateway(): void
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findOneBy')->willReturn(null);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repository);
        $provider = $this->createMock(TenantEntityManagerProvider::class);
        $provider->method('getEntityManager')->willReturn($em);
        $gateway = new SubscriptionStripeGateway($provider, $this->createMock(TenantConnectionProvider::class), $this->createMock(CacheInterface::class), (new \ReflectionClass(StripeConnectSetupService::class))->newInstanceWithoutConstructor(), 'sk_test_fictif', $this->safe); // classe finale : instance vide, non utilisée ici
        $call = fn (\Throwable $failure) => $this->thrownBy(fn () => $this->invoke($gateway, 'call', [fn () => throw $failure]));

        $e = $call($this->authentication());
        $this->assertInstanceOf(SubscriptionException::class, $e);
        $this->assertSame([502, 'Stripe : ' . ClientSafeError::PAYMENT_NEUTRAL, 'STRIPE_ERROR', []], [$e->getStatusCode(), $e->getMessage(), $e->code(), $e->details]);
        $this->assertStringContainsString('sk_live_', implode("\n", $this->logged));

        $e = $call($this->card());
        $this->assertSame(['Stripe : Your card was declined.', ['declineCode' => 'insufficient_funds']], [$e->getMessage(), $e->details]);
    }

    public function testTerminalGateway(): void
    {
        $accounts = $this->createMock(StripeAccountResolver::class);
        $accounts->method('mode')->willReturn('test');
        $accounts->method('secretKey')->willReturn('sk_test_fictif');
        $accounts->method('accountOptions')->willReturn([]);
        $gateway = new StripeTerminalGateway($accounts, $this->logger);
        $call = fn (\Throwable $failure) => $this->thrownBy(fn () => $this->invoke($gateway, 'call', [fn () => throw $failure, null, 'essai']));

        $e = $call(InvalidRequestException::factory('No such payment_intent: pi_3SECRET ; ' . self::KEY_LEAK, 400, null, null, null, 'parameter_unknown'));
        $this->assertInstanceOf(PosTerminalGatewayException::class, $e);
        $this->assertSame(['STRIPE_ERROR', 'Erreur Stripe : ' . ClientSafeError::PAYMENT_NEUTRAL], [$e->errorCode, $e->getMessage()], 'code Stripe non reconnu : texte neutre (avant : le message brut)');
        $this->assertStringContainsString('pi_3SECRET', implode("\n", $this->logged), 'détail au journal');

        $e = $call(InvalidRequestException::factory('Reader is offline', 400, null, null, null, 'terminal_reader_offline'));
        $this->assertSame(['READER_OFFLINE', 'Le terminal est hors ligne : vérifiez son alimentation et sa connexion'], [$e->errorCode, $e->getMessage()], 'codes reconnus : textes rédigés, inchangés');

        $e = $call($this->card());
        $this->assertSame(['PAYMENT_DECLINED', 'Your card was declined.'], [$e->errorCode, $e->getMessage()], 'carte refusée : inchangé');
    }

    private function authentication(): AuthenticationException
    {
        return AuthenticationException::factory(self::KEY_LEAK, 401);
    }

    private function card(): CardException
    {
        return CardException::factory('Your card was declined.', 402, null, ['error' => ['decline_code' => 'insufficient_funds', 'code' => 'card_declined']], null, 'card_declined', 'insufficient_funds');
    }

    private function invoke(object $object, string $method, array $arguments): mixed
    {
        return (new \ReflectionMethod($object, $method))->invokeArgs($object, $arguments);
    }

    private function thrownBy(callable $action): \Throwable
    {
        try {
            $action();
        } catch (\Throwable $e) {
            return $e;
        }
        $this->fail('une exception était attendue');
    }
}
