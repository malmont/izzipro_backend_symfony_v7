<?php

namespace App\Services\CheckoutService;

use Symfony\Component\HttpKernel\Exception\HttpException;

/** Refus d'une étape du paiement d'un panier : statut HTTP, message, erreurs par champ (path, message) */
final class CheckoutException extends HttpException
{
    /** @param list<array{path: string, message: string}> $errors */
    /** 400 de /api/order/create et create-guest (01/11/2026) : vrai si l'autorisation Stripe est annulée (pendant cet appel ou déjà) ; null = non déterminé */
    public ?bool $authorizationCanceled = null;

    public function __construct(int $status, string $message, public readonly array $errors = [])
    {
        parent::__construct($status, $message);
    }
}
