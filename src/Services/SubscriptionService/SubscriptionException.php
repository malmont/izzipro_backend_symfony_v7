<?php

namespace App\Services\SubscriptionService;

use Symfony\Component\HttpKernel\Exception\HttpException;

/** Refus d'une opération d'abonnement : statut HTTP, message, erreurs par champ (path, message) */
final class SubscriptionException extends HttpException
{
    /** @param list<array{path: string, message: string}> $errors */
    public function __construct(int $status, string $message, public readonly array $errors = [])
    {
        parent::__construct($status, $message);
    }
}
