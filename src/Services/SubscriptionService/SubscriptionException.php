<?php

namespace App\Services\SubscriptionService;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Refus d'une opération d'abonnement : statut HTTP, message, erreurs par champ (path, message), champs ajoutés à la
 * réponse ($details : reason, subscriptionId… du 409 d'une double souscription)
 */
final class SubscriptionException extends HttpException
{
    /** @param list<array{path: string, message: string}> $errors */
    public function __construct(int $status, string $message, public readonly array $errors = [], public readonly array $details = [])
    {
        parent::__construct($status, $message);
    }
}
