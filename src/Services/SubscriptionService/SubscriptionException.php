<?php

namespace App\Services\SubscriptionService;

use App\EventSubscriber\ApiExceptionSubscriber;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Refus d'une opération d'abonnement : statut HTTP, message, erreurs par champ (path, message), champs ajoutés à la
 * réponse ($details : reason, subscriptionId… du 409 d'une double souscription).
 *
 * Depuis le 04/11/2026 : code stable (MAJUSCULES_SOULIGNÉES, `code()`), que les applications traduisent elles-mêmes ; chaque
 * élément d'`errors[]` porte le même code et, si le détail varie, des `params` (`error()`). Sans code explicite : code par
 * statut (ApiExceptionSubscriber). Table : docs/boutique.md, « Codes des refus des abonnements ».
 */
final class SubscriptionException extends HttpException
{
    /**
     * @param list<array{path: string, message: string, code?: string, params?: array<string, mixed>}> $errors
     * @param ?\Throwable $previous lot PROVISION-1 : la cause gardée quand le refus reprend le message d'une autre exception (garde-fou ClientErrorGuardTest)
     */
    public function __construct(int $status, string $message, public readonly array $errors = [], public readonly array $details = [], private readonly ?string $errorCode = null, ?\Throwable $previous = null)
    {
        parent::__construct($status, $message, $previous);
    }

    public function code(): string
    {
        return $this->errorCode ?? ApiExceptionSubscriber::code($this, $this->getStatusCode());
    }

    /** @return array{path: string, message: string, code: string, params?: array<string, mixed>} élément d'`errors[]` */
    public static function error(string $path, string $code, string $message, array $params = []): array
    {
        return ['path' => $path, 'message' => $message, 'code' => $code] + ($params !== [] ? ['params' => $params] : []);
    }
}
