<?php

namespace App\Services\CheckoutService;

use Symfony\Component\HttpKernel\Exception\HttpException;

/** Refus d'une étape du paiement d'un panier : statut HTTP, message, erreurs par champ (path, message) */
final class CheckoutException extends HttpException
{
    /** @param list<array{path: string, message: string}> $errors */
    public function __construct(int $status, string $message, public readonly array $errors = [])
    {
        parent::__construct($status, $message);
    }
}
