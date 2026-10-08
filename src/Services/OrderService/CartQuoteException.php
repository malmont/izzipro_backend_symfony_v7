<?php

namespace App\Services\OrderService;

use Symfony\Component\HttpKernel\Exception\HttpException;

/** Devis refusé : statut (400 corps illisible, 404 article inconnu, 409 indisponible, 422 règle non respectée), erreurs par ligne */
final class CartQuoteException extends HttpException
{
    /** @param list<array{path: string, message: string}> $errors */
    public function __construct(int $status, string $message, public readonly array $errors = [])
    {
        parent::__construct($status, $message);
    }
}
