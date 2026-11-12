<?php

namespace App\Tests\Functional\Security;

use App\Services\CaisseService\CashRegisterException;
use App\Services\Http\ClientSafeError;
use App\Tests\Fixtures\ErrorOrigin\src\Thrower;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Stripe\Exception\AuthenticationException;
use Stripe\Exception\CardException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Lot ERREURS-1 (12/11/2026) : le texte d'une exception n'est rendu au client que s'il a été écrit pour lui, c'est-à-dire levé depuis
 * notre code (ici le dossier `tests/Fixtures/ErrorOrigin/src`, qui joue `src/`) sous une forme connue. Tout le reste devient un texte
 * neutre, et le détail part au journal.
 */
class ClientSafeErrorTest extends TestCase
{
    private ClientSafeError $safe;
    /** @var list<array{level: string, message: string, context: array}> */
    private array $logged = [];

    protected function setUp(): void
    {
        $logged = &$this->logged;
        $logger = new class($logged) extends AbstractLogger {
            public function __construct(private array &$records)
            {
            }

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = ['level' => (string) $level, 'message' => (string) $message, 'context' => $context];
            }
        };
        $this->safe = new ClientSafeError($logger, dirname(__DIR__, 2) . '/Fixtures/ErrorOrigin');
    }

    public function testOnlyMessagesWrittenByOurCodeAreIntended(): void
    {
        $pdo = new \PDOException('SQLSTATE[22003]: Numeric value out of range: 7 ERROR: value "99999999999" is out of range for type integer');
        $cases = [
            // gardés : levés depuis notre code, sous une forme connue
            'refus métier en exception générique' => [Thrower::business(\Exception::class, 'Insufficient stock for product variant'), true],
            'RuntimeException de notre code' => [Thrower::business(\RuntimeException::class, 'Adresse invalide : code postal'), true],
            'InvalidArgumentException de notre code' => [Thrower::business(\InvalidArgumentException::class, 'Quantité invalide'), true],
            'DomainException de notre code' => [Thrower::business(\DomainException::class, 'Créneau déjà pris'), true],
            'exception de l\'application' => [Thrower::application('Caisse fermée'), true],
            'exception de l\'application créée hors de notre code (double de test)' => [new CashRegisterException('DEMO', 'Caisse fermée', 409), true],
            'refus d\'adresse nommé' => [new \App\Services\AdressService\AddressUndeliverableException('Adresse invalide : Address not found'), true],
            'exception HTTP de notre code' => [Thrower::notFound('Product not found for ID: 5'), true],
            'notre texte, avec une cause technique gardée en « previous »' => [Thrower::business(\Exception::class, 'Paiement impossible pour le moment', $pdo), true],
            // neutres : venus d'ailleurs
            'même exception générique, levée hors de notre code' => [new \RuntimeException('message d\'une bibliothèque'), false],
            'exception HTTP levée hors de notre code' => [new NotFoundHttpException('"App\\Entity\\Product" object not found by "EntityValueResolver".'), false],
            'exception PDO, même créée dans notre code' => [Thrower::business(\PDOException::class, 'SQLSTATE[08006] connexion refusée'), false],
            'erreur de PHP dans notre code (TypeError)' => [Thrower::typeError(), false],
            'exception née dans une classe de PHP appelée par notre code' => [Thrower::internal(), false],
            // la faille : notre exception reprend le texte de l'exception technique qu'elle enveloppe
            'exception de src qui enveloppe une exception PDO' => [Thrower::business(\Exception::class, 'Erreur : ' . $pdo->getMessage(), $pdo), false],
            'RuntimeException de src qui recopie le message PDO' => [Thrower::business(\RuntimeException::class, $pdo->getMessage(), $pdo), false],
            'enveloppe d\'une enveloppe' => [Thrower::business(\Exception::class, 'Échec : Erreur : ' . $pdo->getMessage(), Thrower::business(\RuntimeException::class, 'Erreur : ' . $pdo->getMessage(), $pdo)), false],
            // notre exception qui reprend le texte d'un refus métier reste un refus métier
            'enveloppe d\'un refus métier' => [Thrower::business(\Exception::class, 'Stock insuffisant', Thrower::business(\DomainException::class, 'Stock insuffisant')), true],
        ];
        $this->assertSame(\Exception::class, Thrower::internal()::class, 'cas témoin : une exception générique, mais écrite par PHP');
        $this->assertInstanceOf(\TypeError::class, Thrower::typeError());
        foreach ($cases as $name => [$e, $intended]) {
            $this->assertSame($intended, $this->safe->isIntended($e), $name);
            $this->logged = [];
            $text = $this->safe->message($e, 'route de test');
            if ($intended) {
                $this->assertSame($e->getMessage(), $text, $name);
                $this->assertSame([], $this->logged, "$name : rien au journal pour un refus métier");
            } else {
                $this->assertSame(ClientSafeError::NEUTRAL, $text, $name);
                $this->assertCount(1, $this->logged, $name);
                $this->assertSame('error', $this->logged[0]['level']);
                $this->assertStringContainsString($e->getMessage(), $this->logged[0]['message'], "$name : le détail est au journal");
                $this->assertStringContainsString('route de test', $this->logged[0]['message']);
                $this->assertSame($e, $this->logged[0]['context']['exception']);
            }
        }
    }

    public function testBodyKeepsTheKeysAndAddsTheNeutralCodeOnlyWhenUnexpected(): void
    {
        $business = Thrower::business(\Exception::class, 'Order not found');
        $failure = new \RuntimeException('SQLSTATE secret');
        $this->assertSame(['error' => 'Order not found'], $this->safe->body($business, 'r'));
        $this->assertSame(['message' => 'Order not found', 'success' => false], $this->safe->body($business, 'r', 'message', ['success' => false]));
        $this->assertSame(['error' => ClientSafeError::NEUTRAL, 'code' => 'INTERNAL_ERROR'], $this->safe->body($failure, 'r'));
        $this->assertSame(['error' => ClientSafeError::NEUTRAL, 'code' => 'BAD_REQUEST'], $this->safe->body($failure, 'r', 'error', ['code' => 'BAD_REQUEST']), 'un code déjà porté par la réponse ne change pas');
        $this->assertSame('Texte neutre choisi', $this->safe->message($failure, 'r', 'Texte neutre choisi'));
        $this->assertSame('Une erreur interne est survenue. Veuillez réessayer dans quelques instants.', ClientSafeError::NEUTRAL);
    }

    public function testStripeKeepsADeclinedCardAndHidesEverythingElse(): void
    {
        $card = CardException::factory('Your card was declined.', 402, null, ['error' => ['decline_code' => 'insufficient_funds', 'code' => 'card_declined']], null, 'card_declined', 'insufficient_funds');
        $auth = AuthenticationException::factory('Invalid API Key provided: sk_live_********************abcd', 401);
        $this->assertSame(['Your card was declined.', 'insufficient_funds', true], [$this->safe->stripe($card, 'paiement'), ClientSafeError::declineCode($card), $this->safe->isIntended($card)]);
        $this->assertSame([], $this->logged, 'carte refusée : rien au journal');
        $this->assertSame([ClientSafeError::PAYMENT_NEUTRAL, null, false], [$this->safe->stripe($auth, 'paiement'), ClientSafeError::declineCode($auth), $this->safe->isIntended($auth)]);
        $this->assertCount(1, $this->logged);
        $this->assertStringContainsString('sk_live_', $this->logged[0]['message'], 'le détail reste au journal, jamais dans la réponse');
        $this->assertStringNotContainsString('sk_live', ClientSafeError::PAYMENT_NEUTRAL);
        $this->assertSame(ClientSafeError::NEUTRAL, $this->safe->message($auth, 'r'), 'par la voie générale aussi');
        // Limite connue : une exception de l'application qui recopie un message technique SANS garder la cause ne se reconnaît pas à
        // l'exécution. C'est le garde-fou (ClientErrorGuardTest) qui interdit de l'écrire.
        $wrapped = new CashRegisterException('STRIPE_ERROR', 'Stripe : ' . $auth->getMessage(), 502);
        $this->assertTrue($this->safe->isIntended($wrapped));
    }
}
