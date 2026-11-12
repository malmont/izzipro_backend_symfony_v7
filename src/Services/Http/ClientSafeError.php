<?php

namespace App\Services\Http;

use Psr\Log\LoggerInterface;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\CardException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Message d'une exception montrable au client, ou texte neutre (lot ERREURS-1, 12/11/2026, `docs/erreurs-client.md`).
 *
 * Un « catch » large attrape à la fois nos refus métier (« Insufficient stock for product variant », levé par notre code) et les pannes
 * imprévues (message SQL, erreur d'une bibliothèque, erreur de PHP). Avant ce lot, les deux partaient tels quels vers le client. La règle,
 * par origine :
 * - message GARDÉ : exception de l'application (`App\…`) ; exception HTTP ou générique (`Exception`, `RuntimeException`,
 *   `InvalidArgumentException`, `DomainException`, `LogicException`, classe exacte) levée depuis notre code (`src/`) ; et une
 *   `CardException` de Stripe (carte refusée : texte destiné au payeur) ;
 * - message NEUTRE, détail au journal : tout le reste, donc ce qui est levé depuis une bibliothèque (Doctrine, PDO, Stripe hors carte,
 *   Symfony, transporteurs) et les erreurs de PHP (`TypeError`…), ainsi qu'une exception de notre code qui reprend dans son texte celui
 *   d'une exception technique qu'elle enveloppe (`new \Exception('Erreur : ' . $e->getMessage(), 0, $e)`).
 *
 * Le statut HTTP et les clés de la réponse ne changent pas : seul le texte change. Le garde-fou `ClientErrorMessagesTest` refuse un
 * nouveau « catch » large qui rendrait `getMessage()` au client sans passer par ici.
 */
final class ClientSafeError
{
    public const NEUTRAL = 'Une erreur interne est survenue. Veuillez réessayer dans quelques instants.';
    public const CODE = 'INTERNAL_ERROR';
    public const PAYMENT_NEUTRAL = 'Le service de paiement n\'a pas pu traiter la demande. Veuillez réessayer dans quelques instants.';
    private const GENERIC = [\Exception::class, \RuntimeException::class, \InvalidArgumentException::class, \DomainException::class, \LogicException::class];

    private readonly string $sourceDir;

    public function __construct(private readonly LoggerInterface $logger, #[Autowire('%kernel.project_dir%')] string $projectDir)
    {
        $this->sourceDir = rtrim($projectDir, '/') . '/src/';
    }

    /** Vrai si le texte de l'exception a été écrit pour le client (voir l'en-tête) */
    public function isIntended(\Throwable $e): bool
    {
        if ($e instanceof CardException) {
            return true;
        }
        if ($e instanceof ApiErrorException) {
            return false;
        }
        $class = $e::class;
        $application = str_starts_with($class, 'App\\'); // une exception de l'application est la nôtre, où qu'elle soit créée
        if (!$application && (!str_starts_with($e->getFile(), $this->sourceDir) || (!$e instanceof HttpExceptionInterface && !in_array($class, self::GENERIC, true)))) {
            return false;
        }
        // Née dans une fonction ou une classe de PHP appelée à cette ligne (`new \SimpleXMLElement('…')`) : texte de PHP, pas le nôtre
        $frame = $e->getTrace()[0] ?? null;
        if ($frame !== null && ($frame['file'] ?? null) === $e->getFile() && ($frame['line'] ?? null) === $e->getLine()) {
            $internal = isset($frame['class'])
                ? class_exists($frame['class'], false) && (new \ReflectionClass($frame['class']))->isInternal()
                : function_exists((string) $frame['function']) && (new \ReflectionFunction((string) $frame['function']))->isInternal();
            if ($internal) {
                return false;
            }
        }
        // Texte technique ré-emballé : notre exception reprend le message d'une exception imprévue qu'elle enveloppe
        $previous = $e->getPrevious();
        if ($previous !== null && $previous->getMessage() !== '' && str_contains($e->getMessage(), $previous->getMessage()) && !$this->isIntended($previous)) {
            return false;
        }

        return true;
    }

    /**
     * Texte à rendre au client : celui de l'exception s'il lui est destiné, sinon le texte neutre (et le détail part au journal).
     *
     * @param string $where route ou traitement, pour retrouver la panne dans le journal
     */
    public function message(\Throwable $e, string $where, string $neutral = self::NEUTRAL): string
    {
        if ($this->isIntended($e)) {
            return $e->getMessage();
        }
        $this->logger->error(sprintf('[erreur imprévue] %s : %s : %s', $where, $e::class, $e->getMessage()), ['exception' => $e, 'where' => $where, 'file' => $e->getFile(), 'line' => $e->getLine()]);

        return $neutral;
    }

    /**
     * Corps `{ <clé>: texte }` d'une réponse d'erreur ; pour une panne imprévue, texte neutre et `code: INTERNAL_ERROR` en plus (jamais à
     * la place d'un code que la réponse porte déjà : `$extra` l'emporte).
     *
     * @param array<string, mixed> $extra autres clés de la réponse d'aujourd'hui (dont un éventuel `code`)
     *
     * @return array<string, mixed>
     */
    public function body(\Throwable $e, string $where, string $key = 'error', array $extra = []): array
    {
        $intended = $this->isIntended($e);

        return [$key => $this->message($e, $where)] + $extra + ($intended ? [] : ['code' => self::CODE]);
    }

    /**
     * Erreur de l'API Stripe : une carte refusée garde le texte de Stripe, destiné au payeur ; toute autre erreur (authentification,
     * requête invalide, limite, connexion) rend un texte neutre : son message peut citer un fragment de clé ou un identifiant interne.
     */
    public function stripe(ApiErrorException $e, string $where): string
    {
        if ($e instanceof CardException) {
            return $e->getMessage();
        }
        $this->logger->error(sprintf('[erreur Stripe] %s : %s (%s)', $where, $e->getMessage(), (string) ($e->getStripeCode() ?? '')), ['exception' => $e, 'where' => $where]);

        return self::PAYMENT_NEUTRAL;
    }

    /** Code de refus d'une carte (`decline_code`, sinon le code Stripe), null hors carte refusée */
    public static function declineCode(ApiErrorException $e): ?string
    {
        return $e instanceof CardException ? ($e->getDeclineCode() ?: $e->getStripeCode()) : null;
    }
}
